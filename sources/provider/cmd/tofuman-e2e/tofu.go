// SPDX-License-Identifier: MIT

package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// tofu runs the tofu binary in the folder of a run. Each command gets a log file of its own, so a
// failed run keeps every output for the person and for a maintainer.
type tofu struct {
	dir  string
	env  []string
	logs int
}

// result is the outcome of one tofu command.
type result struct {
	code int
	log  string // the path of the log file
	text string // what tofu printed
}

// tofuEnv is the environment of the tofu processes: the API key travels only here (REQ-E2E-5).
func tofuEnv(cliConfig string, c Config, key, caCertificate string) []string {
	var env []string
	for _, entry := range os.Environ() {
		name, _, _ := strings.Cut(entry, "=")
		if name == "TF_CLI_CONFIG_FILE" || strings.HasPrefix(strings.ToUpper(name), "TOFUMAN_") {
			continue
		}
		env = append(env, entry)
	}
	env = append(env, "TF_CLI_CONFIG_FILE="+cliConfig, "TF_IN_AUTOMATION=1", "TOFUMAN_ENDPOINT="+c.Endpoint, "TOFUMAN_API_KEY="+key)
	if c.Insecure {
		env = append(env, "TOFUMAN_INSECURE=true")
	}
	if caCertificate != "" {
		env = append(env, "TOFUMAN_CA_CERTIFICATE="+caCertificate)
	}
	return env
}

func (t *tofu) command(ctx context.Context, args ...string) *exec.Cmd {
	cmd := exec.CommandContext(ctx, "tofu", args...)
	cmd.Dir = t.dir
	cmd.Env = t.env
	// An interrupt lets tofu finish writing its state; Windows has none, so it gets a kill.
	cmd.Cancel = func() error {
		if err := cmd.Process.Signal(os.Interrupt); err != nil {
			return cmd.Process.Kill()
		}
		return nil
	}
	cmd.WaitDelay = time.Minute
	return cmd
}

// run runs one tofu command for a step, with the flags that keep it from asking anything.
func (t *tofu) run(ctx context.Context, step string, args ...string) (result, error) {
	t.logs++
	log := filepath.Join(t.dir, fmt.Sprintf("%02d-step-%s-%s.txt", t.logs, step, args[0]))
	args = append(args[:1:1], append([]string{"-no-color", "-input=false"}, args[1:]...)...)
	var out bytes.Buffer
	cmd := t.command(ctx, args...)
	cmd.Stdout = &out
	cmd.Stderr = &out
	err := cmd.Run()
	if werr := os.WriteFile(log, out.Bytes(), 0o644); werr != nil {
		return result{}, werr
	}
	r := result{log: log, text: out.String()}
	var exit *exec.ExitError
	switch {
	case ctx.Err() != nil:
		return r, errStopped
	case err == nil:
	case errors.As(err, &exit):
		r.code = exit.ExitCode()
	default:
		return r, fmt.Errorf("tofu %s: %w", args[0], err)
	}
	return r, nil
}

// output returns the value of an output of the state.
func (t *tofu) output(ctx context.Context, name string) (string, error) {
	cmd := t.command(ctx, "output", "-raw", name)
	out, err := printed(cmd)
	if err != nil {
		return "", fmt.Errorf("tofu output %s: %w", name, err)
	}
	return strings.TrimSpace(out), nil
}

// tofuVersion returns the version of the tofu binary on PATH.
func tofuVersion(ctx context.Context) (string, error) {
	out, err := printed(exec.CommandContext(ctx, "tofu", "version", "-json"))
	if err != nil {
		return "", fmt.Errorf("tofu does not run: %w. Install OpenTofu, and put tofu on PATH", err)
	}
	var v struct {
		Version string `json:"terraform_version"`
	}
	if err := json.Unmarshal([]byte(out), &v); err != nil || v.Version == "" {
		return "", fmt.Errorf("tofu version -json printed no version: %s", strings.TrimSpace(out))
	}
	return v.Version, nil
}

// says reports whether tofu printed text that matches the pattern. tofu wraps the lines of a
// diagnostic, so a space in the pattern matches a line break too.
func (r result) says(pattern string) bool {
	return regexp.MustCompile(`(?s)` + strings.ReplaceAll(regexp.QuoteMeta(pattern), " ", `\s+`)).MatchString(r.text)
}

// tail is the end of what tofu printed, for a failed step (REQ-E2E-16).
func (r result) tail() []string {
	var lines []string
	for _, line := range strings.Split(strings.TrimRight(r.text, "\n"), "\n") {
		if strings.TrimSpace(line) != "" {
			lines = append(lines, line)
		}
	}
	if len(lines) > 14 {
		lines = lines[len(lines)-14:]
	}
	return lines
}
