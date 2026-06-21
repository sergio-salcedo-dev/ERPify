# Documentation layout & file naming

Where each kind of doc lives under `docs/`, and how its file is named. This rule is the
**structure-and-naming** authority; it does not restate the prose rules that already live in the
root `CLAUDE.md` — for **density**, **Markdown link style**, the **"keeping docs up to date"**
change→file mapping, and an **ADR's internal style**, defer there.

## Folder taxonomy

Each folder owns one kind of document. A new file picks the folder by *what kind of doc it is*, not
by topic.

| Location | Owns | Examples |
|----------|------|----------|
| `docs/` (root) | Entry points only — the few docs everything else links to. | `index.md`, `project-context.md`, `project-overview.md` |
| `docs/adr/` | Decision records — one decision per file, the *why* behind a choice. | `keyset-pagination.md`, `event-driven-architecture.md` |
| `docs/rules/` | Prescriptive coding/convention rules (this file included). | `architecture.md`, `cqrs-naming.md`, `security.md` |
| `docs/architecture/` | Current-state system design references — how the system *is*. | `architecture-api.md`, `integration-architecture.md` |
| `docs/guides/` | How-to / workflow / contribution guides — how *you* work. | `development-guide-api.md`, `contribution-guide.md` |
| `docs/operations/` | Deploy, run, recover. `runbooks/` and `troubleshooting/` nest here. | `deployment-guide.md`, `vps-deployment.md` |
| `docs/roadmap/` | Living forward plans — only while they are *live*. | `product-roadmap.md`, `saas-production-roadmap.md` |
| `docs/.archive/` | Frozen point-in-time reports. Read-only; never linked from live docs. | `project-scan-report-2026-04-21.json` |

`adr/` records the decision; `architecture/` describes the resulting current state; `rules/`
prescribes what code must do. A topic can appear in all three — different *kind*, different file.

Decision vs. description: an ADR is the only place the discarded alternatives and the non-obvious
*why* belong; the architecture docs carry the current state, never the deliberation.

## File naming

- **`kebab-case.md`**, lowercase, topic-based, self-describing (`keyset-pagination.md`, not `kp.md`).
- **No sequence numbers** — including ADRs: name by topic (`audit-activity-log.md`), **never**
  `ADR-001-…`. Sequential ids collide across parallel worktrees (two branches both grab `ADR-013`),
  don't sort by meaning, and aren't greppable. Ordering and lifecycle live *inside* the file
  (header) and in `index.md`, not in the filename.
- **No dates in the filename** — the sole exception is `.archive/`, where a date suffix freezes a
  point-in-time artifact. Dates that matter for a live doc go in its header.
- **One topic = one file.** Extend the file that owns a topic before adding a near-duplicate.
- **Every new file gets one line in `index.md`** under the matching section.

## ADR file shape

- **Language: English.** ADRs are authored in English — the exception to the repo's Spanish documentation default (`document_output_language: Spanish`). The lifecycle header below is already English; legacy Spanish ADR bodies migrate to English when the file is next touched (boy-scout).
- Filename: topic in kebab-case (see above).
- First lines: a header blockquote carrying lifecycle —
  `> **Status:** … · **Date:** YYYY-MM-DD · **Scope:** …`, plus
  `> **Superseded by** [other-adr.md](./other-adr.md)` when a later ADR overrides it.
- Body: numbered in-file decisions (`D1`, `D2`, …) with discarded alternatives inline, target
  ≤ ~150 lines. Full internal style: root `CLAUDE.md` → *Docs density → ADRs*.

## Where does a new doc go?

1. Recording *why* a choice was made → `adr/`.
2. Prescribing what code must do → `rules/`.
3. Describing how the system currently works → `architecture/`.
4. Showing a human how to do a task → `guides/`.
5. Deploying / operating / recovering → `operations/`.
6. A live forward plan → `roadmap/` (delete it when the work ships — git preserves it).

If none fit and the doc is a genuine entry point, it stays at `docs/` root. If it is a spent
point-in-time report, it goes to `.archive/` with a date suffix — or is simply deleted.
