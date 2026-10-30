// Package wow finds the one file the uploader is allowed to read:
// <WoW _retail_>/WTF/Account/<account>/SavedVariables/TradeSkillMaster.lua.
// It never opens anything else under the WoW folder and never writes there. The one file the
// uploader may write is the addon's Data.lua, and only internal/addon does that.
package wow

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strings"
)

const fileName = "TradeSkillMaster.lua"

// File is one TradeSkillMaster.lua, with a label that identifies the WoW account without
// revealing its folder name (which resembles a login id).
type File struct {
	Account string
	Label   string
	Path    string
}

// DefaultRoots lists the usual install locations for this OS.
func DefaultRoots() []string {
	switch runtime.GOOS {
	case "windows":
		var roots []string
		for _, env := range []string{"ProgramFiles(x86)", "ProgramFiles"} {
			if v := os.Getenv(env); v != "" {
				roots = append(roots, filepath.Join(v, "World of Warcraft", "_retail_"))
			}
		}
		for _, drive := range []string{"C", "D", "E"} {
			roots = append(roots,
				drive+`:\World of Warcraft\_retail_`,
				drive+`:\Games\World of Warcraft\_retail_`,
				drive+`:\Program Files (x86)\World of Warcraft\_retail_`)
		}
		return roots
	case "darwin":
		roots := []string{"/Applications/World of Warcraft/_retail_"}
		if home, err := os.UserHomeDir(); err == nil {
			roots = append(roots, filepath.Join(home, "Applications", "World of Warcraft", "_retail_"))
		}
		return roots
	}
	return nil
}

// ErrNotFound means no WoW install with an Account folder was found.
var ErrNotFound = errors.New("could not find the World of Warcraft folder; pass --wow with the path to its _retail_ folder")

// DetectRoot returns the _retail_ folder. An explicit path is used as given (or its _retail_
// child) and is never second-guessed; otherwise the default locations are tried in order.
func DetectRoot(explicit string) (string, error) {
	return detectRoot(explicit, DefaultRoots())
}

func detectRoot(explicit string, defaults []string) (string, error) {
	candidates := defaults
	if explicit != "" {
		candidates = []string{explicit}
	}
	for _, c := range candidates {
		for _, root := range []string{c, filepath.Join(c, "_retail_")} {
			if isDir(filepath.Join(root, "WTF", "Account")) {
				return root, nil
			}
		}
	}
	if explicit != "" {
		return "", fmt.Errorf("%q has no WTF/Account folder; point --wow at the _retail_ folder", explicit)
	}
	return "", ErrNotFound
}

// FindFiles returns each account's TradeSkillMaster.lua that exists, sorted by account. The
// account-wide "SavedVariables" folder is skipped, and so is anything that is not a regular
// file (a symlink could redirect the read somewhere else).
func FindFiles(root string) ([]File, error) {
	dir := filepath.Join(root, "WTF", "Account")
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}

	var files []File
	for _, e := range entries {
		if !e.IsDir() || e.Name() == "SavedVariables" || strings.ContainsAny(e.Name(), `/\`) {
			continue
		}
		p := filepath.Join(dir, e.Name(), "SavedVariables", fileName)
		if fi, err := os.Lstat(p); err == nil && fi.Mode().IsRegular() {
			files = append(files, File{Account: e.Name(), Label: Label(e.Name()), Path: p})
		}
	}
	sort.Slice(files, func(i, j int) bool { return files[i].Account < files[j].Account })
	return files, nil
}

// Label is a stable, non-identifying tag for an account folder name.
func Label(account string) string {
	sum := sha256.Sum256([]byte(account))
	return "acct-" + hex.EncodeToString(sum[:])[:6]
}

func isDir(p string) bool {
	fi, err := os.Stat(p)
	return err == nil && fi.IsDir()
}
