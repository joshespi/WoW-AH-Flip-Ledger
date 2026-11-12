package addon

import (
	"context"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"wowflipledger/uploader/internal/client"
	"wowflipledger/uploader/internal/fsx"
	"wowflipledger/uploader/internal/state"
	"wowflipledger/uploader/internal/wow"
)

type fakeFeed struct {
	body    string
	outcome client.Outcome
	res     client.Result
	calls   int
}

func (f *fakeFeed) Feed(context.Context) ([]byte, client.Result, client.Outcome, error) {
	f.calls++
	return []byte(f.body), f.res, f.outcome, nil
}

type nopLog struct{ lines []string }

func (l *nopLog) Printf(format string, args ...any) {
	l.lines = append(l.lines, strings.TrimSpace(format))
}

const feedV1 = `{"schema":1,"generated_at":100,"positions":[]}`

// wowTree makes a _retail_ folder with the WTF tree, a TSM file, and (when installed) the addon
// folder, and returns the root.
func wowTree(t *testing.T, installed bool) string {
	t.Helper()
	root := t.TempDir()
	sv := wow.SavedVariablesDir(root, "ACCT")
	if err := os.MkdirAll(sv, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(sv, "TradeSkillMaster.lua"), []byte("TSM = {}"), 0o644); err != nil {
		t.Fatal(err)
	}
	if installed {
		if err := os.MkdirAll(Dir(root), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(Dir(root), "WowFlipLedger.toc"), []byte("## Title: x"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return root
}

// snapshot lists every path under root: folders by existence (adding a file always bumps its
// folder's mtime), files by content hash.
func snapshot(t *testing.T, root string) map[string]string {
	t.Helper()
	out := map[string]string{}
	err := filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() {
			out[p] = "dir"
			return nil
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		out[p] = fsx.SHA256Hex(b)
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	return out
}

func newSync(t *testing.T, feed *fakeFeed, enabled bool, now *time.Time) (*Sync, *nopLog) {
	t.Helper()
	st, err := state.Open(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	opts := DefaultOptions()
	opts.Now = func() time.Time { return *now }
	log := &nopLog{}
	return New(feed, st, log, func() bool { return enabled }, opts), log
}

func TestWriteReplacesTheFileAtomicallyAndLeavesNoTemp(t *testing.T) {
	root := wowTree(t, true)

	for _, content := range []string{"one", "two"} {
		if err := Write(root, []byte(content)); err != nil {
			t.Fatal(err)
		}
		got, _ := os.ReadFile(DataPath(root))
		if string(got) != content {
			t.Errorf("got %q want %q", got, content)
		}
	}

	if _, err := os.Stat(DataPath(root) + ".tmp"); !errors.Is(err, os.ErrNotExist) {
		t.Error("temp file left behind")
	}
}

func TestWriteNeverCreatesTheAddonFolder(t *testing.T) {
	root := wowTree(t, false)

	if err := Write(root, []byte("x")); !errors.Is(err, ErrNotInstalled) {
		t.Fatalf("want ErrNotInstalled, got %v", err)
	}
	if _, err := os.Stat(filepath.Join(root, "Interface")); !errors.Is(err, os.ErrNotExist) {
		t.Error("Interface folder was created")
	}
}

func TestWriteRefusesAnIrregularDataFile(t *testing.T) {
	root := wowTree(t, true)
	if err := os.Mkdir(DataPath(root), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := Write(root, []byte("x")); err == nil {
		t.Fatal("expected a refusal when Data.lua is a directory")
	}
}

func TestWriteDoesNotWriteThroughAPlantedTempFile(t *testing.T) {
	root := wowTree(t, true)
	victim := filepath.Join(t.TempDir(), "victim.txt")
	if err := os.WriteFile(victim, []byte("keep"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(victim, DataPath(root)+".tmp"); err != nil {
		t.Skipf("symlinks unavailable: %v", err)
	}

	if err := Write(root, []byte("new")); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(victim); string(got) != "keep" {
		t.Errorf("wrote through the symlink: %q", got)
	}
}

func TestWriteRefusesASymlinkedAddonFolder(t *testing.T) {
	root := wowTree(t, false)
	target := t.TempDir()
	if err := os.MkdirAll(filepath.Dir(Dir(root)), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(target, Dir(root)); err != nil {
		t.Skipf("symlinks unavailable: %v", err)
	}

	if err := Write(root, []byte("x")); !errors.Is(err, ErrNotInstalled) {
		t.Fatalf("want ErrNotInstalled, got %v", err)
	}
	if entries, _ := os.ReadDir(target); len(entries) != 0 {
		t.Error("wrote into the symlink target")
	}
}

// The core promise: a full sync changes exactly one path under the WoW folder.
func TestSyncChangesOnlyDataLua(t *testing.T) {
	root := wowTree(t, true)
	before := snapshot(t, root)

	now := time.Unix(1_700_000_000, 0)
	s, _ := newSync(t, &fakeFeed{body: feedV1, outcome: client.OK}, true, &now)

	if ev := s.Tick(context.Background(), root, false); ev == nil || ev.Kind != Written {
		t.Fatalf("want Written, got %+v", ev)
	}

	after := snapshot(t, root)
	var changed []string
	for p, v := range after {
		if before[p] != v {
			changed = append(changed, p)
		}
	}
	for p := range before {
		if _, ok := after[p]; !ok {
			t.Errorf("%s was removed", p)
		}
	}

	if len(changed) != 1 || changed[0] != DataPath(root) {
		t.Errorf("expected only %s to change, got %v", DataPath(root), changed)
	}
}

func TestSyncOffTouchesNothingAndDoesNotCallTheServer(t *testing.T) {
	root := wowTree(t, true)
	before := snapshot(t, root)
	feed := &fakeFeed{body: feedV1, outcome: client.OK}
	now := time.Unix(1_700_000_000, 0)
	s, _ := newSync(t, feed, false, &now)

	if ev := s.Tick(context.Background(), root, true); ev != nil {
		t.Errorf("want no event, got %+v", ev)
	}
	if feed.calls != 0 {
		t.Error("fetched the feed while sync is off")
	}
	if got := snapshot(t, root); len(got) != len(before) {
		t.Error("files changed while sync is off")
	}
}

func TestSyncSkipsQuietlyWhenTheAddonIsNotInstalled(t *testing.T) {
	root := wowTree(t, false)
	feed := &fakeFeed{body: feedV1, outcome: client.OK}
	now := time.Unix(1_700_000_000, 0)
	s, log := newSync(t, feed, true, &now)

	for range 3 {
		if ev := s.Tick(context.Background(), root, true); ev == nil || ev.Kind != Skipped {
			t.Fatalf("want Skipped, got %+v", ev)
		}
	}
	if feed.calls != 0 {
		t.Error("hit the server with nowhere to write")
	}
	if len(log.lines) != 1 {
		t.Errorf("a standing problem should log once, logged %d times", len(log.lines))
	}
}

func TestSyncRewritesOnlyWhenContentChangesOrGetsOld(t *testing.T) {
	root := wowTree(t, true)
	feed := &fakeFeed{body: feedV1, outcome: client.OK}
	now := time.Unix(1_700_000_000, 0)
	s, _ := newSync(t, feed, true, &now)
	ctx := context.Background()

	if ev := s.Tick(ctx, root, false); ev.Kind != Written {
		t.Fatalf("first: %+v", ev)
	}

	// Not due yet: no call at all.
	now = now.Add(time.Minute)
	if ev := s.Tick(ctx, root, false); ev != nil || feed.calls != 1 {
		t.Fatalf("not due: ev=%+v calls=%d", ev, feed.calls)
	}

	// Due, only generated_at moved: nothing to write.
	feed.body = strings.Replace(feedV1, `"generated_at":100`, `"generated_at":400`, 1)
	now = now.Add(10 * time.Minute)
	if ev := s.Tick(ctx, root, false); ev.Kind != Unchanged {
		t.Fatalf("same content: %+v", ev)
	}

	// Real change.
	feed.body = `{"schema":1,"generated_at":500,"positions":[{"item_id":1}]}`
	now = now.Add(10 * time.Minute)
	if ev := s.Tick(ctx, root, false); ev.Kind != Written {
		t.Fatalf("changed: %+v", ev)
	}

	// Unchanged for over an hour: rewritten so the addon's "as of" time moves.
	now = now.Add(61 * time.Minute)
	if ev := s.Tick(ctx, root, false); ev.Kind != Written {
		t.Fatalf("old: %+v", ev)
	}
}

func TestSyncRewritesIfDataLuaWasDeleted(t *testing.T) {
	root := wowTree(t, true)
	now := time.Unix(1_700_000_000, 0)
	s, _ := newSync(t, &fakeFeed{body: feedV1, outcome: client.OK}, true, &now)

	s.Tick(context.Background(), root, false)
	if err := os.Remove(DataPath(root)); err != nil {
		t.Fatal(err)
	}
	now = now.Add(10 * time.Minute)

	if ev := s.Tick(context.Background(), root, false); ev.Kind != Written {
		t.Fatalf("want Written, got %+v", ev)
	}
}

func TestSyncRefreshForcesTheNextFetch(t *testing.T) {
	root := wowTree(t, true)
	feed := &fakeFeed{body: feedV1, outcome: client.OK}
	now := time.Unix(1_700_000_000, 0)
	s, _ := newSync(t, feed, true, &now)

	s.Tick(context.Background(), root, false)
	s.Refresh()
	now = now.Add(time.Second)

	if ev := s.Tick(context.Background(), root, false); ev == nil || feed.calls != 2 {
		t.Fatalf("refresh did not fetch: ev=%+v calls=%d", ev, feed.calls)
	}
}

func TestSyncOutcomes(t *testing.T) {
	cases := []struct {
		name    string
		outcome client.Outcome
		res     client.Result
		body    string
		kind    Kind
		minWait time.Duration
	}{
		{"retry backs off", client.Retry, client.Result{Message: "boom"}, "", Retrying, 30 * time.Second},
		{"retry honors Retry-After", client.Retry, client.Result{RetryAfter: 20 * time.Minute}, "", Retrying, 20 * time.Minute},
		{"fatal waits an hour", client.Fatal, client.Result{HTTPStatus: 426, Message: "update"}, "", Fatal, time.Hour},
		{"rejected waits the interval", client.Rejected, client.Result{Message: "too big"}, "", Skipped, 5 * time.Minute},
		{"bad feed waits the interval", client.OK, client.Result{}, `{"schema":9}`, Skipped, 5 * time.Minute},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			root := wowTree(t, true)
			feed := &fakeFeed{body: c.body, outcome: c.outcome, res: c.res}
			now := time.Unix(1_700_000_000, 0)
			s, _ := newSync(t, feed, true, &now)

			ev := s.Tick(context.Background(), root, false)
			if ev == nil || ev.Kind != c.kind {
				t.Fatalf("want %s, got %+v", c.kind, ev)
			}
			if _, err := os.Stat(DataPath(root)); !errors.Is(err, os.ErrNotExist) {
				t.Error("Data.lua written on a failure")
			}

			// Still waiting just before the minimum, due right after.
			now = now.Add(c.minWait - time.Second)
			if s.Tick(context.Background(), root, false) != nil {
				t.Error("fetched again before the wait was over")
			}
			now = now.Add(2 * time.Second)
			if s.Tick(context.Background(), root, false) == nil {
				t.Error("did not fetch after the wait")
			}
		})
	}
}

func TestBackoffGrowsAndCaps(t *testing.T) {
	now := time.Unix(1_700_000_000, 0)
	s, _ := newSync(t, &fakeFeed{}, true, &now)

	want := []time.Duration{30 * time.Second, time.Minute, 2 * time.Minute, 4 * time.Minute}
	for i, w := range want {
		s.fails = i + 1
		if got := s.backoff(); got != w {
			t.Errorf("fail %d: got %s want %s", i+1, got, w)
		}
	}

	s.fails = 50
	if got := s.backoff(); got != 30*time.Minute {
		t.Errorf("cap: got %s", got)
	}
}
