#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Builds @unraid/shared from the unraid/api source at one tag and prints the path of the built
# package: the upstream manifest and dist/, which is what the package ships. The package is not
# on npm; unraid-api ships it inside its own node_modules, so the API module needs it only to
# build and to test.
#
#   UNRAID_API_TAG=v4.37.4 ci/unraid-shared.sh <work dir>
set -eu
tag=${UNRAID_API_TAG:-v4.37.4}
work=${1:?usage: unraid-shared.sh <work dir>}
package="$work/unraid-shared-$tag"
if [ ! -d "$package" ]; then
  source="$work/unraid-api-$tag"
  [ -d "$source" ] || git clone -q --depth 1 --branch "$tag" https://github.com/unraid/api.git "$source"
  build="$work/unraid-shared-build-$tag"
  rm -rf "$build" "$package.partial"
  cp -r "$source/packages/unraid-shared" "$build"
  # Outside the pnpm workspace npm installs what the workspace would: the devDependencies and
  # the pinned peers. The peers become devDependencies for the build, because npm does not
  # install the peers of the root once it skips the peer check. It skips that check because
  # pnpm does too (nest-authz declares NestJS 10, the package pins 11). Only the path goes to
  # stdout. No npm pack: npm 10 runs the prepare script even with --ignore-scripts, and that
  # script calls pnpm.
  (
    cd "$build"
    node -e "
      const fs = require('node:fs');
      const manifest = JSON.parse(fs.readFileSync('package.json', 'utf8'));
      manifest.devDependencies = { ...manifest.peerDependencies, ...manifest.devDependencies };
      delete manifest.peerDependencies;
      fs.writeFileSync('package.json', JSON.stringify(manifest, null, 2));
    "
    npm install --ignore-scripts --legacy-peer-deps --no-audit --no-fund --loglevel=error >/dev/null
    npx tsc --project tsconfig.build.json
  ) >&2
  mkdir "$package.partial"
  cp "$source/packages/unraid-shared/package.json" "$package.partial/"
  cp -r "$build/dist" "$package.partial/"
  mv "$package.partial" "$package"
fi
echo "$package"
