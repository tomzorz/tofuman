# Spike: can terraform-plugin-testing drive OpenTofu against a framework provider?

**Date**: 2026-09-28
**Timebox**: 15 minutes
**Time spent**: about 10 minutes
**Sandbox**: `spikes/2026-09-28-plugin-testing-on-opentofu/`

## Question

Can terraform-plugin-testing v1.16.0 run acceptance tests through the `tofu` binary (OpenTofu 1.12.6) against an in-process terraform-plugin-framework v1.19.0 provider, for the things the tofuman provider needs: create, import by a name that resolves to a different ID, update in place with the same ID, and a read that finds nothing and drops the resource from the state?

## How to run

On any Linux Docker host:

    ./run.sh          # with the two OpenTofu registry variables
    BARE=1 ./run.sh   # without them

`run.sh` builds `golang:1.26` with `tofu` copied in from `ghcr.io/opentofu/opentofu:1.12.6` and runs `spike_test.go` with `TF_ACC=1` and `TF_ACC_TERRAFORM_PATH` pointing at `tofu`.

## Findings

- With `TF_ACC_PROVIDER_HOST=registry.opentofu.org` and `TF_ACC_PROVIDER_NAMESPACE=hashicorp` the test passes, all four steps. The OpenTofu debug log shows the real commands: two `init`, nine `plan` (six of them the refresh plans that check for an empty plan after each step), three `apply`, one `import`, one `destroy`, and the `show -json` calls the plan checks read.
- Import with `ImportStateId: "alpha"` resolves the name to the ID in `ImportState`, and `ImportStateVerify` compares the imported state with the created one. That is the shape of REQ-PRV-3.
- `plancheck.ExpectResourceAction(..., ResourceActionUpdate)` holds for a change of `name`, and the ID stays the same (REQ-PRV-8).
- After the resource vanishes behind the provider's back, the read drops it and the next plan is a create (REQ-PRV-10).
- Without the two variables, `tofu init` fails: `Invalid provider namespace: The legacy provider namespace "-" can be used only with hostname registry.opentofu.org.` The same error as opentofu/opentofu#977.

## Verdict

**Go.** The provider tests can use terraform-plugin-testing with the `tofu` binary, as long as the test environment sets `TF_ACC_TERRAFORM_PATH`, `TF_ACC_PROVIDER_HOST=registry.opentofu.org` and `TF_ACC_PROVIDER_NAMESPACE`.

## Caveats and open questions

- One OpenTofu version, one framework version, one resource with two string attributes. Sensitive attributes, nested blocks and long-running operations weren't part of it.
- terraform-plugin-testing pulls in terraform-plugin-sdk/v2, terraform-exec and hc-install as its own dependencies; the provider itself needs only the framework.
