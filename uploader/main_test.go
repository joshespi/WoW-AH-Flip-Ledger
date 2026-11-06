package main

import (
	"bytes"
	"compress/gzip"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"testing"
	"time"

	"wowflipledger/uploader/internal/config"
)

const tok = "7|wfl_" + "abcdefghijklmnopqrstuvwxyz0123456789ABCD"

// mtimeAfter returns a time safely later than the file's current mtime, so a rewrite reads as a change.
func mtimeAfter(path string) time.Time {
	fi, _ := os.Stat(path)
	return fi.ModTime().Add(10 * time.Second)
}

type api struct {
	mu      sync.Mutex
	pings   int
	ingests int
	feeds   int
	bodies  []string
	games   []string
	pingSt  int
	ingSt   int
	srv     *httptest.Server
}

func newAPI(t *testing.T) *api {
	t.Helper()
	a := &api{pingSt: 200, ingSt: 200}
	a.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		a.mu.Lock()
		defer a.mu.Unlock()

		if r.Header.Get("Authorization") != "Bearer "+tok {
			w.WriteHeader(401)
			io.WriteString(w, `{"message":"Unauthenticated."}`)
			return
		}
		switch r.URL.Path {
		case "/api/v1/ping":
			a.pings++
			w.WriteHeader(a.pingSt)
			io.WriteString(w, `{"status":"ok","message":"Token accepted.","min_client_version":"0.0.0"}`)
		case "/api/v1/ingest":
			a.ingests++
			a.games = append(a.games, r.Header.Get("X-Wow-Game"))
			if zr, err := gzip.NewReader(r.Body); err == nil {
				b, _ := io.ReadAll(zr)
				a.bodies = append(a.bodies, string(b))
			}
			w.WriteHeader(a.ingSt)
			io.WriteString(w, `{"status":"ok","message":"Data received.","counts":{"sales":{"new":3},"expired":{"new":1},"cancelled":{"new":0}}}`)
		case "/api/v1/addon-feed":
			a.feeds++
			io.WriteString(w, `{"schema":1,"generated_at":1700000000,"region":"us","positions":[{"item_id":238371,"name":"Wyrmfish","held":20,"floor":400000}],"watchlist":[],"alerts":[]}`)
		default:
			w.WriteHeader(404)
		}
	}))
	t.Cleanup(a.srv.Close)
	return a
}

func (a *api) counts() (pings, ingests int) {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.pings, a.ingests
}

type result struct {
	code     int
	out, err string
}

func cli(t *testing.T, dir, stdin string, args ...string) result {
	t.Helper()
	t.Setenv("WFL_SETTLE_MS", "1")
	var out, errb bytes.Buffer
	code := run(append([]string{"--config-dir", dir}, args...), strings.NewReader(stdin), &out, &errb)
	return result{code, out.String(), errb.String()}
}

// wowTree makes <tmp>/_retail_/WTF/Account/ACCT/SavedVariables/TradeSkillMaster.lua.
func wowTree(t *testing.T, content string) (root, file string) {
	t.Helper()
	root = filepath.Join(t.TempDir(), "_retail_")
	file = filepath.Join(root, "WTF", "Account", "ACCT", "SavedVariables", "TradeSkillMaster.lua")
	os.MkdirAll(filepath.Dir(file), 0o755)
	if err := os.WriteFile(file, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
	return root, file
}

func configured(t *testing.T, a *api, wowRoot string) string {
	t.Helper()
	dir := t.TempDir()
	r := cli(t, dir, "", "setup", "--server", a.srv.URL, "--token", tok, "--wow", wowRoot, "--no-check")
	if r.code != exitOK {
		t.Fatalf("setup failed: %+v", r)
	}
	return dir
}

func TestUsageAndUnknownCommands(t *testing.T) {
	dir := t.TempDir()

	if r := cli(t, dir, ""); r.code != exitUsage || !strings.Contains(r.err, "Usage:") {
		t.Errorf("no args: %+v", r)
	}
	if r := cli(t, dir, "", "help"); r.code != exitOK || !strings.Contains(r.err, "setup") {
		t.Errorf("help: %+v", r)
	}
	if r := cli(t, dir, "", "frobnicate"); r.code != exitUsage || !strings.Contains(r.err, "unknown command") {
		t.Errorf("unknown: %+v", r)
	}
	if r := cli(t, dir, "", "version"); r.code != exitOK || strings.TrimSpace(r.out) == "" {
		t.Errorf("version: %+v", r)
	}
}

func TestConfigDirFlagForms(t *testing.T) {
	dir := t.TempDir()
	var out, errb bytes.Buffer
	if code := run([]string{"--config-dir=" + dir, "status"}, strings.NewReader(""), &out, &errb); code != exitNotSetup {
		t.Errorf("--config-dir=DIR form: code %d, %s", code, errb.String())
	}
}

func TestCommandsNeedSetupFirst(t *testing.T) {
	dir := t.TempDir()
	for _, cmd := range []string{"status", "once", "run", "install"} {
		if r := cli(t, dir, "", cmd); r.code != exitNotSetup || !strings.Contains(r.err, "setup") {
			t.Errorf("%s before setup: %+v", cmd, r)
		}
	}
}

func TestSetupSavesAfterAVerifiedPing(t *testing.T) {
	a := newAPI(t)
	root, _ := wowTree(t, "TradeSkillMasterDB = {}")
	dir := t.TempDir()

	r := cli(t, dir, "", "setup", "--server", a.srv.URL+"/", "--token", " "+tok+" ", "--wow", root)
	if r.code != exitOK || !strings.Contains(r.out, "token accepted") || !strings.Contains(r.out, "1 data file") {
		t.Fatalf("setup: %+v", r)
	}

	cfg, err := config.Load(dir)
	if err != nil || cfg.Server != a.srv.URL || cfg.Token != tok || cfg.WowDir != root {
		t.Fatalf("saved config = %+v, %v", cfg, err)
	}
	if runtime.GOOS != "windows" {
		if fi, _ := os.Stat(filepath.Join(dir, "config.json")); fi.Mode().Perm() != 0o600 {
			t.Errorf("config mode = %v", fi.Mode().Perm())
		}
	}
	if strings.Contains(r.out+r.err, tok) {
		t.Error("setup output must not echo the token")
	}
	if p, _ := a.counts(); p != 1 {
		t.Errorf("pings = %d", p)
	}
}

func TestSetupPromptsWhenFlagsAreMissing(t *testing.T) {
	a := newAPI(t)
	dir := t.TempDir()

	r := cli(t, dir, a.srv.URL+"\n"+tok+"\n", "setup")
	if r.code != exitOK {
		t.Fatalf("setup: %+v", r)
	}
	if cfg, err := config.Load(dir); err != nil || cfg.Token != tok {
		t.Errorf("config = %+v, %v", cfg, err)
	}
}

func TestSetupRejectsBadInputWithoutSaving(t *testing.T) {
	dir := t.TempDir()
	for name, args := range map[string][]string{
		"plain http to a real host": {"--server", "http://example.com", "--token", tok},
		"not a url":                 {"--server", "wowledger", "--token", tok},
		"malformed token":           {"--server", "https://example.com", "--token", "nope"},
	} {
		r := cli(t, dir, "", append([]string{"setup"}, args...)...)
		if r.code != exitUsage {
			t.Errorf("%s: code %d, %+v", name, r.code, r)
		}
	}
	if _, err := config.Load(dir); err == nil {
		t.Error("nothing should have been saved")
	}
}

func TestSetupRefusesATokenTheServerRejects(t *testing.T) {
	a := newAPI(t)
	dir := t.TempDir()
	badTok := "9|wfl_" + strings.Repeat("z", 40)

	r := cli(t, dir, "", "setup", "--server", a.srv.URL, "--token", badTok)
	if r.code != exitAuth || !strings.Contains(r.err, "Nothing was saved") {
		t.Fatalf("setup: %+v", r)
	}
	if _, err := config.Load(dir); err == nil {
		t.Error("a rejected token must not be saved")
	}

	if r := cli(t, dir, "", "setup", "--server", a.srv.URL, "--token", badTok, "--no-check"); r.code != exitOK {
		t.Errorf("--no-check should save anyway: %+v", r)
	}
}

func TestSetupSavesWithAWarningWhenTheServerIsUnreachable(t *testing.T) {
	a := newAPI(t)
	url := a.srv.URL
	a.srv.Close()
	dir := t.TempDir()

	r := cli(t, dir, "", "setup", "--server", url, "--token", tok)
	if r.code != exitOK || !strings.Contains(r.err, "Saving anyway") {
		t.Fatalf("setup: %+v", r)
	}
	if _, err := config.Load(dir); err != nil {
		t.Errorf("should have saved: %v", err)
	}
}

func TestOnceUploadsThenReportsNothingToDo(t *testing.T) {
	a := newAPI(t)
	root, file := wowTree(t, "TradeSkillMasterDB = {} -- v1")
	dir := configured(t, a, root)

	r := cli(t, dir, "", "once")
	if r.code != exitOK || !strings.Contains(r.out, "uploaded") || !strings.Contains(r.out, "3 new sales") {
		t.Fatalf("first once: %+v", r)
	}
	if _, n := a.counts(); n != 1 || a.bodies[0] != "TradeSkillMasterDB = {} -- v1" {
		t.Fatalf("ingests = %d bodies = %q", n, a.bodies)
	}

	r = cli(t, dir, "", "once")
	if r.code != exitOK || !strings.Contains(r.out, "Nothing to upload") {
		t.Fatalf("second once: %+v", r)
	}
	if _, n := a.counts(); n != 1 {
		t.Errorf("unchanged file was re-sent (%d ingests)", n)
	}

	if r = cli(t, dir, "", "once", "--force"); !strings.Contains(r.out, "uploaded") {
		t.Fatalf("forced once: %+v", r)
	}
	if _, n := a.counts(); n != 2 {
		t.Errorf("--force should re-send (%d ingests)", n)
	}

	os.WriteFile(file, []byte("TradeSkillMasterDB = {} -- v2"), 0o644)
	os.Chtimes(file, mtimeAfter(file), mtimeAfter(file))
	if r = cli(t, dir, "", "once"); !strings.Contains(r.out, "uploaded") {
		t.Fatalf("changed file: %+v", r)
	}
	if strings.Contains(r.out+r.err, tok) {
		t.Error("output must not contain the token")
	}
}

func (a *api) feedCalls() int {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.feeds
}

func TestAddonSyncIsOffUntilEnabled(t *testing.T) {
	a := newAPI(t)
	root, _ := wowTree(t, "TradeSkillMasterDB = {}")
	os.MkdirAll(filepath.Join(root, "Interface", "AddOns", "WowFlipLedger"), 0o755)
	dir := configured(t, a, root)

	r := cli(t, dir, "", "once")
	if !strings.Contains(r.out, "uploaded") || strings.Contains(r.out, "Addon:") {
		t.Fatalf("once: %+v", r)
	}
	if a.feedCalls() != 0 {
		t.Error("fetched the feed while addon sync is off")
	}
	if _, err := os.Stat(filepath.Join(root, "Interface", "AddOns", "WowFlipLedger", "Data.lua")); err == nil {
		t.Error("Data.lua written while addon sync is off")
	}
	if r = cli(t, dir, "", "status"); !strings.Contains(r.out, "Addon:    off") {
		t.Errorf("status: %+v", r)
	}
}

func TestAddonEnableThenOnceWritesDataLuaAndNothingElse(t *testing.T) {
	a := newAPI(t)
	root, tsm := wowTree(t, "TradeSkillMasterDB = {}")
	folder := filepath.Join(root, "Interface", "AddOns", "WowFlipLedger")
	os.MkdirAll(folder, 0o755)
	os.WriteFile(filepath.Join(folder, "WowFlipLedger.toc"), []byte("## Title: WFL"), 0o644)
	dir := configured(t, a, root)

	if r := cli(t, dir, "", "addon", "enable"); r.code != exitOK || !strings.Contains(r.out, "Addon:    on") {
		t.Fatalf("enable: %+v", r)
	}

	before := fileTree(t, root)
	r := cli(t, dir, "", "once")
	if r.code != exitOK || !strings.Contains(r.out, "Addon: written") || !strings.Contains(r.out, "uploaded") {
		t.Fatalf("once: %+v", r)
	}

	data, err := os.ReadFile(filepath.Join(folder, "Data.lua"))
	if err != nil || !strings.Contains(string(data), `WFL_FEED = {`) || !strings.Contains(string(data), `["name"]="Wyrmfish"`) {
		t.Fatalf("Data.lua = %q (%v)", data, err)
	}

	after := fileTree(t, root)
	for p, sum := range after {
		if before[p] != sum && p != filepath.Join(folder, "Data.lua") {
			t.Errorf("%s changed", p)
		}
	}
	if got, _ := os.ReadFile(tsm); string(got) != "TradeSkillMasterDB = {}" {
		t.Error("the TSM file was modified")
	}
	if strings.Contains(r.out+r.err, tok) {
		t.Error("output must not contain the token")
	}

	if r = cli(t, dir, "", "status"); !strings.Contains(r.out, "Data.lua written") {
		t.Errorf("status: %+v", r)
	}

	if r = cli(t, dir, "", "addon", "disable"); r.code != exitOK || !strings.Contains(r.out, "Addon:    off") {
		t.Fatalf("disable: %+v", r)
	}
	calls := a.feedCalls()
	cli(t, dir, "", "once", "--force")
	if a.feedCalls() != calls {
		t.Error("fetched the feed after disable")
	}
}

func TestAddonEnableExplainsAMissingAddonAndNeverCreatesIt(t *testing.T) {
	a := newAPI(t)
	root, _ := wowTree(t, "TradeSkillMasterDB = {}")
	dir := configured(t, a, root)

	r := cli(t, dir, "", "addon", "enable")
	if r.code != exitOK || !strings.Contains(r.out, "not installed") || !strings.Contains(r.out, "Install the addon") {
		t.Fatalf("enable: %+v", r)
	}

	r = cli(t, dir, "", "once")
	if !strings.Contains(r.out, "Addon: skipped") {
		t.Errorf("once: %+v", r)
	}
	if a.feedCalls() != 0 {
		t.Error("fetched the feed with nowhere to write")
	}
	if _, err := os.Stat(filepath.Join(root, "Interface")); err == nil {
		t.Error("the Interface folder was created")
	}
}

func TestAddonCommandUsage(t *testing.T) {
	a := newAPI(t)
	root, _ := wowTree(t, "x")
	dir := configured(t, a, root)

	if r := cli(t, dir, "", "addon"); r.code != exitUsage {
		t.Errorf("no argument: %+v", r)
	}
	if r := cli(t, dir, "", "addon", "bogus"); r.code != exitUsage {
		t.Errorf("bad argument: %+v", r)
	}
	if r := cli(t, t.TempDir(), "", "addon", "enable"); r.code != exitNotSetup {
		t.Errorf("not set up: %+v", r)
	}
}

// fileTree maps every file under root to its content hash.
func fileTree(t *testing.T, root string) map[string]string {
	t.Helper()
	out := map[string]string{}
	filepath.WalkDir(root, func(p string, d os.DirEntry, err error) error {
		if err == nil && !d.IsDir() {
			b, _ := os.ReadFile(p)
			out[p] = string(b)
		}
		return nil
	})
	return out
}

func TestOnceExitCodesTellScriptsWhatHappened(t *testing.T) {
	a := newAPI(t)
	root, _ := wowTree(t, "x")

	a.ingSt = 500
	if r := cli(t, configured(t, a, root), "", "once"); r.code != exitFailed || !strings.Contains(r.out, "retrying") {
		t.Errorf("server error: %+v", r)
	}

	a.ingSt = 426
	if r := cli(t, configured(t, a, root), "", "once"); r.code != exitUpgrade {
		t.Errorf("426: %+v", r)
	}

	a.ingSt = 200
	dir := t.TempDir()
	cli(t, dir, "", "setup", "--server", a.srv.URL, "--token", "9|wfl_"+strings.Repeat("q", 40), "--wow", root, "--no-check")
	if r := cli(t, dir, "", "once"); r.code != exitAuth {
		t.Errorf("revoked token: %+v", r)
	}
}

func TestOnceWithNoWoWFolderExplainsHowToFixIt(t *testing.T) {
	a := newAPI(t)
	dir := t.TempDir()
	cli(t, dir, "", "setup", "--server", a.srv.URL, "--token", tok, "--wow", filepath.Join(t.TempDir(), "missing"), "--no-check")

	r := cli(t, dir, "", "once")
	if r.code != exitOK || !strings.Contains(r.out, "Nothing to upload") {
		t.Errorf("once: %+v", r)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "uploader.log")); !strings.Contains(string(b), "WoW folder") {
		t.Errorf("log should explain the problem: %q", b)
	}
}

func TestStatusShowsConfigurationWithoutTheSecret(t *testing.T) {
	a := newAPI(t)
	root, _ := wowTree(t, "x")
	dir := configured(t, a, root)
	cli(t, dir, "", "once")

	r := cli(t, dir, "", "status")
	if r.code != exitOK {
		t.Fatalf("status: %+v", r)
	}
	for _, want := range []string{a.srv.URL, "7|wfl_", "acct-", "ok at", "Server:   OK"} {
		if !strings.Contains(r.out, want) {
			t.Errorf("status output missing %q:\n%s", want, r.out)
		}
	}
	if strings.Contains(r.out, tok) || strings.Contains(r.out, tok[10:30]) {
		t.Errorf("status leaked the token:\n%s", r.out)
	}
	if strings.Contains(r.out, "ACCT") {
		t.Errorf("status should show the label, not the account folder name:\n%s", r.out)
	}
}

func TestInstallAndUninstallOnUnsupportedSystems(t *testing.T) {
	if runtime.GOOS == "windows" || runtime.GOOS == "darwin" {
		t.Skip("covered by the autostart package on these systems")
	}
	a := newAPI(t)
	root, _ := wowTree(t, "x")
	dir := configured(t, a, root)

	if r := cli(t, dir, "", "install"); r.code != exitFailed || !strings.Contains(r.err, "Windows and macOS") {
		t.Errorf("install: %+v", r)
	}
	if r := cli(t, dir, "", "uninstall"); r.code != exitFailed {
		t.Errorf("uninstall: %+v", r)
	}
}

func TestOnceUploadsTheAddonRecordToo(t *testing.T) {
	a := newAPI(t)
	root, _ := wowTree(t, "TradeSkillMasterDB = {}")
	rec := filepath.Join(root, "WTF", "Account", "ACCT", "SavedVariables", "WowFlipLedger.lua")
	os.WriteFile(rec, []byte("WowFlipLedgerDB = {}"), 0o644)
	dir := configured(t, a, root)

	r := cli(t, dir, "", "once")
	if r.code != exitOK || strings.Count(r.out, "uploaded") != 2 {
		t.Fatalf("once: %+v", r)
	}
	if _, n := a.counts(); n != 2 {
		t.Fatalf("ingests = %d, want TSM and addon", n)
	}
	got := strings.Join(a.bodies, "\n")
	if !strings.Contains(got, "TradeSkillMasterDB") || !strings.Contains(got, "WowFlipLedgerDB") {
		t.Errorf("bodies: %q", a.bodies)
	}

	// Only the addon file changes: only it is sent again.
	os.WriteFile(rec, []byte("WowFlipLedgerDB = { } -- v2"), 0o644)
	os.Chtimes(rec, mtimeAfter(rec), mtimeAfter(rec))
	cli(t, dir, "", "once")
	if _, n := a.counts(); n != 3 {
		t.Errorf("ingests = %d, want 3 (only the changed file re-sent)", n)
	}

	if r = cli(t, dir, "", "status"); !strings.Contains(r.out, "acct-") || !strings.Contains(r.out, "-addon") {
		t.Errorf("status should list both files: %s", r.out)
	}
}

func TestForeverFilesAreSentTaggedWithTheirGame(t *testing.T) {
	a := newAPI(t)
	root, _ := wowTree(t, "TradeSkillMasterDB = {} -- retail")
	forever := filepath.Join(filepath.Dir(root), "_forever_", "WTF", "Account", "ACCT", "SavedVariables")
	os.MkdirAll(forever, 0o755)
	os.WriteFile(filepath.Join(forever, "WowFlipLedger.lua"), []byte("WowFlipLedgerDB = {} -- forever"), 0o644)
	dir := configured(t, a, root)

	if r := cli(t, dir, "", "status"); !strings.Contains(r.out, "Forever:") {
		t.Errorf("status should say Forever was found: %s", r.out)
	}
	cli(t, dir, "", "once")

	seen := map[string]string{}
	for i, b := range a.bodies {
		seen[b] = a.games[i]
	}
	if seen["TradeSkillMasterDB = {} -- retail"] != "retail" {
		t.Errorf("retail file game = %q", seen["TradeSkillMasterDB = {} -- retail"])
	}
	if seen["WowFlipLedgerDB = {} -- forever"] != "forever" {
		t.Errorf("forever file should be sent with game=forever: %v", seen)
	}
}
