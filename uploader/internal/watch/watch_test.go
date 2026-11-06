package watch

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"wowflipledger/uploader/internal/client"
	"wowflipledger/uploader/internal/state"
	"wowflipledger/uploader/internal/wow"
)

type reply struct {
	res     client.Result
	outcome client.Outcome
	err     error
}

type fakeUp struct {
	queue []reply
	calls []call
}

type call struct {
	data  string
	mtime time.Time
	label string
}

func (f *fakeUp) Upload(_ context.Context, data []byte, mtime time.Time, label, game string) (client.Result, client.Outcome, error) {
	f.calls = append(f.calls, call{string(data), mtime, label})
	if len(f.queue) == 0 {
		return client.Result{HTTPStatus: 200, Status: "ok"}, client.OK, nil
	}
	r := f.queue[0]
	f.queue = f.queue[1:]
	return r.res, r.outcome, r.err
}

type logs struct{ lines []string }

func (l *logs) Printf(format string, args ...any) {
	l.lines = append(l.lines, fmt.Sprintf(format, args...))
}

type env struct {
	t     *testing.T
	root  string
	up    *fakeUp
	log   *logs
	st    *state.Store
	now   time.Time
	opts  Options
	s     *Syncer
	sleep func()
}

var epoch = time.Unix(1_800_000_000, 0)

func newEnv(t *testing.T, accounts ...string) *env {
	t.Helper()
	e := &env{t: t, root: t.TempDir(), up: &fakeUp{}, log: &logs{}, now: epoch}
	if len(accounts) == 0 {
		accounts = []string{"ACCT1"}
	}
	for _, a := range accounts {
		e.write(a, "v1", 1000)
	}

	st, err := state.Open(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	e.st = st

	e.opts = DefaultOptions()
	e.opts.Now = func() time.Time { return e.now }
	e.opts.Sleep = func(ctx context.Context, d time.Duration) error {
		e.now = e.now.Add(d)
		if e.sleep != nil {
			e.sleep()
		}
		return ctx.Err()
	}
	e.opts.Jitter = func(d time.Duration) time.Duration { return d }

	e.s = New(func() (string, error) { return e.root, nil }, e.up, e.st, e.log, e.opts)
	return e
}

func (e *env) path(account string) string {
	return filepath.Join(e.root, "WTF", "Account", account, "SavedVariables", "TradeSkillMaster.lua")
}

func (e *env) write(account, content string, mtime int64) {
	e.t.Helper()
	p := e.path(account)
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		e.t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
		e.t.Fatal(err)
	}
	if err := os.Chtimes(p, time.Unix(mtime, 0), time.Unix(mtime, 0)); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) tick(force bool) []Event { return e.s.Tick(context.Background(), force) }

func kinds(evs []Event) string {
	var k []string
	for _, e := range evs {
		k = append(k, string(e.Kind))
	}
	return strings.Join(k, ",")
}

func TestUploadsOnceThenStaysQuietWhileTheFileIsUnchanged(t *testing.T) {
	e := newEnv(t)

	if got := kinds(e.tick(false)); got != "uploaded" {
		t.Fatalf("first tick = %q", got)
	}
	if len(e.up.calls) != 1 || e.up.calls[0].data != "v1" || e.up.calls[0].mtime.Unix() != 1000 || e.up.calls[0].label != wow.Label("ACCT1") {
		t.Fatalf("upload call = %+v", e.up.calls)
	}

	if evs := e.tick(false); len(evs) != 0 {
		t.Errorf("an unchanged file should produce no events, got %v", kinds(evs))
	}
	if len(e.up.calls) != 1 {
		t.Errorf("uploaded again without a change: %d calls", len(e.up.calls))
	}
}

func TestSameContentWithANewMtimeIsNotResent(t *testing.T) {
	e := newEnv(t)
	e.tick(false)

	e.write("ACCT1", "v1", 2000) // a /reload that wrote identical content

	if got := kinds(e.tick(false)); got != "unchanged" {
		t.Fatalf("tick = %q, want unchanged", got)
	}
	if len(e.up.calls) != 1 {
		t.Errorf("identical content was re-uploaded")
	}
	if evs := e.tick(false); len(evs) != 0 {
		t.Errorf("new mtime should have been remembered, got %v", kinds(evs))
	}
}

func TestChangedContentIsUploaded(t *testing.T) {
	e := newEnv(t)
	e.tick(false)

	e.write("ACCT1", "v2 with more sales", 2000)

	if got := kinds(e.tick(false)); got != "uploaded" {
		t.Fatalf("tick = %q", got)
	}
	if e.up.calls[1].data != "v2 with more sales" || e.up.calls[1].mtime.Unix() != 2000 {
		t.Errorf("second upload = %+v", e.up.calls[1])
	}
}

func TestForceUploadsUnchangedContent(t *testing.T) {
	e := newEnv(t)
	e.tick(false)

	if got := kinds(e.tick(true)); got != "uploaded" {
		t.Fatalf("forced tick = %q", got)
	}
	if len(e.up.calls) != 2 {
		t.Errorf("calls = %d, want 2", len(e.up.calls))
	}
}

func TestStateSurvivesARestart(t *testing.T) {
	e := newEnv(t)
	e.tick(false)

	// A new Syncer sharing the same persisted state (a restart) must not re-upload.
	s2 := New(func() (string, error) { return e.root, nil }, e.up, e.st, e.log, e.opts)
	if evs := s2.Tick(context.Background(), false); len(evs) != 0 {
		t.Errorf("restart re-sent an unchanged file: %v", kinds(evs))
	}
}

func TestEachAccountIsUploadedWithItsOwnLabel(t *testing.T) {
	e := newEnv(t, "ACCT1", "ACCT2")

	if got := kinds(e.tick(false)); got != "uploaded,uploaded" {
		t.Fatalf("tick = %q", got)
	}
	if e.up.calls[0].label == e.up.calls[1].label {
		t.Errorf("accounts shared a label: %+v", e.up.calls)
	}
}

func TestRejectedContentIsNotRetriedUntilItChanges(t *testing.T) {
	e := newEnv(t)
	e.up.queue = []reply{{client.Result{HTTPStatus: 422, Message: "not a TSM file"}, client.Rejected, nil}}

	evs := e.tick(false)
	if kinds(evs) != "rejected" || evs[0].Message != "not a TSM file" {
		t.Fatalf("tick = %+v", evs)
	}

	e.write("ACCT1", "v1", 2000) // touched, same content
	if got := kinds(e.tick(false)); got != "unchanged" || len(e.up.calls) != 1 {
		t.Fatalf("rejected content was retried: kinds=%q calls=%d", got, len(e.up.calls))
	}

	e.write("ACCT1", "v2", 3000) // new content gets another chance
	if got := kinds(e.tick(false)); got != "uploaded" || len(e.up.calls) != 2 {
		t.Fatalf("changed content should be tried again: kinds=%q calls=%d", got, len(e.up.calls))
	}
}

func TestRetriesBackOffExponentiallyAndRecover(t *testing.T) {
	e := newEnv(t)
	boom := reply{client.Result{HTTPStatus: 502}, client.Retry, errors.New("could not reach the server")}
	e.up.queue = []reply{boom, boom, boom}

	if got := kinds(e.tick(false)); got != "retrying" { // fail 1: wait 5s
		t.Fatalf("tick 1 = %q", got)
	}
	e.now = e.now.Add(4 * time.Second)
	if evs := e.tick(false); len(evs) != 0 || len(e.up.calls) != 1 {
		t.Fatalf("retried before the backoff elapsed: %v, calls=%d", kinds(evs), len(e.up.calls))
	}

	e.now = e.now.Add(2 * time.Second) // 6s since failure 1
	e.tick(false)                      // fail 2: wait 10s
	if len(e.up.calls) != 2 {
		t.Fatalf("expected a second attempt, calls=%d", len(e.up.calls))
	}
	e.now = e.now.Add(9 * time.Second)
	if e.tick(false); len(e.up.calls) != 2 {
		t.Fatalf("second backoff should be 10s, but retried after 9s")
	}
	e.now = e.now.Add(2 * time.Second)
	e.tick(false) // fail 3: wait 20s
	e.now = e.now.Add(19 * time.Second)
	if e.tick(false); len(e.up.calls) != 3 {
		t.Fatalf("third backoff should be 20s, but retried after 19s")
	}

	e.now = e.now.Add(2 * time.Second)
	if got := kinds(e.tick(false)); got != "uploaded" { // queue empty: success
		t.Fatalf("recovery tick = %q", got)
	}

	// Success resets the counter: the next failure waits 5s again.
	e.write("ACCT1", "v2", 5000)
	e.up.queue = []reply{boom}
	e.tick(false)
	e.now = e.now.Add(6 * time.Second)
	if e.tick(false); len(e.up.calls) != 6 {
		t.Errorf("backoff did not reset after a success, calls=%d", len(e.up.calls))
	}
}

func TestBackoffIsCappedAndRetryAfterWins(t *testing.T) {
	e := newEnv(t)
	if d := e.s.backoff(30, 0); d != e.opts.BackoffMax {
		t.Errorf("backoff(30) = %v, want cap %v", d, e.opts.BackoffMax)
	}
	if d := e.s.backoff(1, 90*time.Second); d != 90*time.Second {
		t.Errorf("a larger Retry-After should win, got %v", d)
	}
	if d := e.s.backoff(1, time.Second); d != e.opts.BackoffBase {
		t.Errorf("a smaller Retry-After should not shorten the backoff, got %v", d)
	}
}

func TestFatalErrorsWaitAnHourAndSayWhatToDo(t *testing.T) {
	e := newEnv(t)
	e.up.queue = []reply{{client.Result{HTTPStatus: 401, Message: "Unauthenticated."}, client.Fatal, nil}}

	evs := e.tick(false)
	if kinds(evs) != "fatal" || evs[0].HTTPStatus != 401 {
		t.Fatalf("tick = %+v", evs)
	}

	e.now = e.now.Add(59 * time.Minute)
	if e.tick(false); len(e.up.calls) != 1 {
		t.Fatalf("retried a fatal error inside the hour")
	}
	e.now = e.now.Add(2 * time.Minute)
	if e.tick(false); len(e.up.calls) != 2 {
		t.Fatalf("should try again after the hour")
	}
	if !strings.Contains(strings.Join(e.log.lines, "\n"), "fix the token") {
		t.Errorf("log should tell the user what to do: %v", e.log.lines)
	}
}

func TestAFileThatKeepsChangingIsSettledBeforeUpload(t *testing.T) {
	e := newEnv(t)
	writes := 0
	e.sleep = func() {
		if writes < 3 { // TSM still writing during the first three waits
			writes++
			e.write("ACCT1", "v1"+strings.Repeat("+", writes), 1000+int64(writes))
		}
	}

	if got := kinds(e.tick(false)); got != "uploaded" {
		t.Fatalf("tick = %q (log: %v)", got, e.log.lines)
	}
	if len(e.up.calls) != 1 || e.up.calls[0].data != "v1+++" {
		t.Fatalf("should upload the settled content once, got %+v", e.up.calls)
	}
}

func TestAFileThatNeverSettlesIsSkippedAndNotRecorded(t *testing.T) {
	e := newEnv(t)
	n := 0
	e.sleep = func() {
		n++
		e.write("ACCT1", fmt.Sprintf("churn %d", n), 1000+int64(n))
	}

	evs := e.tick(false)
	if kinds(evs) != "skipped" || len(e.up.calls) != 0 {
		t.Fatalf("tick = %v, calls=%d", kinds(evs), len(e.up.calls))
	}
	if _, ok := e.st.Get(e.path("ACCT1")); ok {
		t.Error("an unsettled file must not be recorded, or it would never be retried")
	}
}

func TestOversizedFilesAreSkippedWithoutReadingThem(t *testing.T) {
	e := newEnv(t)
	e.s.Opts.MaxBytes = 10
	e.write("ACCT1", strings.Repeat("x", 11), 2000)

	evs := e.tick(false)
	if kinds(evs) != "skipped" || len(e.up.calls) != 0 {
		t.Fatalf("tick = %v, calls=%d", kinds(evs), len(e.up.calls))
	}
	if e.tick(false); len(e.up.calls) != 0 {
		t.Error("an oversized file should not be rechecked until it changes")
	}
}

func TestMissingWoWFolderIsLoggedOnceAndRecovers(t *testing.T) {
	e := newEnv(t)
	missing := true
	e.s.Root = func() (string, error) {
		if missing {
			return "", errors.New("could not find the World of Warcraft folder")
		}
		return e.root, nil
	}

	for i := 0; i < 3; i++ {
		if evs := e.tick(false); evs != nil {
			t.Fatalf("expected no events while WoW is missing, got %v", kinds(evs))
		}
	}
	if len(e.log.lines) != 1 {
		t.Errorf("the same problem should be logged once, got %d lines: %v", len(e.log.lines), e.log.lines)
	}

	missing = false
	if got := kinds(e.tick(false)); got != "uploaded" {
		t.Errorf("should recover once the folder appears, got %q", got)
	}
}

func TestRunPollsUntilCancelled(t *testing.T) {
	e := newEnv(t)
	ctx, cancel := context.WithCancel(context.Background())
	polls := 0
	e.s.Opts.Sleep = func(ctx context.Context, d time.Duration) error {
		polls++
		if polls == 3 {
			cancel()
		}
		return ctx.Err()
	}

	err := e.s.Run(ctx)
	if !errors.Is(err, context.Canceled) || polls != 3 {
		t.Errorf("Run returned %v after %d polls", err, polls)
	}
	if len(e.up.calls) != 1 {
		t.Errorf("three ticks of an unchanged file should upload once, got %d", len(e.up.calls))
	}
}

func TestSleepStopsOnCancel(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	start := time.Now()
	if err := sleep(ctx, time.Hour); !errors.Is(err, context.Canceled) || time.Since(start) > time.Second {
		t.Errorf("sleep did not return promptly on cancel: %v", err)
	}
}
