#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-or-later
# Spike runner for a disposable Linux Docker host. It creates containers named tofuman-spike-*,
# never starts them, and removes them and its macvlan network (on a dummy parent) at the end.
#
#   TEMPLATES=/path/to/templates CUSTOM_NETWORKS=br0 NET_SUBNET=192.0.2.0/24 NET_GATEWAY=192.0.2.1 ./run.sh
set -eu
here=$(cd "$(dirname "$0")" && pwd)
work="$here/work"
out="$work/out"
: "${TEMPLATES:?set TEMPLATES to a directory of DockerMan user templates}"
: "${CUSTOM_NETWORKS:=br0}" "${NET_SUBNET:=192.0.2.0/24}" "${NET_GATEWAY:=192.0.2.1}"
templates=$(cd "$TEMPLATES" && pwd)
mkdir -p "$work"
[ -d "$work/webgui" ] || git clone -q --depth 1 --branch 7.3.2 https://github.com/unraid/webgui.git "$work/webgui"

php() {
  docker run --rm -u "$(id -u):$(id -g)" -e CUSTOM_NETWORKS="$CUSTOM_NETWORKS" \
    -v "$here:/spike:ro" -v "$work:/work" -v "$templates:/templates:ro" \
    php:8.3-cli php /spike/roundtrip.php "$1" /work/webgui /templates /work/out
}

echo "## round trip"
php write

echo "## docker create"
net=${CUSTOM_NETWORKS%%,*}
docker network inspect "$net" >/dev/null 2>&1 || docker network create -d macvlan --subnet "$NET_SUBNET" --gateway "$NET_GATEWAY" "$net" >/dev/null
docker pull -q busybox:latest >/dev/null
for script in "$out"/*.sh; do
  name=$(basename "$script" .sh)
  # Unraid runs the command through popen, which is /bin/sh -c; do the same.
  if err=$(sh "$script" 2>&1 >/dev/null); then
    docker inspect "tofuman-spike-$name" > "$out/$name.inspect.json"
    echo "created $name"
  else
    echo "FAILED $name: $err"
  fi
done

echo "## compare"
php compare

echo "## cleanup"
docker ps -aq --filter name=tofuman-spike- | xargs -r docker rm >/dev/null
docker network rm "$net" >/dev/null
