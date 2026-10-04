// SPDX-License-Identifier: MIT

package client

import (
	"context"
	"fmt"
	"time"

	"github.com/hashicorp/terraform-plugin-log/tflog"
)

// ConfigEntry is TofumanConfigEntry and TofumanConfigEntryInput of spec section 15.
type ConfigEntry struct {
	Type        string `json:"type"`
	Name        string `json:"name"`
	Target      string `json:"target"`
	Value       string `json:"value"`
	Default     string `json:"default"`
	Mode        string `json:"mode"`
	Description string `json:"description"`
	Display     string `json:"display"`
	Required    bool   `json:"required"`
	Mask        bool   `json:"mask"`
}

// Definition is TofumanDefinition and TofumanDefinitionInput. Every list is non-null in the
// schema, so a nil list fails the request.
type Definition struct {
	Name          string        `json:"name"`
	Repository    string        `json:"repository"`
	Network       string        `json:"network"`
	IPAddresses   []string      `json:"ipAddresses"`
	MACAddress    string        `json:"macAddress"`
	Autostart     bool          `json:"autostart"`
	Privileged    bool          `json:"privileged"`
	CPUSet        string        `json:"cpuset"`
	Shell         string        `json:"shell"`
	ExtraParams   []string      `json:"extraParams"`
	PostArgs      []string      `json:"postArgs"`
	WebUI         string        `json:"webUi"`
	Icon          string        `json:"icon"`
	Overview      string        `json:"overview"`
	Category      string        `json:"category"`
	Support       string        `json:"support"`
	Project       string        `json:"project"`
	ReadMe        string        `json:"readMe"`
	TemplateURL   string        `json:"templateUrl"`
	Registry      string        `json:"registry"`
	DonateText    string        `json:"donateText"`
	DonateLink    string        `json:"donateLink"`
	Requires      string        `json:"requires"`
	ConfigEntries []ConfigEntry `json:"configEntries"`
}

// Container is TofumanContainer.
type Container struct {
	// ID is the managed ID.
	ID                       string     `json:"id"`
	Definition               Definition `json:"definition"`
	Running                  bool       `json:"running"`
	LastMutationAt           *string    `json:"lastMutationAt"`
	ChangedSinceLastMutation bool       `json:"changedSinceLastMutation"`
}

// The states of an operation.
const (
	StateQueued    = "QUEUED"
	StateRunning   = "RUNNING"
	StateSucceeded = "SUCCEEDED"
	StateFailed    = "FAILED"
)

// Operation is TofumanOperation.
type Operation struct {
	ID    string  `json:"id"`
	State string  `json:"state"`
	Step  *string `json:"step"`
	Error *string `json:"error"`
	// Container is set after a successful create or update.
	Container *Container `json:"container"`
}

const containerFields = `id running lastMutationAt changedSinceLastMutation
definition {
  name repository network ipAddresses macAddress autostart privileged cpuset shell extraParams postArgs
  webUi icon overview category support project readMe templateUrl registry donateText donateLink requires
  configEntries { type name target value default mode description display required mask }
}`

const operationFields = `id state step error container { ` + containerFields + ` }`

// Container returns the managed container with the managed ID id, or nil if there is none.
func (c *Client) Container(ctx context.Context, id string) (*Container, error) {
	var data struct {
		Tofuman struct {
			Container *Container `json:"container"`
		} `json:"tofuman"`
	}
	err := c.do(ctx, `query($id: ID) { tofuman { container(id: $id) { `+containerFields+` } } }`, map[string]any{"id": id}, &data)
	return data.Tofuman.Container, err
}

// ContainerNamed returns the managed container with the name name, or nil if there is none.
func (c *Client) ContainerNamed(ctx context.Context, name string) (*Container, error) {
	var data struct {
		Tofuman struct {
			Container *Container `json:"container"`
		} `json:"tofuman"`
	}
	err := c.do(ctx, `query($name: String) { tofuman { container(name: $name) { `+containerFields+` } } }`, map[string]any{"name": name}, &data)
	return data.Tofuman.Container, err
}

// Check is TofumanCheck: the failed checks of a mutation that did not happen.
type Check struct {
	FailedChecks []string `json:"failedChecks"`
	PolicyGaps   int      `json:"policyGaps"`
}

// Check asks the API module which checks a mutation would fail, without the mutation
// (REQ-PRV-15): a definition alone checks a create, an ID and a definition check an update, and
// an ID alone checks a delete.
func (c *Client) Check(ctx context.Context, id *string, definition *Definition) (*Check, error) {
	var data struct {
		Tofuman struct {
			Check *Check `json:"check"`
		} `json:"tofuman"`
	}
	err := c.do(ctx, `query($id: ID, $definition: TofumanDefinitionInput) { tofuman { check(id: $id, definition: $definition) { failedChecks policyGaps } } }`,
		map[string]any{"id": id, "definition": definition}, &data)
	if err != nil {
		return nil, err
	}
	if data.Tofuman.Check == nil {
		return nil, fmt.Errorf("the check answered without a result")
	}
	return data.Tofuman.Check, nil
}

// Create starts an operation that creates a managed container. A nil startCheckSeconds leaves
// the start check at the server's default (REQ-PRV-19).
func (c *Client) Create(ctx context.Context, definition Definition, startCheckSeconds *int) (*Operation, error) {
	parameters, arguments, variables := withStartCheck(`$definition: TofumanDefinitionInput!`, `definition: $definition`, map[string]any{"definition": definition}, startCheckSeconds)
	return c.mutate(ctx, "createContainer", parameters, arguments, variables)
}

// Update starts an operation that recreates the managed container id from definition.
func (c *Client) Update(ctx context.Context, id string, definition Definition, startCheckSeconds *int) (*Operation, error) {
	parameters, arguments, variables := withStartCheck(`$id: ID!, $definition: TofumanDefinitionInput!`, `id: $id, definition: $definition`, map[string]any{"id": id, "definition": definition}, startCheckSeconds)
	return c.mutate(ctx, "updateContainer", parameters, arguments, variables)
}

// withStartCheck names the start check only when it differs from the default, so that a plugin
// from before the start check still takes the mutation.
func withStartCheck(parameters, arguments string, variables map[string]any, seconds *int) (string, string, map[string]any) {
	if seconds == nil {
		return parameters, arguments, variables
	}
	variables["startCheckSeconds"] = *seconds
	return parameters + `, $startCheckSeconds: Int!`, arguments + `, startCheckSeconds: $startCheckSeconds`, variables
}

// Delete starts an operation that removes the managed container id and its template.
func (c *Client) Delete(ctx context.Context, id string) (*Operation, error) {
	return c.mutate(ctx, "deleteContainer", `$id: ID!`, `id: $id`, map[string]any{"id": id})
}

func (c *Client) mutate(ctx context.Context, mutation, parameters, arguments string, variables map[string]any) (*Operation, error) {
	var data struct {
		Tofuman map[string]*Operation `json:"tofuman"`
	}
	query := fmt.Sprintf(`mutation(%s) { tofuman { %s(%s) { %s } } }`, parameters, mutation, arguments, operationFields)
	if err := c.do(ctx, query, variables, &data); err != nil {
		return nil, err
	}
	operation := data.Tofuman[mutation]
	if operation == nil {
		return nil, fmt.Errorf("%s answered without an operation", mutation)
	}
	return operation, nil
}

// Operation returns the operation id.
func (c *Client) Operation(ctx context.Context, id string) (*Operation, error) {
	var data struct {
		Tofuman struct {
			Operation *Operation `json:"operation"`
		} `json:"tofuman"`
	}
	if err := c.do(ctx, `query($id: ID!) { tofuman { operation(id: $id) { `+operationFields+` } } }`, map[string]any{"id": id}, &data); err != nil {
		return nil, err
	}
	if data.Tofuman.Operation == nil {
		return nil, fmt.Errorf("the query for operation %s answered without it", id)
	}
	return data.Tofuman.Operation, nil
}

// Wait polls the operation until it ends or until timeout passes (REQ-PRV-9). A failed
// operation comes back as an error that names the step.
func (c *Client) Wait(ctx context.Context, operation *Operation, timeout time.Duration) (*Operation, error) {
	deadline := time.Now().Add(timeout)
	interval := 250 * time.Millisecond
	for {
		step := ""
		if operation.Step != nil {
			step = *operation.Step
		}
		tflog.Debug(ctx, "tofuman operation", map[string]any{"operation": operation.ID, "state": operation.State, "step": step}) // REQ-PRV-17
		switch operation.State {
		case StateSucceeded:
			return operation, nil
		case StateFailed:
			return operation, failure(operation)
		}
		if time.Now().After(deadline) {
			return operation, fmt.Errorf("operation %s is still %s after %s. It goes on without tofu: the audit log on the tofuman tab shows how it ends, and the next plan shows where the container stands", operation.ID, operation.State, timeout)
		}
		select {
		case <-ctx.Done():
			return operation, ctx.Err()
		case <-time.After(interval):
		}
		interval = min(2*interval, maxPollInterval)
		next, err := c.Operation(ctx, operation.ID)
		if err != nil {
			return operation, err
		}
		operation = next
	}
}

func failure(operation *Operation) error {
	step, text := "unknown", "no error text"
	if operation.Step != nil {
		step = *operation.Step
	}
	if operation.Error != nil {
		text = *operation.Error
	}
	return fmt.Errorf("operation %s failed at the step %s: %s", operation.ID, step, text)
}
