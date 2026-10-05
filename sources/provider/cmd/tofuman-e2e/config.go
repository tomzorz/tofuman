// SPDX-License-Identifier: MIT

package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
)

// configName is the configuration file of REQ-E2E-2 in the working directory.
const configName = "tofuman-e2e.json"

// The sources of the API key (REQ-E2E-4).
const (
	source1Password = "1password"
	sourceCommand   = "command"
	sourceEnv       = "env"
	sourcePlain     = "plain"
)

// KeySource says where each run gets the API key. Only the field of its source is set.
type KeySource struct {
	Source    string `json:"source"`
	Reference string `json:"reference,omitempty"`
	Command   string `json:"command,omitempty"`
	Variable  string `json:"variable,omitempty"`
	Key       string `json:"key,omitempty"`
}

// Config is the configuration file of the e2e tool.
type Config struct {
	Endpoint string    `json:"endpoint"`
	APIKey   KeySource `json:"apiKey"`
	// BindRoot is a bind root of the policy, under which the throwaway container keeps /data.
	BindRoot string `json:"bindRoot"`
	// Port is the host port of the throwaway container, when 18099 is taken on the server.
	Port int `json:"port,omitempty"`
	// Insecure and CACertificate, the path of a PEM file, are the TLS settings of the provider.
	Insecure      bool   `json:"insecure,omitempty"`
	CACertificate string `json:"caCertificate,omitempty"`
}

const defaultPort = 18099

// location is where the configuration lives, and whether the runs go beside it (REQ-E2E-7).
type location struct {
	path  string
	local bool
}

// locate finds the configuration of REQ-E2E-2: the file named on the command line, else the
// one in the working directory, else the one in the user configuration directory. The file
// need not exist yet.
func locate(named string) (location, error) {
	if named != "" {
		abs, err := filepath.Abs(named)
		return location{path: abs, local: true}, err
	}
	here, err := filepath.Abs(configName)
	if err != nil {
		return location{}, err
	}
	if _, err := os.Stat(here); err == nil {
		return location{path: here, local: true}, nil
	}
	return userLocation()
}

func userLocation() (location, error) {
	dir, err := os.UserConfigDir()
	if err != nil {
		return location{}, fmt.Errorf("the operating system names no user configuration directory: %w", err)
	}
	return location{path: filepath.Join(dir, "tofuman", "e2e.json")}, nil
}

// runs is the folder that holds one folder per run (REQ-E2E-7).
func (l location) runs() (string, error) {
	if l.local {
		return filepath.Join(filepath.Dir(l.path), "tofuman-e2e-runs"), nil
	}
	dir, err := os.UserCacheDir()
	if err != nil {
		return "", fmt.Errorf("the operating system names no user cache directory: %w", err)
	}
	return filepath.Join(dir, "tofuman", "e2e-runs"), nil
}

// load reads and checks a configuration file.
func load(path string) (Config, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return Config{}, err
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	var c Config
	if err := decoder.Decode(&c); err != nil {
		return Config{}, fmt.Errorf("%s: %w", path, err)
	}
	if err := c.check(); err != nil {
		return Config{}, fmt.Errorf("%s: %w", path, err)
	}
	return c, nil
}

// save writes the configuration so that only its owner can read it, as it can hold the key.
func save(path string, c Config) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	raw, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, append(raw, '\n'), 0o600)
}

// check reports the first thing that is wrong with the configuration.
func (c Config) check() error {
	if err := checkEndpoint(c.Endpoint); err != nil {
		return err
	}
	if err := checkBindRoot(c.BindRoot); err != nil {
		return err
	}
	if c.Port < 0 || c.Port > 65535 {
		return fmt.Errorf("port %d is no port", c.Port)
	}
	return c.APIKey.check()
}

func (c Config) port() int {
	if c.Port == 0 {
		return defaultPort
	}
	return c.Port
}

// dataPath is the host path of the throwaway container, under the bind root.
func (c Config) dataPath() string {
	return strings.TrimSuffix(c.BindRoot, "/") + "/tofuman-e2e"
}

func checkEndpoint(endpoint string) error {
	u, err := url.Parse(endpoint)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return fmt.Errorf("the endpoint %q is not an http or https URL, such as http://192.0.2.10", endpoint)
	}
	return nil
}

func checkBindRoot(root string) error {
	if !strings.HasPrefix(root, "/") || strings.Contains(root, "..") {
		return fmt.Errorf("the bind root %q is not an absolute path on the server, such as /mnt/user/appdata/", root)
	}
	return nil
}

var keyField = map[string]string{source1Password: "reference", sourceCommand: "command", sourceEnv: "variable", sourcePlain: "key"}

func (k KeySource) check() error {
	values := map[string]string{source1Password: k.Reference, sourceCommand: k.Command, sourceEnv: k.Variable, sourcePlain: k.Key}
	value, known := values[k.Source]
	if !known {
		return fmt.Errorf("apiKey.source %q is none of 1password, command, env, and plain", k.Source)
	}
	if strings.TrimSpace(value) == "" {
		return fmt.Errorf("apiKey.source %s needs apiKey.%s", k.Source, keyField[k.Source])
	}
	return nil
}

// only keeps the field of the source, so that a key pasted once leaves the file when the source
// changes.
func (k KeySource) only() KeySource {
	kept := KeySource{Source: k.Source}
	switch k.Source {
	case source1Password:
		kept.Reference = k.Reference
	case sourceCommand:
		kept.Command = k.Command
	case sourceEnv:
		kept.Variable = k.Variable
	case sourcePlain:
		kept.Key = k.Key
	}
	return kept
}

// resolve returns the API key. An error never holds the key (REQ-E2E-5).
func (k KeySource) resolve(ctx context.Context) (string, error) {
	var key string
	switch k.Source {
	case source1Password:
		out, err := printed(exec.CommandContext(ctx, "op", "read", k.Reference))
		if err != nil {
			return "", fmt.Errorf("the 1Password CLI could not read %s: %w", k.Reference, err)
		}
		key = out
	case sourceCommand:
		out, err := printed(shell(ctx, k.Command))
		if err != nil {
			return "", fmt.Errorf("the command of the API key failed: %w", err)
		}
		key = out
	case sourceEnv:
		key = os.Getenv(k.Variable)
	case sourcePlain:
		key = k.Key
	}
	key = strings.TrimSpace(key)
	if key == "" {
		return "", fmt.Errorf("the API key from apiKey.source %s is empty", k.Source)
	}
	return key, nil
}

// shell runs a command line through the shell of the platform.
func shell(ctx context.Context, line string) *exec.Cmd {
	if runtime.GOOS == "windows" {
		return exec.CommandContext(ctx, "cmd", "/C", line)
	}
	return exec.CommandContext(ctx, "sh", "-c", line)
}

func shellName() string {
	if runtime.GOOS == "windows" {
		return "cmd"
	}
	return "sh"
}

// printed runs a command and returns what it printed. A failure carries what the command wrote
// to stderr, where a key manager explains itself; stdout, which holds the key, stays out of it.
func printed(cmd *exec.Cmd) (string, error) {
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	out, err := cmd.Output()
	if errors.Is(err, exec.ErrNotFound) {
		return "", fmt.Errorf("%s is not on PATH", cmd.Args[0])
	}
	if err != nil {
		if message := strings.TrimSpace(stderr.String()); message != "" {
			return "", errors.New(message)
		}
		return "", err
	}
	return string(out), nil
}
