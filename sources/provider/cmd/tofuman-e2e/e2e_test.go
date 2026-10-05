// SPDX-License-Identifier: MIT

package main

import (
	"archive/zip"
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/tomzorz/tofuman/sources/provider/internal/client"
)

// The acceptance tests of REQ-TST-12 run the e2e tool against the test server of the plugin,
// with a scripted person in place of the person in the webgui. sources/provider/ci/test.sh sets
// up TF_ACC, TOFUMAN_ENDPOINT, TOFUMAN_API_KEY, the test server, and tofu.

func acceptance(t *testing.T) (endpoint, key string) {
	t.Helper()
	if os.Getenv("TF_ACC") == "" {
		t.Skip("an acceptance test: sources/provider/ci/test.sh runs it against the test server")
	}
	endpoint, key = os.Getenv("TOFUMAN_ENDPOINT"), os.Getenv("TOFUMAN_API_KEY")
	if endpoint == "" || key == "" {
		t.Fatal("the acceptance tests need TOFUMAN_ENDPOINT and TOFUMAN_API_KEY")
	}
	return endpoint, key
}

var built struct {
	once sync.Once
	path string
	err  error
}

// providerBinary builds the provider of this checkout, once per test binary.
func providerBinary(t *testing.T) string {
	t.Helper()
	built.once.Do(func() {
		dir, err := os.MkdirTemp("", "tofuman-e2e-provider-")
		if err != nil {
			built.err = err
			return
		}
		built.path = filepath.Join(dir, "terraform-provider-tofuman_v0.0.1")
		if runtime.GOOS == "windows" {
			built.path += ".exe"
		}
		out, err := exec.Command("go", "build", "-o", built.path, "-ldflags", "-X main.version=0.0.1", "github.com/tomzorz/tofuman/sources/provider").CombinedOutput()
		if err != nil {
			built.err = fmt.Errorf("building the provider: %v: %s", err, out)
		}
	})
	if built.err != nil {
		t.Fatal(built.err)
	}
	return built.path
}

// providerZip packs the provider binary the way a provider release does.
func providerZip(t *testing.T) []byte {
	t.Helper()
	binary, err := os.ReadFile(providerBinary(t))
	if err != nil {
		t.Fatal(err)
	}
	var b bytes.Buffer
	w := zip.NewWriter(&b)
	header := &zip.FileHeader{Name: filepath.Base(providerBinary(t)), Method: zip.Deflate}
	header.SetMode(0o755)
	f, err := w.CreateHeader(header)
	if err == nil {
		_, err = f.Write(binary)
	}
	if err == nil {
		err = w.Close()
	}
	if err != nil {
		t.Fatal(err)
	}
	return b.Bytes()
}

func testOptions(endpoint, key, run, cache string) options {
	return options{
		config:  Config{Endpoint: endpoint, BindRoot: "/mnt/tofumantest/", Port: 18199, APIKey: KeySource{Source: sourcePlain, Key: key}},
		key:     key,
		run:     run,
		cache:   cache,
		version: "0.0.1",
		prefix:  "tofumantest-e2e",
		// the person adopts a container of this name, which the cleanup of the tests removes
		adoption: "tofumantest-adoption",
		suffix:   "t1",
	}
}

// REQ-E2E-1 and REQ-TST-12: every step passes against the test server, with the provider from
// a release that looks like one on GitHub (REQ-E2E-10), and the run ends with the summary and the
// cleanup of REQ-E2E-17 and REQ-E2E-19.
func TestTheProcedureAgainstTheTestServer(t *testing.T) {
	endpoint, key := acceptance(t)
	release := newFakeGitHub(t, "0.0.1", providerZip(t), "[]")
	person := &scriptedPerson{t: t, endpoint: endpoint, key: key, name: "tofumantest-e2e-t1", adoption: "tofumantest-adoption"}
	opts := testOptions(endpoint, key, t.TempDir(), t.TempDir())
	opts.releases = release.releases()
	if !newProcedure(person, opts).execute(context.Background()) {
		t.Fatalf("the run stopped:\n%s", strings.Join(person.result, "\n"))
	}
	summary, err := os.ReadFile(filepath.Join(opts.run, "summary.txt"))
	if err != nil || !strings.Contains(string(summary), "Every step passed.") || !strings.Contains(string(summary), "provider 0.0.1") {
		t.Fatalf("the summary is:\n%s (%v)", summary, err)
	}
	adopted, err := person.client().ContainerNamed(context.Background(), "tofumantest-adoption")
	if err != nil || adopted != nil {
		t.Fatalf("the cleanup left the adopted container: %+v (%v)", adopted, err)
	}
}

// REQ-E2E-10: a local provider binary reaches tofu through dev_overrides.
func TestALocalProviderBinary(t *testing.T) {
	endpoint, key := acceptance(t)
	opts := testOptions(endpoint, key, t.TempDir(), t.TempDir())
	opts.providerBinary = providerBinary(t)
	opts.suffix = "t2"
	p := newProcedure(&scriptedPerson{t: t}, opts)
	if detail, err := p.preflight(context.Background(), &p.stages[0].step); err != nil {
		var f *failure
		if errors.As(err, &f) {
			t.Fatalf("the preflight failed: %v\n%s", err, f.result.text)
		}
		t.Fatalf("the preflight failed: %v", err)
	} else if !strings.Contains(detail, "the server would create tofumantest-e2e-t2") {
		t.Fatalf("the preflight found: %s", detail)
	}
}

// scriptedPerson does in the webgui what the procedure asks for, through the API and through the
// test server.
type scriptedPerson struct {
	t        *testing.T
	endpoint string
	key      string
	name     string // the throwaway container of the run
	adoption string
	result   []string
}

func (u *scriptedPerson) Header([]string)           {}
func (u *scriptedPerson) Steps([]Step)              {}
func (u *scriptedPerson) Progress(string, string)   {}
func (u *scriptedPerson) Note(line string)          { u.t.Log(line) }
func (u *scriptedPerson) Finish(_ bool, l []string) { u.result = l }

func (u *scriptedPerson) Update(s Step) {
	if s.State == statePassed || s.State == stateFailed {
		u.t.Logf("%-4s %s: %s (%s)", s.ID, s.Title, s.Detail, took(s.Took))
	}
}

func (u *scriptedPerson) Confirm(Step, []string) (bool, error) { return true, nil }

// Ask deletes leftovers and the adopted container, and claims no container but its own.
func (u *scriptedPerson) Ask(question string) (bool, error) {
	return !strings.Contains(question, "Is it the container that you adopted?"), nil
}

func (u *scriptedPerson) Retry(s Step, tail []string) (bool, error) {
	u.t.Errorf("step %s failed: %s\n%s", s.ID, s.Detail, strings.Join(tail, "\n"))
	return false, nil
}

func (u *scriptedPerson) Await(s Step, _ []string, done <-chan struct{}) error {
	switch s.ID {
	case "4":
		u.editGreeting()
	case "11":
		u.adoptFromTemplate()
	}
	select {
	case <-done:
		return nil
	case <-time.After(3 * time.Minute):
		u.t.Errorf("step %s: the run did not notice the change of the person", s.ID)
		return errStopped
	}
}

func (u *scriptedPerson) client() *client.Client {
	c, err := client.New(client.Config{Endpoint: u.endpoint, APIKey: u.key})
	if err != nil {
		u.t.Fatal(err)
	}
	return c
}

// editGreeting changes GREETING around tofu, as Edit in the webgui does.
func (u *scriptedPerson) editGreeting() {
	ctx := context.Background()
	c := u.client()
	container, err := c.ContainerNamed(ctx, u.name)
	if err != nil || container == nil {
		u.t.Errorf("the person finds no %s: %v", u.name, err)
		return
	}
	d := container.Definition
	for i, e := range d.ConfigEntries {
		if e.Type == "VARIABLE" && e.Target == "GREETING" {
			d.ConfigEntries[i].Value = "changed in the webgui"
		}
	}
	operation, err := c.Update(ctx, container.ID, d, nil)
	if err == nil {
		_, err = c.Wait(ctx, operation, 5*time.Minute)
	}
	if err != nil {
		u.t.Errorf("the edit of the person: %v", err)
	}
}

// adoptFromTemplate has the test server create and adopt a container from the adoption test
// template (REQ-TST-12).
func (u *scriptedPerson) adoptFromTemplate() {
	request, err := http.NewRequest(http.MethodPost, strings.TrimSuffix(u.endpoint, "/")+"/person/adopt-from-template?name="+url.QueryEscape(u.adoption), nil)
	if err != nil {
		u.t.Error(err)
		return
	}
	request.Header.Set("x-api-key", u.key)
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		u.t.Errorf("the adoption of the person: %v", err)
		return
	}
	defer response.Body.Close()
	if body, _ := io.ReadAll(response.Body); response.StatusCode != http.StatusOK {
		u.t.Errorf("the test server did not adopt %s: %s", u.adoption, body)
	}
}
