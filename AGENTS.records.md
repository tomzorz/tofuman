# Records

Where information produced while working in this area gets recorded. Read by the `record` skill when it has something to write; created by `record-setup`. Edit by hand when the answers change.

## Area

- Scope: this repository
- Version control: git, single-branch, public on GitHub
- Spec language: Lojbanlite for anything normative (`docs/SPEC.md` once it exists, `docs/DESIGN.md` until then)
- Spike sandbox: `spikes/`, committed, one `YYYY-MM-DD-<slug>/` folder per spike with a README holding the question, findings and verdict

## Destinations

| Kind | Where | Notes |
|---|---|---|
| Rule | `AGENTS.md` | Rules that apply beyond this repo go to the next `AGENTS.md` up. |
| Code fact | Comment at the site | |
| Design | `docs/DESIGN.md`, later `docs/SPEC.md` | |
| Decision | Inline in the affected section, dated | Never a standalone decisions file. |
| Finding | Inline in the doc it informs, dated, linking its spike folder | With nothing else to inform, a dated paragraph in the design doc's "Things tried" section. |
| Procedure | `docs/` | Lojbanlite. |
| Diagram | `docs/diagrams/` | Referenced from the section it explains. |
| Parked work | Outside this repo, through the next area up; a TODO comment at the site when there is one | Nothing that would be stranded when the repo moves hosts. |
| Question | Ask; an `ASSUMPTION:` comment at the site when the user is away | |
| Machine fact | Not in this repo | The repo gets published. Machine facts go to the next area up. |
| Not recorded | Progress, summaries, anything git already says | |
