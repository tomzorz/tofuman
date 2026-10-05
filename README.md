# tofuman

Declare the containers of an Unraid server in OpenTofu, and keep them ordinary Unraid containers.

tofuman is an Unraid plugin plus an OpenTofu provider. The plugin writes DockerMan templates through the webgui's own code, so Edit, Update, Community Applications, CA Auto Update and Appdata Backup keep working on the containers that tofu manages. A change made in the webgui shows up in `tofu plan` as drift.

Status: early, and in use. Unraid 7.3.2 is the tested release, and the end-to-end procedure passed there on 2026-10-04. On an Unraid release without a tested build the plugin still answers queries, but it refuses every change until a plugin release covers that Unraid release. Every rule lives in [docs/SPEC.md](docs/SPEC.md).

## Install the plugin

In the webgui, open Plugins, then Install Plugin, and paste this URL:

```
https://raw.githubusercontent.com/tomzorz/tofuman/main/sources/plugin/tofuman.plg
```

The plugin adds a tofuman tab to the Docker page, and a small `tofu` badge next to each managed container on the Docker page itself.

## Give tofu a key

1. In Settings, Management Access, API Keys, create a key with only the permission `DOCKER: CREATE_ANY`. tofuman borrows that pair because no official handler of unraid-api uses it.
2. On the tofuman tab, put the ID of that key (the ID, not the key) into `keyAllowlist` in the policy, and select Save policy.

Before unraid-api 4.37.4, which arrives with Unraid 7.3.3, any API key can also reach a few unraid-api handlers that have no permission check (spec section 8). Keep the key wherever you keep your other secrets.

## Install the provider

Until a registry lists it, OpenTofu finds the provider in a local mirror. Download the zip for your platform from the latest `provider-v*` release, and put the zip, as it is, into:

- Windows: `%APPDATA%\terraform.d\plugins\registry.opentofu.org\tomzorz\tofuman\`
- Linux and macOS: `~/.terraform.d/plugins/registry.opentofu.org/tomzorz/tofuman/`

Then:

```hcl
terraform {
  required_providers {
    tofuman = {
      source  = "tomzorz/tofuman"
      version = "~> 0.2"
    }
  }
}

provider "tofuman" {
  endpoint = "https://192.0.2.10" # the webgui address; http works too, but then the key crosses the network in plain text
  # api_key comes from TOFUMAN_API_KEY
}
```

## A first container

```hcl
resource "tofuman_container" "whoami" {
  name       = "whoami"
  repository = "traefik/whoami:latest"
  network    = "bridge"
  autostart  = true
  web_ui     = "http://[IP]:[PORT:80]/"

  port {
    host_port      = "8085"
    container_port = "80"
  }
}
```

The policy on the tab decides what a container may have: host paths under its bind roots (`/mnt/user/appdata/` at first), its networks (`bridge` at first), the `extra_params` flags it lists, and exceptions per container for privileged mode, the host network, the host IPC namespace and devices. `tofu plan` asks the server to run the same checks, so a refusal shows in the plan, before anything changes. When the policy is what is missing, the Policy heading on the tab says how many entries it lacks. Open it and tick them, and Add the selected entries to the editor puts them into the policy for you to save.

## Testing a fresh build of your own image

- Push a new build of the tag, then select Update for the container on the Docker page, or let CA Auto Update do it. The template does not change, so tofu sees no drift.
- Change the configuration in HCL, and `tofu apply` recreates the container with it.
- `tofu apply -replace=tofuman_container.whoami` deletes the container and creates it again, with a new managed ID.
- When an apply starts a container that stops or restarts within `start_check` (10 seconds unless you set it), the apply fails with the exit code and the last log lines of the container, and an update puts the previous container back. Set `start_check = "60s"` for something slow to fail, and `"0s"` for a container that ends on its own.

## Moving existing containers in

1. Open Hand-made containers on the tab. It says for each container whether Adopt would work, and why not. Tick the ones you want, and select Adopt selected below the list. Adopt changes no container; it records the container and marks its template.
2. Select Import blocks for all below the managed containers, paste the blocks into your configuration, and run `tofu plan -generate-config-out=generated.tf` once.
3. Look over the `secret` blocks in the generated file, wire their values to wherever you keep secrets, and run `tofu apply`.

## When something goes wrong

- `TF_LOG=DEBUG tofu apply` shows each request to the server with its duration, and each step of each operation that the provider waits for.
- The Activity list on the tab shows each mutation. Select a line with a chevron to see its operation step by step: the digest of the pull, the `docker create` command with masked values hidden, its output, and for a failed start check the exit code and the log lines.
- The Diagnostics file link at the top of the tab downloads what someone helping you needs: versions, the build check, the policy, the registry, the audit and operation logs, and the lines of the unraid-api log that mention tofuman. It holds no API key, and masked values show as `***`.
- A failed operation raises an Unraid notification, and so does an Unraid release without a tested build.

## After an Unraid update

The plugin refuses changes until a plugin release covers the new Unraid release, and an alert notification says so. Queries keep working, and the containers keep running. Updating the plugin lifts the refusal.

## Check a server end to end

`tofuman-e2e` runs the whole procedure of spec section 18 against your server. It creates a throwaway busybox container with tofu, lets you change it in the webgui and checks that tofu sees the drift, renames it, provokes a refusal at plan time and a failed start check, destroys it, and imports a container that you adopt in the tab. When a step needs you in the webgui, it says what to do and carries on by itself once the server shows the change.

1. Download the `tofuman-e2e` zip for your platform from the latest `provider-v*` release, and unpack it. It needs `tofu` on PATH, and it fetches the matching provider into a cache of its own, so your mirror stays as it is.
2. Run `tofuman-e2e`. The first run asks for the endpoint, where the API key comes from (the 1Password CLI, any command that prints it, an environment variable, or the key itself), and the bind root for the throwaway data. It saves the answers in your user config folder, or as `tofuman-e2e.json` in the current folder if you pick that; a file there wins over the one in your user folder. `tofuman-e2e -setup` asks again.
3. Each run keeps a folder with `summary.txt` and the output of every tofu command. `-plain` prints one line per event instead of the interactive view.

The throwaway containers are named `tofuman-e2e-<random>`, and a run offers to delete the ones that an earlier run left behind. For the adoption, the run links to Add Container with the `tofuman-adoption-test` template filled in; the Hand-made containers section of the tab has the same link.

## Repository

- `sources/plugin/`: the plugin. The shim (PHP) calls the webgui's DockerMan helpers, the API module (TypeScript) loads into unraid-api, and the tab and the `.plg` file live next to them.
- `sources/provider/`: the provider (Go), and in `cmd/tofuman-e2e` the end-to-end tool.
- `docs/SPEC.md`: the specification.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE). The plugin loads and runs inside Unraid's GPL code, the webgui's PHP helpers and the unraid-api. The OpenTofu provider in `sources/provider/` is MIT, see [its LICENSE](sources/provider/LICENSE), because it only talks to the plugin over the network.
