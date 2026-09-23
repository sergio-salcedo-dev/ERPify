---
baseline_commit: 0a4d328c194a5aa2e242e789251c956aeb454de1
---

# Story: make the sprint board legible to bmad-loop

Status: ready-for-dev

## Story

As the operator of `bmad-loop run`,
I want the orchestrator to see this repository's open stories,
so that wiring it into the repo buys an orchestrator that has something to orchestrate rather than a
set of hooks that fire on a queue it reads as empty.

## Why this is a story and not a commit

Wiring bmad-loop is one commit; the fix here is a **convention change to the board**, and the board's
own `INVARIANTE DE NOMBRES` makes a key rename a file rename — workflows resolve a story as
`{implementation_artifacts}/{story_key}.md`, so every key that moves takes its artifact and every
reference to that artifact with it. Which convention to adopt is also not the implementer's call: it
decides how every future epic is named.

## The mechanism, as measured

Read off the installed orchestrator (`bmad_loop/sprintstatus.py`, v0.12.0) rather than inferred from
the symptom:

```
EPIC_RE  = ^epic-(\d+)$
RETRO_RE = ^epic-(\d+)-retrospective$
STORY_RE = ^(\d+)-(\d+)([a-z]?)-(.+)$
```

and, in the model it builds, `epic: int` with `epics: dict[int, str]`. **The epic identity is an
integer through the whole engine**, so this is not a pattern a setting relaxes.

Measured against `_bmad-output/implementation-artifacts/sprint-status.yaml` at the baseline commit,
`bmad-loop validate` reports `14 stories, 0 actionable` over 84 keys:

| | Count | Shape |
|---|---|---|
| Accepted | 20 | 6 × `epic-<n>` + **14 keys beginning with a digit** |
| Ignored | 64 | `af-`, `rm-`, `ii-`, `u-`, `g-`, `br-`, `img-`, `gh-` |

**Not one of the 64 ignored keys begins with a digit, and not one of the 14 accepted stories fails
to.** The correlation is exact, and the regexes above are why.

Everything the repository has planned since epic 3 is therefore invisible to the orchestrator —
including `gh-925-repository-root-marker`, the one story currently `ready-for-dev`. That is what
`0 actionable` means: not an empty backlog, an unreadable one.

## The conflict this story has to settle

[`CLAUDE.md`](../../CLAUDE.md) states that **new epics get their own `sprint-status-<slug>.yaml`**,
and records that the BMad skills resolve `status_file` as `{implementation_artifacts}/sprint-status.yaml`
by fixed convention. bmad-loop is a **third** reader with the same fixed-path assumption and no knob
for it: `[stories] source` accepts `sprint-status` or `stories`, and `stories` requires a
`stories.yaml` + `SPEC.md` spec folder this repository does not produce.

So a scoped board is invisible to bmad-loop twice over — wrong path *and* wrong key shape. Adopting
the orchestrator and keeping the per-epic board preference are in tension, and the story is where
that gets decided rather than silently resolved by whoever writes the next epic.

## Acceptance criteria

1. A convention is decided and written down in [`CLAUDE.md`](../../CLAUDE.md) beside the existing
   per-epic board rule, naming explicitly which of the two preferences yields.
2. At least one **open** story is legible to the orchestrator: `bmad-loop validate` reports a non-zero
   `actionable` count, with the number and the command's exit code recorded.
3. No historical key is renamed purely to silence a warning. `unknown_keys` is a warning and not an
   error, so the 64 closed keys may stay exactly as they are; if any is renamed, its artifact file and
   every reference to it move in the same commit.
4. `make bmad.status.audit` stays green over every board.

## Alternatives, with what each costs

- **Rename all 64 keys to `<n>-<m>-slug`.** Rejected as the default: it renames ~19 artifact files
  that still exist plus every cross-reference in `docs/`, `CLAUDE.md` and merged PR bodies, to make
  *closed* history legible to a tool that only ever runs on open work.
- **Adopt `source = "stories"`.** Requires emitting `stories.yaml` + `SPEC.md` per epic — a second
  planning artifact alongside the board, maintained by hand, for one consumer.
- **Number only new epics** (`epic-4`, `epic-5`, …) and leave history alone. Cheapest, and the cost is
  that epic keys stop being self-describing, which is why the repository moved to slugs.
- **Do nothing and keep bmad-loop unused here.** Legitimate, and it should be stated as a choice
  rather than reached by leaving this story unopened.

## Source

Measured while wiring `bmad-loop init` into the repository; the orchestrator is pinned at
`bmad-loop v0.12.0` (`uv tool`).
