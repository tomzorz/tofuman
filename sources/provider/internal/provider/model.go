// SPDX-License-Identifier: MIT

package provider

import (
	"context"
	"fmt"
	"time"

	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/tomzorz/tofuman/sources/provider/internal/client"
)

type containerModel struct {
	ID          types.String    `tfsdk:"id"`
	Name        types.String    `tfsdk:"name"`
	Repository  types.String    `tfsdk:"repository"`
	Network     types.String    `tfsdk:"network"`
	IPAddresses types.List      `tfsdk:"ip_addresses"`
	MACAddress  types.String    `tfsdk:"mac_address"`
	Autostart   types.Bool      `tfsdk:"autostart"`
	Privileged  types.Bool      `tfsdk:"privileged"`
	CPUSet      types.String    `tfsdk:"cpuset"`
	Shell       types.String    `tfsdk:"shell"`
	ExtraParams types.List      `tfsdk:"extra_params"`
	PostArgs    types.List      `tfsdk:"post_args"`
	WebUI       types.String    `tfsdk:"web_ui"`
	Icon        types.String    `tfsdk:"icon"`
	Overview    types.String    `tfsdk:"overview"`
	Category    types.String    `tfsdk:"category"`
	Support     types.String    `tfsdk:"support"`
	Project     types.String    `tfsdk:"project"`
	ReadMe      types.String    `tfsdk:"read_me"`
	TemplateURL types.String    `tfsdk:"template_url"`
	Registry    types.String    `tfsdk:"registry"`
	DonateText  types.String    `tfsdk:"donate_text"`
	DonateLink  types.String    `tfsdk:"donate_link"`
	Requires    types.String    `tfsdk:"requires"`
	StartCheck  types.String    `tfsdk:"start_check"`
	Paths       []pathModel     `tfsdk:"path"`
	Ports       []portModel     `tfsdk:"port"`
	Variables   []keyValueModel `tfsdk:"variable"`
	Secrets     []keyValueModel `tfsdk:"secret"`
	Labels      []keyValueModel `tfsdk:"label"`
	Devices     []deviceModel   `tfsdk:"device"`
}

type pathModel struct {
	HostPath      types.String `tfsdk:"host_path"`
	ContainerPath types.String `tfsdk:"container_path"`
	Mode          types.String `tfsdk:"mode"`
	DisplayName   types.String `tfsdk:"display_name"`
	Description   types.String `tfsdk:"description"`
	Display       types.String `tfsdk:"display"`
	Required      types.Bool   `tfsdk:"required"`
	Default       types.String `tfsdk:"default"`
}

type portModel struct {
	HostPort      types.String `tfsdk:"host_port"`
	ContainerPort types.String `tfsdk:"container_port"`
	Protocol      types.String `tfsdk:"protocol"`
	DisplayName   types.String `tfsdk:"display_name"`
	Description   types.String `tfsdk:"description"`
	Display       types.String `tfsdk:"display"`
	Required      types.Bool   `tfsdk:"required"`
	Default       types.String `tfsdk:"default"`
}

// keyValueModel is a variable, a secret, or a label.
type keyValueModel struct {
	Key         types.String `tfsdk:"key"`
	Value       types.String `tfsdk:"value"`
	DisplayName types.String `tfsdk:"display_name"`
	Description types.String `tfsdk:"description"`
	Display     types.String `tfsdk:"display"`
	Required    types.Bool   `tfsdk:"required"`
	Default     types.String `tfsdk:"default"`
}

type deviceModel struct {
	HostPath    types.String `tfsdk:"host_path"`
	DisplayName types.String `tfsdk:"display_name"`
	Description types.String `tfsdk:"description"`
	Display     types.String `tfsdk:"display"`
	Required    types.Bool   `tfsdk:"required"`
	Default     types.String `tfsdk:"default"`
}

// entryAttributes are the attributes that every block shares, in the order of the model.
type entryAttributes struct {
	DisplayName, Description, Display, Default types.String
	Required                                   types.Bool
}

// entry builds a config entry. An unset display name falls back to fallback, the value that
// the display name defaults to.
func entry(kind, target, value, mode string, mask bool, fallback string, a entryAttributes) client.ConfigEntry {
	name := a.DisplayName.ValueString()
	if a.DisplayName.IsNull() || a.DisplayName.IsUnknown() {
		name = fallback
	}
	return client.ConfigEntry{
		Type:        kind,
		Name:        name,
		Target:      target,
		Value:       value,
		Default:     a.Default.ValueString(),
		Mode:        mode,
		Description: a.Description.ValueString(),
		Display:     a.Display.ValueString(),
		Required:    a.Required.ValueBool(),
		Mask:        mask,
	}
}

// definition is the definition that the plan asks for. The config entries go in the order
// that the shim keeps them in (REQ-DEF-10): paths, ports, variables, labels, devices, and the
// secrets after the other variables.
func (m containerModel) definition(ctx context.Context) (client.Definition, diag.Diagnostics) {
	var diags diag.Diagnostics
	d := client.Definition{
		Name:          m.Name.ValueString(),
		Repository:    m.Repository.ValueString(),
		Network:       m.Network.ValueString(),
		IPAddresses:   stringList(ctx, m.IPAddresses, &diags),
		MACAddress:    m.MACAddress.ValueString(),
		Autostart:     m.Autostart.ValueBool(),
		Privileged:    m.Privileged.ValueBool(),
		CPUSet:        m.CPUSet.ValueString(),
		Shell:         m.Shell.ValueString(),
		ExtraParams:   stringList(ctx, m.ExtraParams, &diags),
		PostArgs:      stringList(ctx, m.PostArgs, &diags),
		WebUI:         m.WebUI.ValueString(),
		Icon:          m.Icon.ValueString(),
		Overview:      m.Overview.ValueString(),
		Category:      m.Category.ValueString(),
		Support:       m.Support.ValueString(),
		Project:       m.Project.ValueString(),
		ReadMe:        m.ReadMe.ValueString(),
		TemplateURL:   m.TemplateURL.ValueString(),
		Registry:      m.Registry.ValueString(),
		DonateText:    m.DonateText.ValueString(),
		DonateLink:    m.DonateLink.ValueString(),
		Requires:      m.Requires.ValueString(),
		ConfigEntries: []client.ConfigEntry{},
	}
	for _, p := range m.Paths {
		d.ConfigEntries = append(d.ConfigEntries, entry("PATH", p.ContainerPath.ValueString(), p.HostPath.ValueString(), p.Mode.ValueString(), false,
			p.ContainerPath.ValueString(), entryAttributes{p.DisplayName, p.Description, p.Display, p.Default, p.Required}))
	}
	for _, p := range m.Ports {
		d.ConfigEntries = append(d.ConfigEntries, entry("PORT", p.ContainerPort.ValueString(), p.HostPort.ValueString(), p.Protocol.ValueString(), false,
			p.ContainerPort.ValueString(), entryAttributes{p.DisplayName, p.Description, p.Display, p.Default, p.Required}))
	}
	for _, group := range []struct {
		kind    string
		mask    bool
		entries []keyValueModel
	}{{"VARIABLE", false, m.Variables}, {"VARIABLE", true, m.Secrets}, {"LABEL", false, m.Labels}} {
		for _, v := range group.entries {
			d.ConfigEntries = append(d.ConfigEntries, entry(group.kind, v.Key.ValueString(), v.Value.ValueString(), "", group.mask,
				v.Key.ValueString(), entryAttributes{v.DisplayName, v.Description, v.Display, v.Default, v.Required}))
		}
	}
	for _, v := range m.Devices {
		d.ConfigEntries = append(d.ConfigEntries, entry("DEVICE", "", v.HostPath.ValueString(), "", false,
			v.HostPath.ValueString(), entryAttributes{v.DisplayName, v.Description, v.Display, v.Default, v.Required}))
	}
	return d, diags
}

// modelFrom is the state of a container as the server has it, with the start check that the
// configuration asked for: the server does not keep that one (REQ-PRV-20). Every list is empty
// rather than null, the way OpenTofu plans an absent block.
func modelFrom(ctx context.Context, c *client.Container, startCheck types.String) (containerModel, diag.Diagnostics) {
	var diags diag.Diagnostics
	d := c.Definition
	m := containerModel{
		ID:          types.StringValue(c.ID),
		Name:        types.StringValue(d.Name),
		Repository:  types.StringValue(d.Repository),
		Network:     types.StringValue(d.Network),
		IPAddresses: listValue(ctx, d.IPAddresses, &diags),
		MACAddress:  types.StringValue(d.MACAddress),
		Autostart:   types.BoolValue(d.Autostart),
		Privileged:  types.BoolValue(d.Privileged),
		CPUSet:      types.StringValue(d.CPUSet),
		Shell:       types.StringValue(d.Shell),
		ExtraParams: listValue(ctx, d.ExtraParams, &diags),
		PostArgs:    listValue(ctx, d.PostArgs, &diags),
		WebUI:       types.StringValue(d.WebUI),
		Icon:        types.StringValue(d.Icon),
		Overview:    types.StringValue(d.Overview),
		Category:    types.StringValue(d.Category),
		Support:     types.StringValue(d.Support),
		Project:     types.StringValue(d.Project),
		ReadMe:      types.StringValue(d.ReadMe),
		TemplateURL: types.StringValue(d.TemplateURL),
		Registry:    types.StringValue(d.Registry),
		DonateText:  types.StringValue(d.DonateText),
		DonateLink:  types.StringValue(d.DonateLink),
		Requires:    types.StringValue(d.Requires),
		StartCheck:  knownOr(startCheck, defaultStartCheck),
		Paths:       []pathModel{},
		Ports:       []portModel{},
		Variables:   []keyValueModel{},
		Secrets:     []keyValueModel{},
		Labels:      []keyValueModel{},
		Devices:     []deviceModel{},
	}
	for _, e := range d.ConfigEntries {
		name, description, display, def := types.StringValue(e.Name), types.StringValue(e.Description), types.StringValue(e.Display), types.StringValue(e.Default)
		required := types.BoolValue(e.Required)
		switch e.Type {
		case "PATH":
			m.Paths = append(m.Paths, pathModel{types.StringValue(e.Value), types.StringValue(e.Target), types.StringValue(e.Mode), name, description, display, required, def})
		case "PORT":
			m.Ports = append(m.Ports, portModel{types.StringValue(e.Value), types.StringValue(e.Target), types.StringValue(e.Mode), name, description, display, required, def})
		case "VARIABLE":
			v := keyValueModel{types.StringValue(e.Target), types.StringValue(e.Value), name, description, display, required, def}
			if e.Mask {
				m.Secrets = append(m.Secrets, v)
			} else {
				m.Variables = append(m.Variables, v)
			}
		case "LABEL":
			m.Labels = append(m.Labels, keyValueModel{types.StringValue(e.Target), types.StringValue(e.Value), name, description, display, required, def})
		case "DEVICE":
			m.Devices = append(m.Devices, deviceModel{types.StringValue(e.Value), name, description, display, required, def})
		default:
			diags.AddError("Unknown config entry type", fmt.Sprintf("The container %s has a config entry of type %q, which this provider does not know.", d.Name, e.Type))
		}
	}
	return m, diags
}

// defaultStartCheck is the default of start_check, and the length that the server uses when a
// mutation names none (REQ-MUT-16).
const defaultStartCheck = "10s"

// knownOr is the value, or the fallback while the value is null or unknown, as after an import.
func knownOr(value types.String, fallback string) types.String {
	if value.IsNull() || value.IsUnknown() {
		return types.StringValue(fallback)
	}
	return value
}

// startCheckSeconds is start_check in whole seconds. The validator has already refused anything
// else, so a value that does not parse falls back to the default.
func startCheckSeconds(value types.String) int {
	duration, err := time.ParseDuration(knownOr(value, defaultStartCheck).ValueString())
	if err != nil {
		duration, _ = time.ParseDuration(defaultStartCheck)
	}
	return int(duration / time.Second)
}

func stringList(ctx context.Context, list types.List, diags *diag.Diagnostics) []string {
	values := []string{}
	if list.IsNull() || list.IsUnknown() {
		return values
	}
	diags.Append(list.ElementsAs(ctx, &values, false)...)
	return values
}

func listValue(ctx context.Context, values []string, diags *diag.Diagnostics) types.List {
	if values == nil {
		values = []string{}
	}
	list, d := types.ListValueFrom(ctx, types.StringType, values)
	diags.Append(d...)
	return list
}
