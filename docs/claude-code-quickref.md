# Claude Code — quick reference

Long-form reference for Claude Code. The root [`../CLAUDE.md`](../CLAUDE.md) keeps the load-bearing rules and a top-hits cheat sheet; this file holds the full command catalog, layout tables, "adding new code" recipes, and gotchas. `make help` is the source of truth (canonical list grouped by section); this file is curated for readability.

---

## Full command catalog

The root `Makefile` is the canonical interface, including the modules in `make/*.mk`. **Prefer `make` targets** over invoking `docker compose`, `composer`, `npm`, or linters directly: targets decide whether to exec inside the `php`/`pwa` container based on `ENV` (`dev`, `ci`, `staging`, `prod`) and `IN_CONTAINER`. **Always invoke from the repo root.**

### Stack

```bash
make app.dev             # Full dev stack (down → install → up --wait → fix ownership)
make docker.up           # Start stack detached, rebuild images (ENV=dev|staging|prod).
make docker.up.wait      # Same, with --wait health gate.
make docker.down         # Stop stack and remove orphans.
make docker.logs         # Follow compose logs (all services).
make docker.ps           # Compose ps.
make docker.info         # Show this checkout's resolved stack identity (project + host ports).
make php.bash            # Shell into the php container (also: make php.sh, make php.exec cmd='…').
make docker.down.clean-volumes  # Stop stack and REMOVE volumes (destructive).
make docker.worker.cache.reset  # Drop the messenger_worker's PRIVATE compiled-container cache. Its factories
                                # live in a volume of their own, so a changed constructor signature boot-loops
                                # it on ArgumentCountError (exit 255) while the web container stays healthy —
                                # and neither a restart nor `make sf.cc` reaches it. `make app.dev` already
                                # runs this between the down and the up. Detail: docs/troubleshooting/sentry-messenger-worker-dev-cache-crash.md
make docker.prune        # Prune ALL Docker images/volumes/containers system-wide (destructive).
make prod.env.check      # Validate .env.prod.local has all required prod secrets (no placeholders).
make deploy.local        # Stand up the PROD profile at https://erpify.local (preflight → up → migrate → smoke → CA export + trust guidance).
sudo make deploy.local.trust  # Privileged client-trust steps (hosts + system CA + Chromium NSS); targets $SUDO_USER. Don't `sudo make deploy.local`.
make backup.prod         # Prod backup: verified pg_dump of the database (BACKUP_DIR, RETENTION_DAYS, BACKUP_SYNC_CMD). Runbook: docs/vps-deployment.md § Backups.
STAMP=<s> make restore.prod  # Restore (DESTRUCTIVE) for the drill / pre-prod check; verifies the dump. Prod target needs ALLOW_PROD_RESTORE=1 + typed confirm. Runbook: § Backups.
```

Prod/staging load secrets from a gitignored root `.env.prod.local` (copy from [`.env.prod.example`](../.env.prod.example)) via `--env-file`. Runbook: [`erpify-local-test-deployment.md`](erpify-local-test-deployment.md); security gate: [`../PRODUCTION_SECURITY_CHECKLIST.md`](../PRODUCTION_SECURITY_CHECKLIST.md).

### API / PHP

```bash
make composer c='req vendor/pkg'    # Run composer inside the container.
make sf c='about'                   # Symfony console (also: make sf.cc, make sf.routes f='…', make sf.about).
make sf c='organization:provision <name>'              # Bootstrap the installation's single organization (one per install; rejects a 2nd).
make sf c='organization:administrator:create <email>'  # Bootstrap the first ADMIN (identity + ADMIN membership); hidden password prompt if omitted.
make sf c='identity:integrity:inspect'                 # Report identity rows the auth path tolerates silently: role values no Role case backs, credentials HashedPassword refuses. SUCCESS clean / FAILURE finding / INVALID the read failed. Counts, never ids.
make profiler.open                  # Open the Symfony Profiler UI (/_profiler/latest) in the browser (dev).
make profiler.dump-server           # Start the var-dumper server: collects dump() out-of-band (dev).
make php.test                       # PHPUnit + Behat. Pass c='…' for extra args.
make php.unit c='--filter SomeTest' # PHPUnit only.
make php.behat c='features/...'     # Behat only.
make php.bench                      # Opt-in performance-budget benchmarks (default php.unit skips).
make php.quality                    # Full sweep: PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS.
make php.stan                       # PHPStan only — REQUIRED on every PHP file you change.
make composer.update                # Safe dependency update (within composer.json ranges).
make composer.upgrade               # Force-upgrade direct deps to latest (bumps constraints across majors).
make db.migrate                     # Run pending Doctrine migrations.
make db.diff                        # Generate migration from entity/schema diff.
make db.status                      # Migration status.
make db.validate                    # Validate ORM mapping against the database.
make db.load.fixtures               # Load Hautelook Alice fixtures.
make db.reset                       # Drop → migrate → fixtures (destructive).
make db.shell                       # Interactive psql (CLI, any ENV — uses docker exec, no host port).
make db.tunnel                      # Expose prod/staging DB on 127.0.0.1:15432 for a GUI client (pre-prod only; db.tunnel.stop to remove).
make xdebug.enable                  # Toggle Xdebug in api/.env (also xdebug.disable, xdebug.status).
```

Individual linters: `php.rector[.dry-run]`, `php.cs-fixer[.dry-run]`, `php.md`, `php.cs[.dry-run]`, `composer.check.all`. Error-contract drift gate: `php.lint.error-contract` (FR50/FR51/NFR26). Bounded-context isolation gate: `php.lint.bounded-context` (Level 1 cross-context `Domain`/`Application`/`Infrastructure` import fails; Level 2 cross-context FK warns; seams in `api/.bounded-context-allowlist`). Persistent-transport policy gate: `php.lint.persistent-transport` — an "aggregate id alone" payload is safe on a persisted transport only if the aggregate is not a natural person; classification in `api/.persistent-transport-policy`, cross-checked against every `framework.messenger.routing` map Symfony loads by resolving each event to the transports it would really be sent through (class parents, interfaces, namespace wildcards, `'*'`, and `#[AsMessage]` incl. inherited and repeated). Blind spots are listed in the registry header — `TransportNamesStamp`, non-`DomainEvent` messages, PHP config (tripwired), and the payload rather than the aggregate id. Person-reference erasure gate: `php.lint.person-reference` — every `Types::GUID` column an entity declares is classified in `api/.person-reference-policy` as `non-person` or `person :: <file that erases it>`, and declared at the property with `#[PersonSubjectReference]`; nothing in the schema references `identity_user`, so deleting the identity cascades nowhere and leaves the id in every table nobody was told to clean. Four checks: completeness (universe ⊆ registry), staleness (registry ⊆ universe), wiring (the owner holds a collaborator for the entity AND calls a deletion on it, matched on comment-stripped source) and attribute-vs-registry agreement. The same target also carries the **detective** half: every `person ::` column (bar the subject's own primary key) must have a `Shared\Privacy\Application\PersonReferenceSource` declaring that key — a read-only `DISTINCT` list of the ids the column still holds, tagged `erpify.person_reference_source` and collected by `identity:gdpr:reconcile-subject-references`, which reports the ones resolving to no live identity. Compared in both directions, plus one-source-per-key and the tagged-iterator wiring; `audit_log.resource_id` stays outside the collection (no entity, so never a registry key) as a collaborator of its own. Blind spots in the registry header — it never judges the classification, misses references born in configuration and tables with no Doctrine entity (`audit_log.*`, `event_store.aggregate_id`), only sees `Types::GUID`, proves a call exists rather than that it runs, and proves a source is collected rather than that its query reads the right column. Audit erasure-evidence gate: `php.lint.audit-evidence` — every action an `AuditLogger` collaborator declares as a string constant is classified `evidence`/`ordinary` in `api/.audit-evidence-actions`, and the `evidence` half must equal `AuditErasureEvidence::ACTIONS`, the closed set the retention prune exempts for ever. Evidence may not expire before the thing it attests: the `dek_keystore` tombstone a `GDPR_SUBJECT_ERASED` answers for is eternal and the reconciler anti-joins the two with no date bound, so an aged-out proof reports every crypto-shredded subject as a permanent divergence. Centralising the literals stopped a token *drifting*, never a member going *missing*, and that omission fails toward deletion. An unrecognised verdict and a duplicated token throw rather than defaulting (anything not exactly `evidence` would read as ordinary); then completeness, staleness and both directions of agreement. Two classes, each selected by exact name in its own run, so a vanished one is an empty suite rather than a green subset. Blind spots in the registry header: it never judges a classification (`ordinary` over real evidence passes), and it sees only **constructor**-injected collaborators and only **string constants** — a setter-injected logger, an action inlined at the call site, or a backed enum is invisible, as are the route-derived `ROUTE_*` actions, which are outside it by design. Person-resource erasure gate: `php.lint.audit-resource` — every audit `resource_type` classified person-denoting or not in `api/.audit-resource-types`; a `person` type must name an erasure use case that wires `AuditResourceAnonymiser` and an acceptance scenario seeding a row of that type and asserting none survives. Three classes (assertions over the real tree, plus falsifiability of the registry rules and of the witness rule) selected by a common prefix. Audit prune statement: the only `DELETE` on `audit_log` takes `ORDER BY id LIMIT :batch FOR UPDATE`, pinned by `AuditPruneStatementGateTest` (source text, and no second deleter anywhere in `src`) and `AuditPrunePlanFunctionalTest` (`EXPLAIN`, asserting `LockRows` over a plan that reaches rows in `id` order). Ordering without the lock buys nothing — the outer `DELETE` unique-ifies through a blocking node that discards the subquery's order — and deleting the clause reds those two and nothing else. Schedule-consumption gate: `php.lint.schedule-consumption` — every `#[AsSchedule]` in `api/src` must have its derived `scheduler_<name>` transport named in a `messenger:consume` command of **both** `compose.yaml` and `compose.prod.yaml`, and no consume command may name a scheduler transport no schedule produces. Symfony's `AddScheduleMessengerPass` mints the transport from the attribute and `messenger.yaml` declares none of them, so a schedule nobody consumes compiles, registers and ships **dead** with every other check green; the stale direction fails the worker's next boot, since Messenger cannot resolve an unknown receiver. Two classes, each selected by exact name in its own run (assertions over the real tree + falsifiability of the rules against fixtures). It reads the **root** compose files, absent from the `./api` build context, through the read-only `./` bind mount at `/app/repo` declared in `compose.dev.yaml` — with the mount gone the gate **fails** rather than skipping. Blind spots: it proves a transport *name* appears in a consume command, never that the worker runs, that the service is deployed, that `--time-limit` is sane, or that a tick ever reached a log. Behat step-vocabulary gate: `php.lint.step-vocabulary` — every step pattern the contexts declare is classified in `api/.behat-step-vocabulary` as `used` / `idle` / `manual` / `refused`, and the classification is recomputed from the tree: a pattern classified nowhere, a classification whose pattern is gone, a `used` one no scenario reaches, an `idle` one a scenario now reaches, a scenario calling a `manual` escape hatch or a `refused` phrasing, or a feature step matching no pattern at all, each fail. It exists because the rule ("the vocabulary is an asset to spend, never delete a step for being unused") lived only in `api/CLAUDE.md` prose, which drifted within weeks — 205 patterns against 209, 47 features against 49, and a context named as wholly idle that thirteen scenarios were reaching, eighteen times — with every gate green. Blind spots in the registry header: it cannot judge a `manual`/`refused` classification, proves a pattern is *reached* rather than that its assertion can fail, and does not detect two patterns saying the same thing in different words. Architecture-boundary gate: `php.deptrac` (deptrac, in `php.quality[.dry-run]`) — hexagonal layering + bounded-context defence-in-depth + Domain/Application external-dependency allowlist; config `api/tools/deptrac/deptrac.yaml`, grandfathered debt in `api/tools/deptrac/deptrac.baseline.yaml` (regen `php.deptrac.baseline`). Security-dataflow rules: `semgrep.scan` / `semgrep.test` / `semgrep.sarif` (pinned container, rules in `tools/semgrep/rules/erpify-rules.yaml`, fixtures in `tools/semgrep/tests/`) — covers `Request` → DQL/SQL, shell and redirect; runs in the non-gating `api-semgrep` CI job, outside `php.quality`. Composer stability gate: `php.lint.composer-stability` — nothing in `composer.json` `require` may admit a non-stable version (a `dev-*`/`*.x-dev` branch constraint or an `@dev`/`@alpha`/`@beta`/`@RC` flag), because a per-package flag overrides `minimum-stability: stable` silently and is recorded only in the lock's `stability-flags`; `require-dev` is exempt by design. Blind spot: it reads the root manifest only, so a stable dependency that itself requires a dev package passes. Prod-container compile gate: `php.lint.prod-container` — compiles the service container under `APP_ENV=prod` and fails if any definition cannot be resolved. It exists because a class can declare itself out of production and nothing checked that the declaration held: `WebDebugToolbarLoaderController` autowires `Twig\Environment` while TwigBundle is registered only under dev/test, so it is correct only while its `#[When(env: 'dev')]` / `#[When(env: 'test')]` attributes survive — strip them and PHPStan, deptrac, PHPUnit, Behat and `composer.check.all` all stay green while the prod container stops compiling. The only reader was `composer run-script post-install-cmd` inside the `frankenphp_prod` image build, which no workflow performs (CI bakes `compose.yaml` + `compose.dev.yaml`), so the first reader was the deploy. Wired into `php.quality[.dry-run]`, alongside `composer.check.missing-deps` — the *missing-package* half, which CI had likewise never run, which is how four packages `api/src` imports (`monolog/monolog`, `symfony/cache-contracts`, `symfony/finder`, `symfony/security-http`) went undeclared. Blind spot: it compiles against the **dev** vendor tree, so a `src` class importing a `require-dev` package still resolves here and dies under the image's `composer install --no-dev`; that direction belongs to `composer.check.missing-deps`, not to this gate, and neither proves anything about runtime.

### PWA / JS

```bash
make pwa.install                    # npm ci in pwa/.
make pwa.update                     # Safe dependency update (within semver ranges).
make pwa.upgrade                    # Force-upgrade all deps to latest (npm-check-updates).
make pwa.dev                        # Next dev server (Turbopack) on host :80 (needs pwa/.env.local).
make pwa.production.build           # Production build.
make pwa.production.start           # Serve the production build on :80.
make pwa.test                       # Vitest + Playwright.
make pwa.test.unit c='path/to.test' # Vitest single file.
make pwa.test.unit.watch            # Vitest watch mode.
make pwa.test.e2e                   # Playwright. Sharded: CI_SHARD=N CI_TOTAL_SHARDS=M make pwa.test.e2e.
make pwa.test.e2e.reports           # Open the Playwright HTML report.
make pwa.lint                       # ESLint --fix.
make pwa.lint.graph                 # dependency-cruiser boundary gate over pwa/src (check-only).
make pwa.format                     # Prettier --write.
make pwa.quality.dry-run            # ESLint + dependency-cruiser + Prettier + tsc (no writes).
```

### Aggregates and CI

```bash
make app.quality                    # All linters (PHP + PWA).
make app.test                       # All tests (PHP + PWA).
make app.update                     # Safe dep update for API + PWA (within constraints).
make app.upgrade                    # Force upgrade API + PWA deps to latest (across majors).
make ci                             # Full CI (ci.quality + ci.test).
make ci.api                         # API only: lint + tests.
make ci.pwa                         # PWA only: lint + unit + build (no E2E).
make super-lint.full                # SuperLinter over the whole repo (requires GITHUB_TOKEN).
make super-lint.fast                # SuperLinter on changed files only (faster).
make super-lint.slim                # SuperLinter on changed files only (slim image).
```

**Always start the stack with `make app.dev` or `make docker.up`.** Bare `docker compose up -d` skips composer install on cold checkouts and the `pwa.install.if-missing` guard, leaving the PWA container without dependencies.

### BMad artifacts

```bash
make bmad.status.audit              # Report sprint-status.yaml markers left behind by merged work.
make bmad.status.audit c='--strict' # Same, but exit 1 on drift (for a gate).
```

Nothing in the merge path moves a marker in `sprint-status.yaml`: a PR squash-merges on GitHub and the file keeps saying `review` / `in-progress`. The audit is offline (no network, no `gh`) and reports two things — an epic still open whose stories are all `done`, and a story below `done` whose tag (`RM-6`, `U-4`, `II-5`, `AF-1.1`…) already appears in a commit subject on the base branch. Story keys with no letter prefix carry no commit tag, so they are listed as unchecked rather than passed silently.

A `SessionStart` hook in `.claude/settings.json` runs it with `--quiet-when-clean`, so drift surfaces when a session opens and a clean tree stays silent. It never blocks: exit code is 0 unless `--strict`.

### Dependency batches

`/deps-update` (`.claude/commands/deps-update.md`; `--dry-run` inventories only) consolidates the open `chore: bump` PRs into one branch and one PR. Dependabot's one-PR-per-dependency shape cannot express a bump that spans several pin sites, so those PRs are red by construction — `github/codeql-action` covers three steps across `codeql.yml` and `ci.yml`, and none of the three passes alone (#628/#629/#630 → #632). The command classifies by the path segment after `dependabot/` rather than by substring, since `dependabot/github_actions/docker/bake-action-…` is an actions bump, not a docker one. For npm and composer it re-resolves the ranges in a single install instead of merging the branches, whose whole-lockfile rewrites conflict pairwise; expect the caret to land a patch ahead of what Dependabot pinned and peers to move packages nobody asked for. That install is not the whole batch: `npm install` moves nothing whose locked version already satisfies its range, so a bump needing no manifest edit (a PR touching only `package-lock.json`) is dropped silently and the *added: 0, removed: 0* supply-chain check passes over the gap — it proves nothing was gained, never that everything moved. Every claimed version is read back out of the resolved lock. It stops for branch authorization before creating anything, and never merges.

---

## Services

| Service            | Image / Build                        | Port (host)                          | Purpose                                      |
|--------------------|--------------------------------------|--------------------------------------|----------------------------------------------|
| `php`              | `./api` (FrankenPHP worker)          | `:80` / `:443` / `:443/udp` (HTTP/3) | Symfony API + reverse proxy to PWA + Mercure |
| `pwa`              | `./pwa` (Next.js 16)                 | internal `:3000`                     | App Router HTML, served via FrankenPHP       |
| `database`         | `postgres:18-alpine` (sha256-pinned) | internal `:5432`                     | Main app DB                                  |
| `messenger_worker` | reuses `php` image                   | —                                    | Async Symfony Messenger consumer             |

Compose base images are sha256-pinned; Dependabot tracks digest bumps. `compose.yaml` + `compose.dev.yaml` / `compose.prod.yaml` overlays live at the repo root.

---

## Repository layout

### Root

```
api/            Symfony HTTP API (see api/CLAUDE.md)
pwa/            Next.js PWA (see pwa/CLAUDE.md)
compose.yaml    Base Compose; overlays compose.dev.yaml / compose.prod.yaml
make/           Makefile modules (api.mk, db.mk, php-*.mk, pwa.mk, ci.mk, …)
docs/           Cross-deployable docs (architecture, integration, deployment, guides)
scripts/        Utility scripts
```

### `api/`

| What                                                                         | Where                                                                    |
|------------------------------------------------------------------------------|--------------------------------------------------------------------------|
| Symfony kernel                                                               | `api/src/Kernel.php`                                                     |
| Bounded contexts (DDD)                                                       | `api/src/{Backoffice,Frontoffice,Iam,Organization,Shared}/<Module>/`     |
| Domain layer (entities, VOs, ports)                                          | `<Module>/Domain/`                                                       |
| Application layer (use cases, DTOs)                                          | `<Module>/Application/`                                                  |
| Infrastructure (Doctrine, controllers, Messenger handlers, mailers, clients) | `<Module>/Infrastructure/`                                               |
| Cross-cutting capabilities over a minimal `Kernel/`                          | `api/src/Shared/`                                                        |
| Shared search-filter plumbing (applier + per-repo field maps)                | `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/`             |
| Symfony config (services, routes, packages, Messenger transports)            | `api/config/`                                                            |
| Doctrine migrations                                                          | `api/migrations/` (generate via `make db.diff`, never edit applied ones) |
| Test fixtures (Hautelook Alice)                                              | `api/tests/DataFixtures/` and `api/tests/Fixtures/`                      |
| Unit tests                                                                   | `api/tests/Unit/`                                                        |
| Functional tests                                                             | `api/tests/Functional/`                                                  |
| Behat contexts                                                               | `api/tests/Behat/`                                                       |
| Behat features                                                               | `api/features/`                                                          |
| Performance budgets (opt-in)                                                 | `api/tests/Bench/`                                                       |
| Isolated tooling Composer installs                                           | `api/tools/{phpunit,phpstan,…}/`                                         |
| FrankenPHP Caddyfile + worker entry                                          | `api/frankenphp/`                                                        |

### `pwa/`

| What                       | Where                                                                    |
|----------------------------|--------------------------------------------------------------------------|
| Next.js App Router         | `pwa/src/app/`                                                           |
| Bounded contexts (DDD)     | `pwa/src/context/<bounded-context>/{domain,application,infrastructure}/` |
| Cross-cutting kernel       | `pwa/src/context/shared/`                                                |
| Reusable UI (Shadcn-based) | `pwa/src/components/`                                                    |
| Pure helpers / hooks       | `pwa/src/context/shared/<capability>/`                                  |
| Unit tests (Vitest)        | `pwa/tests/` (mirrors `src/`)                                            |
| E2E tests (Playwright)     | `pwa/tests/e2e/`                                                         |

For a deeper, machine-generated tree, see [`source-tree-analysis.md`](source-tree-analysis.md).

---

## Adding new code — quick patterns

### New API endpoint

1. Domain: entities, value objects, repository interfaces in `<Module>/Domain/`. **No** Symfony/Doctrine/HTTP imports here.
2. Application: command/query + handler in `<Module>/Application/`. Use ports defined in `Domain/`.
3. Infrastructure: HTTP controller, Doctrine repository implementation, persistence mapping in `<Module>/Infrastructure/`.
4. Wire services in `api/config/services.yaml` (or per-module config) — autoconfigure handles most cases.
5. Schema changes: `make db.diff` → review the file in `api/migrations/` → `make db.migrate`.
6. Test: unit tests for domain/application in `api/tests/Unit/`; HTTP behaviour in a Behat scenario under `api/features/`.
7. Run `make php.stan` on every PHP file you changed; then `make php.quality` at the end.

See [`../api/docs/adding-endpoints.md`](../api/docs/adding-endpoints.md) for the search-endpoint walkthrough.

### New domain status / closed-set enum

The pattern for any status (`UserStatus`, `ClientStatus`, `BudgetStatus`, …) or other closed set. Why: [`adr/domain-enums.md`](adr/domain-enums.md) (enum = identity) + [`adr/domain-presentation-separation.md`](adr/domain-presentation-separation.md) (no display text inside). Enforced by `DomainPresentationSeparationGateTest`.

1. **Pick the backing first (per-aggregate).** Default **string-backed**; `int`-backed only for hot-path / high-cardinality aggregates under real volume/write/index pressure. It is a conscious call — **stop and present it** (root [`../CLAUDE.md`](../CLAUDE.md), "Per-aggregate persistence strategy").
2. **Domain enum** in `<Module>/Domain/Enum/` — pure identity, `value == name` in `SCREAMING_SNAKE`. Predicates/transitions are allowed; display text is not:

   ```php
   enum BudgetStatus: string
   {
       case DRAFT = 'DRAFT';
       case APPROVED = 'APPROVED';
       case REJECTED = 'REJECTED';

       public function isTerminal(): bool { return self::REJECTED === $this; } // OK; no getLabel()/format()
   }
   ```

3. **Entity mapping** — `text` column, value hydrated to the enum:

   ```php
   #[ORM\Column(type: Types::TEXT, enumType: BudgetStatus::class)]
   #[EnumType(BudgetStatus::class)]
   private BudgetStatus $status,
   ```

   Use `Types::TEXT`, **not** `Types::STRING`/`varchar(n)`: the enum is the constraint, there is no length semantics, and `varchar`'s metadata would drift from the column (`doctrine:schema:validate` fails). Reserve `varchar(n)` for a real domain limit (cf. `iban`/`bic`/`currency`).
4. **Serialize `->value`, never a label.** Exactly one accessor emits the wire field — `#[Groups([self::GROUP_READ])]` + `#[SerializedName('status')]` on `getStatus(): BudgetStatus` (Symfony emits `->value` for a `BackedEnum`). Output: `{ "status": "DRAFT" }`.
5. **Migration** — `make db.diff` yields a `text` column for a new field. For an `int`→`string` swap of an existing column, hand-write it (`ALTER … USING CASE …`; `down()` fails loud on an unexpected value) — see `api/migrations/2026/Version20260616120000.php`.
6. **PWA contract** — TS union of the wire values + presentation maps keyed by value, in the table/component (never in the API/domain):

   ```ts
   type BudgetStatus = "DRAFT" | "APPROVED" | "REJECTED";
   const STATUS_LABEL: Record<BudgetStatus, string> = { DRAFT: "Draft", APPROVED: "Approved", REJECTED: "Rejected" };
   const STATUS_VARIANT: Record<BudgetStatus, StatusBadgeVariant> = { DRAFT: "neutral", APPROVED: "success", REJECTED: "warning" };
   ```

   Labels live in the PWA (a `Record` now, an i18n dictionary later). Validate the incoming value against the closed set at the adapter boundary.
7. **Gates** — `make php.stan`, `make php.quality` (runs the gate + `schema:validate`), `make pwa.quality`.

### New async job

1. Define a Messenger message DTO under `<Module>/Application/` (or `Infrastructure/Messaging/`).
2. Add the handler in `<Module>/Infrastructure/Messaging/` — keep handlers thin; delegate to an Application service.
3. Route the message in `api/config/packages/messenger.yaml` if it needs a non-default transport.
4. Dispatch from an Application service via `MessageBusInterface`.
5. Audit / domain-event flow (when applicable): see [`architecture-api.md`](architecture-api.md).

### New PWA route + component

1. Add the route under `pwa/src/app/<segment>/` (server component by default; mark `'use client'` only when needed).
2. Domain logic (use cases, ports, types) goes under `pwa/src/context/<bounded-context>/{domain,application}/`.
3. Adapters (HTTP clients, storage, framework glue) go under `<bounded-context>/infrastructure/`.
4. Inversify bindings live in the matching container module — keep `domain/` framework-free.
5. Reusable UI in `pwa/src/components/` (Shadcn + Tailwind, BEM class naming `block__element--modifier`).
6. Test: Vitest under `pwa/tests/` mirroring source; Playwright scenarios under `pwa/tests/e2e/`.
7. Run `make pwa.quality` at the end.

### New Doctrine migration

`make db.diff` — review the generated file under `api/migrations/`. Never hand-edit an applied migration. You may only edit a migration created on the current feature branch; once merged into `main` it is immutable — generate a new one instead.

---

## Testing layers

| Layer       | Tool                        | Command              | Scope                                          |
|-------------|-----------------------------|----------------------|------------------------------------------------|
| Unit (PHP)  | PHPUnit                     | `make php.unit`      | Domain + Application — isolated, no I/O        |
| Functional  | PHPUnit                     | `make php.unit`      | Repositories, integration with the kernel      |
| Acceptance  | Behat                       | `make php.behat`     | Full HTTP flows against a real DB (preferred)  |
| Performance | PHPUnit `--group benchmark` | `make php.bench`     | Opt-in budgets (NFR2); default suite skips     |
| Unit (PWA)  | Vitest                      | `make pwa.test.unit` | Domain + Application + components              |
| E2E         | Playwright                  | `make pwa.test.e2e`  | Cross-browser journeys against the live stack  |

Behat is **preferred** over PHPUnit functional tests for HTTP behaviour. Do not commit with failing tests. If a test is wrong, fix the test too — never skip or delete it to make red go away. The error-contract drift gate (`make php.lint.error-contract`) enforces FR50/FR51/NFR26 — do not bypass it.

---

## Known limitations / gotchas

- **Always run `make` from the repo root.** Compose, target paths, and `IN_CONTAINER` detection assume it. Bare `docker compose up -d` skips composer install on cold checkouts and the `pwa.install.if-missing` guard.
- **Prod boot requires secrets** — `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD`. The prod overlay fails non-obviously if any are missing.
- **First boot is slow.** FrankenPHP's healthcheck has a 120s `start_period` because the entrypoint runs composer install (cold), waits for the DB, runs migrations, then starts the worker. Don't kill it early.
- **Port collisions on `:80` / `:443` / `:8000`** surface as a non-obvious Compose error. Free the port or override `HTTP_PORT` / `HTTPS_PORT`.
- **One stack per worktree, not one stack total.** `make/config.mk` derives `COMPOSE_PROJECT_NAME` from the checkout: the primary checkout keeps `erpify`; a linked worktree under `.claude/worktrees/` gets `erpify-<dir-slug>`. So each worktree's containers/networks/volumes are isolated and several stacks can run at once. Linked-worktree stacks publish host ports **ephemerally** (random free ports, not the fixed `80/443/15432/8025`) so they never collide — they are meant for `make php.*` / `make pwa.quality` / Behat (which exec into the container or use the internal network), not for browsing the UI. Browse from the primary checkout, run `make docker.info` to see a checkout's resolved project + ports, or opt a worktree run into a fixed, non-colliding port on demand — every `*_PORT` is `?=`, so a value you pass wins over the ephemeral `0`: `HTTPS_PORT=8443 make docker.up` (from inside the worktree; pick any free port ≠ main's `443`), then open `https://localhost:8443`. `HTTPS_PORT` also feeds `DEFAULT_URI` / `MERCURE_PUBLIC_URL`, so internal URLs stay consistent; set additional `*_PORT` vars (`HTTP_PORT`, `MAILPIT_UI_PORT`, `POSTGRES_PORT`, …) the same way for those surfaces. Per-worktree deterministic port offsets were deliberately **declined** — reach for this override, not new tooling. CI is unaffected (it runs in the primary checkout → `erpify`, fixed ports).
  - **Creating a worktree — `make worktree.create BRANCH=<branch>`.** Adds a linked worktree under `.claude/worktrees/` on a *new* branch off `BASE=` (default `main`). A random 4-char suffix is appended to **both** the branch and the dir slug, so the branch, dir, and its `erpify-<slug>` Compose project are always unique — `feat/foo` and `fix/foo` can coexist and re-running never collides. `NAME=<dir-base>` overrides the derived dir slug (still suffixed); `START=true` brings the new stack up via `make app.dev` (the sub-make builds its *own* `erpify-<slug>` project — `config.mk` hands the project name to compose via `-p` and doesn't export it, so a nested `make` re-derives its own), otherwise it just prints the `cd … && make app.dev` next step. After checkout the recipe seeds the worktree's `.claude/skills/` with the `bmad-*` skills from its own tracked `.agent/skills/` copy — `.claude/skills/bmad-*/` is gitignored, so without the seed `/bmad-*` slash commands are "Unknown command" inside the worktree (a worktree created with bare `git worktree add` still needs the manual `cp -a .agent/skills/bmad-*/ .claude/skills/`). It also **symlinks `_bmad` to the primary checkout's install**, for the same reason and with a sharper failure: `/_bmad` is gitignored too, so a worktree without it makes every `bmad-*` skill die at activation — the skill resolves its workflow block through `_bmad/scripts/resolve_customization.py` and its config through `_bmad/bmm/config.yaml`. Linked rather than copied, so one installed version and one config serve every worktree instead of drifting apart at the first update; the link is relative (`../../../_bmad`), which survives moving the whole tree and does **not** survive moving a single worktree out from under `<main>/.claude/worktrees/`. Like the removal targets it is **local only** — `git worktree add -b` and a local branch, nothing pushed.
  - **Tearing worktrees down — `make worktree.remove NAME=<dir>` / `make worktree.remove-all`.** `worktree.remove` takes down the worktree's isolated `erpify-<slug>` stack + volumes, removes the worktree, prunes stale metadata, then deletes the branch it held; `worktree.remove-all` does the same for every linked worktree. Both are **local only** — they run `git worktree remove` + `git branch -d/-D`, never a remote deletion. `FORCE=true` discards a dirty worktree and force-deletes a not-fully-merged branch (a squash-merged branch reads as unmerged to git, so the common merged-PR cleanup needs `FORCE=true`). `make worktree.list` prints the `NAME` values. The teardown sub-make targets the *worktree's* `erpify-<slug>` project, not the caller's: `config.mk` hands `COMPOSE_PROJECT_NAME` to compose via `-p` and does **not** export it, so a nested `make` re-derives its own project instead of inheriting an env value its `?=` couldn't override. Degraded states are handled: if the worktree's dir was deleted out-of-band (sub-make teardown impossible), the recipe re-derives `erpify-<slug>` from the dir basename and runs `docker compose -p erpify-<slug> down --volumes` from the main checkout so the stack doesn't leak; and when no worktree matches, it sweeps the two residues a half-finished removal leaves *independently* — a leftover directory under `.claude/worktrees/` that git no longer tracks (stack torn down by project name, then `rm -rf` and prune) and a leftover local branch named exactly `NAME` — firing both arms when both match. The directory arm is what makes a failed removal resumable, because `git worktree remove` drops the registration **even when deleting the files fails** (measured: with an undeletable subdirectory planted it prints `error: failed to delete …`, exits 255, and leaves the checkout on disk with its `.git` file gone and its entry already pruned). `worktree.remove-all` enumerates those orphan directories too, so it is resumable on the same terms. **Exit status is the contract: 0 means nothing is left** — a run that deletes the directory while a branch it can see survives, or that could not bring the stack down before removing the last handle on it, names the residue and exits non-zero. The lookup resolves `NAME` by directory basename, by path (trailing slashes stripped) **and by branch**, and the directory arm re-asks git whether it still tracks that exact path and refuses if so: matching only path and basename meant `NAME=<branch>` could never hit a live worktree, fell through to the residue path, and — since the dir slug is derived from the branch basename at creation — `rm -rf`'d a live checkout belonging to another session while printing "git no longer tracks" and exiting 0. Do not lean on branch-basename-equals-dir-slug as an invariant: `NAME=<dir-base>` overrides the slug at creation and the branch keeps its case, so `feat/Foo_Bar` lives in `foo-bar-<sfx>`. Where they diverge no single `NAME` clears both residues — and since an orphan has no `.git` file, nothing can say which branch held it, so the recipe **lists every untracked directory still under `.claude/worktrees/`** on its way out rather than claiming an attribution it cannot make. A removal that fails with `Permission denied` means the worktree's containers wrote bind-mounted files as `root` (`pwa/.next`, `node_modules`, `api/var`, …): run `make worktree.chown` (sudo, dev/test only; mirrors `pwa.chown.next`, reclaiming the whole `.claude/worktrees/` folder at once — stale dirs git no longer tracks included) and retry the removal.
- **FrankenPHP file watchers stay scoped away from `var/`.** Dev watch surfaces are scoped on purpose: the worker `watch` and the site `hot_reload` defaults in `compose.dev.yaml` target `src/` (+ `config/` for hot_reload). A bare `watch`/`hot_reload` recursively watches all of `/app/api`, and the `var/cache/test/` rename storm during in-container test runs crashes the watcher, making frankenphp (PID 1) core-dump (~1 GB into the bind-mounted `api/`) and restart. Belt-and-braces: the `php`/`messenger_worker` services set `ulimits.core: 0` in `compose.yaml` and `api/.gitignore` covers `/core.*`. If you customize `FRANKENPHP_WORKER_CONFIG` / `FRANKENPHP_SITE_CONFIG`, keep the paths scoped (and note compose's `${VAR-default}` ends at the first `}` — no brace-globs in the defaults).
- **API tests services config must be YAML** (`api/config/services_test.yaml`) — never `services_test.php`. Symfony's test kernel only loads the YAML variant.
- **Rector silently privatizes `protected` methods on `final` classes** during `make php.quality`. If you intentionally leave a method `protected` on a `final` class, expect it to be rewritten — refactor the design or drop `final`.
- **Stub interfaces in unit tests with `createStub()`, not an anonymous class.** Rector marks an eligible anonymous double `readonly` (`new class(...) implements …` → `new readonly class(...)`), and the pdepend parser bundled in PHPMD cannot parse a `readonly` anonymous class — `make php.quality` then dies at the `php.md` step with `Unexpected token: class` (a pdepend crash, not a rule violation). PHPUnit's `createStub()` sidesteps it and is the idiomatic pure-return-value double anyway; `createMock()` without an `->expects()` raises a missing-expectation notice, so reach for the stub when you only fix return values.
- **PHP multi-line `if`/`while`/`match` formatting** — newline after the opening `(`; do not put the first operand on the same line as the keyword. PHP-CS-Fixer enforces this.
