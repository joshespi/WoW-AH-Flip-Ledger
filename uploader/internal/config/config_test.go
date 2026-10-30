package config

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestValidateServer(t *testing.T) {
	ok := map[string]string{
		"https://wowledger.joshespi.com":     "https://wowledger.joshespi.com",
		"  https://wowledger.joshespi.com/ ": "https://wowledger.joshespi.com",
		"https://example.com:8443/app/":      "https://example.com:8443/app",
		"http://localhost:8087":              "http://localhost:8087",
		"http://127.0.0.1:8087/":             "http://127.0.0.1:8087",
		"http://[::1]:8087":                  "http://[::1]:8087",
	}
	for in, want := range ok {
		got, err := ValidateServer(in)
		if err != nil || got != want {
			t.Errorf("ValidateServer(%q) = %q, %v; want %q", in, got, err, want)
		}
	}

	bad := []string{
		"", "wowledger.joshespi.com", "ftp://example.com", "https://", "http://example.com",
		"http://192.168.1.20:8087", "https://user:pw@example.com", "https://example.com?x=1", "https://example.com#frag",
	}
	for _, in := range bad {
		if got, err := ValidateServer(in); err == nil {
			t.Errorf("ValidateServer(%q) accepted as %q", in, got)
		}
	}
}

func TestInsecureHTTPNeedsExplicitOptIn(t *testing.T) {
	t.Setenv("WFL_ALLOW_INSECURE_HTTP", "1")
	if _, err := ValidateServer("http://lab.internal:8087"); err != nil {
		t.Fatalf("opt-in should allow plain http: %v", err)
	}
}

func TestValidateToken(t *testing.T) {
	good := "12|wfl_" + strings.Repeat("a1B2", 10)
	if err := ValidateToken(" " + good + "\n"); err != nil {
		t.Errorf("good token rejected: %v", err)
	}
	for _, bad := range []string{"", "wfl_" + strings.Repeat("a", 40), "12|" + strings.Repeat("a", 40), "12|wfl_short", "x|wfl_" + strings.Repeat("a", 40), "12|wfl_" + strings.Repeat("a", 40) + "!"} {
		if err := ValidateToken(bad); err == nil {
			t.Errorf("token %q accepted", bad)
		}
	}
}

func TestMaskHidesTheSecret(t *testing.T) {
	tok := "12|wfl_" + strings.Repeat("s", 40)
	m := Mask(tok)
	if strings.Contains(m, strings.Repeat("s", 10)) || !strings.HasPrefix(m, "12|wfl_") {
		t.Errorf("Mask leaked or lost the prefix: %q", m)
	}
	if Mask("short") != "****" {
		t.Errorf("short token should be fully hidden")
	}
}

func TestSaveLoadRoundTripAndPermissions(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "cfg")
	in := &Config{Server: "https://x.example", Token: "1|wfl_" + strings.Repeat("a", 30), WowDir: "/wow", PollSeconds: 45}
	if err := in.Save(dir); err != nil {
		t.Fatal(err)
	}

	out, err := Load(dir)
	if err != nil || *out != *in {
		t.Fatalf("Load = %+v, %v; want %+v", out, err, in)
	}

	if runtime.GOOS != "windows" {
		fi, _ := os.Stat(filepath.Join(dir, fileName))
		if fi.Mode().Perm() != 0o600 {
			t.Errorf("config file mode = %v, want 0600", fi.Mode().Perm())
		}
		di, _ := os.Stat(dir)
		if di.Mode().Perm() != 0o700 {
			t.Errorf("config dir mode = %v, want 0700", di.Mode().Perm())
		}
	}
}

func TestLoadReportsNotConfigured(t *testing.T) {
	dir := t.TempDir()
	if _, err := Load(dir); !errors.Is(err, ErrNotConfigured) {
		t.Errorf("missing file: err = %v", err)
	}

	(&Config{Server: "https://x.example"}).Save(dir)
	if _, err := Load(dir); !errors.Is(err, ErrNotConfigured) {
		t.Errorf("config without a token: err = %v", err)
	}

	os.WriteFile(filepath.Join(dir, fileName), []byte("{not json"), 0o600)
	if _, err := Load(dir); err == nil || errors.Is(err, ErrNotConfigured) {
		t.Errorf("corrupt file should be a distinct error, got %v", err)
	}
}

func TestDirPrecedence(t *testing.T) {
	t.Setenv("WFL_CONFIG_DIR", "/from/env")
	if d, _ := Dir("/explicit"); d != "/explicit" {
		t.Errorf("override should win, got %q", d)
	}
	if d, _ := Dir(""); d != "/from/env" {
		t.Errorf("env should win over the default, got %q", d)
	}
}
