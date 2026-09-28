// SPDX-License-Identifier: MIT

package provider

import (
	"context"
	"fmt"
	"maps"
	"regexp"
	"slices"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/listdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
)

// containerSchema is section 14.1 of the spec.
func containerSchema() schema.Schema {
	return schema.Schema{
		Description: "A container on the server that tofuman manages. It stays an ordinary DockerMan container: the webgui can edit and update it, and a change made there shows up as drift.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{
				Computed:      true,
				Description:   "The managed ID, which stays the same through every update, a rename included.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()},
			},
			"name":         schema.StringAttribute{Required: true, Description: "The container name, the template element Name."},
			"repository":   schema.StringAttribute{Required: true, Description: "The image, with a tag or an @sha256: digest: the template element Repository. tofuman never updates the image."},
			"network":      schema.StringAttribute{Required: true, Description: "A network that exists on the server: the template element Network."},
			"ip_addresses": optionalList("Fixed addresses on a custom network: the template element MyIP."),
			"mac_address": optionalString("A fixed MAC address, as six lowercase hexadecimal pairs joined by colons: the template element MyMAC.", "",
				macAddress{}),
			"autostart":    optionalBool("Whether the server starts the container at boot. A new container starts if and only if this is true.", false),
			"privileged":   optionalBool("The template element Privileged. The policy on the server refuses it unless the container has an exception.", false),
			"cpuset":       optionalString("CPU pinning such as 0-3,8: the template element CPUset.", ""),
			"shell":        optionalString("The shell of the webgui console: sh or bash.", "sh", oneOf{"sh", "bash"}),
			"extra_params": optionalList("Extra arguments of docker create, one argument per element: the template element ExtraParams. The policy on the server lists the flags it allows."),
			"post_args":    optionalList("The command and its arguments, one per element: the template element PostArgs."),
			"web_ui":       optionalString("The template element WebUI. It takes the placeholders [IP] and [PORT:<number>].", ""),
			"icon":         optionalString("The template element Icon, an http or https URL.", ""),
			"overview":     optionalString("The template element Overview.", ""),
			"category":     optionalString("The template element Category.", ""),
			"support":      optionalString("The template element Support, an http or https URL.", ""),
			"project":      optionalString("The template element Project, an http or https URL.", ""),
			"read_me":      optionalString("The template element ReadMe, an http or https URL.", ""),
			"template_url": optionalString("The template element TemplateURL, an http or https URL.", ""),
			"registry":     optionalString("The template element Registry, an http or https URL.", ""),
			"donate_text":  optionalString("The template element DonateText.", ""),
			"donate_link":  optionalString("The template element DonateLink, an http or https URL.", ""),
			"requires":     optionalString("The template element Requires.", ""),
		},
		Blocks: map[string]schema.Block{
			"path": entryBlock("A bind mount, a config entry of type Path. host_path must lie under a bind root of the policy on the server, on a filesystem other than the root filesystem.", "container_path", map[string]schema.Attribute{
				"host_path":      schema.StringAttribute{Required: true, Description: "The path on the server."},
				"container_path": schema.StringAttribute{Required: true, Description: "The path in the container."},
				"mode":           optionalString("rw, ro, rw,slave, rw,shared, ro,slave, or ro,shared.", "rw"),
			}),
			"port": entryBlock("A port, a config entry of type Port. On macvlan, ipvlan, and host networks DockerMan exports it as the variable TCP_PORT_<container_port> or UDP_PORT_<container_port> instead.", "container_port", map[string]schema.Attribute{
				"host_port":      schema.StringAttribute{Required: true, Description: "The port on the server."},
				"container_port": schema.StringAttribute{Required: true, Description: "The port in the container."},
				"protocol":       optionalString("tcp or udp.", "tcp"),
			}),
			"variable": entryBlock("An environment variable, a config entry of type Variable.", "key", map[string]schema.Attribute{
				"key":   schema.StringAttribute{Required: true, Description: "The name of the variable."},
				"value": schema.StringAttribute{Required: true, Description: "The value of the variable."},
			}),
			"secret": entryBlock("An environment variable whose value the webgui masks, a config entry of type Variable with Mask set. The template on the server holds the value in plain text.", "key", map[string]schema.Attribute{
				"key":   schema.StringAttribute{Required: true, Description: "The name of the variable."},
				"value": schema.StringAttribute{Required: true, Sensitive: true, Description: "The value of the variable."},
			}),
			"label": entryBlock("A Docker label, a config entry of type Label. Keys that start with net.unraid.docker. or tofuman. are reserved.", "key", map[string]schema.Attribute{
				"key":   schema.StringAttribute{Required: true, Description: "The label key."},
				"value": schema.StringAttribute{Required: true, Description: "The label value."},
			}),
			"device": entryBlock("A device, a config entry of type Device. The policy on the server refuses it unless the container has an exception for the device.", "host_path", map[string]schema.Attribute{
				"host_path": schema.StringAttribute{Required: true, Description: "The device path on the server, under /dev/."},
			}),
		},
	}
}

func optionalString(description, value string, validators ...validator.String) schema.StringAttribute {
	return schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString(value), Description: description, Validators: validators}
}

func optionalBool(description string, value bool) schema.BoolAttribute {
	return schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(value), Description: description}
}

func optionalList(description string) schema.ListAttribute {
	return schema.ListAttribute{
		ElementType: types.StringType,
		Optional:    true,
		Computed:    true,
		Default:     listdefault.StaticValue(types.ListValueMust(types.StringType, []attr.Value{})),
		Description: description,
	}
}

// entryBlock adds the attributes that every config entry has to the attributes of one block.
func entryBlock(description, nameFrom string, attributes map[string]schema.Attribute) schema.ListNestedBlock {
	all := map[string]schema.Attribute{
		"display_name": schema.StringAttribute{
			Optional:      true,
			Computed:      true,
			Description:   "The name that the webgui shows for the entry. Defaults to " + nameFrom + ".",
			PlanModifiers: []planmodifier.String{defaultFrom(nameFrom)},
		},
		"description": optionalString("The description that the webgui shows for the entry.", ""),
		"display":     optionalString("Where the webgui shows the entry: always, always-hide, advanced, or advanced-hide.", "always"),
		"required":    optionalBool("Whether the webgui marks the entry as required.", false),
		"default":     optionalString("The default of the entry. DockerMan reads an empty value as the default.", ""),
	}
	maps.Copy(all, attributes)
	return schema.ListNestedBlock{Description: description, NestedObject: schema.NestedBlockObject{Attributes: all}}
}

// defaultFrom plans an unset attribute with the value of a sibling attribute in the same block.
type defaultFrom string

func (m defaultFrom) Description(context.Context) string {
	return "Defaults to " + string(m) + "."
}

func (m defaultFrom) MarkdownDescription(ctx context.Context) string {
	return m.Description(ctx)
}

func (m defaultFrom) PlanModifyString(ctx context.Context, req planmodifier.StringRequest, resp *planmodifier.StringResponse) {
	if !req.ConfigValue.IsNull() {
		return
	}
	var sibling types.String
	resp.Diagnostics.Append(req.Plan.GetAttribute(ctx, req.Path.ParentPath().AtName(string(m)), &sibling)...)
	resp.PlanValue = sibling
}

// oneOf refuses a value outside the list. It guards the values that the shim would rewrite
// instead of refusing, so that the state never differs from the plan.
type oneOf []string

func (v oneOf) Description(context.Context) string {
	return "one of " + strings.Join(v, ", ")
}

func (v oneOf) MarkdownDescription(ctx context.Context) string {
	return v.Description(ctx)
}

func (v oneOf) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if req.ConfigValue.IsNull() || req.ConfigValue.IsUnknown() || slices.Contains(v, req.ConfigValue.ValueString()) {
		return
	}
	resp.Diagnostics.AddAttributeError(req.Path, "Invalid value", fmt.Sprintf("The value must be one of %s, got %q.", strings.Join(v, ", "), req.ConfigValue.ValueString()))
}

var macPattern = regexp.MustCompile(`^([0-9a-f]{2}:){5}[0-9a-f]{2}$`)

// macAddress accepts only the form that DockerMan stores; the shim would rewrite any other form.
type macAddress struct{}

func (macAddress) Description(context.Context) string {
	return "six lowercase hexadecimal pairs joined by colons"
}

func (v macAddress) MarkdownDescription(ctx context.Context) string {
	return v.Description(ctx)
}

func (macAddress) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if req.ConfigValue.IsNull() || req.ConfigValue.IsUnknown() {
		return
	}
	if value := req.ConfigValue.ValueString(); value != "" && !macPattern.MatchString(value) {
		resp.Diagnostics.AddAttributeError(req.Path, "Invalid MAC address", fmt.Sprintf("Write the MAC address as six lowercase hexadecimal pairs joined by colons, such as 02:42:c0:00:02:0a, got %q. DockerMan stores that form, so any other form would come back changed.", value))
	}
}
