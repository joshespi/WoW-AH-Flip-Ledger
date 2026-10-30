// Package autostart makes the uploader start at login: a hidden-window script in the Windows
// Startup folder, or a LaunchAgent on macOS. Neither needs admin rights.
package autostart

import (
	"errors"
	"fmt"
	"html"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
)

const (
	// Label identifies the macOS LaunchAgent.
	Label       = "com.wowflipledger.uploader"
	windowsFile = "WoWFlipLedgerUploader.vbs"
)

var ErrUnsupported = errors.New("start at login is supported on Windows and macOS; on other systems run the 'run' command under your own service manager")

// WindowsScript is a VBScript that starts the uploader with no console window.
// configDir is empty for the default location.
func WindowsScript(exe, configDir string) string {
	cmd := `""` + exe + `""`
	if configDir != "" {
		cmd += ` --config-dir ""` + configDir + `""`
	}
	cmd += " run"
	return "' WoW Flip Ledger uploader: starts at login, runs hidden.\r\n" +
		"' Remove it with: wowflipledger-uploader uninstall\r\n" +
		"Set sh = CreateObject(\"WScript.Shell\")\r\n" +
		"sh.Run \"" + cmd + "\", 0, False\r\n"
}

// LaunchAgentPlist is the macOS LaunchAgent definition. The uploader keeps its own log file,
// so launchd's stdout and stderr go nowhere.
func LaunchAgentPlist(exe, configDir string) string {
	args := []string{exe}
	if configDir != "" {
		args = append(args, "--config-dir", configDir)
	}
	args = append(args, "run")

	var b strings.Builder
	for _, a := range args {
		b.WriteString("\t\t<string>" + html.EscapeString(a) + "</string>\n")
	}

	return `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>Label</key>
	<string>` + Label + `</string>
	<key>ProgramArguments</key>
	<array>
` + b.String() + `	</array>
	<key>RunAtLoad</key>
	<true/>
	<key>KeepAlive</key>
	<true/>
	<key>ThrottleInterval</key>
	<integer>30</integer>
	<key>StandardOutPath</key>
	<string>/dev/null</string>
	<key>StandardErrorPath</key>
	<string>/dev/null</string>
</dict>
</plist>
`
}

// Install registers the uploader to start at login and starts it now. configDir is empty for
// the default location.
func Install(exe, configDir string) (string, error) {
	switch runtime.GOOS {
	case "windows":
		path, err := windowsPath()
		if err != nil {
			return "", err
		}
		if err := writeEntry(path, WindowsScript(exe, configDir)); err != nil {
			return "", err
		}
		if os.Getenv("WFL_NO_START") != "1" {
			_ = exec.Command("wscript.exe", "//B", "//Nologo", path).Start()
		}
		return installed + "Startup entry: " + path, nil

	case "darwin":
		path, err := plistPath()
		if err != nil {
			return "", err
		}
		if err := writeEntry(path, LaunchAgentPlist(exe, configDir)); err != nil {
			return "", err
		}
		domain := "gui/" + strconv.Itoa(os.Getuid())
		_ = exec.Command("launchctl", "bootout", domain+"/"+Label).Run()
		if out, err := exec.Command("launchctl", "bootstrap", domain, path).CombinedOutput(); err != nil {
			return "", fmt.Errorf("saved %s but launchctl failed (%s); run: launchctl bootstrap %s %s",
				path, strings.TrimSpace(string(out)), domain, path)
		}
		return installed + "LaunchAgent: " + path, nil
	}
	return "", ErrUnsupported
}

// Uninstall removes the start-at-login entry. On Windows an already-running uploader keeps
// running until you sign out or end it in Task Manager.
func Uninstall() (string, error) {
	switch runtime.GOOS {
	case "windows":
		path, err := windowsPath()
		if err != nil {
			return "", err
		}
		found, err := removeEntry(path)
		if err != nil {
			return "", err
		}
		if !found {
			return notInstalled, nil
		}
		return "Removed the startup entry. A running uploader stays up until you sign out or end wowflipledger-uploader.exe in Task Manager.", nil

	case "darwin":
		path, err := plistPath()
		if err != nil {
			return "", err
		}
		_ = exec.Command("launchctl", "bootout", "gui/"+strconv.Itoa(os.Getuid())+"/"+Label).Run()
		found, err := removeEntry(path)
		if err != nil {
			return "", err
		}
		if !found {
			return notInstalled, nil
		}
		return "Stopped the uploader and removed its LaunchAgent.", nil
	}
	return "", ErrUnsupported
}

const (
	installed    = "Installed: the uploader starts at login and is running now.\n"
	notInstalled = "Not installed; nothing to remove."
)

// writeEntry saves the startup entry, creating its folder if needed.
func writeEntry(path, content string) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	return os.WriteFile(path, []byte(content), 0o644)
}

// removeEntry deletes the startup entry; found is false when there was none.
func removeEntry(path string) (found bool, err error) {
	if err := os.Remove(path); errors.Is(err, os.ErrNotExist) {
		return false, nil
	} else if err != nil {
		return false, err
	}
	return true, nil
}

func windowsPath() (string, error) {
	// WFL_STARTUP_DIR lets tests and dry runs avoid touching the real Startup folder.
	if dir := os.Getenv("WFL_STARTUP_DIR"); dir != "" {
		return filepath.Join(dir, windowsFile), nil
	}
	appdata := os.Getenv("APPDATA")
	if appdata == "" {
		return "", errors.New("APPDATA is not set; cannot find the Startup folder")
	}
	return filepath.Join(appdata, "Microsoft", "Windows", "Start Menu", "Programs", "Startup", windowsFile), nil
}

func plistPath() (string, error) {
	if dir := os.Getenv("WFL_LAUNCHAGENTS_DIR"); dir != "" {
		return filepath.Join(dir, Label+".plist"), nil
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, "Library", "LaunchAgents", Label+".plist"), nil
}
