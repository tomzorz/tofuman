# tofuman specification

Status: draft, 2026-09-28. A design interview on 2026-09-27, spike 1 on 2026-09-28, and a spec review on 2026-09-28 decided the requirements below. Section 20 lists what is still open.

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD", "SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and "OPTIONAL" in this document are to be interpreted as described in BCP 14 (RFC 2119, RFC 8174) when, and only when, they appear in all capitals, as shown here.

## 1. Glossary

### tofuman (noun)
The product that this repository builds: the plugin and the provider together.
Do not use: the tool, the project, the system.

### server (noun)
The Unraid machine on which the plugin runs.
Do not use: box, host, NAS, machine.

### webgui (noun)
The web interface of Unraid and its PHP code, from the `unraid/webgui` repository.
Do not use: web UI, GUI, dynamix.

### DockerMan (noun)
The Docker manager inside the webgui, `emhttp/plugins/dynamix.docker.manager`.
Do not use: Docker manager, dockerman, the Docker plugin.

### unraid-api (noun)
The GraphQL server that ships with Unraid OS 7.2 and later, from the `unraid/api` repository.
Do not use: the API, the Unraid API, the GraphQL server.

### template (noun)
A DockerMan user template: the file `/boot/config/plugins/dockerMan/templates-user/my-<Name>.xml` on the server.
Do not use: template file, XML, user template, definition file.

### definition (noun)
The contents of one template in the shape that `xmlToVar` returns. The API module and the provider exchange definitions and never templates.
Do not use: spec, configuration, manifest.

### config entry (noun)
One `<Config>` element of a template. Each config entry has exactly one type: path, port, variable, label, or device.
Do not use: setting, field, config item, parameter.

### plugin (noun)
The Unraid side of tofuman: the API module, the shim, the tab, and the `.plg` file that installs them.
Do not use: addon, extension, Unraid app.

### API module (noun)
The NestJS module that the plugin loads into unraid-api. The API module serves every tofuman query and mutation.
Do not use: API plugin, backend, resolver.

### shim (noun)
The PHP program that the API module runs as a child process to read and write templates and containers. The shim loads the DockerMan helpers.
Do not use: PHP helper, script, wrapper.

### tab (noun)
The page that the plugin adds under **Docker** in the webgui.
Do not use: page, dashboard, UI.

### provider (noun)
The OpenTofu provider that calls the API module.
Do not use: Terraform provider, client.

### managed container (noun)
A container that has an entry in the registry.
Do not use: tofu container, owned container.

### hand-made container (noun)
A container on the server that has no entry in the registry.
Do not use: unmanaged container, manual container, user container.

### registry (noun)
The file `/boot/config/plugins/tofuman/registry.json` that lists the managed containers.
Do not use: state, inventory, database.

### managed ID (noun)
The UUID that the plugin assigns to a container when the container enters the registry. The managed ID of a container never changes.
Do not use: tofuman ID, UUID, resource ID, container ID (Docker uses that name for a different value).

### marker (noun)
The config entry of type label whose target is `tofuman.id` and whose value is the managed ID. DockerMan turns the marker into a Docker label on the container.
Do not use: tag, ownership label, marker label.

### adopt (verb)
Turn a hand-made container into a managed container.
Do not use: import (OpenTofu uses that word for a different step), claim, take over.

### policy (noun)
The file `/boot/config/plugins/tofuman/policy.json` that limits what a mutation can write into a template.
Do not use: rules, allowlist (for the whole file), admission policy.

### key allowlist (noun)
The list of API key IDs in the policy.
Do not use: allowed keys, whitelist, key list.

### API key (noun)
A key that unraid-api issues. A client sends the API key in the `x-api-key` header.
Do not use: token, credential, key.

### administrator (noun)
A caller to whom unraid-api gives the ADMIN role: an API key with that role, a signed-in webgui session, or the local CLI session.
Do not use: admin, root user, owner.

### mutation (noun)
One call to a GraphQL mutation of the API module.
Do not use: write, change, request.

### operation (noun)
The background work that a mutation starts. The provider polls an operation until the operation ends.
Do not use: job, task, run.

### drift (noun)
A difference between the definition in the OpenTofu state and the definition that the API module reads from the template.
Do not use: divergence, out-of-band change.

### tested build (noun)
One set of SHA-256 hashes of the webgui files that the shim loads. Each plugin release lists the tested builds that its tests passed against.
Do not use: pin, supported version, known-good hashes.

### audit log (noun)
The file `/boot/config/plugins/tofuman/audit.jsonl`, with one JSON line per mutation and per adoption.
Do not use: log, history, journal.

### throwaway container (noun)
A container that exists only for a test, and that the tester removes after the test.
Do not use: test container, scratch container.

## 2. Scope

tofuman lets OpenTofu declare containers on a server, and the containers stay ordinary DockerMan containers. The plugin writes templates and creates containers through the DockerMan helpers. The webgui actions **Edit** and **Update**, CA Auto Update, and Appdata Backup keep working on managed containers. A change that a person makes in the webgui shows up in `tofu plan` as drift.

Version 1 leaves the following items out of scope:

- Virtual machines and Docker Compose stacks.
- Templates that enable Tailscale.
- The `<ExtraNetworks>` element, which exists only in webgui 7.4 and later.
- The start order of autostart containers, and the wait value of an autostart line.
- Image updates. Section 11 describes how images move.
- The Docker mutations of unraid-api.

## 3. Goals for version 1

- REQ-GOAL-1: The provider MUST create, read, update, and delete one managed container for each `tofuman_container` resource.
- REQ-GOAL-2: After the provider creates a container, the webgui MUST show that container with working **Edit** and **Update** actions.
- REQ-GOAL-3: After a person changes a managed container in the webgui, `tofu plan` MUST show that change as drift.
- REQ-GOAL-4: On unraid-api 4.37.4 and later, an API key on the key allowlist with only the pair `DOCKER:CREATE_ANY` MUST NOT give its holder root on the server.

## 4. Architecture

The provider reaches DockerMan through unraid-api, in these steps:

1. The provider sends GraphQL requests over HTTPS to the path `/graphql` on the server, with an API key.
2. unraid-api authenticates the API key, and then passes each tofuman request to the API module.
3. For each read or write of templates and containers, the API module runs the shim with JSON on standard input and standard output.
4. The shim loads `Helpers.php` and `DockerClient.php` from DockerMan, and uses `xmlToVar`, `postToXML`, and `xmlToCommand`.
5. The plugin keeps the registry, the policy, and the audit log on the flash drive, next to the templates.

The nginx server in front of unraid-api ends a request after 60 seconds without a response byte. An image pull can take longer than 60 seconds, so each mutation starts an operation and returns without a wait for that operation (section 9).

## 5. Ownership

The registry decides which containers tofuman owns. The marker carries the same fact inside the template, where it survives every recreate. The marker alone is not enough: the **Edit** form shows the marker, a person can change or delete the marker, and **Add Container** copies the marker onto a clone.

- REQ-OWN-1: The registry MUST be the only source of truth for which containers are managed containers.
- REQ-OWN-2: When a container enters the registry, the plugin MUST assign a new managed ID to that container.
- REQ-OWN-3: The shim MUST write the marker into the template of each managed container, as the last config entry.
- REQ-OWN-4: The marker MUST have the name `Managed by tofu` and the description `tofu manages this container. A change here shows up as drift, and the next apply reverts the change.`
- REQ-OWN-5: The API module MUST refuse a mutation on a container that has no entry in the registry.
- REQ-OWN-6: The API module MUST refuse to create a container whose name belongs to a hand-made container or to a template without a registry entry, or to both.
- REQ-OWN-7: The API module MUST NOT offer a mutation that adopts a container. Only a person in the tab can adopt a container (section 13).
- REQ-OWN-8: Before each query and each mutation, the API module MUST reconcile the registry with the containers on the server, by REQ-OWN-9 through REQ-OWN-11.
- REQ-OWN-9: If two or more containers carry the marker of one managed ID, the plugin MUST treat only the container with the name in the registry as the managed container.
- REQ-OWN-10: If no container has the name of a registry entry, the plugin MUST look for the marker of that entry. If exactly one container carries that marker, the plugin MUST record the name of that container in the entry.
- REQ-OWN-11: If a registry entry has no container and no template, the API module MUST remove that entry.
- REQ-OWN-12: If a registry entry has a template and no container, a query MUST report that entry as absent. A `createContainer` for the name of that entry MUST reuse the entry and its managed ID (added 2026-09-28 while building the shim: without this rule, a container that a person removes in the webgui can never come back through tofu).

## 6. Validation

Writing a template can give root on the server, in three ways:

- `ExtraParams`, `PostArgs`, and the container name reach `/bin/sh` without escaping.
- Fields that DockerMan escapes can still give root: Privileged, a bind mount of `/`, devices, and host networking.
- The webgui renders the URL fields of a template inside an `onclick` attribute behind `addslashes()` only, which allows stored cross-site scripting against an administrator.

The **Update** action, `rebuild_container`, and CA Auto Update rebuild a container from the stored template through the same shell. For that reason, the API module validates every field before the shim writes the template.

- REQ-VAL-1: The API module MUST validate every field of a definition before the shim writes the template.
- REQ-VAL-2: The API module MUST refuse a container name that does not match `^[a-zA-Z0-9][a-zA-Z0-9_.-]+$`.
- REQ-VAL-3: The provider MUST send `ExtraParams` and `PostArgs` as lists of arguments.
- REQ-VAL-4: The shim MUST store `ExtraParams` and `PostArgs` as their arguments, each argument quoted with `escapeshellarg`, joined by one space.
- REQ-VAL-5: When the shim reads `ExtraParams` or `PostArgs` from a template, the shim MUST split the text into arguments by the quoting rules of a POSIX shell.
- REQ-VAL-6: The API module MUST refuse an `ExtraParams` flag that the policy list `extraParamFlags` does not contain.
- REQ-VAL-7: The API module MUST refuse each `ExtraParams` flag in the following groups, even if the policy lists the flag:
  - Mounts: `-v`, `--volume`, `--mount`, `--volumes-from`.
  - Privilege: `--privileged`, `--cap-add`, `--security-opt`, `--device`, `--cgroup-parent`.
  - Namespaces: `--pid`, `--ipc`, `--uts`, `--userns`.
  - Network: `--net`, `--network`, `--ip`, `--ip6`, `--mac-address`, `-p`, `--publish`.
  - Environment and labels: `-e`, `--env`, `--env-file`, `-l`, `--label`, `--label-file`.
  - Host files: `--cidfile`.
- REQ-VAL-8: The API module MUST know, for each flag that the policy can list, whether the flag takes a value. The tab MUST refuse to save a policy that lists a flag that the API module does not know.
- REQ-VAL-9: The API module MUST refuse a label config entry whose target starts with `net.unraid.docker.` or with `tofuman.`. The shim writes the marker itself.
- REQ-VAL-10: For the fields `WebUI`, `Support`, `Project`, `ReadMe`, `DonateLink`, `Icon`, `TemplateURL`, and `Registry`, the API module MUST refuse a value that does not start with `http://` or `https://`.
- REQ-VAL-11: For the same fields, the API module MUST refuse a value that contains whitespace, `"`, `'`, `<`, `>`, `` ` ``, or `\`.
- REQ-VAL-12: The API module MUST accept the DockerMan placeholders `[IP]` and `[PORT:<number>]` in `WebUI`.
- REQ-VAL-13: The API module MUST refuse an address in `MyIP` that is neither a valid IPv4 address nor a valid IPv6 address.
- REQ-VAL-14: The API module MUST refuse a `MyMAC` that is not 6 pairs of hexadecimal digits joined by `:`.
- REQ-VAL-15: The API module MUST refuse a `CPUset` that does not match `^[0-9]+([-,][0-9]+)*$`.
- REQ-VAL-16: The API module MUST refuse a `Shell` that is not exactly one of `sh` or `bash`.
- REQ-VAL-17: The API module MUST refuse a `Repository` that does not match the reference grammar of `github.com/distribution/reference`.
- REQ-VAL-18: The API module MUST refuse a network that does not exist on the server. Reason: `xmlToVar` reads an unknown network as `none` (spike 1), so the definition would show drift after every apply.
- REQ-VAL-19: The API module MUST refuse a definition that enables Tailscale.
- REQ-VAL-20: The API module MUST refuse a definition that changes when it goes through `postToXML` and back through `xmlToVar`, and MUST name each field that changes.

REQ-VAL-20 exists because the read path of DockerMan rewrites some strings on its own. `xmlToVar` removes backslashes from `Overview`, removes `<` and `>` from each string that looks like HTML, and removes whitespace from the name. Without REQ-VAL-20, such a definition would show drift after every apply.

Docker keeps the last `-l` for a label key, so REQ-VAL-7 and REQ-VAL-9 protect `net.unraid.docker.managed` and the marker from an override.

## 7. Policy

The policy decides what a mutation can put into a template, beyond the checks in section 6. Only a person in the tab edits the policy, so an API key cannot widen its own limits.

- REQ-POL-1: If the policy file is absent or invalid, the API module MUST refuse every mutation.
- REQ-POL-2: The API module MUST refuse a path config entry whose host path is outside every directory in the policy list `bindRoots`.
- REQ-POL-3: To compare a host path with `bindRoots`, the shim MUST resolve the nearest existing ancestor of the host path with `realpath`. Reason: without that step, a symbolic link can lead out of `bindRoots`.
- REQ-POL-4: The API module MUST refuse Privileged, unless the policy lists the container in `exceptions` with `"privileged": true`.
- REQ-POL-5: The API module MUST refuse the network `host`, unless the policy lists the container in `exceptions` with `"hostNetwork": true`.
- REQ-POL-6: The API module MUST refuse a device config entry, unless the `devices` list of the exception for the container contains the device path.
- REQ-POL-7: The API module MUST refuse a network other than `host` that the policy list `networks` does not contain.
- REQ-POL-8: The API module MUST NOT offer a mutation that changes the policy.
- REQ-POL-9: The API module MUST NOT apply the policy to queries.
- REQ-POL-10: A change to the policy MUST NOT change an existing container. The API module applies the new policy at the next mutation of each container.

## 8. API keys

unraid-api knows a fixed list of 29 resources, and a plugin cannot add a resource of its own. The API module therefore borrows the pair `DOCKER:CREATE_ANY`, which no official handler uses. From unraid-api 4.37.4 on, an API key with only that pair reaches the API module and no official query or mutation.

- REQ-AUTH-1: The API module MUST guard each query, each mutation, and each field resolver with `@UsePermissions` for the action `CREATE_ANY` on the resource `DOCKER`.
- REQ-AUTH-2: The API module MUST accept a request from an API key whose ID is on the key allowlist.
- REQ-AUTH-3: The API module MUST accept a request from an administrator (decided 2026-09-28).
- REQ-AUTH-4: The API module MUST refuse every other request.
- REQ-AUTH-5: The plugin MUST NOT create API keys. A person creates the API key in the webgui, with only the pair `DOCKER:CREATE_ANY`.
- REQ-AUTH-6: In the upgrade procedure (section 12), a maintainer MUST check that no official handler of unraid-api requires `DOCKER:CREATE_ANY`. Reason: if upstream starts to use that pair, the API key gains the handler without notice.

unraid-api before 4.37.4 lets each authenticated API key call every handler without permission metadata. Those handlers include notification writes, the UPS configuration, and a query that returns OIDC client secrets. `configureUps` writes free text into `/etc/apcupsd/apcupsd.conf` and restarts apcupsd as root. On those versions, REQ-GOAL-4 does not hold.

## 9. Mutations and operations

- REQ-MUT-1: The API module MUST offer exactly three mutations: `createContainer`, `updateContainer`, and `deleteContainer`.
- REQ-MUT-2: Each mutation MUST run the checks of sections 5 through 8 and section 12 before the mutation returns.
- REQ-MUT-3: If a check fails, the mutation MUST return a refusal that names each failed check.
- REQ-MUT-4: If every check passes, the mutation MUST start an operation and return the ID of that operation.
- REQ-MUT-5: The API module MUST run at most one operation at a time. The API module MUST queue the other operations in order of arrival.
- REQ-MUT-6: The API module MUST keep the result of each operation for at least 1 hour after the operation ends.
- REQ-MUT-7: If a query asks for an operation that the API module does not know, the API module MUST return an error that names the operation ID. A restart of unraid-api loses the operations in memory.

An operation for `createContainer` or `updateContainer` runs these steps in order:

1. The shim checks the mounts (section 10).
2. The shim pulls the image.
3. The shim writes the template with `postToXML`.
4. For `updateContainer`, the shim stops the old container and removes the old container.
5. The shim creates the container with the command from `xmlToCommand`.
6. The shim starts the container by REQ-DEF-7.
7. The API module updates the registry and appends a line to the audit log.

- REQ-MUT-8: If step 1 or step 2 fails, the shim MUST leave the template and the old container unchanged.
- REQ-MUT-9: If an update fails after step 3, the shim MUST restore the previous template and recreate the previous container from that template.
- REQ-MUT-10: If a create fails after step 3, the shim MUST delete the new template and each container that the operation created.
- REQ-MUT-11: For a rename, the shim MUST keep the managed ID, remove the old container and the old template, and create the container under the new name.
- REQ-MUT-12: An operation for `deleteContainer` MUST do each of the following:
  - Stop the container and remove the container.
  - Delete the template.
  - Remove the line of the container from the autostart file.
  - Remove the registry entry.
- REQ-MUT-13: `deleteContainer` MUST keep the image and the host paths of the container.
- REQ-MUT-14: The API module MUST append one line to the audit log for each mutation. This rule covers a mutation that succeeds, a mutation that fails, and a mutation that the API module refuses.
- REQ-MUT-15: The API module MUST keep at most the last 1000 lines in the audit log.

## 10. Mounts

A bind mount whose host path is missing makes Docker create the directory on the root filesystem of the server, which lives in RAM. The container then writes its data into RAM.

- REQ-MNT-1: For each path config entry, the shim MUST check that the nearest existing ancestor of the host path is on a filesystem other than the root filesystem of the server.
- REQ-MNT-2: If the check fails, the operation MUST fail with an error that names the host path.
- REQ-MNT-3: The shim MUST call `xmlToCommand` with `create_paths` set to false.

## 11. Definitions, images, and what tofu owns

- REQ-DEF-1: A definition MUST hold every element that `postToXML` writes, except `DateInstalled` and the Tailscale elements.
- REQ-DEF-2: The API module MUST read each definition with `xmlToVar`.
- REQ-DEF-3: The provider and the tab MUST compare definitions and MUST NOT compare template files. Reason: `postToXML` rewrites `DateInstalled` on every write and changes how carriage returns are encoded (spike 1).
- REQ-DEF-4: An empty value in a config entry MUST mean the default of that config entry. Reason: `xmlToVar` reads an empty value as the default (spike 1).
- REQ-DEF-5: A definition MUST hold the autostart flag of the container.
- REQ-DEF-6: A definition MUST NOT hold whether the container runs. A person can start and stop a managed container in the webgui without drift.
- REQ-DEF-7: After the shim recreates a container, the shim MUST start the new container if and only if the old container ran. The shim MUST start a new container if and only if its autostart flag is on.
- REQ-DEF-8: The shim MUST write the autostart file `/var/lib/docker/unraid-autostart` in the format of the webgui, with one line per container.
- REQ-DEF-9: When the shim adds or removes a line of the autostart file, the shim MUST keep the order and the wait values of the other lines.
- REQ-DEF-10: The shim MUST write config entries in the type order path, port, variable, label, device, and MUST keep the order within each type.
- REQ-IMG-1: `Repository` MAY name a tag or an `@sha256:` digest.
- REQ-IMG-2: The plugin MUST NOT update images.
- REQ-IMG-3: The shim MUST pull an image through `DockerClient`, with the registry credentials of the server.

CA Auto Update moves the image of a container with a tag, if a person opts that container in. DockerMan's update check cannot parse a digest reference. A container with a digest therefore shows a blank update status, and CA Auto Update never updates that container (spike 1, read in the code).

## 12. Unraid upgrades

- REQ-UPG-1: Each plugin release MUST list its tested builds.
- REQ-UPG-2: A tested build MUST cover each webgui file that the shim loads.
- REQ-UPG-3: If the webgui files on the server match no tested build, the API module MUST refuse every mutation. The refusal MUST name each file that does not match.
- REQ-UPG-4: If the webgui files on the server match no tested build, the API module MUST keep answering queries.
- REQ-UPG-5: A maintainer MUST add a tested build only through a plugin release.
- REQ-UPG-6: The tab MUST NOT offer a switch that allows mutations on an untested build (decided 2026-09-28).

For each new Unraid release, a maintainer runs this procedure before a plugin release adds the tested build:

1. Check out the webgui at the tag of the new Unraid release.
2. Run the shim tests against that checkout.
3. Check that no official handler of the matching unraid-api requires `DOCKER:CREATE_ANY`.
4. Run the end-to-end procedure of section 18 on a server with the new Unraid release.
5. Add the tested build to the plugin, and release the plugin.

If step 2, 3, or 4 fails, do not add the tested build. Fix the plugin first, and start again at step 1.

## 13. The tab

- REQ-TAB-1: The plugin MUST add the tab with `Menu="Docker:2"` and the title `tofuman`.
- REQ-TAB-2: For each managed container, the tab MUST show the following facts:
  - The name and the managed ID.
  - The time of the last mutation, in UTC.
  - Whether the definition changed since the last mutation.
  - Whether the container runs.
- REQ-TAB-3: The tab MUST decide whether the definition changed since the last mutation by comparing the SHA-256 hash of the definition with the hash in the registry.
- REQ-TAB-4: For each hand-made container, the tab MUST offer the action **Adopt**.
- REQ-TAB-5: **Adopt** MUST add a registry entry with a new managed ID, write the marker into the template, and append a line to the audit log.
- REQ-TAB-6: **Adopt** MUST NOT recreate the container. The Docker label of the marker appears at the next recreate.
- REQ-TAB-7: **Adopt** MUST refuse a template that enables Tailscale.
- REQ-TAB-14: **Adopt** MUST refuse every adoption while the webgui files on the server match no tested build.
- REQ-TAB-15: **Adopt** MUST refuse a container that is not a DockerMan container or that has no template.
- REQ-TAB-16: **Adopt** MUST refuse a template whose `ExtraParams` or `PostArgs` needs the shell for more than a split into arguments: a separator, a redirection, a subshell, an expansion, a glob, or a comment. Reason: stored as quoted arguments (REQ-VAL-4), such text would change what the container runs.
- REQ-TAB-17: **Adopt** MUST refuse a template whose definition breaks REQ-VAL-20.
- REQ-TAB-8: The tab MUST let a person edit the policy, including the key allowlist.
- REQ-TAB-9: The tab MUST refuse to save a policy that the API module would reject as invalid.
- REQ-TAB-10: The tab MUST show the last 100 lines of the audit log.
- REQ-TAB-11: The tab MUST show whether the webgui files on the server match a tested build.
- REQ-TAB-12: The tab MUST NOT change stock webgui pages with JavaScript.
- REQ-TAB-13: The tab MUST read and write the plugin files on the flash drive through its own PHP endpoint, and MUST send the CSRF token of the webgui with each POST.

The tab does not show the OpenTofu address of a resource, because OpenTofu does not tell a provider that address (decided 2026-09-28). A person finds the resource by the container name.

## 14. The provider

- REQ-PRV-1: The provider MUST offer exactly one resource type, `tofuman_container`, in version 1.
- REQ-PRV-2: The ID of a `tofuman_container` resource MUST be the managed ID.
- REQ-PRV-3: `tofu import` MUST accept a container name.
- REQ-PRV-4: If the container is not a managed container, `tofu import` MUST fail with an error that names the container and asks for **Adopt** in the tab.
- REQ-PRV-5: The resource MUST expose the attributes and blocks of section 14.1.
- REQ-PRV-6: The provider MUST map each config entry of type variable with `Mask="true"` to a `secret` block.
- REQ-PRV-7: The provider MUST mark the value of each `secret` block as sensitive.
- REQ-PRV-8: A change of an attribute or a block MUST update the managed container in place, with the same managed ID.
- REQ-PRV-9: The provider MUST poll an operation until the operation ends, or until `operation_timeout` passes, whichever comes first.
- REQ-PRV-10: If a read finds no managed container for the managed ID, the provider MUST remove the resource from the state.
- REQ-PRV-11: The provider MUST NOT write the API key or the value of a `secret` block to a log.
- REQ-PRV-12: If `insecure` is false, the provider MUST verify the TLS certificate of the endpoint against the system roots and against `ca_certificate`, if `ca_certificate` is set.
- REQ-PRV-13: Before the plan, the provider MUST refuse each value that the shim rewrites instead of refusing. Reason: after such a rewrite, the state differs from the plan. The provider refuses these values:
  - A `shell` that is not exactly one of `sh` or `bash`.
  - A `mac_address` that is neither empty nor 6 lowercase pairs of hexadecimal digits joined by `:`.
  - A block with an empty value and a non-empty `default`.

### 14.1 Resource schema

Attributes of `tofuman_container`:

| Attribute | Type | Required | Default | Template element |
|---|---|---|---|---|
| `id` | string | computed | | managed ID |
| `name` | string | yes | | `Name` |
| `repository` | string | yes | | `Repository` |
| `network` | string | yes | | `Network` |
| `ip_addresses` | list of string | no | empty | `MyIP` |
| `mac_address` | string | no | empty | `MyMAC` |
| `autostart` | bool | no | false | autostart file |
| `privileged` | bool | no | false | `Privileged` |
| `cpuset` | string | no | empty | `CPUset` |
| `shell` | string | no | `sh` | `Shell` |
| `extra_params` | list of string | no | empty | `ExtraParams` |
| `post_args` | list of string | no | empty | `PostArgs` |
| `web_ui` | string | no | empty | `WebUI` |
| `icon` | string | no | empty | `Icon` |
| `overview` | string | no | empty | `Overview` |
| `category` | string | no | empty | `Category` |
| `support` | string | no | empty | `Support` |
| `project` | string | no | empty | `Project` |
| `read_me` | string | no | empty | `ReadMe` |
| `template_url` | string | no | empty | `TemplateURL` |
| `registry` | string | no | empty | `Registry` |
| `donate_text` | string | no | empty | `DonateText` |
| `donate_link` | string | no | empty | `DonateLink` |
| `requires` | string | no | empty | `Requires` |

Blocks of `tofuman_container`, each of which becomes one config entry:

| Block | Attributes | Config entry |
|---|---|---|
| `path` | `host_path`, `container_path`, `mode` (default `rw`) | type Path: value, target, mode |
| `port` | `host_port`, `container_port`, `protocol` (default `tcp`) | type Port: value, target, mode |
| `variable` | `key`, `value` | type Variable, mask false |
| `secret` | `key`, `value` (sensitive) | type Variable, mask true |
| `label` | `key`, `value` | type Label |
| `device` | `host_path` | type Device |

Each block also takes the optional attributes `display_name`, `description`, `display` (default `always`), `required` (default false), and `default`. `display_name` defaults to the target of the config entry, and for a `device` block to `host_path`, because a device entry has an empty target. `mode` of `path` takes exactly one of `rw`, `ro`, `rw,slave`, `rw,shared`, `ro,slave`, or `ro,shared`.

On macvlan, ipvlan, and host networks, DockerMan exports a `port` block as the variable `TCP_PORT_<container_port>` or `UDP_PORT_<container_port>` instead of a port mapping.

Configuration of the provider:

| Attribute | Environment variable | Required | Default |
|---|---|---|---|
| `endpoint` | `TOFUMAN_ENDPOINT` | yes | |
| `api_key` (sensitive) | `TOFUMAN_API_KEY` | yes | |
| `ca_certificate` | `TOFUMAN_CA_CERTIFICATE` | no | empty |
| `insecure` | `TOFUMAN_INSECURE` | no | false |
| `operation_timeout` | `TOFUMAN_OPERATION_TIMEOUT` | no | `30m` |

`endpoint` is the base URL of the server, for example `https://192.0.2.10`. The provider sends each request to the path `/graphql` under `endpoint`, with the API key in the header `x-api-key`. `ca_certificate` holds PEM text. `operation_timeout` takes a duration such as `90s`, `30m`, or `1h`.

## 15. GraphQL interface

The API module adds this schema to unraid-api. Each field of the schema carries the guard of REQ-AUTH-1.

```graphql
extend type Query {
  tofuman: TofumanQuery!
}

extend type Mutation {
  tofuman: TofumanMutation!
}

type TofumanQuery {
  "Exactly one of id or name."
  container(id: ID, name: String): TofumanContainer
  containers: [TofumanContainer!]!
  operation(id: ID!): TofumanOperation!
}

type TofumanMutation {
  createContainer(definition: TofumanDefinitionInput!): TofumanOperation!
  updateContainer(id: ID!, definition: TofumanDefinitionInput!): TofumanOperation!
  deleteContainer(id: ID!): TofumanOperation!
}

type TofumanContainer {
  "The managed ID."
  id: ID!
  definition: TofumanDefinition!
  running: Boolean!
  "ISO 8601, UTC."
  lastMutationAt: String
  changedSinceLastMutation: Boolean!
}

type TofumanOperation {
  id: ID!
  state: TofumanOperationState!
  "The step of section 9 that runs or that failed."
  step: String
  error: String
  "Set after a successful create or update."
  container: TofumanContainer
}

enum TofumanOperationState {
  QUEUED
  RUNNING
  SUCCEEDED
  FAILED
}

type TofumanDefinition {
  name: String!
  repository: String!
  network: String!
  ipAddresses: [String!]!
  macAddress: String!
  autostart: Boolean!
  privileged: Boolean!
  cpuset: String!
  shell: String!
  extraParams: [String!]!
  postArgs: [String!]!
  webUi: String!
  icon: String!
  overview: String!
  category: String!
  support: String!
  project: String!
  readMe: String!
  templateUrl: String!
  registry: String!
  donateText: String!
  donateLink: String!
  requires: String!
  "Without the marker."
  configEntries: [TofumanConfigEntry!]!
}

input TofumanDefinitionInput {
  name: String!
  repository: String!
  network: String!
  ipAddresses: [String!]!
  macAddress: String!
  autostart: Boolean!
  privileged: Boolean!
  cpuset: String!
  shell: String!
  extraParams: [String!]!
  postArgs: [String!]!
  webUi: String!
  icon: String!
  overview: String!
  category: String!
  support: String!
  project: String!
  readMe: String!
  templateUrl: String!
  registry: String!
  donateText: String!
  donateLink: String!
  requires: String!
  configEntries: [TofumanConfigEntryInput!]!
}

enum TofumanConfigType {
  PATH
  PORT
  VARIABLE
  LABEL
  DEVICE
}

type TofumanConfigEntry {
  type: TofumanConfigType!
  name: String!
  target: String!
  value: String!
  default: String!
  mode: String!
  description: String!
  display: String!
  required: Boolean!
  mask: Boolean!
}

input TofumanConfigEntryInput {
  type: TofumanConfigType!
  name: String!
  target: String!
  value: String!
  default: String!
  mode: String!
  description: String!
  display: String!
  required: Boolean!
  mask: Boolean!
}
```

## 16. Files on the flash drive

The plugin keeps its files in `/boot/config/plugins/tofuman/`. Every file is UTF-8 JSON.

### 16.1 The registry

```json
{
  "version": 1,
  "containers": {
    "8f1c2d3e-0000-4000-8000-000000000000": {
      "name": "example",
      "createdAt": "2026-09-28T12:00:00Z",
      "lastMutationAt": "2026-09-28T12:05:00Z",
      "definitionHash": "sha256:<64 hexadecimal digits>"
    }
  }
}
```

`definitionHash` is the SHA-256 hash of the definition after the last mutation, in the canonical JSON form of RFC 8785.

### 16.2 The policy

```json
{
  "version": 1,
  "keyAllowlist": [],
  "bindRoots": ["/mnt/user/appdata/"],
  "networks": ["bridge"],
  "extraParamFlags": [],
  "exceptions": {
    "example": { "privileged": false, "hostNetwork": false, "devices": [] }
  }
}
```

- REQ-FILE-1: On install, the plugin MUST create the policy with the values above and without the `example` exception, if no policy exists.
- REQ-FILE-2: Each entry of `bindRoots` MUST be an absolute path that ends with `/`.
- REQ-FILE-3: The plugin MUST NOT change the policy on update or removal.

### 16.3 The audit log

Each line is one JSON object:

```json
{"time":"2026-09-28T12:05:00Z","caller":"<API key ID, or webgui, or cli>","callerName":"<API key name>","action":"updateContainer","managedId":"8f1c2d3e-0000-4000-8000-000000000000","name":"example","result":"succeeded","error":null}
```

`action` is exactly one of `createContainer`, `updateContainer`, `deleteContainer`, or `adopt`. `result` is exactly one of `succeeded`, `failed`, or `refused`.

## 17. Packaging and installation

How an API module stays installed across a reboot is undocumented. `unraid-api plugins install` edits `package.json` in RAM, and at each start unraid-api replaces `node_modules` from an archive on the flash drive. Spike 2 (section 19) settles the install route.

- REQ-PKG-1: One `.plg` file MUST install the plugin.
- REQ-PKG-2: After a reboot of the server, unraid-api MUST load the API module without an action of a person.
- REQ-PKG-3: After an update of Unraid OS, unraid-api MUST load the API module. If unraid-api does not load the API module, the tab MUST show that fact.
- REQ-PKG-4: The API module MUST declare `@nestjs/common`, `@nestjs/graphql`, `graphql`, and `@unraid/shared` as peer dependencies with version ranges, and MUST NOT bundle them. Reason: unraid-api 4.37.4 moved to newer versions of NestJS and of Apollo Server.
- REQ-PKG-5: The API module MUST load on unraid-api 4.35.1 and on unraid-api 4.37.4.
- REQ-PKG-6: The `.plg` file MUST install the shim and the tab under `/usr/local/emhttp/plugins/tofuman/`.
- REQ-PKG-7: On removal, the `.plg` file MUST remove the API module from unraid-api.
- REQ-PKG-8: On removal, the `.plg` file MUST keep the registry, the policy, the audit log, the templates, and the containers.
- REQ-PKG-9: Each release MUST build the provider for `linux/amd64`, `linux/arm64`, `darwin/arm64`, and `windows/amd64`.

## 18. Testing

- REQ-TST-1: GitHub Actions MUST run the tests of the shim, of the API module, and of the provider on each push to `main`.
- REQ-TST-2: The shim tests MUST run in `php-cli` 8.3 against the webgui source of each tested build. Unraid 7.2 ships PHP 8.3 (release notes 7.2.5 and 7.2.7), and no release note of Unraid 7.3 changes that version.
- REQ-TST-3: The shim tests MUST cover template round trips, validation, the policy, and the commands from `xmlToCommand`.
- REQ-TST-4: The shim tests MUST run each generated `docker create` on the Docker of the test runner, with a stand-in image. The shim tests MUST compare the output of `docker inspect` with the definition.
- REQ-TST-5: The shim tests MUST NOT start a container.
- REQ-TST-6: The API module tests MUST run the real shim, and MUST NOT use mocks.
- REQ-TST-7: The provider tests MUST run against a test server that serves the schema of section 15. The test server MUST use the real API module service and the real shim, on the Docker of the test runner.
- REQ-TST-8: Before each release, a person MUST run the end-to-end procedure of this section on a server.
- REQ-TST-9: The provider tests MUST run the `tofu` binary of one pinned OpenTofu release (spike 4).

End-to-end procedure. Preconditions: the plugin is installed on the server, an API key with only `DOCKER:CREATE_ANY` is on the key allowlist, and a directory for throwaway containers exists under a bind root.

1. Write an HCL file with one throwaway container that uses a path, a port, a variable, a secret, and a label.
2. Run `tofu apply`.
3. In the webgui, check that the container shows **Edit** and **Update** and runs.
4. In the webgui, change the variable with **Edit**, and select **Apply**.
5. Run `tofu plan`. The plan shows the change of the variable as drift.
6. Run `tofu apply`. The variable returns to the value in the HCL file.
7. Change the name in the HCL file, and run `tofu apply`. The tab shows the same managed ID under the new name.
8. Run `tofu destroy`. The container and its template are gone.
9. Create a throwaway hand-made container in the webgui, and select **Adopt** for it in the tab.
10. Run `tofu import` with the name of that container, and write matching HCL. `tofu plan` shows no change.
11. Check that the audit log shows each mutation and the adoption.

If a step fails, keep the throwaway containers, copy the audit log, and do not release.

## 19. Spikes

- Spike 1, 2026-09-28, **go** ([`spikes/2026-09-28-dockerman-helpers-off-unraid`](../spikes/2026-09-28-dockerman-helpers-off-unraid/README.md)): the DockerMan helpers load in `php-cli` with `_var()` and four globals stubbed. 17 of 17 real templates are a fixed point of `xmlToVar`, `postToXML`, `xmlToVar`. 16 of 17 generated commands created a matching container on a plain Docker host, and the 17th needs the nvidia runtime.
- Spike 2, open: find an install route for the API module that survives a reboot and an update of Unraid OS (REQ-PKG-2, REQ-PKG-3). A person runs spike 2 on a server. The candidate route: at each boot, the `.plg` file copies the API module into `node_modules`, adds it to `peerDependencies` and to `api.json`, and runs `rc.unraid-api archive-dependencies`.
- Spike 3, open: find out whether a stylesheet with `:has()` on `input.autostart[container=NAME]` can mark managed containers in the stock container table (deferred, section 21).
- Spike 4, 2026-09-28, **go** ([`spikes/2026-09-28-plugin-testing-on-opentofu`](../spikes/2026-09-28-plugin-testing-on-opentofu/README.md)): terraform-plugin-testing runs OpenTofu 1.12.6 through a create, an import by name, an update in place, and a read that drops a vanished resource. The run needs `TF_ACC_PROVIDER_HOST=registry.opentofu.org` and `TF_ACC_PROVIDER_NAMESPACE`, and without them `tofu init` fails.

## 20. Open items

- Whether `/etc/nginx/nginx.conf` on the server sets a longer proxy timeout is unverified. The design does not depend on it.
- How a person moves a digest to a newer image is undecided. Assumption: by hand in HCL.
- Adoption of a template that came straight from Community Applications drops the elements that `postToXML` does not write. How the tab reports that loss is undecided.
- Whether Unraid Connect's flash backup uploads the plaintext API key files is unverified.
- Whether the free text that `configureUps` writes reaches command execution is untraced.
- Whether the webgui **Update** action works on a container with a digest is untested.
- The URL from which the `.plg` file installs the plugin is undecided. Assumption: the assets of a GitHub release.
- How the provider reaches OpenTofu before a registry lists it is undecided. Assumption: a local filesystem mirror, filled from the assets of a GitHub release.

## 21. Deferred

- A listing in Community Applications and in an OpenTofu provider registry. Both wait until tofuman survives an Unraid upgrade or two.
- The start order of autostart containers and the wait values.
- Tailscale and `<ExtraNetworks>`.
- A badge on managed containers in the stock container table, after spike 3.

## 22. License

- REQ-LIC-1: Everything in the repository MUST carry GPL-2.0-or-later, except `sources/provider/`, which MUST carry MIT (decided 2026-09-28).
- REQ-LIC-2: Every source file MUST start with an `SPDX-License-Identifier` line.

The plugin runs inside the GPL code of Unraid: the shim loads the GPLv2 helpers of the webgui into its own process, and the API module loads into unraid-api and imports `@unraid/shared`, both GPL-2.0-or-later. The provider talks to the plugin over the network only, so the provider carries MIT and takes that license along when it moves to a repository of its own. `LICENSE` holds the verbatim GPL-2.0 text. The README and the SPDX headers state the grant of later versions.

## 23. References

Read 2026-09-27 and 2026-09-28, pinned. unraid/webgui at tag `7.3.2` ([`369f0b2`](https://github.com/unraid/webgui/tree/369f0b2994584a6b6c8e6e570f5f86401d289234)). unraid/api `v4.35.1` ([`a9625ae`](https://github.com/unraid/api/tree/a9625ae20a589e739926923b28ca7efe14233372)), which Unraid 7.3.2 ships, `v4.37.4` ([`ad26830`](https://github.com/unraid/api/tree/ad268301ca78da1fa47fd3bb87e60fcedc458c5b)), which Unraid 7.3.3 ships, and `main` ([`d061525`](https://github.com/unraid/api/tree/d0615255e0ce062f7a8262e560f963d07f311539)).

- `ExtraParams` and `PostArgs` go into the `docker create` string raw ([Helpers.php L565-566](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L565-L566)), the name sits raw inside double quotes ([L430](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L430)), and `execCommand` runs the result through `popen`, which is `/bin/sh -c` ([L714](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L714)). Missing host paths get created only when `xmlToCommand` gets `create_paths` ([L518-519](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L518-L519)).
- Only `dockerman` gets a template and an update status. Every other value of `net.unraid.docker.managed` nulls both ([DockerClient.php L415-417](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L415-L417)).
- Docker keeps the last `-l` for a key ([docker/cli parse.go L42-47](https://github.com/docker/cli/blob/7fc2dff9bceb96b266a3b2c3117c0955a0d9e616/opts/parse.go#L42-L47)).
- `postToXML` writes only the known elements and the 9 known Config attributes ([Helpers.php L124-204](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L124-L204)), and `xmlToVar` reads an unknown network as `none` and an empty value as the default ([L206-372](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/Helpers.php#L206-L372)).
- Writers pick the template file by `my-<Name>.xml` ([DockerClient.php L142-156](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L142-L156)), and readers match the `<Name>` element inside the file.
- `$driver` comes from `DockerUtil::driver()` at the file scope of DockerClient.php ([L38-39](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L38-L39)), so the shim requires that file at its top level. `update_container` sets up `$var`, `$subnet`, and the other globals ([L15-32](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/scripts/update_container#L15-L32)), and starts a recreated container only if the old container ran ([L169-181](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/scripts/update_container#L169-L181)). `rebuild_container` never sets `$var` ([L22-34](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/scripts/rebuild_container#L22-L34)).
- The autostart file has one line per container, `name` or `name wait` ([UpdateConfig.php L22-55](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/UpdateConfig.php#L22-L55)).
- DockerMan's image parser splits a digest reference into repository `name@sha256` and tag `<hex>` ([DockerClient.php L1138-1170](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L1138-L1170)).
- The nginx location for `/graphql` sets no proxy timeout ([rc.nginx L427-438](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/etc/rc.d/rc.nginx#L427-L438)), so the nginx default of 60 seconds applies.
- `unraid-api plugins install` runs `npm i --save-peer --save-exact` in the unraid-api directory ([plugin-management.service.ts L88-96](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/plugin/plugin-management.service.ts#L88-L96)), and each start of unraid-api replaces `node_modules` from the archive, if an archive exists ([dependencies.sh L129-152](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/plugin/source/dynamix.unraid.net/usr/local/share/dynamix.unraid.net/scripts/dependencies.sh#L129-L152)).
- The shared decorator rejects resources outside the fixed list ([use-permissions.directive.ts L93-97](https://github.com/unraid/api/blob/d0615255e0ce062f7a8262e560f963d07f311539/packages/unraid-shared/src/use-permissions.directive.ts#L93-L97)). API 4.35.1 registers nest-authz's `AuthZGuard` ([app.module.ts L63-72](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/app/app.module.ts#L63-L72)), which lets through each handler without permission metadata ([authz.guard.ts L37-39](https://github.com/apache/casbin-nest-authz/blob/8cb1097dff90e4585670db49fcec74bc69ad982b/src/authz.guard.ts#L37-L39)). API 4.37.4 denies such handlers ([3ec4764](https://github.com/unraid/api/commit/3ec47647879a02bd45d55ca0e2bca987b1ff0d27)).
- `configureUps` has no permission metadata in API 4.35.1 ([ups.resolver.ts L83-84](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/graph/resolvers/ups/ups.resolver.ts#L83-L84)) and writes its input into `apcupsd.conf` unfiltered ([ups.service.ts L344-387](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/graph/resolvers/ups/ups.service.ts#L344-L387)).
