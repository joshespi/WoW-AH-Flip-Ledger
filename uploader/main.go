// wowflipledger-uploader sends your TradeSkillMaster data to WoW Flip Ledger.
//
// It reads exactly one file per WoW account (WTF/Account/<account>/SavedVariables/
// TradeSkillMaster.lua) and uploads when TSM saves it (after /reload or logout). It writes
// nothing under the WoW folder, except, only if you run 'addon enable', the in-game addon's own
// Interface/AddOns/WowFlipLedger/Data.lua.
package main

import (
	"bufio"
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"os/signal"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"

	"wowflipledger/uploader/internal/addon"
	"wowflipledger/uploader/internal/autostart"
	"wowflipledger/uploader/internal/client"
	"wowflipledger/uploader/internal/config"
	"wowflipledger/uploader/internal/logx"
	"wowflipledger/uploader/internal/state"
	"wowflipledger/uploader/internal/version"
	"wowflipledger/uploader/internal/watch"
	"wowflipledger/uploader/internal/wow"
)

// Exit codes, so scripts can tell what went wrong.
const (
	exitOK       = 0
	exitFailed   = 1
	exitUpgrade  = 2
	exitAuth     = 3
	exitUsage    = 64
	exitNotSetup = 78
)

const usage = `wowflipledger-uploader ` + "%s" + `

Usage: wowflipledger-uploader [--config-dir DIR] <command> [flags]

Commands:
  setup       Save your server address and token (from the site's Settings page)
  status      Show the configuration, the files found and the last upload
  once        Upload now (add --force to upload even if nothing changed)
  run         Watch for TSM saves and upload them (what start-at-login runs)
  addon       enable | disable | status: keep the in-game addon's data file current
  install     Start the uploader at login (Windows and macOS)
  uninstall   Remove the start-at-login entry
  version     Print the version

TSM only writes its file when you /reload or log out in game.
`

type app struct {
	dir string
	in  io.Reader
	out io.Writer
	err io.Writer
}

func main() {
	os.Exit(run(os.Args[1:], os.Stdin, os.Stdout, os.Stderr))
}

func run(args []string, in io.Reader, out, errw io.Writer) int {
	override := ""
	for len(args) > 0 {
		switch {
		case args[0] == "--config-dir" && len(args) > 1:
			override, args = args[1], args[2:]
			continue
		case strings.HasPrefix(args[0], "--config-dir="):
			override, args = strings.TrimPrefix(args[0], "--config-dir="), args[1:]
			continue
		}
		break
	}

	if len(args) == 0 || args[0] == "help" || args[0] == "--help" || args[0] == "-h" {
		fmt.Fprintf(errw, usage, version.Version)
		if len(args) == 0 {
			return exitUsage
		}
		return exitOK
	}

	dir, err := config.Dir(override)
	if err != nil {
		fmt.Fprintln(errw, "error:", err)
		return exitFailed
	}
	a := &app{dir: dir, in: in, out: out, err: errw}

	cmd, rest := args[0], args[1:]
	switch cmd {
	case "setup":
		return a.setup(rest)
	case "status":
		return a.status()
	case "once":
		return a.once(rest)
	case "run":
		return a.run()
	case "addon":
		return a.addon(rest)
	case "install":
		return a.install()
	case "uninstall":
		return a.uninstall()
	case "version":
		fmt.Fprintln(out, version.Version)
		return exitOK
	}

	fmt.Fprintf(errw, "unknown command %q\n\n", cmd)
	fmt.Fprintf(errw, usage, version.Version)
	return exitUsage
}

func (a *app) setup(args []string) int {
	fs := flag.NewFlagSet("setup", flag.ContinueOnError)
	fs.SetOutput(a.err)
	server := fs.String("server", "", "server address, e.g. https://wowledger.joshespi.com")
	token := fs.String("token", "", "token from the Settings page")
	wowDir := fs.String("wow", "", "path to the World of Warcraft _retail_ folder (auto-detected if omitted)")
	noCheck := fs.Bool("no-check", false, "save without contacting the server")
	if err := fs.Parse(args); err != nil {
		return exitUsage
	}

	r := bufio.NewReader(a.in)
	if *server == "" {
		*server = a.prompt(r, "Server address (e.g. https://wowledger.joshespi.com): ")
	}
	if *token == "" {
		*token = a.prompt(r, "Token (from Settings, looks like 12|wfl_...): ")
	}

	srv, err := config.ValidateServer(*server)
	if err != nil {
		return a.fail(exitUsage, "error:", err)
	}
	tok := strings.TrimSpace(*token)
	if err := config.ValidateToken(tok); err != nil {
		return a.fail(exitUsage, "error:", err)
	}

	if !*noCheck {
		res, outcome, err := ping(srv, tok)

		switch outcome {
		case client.OK:
			fmt.Fprintln(a.out, "Connected to the server; token accepted.")
		case client.Retry:
			fmt.Fprintf(a.err, "warning: could not verify the token right now (%v). Saving anyway.\n", res.Reason(err))
		default:
			fmt.Fprintf(a.err, "error: the server refused this setup: %s\nNothing was saved. Use --no-check to save anyway.\n", res.Reason(err))
			return exitCode(res.HTTPStatus)
		}
	}

	cfg := &config.Config{Server: srv, Token: tok, WowDir: *wowDir}
	if err := cfg.Save(a.dir); err != nil {
		return a.fail(exitFailed, "error: could not save settings:", err)
	}
	fmt.Fprintln(a.out, "Saved settings to", config.Path(a.dir))

	root, err := wow.DetectRoot(cfg.WowDir)
	if err != nil {
		fmt.Fprintf(a.out, "Note: %v\n", err)
	} else if files, _ := wow.FindFiles(root); len(files) == 0 {
		fmt.Fprintf(a.out, "Found WoW at %s, but no TradeSkillMaster.lua yet. Log in, then /reload or log out once so TSM saves it.\n", root)
	} else {
		fmt.Fprintf(a.out, "Found WoW at %s with %d TSM data file(s).\n", root, len(files))
	}

	fmt.Fprintln(a.out, "\nNext: run 'once' to upload now, and 'install' to start at login.")
	return exitOK
}

func (a *app) status() int {
	cfg, ok := a.load()
	if !ok {
		return exitNotSetup
	}

	fmt.Fprintf(a.out, "Version:  %s\nConfig:   %s\nServer:   %s\nToken:    %s\n", version.Version, config.Path(a.dir), cfg.Server, config.Mask(cfg.Token))

	root, err := wow.DetectRoot(cfg.WowDir)
	if err != nil {
		fmt.Fprintf(a.out, "WoW:      %v\n", err)
	} else {
		fmt.Fprintf(a.out, "WoW:      %s\n", root)
		files, _ := wow.FindFiles(root)
		if len(files) == 0 {
			fmt.Fprintln(a.out, "Files:    none yet (log in, then /reload or log out so TSM saves its data)")
		}
		st, _ := state.Open(a.dir)
		for _, f := range files {
			line := "never uploaded"
			if e, ok := st.Get(f.Path); ok {
				line = fmt.Sprintf("%s at %s", e.Status, e.At.Local().Format("2006-01-02 15:04"))
				if e.Message != "" {
					line += " (" + e.Message + ")"
				}
			}
			fmt.Fprintf(a.out, "File:     %s  %s\n", f.Label, line)
		}
	}

	a.addonLine(cfg, root, err)

	res, outcome, err := ping(cfg.Server, cfg.Token)
	if outcome == client.OK {
		fmt.Fprintf(a.out, "Server:   OK (needs uploader %s or newer)\n", res.MinClientVersion)
		return exitOK
	}
	fmt.Fprintf(a.out, "Server:   %s: %s\n", outcome, res.Reason(err))
	return exitCode(res.HTTPStatus)
}

func (a *app) once(args []string) int {
	fs := flag.NewFlagSet("once", flag.ContinueOnError)
	fs.SetOutput(a.err)
	force := fs.Bool("force", false, "upload even if the file looks unchanged")
	if err := fs.Parse(args); err != nil {
		return exitUsage
	}

	s, closeLog, ok := a.syncer()
	if !ok {
		return exitNotSetup
	}
	defer closeLog()

	events := s.Tick(context.Background(), *force)
	if ev := s.LastAddon; ev != nil {
		fmt.Fprintf(a.out, "Addon: %s (%s)\n", ev.Kind, ev.Message)
	}
	if len(events) == 0 {
		fmt.Fprintf(a.out, "Nothing to upload: no TradeSkillMaster.lua found, or it has not changed since the last upload. Use --force to send it anyway.\nDetails: %s\n", logx.Path(a.dir))
		return exitOK
	}

	code := exitOK
	for _, ev := range events {
		fmt.Fprintf(a.out, "%s: %s", ev.File.Label, ev.Kind)
		if ev.Message != "" {
			fmt.Fprintf(a.out, " (%s)", ev.Message)
		}
		fmt.Fprintln(a.out)

		switch ev.Kind {
		case watch.Fatal:
			code = exitCode(ev.HTTPStatus)
		case watch.Retrying, watch.Skipped:
			if code == exitOK {
				code = exitFailed
			}
		}
	}
	return code
}

func (a *app) run() int {
	s, closeLog, ok := a.syncer()
	if !ok {
		return exitNotSetup
	}
	defer closeLog()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	s.Log.Printf("uploader %s started; checking every %s", version.Version, s.Opts.Poll)
	if err := s.Run(ctx); err != nil && !errors.Is(err, context.Canceled) {
		s.Log.Printf("stopped: %v", err)
		return exitFailed
	}
	s.Log.Printf("stopped")
	return exitOK
}

func (a *app) install() int {
	if _, ok := a.load(); !ok {
		return exitNotSetup
	}

	// At login nothing carries our flags or environment, so a non-default config location has
	// to be written into the startup entry.
	configArg := ""
	if def, err := config.DefaultDir(); err != nil || def != a.dir {
		configArg = a.dir
	}
	exe, err := os.Executable()
	if err != nil {
		return a.fail(exitFailed, "error: cannot find this program's path:", err)
	}
	if exe, err = filepath.Abs(exe); err != nil {
		return a.fail(exitFailed, "error:", err)
	}

	msg, err := autostart.Install(exe, configArg)
	if err != nil {
		return a.fail(exitFailed, "error:", err)
	}
	fmt.Fprintln(a.out, msg)
	fmt.Fprintln(a.out, "Keep the program where it is: the startup entry points at "+exe)
	return exitOK
}

func (a *app) uninstall() int {
	msg, err := autostart.Uninstall()
	if err != nil {
		return a.fail(exitFailed, "error:", err)
	}
	fmt.Fprintln(a.out, msg)
	return exitOK
}

// syncer wires up config, state, log and client. The log goes to its file only: the commands
// print their own results, so console lines would be doubled.
func (a *app) syncer() (*watch.Syncer, func(), bool) {
	cfg, ok := a.load()
	if !ok {
		return nil, nil, false
	}
	st, err := state.Open(a.dir)
	if err != nil {
		fmt.Fprintln(a.err, "error: cannot read saved state:", err)
		return nil, nil, false
	}
	log, err := logx.Open(a.dir)
	if err != nil {
		fmt.Fprintln(a.err, "error: cannot open the log file:", err)
		return nil, nil, false
	}

	opts := watch.DefaultOptions()
	if cfg.PollSeconds > 0 {
		opts.Poll = time.Duration(cfg.PollSeconds) * time.Second
	}
	// Test hook: shorten the wait for TSM to finish writing.
	if ms, err := strconv.Atoi(os.Getenv("WFL_SETTLE_MS")); err == nil && ms > 0 {
		opts.StableInterval = time.Duration(ms) * time.Millisecond
	}

	root := func() (string, error) { return wow.DetectRoot(cfg.WowDir) }
	cl := client.New(cfg.Server, cfg.Token, version.Version)
	s := watch.New(root, cl, st, log, opts)

	// Re-read the config each time the addon sync is due, so 'addon enable' reaches a running
	// uploader without a restart.
	enabled := func() bool {
		c, err := config.Load(a.dir)
		return err == nil && c.AddonSync
	}
	s.Addon = addon.New(cl, st, log, enabled, addon.DefaultOptions())
	return s, func() { log.Close() }, true
}

func (a *app) addon(args []string) int {
	if len(args) != 1 || (args[0] != "enable" && args[0] != "disable" && args[0] != "status") {
		fmt.Fprintln(a.err, "usage: addon enable | disable | status")
		return exitUsage
	}
	cfg, ok := a.load()
	if !ok {
		return exitNotSetup
	}

	if args[0] != "status" {
		cfg.AddonSync = args[0] == "enable"
		if err := cfg.Save(a.dir); err != nil {
			return a.fail(exitFailed, "error: could not save settings:", err)
		}
	}

	root, err := wow.DetectRoot(cfg.WowDir)
	a.addonLine(cfg, root, err)

	switch {
	case args[0] == "enable" && err == nil && !addon.Installed(root):
		fmt.Fprintf(a.out, "Install the addon: copy the WowFlipLedger folder from the addon download into %s, then /reload in game.\n", filepath.Dir(addon.Dir(root)))
	case args[0] == "enable":
		fmt.Fprintln(a.out, "The data file is refreshed about every 5 minutes and after each upload; /reload in game to load it.")
	case args[0] == "disable":
		fmt.Fprintf(a.out, "Stopped. Data.lua is left where it is (%s); delete it yourself if you like.\n", addon.FileName)
	}
	return exitOK
}

// addonLine prints the addon sync state. rootErr is the error from detecting the WoW folder.
func (a *app) addonLine(cfg *config.Config, root string, rootErr error) {
	if !cfg.AddonSync {
		fmt.Fprintln(a.out, "Addon:    off (run 'addon enable' to feed the in-game addon)")
		return
	}
	if rootErr != nil {
		fmt.Fprintf(a.out, "Addon:    on, but the WoW folder was not found\n")
		return
	}
	if !addon.Installed(root) {
		fmt.Fprintf(a.out, "Addon:    on, but the addon is not installed (expected %s)\n", addon.Dir(root))
		return
	}
	st, _ := state.Open(a.dir)
	if w := st.GetAddon(); w.Path == addon.DataPath(root) && !w.WrittenAt.IsZero() {
		fmt.Fprintf(a.out, "Addon:    on, Data.lua written %s\n", w.WrittenAt.Local().Format("2006-01-02 15:04"))
		return
	}
	fmt.Fprintf(a.out, "Addon:    on, nothing written yet (%s)\n", addon.DataPath(root))
}

func (a *app) load() (*config.Config, bool) {
	cfg, err := config.Load(a.dir)
	if err != nil {
		fmt.Fprintln(a.err, "error:", err)
		return nil, false
	}
	return cfg, true
}

func (a *app) prompt(r *bufio.Reader, label string) string {
	fmt.Fprint(a.out, label)
	line, _ := r.ReadString('\n')
	return strings.TrimSpace(line)
}

// fail prints an error line and returns the exit code to use.
func (a *app) fail(code int, v ...any) int {
	fmt.Fprintln(a.err, v...)
	return code
}

// ping asks the server to check the token and version without uploading anything.
func ping(server, token string) (client.Result, client.Outcome, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	return client.New(server, token, version.Version).Ping(ctx)
}

func exitCode(httpStatus int) int {
	switch httpStatus {
	case 426:
		return exitUpgrade
	case 401, 403:
		return exitAuth
	}
	return exitFailed
}
