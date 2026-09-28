// SPDX-License-Identifier: MIT

package provider

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/tfsdk"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/tomzorz/tofuman/sources/provider/internal/client"
)

var (
	_ resource.ResourceWithConfigure      = &containerResource{}
	_ resource.ResourceWithImportState    = &containerResource{}
	_ resource.ResourceWithValidateConfig = &containerResource{}
)

type containerResource struct {
	data *providerData
}

func newContainerResource() resource.Resource {
	return &containerResource{}
}

func (r *containerResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_container"
}

func (r *containerResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = containerSchema()
}

func (r *containerResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	if req.ProviderData == nil {
		return // validation runs before the provider is configured
	}
	data, ok := req.ProviderData.(*providerData)
	if !ok {
		resp.Diagnostics.AddError("Unexpected provider data", fmt.Sprintf("Expected *providerData, got %T.", req.ProviderData))
		return
	}
	r.data = data
}

// ValidateConfig refuses an entry with an empty value and a default. DockerMan reads an empty
// value as the default (REQ-DEF-4), so the entry would come back changed after every apply.
func (r *containerResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	for _, block := range []struct{ name, value string }{
		{"path", "host_path"}, {"port", "host_port"}, {"variable", "value"}, {"secret", "value"}, {"label", "value"}, {"device", "host_path"},
	} {
		var list types.List
		resp.Diagnostics.Append(req.Config.GetAttribute(ctx, path.Root(block.name), &list)...)
		if list.IsNull() || list.IsUnknown() {
			continue
		}
		for i, element := range list.Elements() {
			object, ok := element.(types.Object)
			if !ok || object.IsNull() || object.IsUnknown() {
				continue
			}
			value, valueOK := object.Attributes()[block.value].(types.String)
			def, defaultOK := object.Attributes()["default"].(types.String)
			if !valueOK || !defaultOK || value.IsUnknown() || def.IsUnknown() || def.IsNull() {
				continue
			}
			if value.ValueString() == "" && def.ValueString() != "" {
				resp.Diagnostics.AddAttributeError(path.Root(block.name).AtListIndex(i).AtName(block.value), "Empty value with a default",
					fmt.Sprintf("DockerMan reads an empty value as the default, so this entry would come back as %s. Set the value, or leave default empty.", strconv.Quote(def.ValueString())))
			}
		}
	}
}

func (r *containerResource) configured(diags *diag.Diagnostics) bool {
	if r.data == nil {
		diags.AddError("Unconfigured provider", "The tofuman provider has no configuration. Set endpoint and api_key in the provider block, or TOFUMAN_ENDPOINT and TOFUMAN_API_KEY.")
		return false
	}
	return true
}

// addError turns an error of the client into a diagnostic. A refusal lists each failed check
// (REQ-MUT-3) and says that nothing changed.
func addError(diags *diag.Diagnostics, action string, err error) {
	var answer *client.Error
	if errors.As(err, &answer) && answer.Refused() {
		checks := answer.Checks
		if len(checks) == 0 {
			checks = []string{answer.Message}
		}
		diags.AddError("tofuman refused to "+action, "Nothing changed on the server. Each failed check:\n- "+strings.Join(checks, "\n- "))
		return
	}
	diags.AddError("Could not "+action, err.Error())
}

// finish waits for an operation and puts the container it leaves behind into state.
func (r *containerResource) finish(ctx context.Context, action string, operation *client.Operation, state *tfsdk.State, diags *diag.Diagnostics) {
	done, err := r.data.client.Wait(ctx, operation, r.data.timeout)
	if err != nil {
		addError(diags, action, err)
		return
	}
	if done.Container == nil {
		diags.AddError("Could not "+action, fmt.Sprintf("Operation %s succeeded without a container.", done.ID))
		return
	}
	model, d := modelFrom(ctx, done.Container)
	diags.Append(d...)
	if diags.HasError() {
		return
	}
	diags.Append(state.Set(ctx, &model)...)
}

func (r *containerResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if !r.configured(&resp.Diagnostics) {
		return
	}
	var plan containerModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	definition, diags := plan.definition(ctx)
	resp.Diagnostics.Append(diags...)
	if resp.Diagnostics.HasError() {
		return
	}
	operation, err := r.data.client.Create(ctx, definition)
	if err != nil {
		addError(&resp.Diagnostics, "create "+definition.Name, err)
		return
	}
	r.finish(ctx, "create "+definition.Name, operation, &resp.State, &resp.Diagnostics)
}

func (r *containerResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if !r.configured(&resp.Diagnostics) {
		return
	}
	var state containerModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	container, err := r.data.client.Container(ctx, state.ID.ValueString())
	if err != nil {
		addError(&resp.Diagnostics, "read "+state.Name.ValueString(), err)
		return
	}
	if container == nil {
		resp.State.RemoveResource(ctx) // REQ-PRV-10
		return
	}
	model, diags := modelFrom(ctx, container)
	resp.Diagnostics.Append(diags...)
	if resp.Diagnostics.HasError() {
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &model)...)
}

func (r *containerResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if !r.configured(&resp.Diagnostics) {
		return
	}
	var plan, state containerModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	definition, diags := plan.definition(ctx)
	resp.Diagnostics.Append(diags...)
	if resp.Diagnostics.HasError() {
		return
	}
	operation, err := r.data.client.Update(ctx, state.ID.ValueString(), definition)
	if err != nil {
		addError(&resp.Diagnostics, "update "+state.Name.ValueString(), err)
		return
	}
	r.finish(ctx, "update "+state.Name.ValueString(), operation, &resp.State, &resp.Diagnostics)
}

func (r *containerResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if !r.configured(&resp.Diagnostics) {
		return
	}
	var state containerModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	operation, err := r.data.client.Delete(ctx, state.ID.ValueString())
	if err != nil {
		addError(&resp.Diagnostics, "delete "+state.Name.ValueString(), err)
		return
	}
	if _, err := r.data.client.Wait(ctx, operation, r.data.timeout); err != nil {
		addError(&resp.Diagnostics, "delete "+state.Name.ValueString(), err)
	}
}

// ImportState takes a container name (REQ-PRV-3) and finds its managed ID.
func (r *containerResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if !r.configured(&resp.Diagnostics) {
		return
	}
	container, err := r.data.client.ContainerNamed(ctx, req.ID)
	if err != nil {
		addError(&resp.Diagnostics, "import "+req.ID, err)
		return
	}
	if container == nil {
		resp.Diagnostics.AddError("No managed container named "+req.ID, fmt.Sprintf(
			"tofuman manages no container named %q. If it is a hand-made container, select Adopt for it on the tofuman tab of the Docker page in the webgui, then import it again.", req.ID))
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), container.ID)...)
}
