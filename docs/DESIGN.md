# tofuman design

Grilled 2026-09-27. This becomes `docs/SPEC.md` in Lojbanlite when the spec gets written, and each decision below moves into the spec section it affects.

## What it is

An Unraid plugin plus an OpenTofu provider, so containers on an Unraid server can be declared in tofu and still be ordinary Unraid containers. The plugin writes DockerMan user templates (`/boot/config/plugins/dockerMan/templates-user/my-<Name>.xml`) and creates the containers through the webgui's own helpers (`postToXML`, `xmlToVar`, `xmlToCommand`), so Edit, Update, the icon and WebUI link, CA Auto Update and Appdata Backup keep working. It adds mutations and a query for container definitions to the built-in unraid-api as a NestJS plugin, and the provider is a GraphQL client of those. Reads go through `xmlToVar` on the same template the webgui edits, so a change made in the webgui shows up in `tofu plan` as drift.

## Who it is for

I'm building it for my own server first. Nothing in the repo names a machine, a host, an address or my network. The source is public on GitHub since 2026-09-28; releases (a Community Applications listing, a provider registry entry) wait until it has survived an Unraid upgrade or two.

## Marking and owning containers

The plugin marks its containers with a Docker label in the `tofuman` namespace, written as a template Config entry of `Type="Label"` whose value is a per-container UUID, and it keeps a registry on the flash drive. The registry is the source of truth, because the label can't be on its own: the Edit form shows it (the `Display` attribute only collapses an entry or hides its buttons), a user can edit or delete it, and "Add Container" from an existing `my-*.xml` copies it onto the clone. The UUID is how the plugin spots clones, renames and removals.

- The plugin only touches containers in its registry.
- It refuses to overwrite a hand-made container that has the same name.
- Adopting a hand-made container takes an explicit act on the box.
- The label entry's Name and Description carry the "managed by tofu" warning, so the Edit form shows it without any JavaScript.

The marker isn't an env var: the app would see it in its environment, anything that dumps env would print it, and `docker ps` can't filter on it. It isn't `net.unraid.docker.managed` either. Any value other than `dockerman` makes a container "3rd Party" (no Edit, no update check, no autostart toggle), and CA Auto Update's "ignore third party" option skips it. Our label sits next to that one.

## Validation and policy

Writing a template gives root on the server unless the plugin stops it:

- `ExtraParams`, `PostArgs` and the container name reach `/bin/sh` unescaped.
- Fields that are escaped can still give root: Privileged, a bind mount of `/`, devices, host networking.
- WebUI and support URLs land in an `onclick` attribute behind `addslashes()` only, which makes them stored XSS against the admin's session.
- Update, `rebuild_container` and CA Auto Update rebuild from the stored template through the same shell, so a poisoned template keeps firing long after the apply.

So the plugin validates every write when it writes the template. On top of that it enforces a strict policy: it refuses bind mounts outside an allowlist, Privileged, devices and host networking except for containers the policy names, and ExtraParams flags outside an allowlist. The policy lives on the box and only the webgui edits it. A stolen key can create constrained containers, but it can't get root.

## The API key

The plugin guards its mutations and its query with `DOCKER:CREATE_ANY`, which no official handler uses, and checks the calling key's ID against an allowlist kept in the webgui tab. A key holding only that pair reaches the plugin and nothing official, so it can't start, stop or remove hand-made containers through the stock API. If upstream ever starts using `CREATE_ANY`, the key gains that without anyone noticing, so the upgrade checklist looks for it.

Plugins can't bring a permission of their own: resources are a fixed list of 29 in `@unraid/shared`, and the shared `@UsePermissions` throws on anything else.

Before unraid-api 4.37.4 (Unraid 7.3.3), any authenticated key can also call every handler that declares no permission. That includes notification writes, reading OIDC client secrets, and `configureUps`, which writes its free-text `device` and `customUpsCable` fields into `/etc/apcupsd/apcupsd.conf` unfiltered and restarts apcupsd as root. A narrow key is only narrow from 4.37.4.

## What tofu owns

Tofu owns the template and the autostart flag. Whether a container is running is not tofu's business, so starting or stopping one by hand is not drift. A recreate leaves the container in the state it was in, and a new container starts only if autostart is on. `xmlToCommand` builds a `docker create`, so the plugin never has to start a container just to stop it again (`rebuild_container` does exactly that, which is one more reason not to reuse it).

## Image updates

Decided per container. Containers that should move get a tag, and CA Auto Update pulls and recreates them from the same template, which tofu doesn't see as drift. Anything that must never move on its own gets an `@sha256` digest. DockerMan's update check can't parse digest references (the hex becomes the tag and `name@sha256` the repository, so the registry lookup returns nothing), so a pinned container shows a blank update status and CA Auto Update never sees an update for it.

## New and existing containers

New containers are born in tofu. Existing hand-made ones are adopted later, each by an explicit act on the box. Import reads the template through `xmlToVar`, the same shape the provider writes, so there are no `docker inspect` defaults to reconcile after an import.

## Missing mounts

Before writing, the plugin checks every Path source against the live mounts and refuses with an error that names the missing one. It calls `xmlToCommand` without `create_paths`. Otherwise Docker's `-v` would create the missing directory on the RAM-backed root filesystem, and the container would write its data there.

## Unraid upgrades

The plugin pins the webgui build it was tested against, by hashes of the helper files it calls. On any other build, reads keep working and writes fail with a clear error until a smoke test passes and the pin moves. After an upgrade, applies fail loudly instead of writing wrong templates.

## In the webgui

- A Docker tab (a `.page` with `Menu="Docker:2"`) lists the managed containers with their tofu address, the time of the last apply, and whether the template changed since. The same tab holds the policy editor, the key allowlist, and an audit log of API writes.
- Managed rows in the stock container table get a badge from a stylesheet using `:has()` on the row's `input.autostart[container=NAME]`, if a spike shows it works. If the markup changes, the badge disappears and nothing else breaks.
- No JavaScript monkeypatching of the webgui.
- The Edit form carries the warning through the label entry.

## Testing

The webgui's PHP helpers, at the tag the plugin pins, run in a php-cli container on a Linux Docker host: template round trips, the generated commands, and the policy. The `docker create` commands they produce run against that host's own Docker. Only the tab, the badge and a final end-to-end run with throwaway containers need a real Unraid server, and a person runs those.

2026-09-28, spike 1 ([`spikes/2026-09-28-dockerman-helpers-off-unraid`](../spikes/2026-09-28-dockerman-helpers-off-unraid/README.md)), **go**: the helpers load in php-cli with `_var()` and four globals stubbed. 17 of 17 real user templates are a fixed point of `xmlToVar`, `postToXML`, `xmlToVar`, and 16 of 17 generated commands created a matching container on a plain Docker host; the 17th needs the nvidia runtime.

## Open

- The exact label keys in the `tofuman` namespace. Assumption: `tofuman.id` holds the UUID.
- The registry's format and location. Assumption: a JSON file under `/boot/config/plugins/tofuman/`.
- What the explicit adoption act looks like. Assumption: a button per unmanaged container in the tab.
- How pinned digests get bumped. Assumption: by hand in HCL.
- Autostart order. Not managed until decided.
- DockerMan writes every template variable to the flash in plaintext, masked or not. How the provider treats `Mask="true"` values is undecided. Assumption: sensitive attributes.
- Licence, undecided until publishing. The plugin runs GPLv2 webgui code, which points at GPLv2 for the plugin; the provider can differ.
- How the plugin reaches a server before it's published (where the `.plg` comes from), and how the provider reaches tofu (a dev override or a mirror).
- The `:has()` badge needs its spike.
- Whether the webgui's Update action behaves on a digest-pinned container. Untested.
- Whether Unraid Connect's flash backup uploads the plaintext API key files. Unverified.
- The `configureUps` injection wasn't traced to command execution.
- `postToXML` resets `DateInstalled` on every write (spike 1), so drift detection and the tab's changed-since flag can't compare template files. Comparing the `xmlToVar` shape is the obvious candidate.
- `xmlToVar` reads a network the host doesn't have as `none` (spike 1), so a template naming a missing network reads back as drift. Whether the plugin refuses such a write is for the spec.
- An empty Config value reads back as its `Default` (spike 1), so the schema can't say "empty, although the template has a default".
- Adopting a template that came straight from Community Applications drops the elements `postToXML` doesn't write. How adoption reports that is undecided.
- The Tailscale fields aren't covered by anything yet.

## Sources

Read 2026-09-27. unraid/webgui at tag `7.3.2` ([`369f0b2`](https://github.com/unraid/webgui/tree/369f0b2994584a6b6c8e6e570f5f86401d289234)); the docker manager's labels, escaping and exec path are unchanged on `master` (`7.4.0-beta.3`, [`3bb3af3`](https://github.com/unraid/webgui/tree/3bb3af333752800f8d8e1c7d90cb22e7883b7972)). unraid/api `v4.35.1` ([`a9625ae`](https://github.com/unraid/api/tree/a9625ae20a589e739926923b28ca7efe14233372)), which Unraid 7.3.2 ships, and `main` ([`d061525`](https://github.com/unraid/api/tree/d0615255e0ce062f7a8262e560f963d07f311539)).

What the design depends on, in the code:

- `ExtraParams` and `PostArgs` go into the `docker create` string raw ([Helpers.php L565-566](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L565-L566)), the name sits raw inside double quotes ([L430](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L430)), and `execCommand` runs the result through `popen`, which is `/bin/sh -c` ([L714](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L714)). Missing host paths get created only when `xmlToCommand` is called with `create_paths` ([L518-519](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L518-L519)).
- Only `dockerman` gets a template and an update status; any other `net.unraid.docker.managed` value nulls both ([DockerClient.php L415-417](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L415-L417)).
- Docker keeps the last `-l` for a key ([docker/cli parse.go L42-47](https://github.com/docker/cli/blob/7fc2dff9bceb96b266a3b2c3117c0955a0d9e616/opts/parse.go#L42-L47)), so a Config label can override `net.unraid.docker.managed` or ours. Validation has to look at label keys too.
- `postToXML` writes only the known elements and the nine known Config attributes ([Helpers.php L158-171](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L158-L171)), so anything else in a template disappears on the first Apply in the webgui. Config entries are the only plugin data that survives inside a template.
- Writers pick the template file by `my-<Name>.xml` ([DockerClient.php L142-156](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L142-L156)), readers match the `<Name>` element inside it. The two have to stay identical.
- `xmlToVar` and `xmlToCommand` read the globals `$subnet`, `$var` and `$driver`; set them up the way `update_container` does ([L25-32](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/scripts/update_container#L25-L32)). `rebuild_container` never sets `$var`, so containers it recreates get an empty `TZ` and `HOST_HOSTNAME`, and it runs the container before stopping it again ([L22-34](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/scripts/rebuild_container#L22-L34)).
- DockerMan's image parser splits a digest reference into repository `name@sha256` and tag `<hex>` ([DockerClient.php L1138-1170](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L1138-L1170)), and the update status comes back null when the lookup fails ([L601-610](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L601-L610)).
- API keys are roles plus (resource, action) pairs, stored as plaintext JSON under `/boot/config/plugins/dynamix.my.servers/keys/` ([paths.ts L63-65](https://github.com/unraid/api/blob/d0615255e0ce062f7a8262e560f963d07f311539/api/src/store/modules/paths.ts#L63-L65)), sent in `x-api-key` ([header.strategy.ts L15-18](https://github.com/unraid/api/blob/d0615255e0ce062f7a8262e560f963d07f311539/api/src/unraid-api/auth/header.strategy.ts#L15-L18)), with no expiry. The shared decorator rejects resources outside the fixed list ([use-permissions.directive.ts L93-97](https://github.com/unraid/api/blob/d0615255e0ce062f7a8262e560f963d07f311539/packages/unraid-shared/src/use-permissions.directive.ts#L93-L97)).
- API 4.35.1 registers nest-authz's `AuthZGuard` globally ([app.module.ts L63-72](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/app/app.module.ts#L63-L72)), which lets through any handler without permission metadata ([authz.guard.ts L37-39](https://github.com/apache/casbin-nest-authz/blob/8cb1097dff90e4585670db49fcec74bc69ad982b/src/authz.guard.ts#L37-L39)). `configureUps` has none ([ups.resolver.ts L83-84](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/graph/resolvers/ups/ups.resolver.ts#L83-L84)) and writes its input into `apcupsd.conf` unfiltered ([ups.service.ts L344-387](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/graph/resolvers/ups/ups.service.ts#L344-L387)). API 4.37.4 denies such handlers ([3ec4764](https://github.com/unraid/api/commit/3ec47647879a02bd45d55ca0e2bca987b1ff0d27)).
- The API's folder organizer exists, but the Vue Docker table that would render it is switched off ([api#1870](https://github.com/unraid/api/pull/1870)), and 7.4.0-beta.3 still ships the PHP table.
