# tofuman specification

Status: draft, 2026-09-28. A design interview on 2026-09-27, spike 1 on 2026-09-28, and a spec review on 2026-09-28 decided the requirements below. Two rounds of questions on 2026-10-04 added the checks at plan time, the start check, the operation log, the notifications, the diagnostics file, the badge, and the rework of the tab. Section 20 lists what is still open.

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

### operation log (noun)
The file `/boot/config/plugins/tofuman/operations.jsonl`, with one JSON line per operation that ended.
Do not use: job log, history, operation history.

### start check (noun)
The wait after the shim starts a container in an operation, in which the shim watches whether the container keeps running.
Do not use: health check (Docker uses that name for a different test), crash check, liveness check.

### policy gap (noun)
One entry that a definition needs from the policy and that the policy lacks: a bind root, a network, an `ExtraParams` flag, or an exception.
Do not use: missing permission, policy need, violation.

### drift (noun)
A difference between the definition in the OpenTofu state and the definition that the API module reads from the template.
Do not use: divergence, out-of-band change.

### tested build (noun)
One set of SHA-256 hashes of the webgui files that the shim loads. Each plugin release lists the tested builds that its tests passed against.
Do not use: pin, supported version, known-good hashes.

### audit log (noun)
The file `/boot/config/plugins/tofuman/audit.jsonl`, with one JSON line per mutation and per adoption.
Do not use: log, history, journal.

### candidate release (noun)
A plugin release that the release workflow publishes as a GitHub pre-release, before a person has run the end-to-end procedure of section 18 with it.
Do not use: beta, pre-release (GitHub's word for the flag), draft.

### throwaway container (noun)
A container that exists only for a test, and that the tester removes after the test.
Do not use: test container, scratch container.

### diagnostics file (noun)
The JSON file that the tab offers for download, with the facts that a maintainer needs to find the cause of a failure.
Do not use: support bundle, dump, report.

### badge (noun)
The mark that the plugin adds beside the name of each managed container on the **Docker** page of the webgui.
Do not use: tag, label (Docker uses that name), icon.

### e2e tool (noun)
The program `tofuman-e2e`, which runs the end-to-end procedure of section 18 against one server and asks a person for the steps in the webgui.
Do not use: e2e script, test runner, harness.

### adoption test template (noun)
The template `tofuman-adoption-test.xml` that the plugin ships, from which a person creates the hand-made container of step 11 of the end-to-end procedure.
Do not use: test template, sample template.

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

1. The provider sends GraphQL requests over HTTPS or HTTP to the path `/graphql` on the server, with an API key. Over HTTP, the API key crosses the network in plain text.
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
- REQ-VAL-6: The API module MUST refuse an `ExtraParams` flag that the policy list `extraParamFlags` does not contain. The flag `--ipc` is the exception, because REQ-POL-11 governs it.
- REQ-VAL-7: The API module MUST refuse each `ExtraParams` flag in the following groups, even if the policy lists the flag:
  - Mounts: `-v`, `--volume`, `--mount`, `--volumes-from`.
  - Privilege: `--privileged`, `--cap-add`, `--security-opt`, `--device`, `--cgroup-parent`.
  - Namespaces: `--pid`, `--uts`, `--userns`.
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
- REQ-VAL-21: The API module MUST refuse an `--ipc` flag whose value is not `host` (added 2026-10-04 with REQ-POL-11).

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
- REQ-POL-9: A query MUST NOT fail because of the policy. The query `check` returns the failed checks of the policy as its answer (changed 2026-10-04 for the checks at plan time of REQ-PRV-15).
- REQ-POL-10: A change to the policy MUST NOT change an existing container. The API module applies the new policy at the next mutation of each container.
- REQ-POL-11: The API module MUST refuse `--ipc host`, unless the policy lists the container in `exceptions` with `"ipcHost": true` (decided 2026-10-04). Reason: some GPU workloads document `--ipc host` as their way to share memory, and the host IPC namespace is as much a decision of a person as the host network.

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
7. If step 6 started the container, the shim runs the start check.
8. The API module updates the registry and appends a line to the audit log.

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

The start check, decided 2026-10-04: a fresh build that dies at its start fails the apply, so that `tofu apply` never reports a container that does not run.

- REQ-MUT-16: `createContainer` and `updateContainer` MUST take the length of the start check in seconds, from 0 to 600. The default length MUST be 10 seconds.
- REQ-MUT-17: If the length of the start check is 0, the shim MUST skip the start check.
- REQ-MUT-18: The start check MUST fail if the container stops or restarts before the length of the start check passes.
- REQ-MUT-19: A failed start check MUST fail the operation at the step `start check`, and REQ-MUT-9 and REQ-MUT-10 apply. The error MUST name the exit code of the container and MUST hold the last 30 lines of the log of the container.

The operation log and the notifications, decided 2026-10-04 so that a person and a maintainer can find the cause of a failure:

- REQ-MUT-20: When an operation ends, the shim MUST append one line to the operation log (section 16.4).
- REQ-MUT-21: The shim MUST keep at most the last 200 lines in the operation log.
- REQ-MUT-22: While an operation runs, the shim MUST keep the line of that operation, with the steps so far, in `/var/run/tofuman-operation.json`. When the operation ends, the shim MUST delete that file.
- REQ-MUT-23: When an operation fails, the shim MUST raise an Unraid notification with the importance warning. The notification MUST name the container, the mutation, and the step, and MUST link to the **Docker** page, which holds the tab.
- REQ-MUT-24: A failure to raise a notification MUST NOT change the result of the operation.
- REQ-MUT-25: The query `check` MUST run the checks of REQ-MUT-2 without a mutation, and MUST NOT append a line to the audit log.
- REQ-MUT-26: When the shim removes a container, the shim MUST NOT delete the default icon of DockerMan. Reason: the webgui at tag `7.3.2` records that shared icon for each container without an icon of its own, and `removeContainer` then deletes the recorded icon from RAM until the next boot (found 2026-10-04; the webgui guards that icon from tag `7.3.3-rc.1` on).

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

Decided 2026-10-04: tofu does not move the image of a tag, not even while a person tests a fresh build. A new build of a tag reaches a managed container through **Update** or CA Auto Update. To make tofu create a container again, a person runs `tofu apply -replace` on the resource, which deletes the container and creates it with a new managed ID.

## 12. Unraid upgrades

- REQ-UPG-1: Each plugin release MUST list its tested builds.
- REQ-UPG-2: A tested build MUST cover each webgui file that the shim loads.
- REQ-UPG-3: If the webgui files on the server match no tested build, the API module MUST refuse every mutation. The refusal MUST name each file that does not match.
- REQ-UPG-4: If the webgui files on the server match no tested build, the API module MUST keep answering queries.
- REQ-UPG-5: A maintainer MUST add a tested build only through a plugin release.
- REQ-UPG-6: The tab MUST NOT offer a switch that allows mutations on an untested build (decided 2026-09-28).
- REQ-UPG-7: When unraid-api loads the API module and the webgui files match no tested build, the API module MUST raise one Unraid notification with the importance alert. The notification MUST say that tofuman refuses every mutation until a plugin release covers the Unraid release (added 2026-10-04).

Research 2026-10-04: no stable Unraid release after 7.3.2 exists yet. The webgui tag `7.3.3-rc.2` changes one file of the tested build, `DockerClient.php`, where `removeContainer` stops deleting the default icon (REQ-MUT-26). unraid-api 4.37.4 loads plugins as 4.35.1 does, with the same global `ValidationPipe`. A tested build for 7.3.3 therefore needs new hashes only.

For each new Unraid release, a maintainer runs this procedure:

1. Check out the webgui at the tag of the new Unraid release.
2. Run the shim tests against that checkout.
3. Check that no official handler of the matching unraid-api requires `DOCKER:CREATE_ANY`.
4. Add the tested build to the plugin, and publish a candidate release (section 17).
5. On a server with the new Unraid release, a person installs the candidate release and runs the end-to-end procedure of section 18.
6. Promote the candidate release.

If step 2, 3, or 5 fails, do not promote the candidate release. Fix the plugin first, and start again at step 1.

## 13. The tab

- REQ-TAB-1: The plugin MUST add the tab with `Menu="Docker:2"` and the title `tofuman`.
- REQ-TAB-2: For each managed container, the tab MUST show the following facts:
  - The name, the icon, and the managed ID.
  - The image, and the network with its addresses.
  - A link to the WebUI, if the definition has a `WebUI` value, with the placeholders filled in as the **Docker** page fills them.
  - The time of the last mutation.
  - Whether the definition changed since the last mutation.
  - Whether the container runs.
- REQ-TAB-3: The tab MUST decide whether the definition changed since the last mutation by comparing the SHA-256 hash of the definition with the hash in the registry.
- REQ-TAB-4: The tab MUST let a person select hand-made containers, and MUST offer the action **Adopt** for the selection.
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

The tab does not show the OpenTofu address of a resource, because OpenTofu does not tell a provider that address (decided 2026-09-28). A person finds the resource by the container name, and the `import` blocks of REQ-TAB-25 propose an address.

The rework of 2026-10-04 makes the tab the place where a person moves containers into tofu and finds out why a mutation failed:

- REQ-TAB-18: The tab MUST show each time as the time since that moment, and MUST show the UTC time when a pointer rests on it.
- REQ-TAB-19: At the top, the tab MUST show the version of the plugin, the result of REQ-TAB-11, the load state of the API module (REQ-PKG-3), and the number of API keys on the key allowlist.
- REQ-TAB-20: The tab MUST use the table styles and the status styles of the webgui, and MUST follow the theme that the webgui uses.
- REQ-TAB-21: The tab MUST refresh its facts every 30 seconds, and every 5 seconds while an operation runs.
- REQ-TAB-22: While an operation runs, the tab MUST show the container, the mutation, and the step that runs.
- REQ-TAB-23: For each hand-made container, the tab MUST show whether **Adopt** would succeed, and MUST name each check that would refuse the adoption.
- REQ-TAB-24: For a selection of several hand-made containers, the plugin MUST adopt each container by REQ-TAB-5 to REQ-TAB-17, with one line in the audit log for each container.
- REQ-TAB-25: For each managed container, the tab MUST offer an OpenTofu `import` block that names the container. The tab MUST also offer one text with the `import` blocks of all managed containers. Each block MUST name the resource `tofuman_container.<label>`, where `<label>` is the container name in lowercase, with each character outside `a-z`, `0-9`, and `_` replaced by `_`, and with `_` in front of a leading digit.
- REQ-TAB-26: The tab MUST list each policy gap of each managed container.
- REQ-TAB-27: The tab MUST also list each policy gap of the last 20 containers whose mutation or query `check` the policy refused, and MUST leave out each gap that the policy has closed since. To serve that list, the shim MUST keep those policy gaps in `/var/run/tofuman-gaps.json`.
- REQ-TAB-28: The tab MUST let a person copy a selection of the listed policy gaps into the policy editor. The tab MUST NOT save the policy without the action **Save policy** of a person.
- REQ-TAB-29: For a host path outside every bind root, the policy gap MUST propose the directory that the first three segments of the host path name, for example `/mnt/user/appdata/` for `/mnt/user/appdata/example/config`.
- REQ-TAB-30: For each line of the audit log that names an operation, the tab MUST show the line of that operation from the operation log, on request.
- REQ-TAB-31: The tab MUST show, for the last 7 days, the number of mutations that succeeded, that failed, and that the API module refused, and the median duration of an operation.
- REQ-TAB-32: The tab MUST offer the diagnostics file for download (section 16.5).

The first end-to-end run with the reworked tab changed its layout (decided 2026-10-05):

- REQ-TAB-36: The tab MUST show the hand-made containers in a section that a person can open and close. The tab MUST show the policy in a second such section. Both sections MUST be closed when the tab loads.
- REQ-TAB-37: The heading of the hand-made section MUST show the number of hand-made containers. The heading of the policy section MUST show the number of entries that the listed policy gaps would add, and the number of errors in the saved policy, each when it is above zero.
- REQ-TAB-38: The tab MUST place each button that acts on more than one row of a table below that table, as the **Docker** page does.
- REQ-TAB-39: The tab MUST wrap the changes and the error of each line of the audit log inside their cells. Each of the two cells MUST show at most three lines of text, and MUST show its full text when a pointer rests on it.
- REQ-TAB-40: The tab MUST mark each line of the audit log that names an operation, and MUST highlight the line whose operation it shows (REQ-TAB-30).
- REQ-TAB-41: When a pointer rests on the operation of REQ-TAB-30 or on the `import` block of one managed container, the tab MUST NOT highlight it. Reason: neither reacts to a click.
- REQ-TAB-42: The hand-made section MUST link to the **Add Container** page of the webgui with the adoption test template filled in. The plugin MUST NOT put the adoption test template into the user templates of DockerMan. Reason: the template list of a person stays as that person left it.
- REQ-TAB-43: The adoption test template MUST name the image `busybox:latest`, the network `bridge`, a command that keeps the container running, one variable, one label, and an icon of its own, and MUST NOT name a host path. Reason for the icon: on webgui 7.3.2, the removal of a container without an icon deletes the default icon of DockerMan (REQ-MUT-26), and an open **Docker** page then requests that icon in a loop, whose errors filled `/var/log` on a server on 2026-10-05.

### 13.1 The badge

The badge lets a person see on the **Docker** page which containers tofu manages (added 2026-10-04, after spike 3).

- REQ-TAB-33: The plugin MUST show the badge beside the name of each managed container on the **Docker** page.
- REQ-TAB-34: The plugin MUST draw the badge with CSS alone. Reason: REQ-TAB-12.
- REQ-TAB-35: The badge MUST NOT hide, move, or cover a control of the **Docker** page.

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

Added 2026-10-04, so that a refusal shows in the plan and a failure explains itself:

- REQ-PRV-14: The provider MUST send its version in the header `x-tofuman-provider` with each request.
- REQ-PRV-15: During the plan, for each `tofuman_container` that the plan creates, updates, or deletes, the provider MUST ask the query `check` for the failed checks of that mutation. The provider MUST show each failed check as an error of the plan.
- REQ-PRV-16: If a value of the definition is unknown during the plan, the provider MUST skip the query `check` for that resource.
- REQ-PRV-17: The provider MUST log each request to the API module with its duration, and each state of each operation that it polls, at the level `DEBUG` of the OpenTofu log.
- REQ-PRV-18: The error of a failed operation MUST name the operation ID, the step, and the error of the operation. If a refusal names a policy gap, the error MUST say that a person changes the policy in the tab.
- REQ-PRV-19: The provider MUST send the length of `start_check` with each `createContainer` and each `updateContainer` whose `start_check` differs from the default of 10 seconds. Reason: a plugin from before the start check refuses the argument, and the default needs no argument.
- REQ-PRV-20: If a plan changes only `start_check`, the provider MUST change the state without a mutation. Reason: the length of the start check is not part of the template.

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
| `start_check` | string | no | `10s` | none: each mutation carries it (REQ-MUT-16) |

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

`start_check` takes a whole number of seconds as a duration from `0s` to `10m`, for example `30s`. The value `0s` turns the start check off, for a container that ends on its own.

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
  "The checks of a mutation, without the mutation: a definition alone checks createContainer, an id and a definition check updateContainer, and an id alone checks deleteContainer."
  check(id: ID, definition: TofumanDefinitionInput): TofumanCheck!
}

type TofumanMutation {
  createContainer(definition: TofumanDefinitionInput!, startCheckSeconds: Int! = 10): TofumanOperation!
  updateContainer(id: ID!, definition: TofumanDefinitionInput!, startCheckSeconds: Int! = 10): TofumanOperation!
  deleteContainer(id: ID!): TofumanOperation!
}

type TofumanCheck {
  "Each failed check. An empty list means that the API module would accept the mutation."
  failedChecks: [String!]!
  "How many of the failed checks are policy gaps."
  policyGaps: Int!
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

A refusal of a mutation carries the failed checks in the extension `errors` and the number of policy gaps among them in the extension `policyGaps`, next to the code `TOFUMAN_REFUSED`.

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
    "example": { "privileged": false, "hostNetwork": false, "ipcHost": false, "devices": [] }
  }
}
```

- REQ-FILE-1: On install, the plugin MUST create the policy with the values above and without the `example` exception, if no policy exists.
- REQ-FILE-2: Each entry of `bindRoots` MUST be an absolute path that ends with `/`.
- REQ-FILE-3: The plugin MUST NOT change the policy on update or removal.

### 16.3 The audit log

Each line is one JSON object:

```json
{"time":"2026-09-28T12:05:00Z","caller":"<API key ID, or webgui, or cli>","callerName":"<API key name>","providerVersion":"0.2.0","action":"updateContainer","managedId":"8f1c2d3e-0000-4000-8000-000000000000","name":"example","result":"succeeded","error":null,"operationId":"5b0e7a51-0000-4000-8000-000000000000","durationMs":4120,"changes":["repository","variable GREETING"]}
```

`action` is exactly one of `createContainer`, `updateContainer`, `deleteContainer`, or `adopt`. `result` is exactly one of `succeeded`, `failed`, or `refused`. `providerVersion` is the value of the header `x-tofuman-provider`, or null. `operationId` and `durationMs` are null for an adoption and for a refusal.

- REQ-FILE-4: The audit line of an `updateContainer` MUST name each field and each config entry that the mutation changes in `changes`, and `changes` MUST be empty for the other actions. A config entry goes by its type and its target, for example `variable GREETING`.
- REQ-FILE-5: `changes` MUST NOT hold a value.

### 16.4 The operation log

Each line is one JSON object, added 2026-10-04:

```json
{"id":"5b0e7a51-0000-4000-8000-000000000000","mutation":"updateContainer","caller":"<API key ID>","callerName":"<API key name>","providerVersion":"0.2.0","managedId":"8f1c2d3e-0000-4000-8000-000000000000","name":"example","queuedAt":"2026-10-04T12:00:00Z","startedAt":"2026-10-04T12:00:01Z","endedAt":"2026-10-04T12:00:16Z","durationMs":15020,"result":"failed","step":"start check","error":"<the error of the operation>","changes":["variable GREETING"],"steps":[{"step":"pull","startedAt":"2026-10-04T12:00:01Z","ms":2100,"digest":"sha256:<64 hexadecimal digits>"},{"step":"create","startedAt":"2026-10-04T12:00:04Z","ms":400,"command":"docker create <arguments>","output":"<container ID>"},{"step":"start check","startedAt":"2026-10-04T12:00:05Z","ms":3000,"exitCode":3,"log":["<log line>"]}]}
```

`result` is exactly one of `succeeded` or `failed`. `step` and `error` are null for an operation that succeeded. Each entry of `steps` names one step of section 9, with the time it started and its duration in milliseconds, and holds the facts of that step: the digest after a pull, the command and its output for a create, and the exit code and the log lines of a failed start check.

- REQ-FILE-6: The command in the operation log MUST show the value of each config entry with mask true as `***`.
- REQ-FILE-7: Elsewhere in the operation log, in the audit log, and in the error of an operation, the shim MUST show each occurrence of the value of a config entry with mask true as `***`, if that value has at least 4 characters. Reason: the log lines of a container are free text, and a shorter value would turn ordinary words into `***`.

### 16.5 The diagnostics file

The diagnostics file holds the following facts, added 2026-10-04:

- The versions of Unraid and of the plugin, and the load record of the API module (REQ-PKG-15).
- The result of REQ-TAB-11, with each webgui file that does not match.
- The policy, the registry, the audit log, and the operation log.
- The definition of each managed container, and the networks on the server.
- The policy gaps of REQ-TAB-26 and REQ-TAB-27.
- The last 200 lines of the log of unraid-api that name tofuman.

- REQ-FILE-8: The diagnostics file MUST NOT hold an API key, and MUST show the value of each config entry with mask true as `***`.

## 17. Packaging and installation

How an API module stays installed across a reboot is undocumented. unraid-api loads a plugin only if the plugin is in the `plugins` list of `api.json` on the flash drive and in the dependencies of `package.json`. `package.json` lives in RAM and returns to its shipped state at each boot. At each start, `rc.unraid-api` replaces `node_modules` from the vendor archive on the flash drive, if that archive exists. `rc.local` installs the plugins before emhttp starts unraid-api. These facts come from the code (read 2026-09-28), and spike 2 (section 19) checks them on a server.

- REQ-PKG-1: One `.plg` file MUST install the plugin.
- REQ-PKG-2: After a reboot of the server, unraid-api MUST load the API module without an action of a person.
- REQ-PKG-3: After an update of Unraid OS, unraid-api MUST load the API module. If unraid-api does not load the API module, the tab MUST show that fact.
- REQ-PKG-4: The API module MUST declare `@nestjs/common`, `@nestjs/graphql`, `graphql`, and `@unraid/shared` as peer dependencies with version ranges, and MUST NOT bundle them. Reason: unraid-api 4.37.4 moved to newer versions of NestJS and of Apollo Server.
- REQ-PKG-5: The API module MUST load on unraid-api 4.35.1 and on unraid-api 4.37.4.
- REQ-PKG-6: The `.plg` file MUST install the shim and the tab under `/usr/local/emhttp/plugins/tofuman/`.
- REQ-PKG-7: On removal, the `.plg` file MUST remove the API module from unraid-api.
- REQ-PKG-8: On removal, the `.plg` file MUST keep the registry, the policy, the audit log, the templates, and the containers.
- REQ-PKG-9: Each release MUST build the provider for `linux/amd64`, `linux/arm64`, `darwin/arm64`, and `windows/amd64`.
- REQ-PKG-28: Each provider release MUST also build the e2e tool for the platforms of REQ-PKG-9, as one zip per platform beside the zips of the provider, with a SHA256SUMS file of its own (added 2026-10-05).
- REQ-PKG-10: At each run, the install script of the `.plg` file MUST copy the API module into `node_modules` of unraid-api.
- REQ-PKG-11: At each run, the install script MUST add the API module to the peer dependencies in `package.json` of unraid-api.
- REQ-PKG-12: If the `plugins` list of `api.json` lacks the API module, the install script MUST add the API module to that list.
- REQ-PKG-13: If the vendor archive lacks the current version of the API module, the install script MUST rebuild the vendor archive with `rc.unraid-api archive-dependencies`. Reason: each start of unraid-api replaces `node_modules` from the vendor archive.
- REQ-PKG-14: If unraid-api runs while the install script runs, the install script MUST restart unraid-api.
- REQ-PKG-15: When unraid-api loads the API module, the API module MUST write its version and the process ID of unraid-api to `/var/run/tofuman-api.json`. The tab MUST read that file for REQ-PKG-3.
- REQ-PKG-29: The install script MUST give the restart of REQ-PKG-14 at most 2 minutes. When the restart takes longer, the install script MUST end the restart and each process that the restart started in its process group. The install script MUST then finish the install, and MUST say that unraid-api did not restart. Reason: on 2026-10-05, a full `/var/log` left PM2 unable to start, and the install waited for hours (added 2026-10-05).

Releases (decided 2026-09-28):

- REQ-PKG-16: The tag of a plugin release MUST be `plugin-YYYY.MM.DD`, and the version of the `.plg` file MUST be that date. Reason: the plugin manager of Unraid compares versions with `strcmp`. A second release on one day appends one lowercase letter to the date.
- REQ-PKG-17: The tag of a provider release MUST be `provider-vX.Y.Z`, with a semantic version.
- REQ-PKG-18: The `.plg` file MUST live at `sources/plugin/tofuman.plg` on `main`, and its `pluginURL` MUST be the raw GitHub URL of that file.
- REQ-PKG-19: The `.plg` file MUST download the package from the assets of the plugin release, and MUST check the SHA256 hash of the package.
- REQ-PKG-20: The build of the package MUST be reproducible.

The plugin manager of the webgui shows the release notes, the icon, and the support link of a `.plg` file, and refuses a `.plg` file below its `min` (added 2026-10-04):

- REQ-PKG-21: The `.plg` file MUST hold the release notes of each release in its `CHANGES` element.
- REQ-PKG-22: The `.plg` file MUST name an icon, the issues page of the repository as `support`, the **Docker** page as `launch`, and the oldest Unraid release that a tested build covers as `min`.

Candidate releases, decided 2026-10-04: a server installs only a released plugin, so each plugin release starts as a candidate release, and the `.plg` file on `main` takes its version only after the end-to-end procedure passed with it (REQ-TST-8).

- REQ-PKG-23: For each plugin tag, the release workflow MUST publish a candidate release: a GitHub pre-release with the package and with a `.plg` file that names the version of the tag and the hash of that package.
- REQ-PKG-24: The release workflow MUST refuse a tag whose version has no heading in the `CHANGES` element of the `.plg` file.
- REQ-PKG-25: To promote a candidate release, a maintainer MUST put the `.plg` file of that candidate release, unchanged, at `sources/plugin/tofuman.plg` on `main`.
- REQ-PKG-26: On that push, the promotion workflow MUST check that the hash in the `.plg` file is the hash of the package of the candidate release, and that the `.plg` file equals the `.plg` file of the candidate release. If both checks pass, the promotion workflow MUST mark the release as no longer a pre-release. The promotion workflow MUST then replace the candidate title and notes of the release with the title `tofuman plugin <version>` and the `CHANGES` of that version (added 2026-10-05). If either check fails, the promotion workflow MUST fail, and the release stays a pre-release.
- REQ-PKG-27: The version in the `.plg` file on `main` MUST change only through a promotion.

The `.plg` file of a candidate release keeps the `pluginURL` of `main` (REQ-PKG-18), so a server that installed the candidate release sees each later promotion as an update.

Release procedure:

1. Add the heading and the notes of the new version to `CHANGES` in `sources/plugin/tofuman.plg`, and push the commit to `main`.
2. Tag that commit `plugin-YYYY.MM.DD`, and push the tag. The release workflow publishes the candidate release.
3. In the webgui of a server, open Plugins, then Install Plugin, and install `https://github.com/tomzorz/tofuman/releases/download/plugin-YYYY.MM.DD/tofuman.plg`.
4. Run the end-to-end procedure of section 18 on that server.
5. Download the `.plg` file of the candidate release over `sources/plugin/tofuman.plg`, commit it, and push the commit to `main`. The promotion workflow marks the release as no longer a pre-release, and gives it the notes of its version.

If step 4 fails, do not run step 5. A fix goes out as a new candidate release with a new tag.

## 18. Testing

- REQ-TST-1: GitHub Actions MUST run the tests of the shim, of the API module, and of the provider on each push to `main`.
- REQ-TST-2: The shim tests MUST run in `php-cli` 8.3 against the webgui source of each tested build. Unraid 7.2 ships PHP 8.3 (release notes 7.2.5 and 7.2.7), and no release note of Unraid 7.3 changes that version.
- REQ-TST-3: The shim tests MUST cover template round trips, validation, the policy, and the commands from `xmlToCommand`.
- REQ-TST-4: The shim tests MUST run each generated `docker create` on the Docker of the test runner, with a stand-in image. The shim tests MUST compare the output of `docker inspect` with the definition.
- REQ-TST-5: The shim tests MUST NOT start a container, except the tests of the start check, which start throwaway containers of a stand-in image.
- REQ-TST-6: The API module tests MUST run the real shim, and MUST NOT use mocks.
- REQ-TST-7: The provider tests MUST run against a test server that serves the schema of section 15. The test server MUST use the real API module service and the real shim, on the Docker of the test runner.
- REQ-TST-8: Before a maintainer promotes a candidate release, a person MUST run the end-to-end procedure of this section on a server with that candidate release installed, and with the newest provider release (changed 2026-10-04 with the candidate releases of section 17).
- REQ-TST-9: The provider tests MUST run the `tofu` binary of one pinned OpenTofu release (spike 4).
- REQ-TST-10: The shim tests MUST load the webgui from `/usr/local/emhttp` and the plugin from `/usr/local/emhttp/plugins/tofuman`, as a server does.
- REQ-TST-11: The PHP configuration of the shim tests MUST prepend `local_prepend.php` of the webgui to every run, as the PHP configuration of Unraid does. Reason: without REQ-TST-10 and REQ-TST-11, a tested build misses files that the shim loads on a server (spike 2).
- REQ-TST-12: The provider tests MUST run the e2e tool against the test server of REQ-TST-7, with a scripted person in place of the person in the webgui. For that person, the test server MUST create and adopt a hand-made container from the adoption test template.

End-to-end procedure. Preconditions: the plugin is installed on the server, an API key with only `DOCKER:CREATE_ANY` is on the key allowlist, and a directory for throwaway containers exists under a bind root.

1. Write an HCL file with one throwaway container that uses a path, a port, a variable, a secret, a label, and an icon of its own.
2. Run `tofu apply`.
3. In the webgui, check that the container runs, offers **Edit**, shows an update status, and carries the badge. The update status is where **Update** appears when a newer image exists.
4. In the webgui, change the variable with **Edit**, and select **Apply**.
5. Run `tofu plan`. The plan shows the change of the variable as drift.
6. Run `tofu apply`. The variable returns to the value in the HCL file.
7. Change the name in the HCL file, and run `tofu apply`. The server reports the same managed ID under the new name.
8. Add an `ExtraParams` flag that tofuman does not know to the HCL file, and run `tofu plan`. The plan fails and names the flag. Remove the flag again.
9. Give the container a command that exits at once, and run `tofu apply`. The apply fails at the step `start check` and shows the log lines of the container. The previous container runs again. Put the command back.
10. Run `tofu destroy`. The container and its template are gone.
11. In the webgui, create a throwaway hand-made container from the adoption test template. In the tab, tick it in the hand-made section, and select **Adopt selected**.
12. Run `tofu import` with the name of that container, and write matching HCL. `tofu plan` shows no change.
13. Check that the audit log shows each mutation and the adoption, and that the tab shows the operation of step 9 with its log lines.

If a step fails, keep the throwaway containers, download the diagnostics file from the tab, and do not promote the candidate release.

### 18.1 The e2e tool

The e2e tool runs this procedure (decided 2026-10-05). A person runs it in that person's own environment, so the API key never reaches an agent. It lives at `sources/provider/cmd/tofuman-e2e`, in the module of the provider, and uses the API client of the provider.

- REQ-E2E-1: The e2e tool MUST run the steps of the end-to-end procedure against one server, in their order.
- REQ-E2E-2: The e2e tool MUST read its configuration from `tofuman-e2e.json` in the working directory if that file exists, and else from `tofuman/e2e.json` in the user configuration directory of the operating system.
- REQ-E2E-3: When neither file exists, the e2e tool MUST ask for the endpoint, the source of the API key, and the bind root of the throwaway containers. It MUST save the answers in the location that the person picks from those two.
- REQ-E2E-4: The source of the API key MUST be exactly one of these: a reference that the 1Password CLI resolves with `op read`, a command whose output is the key, an environment variable, or the key itself in the configuration file.
- REQ-E2E-5: The e2e tool MUST NOT show the API key, and MUST NOT write the API key into a file other than the configuration file. It MUST give the API key to tofu only through the environment of the tofu process.
- REQ-E2E-6: The repository MUST keep `tofuman-e2e.json` and the run folders of the e2e tool out of git.
- REQ-E2E-7: The e2e tool MUST keep the files of each run in a new folder: under `tofuman-e2e-runs/` in the working directory when its configuration comes from there, and else under the user cache directory of the operating system.
- REQ-E2E-8: Before the first step, the e2e tool MUST check that `tofu` runs, that the API answers with the API key, and that the API module would accept the throwaway container (query `check`). If a check fails, the e2e tool MUST stop and name each failed check.
- REQ-E2E-9: Before the first step, the e2e tool MUST list each managed container that an earlier run left, and MUST offer to delete those containers.
- REQ-E2E-10: The e2e tool MUST give tofu the provider release of its own version from a filesystem mirror, which it fills from the assets of that release. It MUST check each asset against the SHA256SUMS file of the release. A build without a version MUST use the newest provider release. A person MAY name a local provider binary instead.
- REQ-E2E-11: The e2e tool MUST give the throwaway containers of each run a random suffix in their names.
- REQ-E2E-12: In step 4 and step 11, the e2e tool MUST watch the API and go on by itself: in step 4 when the variable holds a new value, and in step 11 when a managed container appears that was not managed when the step began.
- REQ-E2E-13: In step 3 and step 13, the e2e tool MUST ask the person to confirm what the person sees in the webgui.
- REQ-E2E-14: The e2e tool MUST also check through the API what the procedure asks of the server: that the container runs (step 3), that the variable holds its value again (step 6), that the managed ID stays under the new name (step 7), that the previous container runs again (step 9), and that the container is gone (step 10).
- REQ-E2E-15: The e2e tool MUST show each step with its state, the time when it began, and its duration.
- REQ-E2E-16: When a step fails, the e2e tool MUST show the end of the output of tofu for that step, and MUST let the person retry the step or stop the run. When the run stops, the throwaway containers MUST stay.
- REQ-E2E-17: The e2e tool MUST write a summary file into the folder of the run, with the versions, each step, its times, and its result.
- REQ-E2E-18: When its output is not a terminal, the e2e tool MUST print one line per event and read the answers of the person from its input. Reason: a run inside a log or a test has no terminal.
- REQ-E2E-19: After the last step, the e2e tool MUST offer to delete the adopted container through `tofu destroy`.
- REQ-E2E-20: The throwaway container of the e2e tool MUST have an icon of its own. Reason: REQ-TAB-43.

## 19. Spikes

- Spike 1, 2026-09-28, **go** ([`spikes/2026-09-28-dockerman-helpers-off-unraid`](../spikes/2026-09-28-dockerman-helpers-off-unraid/README.md)): the DockerMan helpers load in `php-cli` with `_var()` and four globals stubbed. 17 of 17 real templates are a fixed point of `xmlToVar`, `postToXML`, `xmlToVar`. 16 of 17 generated commands created a matching container on a plain Docker host, and the 17th needs the nvidia runtime.
- Spike 2, 2026-10-04, **go** on Unraid 7.3.2: the install route of REQ-PKG-10 to REQ-PKG-14 meets REQ-PKG-2. The update half of REQ-PKG-3 stays open until NewIntersect takes the next Unraid release. A person ran the spike on NewIntersect, with a written rollback (decided 2026-09-28). unraid-api loaded the API module after the install, after a restart, and after a reboot. There, `vendor_archive.json` names an archive that does not exist, so no start restores `node_modules`. Read in the code 2026-09-28: the load conditions and the restore at each start of section 17, and a plugin that fails to import leaves unraid-api running and raises an alert notification. The first runs found two defects, and plugin releases 2026.10.02 and 2026.10.03 fixed them. The tested build failed as a set, because the shim counted its own files and `local_prepend.php` (REQ-TST-10, REQ-TST-11), while every webgui file matched the tag `7.3.2` byte for byte. The first `createContainer` failed with "Bad Request Exception", because the global `ValidationPipe` of unraid-api rejected each field of the input class (section 23). With plugin 2026.10.03 and provider 0.1.0, the end-to-end procedure of section 18 passed on 2026-10-04.
- Spike 3, 2026-10-04, **go**: a stylesheet can mark managed containers on the **Docker** page without JavaScript. The webgui renders the content of every tab of a page into that page, so a `<link>` in the tab reaches the stock container table, and each row of that table names its container only in the `container` attribute of its autostart switch. The rule `#docker_list tr:has(input.autostart[container="NAME"]) span.appname::after` puts the badge after the name, where it covers no control. The endpoint of the tab serves that stylesheet from the registry. `tests/preview/router.php` shows the badge on a stand-in table with the markup of `DockerContainers.php` at the tag `7.3.2` (path `/docker`); a server shows the real one.
- Spike 4, 2026-09-28, **go** ([`spikes/2026-09-28-plugin-testing-on-opentofu`](../spikes/2026-09-28-plugin-testing-on-opentofu/README.md)): terraform-plugin-testing runs OpenTofu 1.12.6 through a create, an import by name, an update in place, and a read that drops a vanished resource. The run needs `TF_ACC_PROVIDER_HOST=registry.opentofu.org` and `TF_ACC_PROVIDER_NAMESPACE`, and without them `tofu init` fails.

## 20. Open items

- Whether `/etc/nginx/nginx.conf` on the server sets a longer proxy timeout is unverified. The design does not depend on it.
- How a person moves a digest to a newer image is undecided. Assumption: by hand in HCL.
- Adoption of a template that came straight from Community Applications drops the elements that `postToXML` does not write. How the tab reports that loss is undecided.
- Whether Unraid Connect's flash backup uploads the plaintext API key files is unverified.
- Whether the free text that `configureUps` writes reaches command execution is untraced.
- Whether the webgui **Update** action works on a container with a digest is untested.
- How the provider reaches OpenTofu before a registry lists it is undecided. Assumption: a local filesystem mirror, filled from the assets of a GitHub release.
- Research 2026-10-04: webgui `7.4.0-beta.3` adds the elements `Memory` and `ExtraNetworks` to `postToXML` and to `xmlToVar`, and `xmlToCommand` turns `Memory` into `--memory`. A tested build for 7.4 needs the shim to model both elements, or to refuse a template that sets either of them.

## 21. Deferred

- A listing in Community Applications and in an OpenTofu provider registry. Both wait until tofuman survives an Unraid upgrade or two. Research 2026-10-04: registry.opentofu.org lists a provider only from a public repository named `terraform-provider-<name>` with tags `vX.Y.Z`, and `tofu init` fails when a release lacks `SHA256SUMS.sig`. The smallest route is a repository `terraform-provider-tofuman` that holds only the signed releases, which the release workflow of this repository fills.
- A server check in the tab (decided 2026-10-05, after the e2e tool): checks on the server, and a throwaway container through create, start check, and delete in the shim, without tofu and without the provider.
- The start order of autostart containers and the wait values.
- Tailscale and `<ExtraNetworks>`.

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
- `getAllInfo` records the shared default icon for a container without an icon ([DockerClient.php L346](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L346)), and `removeContainer` with a cache level of 1 or more deletes the recorded icon ([L900-906](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php#L900-L906)). The webgui skips the shared icon from [`ff8f6e8`](https://github.com/unraid/webgui/commit/ff8f6e8db9) on (read 2026-10-04).
- The nginx location for `/graphql` sets no proxy timeout ([rc.nginx L427-438](https://github.com/unraid/webgui/blob/369f0b2994584a6b6c8e6e570f5f86401d289234/etc/rc.d/rc.nginx#L427-L438)), so the nginx default of 60 seconds applies.
- `unraid-api plugins install` runs `npm i --save-peer --save-exact` in the unraid-api directory ([plugin-management.service.ts L88-96](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/plugin/plugin-management.service.ts#L88-L96)), and each start of unraid-api replaces `node_modules` from the archive, if an archive exists ([dependencies.sh L129-152](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/plugin/source/dynamix.unraid.net/usr/local/share/dynamix.unraid.net/scripts/dependencies.sh#L129-L152)).
- The shared decorator rejects resources outside the fixed list ([use-permissions.directive.ts L93-97](https://github.com/unraid/api/blob/d0615255e0ce062f7a8262e560f963d07f311539/packages/unraid-shared/src/use-permissions.directive.ts#L93-L97)). API 4.35.1 registers nest-authz's `AuthZGuard` ([app.module.ts L63-72](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/app/app.module.ts#L63-L72)), which lets through each handler without permission metadata ([authz.guard.ts L37-39](https://github.com/apache/casbin-nest-authz/blob/8cb1097dff90e4585670db49fcec74bc69ad982b/src/authz.guard.ts#L37-L39)). API 4.37.4 denies such handlers ([3ec4764](https://github.com/unraid/api/commit/3ec47647879a02bd45d55ca0e2bca987b1ff0d27)).
- unraid-api runs a global `ValidationPipe` with `whitelist` and `forbidNonWhitelisted` ([main.ts L78-88](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/main.ts#L78-L88)). The pipe rejects each field of an input class without class-validator decorators, and it leaves an argument alone whose type is not a class.
- `configureUps` has no permission metadata in API 4.35.1 ([ups.resolver.ts L83-84](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/graph/resolvers/ups/ups.resolver.ts#L83-L84)) and writes its input into `apcupsd.conf` unfiltered ([ups.service.ts L344-387](https://github.com/unraid/api/blob/a9625ae20a589e739926923b28ca7efe14233372/api/src/unraid-api/graph/resolvers/ups/ups.service.ts#L344-L387)).
