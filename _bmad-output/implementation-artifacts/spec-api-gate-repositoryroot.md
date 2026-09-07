---
title: 'Test-database gate resolves its root through the shared helper'
type: 'refactor'
created: '2026-09-03'
status: 'done'
route: 'one-shot'
---

# Test-database gate resolves its root through the shared helper

## Intent

**Problem:** `TestDatabaseGuardGateTest` carried a private `repositoryRoot()` written before
`api/tests/Support/RepositoryRoot.php` existed, while five sibling gates already resolve through the shared
helper. Converging is not a straight swap — `RepositoryRoot::MARKERS` is `compose.yaml`/`Makefile`/`CLAUDE.md`,
so `path()` proves the repository ROOT is reachable and says nothing about `make/db.mk`, the file this gate
actually parses.

**Approach:** Delegate resolution to the helper and move the subject check into a `read()` helper matching the
siblings' shape, so a missing `make/db.mk` stays a named failure instead of degrading into a type error two
lines later. The code review then found three false greens in the surrounding assertions, each measured before
and after, and all three are fixed here rather than deferred.

## What the review found

Three parallel layers ran on the branch — Blind Hunter, Edge Case Hunter and Acceptance Auditor — against the
worktree at `.claude/worktrees/api-gate-repositoryroot-erft`. Two findings were reached independently by two
layers each, which is what made them worth the cost.

| False green | Measured before | Measured after |
|---|---|---|
| A commented-out guard call satisfied the entry-point match | `str_contains` true — entry point unprotected, gate green | red at the assertion, file still parsing |
| Guard command deleted, a comment naming it left before the migrate | **9/9 green** over the invariant the gate defends | red: "no longer runs the database guard" |
| `make/db.mk` present but a directory | both assertions pass, dies reporting "declares no `db.test.prepare` target" | red: "no regular file is there" |

The second is the sharpest: `recipeOf()`'s slice spans `make/db.mk` 39-69, of which 25 lines are comments and
the tail belongs to `db.test.reset`, so both needles could match prose.

**What a green still does not prove:** that either entry point EXECUTES its call. Stripping comments closes the
commented-out vector; a call after an early `return`, or under a condition never true, still passes. Reading a
file cannot establish execution — that needs a different instrument, and the class docblock says so.

## Falsification

Six directions, each red observed and restored by copying saved bytes, never `git checkout --`:
guard call deleted; guard moved after the migrate; `make/db.mk` absent; plus the three false greens above.
Two counterfactuals were measured rather than asserted — without `assertFileExists` the absent case degrades to
`false is not of type string`, and without the comment strip the deleted guard passes 9/9.

## Suggested Review Order

Start here — the whole design decision is the split between what the shared helper proves and what it does not:

  [TestDatabaseGuardGateTest.php:255](../../api/tests/Unit/Gate/TestDatabaseGuardGateTest.php#L255)

The subject check the migration would otherwise have lost; `is_file`, not `assertFileExists`:

  [TestDatabaseGuardGateTest.php:276](../../api/tests/Unit/Gate/TestDatabaseGuardGateTest.php#L276)

Text matchers that could not tell a call from prose about one — the two measured false greens:

  [TestDatabaseGuardGateTest.php:186](../../api/tests/Unit/Gate/TestDatabaseGuardGateTest.php#L186)
  [TestDatabaseGuardGateTest.php:237](../../api/tests/Unit/Gate/TestDatabaseGuardGateTest.php#L237)

What the gate's green does not prove, stated where the next reader will look:

  [TestDatabaseGuardGateTest.php:24](../../api/tests/Unit/Gate/TestDatabaseGuardGateTest.php#L24)

A hand-kept count this change would have made wrong; removed rather than incremented:

  [RepositoryRootTest.php:14](../../api/tests/Unit/Support/RepositoryRootTest.php#L14)
