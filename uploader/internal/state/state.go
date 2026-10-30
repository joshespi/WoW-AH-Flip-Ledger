// Package state remembers what was last decided for each file, so a restart doesn't re-upload
// an unchanged file.
package state

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
	"time"

	"wowflipledger/uploader/internal/fsx"
)

const fileName = "state.json"

const (
	StatusOK       = "ok"
	StatusRejected = "rejected"
)

// Entry is the last outcome for one file: ok (uploaded) or rejected (the server refused this
// content, so it is not retried until the file changes).
type Entry struct {
	Mtime   int64     `json:"mtime"`
	Size    int64     `json:"size"`
	SHA256  string    `json:"sha256"`
	Status  string    `json:"status"`
	Message string    `json:"message,omitempty"`
	At      time.Time `json:"at"`
}

// Addon is the last Data.lua the uploader wrote: where, and the content hash it was rendered from.
type Addon struct {
	Path      string    `json:"path"`
	SHA256    string    `json:"sha256"`
	WrittenAt time.Time `json:"written_at"`
}

type Store struct {
	mu    sync.Mutex
	path  string
	Files map[string]Entry `json:"files"`
	Addon Addon            `json:"addon"`
}

func Open(dir string) (*Store, error) {
	s := &Store{path: filepath.Join(dir, fileName), Files: map[string]Entry{}}
	b, err := os.ReadFile(s.path)
	if errors.Is(err, os.ErrNotExist) {
		return s, nil
	}
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(b, s); err != nil || s.Files == nil {
		// A corrupt state file only costs one redundant (idempotent) upload.
		s.Files = map[string]Entry{}
	}
	return s, nil
}

func (s *Store) Get(path string) (Entry, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	e, ok := s.Files[path]
	return e, ok
}

func (s *Store) GetAddon() Addon {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.Addon
}

func (s *Store) SetAddon(a Addon) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.Addon = a

	return fsx.WriteJSON(s.path, s)
}

func (s *Store) Set(path string, e Entry) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.Files[path] = e

	return fsx.WriteJSON(s.path, s)
}
