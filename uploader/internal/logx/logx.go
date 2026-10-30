// Package logx is a plain-text log file with simple size rotation.
package logx

import (
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"
)

const (
	fileName = "uploader.log"
	maxBytes = 1 << 20
)

type Logger struct {
	mu   sync.Mutex
	dir  string
	f    *os.File
	size int64
}

// Path is the log file inside dir.
func Path(dir string) string { return filepath.Join(dir, fileName) }

// Open appends to <dir>/uploader.log (rotated to uploader.log.1 past 1 MiB).
func Open(dir string) (*Logger, error) {
	l := &Logger{dir: dir}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	return l, l.open()
}

func (l *Logger) open() error {
	p := Path(l.dir)
	if fi, err := os.Stat(p); err == nil && fi.Size() > maxBytes {
		_ = os.Rename(p, p+".1")
	}
	f, err := os.OpenFile(p, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	fi, _ := f.Stat()
	l.f = f
	if fi != nil {
		l.size = fi.Size()
	}
	return nil
}

func (l *Logger) Printf(format string, args ...any) {
	line := fmt.Sprintf("%s %s\n", time.Now().Format("2006-01-02 15:04:05"), fmt.Sprintf(format, args...))

	l.mu.Lock()
	defer l.mu.Unlock()

	if l.size+int64(len(line)) > maxBytes {
		l.f.Close()
		_ = l.open()
	}
	if n, err := l.f.WriteString(line); err == nil {
		l.size += int64(n)
	}
}

func (l *Logger) Close() error { return l.f.Close() }
