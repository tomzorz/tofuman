// SPDX-License-Identifier: GPL-2.0-or-later
//
// A provider with one in-memory resource, driven by terraform-plugin-testing through the tofu
// binary. Each step exercises something the tofuman provider needs: create, import by a name
// that resolves to a different ID, update in place with the same ID, and a read that finds
// nothing and drops the resource from the state.
package spike

import (
	"context"
	"fmt"
	"sync"
	"sync/atomic"
	"testing"

	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/provider"
	"github.com/hashicorp/terraform-plugin-framework/providerserver"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-go/tfprotov6"
	rt "github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
)

var (
	store   sync.Map // id -> name
	counter atomic.Int64
)

type spikeProvider struct{}

func (p *spikeProvider) Metadata(_ context.Context, _ provider.MetadataRequest, resp *provider.MetadataResponse) {
	resp.TypeName = "spike"
}

func (p *spikeProvider) Schema(_ context.Context, _ provider.SchemaRequest, _ *provider.SchemaResponse) {}

func (p *spikeProvider) Configure(_ context.Context, _ provider.ConfigureRequest, _ *provider.ConfigureResponse) {
}

func (p *spikeProvider) Resources(_ context.Context) []func() resource.Resource {
	return []func() resource.Resource{func() resource.Resource { return &thing{} }}
}

func (p *spikeProvider) DataSources(_ context.Context) []func() datasource.DataSource { return nil }

type thing struct{}

type thingModel struct {
	ID   types.String `tfsdk:"id"`
	Name types.String `tfsdk:"name"`
}

func (r *thing) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_thing"
}

func (r *thing) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = schema.Schema{Attributes: map[string]schema.Attribute{
		"id":   schema.StringAttribute{Computed: true, PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
		"name": schema.StringAttribute{Required: true},
	}}
}

func (r *thing) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	var m thingModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &m)...)
	m.ID = types.StringValue(fmt.Sprintf("id-%04d", counter.Add(1)))
	store.Store(m.ID.ValueString(), m.Name.ValueString())
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}

func (r *thing) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	var m thingModel
	resp.Diagnostics.Append(req.State.Get(ctx, &m)...)
	name, ok := store.Load(m.ID.ValueString())
	if !ok {
		resp.State.RemoveResource(ctx)
		return
	}
	m.Name = types.StringValue(name.(string))
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}

func (r *thing) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	var plan, state thingModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	plan.ID = state.ID
	store.Store(plan.ID.ValueString(), plan.Name.ValueString())
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *thing) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	var m thingModel
	resp.Diagnostics.Append(req.State.Get(ctx, &m)...)
	store.Delete(m.ID.ValueString())
}

// ImportState takes a name, like `tofu import` of a tofuman container, and finds its ID.
func (r *thing) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	var found string
	store.Range(func(id, name any) bool {
		if name == req.ID {
			found = id.(string)
			return false
		}
		return true
	})
	if found == "" {
		resp.Diagnostics.AddError("not found", fmt.Sprintf("no thing named %q", req.ID))
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), found)...)
}

func TestThingOnOpenTofu(t *testing.T) {
	var firstID string
	rt.Test(t, rt.TestCase{
		ProtoV6ProviderFactories: map[string]func() (tfprotov6.ProviderServer, error){
			"spike": providerserver.NewProtocol6WithError(&spikeProvider{}),
		},
		Steps: []rt.TestStep{
			{
				Config: `resource "spike_thing" "a" { name = "alpha" }`,
				Check: rt.TestCheckResourceAttrWith("spike_thing.a", "id", func(id string) error {
					firstID = id
					return nil
				}),
			},
			{
				ResourceName:      "spike_thing.a",
				ImportState:       true,
				ImportStateId:     "alpha",
				ImportStateVerify: true,
			},
			{
				Config: `resource "spike_thing" "a" { name = "beta" }`,
				ConfigPlanChecks: rt.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("spike_thing.a", plancheck.ResourceActionUpdate),
				}},
				Check: rt.TestCheckResourceAttrWith("spike_thing.a", "id", func(id string) error {
					if id != firstID {
						return fmt.Errorf("the update replaced the thing: %s became %s", firstID, id)
					}
					return nil
				}),
			},
			{
				PreConfig: func() { store.Delete(firstID) },
				Config:    `resource "spike_thing" "a" { name = "beta" }`,
				ConfigPlanChecks: rt.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("spike_thing.a", plancheck.ResourceActionCreate),
				}},
			},
		},
	})
}
