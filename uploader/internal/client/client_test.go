package client

import (
	"bytes"
	"compress/gzip"
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

const token = "12|wfl_" + "abcdefghijklmnopqrstuvwxyz0123456789ABCD"

func newClient(srv *httptest.Server) *Client { return New(srv.URL, token, "1.2.3") }

func upload(t *testing.T, c *Client, data []byte) (Result, Outcome, error) {
	t.Helper()
	return c.Upload(context.Background(), data, time.Unix(1_790_000_000, 0), "acct-abc123", "retail")
}

func TestUploadSendsGzippedBodyAndHeaders(t *testing.T) {
	var gotBody []byte
	var h http.Header
	var method, path string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		method, path, h = r.Method, r.URL.Path, r.Header.Clone()
		zr, err := gzip.NewReader(r.Body)
		if err != nil {
			t.Errorf("body is not gzip: %v", err)
			return
		}
		gotBody, _ = io.ReadAll(zr)
		w.Header().Set("Content-Type", "application/json")
		io.WriteString(w, `{"status":"ok","message":"Data received.","counts":{"sales":{"new":5},"expired":{"new":2},"cancelled":{"new":1}},"min_client_version":"0.0.0"}`)
	}))
	defer srv.Close()

	payload := bytes.Repeat([]byte("TradeSkillMasterDB = {}\n"), 1000)
	res, outcome, err := upload(t, newClient(srv), payload)

	if err != nil || outcome != OK {
		t.Fatalf("outcome = %v, err = %v", outcome, err)
	}
	if method != "POST" || path != "/api/v1/ingest" {
		t.Errorf("request = %s %s", method, path)
	}
	if !bytes.Equal(gotBody, payload) {
		t.Error("server did not receive the original bytes after gunzip")
	}
	for k, want := range map[string]string{
		"Authorization":    "Bearer " + token,
		"X-File-Mtime":     "1790000000",
		"X-Wow-Account":    "acct-abc123",
		"X-Wow-Game":       "retail",
		"X-Client-Version": "1.2.3",
		"Content-Type":     "application/gzip",
		"Accept":           "application/json",
		"User-Agent":       "wowflipledger-uploader/1.2.3",
	} {
		if got := h.Get(k); got != want {
			t.Errorf("header %s = %q, want %q", k, got, want)
		}
	}
	if got := res.Summary(); got != "5 new sales, 2 expired, 1 cancelled" {
		t.Errorf("Summary() = %q", got)
	}
}

func TestStatusClassification(t *testing.T) {
	cases := []struct {
		status  int
		body    string
		outcome Outcome
		msg     string
	}{
		{200, `{"status":"ok","message":"Data received."}`, OK, "Data received."},
		{422, `{"status":"failed","message":"That does not look like TradeSkillMaster.lua"}`, Rejected, "That does not look like TradeSkillMaster.lua"},
		{413, `<html>413 Request Entity Too Large</html>`, Rejected, "HTTP 413 Request Entity Too Large"},
		{401, `{"message":"Unauthenticated."}`, Fatal, "Unauthenticated."},
		{403, `{"message":"Invalid ability provided."}`, Fatal, "Invalid ability provided."},
		{426, `{"status":"upgrade_required","message":"Update to 1.0.0 or newer.","min_client_version":"1.0.0"}`, Fatal, "Update to 1.0.0 or newer."},
		{404, ``, Fatal, "HTTP 404 Not Found"},
		{429, `{"message":"Too many requests."}`, Retry, "Too many requests."},
		{500, `Server Error`, Retry, "HTTP 500: Server Error"},
		{502, `<html>bad gateway</html>`, Retry, "HTTP 502 Bad Gateway"},
	}

	for _, tc := range cases {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.WriteHeader(tc.status)
			io.WriteString(w, tc.body)
		}))
		res, outcome, err := upload(t, newClient(srv), []byte("x"))
		srv.Close()

		if err != nil {
			t.Errorf("%d: unexpected error %v", tc.status, err)
		}
		if outcome != tc.outcome || res.Message != tc.msg || res.HTTPStatus != tc.status {
			t.Errorf("%d: outcome=%v message=%q status=%d; want %v %q", tc.status, outcome, res.Message, res.HTTPStatus, tc.outcome, tc.msg)
		}
	}
}

func TestRetryAfterIsParsed(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "37")
		w.WriteHeader(429)
	}))
	defer srv.Close()

	res, outcome, _ := upload(t, newClient(srv), []byte("x"))
	if outcome != Retry || res.RetryAfter != 37*time.Second {
		t.Errorf("outcome=%v retryAfter=%v", outcome, res.RetryAfter)
	}
}

func TestRetryAfterIsCapped(t *testing.T) {
	for _, v := range []string{"99999999", "9223372036854775807"} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Retry-After", v)
			w.WriteHeader(503)
		}))

		res, _, _ := upload(t, newClient(srv), []byte("x"))
		srv.Close()
		if res.RetryAfter != MaxRetryAfter {
			t.Errorf("Retry-After %s: got %v, want %v", v, res.RetryAfter, MaxRetryAfter)
		}
	}
}

func TestRedirectsAreNotFollowedAndTheTokenStaysPut(t *testing.T) {
	var hits atomic.Int32
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits.Add(1)
	}))
	defer target.Close()

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, target.URL+"/api/v1/ingest", http.StatusMovedPermanently)
	}))
	defer srv.Close()

	res, outcome, err := upload(t, newClient(srv), []byte("x"))
	if err != nil || outcome != Fatal || hits.Load() != 0 {
		t.Fatalf("outcome=%v err=%v redirectTargetHits=%d", outcome, err, hits.Load())
	}
	if !strings.Contains(res.Message, "redirected") {
		t.Errorf("message should explain the redirect: %q", res.Message)
	}
}

func TestNetworkErrorsAreRetryable(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler())
	c := newClient(srv)
	srv.Close() // nothing listening any more

	_, outcome, err := upload(t, c, []byte("x"))
	if outcome != Retry || err == nil {
		t.Errorf("outcome=%v err=%v", outcome, err)
	}
	if err != nil && strings.Contains(err.Error(), token) {
		t.Errorf("error text leaked the token: %v", err)
	}
}

func TestRedactRemovesTheToken(t *testing.T) {
	err := redact(io.ErrUnexpectedEOF, token)
	if err.Error() != io.ErrUnexpectedEOF.Error() {
		t.Errorf("unrelated errors should pass through, got %v", err)
	}

	leaky := &echoErr{"Get https://x/?key=" + token + ": boom"}
	if got := redact(leaky, token).Error(); strings.Contains(got, token) || !strings.Contains(got, "[token]") {
		t.Errorf("token not redacted: %q", got)
	}
}

type echoErr struct{ s string }

func (e *echoErr) Error() string { return e.s }

func TestPing(t *testing.T) {
	var method, path, auth string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		method, path, auth = r.Method, r.URL.Path, r.Header.Get("Authorization")
		io.WriteString(w, `{"status":"ok","message":"Token accepted.","min_client_version":"0.3.0"}`)
	}))
	defer srv.Close()

	res, outcome, err := newClient(srv).Ping(context.Background())
	if err != nil || outcome != OK || res.MinClientVersion != "0.3.0" {
		t.Fatalf("ping: %+v %v %v", res, outcome, err)
	}
	if method != "GET" || path != "/api/v1/ping" || auth != "Bearer "+token {
		t.Errorf("ping request = %s %s auth=%q", method, path, auth)
	}
}

func TestBaseURLTrailingSlashIsTolerated(t *testing.T) {
	var path string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { path = r.URL.Path }))
	defer srv.Close()

	New(srv.URL+"/", token, "1").Ping(context.Background())
	if path != "/api/v1/ping" {
		t.Errorf("path = %q", path)
	}
}

func TestOutcomeStrings(t *testing.T) {
	if OK.String() != "ok" || Rejected.String() != "rejected" || Retry.String() != "retry" || Fatal.String() != "fatal" {
		t.Error("unexpected Outcome strings")
	}
}

func TestFeedGetsTheRawBodyWithAuthHeaders(t *testing.T) {
	var method, path string
	var h http.Header

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		method, path, h = r.Method, r.URL.Path, r.Header.Clone()
		io.WriteString(w, `{"schema":1,"positions":[]}`)
	}))
	defer srv.Close()

	body, res, outcome, err := newClient(srv).Feed(context.Background())

	if err != nil || outcome != OK || string(body) != `{"schema":1,"positions":[]}` {
		t.Fatalf("body=%q outcome=%v err=%v", body, outcome, err)
	}
	if method != "GET" || path != "/api/v1/addon-feed" {
		t.Errorf("request = %s %s", method, path)
	}
	if h.Get("Authorization") != "Bearer "+token || h.Get("X-Client-Version") != "1.2.3" {
		t.Errorf("headers = %v", h)
	}
	if res.HTTPStatus != 200 || res.Message != "" {
		t.Errorf("a 2xx feed should not get a fallback message: %+v", res)
	}
}

func TestFeedClassifiesStatuses(t *testing.T) {
	cases := map[int]Outcome{200: OK, 401: Fatal, 403: Fatal, 426: Fatal, 429: Retry, 500: Retry, 503: Retry}

	for status, want := range cases {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Retry-After", "7")
			w.WriteHeader(status)
			io.WriteString(w, `{"message":"nope"}`)
		}))
		_, res, outcome, _ := newClient(srv).Feed(context.Background())
		srv.Close()

		if outcome != want {
			t.Errorf("%d: got %v want %v", status, outcome, want)
		}
		if status == 429 && res.RetryAfter != 7*time.Second {
			t.Errorf("Retry-After = %v", res.RetryAfter)
		}
	}
}

func TestFeedOverTheLimitIsRejectedNotTruncated(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write(bytes.Repeat([]byte("x"), maxFeed+10))
	}))
	defer srv.Close()

	body, res, outcome, _ := newClient(srv).Feed(context.Background())

	if outcome != Rejected || body != nil || !strings.Contains(res.Message, "larger") {
		t.Errorf("outcome=%v body=%d res=%+v", outcome, len(body), res)
	}
}

func TestFeedNeverFollowsRedirectsWithTheToken(t *testing.T) {
	var hits atomic.Int32
	other := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { hits.Add(1) }))
	defer other.Close()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, other.URL, http.StatusFound)
	}))
	defer srv.Close()

	_, _, outcome, _ := newClient(srv).Feed(context.Background())

	if outcome != Fatal || hits.Load() != 0 {
		t.Errorf("outcome=%v followed=%d", outcome, hits.Load())
	}
}
