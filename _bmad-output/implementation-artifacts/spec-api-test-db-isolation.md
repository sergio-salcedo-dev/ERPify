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

1. `dbname_suffix` under `when@test` in `api/config/packages/test/doctrine.yaml` — applied to the resolved
   connection, so it binds every lane however `DATABASE_URL` arrives.
2. The inert DSN removed from `api/.env.test` (it would stack to `..._test_test` where the file does win).
3. `TEST_TOKEN=_behat` in Behat's bootstrap: one database per lane.
4. `RefuseRuntimeDatabaseGuard`, called from the PHPUnit **file** bootstrap; `FixturesContext::requireDbName()`
   for the Behat lane; `TestDatabaseIsolationTest` as the server-side pin.
5. `make db.test.prepare` / `db.test.reset` / `db.test.shell`.

## Evidence

| Measurement | Result |
|---|---|
| `php.unit` (3528 tests), suffix present, dev seeded | dev `identity_user` 13 → **13**, `iam_session` 6 → **6** |
| `php.unit`, suffix removed, no guard | dev `identity_user` 13 → **1**, `iam_session` 6 → **4** |
| `php.unit`, suffix removed, guard in file bootstrap | exit 2, **0 tests executed**, dev **13 / 6** untouched |
| `php.behat`, suffix removed | exit 2, `Refusing to purge and re-clone "erpify_db"`, dev untouched |
| `php.unit` / `php.behat` / `php.quality.dry-run`, final | exit **0** / **0** / **0** (3528 tests, 488 scenarios) |
| Guard cost, container cached | 500 ms → 521 ms per filtered invocation (~20 ms) |

## Adversarial pass

Three layers run in parallel as read-only subagents against the worktree (Blind Hunter, Edge Case Hunter,
Acceptance Auditor), before the PR was opened. Every layer's claims were re-verified against the tree before
being acted on; two were partly wrong and are recorded as dismissed below.

### GRAVE — the recorded diagnosis was false, and the falsehood had entered `CLAUDE.md`

All three layers, independently. The change blamed `make php.behat` and the `FixturesContext` clone. The tree
refutes it: `api/tests/Behat/bootstrap.php:31` calls `Dotenv::overload()`, so the Behat lane had resolved
`erpify_db_test` since 2026-07-28. The destructive lane was **PHPUnit**. Corroborating detail nobody had
weighed: `erpify_db_test` was absent from the server, which is only consistent with Behat never having run
there — under the original story it would have created it.

The first `\l` measurement did not discriminate between the two hypotheses, and the post-fix
`erpify_db_test_behat_backup` observation was worthless as evidence because the name is identical either way.
Corrected in six places (`CLAUDE.md`, `docs/claude-code-quickref.md`, `docs/development-guide-api.md`, the
config comment, `api/.env.test`, the test docblock) and the commit message. The durable *why* survives the
correction and is a stronger argument for the suffix than the original one: two bootstraps disagree about
which file wins, so no DSN can bind both lanes — only a suffix on the resolved connection can.

### GRAVE — the guard was post-hoc, and two plausible placements do not stop anything

Files execute in sorted-path order; `TestDatabaseIsolationTest` sat at position 22 with twelve destructive
files ahead of it. It reported the damage in the past tense. Worse, the two obvious repairs were **measured
not to work** — each printed its refusal and then ran the whole suite anyway, taking dev `identity_user` from
13 to 1:

| Placement | Behaviour |
|---|---|
| `Extension` + `ExecutionStarted` | `Exception in third-party event subscriber`, suite runs |
| `Extension::bootstrap()` | `Bootstrapping of extension … failed`, suite runs |
| `api/tools/phpunit/bootstrap.php` | **fatal — 0 tests executed, data untouched** |

The guard reads the resolved connection parameters and deliberately does not connect: CI fans out 27
kernel-free `php.lint.*` gates through `bin/phpunit --filter` during `php.quality.dry-run`, before
`db.test.prepare` has created anything.

### SERIOUS — the fix introduced a regression the layers caught: both lanes on one database

Before the change the lanes were on different databases by accident; deleting the DSN put them on the same
one. `FixturesContext` DROPs and re-clones what it connects to, so `make -j php.test` could kill an in-flight
PHPUnit run mid-query, and PHPUnit's leftover rows would be baked into the per-feature backup. Fixed by
`TEST_TOKEN=_behat`, which restores one database per lane and makes the `%env(default::TEST_TOKEN)%` in the
suffix load-bearing instead of speculative — closing a separate YAGNI finding against it.

### SERIOUS — the destructive lane had no guard of its own

`make php.behat` runs no PHPUnit, and neither does CI's `api-behat` job, so the suite's guard never executes
there. `FixturesContext::requireDbName()` now refuses at the statement that destroys, which also covers
`vendor/bin/behat` and an IDE run configuration.

### Applied without further comment

Stale comments corrected in `api/tests/Behat/bootstrap.php` (3) and `FixturesContext` (1); the `.env.test.local`
double-suffix hazard documented in three places; `db.test.reset` and `db.test.shell` added (`db.drop`/`db.reset`
run under `APP_ENV=dev` and cannot reach a test database); `guard_var_writable` on both new destructive
targets; the suffix moved out of the shared `doctrine.yaml` into the env-scoped file a reader actually opens;
`MAILER_DSN` and `DEFAULT_NOTIFICATION_EMAIL` forced in `phpunit.dist.xml`, since compose was shadowing the
declared null transport and the "deterministic, non-personal" recipient with a real personal address; the
`make/db.mk` comment that stated a derived guarantee as a primitive one; recipe idiom aligned to
`$(PHP_TEST) bin/console`.

### Dismissed

- **Widen `FixturesContext`'s explicit `TRUNCATE` to cover `dek_keystore` and `messenger_messages`** — real
  while the lanes shared a database, dissolved by giving each its own. Adding it would pin a coupling that no
  longer exists.
- **`db.test.prepare` makes filtered runs need a healthy database** — the 27 `php.lint.*` gates call
  `bin/phpunit --filter` directly and never go through `php.unit`, so the prerequisite does not reach them.
  Verified against `make/php-quality.mk`.

### Found while applying, not by any layer

The first `db.test.reset` did not work: `PHP_BEHAT` does not set `TEST_TOKEN` (the Behat bootstrap does, and
`bin/console` never loads it), so its drop hit the PHPUnit database instead, and `${POSTGRES_DB}` inside
single quotes never expanded. Rewritten to sweep by prefix with `starts_with`, which also reclaims the clone
and any ParaTest-token orphan, and cannot match the runtime database because the prefix contains `_test`.

## Residual, not closed

- All three guards check the database **name** for a `_test` marker. A runtime database itself spelled that
  way would satisfy them; no environment in this repository is.
- The guard proves what was **configured**; only `TestDatabaseIsolationTest` proves what was **connected**,
  and it runs in the PHPUnit lane only.
- `make ENV=prod php.unit` is refused by `guard_var_writable` on the prepare step, not by anything in the
  suite itself.
