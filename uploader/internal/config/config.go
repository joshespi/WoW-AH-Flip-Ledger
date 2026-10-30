// Package config reads and writes the uploader's settings file.
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"wowflipledger/uploader/internal/fsx"
)

const (
	fileName = "config.json"
	dirName  = "WoWFlipLedger"
)

// Config is stored as JSON with 0600 permissions in the user's config directory.
type Config struct {
	Server      string `json:"server"`
	Token       string `json:"token"`
	WowDir      string `json:"wow_dir,omitempty"`
	PollSeconds int    `json:"poll_seconds,omitempty"`

	// AddonSync turns on writing the in-game addon's Data.lua. Off unless the user enables it.
	AddonSync bool `json:"addon_sync,omitempty"`
}

// Dir picks the config directory: an explicit override, then WFL_CONFIG_DIR, then the OS default.
func Dir(override string) (string, error) {
	if override != "" {
		return override, nil
	}
	if env := os.Getenv("WFL_CONFIG_DIR"); env != "" {
		return env, nil
	}
	return DefaultDir()
}

// DefaultDir is the OS-standard location, ignoring any override.
func DefaultDir() (string, error) {
	base, err := os.UserConfigDir()
	if err != nil {
		return "", fmt.Errorf("find config directory: %w", err)
	}
	return filepath.Join(base, dirName), nil
}

// ErrNotConfigured means setup has not been run.
var ErrNotConfigured = errors.New("not configured: run the setup command first")

// Path is the settings file inside dir.
func Path(dir string) string { return filepath.Join(dir, fileName) }

func Load(dir string) (*Config, error) {
	b, err := os.ReadFile(Path(dir))
	if errors.Is(err, os.ErrNotExist) {
		return nil, ErrNotConfigured
	}
	if err != nil {
		return nil, err
	}
	var c Config
	if err := json.Unmarshal(b, &c); err != nil {
		return nil, fmt.Errorf("read %s: %w", fileName, err)
	}
	if c.Server == "" || c.Token == "" {
		return nil, ErrNotConfigured
	}
	return &c, nil
}

func (c *Config) Save(dir string) error {
	return fsx.WriteJSON(Path(dir), c)
}

// ValidateServer normalizes the server URL. The token travels in a header, so plain http is only
// allowed for loopback (local development) unless WFL_ALLOW_INSECURE_HTTP=1.
func ValidateServer(raw string) (string, error) {
	u, err := url.Parse(strings.TrimSpace(raw))
	if err != nil || u.Host == "" || (u.Scheme != "https" && u.Scheme != "http") {
		return "", errors.New("server must be a URL like https://wowledger.joshespi.com")
	}
	if u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return "", errors.New("server URL must not contain credentials, a query or a fragment")
	}
	if u.Scheme == "http" && !isLoopback(u.Hostname()) && os.Getenv("WFL_ALLOW_INSECURE_HTTP") != "1" {
		return "", errors.New("server must use https (plain http is only allowed for localhost)")
	}
	return strings.TrimRight(u.Scheme+"://"+u.Host+u.Path, "/"), nil
}

func isLoopback(host string) bool {
	if host == "localhost" {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}

var tokenRE = regexp.MustCompile(`^\d+\|wfl_[A-Za-z0-9]{20,}$`)

// ValidateToken checks the shape of a token from the Settings page ("<id>|wfl_<secret>").
func ValidateToken(t string) error {
	if !tokenRE.MatchString(strings.TrimSpace(t)) {
		return errors.New("token should look like 12|wfl_... (copy the whole token from Settings)")
	}
	return nil
}

// Mask shows enough of a token to recognize it without exposing the secret.
func Mask(t string) string {
	if len(t) <= 10 {
		return "****"
	}
	return t[:7] + "…" + t[len(t)-3:]
}
