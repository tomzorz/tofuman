#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Builds the package of a plugin release (REQ-PKG-19, REQ-PKG-20): the tab, the shim, the API
# module, and its install script, as a tarball with the same bytes on every machine. It prints
# the path and the SHA256 hash of the tarball. With --plg it writes the version and the hash into
# tofuman.plg. The host needs git, node, npm, and GNU tar.
#
#   ci/package.sh VERSION [--plg]   for example ci/package.sh 2026.09.28 --plg
set -eu
here=$(cd "$(dirname "$0")" && pwd)
plugin=$(cd "$here/.." && pwd)
version=${1:?usage: ci/package.sh VERSION [--plg]}
work=${TOFUMAN_WORK:-$plugin/.work}
if ! echo "$version" | grep -Eq '^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$'; then
  echo "the version must be YYYY.MM.DD, with at most one lowercase letter after it (REQ-PKG-16)" >&2
  exit 2
fi
mkdir -p "$work"

# The API module, built against @unraid/shared from source, as the tests build it.
shared=$(sh "$plugin/api/ci/unraid-shared.sh" "$work")
(
  cd "$plugin/api"
  npm ci --no-audit --no-fund --loglevel=error >&2
  rm -rf node_modules/@unraid/shared dist
  mkdir -p node_modules/@unraid
  cp -r "$shared" node_modules/@unraid/shared
  npm run --silent build >&2
)

stage="$work/package-$version"
rm -rf "$stage"
mkdir -p "$stage/shim" "$stage/api" "$stage/scripts"
cp -r "$plugin/tab/." "$stage/"
cp -r "$plugin/templates" "$stage/"
cp -r "$plugin/shim/tofuman-shim.php" "$plugin/shim/bootstrap.php" "$plugin/shim/tested-builds.json" "$plugin/shim/src" "$stage/shim/"
cp -r "$plugin/api/dist" "$stage/api/"
cp "$plugin/scripts/api-module.php" "$stage/scripts/"
# The manifest that unraid-api reads, at the version of the plugin (REQ-PKG-11, REQ-PKG-15).
node -e '
  const fs = require("node:fs");
  const [source, target, version] = process.argv.slice(1);
  const m = JSON.parse(fs.readFileSync(source, "utf8"));
  const keep = { name: m.name, version, description: m.description, license: m.license, type: m.type, main: m.main,
    peerDependencies: m.peerDependencies, peerDependenciesMeta: m.peerDependenciesMeta };
  fs.writeFileSync(target, JSON.stringify(keep, null, 2) + "\n");
' "$plugin/api/package.json" "$stage/api/package.json" "$version"

# The same bytes on every machine: a fixed order, time, owner, and mode for every entry, and no
# name or time in the gzip header.
tarball="$work/tofuman-$version.tgz"
tar --format=gnu --sort=name --mtime='2000-01-01 00:00:00Z' --owner=0 --group=0 --numeric-owner \
  --mode='u+rwX,go+rX,go-w' -C "$stage" -cf - . | gzip -9 -n > "$tarball"
sha=$(sha256sum "$tarball" | cut -d' ' -f1)
echo "$tarball $sha"

if [ "${2:-}" = "--plg" ]; then
  sed -i -e "s|<!ENTITY version \"[^\"]*\">|<!ENTITY version \"$version\">|" \
    -e "s|<!ENTITY sha256 \"[^\"]*\">|<!ENTITY sha256 \"$sha\">|" "$plugin/tofuman.plg"
fi
