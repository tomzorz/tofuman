// SPDX-License-Identifier: MIT

package main

import (
	"errors"
	"strings"

	"charm.land/huh/v2"
)

// setup asks for the configuration of REQ-E2E-3, starting from the current one if there is one,
// and returns it with the path that the person picked for it: here or user.
func setup(current *Config, currentPath, here, user string) (Config, string, error) {
	c := Config{BindRoot: "/mnt/user/appdata/", APIKey: KeySource{Source: source1Password}}
	where := user
	if current != nil {
		c = *current
		where = currentPath
	}
	if c.APIKey.Variable == "" {
		c.APIKey.Variable = "TOFUMAN_API_KEY"
	}
	hiddenUnless := func(source string) func() bool {
		return func() bool { return c.APIKey.Source != source }
	}
	form := huh.NewForm(
		huh.NewGroup(
			huh.NewInput().Title("Endpoint").
				Description("The base URL of the Unraid server, as tofu reaches it.").
				Placeholder("http://192.0.2.10").Value(&c.Endpoint).Validate(checkEndpoint),
			huh.NewSelect[string]().Title("API key").
				Description("An API key with only DOCKER:CREATE_ANY, on keyAllowlist of the tofuman policy. Where does each run get it?").
				Options(
					huh.NewOption("1Password CLI (op read)", source1Password),
					huh.NewOption("A command that prints it", sourceCommand),
					huh.NewOption("An environment variable", sourceEnv),
					huh.NewOption("Paste it here (kept in the file in plain text)", sourcePlain),
				).Value(&c.APIKey.Source),
		),
		huh.NewGroup(huh.NewInput().Title("1Password reference").
			Placeholder("op://Private/tofuman/api key").Value(&c.APIKey.Reference).Validate(filled("a reference")),
		).WithHideFunc(hiddenUnless(source1Password)),
		huh.NewGroup(huh.NewInput().Title("Command").
			Description("Runs in "+shellName()+" at each run; what it prints is the key.").
			Value(&c.APIKey.Command).Validate(filled("a command")),
		).WithHideFunc(hiddenUnless(sourceCommand)),
		huh.NewGroup(huh.NewInput().Title("Environment variable").
			Value(&c.APIKey.Variable).Validate(filled("the name of a variable")),
		).WithHideFunc(hiddenUnless(sourceEnv)),
		huh.NewGroup(huh.NewInput().Title("API key").EchoMode(huh.EchoModePassword).
			Value(&c.APIKey.Key).Validate(filled("the key")),
		).WithHideFunc(hiddenUnless(sourcePlain)),
		huh.NewGroup(
			huh.NewInput().Title("Bind root").
				Description("A bind root of the policy. The throwaway container keeps its data in <bind root>tofuman-e2e on the server.").
				Value(&c.BindRoot).Validate(checkBindRoot),
			huh.NewSelect[string]().Title("Save the answers in").
				Options(
					huh.NewOption(user+"  (your user folder)", user),
					huh.NewOption(here+"  (this folder)", here),
				).Value(&where),
		),
	).WithTheme(huh.ThemeFunc(huh.ThemeCharm))
	if err := form.Run(); err != nil {
		if errors.Is(err, huh.ErrUserAborted) {
			return Config{}, "", errors.New("the setup was cancelled; nothing was saved")
		}
		return Config{}, "", err
	}
	c.APIKey = c.APIKey.only()
	return c, where, c.check()
}

func filled(what string) func(string) error {
	return func(value string) error {
		if strings.TrimSpace(value) == "" {
			return errors.New("enter " + what)
		}
		return nil
	}
}
