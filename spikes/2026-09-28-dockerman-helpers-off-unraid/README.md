# Spike: can DockerMan's template helpers run off Unraid, and are they lossless?

**Date**: 2026-09-28
**Timebox**: 20 minutes
**Time spent**: about 25 minutes (the byte-level check ran past the box)
**Sandbox**: `spikes/2026-09-28-dockerman-helpers-off-unraid/`

## Question

Can the webgui's own DockerMan helpers (`Helpers.php` at tag `7.3.2`) run in plain php-cli outside Unraid? Do real user templates survive `xmlToVar`, the Edit form's POST shape, `postToXML` and `xmlToVar` again without change? And does `xmlToCommand`'s output create the intended container on a plain Docker host?

## How to run

On any Linux Docker host, with a directory of DockerMan user templates (`my-*.xml`):

    TEMPLATES=/path/to/templates CUSTOM_NETWORKS=br0 NET_SUBNET=192.0.2.0/24 NET_GATEWAY=192.0.2.1 ./run.sh

`run.sh` clones the webgui at `7.3.2`, runs `roundtrip.php` in `php:8.3-cli`, creates (never starts) one container per template with `busybox` standing in for the image, compares `docker inspect` with the template, and removes everything it created. Real templates stay outside the repo.

## Findings

Corpus: 17 user templates from a production server, covering bridge, host and macvlan networks, fixed IPs and MACs, GPU flags, ExtraParams and PostArgs. Run on Docker 29, with the custom network recreated as a macvlan on a dummy parent.

- The four helpers load in php-cli with nothing from Unraid except `_var()` and four globals: `$docroot`, `$var` (`timeZone`, `NAME`), `$subnet` (the network names) and `$driver` (network name to driver). They're stubbed at the top of `roundtrip.php`.
- The Edit form maps `xmlToVar` output to the POST shape `postToXML` takes. On Unraid the browser does that; the plugin has to do it on the server. It is about 40 lines (`varToPost` in `roundtrip.php`).
- 17 of 17 templates are a fixed point: `xmlToVar(postToXML(varToPost(xmlToVar(t))))` equals `xmlToVar(t)`. A provider whose schema is the `xmlToVar` shape therefore sees no permanent diff.
- Byte for byte, `postToXML` reproduces a webgui-written user template except in two places. `DateInstalled` is reset to the current time on every write, and carriage returns inside element text come out as `&#xD;` instead of `&#13;` (the same character). Attributes, the `&amp;amp;` double escapes and everything else come out as they went in.
- 16 of 17 `xmlToCommand` outputs were accepted by `docker create` unchanged, and every created container matched its template: binds with their mode, every Variable and Label, `net.unraid.docker.managed=dockerman`, the fixed IP and MAC on the macvlan (Docker 29 reports the MAC at create time), PostArgs as `Cmd`, and ExtraParams applied (`--user`, `--sysctl`, `--restart`, `--hostname`, `--dns`, `--gpus`). The one failure was `--runtime nvidia` on a host without that runtime.
- Read in the code while writing the harness, not exercised: `xmlToVar` turns a network the host doesn't have into `none` (`Helpers.php` L285-286); an empty Config value reads back as its `Default` (L259); with a MAC set, `xmlToCommand` emits `--network='name=…,ip=…,mac-address=…'` (L402-409); on macvlan, ipvlan and host networks a Port entry becomes a `TCP_PORT_<n>` variable instead of a `-p` mapping (L524-537).

## Verdict

**Go.** The plugin can read with `xmlToVar` and write with `postToXML` and `xmlToCommand`, the `xmlToVar` shape is a stable schema for the provider, and the path from template to container can be tested on a plain Linux Docker host.

## Caveats and open questions

- The corpus is webgui-written user templates, which are already `postToXML` output. A template straight from Community Applications carries elements `postToXML` doesn't write, so adopting one normalises it. Not measured.
- `varToPost` doesn't map the Tailscale fields; no template in the corpus uses Tailscale.
- Containers were created, never started, with a stand-in image, so nothing at start time is tested: GPU device requests, bind sources, the MAC on the wire.
- The harness used PHP 8.3; the PHP version Unraid 7.3.2 ships wasn't checked.
