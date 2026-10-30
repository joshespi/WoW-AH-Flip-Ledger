package state

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"
	"time"
)

func TestSetPersistsAndReloads(t *testing.T) {
	dir := t.TempDir()
	s, err := Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := s.Get("/a"); ok {
		t.Fatal("empty store should have no entries")
	}

	want := Entry{Mtime: 10, Size: 20, SHA256: "abc", Status: StatusOK, Message: "m", At: time.Unix(1_800_000_000, 0).UTC()}
	if err := s.Set("/a", want); err != nil {
		t.Fatal(err)
	}

	again, err := Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	if got, ok := again.Get("/a"); !ok || got != want {
		t.Errorf("reloaded %+v, %v; want %+v", got, ok, want)
	}

	if runtime.GOOS != "windows" {
		fi, _ := os.Stat(filepath.Join(dir, fileName))
		if fi.Mode().Perm() != 0o600 {
			t.Errorf("state file mode = %v, want 0600", fi.Mode().Perm())
		}
	}
}

func TestCorruptStateIsTreatedAsEmpty(t *testing.T) {
	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, fileName), []byte("{definitely not json"), 0o600)

	s, err := Open(dir)
	if err != nil {
		t.Fatalf("a corrupt state file should not be fatal: %v", err)
	}
	if err := s.Set("/a", Entry{Status: StatusOK}); err != nil {
		t.Fatalf("and should be writable afterwards: %v", err)
	}
}

func TestNullFilesMapIsRepaired(t *testing.T) {
	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, fileName), []byte(`{"files": null}`), 0o600)

	s, _ := Open(dir)
	if err := s.Set("/a", Entry{Status: StatusOK}); err != nil {
		t.Fatalf("Set on a repaired store panicked or failed: %v", err)
	}
}
