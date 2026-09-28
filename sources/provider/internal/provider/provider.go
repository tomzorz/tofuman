// SPDX-License-Identifier: MIT

// Package provider is the OpenTofu provider of tofuman (spec section 14).
package provider

import (
	"context"
	"fmt"
	"os"
	"strconv"
	"time"

	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/provider"
	"github.com/hashicorp/terraform-plugin-framework/provider/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/tomzorz/tofuman/sources/provider/internal/client"
)

const defaultOperationTimeout = 30 * time.Minute

type tofumanProvider struct {
	version string
}

// New returns the constructor of the provider that main serves.
func New(version string) func() provider.Provider {
	return func() provider.Provider {
		return &tofumanProvider{version: version}
	}
}

// providerData is what Configure hands to the resources.
type providerData struct {
	client  *client.Client
	timeout time.Duration
}

type providerModel struct {
	Endpoint         types.String `tfsdk:"endpoint"`
	APIKey           types.String `tfsdk:"api_key"`
	CACertificate    types.String `tfsdk:"ca_certificate"`
	Insecure         types.Bool   `tfsdk:"insecure"`
	OperationTimeout types.String `tfsdk:"operation_timeout"`
}

func (p *tofumanProvider) Metadata(_ context.Context, _ provider.MetadataRequest, resp *provider.MetadataResponse) {
	resp.TypeName = "tofuman"
	resp.Version = p.version
}

func (p *tofumanProvider) Schema(_ context.Context, _ provider.SchemaRequest, resp *provider.SchemaResponse) {
	resp.Schema = schema.Schema{
		Description: "Declares containers on an Unraid server through the tofuman plugin. The containers stay ordinary DockerMan containers.",
		Attributes: map[string]schema.Attribute{
			"endpoint": schema.StringAttribute{
				Optional:    true,
				Description: "The base URL of the server, for example https://192.0.2.10. The requests go to /graphql under it. Environment variable: TOFUMAN_ENDPOINT.",
			},
			"api_key": schema.StringAttribute{
				Optional:    true,
				Sensitive:   true,
				Description: "An API key with only DOCKER:CREATE_ANY, on the key allowlist of the plugin. Environment variable: TOFUMAN_API_KEY.",
			},
			"ca_certificate": schema.StringAttribute{
				Optional:    true,
				Description: "PEM text of a certificate to trust next to the system roots. Environment variable: TOFUMAN_CA_CERTIFICATE.",
			},
			"insecure": schema.BoolAttribute{
				Optional:    true,
				Description: "Skip the check of the server's TLS certificate. Environment variable: TOFUMAN_INSECURE.",
			},
			"operation_timeout": schema.StringAttribute{
				Optional:    true,
				Description: "How long to wait for an operation to end, as a duration such as 90s, 30m, or 1h. Default 30m. Environment variable: TOFUMAN_OPERATION_TIMEOUT.",
			},
		},
	}
}

// setting takes the value from the configuration, else from the environment variable.
func setting(value types.String, variable string) string {
	if !value.IsNull() {
		return value.ValueString()
	}
	return os.Getenv(variable)
}

func (p *tofumanProvider) Configure(ctx context.Context, req provider.ConfigureRequest, resp *provider.ConfigureResponse) {
	var config providerModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &config)...)
	if resp.Diagnostics.HasError() {
		return
	}
	for name, value := range map[string]interface{ IsUnknown() bool }{
		"endpoint": config.Endpoint, "api_key": config.APIKey, "ca_certificate": config.CACertificate,
		"insecure": config.Insecure, "operation_timeout": config.OperationTimeout,
	} {
		if value.IsUnknown() {
			resp.Diagnostics.AddAttributeError(path.Root(name), "Unknown provider setting", fmt.Sprintf("%s is unknown until apply. The provider needs it to plan: set it from a value that is known at plan time.", name))
		}
	}
	if resp.Diagnostics.HasError() {
		return
	}

	endpoint := setting(config.Endpoint, "TOFUMAN_ENDPOINT")
	apiKey := setting(config.APIKey, "TOFUMAN_API_KEY")
	if endpoint == "" {
		resp.Diagnostics.AddAttributeError(path.Root("endpoint"), "Missing endpoint", "Set endpoint in the provider block or in TOFUMAN_ENDPOINT.")
	}
	if apiKey == "" {
		resp.Diagnostics.AddAttributeError(path.Root("api_key"), "Missing API key", "Set api_key in the provider block or in TOFUMAN_API_KEY.")
	}

	insecure := config.Insecure.ValueBool()
	if config.Insecure.IsNull() {
		if text := os.Getenv("TOFUMAN_INSECURE"); text != "" {
			parsed, err := strconv.ParseBool(text)
			if err != nil {
				resp.Diagnostics.AddError("Invalid TOFUMAN_INSECURE", fmt.Sprintf("TOFUMAN_INSECURE must be true or false, got %q.", text))
			}
			insecure = parsed
		}
	}

	timeout := defaultOperationTimeout
	if text := setting(config.OperationTimeout, "TOFUMAN_OPERATION_TIMEOUT"); text != "" {
		parsed, err := time.ParseDuration(text)
		if err != nil || parsed <= 0 {
			resp.Diagnostics.AddAttributeError(path.Root("operation_timeout"), "Invalid operation timeout", fmt.Sprintf("operation_timeout must be a positive duration such as 90s, 30m, or 1h, got %q.", text))
		}
		timeout = parsed
	}
	if resp.Diagnostics.HasError() {
		return
	}

	c, err := client.New(client.Config{
		Endpoint:      endpoint,
		APIKey:        apiKey,
		CACertificate: setting(config.CACertificate, "TOFUMAN_CA_CERTIFICATE"),
		Insecure:      insecure,
	})
	if err != nil {
		resp.Diagnostics.AddError("Invalid provider configuration", err.Error())
		return
	}
	resp.ResourceData = &providerData{client: c, timeout: timeout}
}

func (p *tofumanProvider) Resources(_ context.Context) []func() resource.Resource {
	return []func() resource.Resource{newContainerResource}
}

func (p *tofumanProvider) DataSources(_ context.Context) []func() datasource.DataSource {
	return nil
}
