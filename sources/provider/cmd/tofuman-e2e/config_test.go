// SPDX-License-Identifier: MIT

package main

import (
	"context"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func testConfig() Config {
	return Config{Endpoint: "http://192.0.2.10", BindRoot: "/mnt/user/appdata/", APIKey: KeySource{Source: sourceEnv, Variable: "TOFUMAN_E2E_TEST_KEY"}}
}

// userConfigIn points the user configuration directory of every platform into dir.
func userConfigIn(t *testing.T, dir string) {
	t.Setenv("XDG_CONFIG_HOME", dir)
	t.Setenv("APPDATA", dir)
	t.Setenv("HOME", dir)
}

// REQ-E2E-2 and REQ-E2E-7: the file in the working directory wins, and the runs go beside the
// file that wins.
func TestLocatePrefersTheWorkingDirectory(t *testing.T) {
	userConfigIn(t, t.TempDir())
	t.Chdir(t.TempDir())
	user, err := locate("")
	if err != nil {
		t.Fatal(err)
	}
	if user.local || !strings.HasSuffix(user.path, filepath.Join("tofuman", "e2e.json")) {
		t.Fatalf("without a file here, the configuration is %+v", user)
	}
	if err := save(configName, testConfig()); err != nil {
		t.Fatal(err)
	}
	here, err := locate("")
	if err != nil {
		t.Fatal(err)
	}
	if !here.local || filepath.Base(here.path) != configName {
		t.Fatalf("with a file here, the configuration is %+v", here)
	}
	runs, err := here.runs()
	if err != nil {
		t.Fatal(err)
	}
	if runs != filepath.Join(filepath.Dir(here.path), "tofuman-e2e-runs") {
		t.Fatalf("the runs of a configuration here go to %s", runs)
	}
}

func TestLoadRefusesWhatItCannotUse(t *testing.T) {
	cases := map[string]string{
		"an unknown field":    `{"endpoint": "http://192.0.2.10", "bindRoot": "/mnt/user/appdata/", "apiKey": {"source": "plain", "key": "k"}, "extra": 1}`,
		"an endpoint":         `{"endpoint": "192.0.2.10", "bindRoot": "/mnt/user/appdata/", "apiKey": {"source": "plain", "key": "k"}}`,
		"a relative bindRoot": `{"endpoint": "http://192.0.2.10", "bindRoot": "appdata", "apiKey": {"source": "plain", "key": "k"}}`,
		"an unknown source":   `{"endpoint": "http://192.0.2.10", "bindRoot": "/mnt/user/appdata/", "apiKey": {"source": "vault"}}`,
		"a missing field":     `{"endpoint": "http://192.0.2.10", "bindRoot": "/mnt/user/appdata/", "apiKey": {"source": "1password"}}`,
	}
	for name, text := range cases {
		path := filepath.Join(t.TempDir(), "e2e.json")
		if err := os.WriteFile(path, []byte(text), 0o600); err != nil {
			t.Fatal(err)
		}
		if _, err := load(path); err == nil || !strings.Contains(err.Error(), path) {
			t.Errorf("%s: load answered %v", name, err)
		}
	}
}

// A key pasted once leaves the file when the source changes, and only its owner reads the file.
func TestSaveKeepsOnlyTheFieldOfTheSource(t *testing.T) {
	c := testConfig()
	c.APIKey = KeySource{Source: sourceEnv, Variable: "TOFUMAN_E2E_TEST_KEY", Key: "pasted-before", Reference: "op://Private/x/y"}.only()
	path := filepath.Join(t.TempDir(), "tofuman", "e2e.json")
	if err := save(path, c); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(raw), "pasted-before") || strings.Contains(string(raw), "op://") {
		t.Fatalf("the file kept the field of another source: %s", raw)
	}
	if info, err := os.Stat(path); err != nil || (runtime.GOOS != "windows" && info.Mode().Perm() != 0o600) {
		t.Fatalf("the file has the mode %v (%v)", info.Mode(), err)
	}
	loaded, err := load(path)
	if err != nil || loaded != c {
		t.Fatalf("the file loads as %+v (%v)", loaded, err)
	}
}

// REQ-E2E-4
func TestEachKeySourceResolves(t *testing.T) {
	t.Setenv("TOFUMAN_E2E_TEST_KEY", " from-the-environment\n")
	cases := []struct {
		source KeySource
		want   string
	}{
		{KeySource{Source: sourceEnv, Variable: "TOFUMAN_E2E_TEST_KEY"}, "from-the-environment"},
		{KeySource{Source: sourcePlain, Key: "from-the-file"}, "from-the-file"},
		{KeySource{Source: sourceCommand, Command: "echo from-a-command"}, "from-a-command"},
	}
	for _, c := range cases {
		got, err := c.source.resolve(context.Background())
		if err != nil || got != c.want {
			t.Errorf("%s: %q, %v", c.source.Source, got, err)
		}
	}
}

// REQ-E2E-5: a failure says what the key manager said, and never what it printed.
func TestKeyErrorsNeverHoldTheKey(t *testing.T) {
	_, err := KeySource{Source: sourceCommand, Command: "echo the-key-itself && echo the vault is locked 1>&2 && exit 3"}.resolve(context.Background())
	if err == nil || strings.Contains(err.Error(), "the-key-itself") || !strings.Contains(err.Error(), "the vault is locked") {
		t.Fatalf("the failed command became %v", err)
	}
	if _, err := (KeySource{Source: sourceEnv, Variable: "TOFUMAN_E2E_UNSET"}).resolve(context.Background()); err == nil || !strings.Contains(err.Error(), "empty") {
		t.Fatalf("an unset variable became %v", err)
	}
	t.Setenv("PATH", t.TempDir())
	_, err = KeySource{Source: source1Password, Reference: "op://Private/tofuman/api key"}.resolve(context.Background())
	if err == nil || !strings.Contains(err.Error(), "op is not on PATH") {
		t.Fatalf("a missing 1Password CLI became %v", err)
	}
}
