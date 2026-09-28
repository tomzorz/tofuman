# SPDX-License-Identifier: MIT
# Go for the provider, and the tofu binary of one pinned OpenTofu release that the acceptance
# tests run (REQ-TST-9, spike 4).
FROM golang:1.26-bookworm
COPY --from=ghcr.io/opentofu/opentofu:1.12.6 /usr/local/bin/tofu /usr/local/bin/tofu
