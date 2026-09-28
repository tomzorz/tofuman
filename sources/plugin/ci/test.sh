#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Runs the shim tests in php-cli against the webgui source at one tag, on the Docker of this
# host (REQ-TST-2 to REQ-TST-5). It creates and removes containers whose names start with
# tofumantest-, so it runs on a disposable Docker host only, never on an Unraid server.
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
else
  run php /plugin/tests/run.php "$@"
fi
