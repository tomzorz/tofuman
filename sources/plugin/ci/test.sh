#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Runs the tests of the plugin against the webgui source at one tag, on the Docker of this
# host: the shim in php-cli (REQ-TST-2 to REQ-TST-5), then the API module against the real
# shim (REQ-TST-6). It creates and removes containers whose names start with tofumantest-,
# so it runs on a disposable Docker host only, never on an Unraid server. The host needs git,
# node, and npm.
#
#   WEBGUI_TAG=7.3.2 ci/test.sh [filter]   run the tests, optionally only matching ones
#   WEBGUI_TAG=7.3.2 ci/test.sh --hashes   print the files and hashes for tested-builds.json
set -eu
if [ -d /boot/config/plugins/dockerMan ]; then
  echo "this looks like an Unraid server; the shim tests run on a disposable Docker host only" >&2
  exit 2
fi
here=$(cd "$(dirname "$0")" && pwd)
plugin=$(cd "$here/.." && pwd)
tag=${WEBGUI_TAG:-7.3.2}
work=${TOFUMAN_WORK:-$plugin/.work}
network=tofumantest0

mkdir -p "$work/mnt"
[ -d "$work/webgui-$tag" ] || git clone -q --depth 1 --branch "$tag" https://github.com/unraid/webgui.git "$work/webgui-$tag"
docker build -q -t tofuman-shim-test -f "$here/Dockerfile.test" "$here" >/dev/null
docker network inspect $network >/dev/null 2>&1 || docker network create -d macvlan --subnet 192.0.2.0/24 --gateway 192.0.2.1 $network >/dev/null

cleanup() {
  docker ps -aq --filter name=tofumantest- | xargs -r docker rm -f >/dev/null
  docker network rm $network >/dev/null 2>&1 || true
}
trap cleanup EXIT

run() {
  docker run --rm -i \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "$plugin:/plugin:ro" \
    -v "$work/webgui-$tag:/webgui:ro" \
    -v "$work/mnt:/mnt/tofumantest" \
    -e TOFUMAN_DOCROOT=/webgui/emhttp \
    -e TOFUMAN_VAR_INI=/plugin/tests/fixtures/var.ini \
    -e TOFUMAN_TEST_NETWORK=$network \
    tofuman-shim-test "$@"
}

if [ "${1:-}" = "--hashes" ]; then
  echo '{"action":"testedBuild"}' | run php /plugin/shim/tofuman-shim.php
  exit
fi

run php /plugin/tests/run.php "$@"

# The API module: built on this host against @unraid/shared from source, its schema checked
# here, its service tested in the container against the real shim.
tarball=$(sh "$plugin/api/ci/unraid-shared.sh" "$work")
(
  cd "$plugin/api"
  npm ci --no-audit --no-fund --loglevel=error
  # unraid-api ships @unraid/shared inside its own node_modules, and so do the tests. npm would
  # not install it: it is a peer, and .npmrc leaves peers alone.
  rm -rf node_modules/@unraid/shared
  mkdir -p node_modules/@unraid/shared
  tar -xzf "$tarball" -C node_modules/@unraid/shared --strip-components=1
  npm run --silent build:test
  node --test build/test/schema.test.js
)
run env TOFUMAN_SHIM=/plugin/shim/tofuman-shim.php node --test /plugin/api/build/test/service.test.js
