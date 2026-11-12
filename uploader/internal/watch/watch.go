// Package watch decides when a TradeSkillMaster.lua or WowFlipLedger.lua is worth uploading and uploads it.
//
// The game only writes these files on /reload or logout, so the loop just polls each file's mtime. A
// changed file is uploaded once it has stopped changing (the game may still be writing), unless its
// content hash matches what the server already has. Failures back off per file.
package watch

import (
	"context"
	"fmt"
	"math/rand"
	"os"
	"time"

	"wowflipledger/uploader/internal/addon"
	"wowflipledger/uploader/internal/client"
	"wowflipledger/uploader/internal/fsx"
	"wowflipledger/uploader/internal/state"
	"wowflipledger/uploader/internal/wow"
)

// Uploader is what the Syncer needs from the API client.
type Uploader interface {
	Upload(ctx context.Context, data []byte, mtime time.Time, label, game string) (client.Result, client.Outcome, error)
}

type Logger interface {
	Printf(format string, args ...any)
}

type Options struct {
	Poll           time.Duration
	StableInterval time.Duration
	StableChecks   int
	StableTimeout  time.Duration
	MaxBytes       int64
	BackoffBase    time.Duration
	BackoffMax     time.Duration
	FatalWait      time.Duration

	Now    func() time.Time
	Sleep  func(ctx context.Context, d time.Duration) error
	Jitter func(d time.Duration) time.Duration
}

func DefaultOptions() Options {
	return Options{
		Poll:           30 * time.Second,
		StableInterval: 2 * time.Second,
		StableChecks:   2,
		StableTimeout:  time.Minute,
		// Matches the server's cap on a decompressed TSM file.
		MaxBytes:    48 * 1024 * 1024,
		BackoffBase: 5 * time.Second,
		BackoffMax:  15 * time.Minute,
		FatalWait:   time.Hour,
		Now:         time.Now,
		Sleep:       sleep,
		Jitter: func(d time.Duration) time.Duration {
			return d + time.Duration(rand.Int63n(int64(d)/5+1)) // up to +20%
		},
	}
}

func sleep(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

type Kind string

const (
	Uploaded  Kind = "uploaded"
	Unchanged Kind = "unchanged"
	Skipped   Kind = "skipped"
	Rejected  Kind = "rejected"
	Retrying  Kind = "retrying"
	Fatal     Kind = "fatal"
)

// Event is what happened to one file during a Tick.
type Event struct {
	File       wow.File
	Kind       Kind
	Message    string
	HTTPStatus int
}

type Syncer struct {
	// Root returns the _retail_ folder; it is re-evaluated every tick so a drive that mounts
	// late, or a first-time install, is picked up without restarting.
	Root  func() (string, error)
	Up    Uploader
	State *state.Store
	Log   Logger
	Opts  Options

	// Addon keeps the in-game addon's data file current; nil leaves it out. LastAddon is what
	// the most recent Tick did there (nil when sync is off or not due).
	Addon     *addon.Sync
	LastAddon *addon.Event

	next        map[string]time.Time
	fails       map[string]int
	lastRootErr string
}

func New(root func() (string, error), up Uploader, st *state.Store, log Logger, opts Options) *Syncer {
	return &Syncer{Root: root, Up: up, State: st, Log: log, Opts: opts, next: map[string]time.Time{}, fails: map[string]int{}}
}

// Run polls until ctx is cancelled.
func (s *Syncer) Run(ctx context.Context) error {
	for {
		s.Tick(ctx, false)
		if err := s.Opts.Sleep(ctx, s.Opts.Poll); err != nil {
			return err
		}
	}
}

// Tick checks every file once. force uploads even if the content looks unchanged.
func (s *Syncer) Tick(ctx context.Context, force bool) []Event {
	root, err := s.Root()
	if err != nil {
		if msg := err.Error(); msg != s.lastRootErr {
			s.Log.Printf("WoW folder: %s", msg)
			s.lastRootErr = msg
		}
		return nil
	}
	s.lastRootErr = ""

	files, err := wow.FindFiles(root)
	if err != nil {
		s.Log.Printf("cannot list WoW accounts: %v", err)
		return nil
	}

	var events []Event
	for _, f := range files {
		if ctx.Err() != nil {
			break
		}
		if ev := s.syncFile(ctx, f, force); ev != nil {
			events = append(events, *ev)
		}
	}

	// A feed problem never touches the upload backoff above; the two are independent.
	s.LastAddon = nil
	if s.Addon != nil && ctx.Err() == nil {
		for _, ev := range events {
			if ev.Kind == Uploaded {
				s.Addon.Refresh() // new sales change positions
				break
			}
		}
		s.LastAddon = s.Addon.Tick(ctx, root, force)
	}
	return events
}

func (s *Syncer) syncFile(ctx context.Context, f wow.File, force bool) *Event {
	now := s.Opts.Now()
	if now.Before(s.next[f.Path]) {
		return nil
	}

	fi := f.Info // from the listing this tick; readStable re-checks before reading
	entry, known := s.State.Get(f.Path)
	if !force && known && entry.Mtime == fi.ModTime().Unix() && entry.Size == fi.Size() {
		return nil
	}

	if fi.Size() > s.Opts.MaxBytes {
		return s.record(f, fi, "", state.StatusRejected, fmt.Sprintf("file is %d MB, over the %d MB limit", fi.Size()>>20, s.Opts.MaxBytes>>20), Skipped)
	}

	data, fi, ok := s.readStable(ctx, f.Path)
	if !ok {
		s.Log.Printf("%s: still being written, will check again", f.Label)
		return &Event{File: f, Kind: Skipped, Message: "file is still changing"}
	}

	sum := fsx.SHA256Hex(data)
	if !force && known && entry.SHA256 == sum {
		// Same content (e.g. a /reload that changed nothing): remember the new mtime, send nothing.
		s.remember(f, fi, sum, entry.Status, entry.Message, entry.At)
		return &Event{File: f, Kind: Unchanged, Message: "content unchanged since the last upload"}
	}

	res, outcome, err := s.Up.Upload(ctx, data, fi.ModTime(), f.Label, f.Game)

	switch outcome {
	case client.OK:
		s.fails[f.Path] = 0
		s.next[f.Path] = time.Time{}
		msg := res.Summary()
		s.Log.Printf("%s: uploaded (%s)", f.Label, msg)
		s.remember(f, fi, sum, state.StatusOK, msg, now)
		return &Event{File: f, Kind: Uploaded, Message: msg, HTTPStatus: res.HTTPStatus}

	case client.Rejected:
		s.Log.Printf("%s: the server rejected this file: %s", f.Label, res.Message)
		s.remember(f, fi, sum, state.StatusRejected, res.Message, now)
		return &Event{File: f, Kind: Rejected, Message: res.Message, HTTPStatus: res.HTTPStatus}

	case client.Retry:
		s.fails[f.Path]++
		wait := s.backoff(s.fails[f.Path], res.RetryAfter)
		// From now, not from the start of the attempt: waiting for the file to settle and the
		// upload itself can take a while and must not eat into the backoff.
		s.next[f.Path] = s.Opts.Now().Add(wait)
		msg := res.Reason(err)
		s.Log.Printf("%s: upload failed (%s); retrying in %s", f.Label, msg, wait.Round(time.Second))
		return &Event{File: f, Kind: Retrying, Message: msg, HTTPStatus: res.HTTPStatus}

	default:
		s.next[f.Path] = s.Opts.Now().Add(s.Opts.FatalWait)
		msg := res.Reason(err)
		s.Log.Printf("%s: cannot upload: %s (will try again in %s; fix the token, server URL or update the uploader)", f.Label, msg, s.Opts.FatalWait)
		return &Event{File: f, Kind: Fatal, Message: msg, HTTPStatus: res.HTTPStatus}
	}
}

func (s *Syncer) record(f wow.File, fi os.FileInfo, sum, status, msg string, kind Kind) *Event {
	s.Log.Printf("%s: skipped: %s", f.Label, msg)
	s.remember(f, fi, sum, status, msg, s.Opts.Now())
	return &Event{File: f, Kind: kind, Message: msg}
}

// remember saves the outcome for f against the mtime and size it has now, so an unchanged file
// is not looked at again. A failed save only costs one redundant (idempotent) check.
func (s *Syncer) remember(f wow.File, fi os.FileInfo, sum, status, msg string, at time.Time) {
	_ = s.State.Set(f.Path, state.Entry{Mtime: fi.ModTime().Unix(), Size: fi.Size(), SHA256: sum, Status: status, Message: msg, At: at})
}

// readStable waits until size and mtime have stopped changing, reads the file, and checks it
// didn't change while being read.
func (s *Syncer) readStable(ctx context.Context, path string) ([]byte, os.FileInfo, bool) {
	deadline := s.Opts.Now().Add(s.Opts.StableTimeout)
	var last os.FileInfo
	same := 0

	for {
		fi, err := os.Stat(path)
		if err != nil {
			return nil, nil, false
		}
		if last != nil && fi.Size() == last.Size() && fi.ModTime().Equal(last.ModTime()) {
			same++
		} else {
			same = 0
		}
		last = fi

		if same >= s.Opts.StableChecks {
			break
		}
		if s.Opts.Now().After(deadline) {
			return nil, nil, false
		}
		if err := s.Opts.Sleep(ctx, s.Opts.StableInterval); err != nil {
			return nil, nil, false
		}
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return nil, nil, false
	}
	after, err := os.Stat(path)
	if err != nil || after.Size() != last.Size() || !after.ModTime().Equal(last.ModTime()) || int64(len(data)) != last.Size() {
		return nil, nil, false
	}
	return data, last, true
}

func (s *Syncer) backoff(fails int, retryAfter time.Duration) time.Duration {
	d := client.Backoff(s.Opts.BackoffBase, s.Opts.BackoffMax, fails)
	if s.Opts.Jitter != nil {
		d = s.Opts.Jitter(d)
	}
	return max(d, retryAfter)
}
