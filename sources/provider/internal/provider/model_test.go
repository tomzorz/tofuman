// SPDX-License-Identifier: MIT

package provider

import (
	"context"
	"reflect"
	"testing"

	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/tomzorz/tofuman/sources/provider/internal/client"
)

// A definition that the server answers with must come back unchanged from the state that the
// provider makes of it, or every plan after an import would show a change.
func TestModelRoundTrip(t *testing.T) {
	entry := func(kind, name, target, value, mode string, mask bool) client.ConfigEntry {
		return client.ConfigEntry{Type: kind, Name: name, Target: target, Value: value, Default: "d-" + name, Mode: mode,
			Description: "about " + name, Display: "advanced", Required: true, Mask: mask}
	}
	container := &client.Container{
		ID: "8f1c2d3e-0000-4000-8000-000000000000",
		Definition: client.Definition{
			Name: "example", Repository: "busybox:1.36", Network: "br0", IPAddresses: []string{"192.0.2.20"},
			MACAddress: "02:42:c0:00:02:14", Autostart: true, Privileged: false, CPUSet: "0-3", Shell: "bash",
			ExtraParams: []string{"--hostname", "example"}, PostArgs: []string{"sleep", "3600"},
			WebUI: "http://[IP]:[PORT:8080]/", Icon: "https://example.com/icon.png", Overview: "An example.",
			Category: "Tools:", Support: "https://example.com/support", Project: "https://example.com",
			ReadMe: "https://example.com/readme", TemplateURL: "https://example.com/template.xml",
			Registry: "https://example.com/registry", DonateText: "Thanks", DonateLink: "https://example.com/donate",
			Requires: "Nothing.",
			ConfigEntries: []client.ConfigEntry{
				entry("PATH", "Config", "/config", "/mnt/user/appdata/example", "rw,slave", false),
				entry("PORT", "Web", "8080", "18080", "tcp", false),
				entry("VARIABLE", "TZ", "TZ", "Etc/UTC", "", false),
				entry("VARIABLE", "Token", "TOKEN", "hunter2", "", true),
				entry("LABEL", "Role", "com.example.role", "web", "", false),
				entry("DEVICE", "GPU", "", "/dev/dri", "", false),
			},
		},
	}
	model, diags := modelFrom(context.Background(), container, types.StringValue("30s"))
	if diags.HasError() {
		t.Fatalf("modelFrom: %v", diags)
	}
	if model.ID.ValueString() != container.ID {
		t.Errorf("the ID became %s", model.ID)
	}
	if model.StartCheck.ValueString() != "30s" {
		t.Errorf("start_check became %s, not the 30s of the configuration", model.StartCheck)
	}
	definition, diags := model.definition(context.Background())
	if diags.HasError() {
		t.Fatalf("definition: %v", diags)
	}
	if !reflect.DeepEqual(definition, container.Definition) {
		t.Errorf("the definition changed on its way through the state:\n got %+v\nwant %+v", definition, container.Definition)
	}
}

// An absent list comes back as an empty list, never as null: the schema declares every list
// non-null.
func TestEmptyListsStayLists(t *testing.T) {
	model, diags := modelFrom(context.Background(), &client.Container{ID: "x", Definition: client.Definition{Name: "empty"}}, types.StringNull())
	if diags.HasError() {
		t.Fatal(diags)
	}
	if model.StartCheck.ValueString() != defaultStartCheck {
		t.Errorf("after an import, start_check is %s, not the default", model.StartCheck)
	}
	definition, diags := model.definition(context.Background())
	if diags.HasError() {
		t.Fatal(diags)
	}
	if definition.IPAddresses == nil || definition.ExtraParams == nil || definition.PostArgs == nil || definition.ConfigEntries == nil {
		t.Errorf("a nil list would fail the request: %+v", definition)
	}
}

// The default start check goes unnamed, so a plugin from before the start check still takes
// the mutation; any other length goes as whole seconds (REQ-PRV-19).
func TestStartCheckArgument(t *testing.T) {
	for value, want := range map[string]*int{"10s": nil, "0s": pointer(0), "2m": pointer(120), "45s": pointer(45)} {
		got := startCheckArgument(types.StringValue(value))
		if (got == nil) != (want == nil) || (got != nil && *got != *want) {
			t.Errorf("start_check %s became %v", value, got)
		}
	}
	if startCheckArgument(types.StringNull()) != nil {
		t.Error("an unset start_check was named")
	}
}

func TestStartCheckValidator(t *testing.T) {
	for value, valid := range map[string]bool{"0s": true, "10s": true, "10m": true, "1m30s": true, "601s": false, "-1s": false, "1.5s": false, "ten": false} {
		var resp validator.StringResponse
		startCheck{}.ValidateString(context.Background(), validator.StringRequest{ConfigValue: types.StringValue(value)}, &resp)
		if resp.Diagnostics.HasError() == valid {
			t.Errorf("start_check %q: valid is %v, the validator said %v", value, valid, resp.Diagnostics)
		}
	}
}

func pointer(value int) *int {
	return &value
}
