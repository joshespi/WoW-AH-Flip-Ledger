// Package addon keeps the in-game addon's data file current.
//
// This is the only code in the uploader that writes under the WoW folder, and it writes exactly
// one file: <WoW _retail_>/Interface/AddOns/WowFlipLedger/Data.lua (via a temp file beside it).
// It never creates the addon folder (the user installs the addon), never follows a symlink for the
// folder or the file, and does nothing unless the user turned addon sync on.
package addon

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"wowflipledger/uploader/internal/client"
	"wowflipledger/uploader/internal/fsx"
	"wowflipledger/uploader/internal/state"
)

const (
	// FolderName is the addon's folder under Interface/AddOns; FileName is the one file written.
	FolderName = "WowFlipLedger"
	FileName   = "Data.lua"
)

// ErrNotInstalled means the addon folder is missing; install the addon first.
var ErrNotInstalled = errors.New("the WowFlipLedger addon folder is not in Interface/AddOns")

// Dir is the addon folder for a _retail_ root.
func Dir(root string) string { return filepath.Join(root, "Interface", "AddOns", FolderName) }

// DataPath is the one file this package writes.
func DataPath(root string) string { return filepath.Join(Dir(root), FileName) }

// Installed reports whether the addon folder exists (as a real folder, not a link).
func Installed(root string) bool { return isRealDir(Dir(root)) }

func isRealDir(path string) bool {
	fi, err := os.Lstat(path)
	return err == nil && fi.Mode().IsDir()
}

// Write replaces Data.lua atomically. It refuses when the addon folder is missing or Data.lua
// exists but is not a regular file.
func Write(root string, data []byte) error {
	dir := Dir(root)
	if !isRealDir(dir) {
		return ErrNotInstalled
	}

	dst := filepath.Join(dir, FileName)
	if fi, err := os.Lstat(dst); err == nil && !fi.Mode().IsRegular() {
		return fmt.Errorf("%s is not a regular file; remove it and sync again", dst)
	}

	return fsx.WriteFileAtomic(dst, data, 0o644)
}

// Fetcher is what Sync needs from the API client.
type Fetcher interface {
	Feed(ctx context.Context) ([]byte, client.Result, client.Outcome, error)
}

type Logger interface {
	Printf(format string, args ...any)
}

type Options struct {
	// Interval between feed fetches; MaxAge forces a rewrite of an unchanged file this often so
	// the addon's "data as of" time stays honest.
	Interval    time.Duration
	MaxAge      time.Duration
	Timeout     time.Duration
	BackoffBase time.Duration
	BackoffMax  time.Duration
	FatalWait   time.Duration

	Now func() time.Time
}

func DefaultOptions() Options {
	return Options{
		Interval:    5 * time.Minute,
		MaxAge:      time.Hour,
		Timeout:     30 * time.Second,
		BackoffBase: 30 * time.Second,
		BackoffMax:  30 * time.Minute,
		FatalWait:   time.Hour,
		Now:         time.Now,
	}
}

type Kind string

const (
	Written   Kind = "written"
	Unchanged Kind = "unchanged"
	Skipped   Kind = "skipped"
	Retrying  Kind = "retrying"
	Fatal     Kind = "fatal"
)

// Event is what one Tick did.
type Event struct {
	Kind       Kind
	Message    string
	HTTPStatus int
}

// Sync fetches the feed on a timer and writes Data.lua when it changed.
type Sync struct {
	Feed    Fetcher
	State   *state.Store
	Log     Logger
	Opts    Options
	Enabled func() bool

	next    time.Time
	fails   int
	lastMsg string
}

func New(feed Fetcher, st *state.Store, log Logger, enabled func() bool, opts Options) *Sync {
	return &Sync{Feed: feed, State: st, Log: log, Opts: opts, Enabled: enabled}
}

// Refresh makes the next Tick fetch regardless of the timer (after an upload, the data changed).
func (s *Sync) Refresh() { s.next = time.Time{} }

// Tick syncs once if it is due (or force). It returns nil when addon sync is off or not due.
func (s *Sync) Tick(ctx context.Context, root string, force bool) *Event {
	if s.Enabled != nil && !s.Enabled() {
		return nil
	}
	now := s.Opts.Now()
	if !force && now.Before(s.next) {
		return nil
	}

	if !Installed(root) {
		s.next = now.Add(s.Opts.Interval)
		return s.skip(fmt.Sprintf("%v (%s); copy the folder in, or turn addon sync off", ErrNotInstalled, Dir(root)))
	}

	fctx, cancel := context.WithTimeout(ctx, s.Opts.Timeout)
	body, res, outcome, err := s.Feed.Feed(fctx)
	cancel()

	switch outcome {
	case client.OK:
		return s.store(root, body, now, force)

	case client.Rejected:
		s.fails = 0
		s.next = now.Add(s.Opts.Interval)
		return s.skip(res.Reason(err))

	case client.Retry:
		s.fails++
		wait := max(s.backoff(), res.RetryAfter)
		s.next = now.Add(wait)
		return s.note(Retrying, res, fmt.Sprintf("addon feed failed (%s); retrying in %s", res.Reason(err), wait.Round(time.Second)))

	default:
		s.fails = 0
		s.next = now.Add(s.Opts.FatalWait)
		return s.note(Fatal, res, fmt.Sprintf("cannot fetch the addon feed: %s (will try again in %s)", res.Reason(err), s.Opts.FatalWait))
	}
}

func (s *Sync) store(root string, body []byte, now time.Time, force bool) *Event {
	s.fails = 0
	s.next = now.Add(s.Opts.Interval)

	lua, sum, err := Render(body)
	if err != nil {
		return s.skip(err.Error())
	}

	path := DataPath(root)
	prev := s.State.GetAddon()
	if _, statErr := os.Stat(path); !force && statErr == nil && prev.Path == path && prev.SHA256 == sum && now.Sub(prev.WrittenAt) < s.Opts.MaxAge {
		s.lastMsg = ""
		return &Event{Kind: Unchanged, Message: "addon data unchanged"}
	}

	if err := Write(root, lua); err != nil {
		return s.skip(fmt.Sprintf("could not write %s: %v", path, err))
	}
	_ = s.State.SetAddon(state.Addon{Path: path, SHA256: sum, WrittenAt: now})

	s.lastMsg = ""
	s.Log.Printf("addon: wrote %s (/reload in game to load it)", path)
	return &Event{Kind: Written, Message: "wrote " + path}
}

// skip logs a problem once per distinct message, so a standing one does not fill the log.
func (s *Sync) skip(msg string) *Event {
	return s.note(Skipped, client.Result{}, "addon: "+msg)
}

func (s *Sync) note(kind Kind, res client.Result, msg string) *Event {
	if msg != s.lastMsg {
		s.Log.Printf("%s", msg)
		s.lastMsg = msg
	}
	return &Event{Kind: kind, Message: msg, HTTPStatus: res.HTTPStatus}
}

func (s *Sync) backoff() time.Duration {
	return client.Backoff(s.Opts.BackoffBase, s.Opts.BackoffMax, s.fails)
}
