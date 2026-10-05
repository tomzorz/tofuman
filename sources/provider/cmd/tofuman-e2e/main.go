// SPDX-License-Identifier: MIT

// tofuman-e2e runs the end-to-end procedure of tofuman against one Unraid server, and asks the
// person for the steps in the webgui (spec section 18.1).
package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"flag"
	"fmt"
	"io/fs"
	"os"
	"os/signal"
	"path/filepath"
	"time"
)

// version is set at release time with -ldflags "-X main.version=<version>".
var version = "dev"

func main() {
	os.Exit(run(os.Args[1:]))
}

func run(args []string) int {
	flags := flag.NewFlagSet("tofuman-e2e", flag.ContinueOnError)
	again := flags.Bool("setup", false, "ask for the endpoint, the source of the API key, and the bind root again")
	named := flags.String("config", "", "the configuration file, instead of "+configName+" here or the one in your user folder")
	plainOutput := flags.Bool("plain", false, "print one line per event instead of the interactive view")
	binary := flags.String("provider-binary", "", "give tofu this provider binary instead of a provider release")
	release := flags.String("provider-version", "", "give tofu this provider release instead of the one of this tool")
	showVersion := flags.Bool("version", false, "print the version and stop")
	flags.Usage = func() {
		fmt.Fprint(flags.Output(), "tofuman-e2e runs the end-to-end procedure of tofuman against one Unraid server.\n\nUsage: tofuman-e2e [flags]\n\n")
		flags.PrintDefaults()
	}
	if err := flags.Parse(args); err != nil {
		if errors.Is(err, flag.ErrHelp) {
			return 0
		}
		return 2
	}
	if *showVersion {
		fmt.Println(version)
		return 0
	}
	ctx, stopSignals := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stopSignals()
	interactive := !*plainOutput && terminal(os.Stdin) && terminal(os.Stdout)
	passed, err := start(ctx, interactive, *again, *named, *binary, *release)
	if err != nil {
		fmt.Fprintln(os.Stderr, "tofuman-e2e: "+err.Error())
		return 1
	}
	if !passed {
		return 1
	}
	return 0
}

// start finds or asks for the configuration, gets the API key, and runs the procedure.
func start(ctx context.Context, interactive, again bool, named, binary, release string) (bool, error) {
	at, err := locate(named)
	if err != nil {
		return false, err
	}
	config, err := load(at.path)
	missing := errors.Is(err, fs.ErrNotExist)
	if err != nil && !missing {
		return false, err
	}
	if missing || again {
		if !interactive {
			return false, fmt.Errorf("there is no configuration at %s. Run tofuman-e2e once in a terminal, which asks for it, or write that file", at.path)
		}
		if at, config, err = askConfiguration(at, config, missing); err != nil {
			return false, err
		}
	}
	key, err := config.APIKey.resolve(ctx)
	if err != nil {
		return false, err
	}
	runs, err := at.runs()
	if err != nil {
		return false, err
	}
	runDir := filepath.Join(runs, time.Now().UTC().Format("20060102T150405Z"))
	if err := os.MkdirAll(runDir, 0o755); err != nil {
		return false, err
	}
	cache, err := os.UserCacheDir()
	if err != nil {
		return false, fmt.Errorf("the operating system names no user cache directory: %w", err)
	}
	opts := options{
		config: config, key: key, run: runDir, cache: filepath.Join(cache, "tofuman"),
		version: version, providerBinary: binary, providerVersion: release, releases: github,
		prefix: "tofuman-e2e", adoption: "tofuman-adoption-test", suffix: randomSuffix(),
	}
	if !interactive {
		return newProcedure(newPlain(ctx, os.Stdin, os.Stdout), opts).execute(ctx), nil
	}
	ctx, stop := context.WithCancel(ctx)
	defer stop()
	view := newTUI(stop)
	result := make(chan bool, 1)
	go func() { result <- newProcedure(view, opts).execute(ctx) }()
	viewErr := view.run()
	stop()
	select {
	case passed := <-result:
		return passed, viewErr
	case <-time.After(300 * time.Millisecond):
		fmt.Fprintln(os.Stderr, "Waiting for tofu to end its command. The summary goes to "+runDir)
		return <-result, viewErr
	}
}

// askConfiguration runs the setup of REQ-E2E-3 and saves its answers.
func askConfiguration(at location, config Config, missing bool) (location, Config, error) {
	here, err := filepath.Abs(configName)
	if err != nil {
		return at, config, err
	}
	user, err := userLocation()
	if err != nil {
		return at, config, err
	}
	var current *Config
	if !missing {
		current = &config
	}
	config, path, err := setup(current, at.path, here, user.path)
	if err != nil {
		return at, config, err
	}
	if err := save(path, config); err != nil {
		return at, config, err
	}
	fmt.Println("Saved the configuration in " + path)
	return location{path: path, local: path != user.path}, config, nil
}

func terminal(f *os.File) bool {
	info, err := f.Stat()
	return err == nil && info.Mode()&os.ModeCharDevice != 0
}

// randomSuffix is REQ-E2E-11.
func randomSuffix() string {
	b := make([]byte, 2)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}
