#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
# Spike runner for a Linux Docker host: runs spike_test.go with terraform-plugin-testing
# pointed at the tofu binary. BARE=1 leaves out the two OpenTofu registry variables, to see
# whether they are needed.
set -eu
here=$(cd "$(dirname "$0")" && pwd)
docker build -q -t tofuman-spike-plugin-testing "$here" >/dev/null
registry="-e TF_ACC_PROVIDER_HOST=registry.opentofu.org -e TF_ACC_PROVIDER_NAMESPACE=hashicorp"
[ "${BARE:-}" = 1 ] && registry=""
# shellcheck disable=SC2086
docker run --rm -v "$here:/spike" -w /spike \
  -e TF_ACC=1 -e TF_ACC_TERRAFORM_PATH=/usr/local/bin/tofu $registry \
  tofuman-spike-plugin-testing sh -c 'tofu version && go mod tidy && go test -v -count=1 ./...'
