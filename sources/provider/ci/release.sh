#!/bin/sh
# SPDX-License-Identifier: MIT
#
# Builds the provider for the platforms of REQ-PKG-9 into dist/, one zip per platform with the
# names that a provider registry and a filesystem mirror expect, and a SHA256SUMS file. The host
# needs Docker only.
#
#   ci/release.sh VERSION   for example ci/release.sh 0.1.0
set -eu
here=$(cd "$(dirname "$0")" && pwd)
provider=$(cd "$here/.." && pwd)
version=${1:?usage: ci/release.sh VERSION}
if ! echo "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "the version must be a semantic version X.Y.Z (REQ-PKG-17)" >&2
  exit 2
fi
rm -rf "$provider/dist"
mkdir -p "$provider/dist" "$provider/.work/go"
docker build -q -t tofuman-provider-test -f "$here/Dockerfile" "$here" >/dev/null
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$provider:/provider" -v "$provider/.work/go:/go-cache" -w /provider \
  -e HOME=/tmp -e GOPATH=/go-cache/path -e GOCACHE=/go-cache/build -e CGO_ENABLED=0 -e VERSION="$version" \
  tofuman-provider-test sh -ec '
    for target in linux/amd64 linux/arm64 darwin/arm64 windows/amd64; do
      os=${target%/*}
      arch=${target#*/}
      binary=terraform-provider-tofuman_v$VERSION
      if [ "$os" = windows ]; then binary=$binary.exe; fi
      mkdir -p "dist/$os-$arch"
      GOOS=$os GOARCH=$arch go build -trimpath -ldflags "-s -w -X main.version=$VERSION" -o "dist/$os-$arch/$binary" .
      (cd "dist/$os-$arch" && zip -q -X "../terraform-provider-tofuman_${VERSION}_${os}_${arch}.zip" "$binary")
      rm -r "dist/$os-$arch"
    done
    cd dist
    sha256sum ./*.zip | sed "s| \./| |" > "terraform-provider-tofuman_${VERSION}_SHA256SUMS"
  '
ls -1 "$provider/dist"
