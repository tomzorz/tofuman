#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Runs the tests of the plugin against the webgui source at one tag, on the Docker of this
# host: the shim in php-cli (REQ-TST-2 to REQ-TST-5), then the API module against the real
# shim (REQ-TST-6). It creates and removes containers whose names start with tofumantest-,
# so it runs on a disposable Docker host only, never on an Unraid server. The host needs git,
# node, and npm.
#
#   WEBGUI_TAG=7.3.2 ci/test.sh [filter]       run the tests, optionally only matching ones
#   WEBGUI_TAG=7.3.2 ci/test.sh --hashes       print the files and hashes for tested-builds.json
#   WEBGUI_TAG=7.3.2 ci/test.sh --server PORT  start the test server of the provider tests
#                                              (REQ-TST-7) on 127.0.0.1:PORT, with the API key
#                                              in TOFUMAN_TEST_API_KEY, and leave it running
#   WEBGUI_TAG=7.3.2 ci/test.sh --tab PORT     serve a preview of the tab on 127.0.0.1:PORT,
#                                              with seeded containers, until stopped
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

# docker run in the test image, with the shim, the webgui source, and the Docker of this host;
# the arguments are further docker run options, the image, and the command
in_image() {
  docker run \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "$plugin:/plugin:ro" \
    -v "$work/webgui-$tag:/webgui:ro" \
    -v "$work/mnt:/mnt/tofumantest" \
    -e TOFUMAN_DOCROOT=/webgui/emhttp \
    -e TOFUMAN_VAR_INI=/plugin/tests/fixtures/var.ini \
    -e TOFUMAN_TEST_NETWORK=$network \
    -e TOFUMAN_SHIM=/plugin/shim/tofuman-shim.php \
    "$@"
}

run() {
  in_image --rm -i tofuman-shim-test "$@"
}

# The API module, built on this host against @unraid/shared from source.
build_api() {
  shared=$(sh "$plugin/api/ci/unraid-shared.sh" "$work")
  (
    cd "$plugin/api"
    npm ci --no-audit --no-fund --loglevel=error
    # unraid-api ships @unraid/shared inside its own node_modules, and so do the tests. npm would
    # not install it: it is a peer, and .npmrc leaves peers alone.
    rm -rf node_modules/@unraid/shared
    mkdir -p node_modules/@unraid
    cp -r "$shared" node_modules/@unraid/shared
    npm run --silent build:test
  )
}

case "${1:-}" in
  --hashes)
    echo '{"action":"testedBuild"}' | run php /plugin/shim/tofuman-shim.php
    ;;
  --server)
    port=${2:?usage: ci/test.sh --server PORT}
    : "${TOFUMAN_TEST_API_KEY:?the test server needs TOFUMAN_TEST_API_KEY}"
    build_api
    docker rm -f tofumantest-server >/dev/null 2>&1 || true
    in_image -d --name tofumantest-server -p "127.0.0.1:$port:8931" -e TOFUMAN_TEST_API_KEY tofuman-shim-test node /plugin/api/build/test/server.js >/dev/null
    tries=0
    until docker exec tofumantest-server node -e "fetch('http://127.0.0.1:8931/health').then((r) => process.exit(r.ok ? 0 : 1), () => process.exit(1))" 2>/dev/null; do
      tries=$((tries + 1))
      if [ $tries -ge 30 ]; then
        docker logs tofumantest-server >&2
        exit 1
      fi
      sleep 1
    done
    # The server, the containers it creates, and the network stay up for the provider tests,
    # which remove them.
    trap - EXIT
    ;;
  --tab)
    port=${2:?usage: ci/test.sh --tab PORT}
    # not named tofumantest-: the seed removes those containers before it creates its own
    in_image --rm --name tofuman-tab-preview -p "127.0.0.1:$port:8080" tofuman-shim-test \
      sh -c 'php /plugin/tests/preview/seed.php && php -S 0.0.0.0:8080 /plugin/tests/preview/router.php'
    ;;
  *)
    run php /plugin/tests/run.php "$@"
    build_api
    (cd "$plugin/api" && node --test build/test/schema.test.js)
    run node --test /plugin/api/build/test/service.test.js
    ;;
esac
