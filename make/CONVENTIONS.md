# Make System Conventions

The root `Makefile` and `make/*.mk` modules are ERPify's canonical task interface. Every dev workflow, CI job, and IDE Run Configuration invokes the same target names. These conventions exist so the system stays coherent as it grows — they are load-bearing.

**If you can't make a change without violating one of these rules, open a discussion first. Don't silently break the contract.**

---

## 1. Namespacing

Target names follow `<domain>.<noun>[.<variant>]` with dots as separators.

```
docker.up               # domain.noun
docker.up.wait          # domain.noun.variant
php.cs-fixer.dry-run    # domain.noun.variant
pwa.test.e2e.reports    # domain.noun.variant.sub-variant
```

Rules:

- **One canonical name per command. Zero aliases.** Not `up` for `docker.up`, not `pwa.e2e` for `pwa.test.e2e`.
- Use dots (`.`), never colons (break Make), hyphens (no hierarchy), or camelCase.
- Hyphens *within* a segment are fine when they match the upstream tool: `php.cs-fixer`, `php.composer-unused`, `docker.up.wait`.
- Dry-run / check-only variants use `.dry-run`. Fix / apply variants have no suffix (apply is the default action).
- Test suite split uses `.unit` and `.e2e`. A bare `<domain>.test` is always the aggregate.

**Reserved top-level names** (no namespace, top of `make help`):

| Name   | Meaning                             |
|--------|-------------------------------------|
| `dev`  | Start the stack and open a browser  |
| `test` | Run the full test suite (API + PWA) |
| `lint` | Run all linters (API + PWA)         |
| `ci`   | Run the full CI pipeline locally    |
| `help` | Print grouped target help           |

These exist because a first-day contributor guesses them without reading docs. Do not add more.

---

## 2. Self-documenting help

`make help` is generated from the source files themselves — no external manifest, no registry.

### The three marker types

```makefile
## —— Section Title ——    # Groups targets under a heading in `make help`

target.name: ## One-line description shown after the target name
	recipe

internal.helper:           # No `##` — hidden from help
	recipe
```

Rules:

- **Every public target MUST have a trailing `## <description>`.** No exceptions. If a target isn't meant to be called by humans, omit the `##` and it stays hidden.
- **Every module MUST group its public targets under at least one `## —— Section ——` header.** Section headers drive the grouping in `make help`.
- Descriptions are one line, imperative mood, end without a period ("Run PHPUnit", not "Runs PHPUnit.").
- Document side effects in the description: `(destructive)`, `(requires secrets)`, `(destructive: drops volumes)`.
- Passthrough args and env knobs go in the description in backticks: `pass c='…'`, `CI_SHARD=N CI_TOTAL_SHARDS=M for sharded runs`.

### Do not

- Do not parse help output in scripts. If you need machine-readable target metadata, add a `help.json` target instead.
- Do not skip the section header because "this module only has two targets." Empty sections are fine; missing sections break grouping for *other* modules.
- Do not add color codes, emoji, or multiline descriptions to `##` comments — the awk parser in `help.mk` only handles one plain line.

---

## 3. Module layout

Each module in `make/` owns one domain. The active modules:

| Module           | Owns                                                     |
|------------------|----------------------------------------------------------|
| `config.mk`      | Variables only, no targets                               |
| `docker.mk`      | Stack lifecycle (`docker.*`)                             |
| `dev.mk`         | Developer ergonomics (`dev`, `dev.local`, `xdebug.*`)    |
| `api.mk`         | Composer, Symfony console, Messenger                     |
| `db.mk`          | Doctrine migrations, fixtures, psql                      |
| `php-test.mk`    | PHPUnit, Behat                                           |
| `php-lint.mk`    | PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS, Psalm       |
| `pwa.mk`         | Next.js install/dev/build/test/lint                      |
| `ci.mk`          | CI aggregates only (composes other modules)              |
| `super-lint.mk`  | GitHub SuperLinter (Docker)                              |
| `help.mk`        | `help` + `help-targets` (always last in include order)   |

### Splitting a module

Split when a single file exceeds ~100 lines *and* has two clearly separable concerns (e.g., composer vs symfony console — both live in `api.mk` today, but if Composer grows to 10 targets, split it out).

Never split for cosmetic reasons. Fewer files beats more files.

### Adding a module

1. Create `make/<name>.mk`.
2. Add `include make/<name>.mk` to the root `Makefile` in the correct spot (config first, help last, everything else alphabetical by domain).
3. Put at least one `## —— Section ——` header inside.
4. Add `.PHONY:` for every target in the module, at the bottom of the file.

### Not allowed

- No target definitions outside `make/*.mk`. The root `Makefile` contains includes and the three top-level aggregates (`lint`, `test`) plus `.DEFAULT_GOAL`.
- No cross-module variable definitions. All shared vars live in `config.mk`.
- No recipe that shells out to another `make/*.mk` file directly. Depend on targets, not files.

---

## 4. Passing arguments (`c='…'`)

Every target that forwards arguments to an underlying tool uses the `c` variable pattern:

```makefile
composer: ## Run composer; pass c='…'
	@$(eval c ?=)
	@$(COMPOSER) $(c)
```

Usage:

```bash
make composer c='require vendor/pkg'
make php.unit c='--filter SomeTest'
make sf c='debug:container'
```

Rules:

- **Never post-process the underlying tool's output** — no `tee`, no `grep`, no color stripping. PHPStorm's test runner, ESLint's stylish reporter, and Playwright's reporter all need raw output.
- **Never add default arguments that the user can't override.** If you need a sensible default, document it in the description and let `c='…'` replace it.
- `c=` is for passthrough. For target-specific knobs (filters, modes), use distinct variables: `f=` for `make routes`, `cmd=` for `make docker.exec`, `CI_SHARD=` / `CI_TOTAL_SHARDS=` for sharded E2E.

---

## 5. Environment knobs

| Variable         | Values                   | Default       | Purpose                                                     |
|------------------|--------------------------|---------------|-------------------------------------------------------------|
| `ENV`            | `dev`, `staging`, `prod` | `dev`         | Chooses Compose overlay                                     |
| `IN_CONTAINER`   | `true`, `false`          | `true`        | `false` = run PHP on host instead of `docker compose exec`  |
| `OPEN_BROWSER`   | `0`, `1`                 | `1`           | `0` skips `xdg-open` / `open` in `dev` and `prod-up`        |
| `CI_SHARD`       | `1..N`                   | unset         | Playwright shard index (requires `CI_TOTAL_SHARDS`)         |
| `CI_TOTAL_SHARDS`| `N`                      | unset         | Playwright total shard count (requires `CI_SHARD`)          |
| `c`              | any string               | empty         | Passthrough args to the underlying tool                     |
| `f`              | any string               | empty         | Filter for `routes`                                         |
| `cmd`            | any string               | empty         | Command for `docker.exec`                                   |

Rules:

- **`ENV=ci` is not a supported value.** CI jobs set `ENV=dev` (which they do by omission). If CI eventually needs dedicated Compose behavior, introduce `compose.ci.yaml` and re-add `ENV=ci` deliberately.
- Do not introduce new top-level knobs without updating `make help` and this table.
- Target-specific knobs stay local to the target, not in `config.mk`.

---

## 6. CI invocation pattern

`.github/workflows/ci.yml` calls `make <target>` verbatim. This is the contract.

Rules:

- **CI never invokes `docker compose`, `composer`, `npm`, `phpunit`, or any tool directly** — except for the one-time stack bring-up in CI-specific jobs that pre-date Make entry points (those are explicitly allowed and flagged as such).
- **CI never sets `ENV=ci`.** See #5.
- When CI needs different defaults (e.g., skip PHPStan for speed), add a `ci.*` target in `ci.mk` that composes the right subset. Do *not* branch on an `IS_CI` variable inside a general-purpose target.
- Every target invoked by CI has a test: running it locally reproduces the CI behavior exactly. If it doesn't, the target is wrong — not the CI job.
- Renaming a CI-invoked target requires updating `ci.yml` in the same PR. Grep before renaming: `rg "make " .github/workflows/`.

### `ci.*` namespace

Reserved for CI-tuned variants of domain targets:

- `ci` — full pipeline (`ci.lint` + `ci.test`)
- `ci.lint`, `ci.test` — aggregate linters / tests
- `ci.api`, `ci.pwa` — side-specific aggregates
- `ci.php.lint` — CI-fast PHP lint (skips PHPStan)

Targets in this namespace do not have dev-facing variants. If devs want CI behavior, they run `ci.*` directly.

---

## 7. IDE integration

The Make system must run identically from:

1. A terminal (`zsh`, `bash`) with a full login shell.
2. PHPStorm External Tools and Run Configurations (minimal PATH, stripped environment).
3. VS Code tasks.
4. GitHub Actions runners.

Three rules guarantee this:

### 7.1 Host-tool targets go through a login-shell wrapper

Any target that calls `npm`, `node`, `npx`, or another host-installed binary that isn't on PHPStorm's stripped `PATH` MUST use `$(pwa_cmd)` (defined in `config.mk`).

The wrapper:

1. Prefers `zsh -lc` or `bash -lc` so `nvm` / `fnm` / Homebrew are sourced.
2. Falls back to an inline `PATH` extension covering the common Node install locations.

Do not add a second wrapper. If you have a new host tool that isn't covered, extend `pwa_cmd`.

### 7.2 Targets are CWD-independent

Every recipe that touches the filesystem or shells out MUST `cd` to an absolute path (`$(PROJECT_ROOT)`, `$(API_ROOT)`, `$(PWA_ROOT)`). Never assume `make` was invoked from the repo root.

Reason: PHPStorm External Tools sometimes run from the opened file's directory. `cd $(PROJECT_ROOT) && ...` fixes this permanently.

### 7.3 Tool output reaches stdout unchanged

See #4 — no post-processing. PHPStorm, Playwright's HTML reporter, and ESLint's `--format=json` all depend on raw tool output. A single `| tee` in the wrong place breaks IDE integration silently.

### 7.4 Checked-in Run Configurations

`.idea/runConfigurations/` contains Run Configurations for the most common targets (`dev`, `test`, `lint`, `php.unit`, `pwa.test.e2e`, `docker.up`, `docker.down`, `db.reset`, `ci`). These are the onboarding artifact — a new dev opens the project in PHPStorm and can run everything without reading any docs.

Do not delete these. Update them when target names change.

---

## 8. PWA test execution (host-only)

Both Vitest (unit) and Playwright (E2E) run on the host, via `$(pwa_cmd)`. There is no container variant. This is deliberate, not accidental — do not add `IN_CONTAINER` branching, a `pwa.test.*.docker` variant, or a dedicated `playwright` Compose service without revisiting the rationale below.

### Rationale

- **Linux-only dev machines.** Dev hosts match the CI runner's OS (`ubuntu-latest`). The strongest reason to containerize E2E tests — reproducing the CI OS for rendering and screenshot parity — does not apply here.
- **No visual-regression assertions.** The suite does not use `toHaveScreenshot` or `toMatchSnapshot`. Sub-pixel font rendering and antialiasing differences between environments would not cause flakes.
- **Dev-loop speed.** Vitest watch mode and Playwright iteration are inner-loop activities. `docker compose exec` adds cold-start and volume-mount FS overhead on every invocation, paid hundreds of times per day.
- **IDE integration.** PHPStorm's native Vitest and Playwright test runners — gutter icons, breakpoints, test-tree — require host execution. Containerizing Vitest silently breaks them.
- **CI speed is not a containerization problem.** CI wall-clock time is bounded by (a) Playwright sharding via `CI_SHARD`/`CI_TOTAL_SHARDS` (in place), (b) Playwright browser cache via `actions/cache` (in place), (c) Node dependency cache in the `node-setup` action (in place). Adding a container layer adds cold-start overhead to every job; it does not make CI faster.
- **Maintenance surface.** One codepath, one wrapper (`pwa_cmd`), one mental model. A container variant doubles the surface for no reproduction benefit.

### Prerequisite

Dev machines must have Node installed (version pinned in `pwa/.nvmrc` / `pwa/package.json#engines`). Document this as an onboarding prereq in `docs/development-guide-pwa.md`. This is the trade the policy makes in exchange for the speed and IDE wins above.

### When to revisit

Any of the following invalidate the rationale and should trigger a re-open:

- A non-Linux dev machine joins the team (macOS or Windows-via-WSL).
- The suite adopts `toHaveScreenshot` / `toMatchSnapshot` — CI/dev rendering drift becomes a real flake source.
- Playwright browser versions pinned in CI diverge from what dev machines resolve (e.g., distro package vs npm-managed browser).
- A CI runner image change introduces a libc or kernel delta that dev machines don't track.

If re-opened, the preferred response is containerizing **Playwright only**, pinned to Microsoft's official `mcr.microsoft.com/playwright:v<version>-noble` image — not a general `IN_CONTAINER=true` switch on PWA targets, and not reusing the `pwa` Compose service.

---

## 9. Destructive targets

Any target that deletes data, drops volumes, or resets state MUST:

- End with `(destructive)` in its `##` description.
- Not be invoked transitively by a non-destructive target. `db.reset` is called by the human, never by `test` or `ci`.
- Not be the default recipe for anything. `.DEFAULT_GOAL := help`, full stop.

Current destructive targets:

- `docker.clean` — drops Compose volumes
- `db.reset` — drops schema and re-seeds
- `pwa.clean` — removes `node_modules`, `package-lock.json`, `.next`

---

## 10. Deprecation

Module files scheduled for removal contain a single-line deprecation comment and no targets. They stay on disk until the team confirms nothing external references them, then they're deleted.

**Do not restore deprecated targets.** If you need a removed behavior, re-add it under the current naming scheme.

Current deprecated files (consolidated into the active modules):

- `composer.mk` → `api.mk`
- `php.mk` → `api.mk` + `db.mk`
- `php-linters.mk` → `php-lint.mk`
- `js.mk`, `js-test.mk`, `js-lint.mk`, `npm.mk` → `pwa.mk`
- `utils.mk` → `help.mk` + `dev.mk`
- `super-linter.mk` → `super-lint.mk`

---

## 11. Pre-commit checklist for Make changes

Before you commit a change under `Makefile` or `make/`:

- [ ] `make help` renders cleanly and groups your change under the correct section.
- [ ] New targets have a `## <description>` comment.
- [ ] New modules are included in the root `Makefile` in the right spot.
- [ ] `.PHONY:` updated for any new targets.
- [ ] `make --dry-run <new-target>` expands to the expected command.
- [ ] If a target is invoked by CI, `.github/workflows/ci.yml` was updated in the same commit.
- [ ] No new aliases (multiple target names for the same recipe).
- [ ] No post-processing of tool output (`tee`, `grep`, color strippers).
- [ ] Destructive targets flagged `(destructive)` in the description.
- [ ] Tab-completion still works: `make <TAB>` lists the new target.

---

## 12. When to change these conventions

These rules are a floor, not a ceiling. They exist because past drift cost the team a working build.

Change them when:

- A new external constraint forces a rule to break (a CI platform that can't invoke Make, a new IDE with different launch semantics).
- The team agrees the rule no longer pays for itself.
- You're introducing a genuinely new pattern that doesn't fit the existing shape (e.g., adopting a task-runner tool alongside Make).

Don't change them:

- Because it feels nicer on a Tuesday afternoon.
- To accommodate a one-off workflow. Write a shell script outside `make/` instead.
- Without updating this document and `make help` in the same PR.
