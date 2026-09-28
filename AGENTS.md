# tofuman

An Unraid plugin and an OpenTofu provider that let tofu declare Docker containers which stay ordinary Unraid containers. The plugin extends the built-in Unraid API with mutations that write DockerMan user templates through the webgui's own helpers, and the provider is a client of those mutations. The specification is `docs/SPEC.md`, in Lojbanlite, with each decision in the section it affects and no separate decisions file. Code follows the spec; a change of behaviour changes the spec first.

## Repo

- Single-branch: commit to `main`, push `main`.
- This repository is public. Nothing in it names a particular machine, host, address, network or anyone's setup: not in code, docs, examples, test fixtures or commit messages. Examples use generic Unraid paths (`/mnt/user/appdata/...`) and documentation addresses (`192.0.2.0/24`).
- The plugin and the provider share this repo until the provider is published to a registry, which needs a repo of its own.
- License: GPL-2.0-or-later for everything except the provider, which is MIT in its own folder. Every source file starts with an `SPDX-License-Identifier` line.
- Archetype layout: projects go in `sources/<project>/`, each with its stack's ignore file from archetype, created on the day the project exists.
- LFS is on. A fresh clone needs `git lfs install --local`.
- Spikes live in `spikes/YYYY-MM-DD-<slug>/` and are committed. Each has a README with its question, findings and verdict.
- Anything worth keeping past this session goes through the Record skill. It reads `AGENTS.records.md` next to this file for where each kind of information lives here. "Remember this" and "park this" are always worth keeping; anything the code or git history already says never is.

## Building and testing

- Agents write the code and the HCL and run the tests on a disposable Linux Docker host. They never run `tofu plan` or `tofu apply` against a real Unraid server and never install the plugin on one. A person does that, with throwaway containers first.
- The webgui helpers the plugin calls are internal to Unraid and change between releases without notice. Test against the webgui source at the tag the plugin pins.
