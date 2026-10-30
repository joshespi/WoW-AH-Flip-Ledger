package wow

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func mkfile(t *testing.T, parts ...string) string {
	t.Helper()
	p := filepath.Join(parts...)
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte("data"), 0o644); err != nil {
		t.Fatal(err)
	}
	return p
}

func TestFindFilesReturnsOnlyTradeSkillMasterPerAccount(t *testing.T) {
	root := t.TempDir()
	a := mkfile(t, root, "WTF", "Account", "ACCTB", "SavedVariables", "TradeSkillMaster.lua")
	b := mkfile(t, root, "WTF", "Account", "ACCTA", "SavedVariables", "TradeSkillMaster.lua")

	// Things that must never be returned (or opened).
	mkfile(t, root, "WTF", "Account", "ACCTA", "SavedVariables", "Other.lua")
	mkfile(t, root, "WTF", "Account", "ACCTA", "SavedVariables", "TradeSkillMaster.lua.bak")
	mkfile(t, root, "WTF", "Account", "ACCTA", "config-cache.wtf")
	mkfile(t, root, "WTF", "Account", "SavedVariables", "TradeSkillMaster.lua") // account-wide folder
	mkfile(t, root, "WTF", "Account", "NOTSM", "SavedVariables", "Details.lua")
	mkfile(t, root, "WTF", "Config.wtf")

	files, err := FindFiles(root)
	if err != nil {
		t.Fatal(err)
	}
	if len(files) != 2 || files[0].Path != b || files[1].Path != a {
		t.Fatalf("got %+v, want ACCTA then ACCTB only", files)
	}
	if files[0].Account != "ACCTA" || files[0].Label != Label("ACCTA") {
		t.Errorf("bad account/label: %+v", files[0])
	}
}

func TestFindFilesIgnoresSymlinks(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("symlinks need privileges on Windows")
	}
	root := t.TempDir()
	secret := mkfile(t, t.TempDir(), "secret.lua")
	dir := filepath.Join(root, "WTF", "Account", "LINKED", "SavedVariables")
	os.MkdirAll(dir, 0o755)
	if err := os.Symlink(secret, filepath.Join(dir, "TradeSkillMaster.lua")); err != nil {
		t.Fatal(err)
	}

	files, err := FindFiles(root)
	if err != nil || len(files) != 0 {
		t.Fatalf("a symlinked file must not be followed; got %+v, %v", files, err)
	}
}

func TestFindFilesNeedsAnAccountFolder(t *testing.T) {
	if _, err := FindFiles(t.TempDir()); err == nil {
		t.Error("expected an error when WTF/Account is missing")
	}
}

func TestLabelIsStableAndDoesNotRevealTheFolderName(t *testing.T) {
	l := Label("ESPIONAGE4631")
	if l != Label("ESPIONAGE4631") || l == Label("OTHER") {
		t.Errorf("label should be stable per account and differ across accounts: %q", l)
	}
	if !strings.HasPrefix(l, "acct-") || len(l) != len("acct-")+6 || strings.Contains(strings.ToLower(l), "espionage") {
		t.Errorf("unexpected label %q", l)
	}
}

func TestDetectRoot(t *testing.T) {
	retail := filepath.Join(t.TempDir(), "World of Warcraft", "_retail_")
	os.MkdirAll(filepath.Join(retail, "WTF", "Account"), 0o755)
	other := filepath.Join(t.TempDir(), "Elsewhere", "_retail_")
	os.MkdirAll(filepath.Join(other, "WTF", "Account"), 0o755)
	missing := filepath.Join(t.TempDir(), "nope")

	// Explicit path: used as is, or its _retail_ child.
	if got, err := detectRoot(retail, nil); err != nil || got != retail {
		t.Errorf("explicit _retail_: %q, %v", got, err)
	}
	if got, err := detectRoot(filepath.Dir(retail), nil); err != nil || got != retail {
		t.Errorf("explicit parent should resolve to _retail_: %q, %v", got, err)
	}

	// An explicit path that is wrong is an error even if a default would work.
	if _, err := detectRoot(missing, []string{retail}); err == nil || errors.Is(err, ErrNotFound) {
		t.Errorf("bad explicit path should fail with its own message, got %v", err)
	}

	// Defaults are tried in order, skipping ones that don't exist.
	if got, err := detectRoot("", []string{missing, other, retail}); err != nil || got != other {
		t.Errorf("defaults in order: %q, %v", got, err)
	}
	if _, err := detectRoot("", []string{missing}); !errors.Is(err, ErrNotFound) {
		t.Errorf("no install: err = %v", err)
	}
}

func TestDefaultRootsAreAbsoluteAndRetail(t *testing.T) {
	for _, r := range DefaultRoots() {
		if !strings.HasSuffix(r, "_retail_") {
			t.Errorf("default root %q should end in _retail_", r)
		}
	}
}
