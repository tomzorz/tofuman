# tofuman

The missing connection between OpenTofu and Unraid's DockerMan.

An Unraid plugin plus an OpenTofu provider, so containers on an Unraid server can be declared in tofu and still be ordinary Unraid containers. The plugin writes DockerMan templates through the webgui's own code, so Edit, Update, Community Applications and Appdata Backup keep working, and a change made in the webgui shows up in `tofu plan` as drift.

Status: design and a first spike, nothing to install yet. Start at [docs/DESIGN.md](docs/DESIGN.md).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE). The plugin loads and runs inside Unraid's GPL code, the webgui's PHP helpers and the unraid-api. The OpenTofu provider will be MIT, in its own folder, because it only talks to the plugin over the network.
