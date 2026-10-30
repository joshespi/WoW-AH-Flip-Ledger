package logx

import (
	"bytes"
	"os"
	"runtime"
	"strings"
	"testing"
)

func TestPrintfWritesTimestampedLines(t *testing.T) {
	dir := t.TempDir()

	l, err := Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	l.Printf("uploaded %d files", 2)
	l.Close()

	b, _ := os.ReadFile(Path(dir))
	line := strings.TrimSpace(string(b))
	if !strings.HasSuffix(line, " uploaded 2 files") || len(line) != len("2006-01-02 15:04:05 uploaded 2 files") {
		t.Errorf("log line = %q", line)
	}

	if runtime.GOOS != "windows" {
		if fi, _ := os.Stat(Path(dir)); fi.Mode().Perm() != 0o600 {
			t.Errorf("log mode = %v, want 0600", fi.Mode().Perm())
		}
	}
}

func TestLogRotatesPastTheSizeLimit(t *testing.T) {
	dir := t.TempDir()
	l, err := Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer l.Close()

	chunk := strings.Repeat("x", 1000)
	for i := 0; i < 1500; i++ { // ~1.5 MB
		l.Printf("%s", chunk)
	}

	cur, _ := os.Stat(Path(dir))
	old, err := os.Stat(Path(dir) + ".1")
	if err != nil {
		t.Fatalf("expected a rotated file: %v", err)
	}
	if cur.Size() > maxBytes || old.Size() > maxBytes+2000 || cur.Size() == 0 {
		t.Errorf("sizes after rotation: current=%d rotated=%d", cur.Size(), old.Size())
	}
}

func TestOpenRotatesAnAlreadyLargeLog(t *testing.T) {
	dir := t.TempDir()
	os.WriteFile(Path(dir), bytes.Repeat([]byte("y"), maxBytes+10), 0o600)

	l, err := Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer l.Close()

	if _, err := os.Stat(Path(dir) + ".1"); err != nil {
		t.Errorf("an oversized log should be rotated on open: %v", err)
	}
}
