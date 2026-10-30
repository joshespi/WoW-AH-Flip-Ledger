// Package fsx has the file helpers the uploader's own data files (config, state) share.
package fsx

import (
	"encoding/json"
	"os"
	"path/filepath"
)

// WriteJSON writes v as indented JSON to path (0600, in a 0700 directory). It goes through a
// temp file and a rename, so a crash never leaves a half-written file.
func WriteJSON(path string, v any) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	b, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}
