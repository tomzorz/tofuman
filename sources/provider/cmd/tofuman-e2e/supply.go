// SPDX-License-Identifier: MIT

package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"time"
)

// providerAddress is the name of the provider in tofu.
const providerAddress = "registry.opentofu.org/tomzorz/tofuman"

// releases is where the provider releases live: GitHub, or a local server in the tests.
type releases struct {
	download string // the base URL of the release assets
	list     string // the URL of the list of releases
	http     *http.Client
}

var github = releases{
	download: "https://github.com/tomzorz/tofuman/releases/download",
	list:     "https://api.github.com/repos/tomzorz/tofuman/releases?per_page=100",
	http:     &http.Client{Timeout: 5 * time.Minute},
}

var providerTag = regexp.MustCompile(`^provider-v(\d+)\.(\d+)\.(\d+)$`)

// newest returns the version of the newest provider release that is not a pre-release
// (REQ-E2E-10). Plugin releases share the repository, so the newest release is not the answer.
func (r releases) newest(ctx context.Context) (string, error) {
	body, err := r.get(ctx, r.list)
	if err != nil {
		return "", err
	}
	var list []struct {
		Tag        string `json:"tag_name"`
		Draft      bool   `json:"draft"`
		Prerelease bool   `json:"prerelease"`
	}
	if err := json.Unmarshal(body, &list); err != nil {
		return "", fmt.Errorf("the list of releases is not JSON: %w", err)
	}
	best := []int{-1, -1, -1}
	for _, release := range list {
		match := providerTag.FindStringSubmatch(release.Tag)
		if match == nil || release.Draft || release.Prerelease {
			continue
		}
		version := []int{atoi(match[1]), atoi(match[2]), atoi(match[3])}
		if newer(version, best) {
			best = version
		}
	}
	if best[0] < 0 {
		return "", fmt.Errorf("%s lists no provider release", r.list)
	}
	return fmt.Sprintf("%d.%d.%d", best[0], best[1], best[2]), nil
}

func atoi(s string) int {
	n, _ := strconv.Atoi(s)
	return n
}

func newer(a, b []int) bool {
	for i := range a {
		if a[i] != b[i] {
			return a[i] > b[i]
		}
	}
	return false
}

// mirror puts the zip of the provider release for this platform into a filesystem mirror under
// cache, checked against the SHA256SUMS file of the release (REQ-E2E-10), and returns the mirror.
// A zip that is there already and matches is not downloaded again.
func (r releases) mirror(ctx context.Context, cache, version string) (string, error) {
	tag := "provider-v" + version
	zipName := fmt.Sprintf("terraform-provider-tofuman_%s_%s_%s.zip", version, runtime.GOOS, runtime.GOARCH)
	sums, err := r.get(ctx, r.download+"/"+tag+"/terraform-provider-tofuman_"+version+"_SHA256SUMS")
	if err != nil {
		return "", err
	}
	want := ""
	for _, line := range strings.Split(string(sums), "\n") {
		if fields := strings.Fields(line); len(fields) == 2 && fields[1] == zipName {
			want = fields[0]
		}
	}
	if want == "" {
		return "", fmt.Errorf("the provider release %s has no build for %s/%s", version, runtime.GOOS, runtime.GOARCH)
	}
	mirror := filepath.Join(cache, "providers")
	dir := filepath.Join(mirror, filepath.FromSlash(providerAddress))
	path := filepath.Join(dir, zipName)
	if have, err := os.ReadFile(path); err == nil && sha256Hex(have) == want {
		return mirror, nil
	}
	zip, err := r.get(ctx, r.download+"/"+tag+"/"+zipName)
	if err != nil {
		return "", err
	}
	if have := sha256Hex(zip); have != want {
		return "", fmt.Errorf("%s hashes to %s, and the SHA256SUMS file of the release names %s", zipName, have, want)
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", err
	}
	if err := os.WriteFile(path+".part", zip, 0o644); err != nil {
		return "", err
	}
	return mirror, os.Rename(path+".part", path)
}

func sha256Hex(data []byte) string {
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:])
}

func (r releases) get(ctx context.Context, url string) ([]byte, error) {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	response, err := r.http.Do(request)
	if err != nil {
		return nil, fmt.Errorf("GET %s: %w", url, err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("GET %s answered HTTP %d", url, response.StatusCode)
	}
	return io.ReadAll(io.LimitReader(response.Body, 256<<20))
}

// localProvider copies a provider binary to where dev_overrides look for it, under the name
// that tofu expects, and returns that folder.
func localProvider(run, binary string) (string, error) {
	data, err := os.ReadFile(binary)
	if err != nil {
		return "", fmt.Errorf("the provider binary: %w", err)
	}
	dir := filepath.Join(run, "provider")
	name := "terraform-provider-tofuman"
	if runtime.GOOS == "windows" {
		name += ".exe"
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", err
	}
	return dir, os.WriteFile(filepath.Join(dir, name), data, 0o755)
}

// cliConfig writes the CLI configuration that gives tofu the provider, and returns its path:
// the mirror for a provider release, or dev_overrides for a local binary. Every other provider
// comes from its registry as usual.
func cliConfig(run, mirror, local string) (string, error) {
	var b strings.Builder
	b.WriteString("provider_installation {\n")
	if local != "" {
		fmt.Fprintf(&b, "  dev_overrides {\n    %q = %q\n  }\n  direct {}\n", providerAddress, filepath.ToSlash(local))
	} else {
		fmt.Fprintf(&b, "  filesystem_mirror {\n    path    = %q\n    include = [%q]\n  }\n", filepath.ToSlash(mirror), providerAddress)
		fmt.Fprintf(&b, "  direct {\n    exclude = [%q]\n  }\n", providerAddress)
	}
	b.WriteString("}\n")
	path := filepath.Join(run, "tofu.tfrc")
	return path, os.WriteFile(path, []byte(b.String()), 0o644)
}
