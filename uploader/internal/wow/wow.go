// Package wow finds the two files the uploader is allowed to read, per WoW account:
// <WoW _retail_>/WTF/Account/<account>/SavedVariables/TradeSkillMaster.lua (TSM) and
// .../SavedVariables/WowFlipLedger.lua (the companion addon's own record).
// It never opens anything else under the WoW folder and never writes there. The one file the
// uploader may write is the addon's Data.lua, and only internal/addon does that.
package wow

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strings"

	"wowflipledger/uploader/internal/fsx"
)

// The only two file names ever read.
const (
	tsmFile   = "TradeSkillMaster.lua"
	addonFile = "WowFlipLedger.lua"
)

// Games a file can belong to. Forever (_forever_) and Classic (_classic_) installs sit beside
// _retail_ and are separate economies; the server keeps them apart by this value.
const (
	GameRetail  = "retail"
	GameForever = "forever"
	GameClassic = "classic"
)

// siblings are the installs read besides _retail_, each in a folder beside it.
var siblings = []struct{ game, folder string }{
	{GameForever, "_forever_"},
	{GameClassic, "_classic_"},
}

// GameLabel is the display name of a game ("Forever").
func GameLabel(game string) string {
	switch game {
	case GameForever:
		return "Forever"
	case GameClassic:
		return "Classic"
	}
	return "Retail"
}

// AccountsDir is <root>/WTF/Account.
func AccountsDir(root string) string { return filepath.Join(root, "WTF", "Account") }

// SavedVariablesDir is <root>/WTF/Account/<account>/SavedVariables.
func SavedVariablesDir(root, account string) string {
	return filepath.Join(AccountsDir(root), account, "SavedVariables")
}

// SavedVariablesPath is the file <name> in SavedVariablesDir.
func SavedVariablesPath(root, account, name string) string {
	return filepath.Join(SavedVariablesDir(root, account), name)
}

// siblingRoot is the install folder beside root (the _retail_ folder).
func siblingRoot(root, folder string) string {
	return filepath.Join(filepath.Dir(root), folder)
}

// File is one data file, with a label that identifies the WoW account without revealing its
// folder name (which resembles a login id). The addon's file gets a "-addon" suffix so the two
// of one account stay distinguishable in the log. Info is the Lstat taken when it was found.
type File struct {
	Account string
	Label   string
	Path    string
	Game    string
	Info    os.FileInfo
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
			if isDir(AccountsDir(root)) {
				return root, nil
			}
		}
	}
	if explicit != "" {
		return "", fmt.Errorf("%q has no WTF/Account folder; point --wow at the _retail_ folder", explicit)
	}
	return "", ErrNotFound
}

// FindFiles returns each account's TradeSkillMaster.lua and WowFlipLedger.lua that exist under
// root (the _retail_ folder) and under each sibling install beside it, sorted by game then account
// (TSM first). The account-wide "SavedVariables" folder is skipped, and so is anything that is
// not a regular file (a symlink could redirect the read somewhere else).
func FindFiles(root string) ([]File, error) {
	files, err := findIn(root, GameRetail)
	if err != nil {
		return nil, err
	}
	for _, s := range siblings {
		if more, err := findIn(siblingRoot(root, s.folder), s.game); err == nil {
			files = append(files, more...)
		}
	}
	return files, nil
}

func findIn(root, game string) ([]File, error) {
	dir := AccountsDir(root)
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}

	tag := ""
	if game != GameRetail {
		tag = "-" + game
	}
	var files []File
	for _, e := range entries {
		if !e.IsDir() || e.Name() == "SavedVariables" || strings.ContainsAny(e.Name(), `/\`) {
			continue
		}
		for _, f := range []struct{ name, suffix string }{{tsmFile, ""}, {addonFile, "-addon"}} {
			p := SavedVariablesPath(root, e.Name(), f.name)
			if fi, err := os.Lstat(p); err == nil && fi.Mode().IsRegular() {
				files = append(files, File{Account: e.Name(), Label: Label(e.Name()) + tag + f.suffix, Path: p, Game: game, Info: fi})
			}
		}
	}
	sort.SliceStable(files, func(i, j int) bool { return files[i].Account < files[j].Account })
	return files, nil
}

// Label is a stable, non-identifying tag for an account folder name.
func Label(account string) string {
	return "acct-" + fsx.SHA256Hex([]byte(account))[:6]
}

func isDir(p string) bool {
	fi, err := os.Stat(p)
	return err == nil && fi.IsDir()
}

// Siblings returns the games (GameForever, GameClassic) whose install sits beside root (the
// _retail_ folder), with or without data files yet. Their files are sent tagged with that game.
func Siblings(root string) []string {
	var games []string
	for _, s := range siblings {
		if isDir(AccountsDir(siblingRoot(root, s.folder))) {
			games = append(games, s.game)
		}
	}
	return games
}
