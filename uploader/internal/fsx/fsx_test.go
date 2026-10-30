package fsx

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

func TestWriteJSONCreatesTheDirectoryAndReplacesTheFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "sub", "data.json")

	for _, n := range []int{1, 2} {
		if err := WriteJSON(path, map[string]int{"n": n}); err != nil {
			t.Fatal(err)
		}
	}

	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var got map[string]int
	if err := json.Unmarshal(b, &got); err != nil || got["n"] != 2 {
		t.Errorf("got %q (%v)", b, err)
	}
	if _, err := os.Stat(path + ".tmp"); !os.IsNotExist(err) {
		t.Error("the temp file was left behind")
	}
	if runtime.GOOS != "windows" {
		if fi, _ := os.Stat(path); fi.Mode().Perm() != 0o600 {
			t.Errorf("mode %v, want 0600", fi.Mode().Perm())
		}
	}
}

func TestWriteJSONReportsUnserializableValues(t *testing.T) {
	if err := WriteJSON(filepath.Join(t.TempDir(), "x.json"), make(chan int)); err == nil {
		t.Error("expected an error for a channel")
	}
}
