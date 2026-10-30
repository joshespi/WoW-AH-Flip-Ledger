package autostart

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestWindowsScriptRunsHiddenWithQuotedPaths(t *testing.T) {
	s := WindowsScript(`C:\Users\Jo Smith\Tools\wowflipledger-uploader.exe`, "")

	want := `sh.Run """C:\Users\Jo Smith\Tools\wowflipledger-uploader.exe"" run", 0, False`
	if !strings.Contains(s, want) {
		t.Errorf("script missing %q:\n%s", want, s)
	}
	if !strings.Contains(s, `CreateObject("WScript.Shell")`) || !strings.Contains(s, "\r\n") {
		t.Errorf("script should be a CRLF VBScript using WScript.Shell:\n%s", s)
	}
	if strings.Contains(s, "--config-dir") {
		t.Error("default config dir should not be passed explicitly")
	}
}

func TestWindowsScriptPassesACustomConfigDir(t *testing.T) {
	s := WindowsScript(`C:\bin\u.exe`, `D:\My Config`)
	want := `sh.Run """C:\bin\u.exe"" --config-dir ""D:\My Config"" run", 0, False`
	if !strings.Contains(s, want) {
		t.Errorf("script missing %q:\n%s", want, s)
	}
}

func TestLaunchAgentPlist(t *testing.T) {
	p := LaunchAgentPlist("/Applications/Tools & Co/uploader", "")

	for _, want := range []string{
		"<string>" + Label + "</string>",
		"<string>/Applications/Tools &amp; Co/uploader</string>",
		"<string>run</string>",
		"<key>RunAtLoad</key>\n\t<true/>",
		"<key>KeepAlive</key>\n\t<true/>",
		`<?xml version="1.0"`,
	} {
		if !strings.Contains(p, want) {
			t.Errorf("plist missing %q:\n%s", want, p)
		}
	}
	if strings.Contains(p, "--config-dir") {
		t.Error("default config dir should not be passed explicitly")
	}

	custom := LaunchAgentPlist("/bin/u", "/Users/me/cfg")
	i, j, k := strings.Index(custom, "/bin/u"), strings.Index(custom, "--config-dir"), strings.Index(custom, "<string>run</string>")
	if !(i < j && j < k) || !strings.Contains(custom, "<string>/Users/me/cfg</string>") {
		t.Errorf("arguments should be exe, --config-dir, dir, run in order:\n%s", custom)
	}
}

func TestStartupLocationsHonorTestOverrides(t *testing.T) {
	t.Setenv("WFL_STARTUP_DIR", "/tmp/startup")
	t.Setenv("WFL_LAUNCHAGENTS_DIR", "/tmp/agents")

	if p, _ := windowsPath(); p != filepath.Join("/tmp/startup", windowsFile) {
		t.Errorf("windowsPath = %q", p)
	}
	if p, _ := plistPath(); p != filepath.Join("/tmp/agents", Label+".plist") {
		t.Errorf("plistPath = %q", p)
	}
}

func TestDefaultWindowsPathIsTheUserStartupFolder(t *testing.T) {
	t.Setenv("WFL_STARTUP_DIR", "")
	t.Setenv("APPDATA", filepath.Join("C:", "Users", "x", "AppData", "Roaming"))

	p, err := windowsPath()
	if err != nil || !strings.HasSuffix(filepath.ToSlash(p), "Start Menu/Programs/Startup/"+windowsFile) {
		t.Errorf("windowsPath = %q, %v", p, err)
	}

	t.Setenv("APPDATA", "")
	if _, err := windowsPath(); err == nil {
		t.Error("missing APPDATA should be an error")
	}
}

func TestUnsupportedPlatformsSayWhy(t *testing.T) {
	if runtime.GOOS == "windows" || runtime.GOOS == "darwin" {
		t.Skip("only meaningful elsewhere")
	}
	if _, err := Install("/bin/u", ""); !errors.Is(err, ErrUnsupported) {
		t.Errorf("Install err = %v", err)
	}
	if _, err := Uninstall(); !errors.Is(err, ErrUnsupported) {
		t.Errorf("Uninstall err = %v", err)
	}
}

// On Windows this exercises the real Install/Uninstall code against a temp Startup folder, without
// launching anything.
func TestWindowsInstallAndUninstallRoundTrip(t *testing.T) {
	if runtime.GOOS != "windows" {
		t.Skip("windows only")
	}
	dir := t.TempDir()
	t.Setenv("WFL_STARTUP_DIR", dir)
	t.Setenv("WFL_NO_START", "1")

	if _, err := Install(`C:\bin\u.exe`, ""); err != nil {
		t.Fatal(err)
	}
	b, err := os.ReadFile(filepath.Join(dir, windowsFile))
	if err != nil || !strings.Contains(string(b), `C:\bin\u.exe`) {
		t.Fatalf("startup script not written: %v %q", err, b)
	}
	if _, err := Uninstall(); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(dir, windowsFile)); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("startup script still present: %v", err)
	}
	if msg, err := Uninstall(); err != nil || !strings.Contains(msg, "Not installed") {
		t.Errorf("second uninstall = %q, %v", msg, err)
	}
}
