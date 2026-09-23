---
baseline_commit: fbe9a255967d88c894fe9ccdd3af6cc350b9c5b0
---

# Story GH-925: Narrow `RepositoryRoot::MARKERS` to the two that discriminate

Status: ready-for-dev

## Story

As a maintainer of the six gates that resolve the repository root from inside the container,
I want `RepositoryRoot::MARKERS` to hold only files that exist at the repository root and nowhere else,
so that a future candidate path or a move of the resolver cannot make `api/` resolve as "the root" and
silently point every one of those gates at the wrong tree.

## Why this is a story and not a commit

The constant is read by **six** gates — `AuditRedactionSentinelParityTest`,
`AuditWriteOperationParityTest`, `EnumWireContractGateTest`, `ProjectContextVersionGateTest`,
`RedactionVocabularyParityTest` and `TestDatabaseGuardGateTest`. Narrowing a shared resolver is not a
call the chore that found it should settle alone, which is why GH-925 was filed rather than fixed.

## The defect, as measured in the issue

| Marker | Where it exists |
|---|---|
| `compose.yaml` | repository root only |
| `Makefile` | repository root only |
| **`CLAUDE.md`** | **repository root, `api/` and `pwa/`** |

Nested `CLAUDE.md` files are deliberate — they auto-load inside their subtree — so the third marker
states a property the repository does not have.

**Not reachable today**, and the story must not claim otherwise: `candidates()` returns
`dirname($apiRoot)` and `dirname($apiRoot) . '/repo'`, neither of which is `api/` or `pwa/`, and
`RepositoryRootTest` pins the discriminant separately. It becomes reachable the moment `candidates()`
grows an entry or `RepositoryRoot.php` moves one directory deeper.

## Acceptance criteria

1. `RepositoryRoot::MARKERS` is `['compose.yaml', 'Makefile']`. The set stays **plural**, which is the
   property its docblock argues for — more than one, so a single rename cannot turn every caller into a
   silent refusal.
2. The docblock no longer describes a marker the tree does not support.
3. **All six gates above pass**, run individually, not inferred from a green sweep. This is the work the
   issue asks for and explicitly did not do: *"the six gates have not been run against that diff"*.
4. `make php.quality.dry-run` — exit 0.
5. Resolution is unchanged in both environments: `/app/repo` inside the container (the whole repository
   arrives through the read-only `./` bind mount) and `dirname($apiRoot)` on a host checkout.

## Falsification

Removing a surviving marker must red something. Before closing, plant each of these and record the result:
delete `'Makefile'` from the set, and delete `'compose.yaml'` — a set that still resolves with either one
missing would mean the plurality AC is decorative.

## Alternatives already discarded in the issue — do not re-open them

- **Keep three markers and narrow the docblock instead.** Cheapest, but leaves the resolver stating a
  property the tree does not support, which this repository generally refuses.
- **Assert the discriminant inside `path()`** rather than only in `RepositoryRootTest`. Strictly
  stronger, but it moves a test's job into the resolver and costs a filesystem check per call.

## Source

GitHub issue #925 (`php`, `refactor`), opened 2026-09-07. Read it before starting: it carries the
measurement, the diff and the reasoning this story compresses.
