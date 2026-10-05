// SPDX-License-Identifier: MIT

package main

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync/atomic"
	"testing"
)

// fakeGitHub serves releases the way GitHub does: the list, and the assets of one provider
// release, whose zip for this platform holds zip.
type fakeGitHub struct {
	server    *httptest.Server
	zip       atomic.Value // []byte, what the zip of this platform holds
	sums      atomic.Value // string, the SHA256SUMS file
	downloads atomic.Int32 // of the zip
}

func newFakeGitHub(t *testing.T, version string, zip []byte, list string) *fakeGitHub {
	t.Helper()
	g := &fakeGitHub{}
	g.zip.Store(zip)
	zipName := fmt.Sprintf("terraform-provider-tofuman_%s_%s_%s.zip", version, runtime.GOOS, runtime.GOARCH)
	g.sums.Store(fmt.Sprintf("%s  %s\n%s  terraform-provider-tofuman_%s_plan9_mips.zip\n", sha256Hex(zip), zipName, strings.Repeat("0", 64), version))
	g.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/releases":
			_, _ = io.WriteString(w, list)
		case "/download/provider-v" + version + "/terraform-provider-tofuman_" + version + "_SHA256SUMS":
			_, _ = io.WriteString(w, g.sums.Load().(string))
		case "/download/provider-v" + version + "/" + zipName:
			g.downloads.Add(1)
			_, _ = w.Write(g.zip.Load().([]byte))
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(g.server.Close)
	return g
}

func (g *fakeGitHub) releases() releases {
	return releases{download: g.server.URL + "/download", list: g.server.URL + "/releases", http: g.server.Client()}
}

// REQ-E2E-10: the zip lands where a filesystem mirror keeps it, a matching zip is not fetched
// again, and a zip that the SHA256SUMS file does not name is refused.
func TestMirrorChecksTheReleaseAndKeepsItsZip(t *testing.T) {
	zip := []byte("the bytes of a provider zip")
	g := newFakeGitHub(t, "1.2.3", zip, "[]")
	cache := t.TempDir()
	mirror, err := g.releases().mirror(context.Background(), cache, "1.2.3")
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(mirror, "registry.opentofu.org", "tomzorz", "tofuman", fmt.Sprintf("terraform-provider-tofuman_1.2.3_%s_%s.zip", runtime.GOOS, runtime.GOARCH))
	if got, err := os.ReadFile(path); err != nil || string(got) != string(zip) {
		t.Fatalf("the mirror holds %q (%v)", got, err)
	}
	if _, err := g.releases().mirror(context.Background(), cache, "1.2.3"); err != nil || g.downloads.Load() != 1 {
		t.Fatalf("the second run downloaded the zip again: %d downloads (%v)", g.downloads.Load(), err)
	}
	g.zip.Store([]byte("tampered"))
	if err := os.Remove(path); err != nil {
		t.Fatal(err)
	}
	if _, err := g.releases().mirror(context.Background(), cache, "1.2.3"); err == nil || !strings.Contains(err.Error(), "hashes to") {
		t.Fatalf("a zip that the SHA256SUMS file does not name became %v", err)
	}
	g.sums.Store("")
	if _, err := g.releases().mirror(context.Background(), cache, "1.2.3"); err == nil || !strings.Contains(err.Error(), "has no build for") {
		t.Fatalf("a release without a build for this platform became %v", err)
	}
}

// Plugin releases share the repository, so the newest provider release is not the newest release.
func TestNewestSkipsPluginReleasesAndPreReleases(t *testing.T) {
	list := `[{"tag_name": "plugin-2026.10.05", "prerelease": true}, {"tag_name": "provider-v0.10.0"},
		{"tag_name": "provider-v0.9.12"}, {"tag_name": "provider-v0.11.0", "prerelease": true},
		{"tag_name": "provider-v1.0.0", "draft": true}, {"tag_name": "plugin-2026.10.04"}]`
	g := newFakeGitHub(t, "0.10.0", nil, list)
	got, err := g.releases().newest(context.Background())
	if err != nil || got != "0.10.0" {
		t.Fatalf("the newest provider release is %q (%v)", got, err)
	}
}

func TestCLIConfigurationPointsTofuAtTheProvider(t *testing.T) {
	run := t.TempDir()
	path, err := cliConfig(run, filepath.Join(run, "providers"), "")
	if err != nil {
		t.Fatal(err)
	}
	text, _ := os.ReadFile(path)
	mirror := filepath.ToSlash(filepath.Join(run, "providers"))
	for _, want := range []string{`filesystem_mirror {`, `path    = "` + mirror + `"`, `include = ["registry.opentofu.org/tomzorz/tofuman"]`, `exclude = ["registry.opentofu.org/tomzorz/tofuman"]`} {
		if !strings.Contains(string(text), want) {
			t.Errorf("the mirror configuration lacks %s:\n%s", want, text)
		}
	}
	path, err = cliConfig(run, "", filepath.Join(run, "provider"))
	if err != nil {
		t.Fatal(err)
	}
	text, _ = os.ReadFile(path)
	if !strings.Contains(string(text), `"registry.opentofu.org/tomzorz/tofuman" = "`+filepath.ToSlash(filepath.Join(run, "provider"))+`"`) {
		t.Errorf("the configuration of a local binary lacks its dev_overrides:\n%s", text)
	}
}
