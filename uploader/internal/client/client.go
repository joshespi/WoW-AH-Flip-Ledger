// Package client talks to the WoW Flip Ledger API.
package client

import (
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"
)

const (
	maxResponse = 1 << 20
	maxFeed     = 8 << 20
	// MaxRetryAfter caps the server's Retry-After, so a bad proxy header can't stall syncing.
	MaxRetryAfter = time.Hour
)

// Outcome tells the caller what to do next, independent of the HTTP details.
type Outcome int

const (
	// OK: the server took the data.
	OK Outcome = iota
	// Rejected: this content will never be accepted (not a TSM file, too large). Don't retry
	// it until the file changes.
	Rejected
	// Retry: a network problem, server error or rate limit. Try again later.
	Retry
	// Fatal: retrying won't help until the user acts (bad token, uploader too old, wrong URL).
	Fatal
)

func (o Outcome) String() string {
	return [...]string{"ok", "rejected", "retry", "fatal"}[o]
}

// Result is the server's JSON reply plus the HTTP status.
type Result struct {
	HTTPStatus       int            `json:"-"`
	RetryAfter       time.Duration  `json:"-"`
	Status           string         `json:"status"`
	Message          string         `json:"message"`
	MinClientVersion string         `json:"min_client_version"`
	Counts           map[string]any `json:"counts"`
}

// Reason is why a request failed: err when it never got an answer, else the server's message.
func (r Result) Reason(err error) string {
	if err != nil {
		return err.Error()
	}
	return r.Message
}

// Summary is a one-line description of what an upload added.
func (r Result) Summary() string {
	n := func(group string) int {
		m, _ := r.Counts[group].(map[string]any)
		f, _ := m["new"].(float64)
		return int(f)
	}
	return fmt.Sprintf("%d new sales, %d expired, %d cancelled", n("sales"), n("expired"), n("cancelled"))
}

type Client struct {
	BaseURL string
	Token   string
	Version string
	HTTP    *http.Client
}

func New(baseURL, token, version string) *Client {
	return &Client{
		BaseURL: strings.TrimRight(baseURL, "/"),
		Token:   token,
		Version: version,
		HTTP: &http.Client{
			Timeout: 2 * time.Minute,
			// The token must never follow a redirect (http to https silently turns a POST into a
			// GET); surface it as an error telling the user to fix the server URL.
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		},
	}
}

// Upload sends the file gzipped. mtime is when TSM wrote it; label identifies the WoW account;
// game is "retail", "forever" or "classic".
func (c *Client) Upload(ctx context.Context, data []byte, mtime time.Time, label, game string) (Result, Outcome, error) {
	var buf bytes.Buffer
	zw := gzip.NewWriter(&buf)
	if _, err := zw.Write(data); err != nil {
		return Result{}, Fatal, err
	}
	if err := zw.Close(); err != nil {
		return Result{}, Fatal, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.BaseURL+"/api/v1/ingest", &buf)
	if err != nil {
		return Result{}, Fatal, err
	}
	req.Header.Set("Content-Type", "application/gzip")
	req.Header.Set("X-File-Mtime", strconv.FormatInt(mtime.Unix(), 10))
	if label != "" {
		req.Header.Set("X-Wow-Account", label)
	}
	req.Header.Set("X-Wow-Game", game)
	return c.do(req)
}

// Ping checks the token and version without uploading anything.
func (c *Client) Ping(ctx context.Context) (Result, Outcome, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.BaseURL+"/api/v1/ping", nil)
	if err != nil {
		return Result{}, Fatal, err
	}
	return c.do(req)
}

// Feed fetches the in-game addon feed. The body comes back raw: the addon package validates and
// renders it. A feed over maxFeed is Rejected rather than silently cut short.
func (c *Client) Feed(ctx context.Context) ([]byte, Result, Outcome, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.BaseURL+"/api/v1/addon-feed", nil)
	if err != nil {
		return nil, Result{}, Fatal, err
	}
	r, body, outcome, err := c.send(req, maxFeed)
	if outcome == OK && int64(len(body)) > maxFeed {
		r.Message = "the addon feed is larger than the uploader accepts"
		return nil, r, Rejected, nil
	}
	return body, r, outcome, err
}

func (c *Client) do(req *http.Request) (Result, Outcome, error) {
	r, _, outcome, err := c.send(req, maxResponse)
	return r, outcome, err
}

// send performs the request and reads at most limit+1 bytes of the reply, so a caller can tell
// a body that fit from one that was cut off.
func (c *Client) send(req *http.Request, limit int64) (Result, []byte, Outcome, error) {
	req.Header.Set("Authorization", "Bearer "+c.Token)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "wowflipledger-uploader/"+c.Version)
	req.Header.Set("X-Client-Version", c.Version)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return Result{}, nil, Retry, fmt.Errorf("could not reach the server: %w", redact(err, c.Token))
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, limit+1))

	var r Result
	if int64(len(body)) <= limit {
		_ = json.Unmarshal(body, &r)
	}
	r.HTTPStatus = resp.StatusCode
	if secs, err := strconv.Atoi(resp.Header.Get("Retry-After")); err == nil && secs > 0 {
		// Capped in seconds first, so a huge value can't overflow the Duration.
		r.RetryAfter = time.Duration(min(secs, int(MaxRetryAfter/time.Second))) * time.Second
	}
	if r.Message == "" && resp.StatusCode >= 300 {
		r.Message = fallbackMessage(resp.StatusCode, body)
	}

	return r, body, classify(resp.StatusCode), nil
}

func classify(status int) Outcome {
	switch {
	case status >= 200 && status < 300:
		return OK
	case status == http.StatusRequestEntityTooLarge, status == http.StatusUnprocessableEntity:
		return Rejected
	case status == http.StatusTooManyRequests, status >= 500:
		return Retry
	default:
		// 401/403 bad or revoked token, 426 uploader too old, 3xx wrong URL, other 4xx.
		return Fatal
	}
}

func fallbackMessage(status int, body []byte) string {
	if status >= 300 && status < 400 {
		return "the server redirected the request; check the server URL (it should be the final https address)"
	}
	text := strings.TrimSpace(string(body))
	if text == "" || strings.HasPrefix(text, "<") {
		return fmt.Sprintf("HTTP %d %s", status, http.StatusText(status))
	}
	if len(text) > 200 {
		text = strings.ToValidUTF8(text[:200], "") // drop a rune cut in half
	}
	return fmt.Sprintf("HTTP %d: %s", status, text)
}

// redact keeps the token out of error text if a proxy or URL error ever echoes it.
func redact(err error, token string) error {
	if token == "" {
		return err
	}
	return fmt.Errorf("%s", strings.ReplaceAll(err.Error(), token, "[token]"))
}
