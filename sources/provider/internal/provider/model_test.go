// SPDX-License-Identifier: MIT

package provider

import (
	"context"
	"reflect"
	"testing"

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
	model, diags := modelFrom(context.Background(), container)
	if diags.HasError() {
		t.Fatalf("modelFrom: %v", diags)
	}
	if model.ID.ValueString() != container.ID {
		t.Errorf("the ID became %s", model.ID)
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
	model, diags := modelFrom(context.Background(), &client.Container{ID: "x", Definition: client.Definition{Name: "empty"}})
	if diags.HasError() {
		t.Fatal(diags)
	}
	definition, diags := model.definition(context.Background())
	if diags.HasError() {
		t.Fatal(diags)
	}
	if definition.IPAddresses == nil || definition.ExtraParams == nil || definition.PostArgs == nil || definition.ConfigEntries == nil {
		t.Errorf("a nil list would fail the request: %+v", definition)
	}
}
