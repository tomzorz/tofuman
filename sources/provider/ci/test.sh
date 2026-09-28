#!/bin/sh
# SPDX-License-Identifier: MIT
#
# Runs the provider tests: the unit tests, and the acceptance tests through the tofu binary
# against the test server of the plugin (REQ-TST-7, REQ-TST-9). The test server creates
# containers whose names start with tofumantest- on the Docker of this host, so this runs on a
# disposable Docker host only, never on an Unraid server. The host needs what the plugin tests
# need: git, node, npm, and Docker.
#
#   ci/test.sh [go test flags]   for example ci/test.sh -run TestContainerLifecycle
set -eu
here=$(cd "$(dirname "$0")" && pwd)
provider=$(cd "$here/.." && pwd)
repo=$(cd "$provider/../.." && pwd)
work=$provider/.work
port=${TOFUMAN_TEST_PORT:-8931}
export TOFUMAN_TEST_API_KEY=tofumantest-provider-key

cleanup() {
  docker ps -aq --filter name=tofumantest- | xargs -r docker rm -f >/dev/null
  docker network rm tofumantest0 >/dev/null 2>&1 || true
}
trap cleanup EXIT

sh "$repo/sources/plugin/ci/test.sh" --server "$port"
docker build -q -t tofuman-provider-test -f "$here/Dockerfile" "$here" >/dev/null
mkdir -p "$work/go"
# terraform-plugin-testing needs the two TF_ACC_PROVIDER variables to run tofu instead of
# terraform (spike 4); without them tofu init rejects the provider address.
docker run --rm --network host --user "$(id -u):$(id -g)" \
  -v "$provider:/provider" -v "$work/go:/go-cache" -w /provider \
  -e HOME=/tmp -e GOPATH=/go-cache/path -e GOCACHE=/go-cache/build \
  -e TF_ACC=1 -e TF_ACC_TERRAFORM_PATH=/usr/local/bin/tofu \
  -e TF_ACC_PROVIDER_HOST=registry.opentofu.org -e TF_ACC_PROVIDER_NAMESPACE=tomzorz \
  -e TOFUMAN_ENDPOINT="http://127.0.0.1:$port" -e TOFUMAN_API_KEY="$TOFUMAN_TEST_API_KEY" \
  tofuman-provider-test go test -count=1 "$@" ./...
