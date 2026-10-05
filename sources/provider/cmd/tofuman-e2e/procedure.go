// SPDX-License-Identifier: MIT

package main

import (
	"context"
	"errors"
	"fmt"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/tomzorz/tofuman/sources/provider/internal/client"
)

// adoptionTemplate is where the plugin installs the adoption test template (REQ-TAB-42).
const adoptionTemplate = "/usr/local/emhttp/plugins/tofuman/templates/tofuman-adoption-test.xml"

// testIcon is the icon of the throwaway container, the one of the adoption test template too. On
// webgui 7.3.2, removing a container without an icon deletes the default icon of DockerMan, and
// an open Docker page then asks for it in a loop (REQ-E2E-20).
const testIcon = "https://raw.githubusercontent.com/tomzorz/tofuman/main/sources/plugin/templates/tofuman-test-icon.png"

// options are what a run needs besides the person.
type options struct {
	config          Config
	key             string
	run             string // the folder of the run
	cache           string // the folder of the provider mirror
	version         string // of the e2e tool
	providerBinary  string
	providerVersion string
	releases        releases
	prefix          string // of the names of the throwaway containers
	adoption        string // the name in the adoption test template, which leftovers can carry
	suffix          string // REQ-E2E-11
}

// procedure is one run of the end-to-end procedure of spec section 18.
type procedure struct {
	ui       UI
	opts     options
	api      *client.Client
	tofu     *tofu
	stages   []*stage
	began    time.Time
	facts    []string
	existing []client.Container // the managed containers when the run began
	first    string             // the name of the throwaway container
	renamed  string             // its name after step 7
	id       string             // its managed ID
	adopted  string             // the container that the person adopts in step 11
}

type stage struct {
	step Step
	run  func(ctx context.Context, s *Step) (string, error)
}

// failure is a failed step that tofu printed the reason for.
type failure struct {
	message string
	result  result
}

func (f *failure) Error() string { return f.message }

func failed(r result, format string, args ...any) error {
	return &failure{message: fmt.Sprintf(format, args...) + " (" + filepath.Base(r.log) + ")", result: r}
}

func newProcedure(ui UI, opts options) *procedure {
	p := &procedure{ui: ui, opts: opts}
	p.first = opts.prefix + "-" + opts.suffix
	p.renamed = p.first + "-renamed"
	p.facts = []string{"e2e " + opts.version, opts.config.Endpoint}
	for _, s := range []struct {
		id, title string
		run       func(context.Context, *Step) (string, error)
	}{
		{"pre", "Preflight", p.preflight},
		{"left", "Leftovers", p.leftovers},
		{"1-2", "Create", p.create},
		{"3", "Look in the webgui", p.look},
		{"4", "Edit in the webgui", p.edit},
		{"5", "Drift", p.drift},
		{"6", "Revert", p.revert},
		{"7", "Rename", p.rename},
		{"8", "Refusal at plan time", p.refusal},
		{"9", "Start check", p.startCheck},
		{"10", "Destroy", p.destroy},
		{"11", "Adopt in the tab", p.adopt},
		{"12", "Import", p.importAdopted},
		{"13", "Activity in the tab", p.activity},
	} {
		p.stages = append(p.stages, &stage{step: Step{ID: s.id, Title: s.title}, run: s.run})
	}
	return p
}

// execute runs every step in order (REQ-E2E-1), writes the summary (REQ-E2E-17), and offers to
// delete the adopted container (REQ-E2E-19). It reports whether every step passed.
func (p *procedure) execute(ctx context.Context) bool {
	p.began = time.Now()
	p.ui.Header(p.header())
	steps := make([]Step, len(p.stages))
	for i, st := range p.stages {
		steps[i] = st.step
	}
	p.ui.Steps(steps)
	passed := true
	for _, st := range p.stages {
		if !p.runStage(ctx, st) {
			passed = false
			break
		}
	}
	if passed && ctx.Err() == nil {
		p.offerCleanup(ctx)
	}
	lines := p.summary(passed)
	if err := os.WriteFile(filepath.Join(p.opts.run, "summary.txt"), []byte(strings.Join(lines, "\n")+"\n"), 0o644); err != nil {
		lines = append(lines, "The summary file could not be written: "+err.Error())
	}
	p.ui.Finish(passed, lines)
	return passed
}

// runStage runs one step until it passes, or until the person stops retrying it (REQ-E2E-16).
func (p *procedure) runStage(ctx context.Context, st *stage) bool {
	s := &st.step
	for {
		s.State, s.Began, s.Took, s.Detail = stateRunning, time.Now(), 0, ""
		p.ui.Update(*s)
		detail, err := st.run(ctx, s)
		s.Took = time.Since(s.Began)
		if err == nil {
			s.State, s.Detail = statePassed, detail
			p.ui.Update(*s)
			return true
		}
		s.State, s.Detail = stateFailed, err.Error()
		if errors.Is(err, errStopped) || ctx.Err() != nil {
			s.Detail = errStopped.Error()
			p.ui.Update(*s)
			return false
		}
		p.ui.Update(*s)
		var f *failure
		var tail []string
		if errors.As(err, &f) {
			tail = f.result.tail()
		}
		again, err := p.ui.Retry(*s, tail)
		if err != nil || !again {
			return false
		}
	}
}

func (p *procedure) header() []string {
	return []string{strings.Join(p.facts, " · "), "Run folder: " + p.opts.run}
}

func (p *procedure) progress(s *Step, what string) {
	p.ui.Progress(s.ID, what)
}

// waiting marks a step as waiting for the person, until it runs again.
func (p *procedure) waiting(s *Step, what string) {
	s.State, s.Detail = stateWaiting, what
	p.ui.Update(*s)
}

func (p *procedure) running(s *Step) {
	s.State, s.Detail = stateRunning, ""
	p.ui.Update(*s)
}

func (p *procedure) tofuRun(ctx context.Context, s *Step, args ...string) (result, error) {
	p.progress(s, "tofu "+args[0])
	return p.tofu.run(ctx, s.ID, args...)
}

func (p *procedure) apply(ctx context.Context, s *Step, what string, vars ...string) error {
	r, err := p.tofuRun(ctx, s, append([]string{"apply", "-auto-approve"}, vars...)...)
	if err != nil {
		return err
	}
	if r.code != 0 {
		return failed(r, "%s failed", what)
	}
	return nil
}

func (p *procedure) plan(ctx context.Context, s *Step, vars ...string) (result, error) {
	return p.tofuRun(ctx, s, append([]string{"plan", "-detailed-exitcode"}, vars...)...)
}

func (p *procedure) noChange(ctx context.Context, s *Step, what string, vars ...string) error {
	r, err := p.plan(ctx, s, vars...)
	if err != nil {
		return err
	}
	if r.code != 0 {
		return failed(r, "%s shows a change, or fails", what)
	}
	return nil
}

// watch polls the server while the person works in the webgui (REQ-E2E-12), until found reports
// that the change of the person is there, and returns what found saw.
func (p *procedure) watch(ctx context.Context, s *Step, lines []string, found func(context.Context) (string, bool, error)) (string, error) {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	done := make(chan struct{})
	var value string
	go func() {
		ticker := time.NewTicker(2 * time.Second)
		defer ticker.Stop()
		for {
			v, ok, err := found(ctx)
			if ok {
				value = v
				close(done)
				return
			}
			if err != nil && ctx.Err() == nil {
				p.progress(s, "the server: "+err.Error())
			}
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
			}
		}
	}()
	if err := p.ui.Await(*s, lines, done); err != nil {
		return "", err
	}
	return value, nil
}

// until polls cond every second until it holds or the time is up.
func until(ctx context.Context, limit time.Duration, cond func() (bool, error)) error {
	deadline := time.Now().Add(limit)
	for {
		ok, err := cond()
		if ok {
			return nil
		}
		if time.Now().After(deadline) {
			if err != nil {
				return err
			}
			return fmt.Errorf("not within %s", limit)
		}
		select {
		case <-ctx.Done():
			return errStopped
		case <-time.After(time.Second):
		}
	}
}

func variable(c *client.Container, key string) (string, bool) {
	for _, e := range c.Definition.ConfigEntries {
		if e.Type == "VARIABLE" && e.Target == key {
			return e.Value, true
		}
	}
	return "", false
}

// preflight is REQ-E2E-8 and REQ-E2E-10: tofu, the provider, the API, and a plan that the server
// would accept.
func (p *procedure) preflight(ctx context.Context, s *Step) (string, error) {
	p.progress(s, "tofu version")
	tofuRelease, err := tofuVersion(ctx)
	if err != nil {
		return "", err
	}
	providerFact, cli, err := p.supplyProvider(ctx, s)
	if err != nil {
		return "", err
	}
	ca := ""
	if p.opts.config.CACertificate != "" {
		pem, err := os.ReadFile(p.opts.config.CACertificate)
		if err != nil {
			return "", fmt.Errorf("caCertificate: %w", err)
		}
		ca = string(pem)
	}
	p.tofu = &tofu{dir: p.opts.run, env: tofuEnv(cli, p.opts.config, p.opts.key, ca)}
	p.facts = append(p.facts, "tofu "+tofuRelease, providerFact)
	p.ui.Header(p.header())

	p.progress(s, "the API")
	api, err := client.New(client.Config{Endpoint: p.opts.config.Endpoint, APIKey: p.opts.key, CACertificate: ca, Insecure: p.opts.config.Insecure, Version: "e2e-" + p.opts.version})
	if err != nil {
		return "", err
	}
	if p.existing, err = api.Containers(ctx); err != nil {
		return "", apiProblem(p.opts.config.Endpoint, err)
	}
	p.api = api

	if err := p.writeConfiguration(); err != nil {
		return "", err
	}
	// dev_overrides need no init, and an init would look for the provider in the registry
	if p.opts.providerBinary == "" {
		r, err := p.tofuRun(ctx, s, "init")
		if err != nil {
			return "", err
		}
		if r.code != 0 {
			return "", failed(r, "tofu init failed")
		}
	}
	r, err := p.plan(ctx, s)
	if err != nil {
		return "", err
	}
	if r.code != 2 {
		return "", failed(r, "the server would not create %s; the output of tofu plan names each failed check", p.first)
	}
	return fmt.Sprintf("tofu, %s, and the API answer, and the server would create %s", providerFact, p.first), nil
}

// supplyProvider prepares the provider for tofu, and returns how the summary names it and the
// path of the CLI configuration.
func (p *procedure) supplyProvider(ctx context.Context, s *Step) (string, string, error) {
	if p.opts.providerBinary != "" {
		dir, err := localProvider(p.opts.run, p.opts.providerBinary)
		if err != nil {
			return "", "", err
		}
		cli, err := cliConfig(p.opts.run, "", dir)
		return "provider " + p.opts.providerBinary, cli, err
	}
	version := p.opts.providerVersion
	if version == "" && p.opts.version != "dev" {
		version = p.opts.version
	}
	if version == "" {
		p.progress(s, "the newest provider release")
		newest, err := p.opts.releases.newest(ctx)
		if err != nil {
			return "", "", err
		}
		version = newest
	}
	p.opts.providerVersion = version
	p.progress(s, "provider "+version+" from its release")
	mirror, err := p.opts.releases.mirror(ctx, p.opts.cache, version)
	if err != nil {
		return "", "", err
	}
	cli, err := cliConfig(p.opts.run, mirror, "")
	return "provider " + version, cli, err
}

func apiProblem(endpoint string, err error) error {
	var answer *client.Error
	if errors.As(err, &answer) && (answer.Refused() || answer.Code == "UNAUTHENTICATED" || answer.Code == "FORBIDDEN") {
		return fmt.Errorf("the API at %s refused the API key: %w. Check the key, and that its ID is on keyAllowlist in the policy on the tofuman tab", endpoint, err)
	}
	return fmt.Errorf("the API at %s: %w", endpoint, err)
}

// writeConfiguration writes the HCL of the throwaway container, a path, a port, a variable, a
// secret, and a label (step 1). Variables rename it and give it a flag or a command later.
func (p *procedure) writeConfiguration() error {
	version := ""
	if p.opts.providerBinary == "" {
		version = fmt.Sprintf("\n      version = %q", "= "+p.opts.providerVersion)
	}
	providers := fmt.Sprintf(`terraform {
  required_providers {
    tofuman = {
      source  = "tomzorz/tofuman"%s
    }
  }
}

provider "tofuman" {}
`, version)
	container := fmt.Sprintf(`variable "name" {
  type    = string
  default = %q
}

variable "extra_params" {
  type    = list(string)
  default = []
}

variable "command" {
  type    = list(string)
  default = ["sleep", "86400"]
}

resource "tofuman_container" "e2e" {
  name         = var.name
  repository   = "busybox:latest"
  network      = "bridge"
  autostart    = true
  extra_params = var.extra_params
  post_args    = var.command
  icon         = %q

  path {
    host_path      = %q
    container_path = "/data"
  }
  port {
    host_port      = "%d"
    container_port = "8080"
  }
  variable {
    key   = "GREETING"
    value = "hello"
  }
  secret {
    key   = "TOKEN"
    value = "not-a-real-secret"
  }
  label {
    key   = "com.example.purpose"
    value = "tofuman-e2e"
  }
}

output "id" {
  value = tofuman_container.e2e.id
}
`, p.first, testIcon, p.opts.config.dataPath(), p.opts.config.port())
	if err := os.WriteFile(filepath.Join(p.opts.run, "providers.tf"), []byte(providers), 0o644); err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(p.opts.run, "e2e.tf"), []byte(container), 0o644)
}

// leftovers is REQ-E2E-9.
func (p *procedure) leftovers(ctx context.Context, s *Step) (string, error) {
	var left []client.Container
	for _, c := range p.existing {
		if strings.HasPrefix(c.Definition.Name, p.opts.prefix+"-") || c.Definition.Name == p.opts.adoption {
			left = append(left, c)
		}
	}
	if len(left) == 0 {
		return "none", nil
	}
	names := make([]string, len(left))
	for i, c := range left {
		names[i] = c.Definition.Name
	}
	list := strings.Join(names, ", ")
	p.waiting(s, "a question for you")
	yes, err := p.ui.Ask("Earlier runs left these managed containers: " + list + ". Delete them now?")
	if err != nil {
		return "", err
	}
	if !yes {
		return "kept " + list, nil
	}
	p.running(s)
	for _, c := range left {
		p.progress(s, "deleting "+c.Definition.Name)
		operation, err := p.api.Delete(ctx, c.ID)
		if err == nil {
			_, err = p.api.Wait(ctx, operation, 5*time.Minute)
		}
		if err != nil {
			return "", fmt.Errorf("deleting %s: %w", c.Definition.Name, err)
		}
	}
	return "deleted " + list, nil
}

// create is steps 1 and 2.
func (p *procedure) create(ctx context.Context, s *Step) (string, error) {
	if err := p.apply(ctx, s, "the apply that creates "+p.first); err != nil {
		return "", err
	}
	id, err := p.tofu.output(ctx, "id")
	if err != nil {
		return "", err
	}
	p.id = id
	if err := p.noChange(ctx, s, "the plan right after the apply"); err != nil {
		return "", err
	}
	return fmt.Sprintf("%s has the managed ID %s, and the next plan shows no change", p.first, id), nil
}

// look is step 3.
func (p *procedure) look(ctx context.Context, s *Step) (string, error) {
	p.progress(s, "asking the server whether "+p.first+" runs")
	err := until(ctx, 30*time.Second, func() (bool, error) {
		c, err := p.api.Container(ctx, p.id)
		return c != nil && c.Running, err
	})
	if err != nil {
		return "", fmt.Errorf("the server does not report %s as running: %w", p.first, err)
	}
	p.waiting(s, "a look in the webgui")
	yes, err := p.ui.Confirm(*s, []string{
		"On the Docker page of the webgui, check " + p.first + ":",
		"• it runs, and the tofu badge follows its name",
		"• its menu offers Edit",
		"• it shows an update status, such as up-to-date",
	})
	if err != nil {
		return "", err
	}
	if !yes {
		return "", errors.New("the webgui does not show it as described")
	}
	return "it runs, carries the badge, offers Edit, and shows an update status", nil
}

// edit is step 4.
func (p *procedure) edit(ctx context.Context, s *Step) (string, error) {
	p.waiting(s, "your edit in the webgui")
	value, err := p.watch(ctx, s, []string{
		"On the Docker page, open the menu of " + p.first + " and select Edit.",
		"Change GREETING to anything but hello, and select Apply.",
		"The run goes on by itself when the server reports the new value.",
	}, func(ctx context.Context) (string, bool, error) {
		c, err := p.api.Container(ctx, p.id)
		if err != nil || c == nil {
			return "", false, err
		}
		value, present := variable(c, "GREETING")
		return value, present && value != "hello", nil
	})
	if err != nil {
		return "", err
	}
	return "GREETING is " + strconv.Quote(value) + " on the server", nil
}

// drift is step 5. tofu hides the unchanged attributes of a block, so the plan names the value
// that it goes back to, not the key.
func (p *procedure) drift(ctx context.Context, s *Step) (string, error) {
	r, err := p.plan(ctx, s)
	if err != nil {
		return "", err
	}
	if r.code != 2 || !r.says(`"hello"`) {
		return "", failed(r, "tofu plan does not show the edit of GREETING as drift")
	}
	return "tofu plan shows the edit of GREETING as drift", nil
}

// revert is step 6.
func (p *procedure) revert(ctx context.Context, s *Step) (string, error) {
	if err := p.apply(ctx, s, "the apply that puts hello back"); err != nil {
		return "", err
	}
	if err := p.noChange(ctx, s, "the plan after the revert"); err != nil {
		return "", err
	}
	c, err := p.api.Container(ctx, p.id)
	if err != nil {
		return "", err
	}
	if c == nil {
		return "", errors.New("the server no longer reports the container after the revert")
	}
	if value, _ := variable(c, "GREETING"); value != "hello" {
		return "", fmt.Errorf("the server reports GREETING as %q after the revert", value)
	}
	return "the apply puts hello back, and the next plan shows no change", nil
}

func (p *procedure) renamedVars(more ...string) []string {
	return append([]string{"-var", "name=" + p.renamed}, more...)
}

// rename is step 7.
func (p *procedure) rename(ctx context.Context, s *Step) (string, error) {
	r, err := p.plan(ctx, s, p.renamedVars()...)
	if err != nil {
		return "", err
	}
	if r.code != 2 || !r.says("updated in-place") || r.says("must be replaced") {
		return "", failed(r, "the rename does not plan an update in place")
	}
	if err := p.apply(ctx, s, "the apply that renames "+p.first, p.renamedVars()...); err != nil {
		return "", err
	}
	byNew, err := p.api.ContainerNamed(ctx, p.renamed)
	if err != nil {
		return "", err
	}
	byOld, err := p.api.ContainerNamed(ctx, p.first)
	if err != nil {
		return "", err
	}
	if byNew == nil || byNew.ID != p.id || byOld != nil {
		return "", fmt.Errorf("the server does not report the managed ID %s under %s alone", p.id, p.renamed)
	}
	return fmt.Sprintf("the rename updates in place, and the server reports %s under %s", p.id, p.renamed), nil
}

// refusal is step 8: the plan names the failed check before anything changes (REQ-PRV-15).
func (p *procedure) refusal(ctx context.Context, s *Step) (string, error) {
	r, err := p.plan(ctx, s, p.renamedVars("-var", `extra_params=["--tofuman-e2e-unknown"]`)...)
	if err != nil {
		return "", err
	}
	if r.code != 1 || !r.says("tofuman would refuse") || !r.says("tofuman-e2e-unknown") {
		return "", failed(r, "tofu plan does not name the flag that tofuman does not know")
	}
	c, err := p.api.Container(ctx, p.id)
	if err != nil {
		return "", err
	}
	if c == nil || len(c.Definition.ExtraParams) != 0 {
		return "", errors.New("the refused plan changed the container on the server")
	}
	return "tofu plan names the flag that tofuman does not know, and nothing changes", nil
}

// startCheck is step 9: a command that exits at once fails the apply, and the previous container
// comes back (REQ-MUT-19).
func (p *procedure) startCheck(ctx context.Context, s *Step) (string, error) {
	r, err := p.tofuRun(ctx, s, append([]string{"apply", "-auto-approve"}, p.renamedVars("-var", `command=["sh","-c","echo tofuman-e2e-start-check; exit 3"]`)...)...)
	if err != nil {
		return "", err
	}
	if r.code == 0 || !r.says("start check") || !r.says("tofuman-e2e-start-check") {
		return "", failed(r, "the apply does not fail at the start check with the log line of the container")
	}
	if err := p.noChange(ctx, s, "the plan without the new command", p.renamedVars()...); err != nil {
		return "", err
	}
	c, err := p.api.Container(ctx, p.id)
	if err != nil {
		return "", err
	}
	if c == nil || !c.Running || !slices.Equal(c.Definition.PostArgs, []string{"sleep", "86400"}) {
		return "", errors.New("the server does not report the previous container as running")
	}
	return "the apply fails at the start check with the log line, and the previous container runs again", nil
}

// destroy is step 10.
func (p *procedure) destroy(ctx context.Context, s *Step) (string, error) {
	r, err := p.tofuRun(ctx, s, append([]string{"destroy", "-auto-approve"}, p.renamedVars()...)...)
	if err != nil {
		return "", err
	}
	if r.code != 0 {
		return "", failed(r, "tofu destroy failed")
	}
	c, err := p.api.ContainerNamed(ctx, p.renamed)
	if err != nil {
		return "", err
	}
	if c != nil {
		return "", fmt.Errorf("the server still reports %s", p.renamed)
	}
	// the import of step 12 plans against the adopted container alone
	if err := os.Remove(filepath.Join(p.opts.run, "e2e.tf")); err != nil {
		return "", err
	}
	return "tofu destroy removes " + p.renamed + ", and the server no longer reports it", nil
}

// adopt is step 11. The container of the adoption test template is the one. Any other hand-made
// container works too, once the person confirms it, since a tofu run elsewhere can make a
// container managed at the same time.
func (p *procedure) adopt(ctx context.Context, s *Step) (string, error) {
	p.progress(s, "listing the managed containers")
	list, err := p.api.Containers(ctx)
	if err != nil {
		return "", err
	}
	known := map[string]bool{}
	for _, c := range list {
		known[c.ID] = true
	}
	link := strings.TrimSuffix(p.opts.config.Endpoint, "/") + "/Docker/AddContainer?xmlTemplate=user:" + adoptionTemplate
	lines := []string{
		"Open Add Container with the template tofuman-adoption-test filled in, and select Apply:",
		link,
		"Then on the tofuman tab, open Hand-made containers, tick tofuman-adoption-test, and select Adopt selected.",
		"The run goes on by itself once tofuman manages it. Any other hand-made container works too.",
	}
	for {
		p.waiting(s, "your adoption in the tab")
		skip := maps.Clone(known) // the watcher reads its own copy
		id, err := p.watch(ctx, s, lines, func(ctx context.Context) (string, bool, error) {
			list, err := p.api.Containers(ctx)
			if err != nil {
				return "", false, err
			}
			other := ""
			for _, c := range list {
				if skip[c.ID] {
					continue
				}
				if c.Definition.Name == p.opts.adoption {
					return c.ID, true, nil
				}
				if other == "" {
					other = c.ID
				}
			}
			return other, other != "", nil
		})
		if err != nil {
			return "", err
		}
		known[id] = true
		c, err := p.api.Container(ctx, id)
		if err != nil {
			return "", err
		}
		if c == nil {
			continue
		}
		if name := c.Definition.Name; name != p.opts.adoption {
			yes, err := p.ui.Ask("tofuman now manages " + name + ", which is not " + p.opts.adoption + ". Is it the container that you adopted?")
			if err != nil {
				return "", err
			}
			if !yes {
				continue
			}
		}
		p.adopted = c.Definition.Name
		return "tofuman manages " + p.adopted, nil
	}
}

// importAdopted is step 12.
func (p *procedure) importAdopted(ctx context.Context, s *Step) (string, error) {
	block := fmt.Sprintf("import {\n  to = tofuman_container.adopted\n  id = %q\n}\n", p.adopted)
	if err := os.WriteFile(filepath.Join(p.opts.run, "adopt.tf"), []byte(block), 0o644); err != nil {
		return "", err
	}
	generated := filepath.Join(p.opts.run, "adopted.tf")
	_ = os.Remove(generated) // a retry writes it again
	r, err := p.plan(ctx, s, "-generate-config-out=adopted.tf")
	if err != nil {
		return "", err
	}
	if _, statErr := os.Stat(generated); (r.code != 0 && r.code != 2) || statErr != nil {
		return "", failed(r, "tofu does not write the HCL of %s", p.adopted)
	}
	if err := p.apply(ctx, s, "the apply that imports "+p.adopted); err != nil {
		return "", err
	}
	if err := p.noChange(ctx, s, "the plan after the import"); err != nil {
		return "", err
	}
	return "tofu imports " + p.adopted + " by its name, writes matching HCL, and the next plan shows no change", nil
}

// activity is step 13.
func (p *procedure) activity(_ context.Context, s *Step) (string, error) {
	p.waiting(s, "a look in the tab")
	yes, err := p.ui.Confirm(*s, []string{
		"On the tofuman tab, check the Activity list:",
		"• each mutation of this run, and the adoption of " + p.adopted,
		"• the failed update of step 9 opens with the log line tofuman-e2e-start-check",
	})
	if err != nil {
		return "", err
	}
	if !yes {
		return "", errors.New("the tab does not show it as described")
	}
	return "the activity shows each mutation, the adoption, and the operation of step 9", nil
}

// offerCleanup is REQ-E2E-19.
func (p *procedure) offerCleanup(ctx context.Context) {
	yes, err := p.ui.Ask("Every step passed. Delete " + p.adopted + " again, through tofu destroy?")
	if err != nil || !yes {
		p.ui.Note(p.adopted + " stays on the server and in the state of the run folder; tofu destroy there removes it.")
		return
	}
	r, err := p.tofu.run(ctx, "end", "destroy", "-auto-approve")
	switch {
	case err != nil:
		p.ui.Note("The removal of " + p.adopted + " stopped: " + err.Error())
	case r.code != 0:
		p.ui.Note("The removal of " + p.adopted + " failed; tofu destroy in the run folder tries again (" + filepath.Base(r.log) + ").")
	default:
		p.ui.Note(p.adopted + " is gone. " + p.opts.config.dataPath() + " stays on the server, as every delete leaves host paths.")
	}
}

// summary is REQ-E2E-17.
func (p *procedure) summary(passed bool) []string {
	lines := []string{"tofuman end-to-end, " + p.began.UTC().Format(time.RFC3339), strings.Join(p.facts, " · ")}
	for _, st := range p.stages {
		s := st.step
		switch s.State {
		case statePassed:
			lines = append(lines, fmt.Sprintf("ok    %-4s %s %8s  %s: %s", s.ID, clock(s.Began), took(s.Took), s.Title, s.Detail))
		case stateFailed:
			lines = append(lines, fmt.Sprintf("FAIL  %-4s %s %8s  %s: %s", s.ID, clock(s.Began), took(s.Took), s.Title, s.Detail))
		default:
			lines = append(lines, fmt.Sprintf("--    %-4s %-17s %s (not run)", s.ID, "", s.Title))
		}
	}
	if passed {
		lines = append(lines, "Every step passed.")
	} else {
		lines = append(lines, "Stopped. The throwaway containers stay as they are. Download the diagnostics file from the tofuman tab, and do not promote the candidate release.")
	}
	return append(lines, "Run folder: "+p.opts.run)
}
