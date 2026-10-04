// SPDX-License-Identifier: MIT

package provider

import (
	"context"
	"errors"
	"fmt"
	"reflect"
	"strconv"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/tfsdk"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-go/tftypes"

	"github.com/tomzorz/tofuman/sources/provider/internal/client"
)

var (
	_ resource.ResourceWithConfigure      = &containerResource{}
	_ resource.ResourceWithImportState    = &containerResource{}
	_ resource.ResourceWithValidateConfig = &containerResource{}
	_ resource.ResourceWithModifyPlan     = &containerResource{}
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

// ModifyPlan asks the server which checks the planned mutation would fail, so that a refusal
// shows in the plan instead of halfway through an apply (REQ-PRV-15, REQ-PRV-16).
func (r *containerResource) ModifyPlan(ctx context.Context, req resource.ModifyPlanRequest, resp *resource.ModifyPlanResponse) {
	if r.data == nil {
		return // the provider is not configured yet, as during validation
	}
	var state *containerModel
	if !req.State.Raw.IsNull() {
		state = &containerModel{}
		resp.Diagnostics.Append(req.State.Get(ctx, state)...)
	}
	if req.Plan.Raw.IsNull() {
		if state != nil {
			id := state.ID.ValueString()
			r.check(ctx, &id, nil, "delete "+state.Name.ValueString(), &resp.Diagnostics)
		}
		return
	}
	if !knownExceptID(req.Plan.Raw) {
		return // REQ-PRV-16: an unknown value leaves nothing to check yet
	}
	var plan containerModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	definition, diags := plan.definition(ctx)
	resp.Diagnostics.Append(diags...)
	if resp.Diagnostics.HasError() {
		return
	}
	if state == nil {
		r.check(ctx, nil, &definition, "create "+definition.Name, &resp.Diagnostics)
		return
	}
	current, diags := state.definition(ctx)
	resp.Diagnostics.Append(diags...)
	if resp.Diagnostics.HasError() || reflect.DeepEqual(definition, current) {
		return // no mutation is planned, at most a new start_check (REQ-PRV-20)
	}
	id := state.ID.ValueString()
	r.check(ctx, &id, &definition, "update "+state.Name.ValueString(), &resp.Diagnostics)
}

// knownExceptID reports whether every value of a planned resource is known, apart from the
// managed ID, which a create always leaves unknown.
func knownExceptID(raw tftypes.Value) bool {
	known := true
	_ = tftypes.Walk(raw, func(at *tftypes.AttributePath, value tftypes.Value) (bool, error) {
		steps := at.Steps()
		if len(steps) == 1 && steps[0] == tftypes.AttributeName("id") {
			return false, nil
		}
		if !value.IsKnown() {
			known = false
			return false, nil
		}
		return true, nil
	})
	return known
}

// check turns the failed checks of a planned mutation into errors of the plan.
func (r *containerResource) check(ctx context.Context, id *string, definition *client.Definition, action string, diags *diag.Diagnostics) {
	result, err := r.data.client.Check(ctx, id, definition)
	var answer *client.Error
	if errors.As(err, &answer) && answer.Code == "GRAPHQL_VALIDATION_FAILED" {
		diags.AddWarning("The plugin on the server cannot check a plan", "The tofuman plugin on the server predates the checks at plan time, so a refusal shows only during the apply. Update the plugin in the webgui.")
		return
	}
	if err != nil {
		addError(diags, "check the plan to "+action, err)
		return
	}
	if len(result.FailedChecks) > 0 {
		diags.AddError("tofuman would refuse to "+action, "The server refuses this change as it stands. Each failed check:\n- "+strings.Join(result.FailedChecks, "\n- ")+policyHint(result.PolicyGaps))
	}
}

// policyHint is what a person does about a policy gap (REQ-PRV-18).
func policyHint(gaps int) string {
	if gaps == 0 {
		return ""
	}
	return "\n\nA person adds what the policy lacks on the tofuman tab of the Docker page in the webgui, which lists each missing entry."
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
		diags.AddError("tofuman refused to "+action, "Nothing changed on the server. Each failed check:\n- "+strings.Join(checks, "\n- ")+policyHint(answer.PolicyGaps))
		return
	}
	diags.AddError("Could not "+action, err.Error())
}

// finish waits for an operation and puts the container it leaves behind into state.
func (r *containerResource) finish(ctx context.Context, action string, operation *client.Operation, startCheck types.String, state *tfsdk.State, diags *diag.Diagnostics) {
	done, err := r.data.client.Wait(ctx, operation, r.data.timeout)
	if err != nil {
		addError(diags, action, err)
		return
	}
	if done.Container == nil {
		diags.AddError("Could not "+action, fmt.Sprintf("Operation %s succeeded without a container.", done.ID))
		return
	}
	model, d := modelFrom(ctx, done.Container, startCheck)
	diags.Append(d...)
	if diags.HasError() {
		return
	}
	diags.Append(state.Set(ctx, &model)...)
}

// startCheckArgument is the start check that a mutation names. The default goes unnamed, so a
// plugin from before the start check still takes the mutation (REQ-MUT-16 has the same default).
func startCheckArgument(value types.String) *int {
	if knownOr(value, defaultStartCheck).ValueString() == defaultStartCheck {
		return nil
	}
	seconds := startCheckSeconds(value)
	return &seconds
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
	operation, err := r.data.client.Create(ctx, definition, startCheckArgument(plan.StartCheck))
	if err != nil {
		addError(&resp.Diagnostics, "create "+definition.Name, err)
		return
	}
	r.finish(ctx, "create "+definition.Name, operation, plan.StartCheck, &resp.State, &resp.Diagnostics)
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
	model, diags := modelFrom(ctx, container, state.StartCheck)
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
	current, diags := state.definition(ctx)
	resp.Diagnostics.Append(diags...)
	if resp.Diagnostics.HasError() {
		return
	}
	if reflect.DeepEqual(definition, current) {
		state.StartCheck = plan.StartCheck // REQ-PRV-20: the template stays as it is
		resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
		return
	}
	operation, err := r.data.client.Update(ctx, state.ID.ValueString(), definition, startCheckArgument(plan.StartCheck))
	if err != nil {
		addError(&resp.Diagnostics, "update "+state.Name.ValueString(), err)
		return
	}
	r.finish(ctx, "update "+state.Name.ValueString(), operation, plan.StartCheck, &resp.State, &resp.Diagnostics)
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
