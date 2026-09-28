# SPDX-License-Identifier: MIT
# Go for the provider, the tofu binary of one pinned OpenTofu release that the acceptance tests
# run (REQ-TST-9, spike 4), and zip for the release archives that registries and filesystem
# mirrors expect.
FROM golang:1.26-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends zip && rm -rf /var/lib/apt/lists/*
COPY --from=ghcr.io/opentofu/opentofu:1.12.6 /usr/local/bin/tofu /usr/local/bin/tofu
