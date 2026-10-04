// SPDX-License-Identifier: MIT

package provider

import (
	"context"
	"fmt"
	"os"
	"regexp"
	"testing"

	"github.com/hashicorp/terraform-plugin-framework/providerserver"
	"github.com/hashicorp/terraform-plugin-go/tfprotov6"
	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/tomzorz/tofuman/sources/provider/internal/client"
)

// The acceptance tests run the tofu binary against the test server of the plugin, which runs the
// real API module service and the real shim (REQ-TST-7, REQ-TST-9). They need TF_ACC,
// TOFUMAN_ENDPOINT, and TOFUMAN_API_KEY; sources/provider/ci/test.sh sets all three up.

var factories = map[string]func() (tfprotov6.ProviderServer, error){
	"tofuman": providerserver.NewProtocol6WithError(New("test")()),
}

// testClient reaches the test server around tofu, the way a person in the webgui would.
func testClient(t *testing.T) *client.Client {
	t.Helper()
	c, err := client.New(client.Config{Endpoint: os.Getenv("TOFUMAN_ENDPOINT"), APIKey: os.Getenv("TOFUMAN_API_KEY")})
	if err != nil {
		t.Fatalf("the acceptance tests need TOFUMAN_ENDPOINT and TOFUMAN_API_KEY: %v", err)
	}
	return c
}

// requiredProviders names the provider the way a real configuration does. Without it, an import
// step that comes first looks for hashicorp/tofuman in the registry.
const requiredProviders = `
terraform {
  required_providers {
    tofuman = {
      source = "tomzorz/tofuman"
    }
  }
}
`

func webConfig(name, timezone string) string {
	return requiredProviders + fmt.Sprintf(`
resource "tofuman_container" "web" {
  name         = %q
  repository   = "busybox:latest"
  network      = "bridge"
  extra_params = ["--hostname", "alpha"]
  post_args    = ["sleep", "3600"]

  path {
    host_path      = "/mnt/tofumantest/appdata/prv-web"
    container_path = "/config"
  }
  port {
    host_port      = "18080"
    container_port = "80"
  }
  variable {
    key   = "TZ"
    value = %q
  }
  secret {
    key   = "TOKEN"
    value = "hunter2"
  }
  label {
    key   = "com.example.role"
    value = "web"
  }
}
`, name, timezone)
}

// changeOutside rewrites one label of the container through the API module, around tofu.
func changeOutside(t *testing.T, id string) {
	ctx := context.Background()
	c := testClient(t)
	container, err := c.Container(ctx, id)
	if err != nil || container == nil {
		t.Fatalf("reading %s: %v", id, err)
	}
	definition := container.Definition
	for i, entry := range definition.ConfigEntries {
		if entry.Type == "LABEL" {
			definition.ConfigEntries[i].Value = "changed-outside"
		}
	}
	operation, err := c.Update(ctx, id, definition, nil)
	if err == nil {
		_, err = c.Wait(ctx, operation, operationTimeout)
	}
	if err != nil {
		t.Fatalf("changing %s around tofu: %v", id, err)
	}
}

// removeOutside deletes the container through the API module, around tofu.
func removeOutside(t *testing.T, id string) {
	ctx := context.Background()
	c := testClient(t)
	operation, err := c.Delete(ctx, id)
	if err == nil {
		_, err = c.Wait(ctx, operation, operationTimeout)
	}
	if err != nil {
		t.Fatalf("removing %s around tofu: %v", id, err)
	}
}

const operationTimeout = defaultOperationTimeout

func TestContainerLifecycle(t *testing.T) {
	var id string
	sameID := func(value string) error {
		if value != id {
			return fmt.Errorf("the managed ID changed from %s to %s", id, value)
		}
		return nil
	}
	expect := func(action plancheck.ResourceActionType) resource.ConfigPlanChecks {
		return resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectResourceAction("tofuman_container.web", action)}}
	}
	resource.Test(t, resource.TestCase{
		ProtoV6ProviderFactories: factories,
		CheckDestroy: func(*terraform.State) error {
			for _, name := range []string{"tofumantest-prv-web", "tofumantest-prv-web2"} {
				container, err := testClient(t).ContainerNamed(context.Background(), name)
				if err != nil {
					return err
				}
				if container != nil {
					return fmt.Errorf("%s is still there after the destroy", name)
				}
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: webConfig("tofumantest-prv-web", "Etc/UTC"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrWith("tofuman_container.web", "id", func(value string) error {
						id = value
						return nil
					}),
					resource.TestCheckResourceAttr("tofuman_container.web", "shell", "sh"),
					resource.TestCheckResourceAttr("tofuman_container.web", "path.0.mode", "rw"),
					resource.TestCheckResourceAttr("tofuman_container.web", "path.0.display_name", "/config"),
					resource.TestCheckResourceAttr("tofuman_container.web", "port.0.protocol", "tcp"),
					resource.TestCheckResourceAttr("tofuman_container.web", "variable.0.display", "always"),
					resource.TestCheckResourceAttr("tofuman_container.web", "secret.0.value", "hunter2"),
				),
			},
			{
				// REQ-PRV-3: import takes the container name
				ResourceName:      "tofuman_container.web",
				ImportState:       true,
				ImportStateId:     "tofumantest-prv-web",
				ImportStateVerify: true,
			},
			{
				// REQ-PRV-8: a rename and a changed variable update in place, with the same managed ID
				Config:           webConfig("tofumantest-prv-web2", "Europe/Budapest"),
				ConfigPlanChecks: expect(plancheck.ResourceActionUpdate),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrWith("tofuman_container.web", "id", sameID),
					resource.TestCheckResourceAttr("tofuman_container.web", "name", "tofumantest-prv-web2"),
					resource.TestCheckResourceAttr("tofuman_container.web", "variable.0.value", "Europe/Budapest"),
				),
			},
			{
				// REQ-GOAL-3: a change made around tofu shows up as drift, and the apply reverts it
				PreConfig:        func() { changeOutside(t, id) },
				Config:           webConfig("tofumantest-prv-web2", "Europe/Budapest"),
				ConfigPlanChecks: expect(plancheck.ResourceActionUpdate),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrWith("tofuman_container.web", "id", sameID),
					resource.TestCheckResourceAttr("tofuman_container.web", "label.0.value", "web"),
				),
			},
			{
				// REQ-PRV-10: a container that is gone leaves the state, and the apply creates it again
				PreConfig:        func() { removeOutside(t, id) },
				Config:           webConfig("tofumantest-prv-web2", "Europe/Budapest"),
				ConfigPlanChecks: expect(plancheck.ResourceActionCreate),
			},
		},
	})
}

// REQ-PRV-15 and REQ-PRV-18: the plan already names each failed check, and says where the
// policy changes.
func TestPlanNamesEachFailedCheck(t *testing.T) {
	resource.Test(t, resource.TestCase{
		ProtoV6ProviderFactories: factories,
		Steps: []resource.TestStep{{
			Config: requiredProviders + `
resource "tofuman_container" "bad" {
  name       = "tofumantest-prv-bad"
  repository = "busybox:latest"
  network    = "host"
  privileged = true
}
`,
			PlanOnly:    true,
			ExpectError: regexp.MustCompile(`(?s)tofuman would refuse to create tofumantest-prv-bad.*privileged\s+needs.*network\s+host\s+needs.*tofuman\s+tab`),
		}},
	})
}

// REQ-MUT-17 to REQ-MUT-19: a container that dies at its start fails the apply with its log
// lines; a failed create leaves nothing, and a failed update puts the previous container back.
func TestStartCheckFailsTheApply(t *testing.T) {
	config := func(command string) string {
		return requiredProviders + fmt.Sprintf(`
resource "tofuman_container" "starter" {
  name        = "tofumantest-prv-starter"
  repository  = "busybox:latest"
  network     = "bridge"
  autostart   = true
  start_check = "3s"
  post_args   = ["sh", "-c", %q]
}
`, command)
	}
	var id string
	resource.Test(t, resource.TestCase{
		ProtoV6ProviderFactories: factories,
		Steps: []resource.TestStep{
			{
				Config:      config("echo tofumantest-dies; exit 3"),
				ExpectError: regexp.MustCompile(`(?s)start\s+check.*exit\s+code\s+3.*tofumantest-dies`), // tofu wraps long lines
			},
			{
				PreConfig: func() {
					if container, err := testClient(t).ContainerNamed(context.Background(), "tofumantest-prv-starter"); err != nil || container != nil {
						t.Fatalf("a failed create left a managed container behind: %v %v", container, err)
					}
				},
				Config: config("sleep 3600"),
				Check: resource.TestCheckResourceAttrWith("tofuman_container.starter", "id", func(value string) error {
					id = value
					return nil
				}),
			},
			{
				Config:      config("echo tofumantest-dies-later; exit 4"),
				ExpectError: regexp.MustCompile(`(?s)start\s+check.*exit\s+code\s+4.*tofumantest-dies-later.*is\s+back`),
			},
			{
				PreConfig: func() {
					container, err := testClient(t).Container(context.Background(), id)
					if err != nil || container == nil || !container.Running || container.Definition.PostArgs[2] != "sleep 3600" {
						t.Fatalf("the previous container is not back and running: %+v %v", container, err)
					}
				},
				Config:   config("sleep 3600"),
				PlanOnly: true,
			},
		},
	})
}

// REQ-PRV-20: start_check is not part of the template, so a change of it alone is no mutation.
func TestStartCheckAloneChangesNothingOnTheServer(t *testing.T) {
	config := func(startCheck string) string {
		return requiredProviders + fmt.Sprintf(`
resource "tofuman_container" "quiet" {
  name        = "tofumantest-prv-quiet"
  repository  = "busybox:latest"
  network     = "bridge"
  post_args   = ["sleep", "3600"]
  start_check = %q
}
`, startCheck)
	}
	var id string
	var before *string
	resource.Test(t, resource.TestCase{
		ProtoV6ProviderFactories: factories,
		Steps: []resource.TestStep{
			{
				Config: config("10s"),
				Check: resource.TestCheckResourceAttrWith("tofuman_container.quiet", "id", func(value string) error {
					id = value
					container, err := testClient(t).Container(context.Background(), id)
					if err != nil || container == nil {
						return fmt.Errorf("reading %s: %v", id, err)
					}
					before = container.LastMutationAt
					return nil
				}),
			},
			{
				Config: config("45s"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("tofuman_container.quiet", "start_check", "45s"),
					func(*terraform.State) error {
						container, err := testClient(t).Container(context.Background(), id)
						if err != nil || container == nil || before == nil || container.LastMutationAt == nil || *container.LastMutationAt != *before {
							return fmt.Errorf("a change of start_check alone reached the server: %+v %v", container, err)
						}
						return nil
					},
				),
			},
		},
	})
}

func TestImportOfAnUnmanagedName(t *testing.T) {
	resource.Test(t, resource.TestCase{
		ProtoV6ProviderFactories: factories,
		Steps: []resource.TestStep{{
			Config: requiredProviders + `
resource "tofuman_container" "nobody" {
  name       = "tofumantest-prv-nobody"
  repository = "busybox:latest"
  network    = "bridge"
}
`,
			ResourceName:  "tofuman_container.nobody",
			ImportState:   true,
			ImportStateId: "tofumantest-prv-nobody",
			ExpectError:   regexp.MustCompile(`(?s)No managed container named tofumantest-prv-nobody.*Adopt`),
		}},
	})
}

// The values that the shim would rewrite instead of refusing fail at plan time, so the state
// never differs from the plan.
func TestValuesTheShimWouldRewrite(t *testing.T) {
	config := func(extra string) string {
		return requiredProviders + fmt.Sprintf(`
resource "tofuman_container" "x" {
  name       = "tofumantest-prv-rewrite"
  repository = "busybox:latest"
  network    = "bridge"
%s
}
`, extra)
	}
	resource.Test(t, resource.TestCase{
		ProtoV6ProviderFactories: factories,
		Steps: []resource.TestStep{
			{
				Config:      config("variable {\n    key     = \"A\"\n    value   = \"\"\n    default = \"b\"\n  }"),
				ExpectError: regexp.MustCompile(`Empty value with a default`),
			},
			{
				Config:      config(`shell = ""`),
				ExpectError: regexp.MustCompile(`must be one of sh, bash`),
			},
			{
				Config:      config(`mac_address = "02:42:C0:00:02:0A"`),
				ExpectError: regexp.MustCompile(`Invalid MAC address`),
			},
		},
	})
}
