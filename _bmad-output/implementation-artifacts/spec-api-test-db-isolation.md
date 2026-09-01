---
title: Test-suite database isolation
status: in-review
branch: fix/api-test-db-isolation-q6sw
---

# Test-suite database isolation

## Problem

`GET /api/v1/me` returned 401 against the local stack. The session row it authenticates against was gone:
the PHP test suite had been resolving the **dev** database and truncating it.

Measured before any change, on the primary checkout:

- `identity_user` held exactly the 13 rows of `api/tests/DataFixtures/Fixtures/User.yaml`, `iam_session` the
  6 seeded sessions — the developer's own session absent.
- `docker compose exec -e APP_ENV=test php bin/console dbal:run-sql "select current_database()"` → `erpify_db`.
- `erpify_db_test` did not exist on the server at all.

## Cause

`api/.env.test` declared a dedicated DSN that bound **one lane and not the other**, and the asymmetry is the
defect rather than the file:

| Lane | Bootstrap | Dotenv call | Resolved |
|---|---|---|---|
| Behat | `api/tests/Behat/bootstrap.php` | `overload()` — overwrites an already-set variable | `erpify_db_test` |
| PHPUnit | `api/tools/phpunit/bootstrap.php` | `bootEnv()` — never overwrites | **`erpify_db`** |

Compose exports `DATABASE_URL` as a real container environment variable, so `bootEnv()` left it alone. A
dozen functional tests `TRUNCATE`/`DELETE` in `setUp()` with no rollback, so the run reported success while
consuming the data.

## Fix

1. `dbname_suffix` in `api/config/packages/test/doctrine.yaml` (env-scoped by its directory, not by a
   `when@test` key) — applied to the resolved connection, so it binds every lane however `DATABASE_URL` arrives.
2. The inert DSN removed from `api/.env.test` (it would stack to `..._test_test` where the file does win).
3. `TEST_TOKEN=_behat` in Behat's bootstrap: one database per lane.
4. `RefuseRuntimeDatabaseGuard`, called from the PHPUnit **file** bootstrap; `FixturesContext::requireDbName()`
   for the Behat lane; `TestDatabaseIsolationTest` as the server-side pin.
5. `make db.test.prepare` / `db.test.reset` / `db.test.shell`.

## Evidence

Measured on a worktree stack. The oracle is a **sentinel row** absent from the fixtures, not a row count: a
purge-and-reload restores the fixture counts exactly, so counts cannot distinguish "untouched" from "destroyed
and re-seeded" — which is how the first version of this table reported a clean Behat run that had in fact
emptied the dev database.

| Measurement | Result |
|---|---|
| `php.unit` + `php.behat` in full, suffix present, dev seeded | sentinel **survives**; 14 users before and after |
| `php.unit`, suffix removed, no guard | dev `identity_user` 13 → **1**, `iam_session` 6 → **4** |
| `php.behat`, suffix removed, guard only in `backupDatabase()` | sentinel **deleted** (1 → 0) while counts stayed at 13 |
| `php.unit`, suffix removed, guard in the file bootstrap, cache **warm** | exit 2, **0 tests executed**, sentinel survives |
| `php.behat`, suffix removed, guard at the top of the hook | exit 2, `Refusing to purge and re-clone "erpify_db"`, sentinel survives |
| `php.unit` guard placed in a PHPUnit `Extension` (both hooks) | refusal printed, suite ran anyway, dev 13 → **1** |
| `db.test.reset` with an unreachable role | exit **2** (before the fix: exit 0, dropped nothing) |
| Final: `php.unit` / `php.behat` / `php.quality.dry-run` | exit **0** / **0** / **0** (3673 tests, 498 scenarios) |

## Adversarial pass

Three layers run in parallel as read-only subagents against the worktree (Blind Hunter, Edge Case Hunter,
Acceptance Auditor), before the PR was opened. Every layer's claims were re-verified against the tree before
being acted on; two were partly wrong and are recorded as dismissed below.

### GRAVE — the recorded diagnosis was false, and the falsehood had entered `CLAUDE.md`

All three layers, independently. The change blamed `make php.behat` and the `FixturesContext` clone. The tree
refutes it: `api/tests/Behat/bootstrap.php:31` calls `Dotenv::overload()`, so the Behat lane had resolved
`erpify_db_test` since 2026-07-28. The destructive lane was **PHPUnit**. Corroborating detail nobody had
weighed: `erpify_db_test` was absent from the server, which is only consistent with Behat never having run
there — under the original story it would have created it. Corrected in six places and the commit message.

### GRAVE — the guard was post-hoc, and two plausible placements do not stop anything

Files execute in sorted-path order; `TestDatabaseIsolationTest` sat at position 22 with twelve destructive
files ahead of it. The two obvious repairs were **measured not to work** — each printed its refusal and then
ran the whole suite, taking dev `identity_user` from 13 to 1:

| Placement | Behaviour |
|---|---|
| `Extension` + `ExecutionStarted` | `Exception in third-party event subscriber`, suite runs |
| `Extension::bootstrap()` | `Bootstrapping of extension … failed`, suite runs |
| `api/tools/phpunit/bootstrap.php` | **`BootstrapLoader` aborts the run — 0 tests, data untouched** |

### SERIOUS — the fix introduced a regression the layers caught: both lanes on one database

Deleting the DSN put the two lanes on the same database, which they had not shared before. Fixed by
`TEST_TOKEN=_behat`, which also makes the `%env(default::TEST_TOKEN)%` in the suffix load-bearing rather than
speculative.

## Code review — second round

Run as three parallel layers over the rebased branch, with the findings above passed in as closed. It found
two GRAVE the first pass did not, both of which invalidated part of the record above.

### GRAVE — the Behat guard was post-hoc too, and the evidence row could not have detected it

All three layers. `requireDbName()` was reached only from `backupDatabase()`, which runs **after**
`loadFixtures()` — and that method runs `hautelook:fixtures:load --purge-with-truncate` and a raw `TRUNCATE`
of the five event-store tables. So the refusal landed after three destructive statements, not "at the
statement that destroys" as three sentences in the diff claimed.

**The measurement that missed it is the lesson.** The evidence row read `identity_user` = 13 and
`iam_session` = 6 before and after, and concluded "dev untouched" — but a purge-and-reload restores exactly
those counts, because the dev database held exactly the fixture set. The oracle could not distinguish
"untouched" from "destroyed and re-seeded", which is the same non-discriminating-instrument error the first
pass had already caught once. Re-measured with a sentinel row absent from the fixtures: **it was deleted**
(1 → 0) while the counts stayed at 13. With the check hoisted to the top of the `#[BeforeScenario]` hook, the
sentinel survives.

### GRAVE — the guard read a container Symfony never freshness-checks, and passed on the mutation it exists to catch

All three layers, from `KernelTrait::initializeContainer()`: `(!$this->debug || $cache->isFresh())`
short-circuits when debug is off, and `ConfigCache::isFresh()` documents it in as many words. `new
Kernel('test', false)` therefore reused a compiled container with the old `dbname_suffix` baked in. Measured:
with the suffix deleted and the container warm, the guard raised **nothing**.

The obvious repair — boot with the suite's own debug flag — was rejected on a cost the layers surfaced
between them: it warms the very container `Erpify\Kernel::getCacheDir()` gives PHPUnit a private cache
directory to keep cold, and that directory exists because a warm container means `failOnDeprecation` never
sees a file-scope deprecation (measured, and documented at `api/src/Kernel.php:40-51`). Booting any kernel
also turns all 32 kernel-free `bin/phpunit --filter` invocations into container compiles under CI's `-j4`.

The guard now **composes the name from its sources** — the DSN's path, `dbname_suffix` read from the config
file, and the lane's `TEST_TOKEN` — with no kernel, no container, no cache and no socket. The cost is stated
rather than hidden: it reproduces what doctrine-bundle's `ConnectionFactory::addDatabaseSuffix()` does, so it
is a second source of truth. For a guard that is a feature — the two disagreeing is a red, and
`TestDatabaseIsolationTest` asks the server what actually happened.

### SERIOUS — `db.test.prepare` migrated ahead of every guard

It is a make prerequisite of the PHPUnit targets, so it runs before the bootstrap loads. With a broken suffix
and an unapplied migration on the branch, the migration would land on the runtime database and the suite
would refuse afterwards. It now runs the same guard first, through
`api/tools/phpunit/assert-test-database.php`.

### SERIOUS — `db.test.reset` could not fail, and then could not work

Two layers found the first half: no `pipefail`, no `ON_ERROR_STOP`, so a failed enumeration or a failed DROP
exited 0 — a reset that dropped nothing while reporting success, against a target whose entire purpose is
recovering a broken schema. Rewritten to a single psql using `\gexec`, with `ON_ERROR_STOP=1` and the
database name bound through `-v` and read as `:'db'` (psql quotes it, so nothing is interpolated into SQL).

**Applying that fix surfaced a defect none of the layers saw**: `$(DOCKER_COMPOSE_EXEC)` expands to
`cd <dir> && docker …`, so the pipe fed `cd` and psql read nothing — the target dropped nothing and exited 0,
the same shape one layer over. Caught by checking the database list before and after rather than the exit
code. Fixed by grouping the exec in a subshell.

### SERIOUS — the guard had no falsification and its call site was unpinned

Deleting the call left every gate green, because `TestDatabaseIsolationTest` proves the connection and never
that the guard ran. `TestDatabaseGuardGateTest` now drives both accept and refuse branches over injected
parameters, asserts both entry points still call it, and asserts `db.test.prepare` calls it **before** its
migrate. Falsified in both directions before being kept. Its own first version compared `strpos` over the
whole makefile and matched `db.migrate`'s migrate line instead — scoped to the target's recipe.

### Applied without further comment

Two live references to a class that never existed (`RefuseRuntimeDatabaseExtension`), both asserting the
refuted extension placement; "27 gates" corrected to the measured 32 invocations across 20 targets;
"under `when@test`" corrected (the file is env-scoped by its directory); "a throwable in this file is fatal"
corrected to what PHPUnit actually does (`BootstrapScriptException` → `Result::EXCEPTION`); `db.test.reset`
described three different ways, aligned to what the recipe does; `(destructive)` added to its `##` description
per `make/CONVENTIONS.md` §9; `RATE_LIMIT_*` dropped from a list of keys compose exports (it does not); the
quickref sentence given its own paragraph instead of being concatenated onto the "Individual linters:" one;
"in the order they fire" corrected (the guards are per-lane and never all fire); a stray blank line in
`api/config/packages/doctrine.yaml`; and one change-relative phrase in a docblock reframed.

### Dismissed

- **Widen `FixturesContext`'s explicit `TRUNCATE` to `dek_keystore` and `messenger_messages`.** One layer
  argued the first pass dismissed this on the wrong axis, and it is right that lane separation removes only
  PHPUnit's contribution: both tables are raw DBAL with no ORM entity, so rows Behat itself writes still reach
  the clone. But that is **pre-existing and unchanged by this branch** — it was equally true when Behat owned
  `erpify_db_test` alone, which it has since July. Widening the statement here would be an unrelated fix
  riding a database-isolation change, and `messenger_messages` may be created lazily by the transport rather
  than by a migration, which would make the statement fail on a fresh database. Left alone deliberately, and
  recorded here rather than filed as an issue, since the diff that would close it is not this one.
- **`db.test.prepare` makes filtered runs need a database.** The 32 `php.lint.*` invocations call
  `bin/phpunit --filter` directly and never go through `php.unit`, so the prerequisite does not reach them —
  verified. The narrower true statement, which one layer added: `make php.unit c='--filter SomeTest'`, the
  documented invocation, now does require a reachable database.

## Residual, not closed

- All three guards check the database **name** for a `_test` marker. A runtime database itself spelled that
  way would satisfy them; no environment in this repository is.
- The guards prove what the **sources declare**; only `TestDatabaseIsolationTest` proves what was
  **connected**, and it runs in the PHPUnit lane only.
- `db.test.reset` and `db.test.shell` derive the database name from `POSTGRES_DB` as make's shell sees it,
  which is what `db.shell` has always done — not from `DATABASE_URL`. A per-developer override in
  `api/.env.local` or `api/.env.test.local` moves the suite's database without moving theirs, and the sweep
  then matches nothing. Stated in the recipe comment; both targets are container-only.
- A `DATABASE_URL` in an untracked `.env.test.local` that already ends in `_test` yields `..._test_test`. It
  is isolated, so no guard refuses it; documented at the two places that invite such an override.
- `make ENV=prod php.unit` is refused by `guard_var_writable` on the prepare step, not by anything in the
  suite itself.
- `docs/project-context.md` still asserts "Wrap each test in a transaction or reset via migrations/fixtures",
  which this suite demonstrably does not do — noticed by a review layer, out of scope for this branch, and
  recorded here rather than fixed silently.
