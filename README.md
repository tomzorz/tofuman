# tofuman

The missing connection between OpenTofu and Unraid's DockerMan.

An Unraid plugin plus an OpenTofu provider, so containers on an Unraid server can be declared in tofu and still be ordinary Unraid containers. The plugin writes DockerMan templates through the webgui's own code, so Edit, Update, Community Applications and Appdata Backup keep working, and a change made in the webgui shows up in `tofu plan` as drift.

Status: early. The plugin and the provider build and pass their tests; nobody has installed them on a real server yet. Start at [docs/SPEC.md](docs/SPEC.md).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE). The plugin loads and runs inside Unraid's GPL code, the webgui's PHP helpers and the unraid-api. The OpenTofu provider in `sources/provider/` is MIT, see [its LICENSE](sources/provider/LICENSE), because it only talks to the plugin over the network.
