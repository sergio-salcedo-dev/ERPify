# ERPify — Claude Code instructions

Monorepo with two deployables sharing one Compose stack: a Symfony HTTP API on FrankenPHP and a Next.js PWA. Nested `CLAUDE.md` files ([`api/CLAUDE.md`](api/CLAUDE.md), [`pwa/CLAUDE.md`](pwa/CLAUDE.md)) auto-load inside their subtree — this file is the monorepo-wide baseline.

**Stack:** PHP 8.5 · Symfony 8 · FrankenPHP (Caddy embedded) · PostgreSQL 18 · Doctrine ORM · Symfony Messenger · Mercure · Next.js 16 (App Router) · TypeScript · Tailwind 4 · Inversify · Vitest · Playwright · PHPUnit · Behat

> Full command catalog, repo layout tables, "adding new code" recipes, and gotchas → [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md). Run `make help` for the live target list.

## What to do

- Every time Claude makes a mistake → you add a rule
- Every time you repeat yourself → you add a workflow
- Every time something breaks → you add a guardrail

### Detect automation opportunities (then ask before building)

Watch the user's requests and your own steps for repetition. When a pattern matches a signal below, **stop and tell the user** what you noticed, which automation fits, and the one-line value — then let them decide. Never create the skill/command/routine/hook unprompted.

| Signal you notice                                                                                                            | Propose                                                                    | Why                                                                         |
|------------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------|-----------------------------------------------------------------------------|
| A multi-step **procedure** repeated across sessions (same sequence of steps/decisions, repo-specific know-how)               | a **skill** (`.claude/skills/`)                                            | Captures the *how* so it loads on demand. See `superpowers:writing-skills`. |
| The user keeps typing/asking for the **same invocation** to kick off a known task ("do the release steps", "run the import") | a **slash command** (`.claude/commands/`)                                  | A named shortcut for one repeatable action.                                 |
| "**Every time** X happens, do Y" — a deterministic action tied to a tool event (pre/post edit, on stop, on session start)    | a **hook** in `settings.json` (use the `update-config` skill)              | The harness runs it, not me — the only way to guarantee "always".           |
| A task that should run **on a schedule / unattended** ("check the deploy every morning", "weekly cleanup")                   | a **routine** (`/schedule` cloud agent, or `/loop` for in-session polling) | Time-based, recurring, no human in the loop.                                |

A pattern earns an automation when I've **repeated it ≥2–3 times**, the steps are **stable**, and the trigger is **recognizable** — otherwise just do the work. Prefer the lightest tool that fits (command < skill < hook < routine); when unsure, name the trade-off and let the user pick.

---

## Top-hits commands

Always invoke from the repo root. Targets decide whether to exec inside the `php`/`pwa` container based on `ENV` and `IN_CONTAINER`.

```bash
make app.dev                        # Full dev stack (down → install → up --wait → fix ownership)
make docker.up                      # Stack up detached (ENV=dev|staging|prod).
make docker.down                    # Stop stack and remove orphans.
make docker.worker.cache.reset      # Drop messenger_worker's private container cache (fixes its boot loop).
make php.bash                       # Shell into the php container (also: php.sh, php.exec).
make sf c='about'                   # Symfony console (also: make sf.cc, make sf.routes f='…').
make composer c='req vendor/pkg'    # Composer in container.
make php.unit c='--filter SomeTest' # PHPUnit (also: make php.behat, make php.test).
make php.stan                       # PHPStan — REQUIRED on every PHP file you change.
make php.quality                    # Full PHP lint sweep — REQUIRED at end of any PHP task.
make db.diff                        # Generate migration from entity diff (then make db.migrate).
make pwa.dev                        # Next dev (Turbopack, host :80) — needs pwa/.env.local.
make pwa.test                       # Vitest + Playwright (also: make pwa.test.unit/.e2e).
make pwa.quality                    # ESLint + dependency-cruiser + Prettier + tsc — REQUIRED at end of any PWA task.
make pwa.lint.graph                 # dependency-cruiser boundary gate over pwa/src (check-only).
make app.quality                    # All linters (PHP + PWA).
make app.test                       # All tests (PHP + PWA).
make ci                             # Full CI (ci.quality + ci.test).
```

**Always start the stack with `make app.dev` or `make docker.up`.** Bare `docker compose up -d` skips composer install on cold checkouts and the `pwa.install.if-missing` guard.

### Worktrees

**Hard rule — every new feature (or any multi-edit task) starts in a worktree**, never in the primary `main` checkout (a live shared surface where concurrent git ops can wipe uncommitted/branch work). Once authorized (see *Confirm branch creation & topology* below), create it *first*. `/feature <scope> <slug>` wraps the mechanics; fixes/chores follow the same rule with their branch prefix. Per-worktree stacks are isolated — `make/config.mk` derives `COMPOSE_PROJECT_NAME` per checkout (primary keeps bare `erpify` with fixed ports `80/443/15432/8025`; a worktree gets `erpify-<slug>` with ephemeral host ports), so they never collide. Run `make` targets from inside the worktree and they exec into *that* stack (checks/tests see the worktree's code, over the internal network — random host ports don't matter).

**A worktree isolates files, never the branch.** Object store, reflog, stash and remote are shared, so a second session pointed at the same branch commits into your work and nothing warns — that is how three unauthored commits landed mid-task on a live feature branch. Before editing, confirm the checkout is yours: `scripts/worktree-collision-check.sh` runs from a `SessionStart` hook and flags the three signals (you are in the primary, the tree was already dirty on arrival, the branch has a commit minutes old). It is advisory and never blocks; run it by hand any time, `--strict` to make it exit non-zero.

- **Create** — `make worktree.create BRANCH=<branch>`: linked worktree under `.claude/worktrees/`, new branch off `BASE=` (default `main`), random 4-char suffix on branch+dir+project so re-running is always safe. `NAME=<dir-base>` overrides the dir slug; `START=true` also runs `make app.dev`. Seeds `bmad-*` skills automatically, and **symlinks `_bmad` to the primary checkout's install** — both are gitignored, so without them every `bmad-*` skill dies at activation looking for `_bmad/scripts/resolve_customization.py` and `_bmad/bmm/config.yaml`. Linked and not copied, so one install and one config serve every worktree; the link is relative, so moving the whole tree keeps it resolving but moving a single worktree out of `<main>/.claude/worktrees/` does not.
- **Remove** — `make worktree.remove NAME=<dir>` (stack + volumes + worktree + branch; **local only**). `make worktree.remove-all`; `make worktree.list` for `NAME` values. `FORCE=true` discards a dirty worktree / deletes a not-fully-merged branch (squash-merged PRs look unmerged → need `FORCE=true`). On `Permission denied` (root-owned bind mounts: `pwa/.next`, `node_modules`, `api/var`) run `make worktree.chown` (sudo, dev/test) and retry. **A failed removal is resumable** — `git worktree remove` drops the registration even when deleting the files fails, so re-running sweeps whichever residues git no longer knows about (the untracked directory, the branch, or both). Read the markers: **`•` is something the run owed you and forces a non-zero exit** (a stack that would not come down, a branch surviving its directory); **`ℹ` lists an untracked directory nobody can attribute to a branch** and does not fail the run. `NAME` resolves by directory, by path or by **branch**, and the residue arm refuses any path git still tracks *or that contains a tracked worktree* — matching only exact paths is what twice let it `rm -rf` a live checkout belonging to another session. `worktree.remove-all` touches **registered worktrees only**; clear untracked directories one at a time, after looking at them.
- **Browse a worktree UI (on demand; default is browse-from-`main`)** — `HTTPS_PORT=8443 make docker.up` from inside it (any free port ≠ main's 443; also feeds `DEFAULT_URI`/Mercure so internal URLs stay consistent). Other `*_PORT` vars work the same; `make docker.info` prints the resolved project + ports.

Full mechanics (ephemeral-port internals, degraded-state recovery) → [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md).

---

## Architecture

```
Browser → FrankenPHP :80/:443 ──┬─ /api/*                 → Symfony controllers (api/)
  (Caddy embedded)              ├─ /.well-known/mercure   → Mercure hub
                                └─ everything else        → reverse-proxy to pwa:3000 (Next.js)
                                                                   ↓
                                                        Doctrine → Postgres :5432
                                                                   ↓
                                                  Messenger (Doctrine transport) → messenger_worker
```

Full request-routing diagram and host/container trade-offs in [`docs/integration-architecture.md`](docs/integration-architecture.md).

Both sides follow **DDD + Hexagonal / Clean Architecture**, dependencies pointing inward. **Do not** import frameworks (Symfony, Doctrine, Next, Inversify, HTTP clients, ORM) inside `Domain/` — adapters go in `Infrastructure/`, orchestration in `Application/`. Documented exceptions: API domain entities may carry passive **persistence/validation** metadata (`#[ORM]`, `#[Assert]`) — the HTTP wire contract lives in per-view Resource DTOs (`Application/Resource/`) mapped in `Infrastructure/Http/`, not in serializer `#[Groups]` on the entity — and `Domain/` may import `symfony/uid` as a UUID value-object library (e.g. `api/src/Shared/Uuid/Domain/Uuid.php`) — see [`docs/rules/architecture.md`](docs/rules/architecture.md) and [`docs/adr/api-resource-dtos.md`](docs/adr/api-resource-dtos.md). Full rule set: `docs/rules/*.md` (architecture, clean-code, commits, cqrs-naming, database, frontend, php-standards, read-side-projections, security, testing).

### Per-aggregate persistence strategy (conscious decision)

- **No object graph crosses a module boundary.** An aggregate references another module's aggregate by id (`private string $fooId`, UUID v7, `Uuid::ensure()` at the edge) — never via a typed `#[ORM\ManyToOne]` property to the other module's entity. Read composition (e.g. account + bank name) is an explicit DQL JOIN into a projection DTO. Physical FKs are kept schema-aware via a `postGenerateSchema` listener. Full decision record: [`docs/adr/bank-bankaccount-modeling.md`](docs/adr/bank-bankaccount-modeling.md).
- **State-oriented persistence is the default; event sourcing is opt-in per aggregate, never global.** Before modeling a new aggregate (or extending one into a new business meaning), **stop and present the user the persistence-strategy decision**: where is this aggregate headed — does the business need only the current snapshot (catalogs, reference data → state-oriented), or is the history itself the business (ledgers, balances, stock movements → event-sourcing candidate)? Lay out both options with their costs and let the user pick. Worked example (bank account as payment reference in invoicing vs ledger in finance/treasury) and criteria table: [`docs/adr/bank-bankaccount-modeling.md`](docs/adr/bank-bankaccount-modeling.md).

---

## Required checks

- **PHP edits** → `make php.stan` on every changed file before declaring done; at the end run `make php.quality`. Fix anything reported.
- **PWA edits** → `make pwa.quality` at the end.
- **PWA component boundaries** → two gates with different eyes. On coverage the graph gate strictly **contains** the specifier gate (same `from` paths, same targets, plus reachability), so the reason to keep both is not a coverage gap: `no-restricted-imports` (`pwa/eslint.config.mjs`) matches an import **specifier** per file and is the only one giving inline editor signal; `make pwa.lint.graph` (dependency-cruiser, `pwa/.dependency-cruiser.cjs`) walks the resolved **module graph** and sees the three shapes a specifier match cannot state — transitive reach through a facade, relative escapes that dodge the `@/` patterns, and barrel re-exports. Measured: with a `@/context/**` import planted in `components/cn.ts`, ESLint exits 0 while the graph gate reds all ten `ui/` files reaching through it — the exact regression #349 had to *move* `cn` to prevent, and which nothing verified until this gate. It also covers `src/components/*.ts`, which no ESLint block matches. Wired into `pwa.quality` **and** `pwa.quality.dry-run` (so CI gates on it). No baseline, no `--cache`, no preset, and zero exception lines — a rule needing an allowlist is written wrong. A green proves no forbidden edge exists in the graph; it proves nothing about a specifier the resolver could not resolve, which is why `no-unresolvable` is mandatory rather than optional.
- **HTTP error responses** → never bypass the RFC 9457 pipeline with manual `JsonResponse` error bodies. Adding a marker interface or changing its mapping requires updating [`docs/api-error-contract.md`](docs/api-error-contract.md) (NFR26). Drift gate: `make php.lint.error-contract`.
- **Bounded-context isolation** → never import another business context's `Domain\`/`Application\`/`Infrastructure\` (inject foreign repositories, know its internals) — reference identities and react to events instead (`Erpify\Shared\…` is always importable). A genuine published cross-context seam goes in `api/.bounded-context-allowlist`. Gate: `make php.lint.bounded-context` (Level 1 fails; cross-context FKs are Level 2 warnings). Rule: [`docs/rules/database.md`](docs/rules/database.md).
- **Queuing an event about a person** → never. An "aggregate id alone" payload is safe on a persisted transport **iff** the aggregate is not a natural person: `async` and `failed` are Doctrine tables no erasure path touches — `async` has no TTL and no prune at all, and `failed` is swept only by a 30-day retention window that bounds the exposure without closing it — so a queued person id still outlives the erasure the app confirmed to the subject. Classify every `aggregateType()` in `api/.persistent-transport-policy` — by what the **`aggregate_id`** denotes, not the type's name, and conservatively where one type covers events with different id semantics. Leave person-aggregate events unrouted (handled in-process) and put blocking or failing effects in the use case post-commit, not in a handler. Gate: `make php.lint.persistent-transport`; its blind spots are enumerated in the registry header and a green build proves only what is listed there.
- **Persisting a person's id** → it needs a named owner of its erasure, and that owner must execute it. **Nothing in the schema references `identity_user`** (the database holds two foreign keys, neither into it), so deleting an identity cascades nowhere and nothing removes a person's id from any other table — the obligation is distributed and every context that comes to touch a person mints another one. Classify every `Types::GUID` entity column in `api/.person-reference-policy` (`non-person`, or `person :: <path of the file that erases it>` — `person` without an owner is not a spelling, it is the violation), and declare it at the property with `#[PersonSubjectReference]` — required on every `person ::` column **except the subject's own primary key**, which is not a reference but the row itself, and whose erasure is that row's deletion. Every `person ::` column also needs a **detective** counterpart: a `Shared\Privacy\Application\PersonReferenceSource` in the owning context, tagged `erpify.person_reference_source`, listing the ids that column still holds so `identity:gdpr:reconcile-subject-references` can report the ones no live identity backs — the preventive half proves the erasure is *written*, only this proves the row is *gone*. Gate: `make php.lint.person-reference`; it checks completeness, staleness, wiring, attribute-vs-registry agreement, that every non-exempt person reference is declared at its property, and that each one has exactly one source (in both directions, and with the tagged-iterator wiring intact). Its blind spots are enumerated in the registry header — notably that it never judges the classification, so `non-person` over a person's id passes and review is the only control on that direction, and that it proves a source exists and is collected, never that its query reads the right column.
- **Declaring an `#[AsSchedule]`** → it is not scheduled until something consumes it. Symfony's `AddScheduleMessengerPass` mints `messenger.transport.scheduler_<name>` from the attribute alone and `messenger.yaml` declares none of them, so a schedule whose transport no `messenger:consume` command names **compiles, registers and ships dead with every other gate green** — which is how "this control is scheduled" becomes unfalsifiable. Add the transport to the consume command in **both** `compose.yaml` (dev `messenger_worker`) and `compose.prod.yaml` (`scheduler_worker`). Gate: `make php.lint.schedule-consumption` (both directions — a consume argument no schedule backs fails the worker's next boot). It reads the root compose files through the read-only `./` bind mount at `/app/repo` in `compose.dev.yaml`, and **fails rather than skips** when that mount is gone. The same gate holds the consumer to **one replica** across all three root compose files (`compose.dev.yaml` included — dev layers it, so a count written there runs two clocks with the consumption pair green). Ticks come from an in-process clock and `Checkpoint::acquire()` returns true unconditionally without a `->lock()` (none of the three schedules has one); the durable checkpoint pool shares *state*, not exclusion, so a second replica **can** duplicate a tick — the window runs from the winner's `acquire()` to its `save()`, which lands after the handler. The eight idempotent sweeps mostly collapse in that race; `NotifyLockedIdentitiesMessage` holds it open for an SMTP exchange and mails a person before stamping its suppression window, which is the tick that decides the pin. `compose.prod.yaml` must declare the count as a literal, the other two must not contradict it, and an interpolated value fails rather than passing as a pin. A green proves the name is wired and no file over-declares the consumer — never that the worker runs, that a tick reached a log, or that the *deploy* runs one clock: measured by running it, `docker compose up -d --scale <svc>=2` leaves **two containers running** for a service declaring `replicas: 1`, exit 0, identical to one declaring none. So the gate refuses a duplicated clock **committed to the repo** and is blind both to one asked for on a command line and to a service that inherits the consume command via `extends`. Those vectors need `symfony/lock`, declined in #261.
- **Erasing a person from the audit trail** → the row that proves the erasure ran may not outlive the thing it attests, and the row that names the person must be erasable by the context that owns them. Two registries, one per axis. **Evidence:** `AuditErasureEvidence::ACTIONS` is exempt from the retention prune for ever, because the `dek_keystore` tombstone a `GDPR_SUBJECT_ERASED` answers for is eternal and the reconciler anti-joins the two with no date bound — let the evidence age out and every crypto-shredded subject reports as a permanent divergence. Centralising the literals stopped a token *drifting*; it never saw a member going *missing*, and that omission fails toward deletion, so every action an `AuditLogger` collaborator declares as a string constant is classified `evidence`/`ordinary` in `api/.audit-evidence-actions`. **Resource:** every audit `resource_type` is classified person-denoting or not in `api/.audit-resource-types`, and a `person` type must name an erasure use case that wires `AuditResourceAnonymiser` and an acceptance scenario that proves no row survives. Gates: `make php.lint.audit-evidence`, `make php.lint.audit-resource`. Neither judges a classification — `ordinary` over real evidence and `non-person` over a real person both pass, and review is the only control on that direction. The evidence gate additionally sees only constructor-injected collaborators and only string constants, so an inline literal or a backed enum is invisible to it; the registry headers enumerate the rest. ADR: [`docs/adr/audit-activity-log.md`](docs/adr/audit-activity-log.md) (D4).
- **Deleting from `audit_log`** → one statement, and it acquires `ORDER BY id … FOR UPDATE`. `ORDER BY` alone buys nothing: `LIMIT` blocks sublink pull-up, so the outer `DELETE` unique-ifies the subquery through a blocking node that **discards its order** and then probes in that node's order — which is how the clause shipped defending an invariant it did not defend. The lock is what makes the ordering mean anything, and ascending `id` is the order `DbalAuditActorAnonymiser` imposes on itself and `DbalAuditSubjectRowLock` imposes on the resource pass, which orders nothing of its own. Pinned twice, because neither instrument alone is enough: `AuditPruneStatementGateTest` reads the source (and refuses a second deleter anywhere in `src`), `AuditPrunePlanFunctionalTest` asks Postgres for the plan. Cost, measured: ordering by `id` is free while ids track `occurred_on` — UUID v7 orders by *mint* time, so that holds while rows are written as they occur — and a backfill breaks it, at ~134 ms a batch against ~4 ms. That cost is the price of the lock order and is paid deliberately; ordering on `(occurred_on, id)` would serve an index and hand back the deadlock.
- **Deleting from `messenger_messages`** → one statement, and it names its queue. `async` and `failed` are ONE physical table discriminated by `queue_name`, so a statement missing that predicate does not prune a dead letter early — it deletes work in flight, and nothing downstream reports the absence. It carries `ORDER BY id … FOR UPDATE` for the same reason `audit_log` does, plus a **wall-clock drain budget** the audit pruner does not need: that table has always been pruned, so a sweep meets one day of arrivals, while this one arrives at a queue nothing ever bounded and its FIRST run faces the whole history, holding the advisory lock and the single-replica scheduler behind it (`messenger:consume --time-limit` cannot interrupt a handler already in flight). Pinned twice, like the audit prune: `FailedMessagePruneStatementGateTest` reads the source, refuses a second deleter anywhere in `src`, and compares the queue constant against `messenger.yaml` — a DSN rename would otherwise leave the pruner matching zero rows for ever, silently and in the safe direction; `FailedMessagePrunePlanFunctionalTest` asks Postgres for the plan **over a population it seeds and `ANALYZE`s**, because a plan assertion over ambient data is a property of the data (measured: the same mutation left it green under one row population and red under another). Retention is 30 days and coupled to the dead-letter alarm — the alarm reports the age of the oldest SURVIVING row, so a window near its threshold would quiet the queue by deleting the evidence; the margin is asserted, not assumed.
- **Behat step vocabulary** → every step pattern the contexts declare is classified in `api/.behat-step-vocabulary` (`used` / `idle` / `manual` / `refused`) and the classification is recomputed from the tree. The rule it mechanises — *the vocabulary is an asset to spend, never delete a step for being unused, search before writing a near-duplicate* — lived only in [`api/CLAUDE.md`](api/CLAUDE.md) prose, and prose drifts with every gate green: that paragraph counted 205 patterns against 209 and 47 features against 49, and named a context as wholly idle that thirteen scenarios were reaching, eighteen times. `idle` is coverage debt worth recording; `manual` (a hand-invocation escape hatch) and `refused` (a superseded phrasing kept only to redirect) are counted apart because neither is. Gate: `make php.lint.step-vocabulary`; a green proves each pattern's classification matches what the features actually do — never that the assertion behind a reached pattern can fail, and it does not detect two patterns saying the same thing in different words. Its full blind-spot list is the registry header, including the one direction where it fails open (its placeholder token is wider than Behat's, so `--strict` is what catches a step Behat cannot dispatch).
- **Claiming a version in `docs/project-context.md`** → bind it in `api/.project-context-versions`. That page is not the lightly-read note its history suggests: **60 of the 90** installed skills declare it in `persistent_facts`, so it is loaded as foundational context at the start of an agent session rather than consulted on demand, and a stale line is a false premise delivered before the agent reads any code, asserted with exactly the confidence of a true one. Measured over that page's history, **fourteen** second-column version numbers have been corrected — **twelve of them in one commit** (#746), landing immediately before the registry existed; prose drifted alongside them and is still ungated. The page restates normative rules as well, and **that half stays there**: a measured attempt to cut it to versions-and-pointers left ~24 rules existing nowhere else in the repo and deleted the only refutation of a live falsehood five other documents assert as fact — that something scans a commit, when there is no `.pre-commit-config.yaml` anywhere and `core.hooksPath` points at the default directory, which holds nothing but `*.sample`. Gate: `make php.lint.project-context`, four directions — the manifest still starts with the claimed version, the claimed **token** still appears on the page, every version the page's tables state is bound by a line (33 claims, extracted from the second column), and every line still covers a claim the extraction finds. That last one is what stops the third going vacuous: reformatting one table's columns drops it out of the universe with everything else green (measured: 33 claims to 18, fifteen lines orphaned and still passing). The token carries its product name (`Behat 4`, not `4`) or the staleness direction is a tautology, and only a constraint with a **floor** is read — `<2.0` and `>2` used to satisfy "2", and `^24.15.0 || >=26.0.0` used to satisfy "Node 24" on a container running 26. A claim nothing can own is declared `unbound :: <reason> => <token>` rather than omitted, so an exemption never looks like an oversight. A green proves nothing about the tables' prose column, nothing about the prose outside the tables (most of the page, and still measurably false in places), nothing about a version glued to its subject by `:` or `/` rather than whitespace (`node:26-trixie` is invisible), nothing about an `unbound` reason — which is a statement, not a proof — and nothing about a digest.
- **Architecture boundaries (deptrac)** → `make php.deptrac` is the AST-aware gate over `api/src` enforcing hexagonal layering (Infrastructure → Application → Domain), bounded-context isolation (defence-in-depth alongside `php.lint.bounded-context`), and the Domain/Application external-dependency allowlist (only PSR/`symfony/uid`/passive-metadata attributes inward; frameworks confined to `Infrastructure/`). Config `api/tools/deptrac/deptrac.yaml`; pre-existing inner-layer framework deps are grandfathered in `tools/deptrac/deptrac.baseline.yaml` (a ratchet — new ones fail). Published cross-context seams mirror `api/.bounded-context-allowlist` in the config's `skip_violations`. Wired into `php.quality` / `php.quality.dry-run` (so CI gates on it). Rule: [`docs/adr/external-dependencies-in-domain.md`](docs/adr/external-dependencies-in-domain.md).
- **Declaring a class out of production** → `#[When(env: 'dev')]` is a claim, and until this gate it was an unchecked one. `WebDebugToolbarLoaderController` autowires `Twig\Environment` while TwigBundle is registered only under dev/test, so the class is correct exactly as long as those attributes survive — strip them and the prod container stops compiling (`Cannot autowire … no such service exists`) while PHPStan, deptrac, PHPUnit, Behat, `composer.check.all` and all of CI stay green. Nothing read the prod container: it is compiled by `composer run-script post-install-cmd` inside the `frankenphp_prod` image build, and **no workflow builds that image** — CI bakes `compose.yaml` + `compose.dev.yaml` only — so the first reader was the deploy. Gate: `make php.lint.prod-container`, in `php.quality[.dry-run]` next to `composer.check.missing-deps` (the missing-*package* half, which CI had never run either — that is how four packages `api/src` imports went undeclared). A green proves every service definition resolves **against the dev vendor tree**; it does not prove the prod image builds, because `composer install --no-dev` prunes `require-dev` and a `src` class importing one still compiles here. That direction belongs to `composer.check.missing-deps`; neither says anything about runtime.
- **Migrations** → generate via `make db.diff`. You may edit a migration created on the current feature branch; once merged into `main` it is immutable — create a new one instead.
- **Local browser checks** → when a Playwright/browser navigation to the local HTTPS stack fails with `ERR_CERT_AUTHORITY_INVALID` (self-signed dev cert), don't silently downgrade to curl-only: say you hit it and pause — the user accepts the cert manually in the browser, then you retry the navigation. `curl -k` stays the hard gate, but the live visual check is recoverable this way, not abandoned.

---

## Working principles

1. Don't assume. Don't hide confusion. Surface tradeoffs.
2. Minimum code that solves the problem. Nothing speculative.
3. Touch only what you must. Clean up only your own mess.
4. Define success criteria. Loop until verified.
5. Learn from your errors; don't repeat them. Follow up with a plan or doc when warranted.

---

## Question the status quo — argued improvement

Treat every task as a chance to *improve* what you touch, not merely satisfy the literal ask. **Distrust the existing design of the code in scope**: look for the real improvement toward clean architecture, Clean Code, SOLID, and DDD ([`docs/rules/clean-code.md`](docs/rules/clean-code.md), [`docs/rules/architecture.md`](docs/rules/architecture.md), [`docs/rules/php-standards.md`](docs/rules/php-standards.md)) — with **justified flexibility**: a deliberate, argued deviation beats dogma. Tie every suggestion to a concrete end — **scalability, maintainability, performance, or speed**. This lens is *orthogonal* to the Working principles above, not a loophole around them: *minimum code* and *touch only what you must* still bind.

**Operating mode — propose first, never refactor unilaterally.**

- Scrutinise the code **you touch**, not code you don't. The lens rides on the task in scope; it is never a pretext for repo-wide sweeps or speculative rewrites.
- When you spot a real improvement beyond the immediate change, **surface it with its argument and stop** — the user decides whether it lands now, lands later, or becomes a tracking issue. Don't silently grow the diff.
- An improvement **inside the files you already touch** that is low-risk may be folded in under the boy-scout rule — but *name it* in your summary / commit so it's reviewable. Never smuggle a refactor.

**What counts as "argued" (no naked opinions).** A proposal earns consideration only when it states all three:

1. **Principle at stake** — which SOLID rule, DDD boundary, or Clean-Code smell the current code breaks (e.g. "SRP: this handler mixes I/O and policy"; "DIP: the domain depends on a concrete adapter"; "leaky aggregate boundary — an object graph crosses a module").
2. **Objective it buys** — the concrete win in scalability / maintainability / performance / speed (e.g. "unit-test the rule with no DB"; "kills an N+1"; "unblocks a second consumer").
3. **Cost and the discarded alternative** — what the change costs and why the simpler option loses. If you can't fill all three, it isn't ripe — keep working, don't propose.

**Calibration — flexibility is expected, dogma is the smell.**

- **Rule of Three / YAGNI gate every abstraction.** Don't abstract for one caller or a hypothetical future; two similar lines beat a premature abstraction. "Justified flexibility" cuts both ways — bending hexagonal/DDD purity is legitimate *when argued* (the documented `#[ORM]`-on-entity and `symfony/uid`-in-`Domain` exceptions are exactly this; see [`docs/adr/external-dependencies-in-domain.md`](docs/adr/external-dependencies-in-domain.md)), and so is *declining* a textbook abstraction that buys nothing here.
- **Boring over clever.** Prefer the change a future reader understands without you in the room.
- **Performance is measured, not asserted.** "Faster" needs a query plan, a benchmark, or a complexity argument — never a hunch.
- **Persistence-strategy and aggregate-boundary calls stay user decisions** (see Architecture below) — scrutiny may *raise* them, never *settle* them unilaterally.

**Scope hygiene.** Keep *improvement in scope* (do it with the task) separate from *debt found in passing* (propose it, or file a follow-up issue). Never let the second silently inflate the PR.

---

## Security review on every change (backend AND frontend)

Every PR — even a "small" one — MUST be self-reviewed for the common attack classes BEFORE human review and BEFORE the final commit, across both `api/` and `pwa/`. If a class doesn't apply, say so in the PR description; don't silently skip. Walk this checklist per diffed file:

**Frontend (`pwa/`)**
- **XSS** — never `dangerouslySetInnerHTML`, `innerHTML`, `document.write`, `eval`, `new Function(string)`. Wrap every dynamic `href` / `src` / `router.push` URL in `safeHref(...)` (`@/context/shared/navigation/domain/safeHref`) and `encodeURIComponent` the dynamic path segment. Static `aria-label` / `title` only — full list in `pwa/CLAUDE.md`.
- **CSRF / Open redirect** — validate any URL from query params, location state, or API payload is same-origin or relative before navigating; reject `data:`, `javascript:`, `file:`, `vbscript:`.
- **Untrusted input → DOM attributes** — explicit allowlists for `target`, `rel`, `download`. External `target="_blank"` always pairs with `rel="noopener noreferrer"`.
- **Storage / clipboard** — never put secrets, JWTs, or PII into `localStorage` / `sessionStorage` (use httpOnly cookies). Clipboard writes go through `<CopyButton>`, which never trusts the value as HTML.
- **Headers** — confirm `next.config.ts#headers()` (CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, HSTS) hasn't regressed. No `'unsafe-eval'` in production CSP; don't widen `connect-src` / `script-src` without explicit reason.
- **Dependencies** — `npm audit` clean; no new transitive package whose ownership you haven't verified.

**Backend (`api/`)**
- **Injection** — every Doctrine query parameterised (`:placeholder` or query-builder bindings); no raw `${}`/`.$variable.` interpolation reaching SQL/DQL. No `Process::fromShellCommandline($userInput)` (use the array form); no `unserialize($userInput)`.
- **Authentication / Authorization** — every new controller / handler declares the security voter or `IsGranted` it expects; absence is a conscious public route, called out in the PR.
- **Input validation** — every DTO carries `#[Assert\…]` constraints, enforced by `#[MapRequestPayload]` / `#[MapQueryString]` at mapping time (failures → 422 `validation-failed`); other inputs (uploads, non-id scalars) go through the shared `Validator::ensure()` (`Shared/Validation/Application`) before any domain call. Route-id UUIDs are guarded by `Uuid::ensure()` (`Shared/Uuid/Domain/Uuid`), throwing `InvalidUuidException` (400 `invalid-uuid`) before any repository lookup — a malformed id is a request-target error, distinct from a valid-but-absent id (404). Validate IDs are UUIDs, not arbitrary strings.
- **Mass assignment / hidden fields** — entity setters / serializer groups never expose audit fields (`createdAt`, `updatedAt`, `id`) to client-supplied payloads.
- **Output encoding / serialization** — JSON-only responses, no server-side HTML (no Twig emitting user content). Error payloads follow RFC 9457 without leaking stack traces outside dev.
- **Secrets** — never logged, returned, or committed. Confirm `.env`/`*.local` are not in the diff. `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD` env-driven — see `PRODUCTION_SECURITY_CHECKLIST.md`.
- **CORS / CSRF / Mercure** — allowlist not broadened; Mercure JWT secret rotation policy preserved.
- **Migrations & data** — never seed PII or secrets; never `DROP TABLE` outside an explicit destructive migration; `down()` is reversible. Hard delete is the default — soft delete only under the exceptions in [`docs/rules/database.md`](docs/rules/database.md) (GDPR erasure must stay satisfiable).
- **Domain events / Messenger** — handlers idempotent; transports authenticated; payloads scrubbed of secrets.

**Process**
- **No security, GDPR, or audit-surface work opens a PR without a recorded adversarial pass.** Self-certification does not count: a hostile read by someone other than the author (a fresh context, a different model, or a human) is the gate, and *where it was recorded* — PR description, review thread, or the story artifact — must be stated in the PR body. The rule exists because this class of defect is invisible to the checklist above: the failure modes that shipped here (drain the org to zero admins under concurrency; PII surviving its own erasure) each needed someone actively trying to break the change, and the two most sensitive stories of an epic are exactly the ones that tend to skip it. A pass that finds nothing still counts — record it and say so.
  - **The gate is on *opening* the PR, not on reaching `done`, and that is the correction — the weaker wording was measured to permit exactly what it was written to prevent.** Under "must be recorded by `done`", #616 merged and its pass arrived in #618; the pattern then repeated in #620. Both are honest — the registration points at the follow-up PR because that is where the findings live — but the delivery was on `main` for the window in between, and the pass that followed found a **GRAVE** defect (a test asserting an invariant over a seed that inserted zero rows). A pass whose findings can only land in a *second* PR is a review, not a gate.
  - **Mechanically: the pass runs, and its findings are written into the story artifact, before `gh pr create`.** No draft is involved — drafts are not used in this repo, and a rule whose mechanism nobody performs is not a gate, it is a paragraph. What "before opening" buys is the thing draft was standing in for: the hostile read happens while the work is still yours to change, so a finding costs an edit instead of a second PR.
  - **The order matters more than it looks, and BR-2 is the measurement.** There the PR was opened first and the pass followed; that pass returned three GRAVE, two of them measured leaks of a person id into a log the change existed to close — an identifier riding inside a `?next=` value, and an index range bound to what our own UI emits rather than to what the API accepts. Every one of them was cheap to fix and none of them was visible to the checklist above. Had the branch merged on the strength of "gates are green", all three would have shipped as closed.
  - **A PR that is open is a PR that can merge.** Nothing in the tooling distinguishes "open, awaiting its pass" from "open, ready" — which is precisely how #616 and #620 merged ahead of their own passes. Opening it last is what removes the window.
- Run `make php.quality` and `make pwa.quality` locally before pushing — PHPStan / ESLint catch many of the above implicitly. There is no taint / security-dataflow analyser: this checklist is the control.
- For security-sensitive changes (auth, input parsing, file uploads, SQL, headers, CSP), update `PRODUCTION_SECURITY_CHECKLIST.md` and [`docs/rules/security.md`](docs/rules/security.md) if a new pattern is introduced.
- When a finding is genuinely out of scope, file a follow-up issue rather than ship-and-forget.

If you cannot answer "yes" to every applicable item, fix it in the same PR or call it out explicitly in the PR description and link a tracking issue. Silent skips are the most common path to a CVE.

---

## Parallelizing work with subagents

When a task decomposes into independent subtasks (different bounded contexts, different files, no shared state), spawn parallel subagents rather than working sequentially. Each subagent must receive a self-contained prompt with full context.

Example: plan → subagent A (API: domain entity + Doctrine mapping + migration in `api/`) + subagent B (PWA: route + component + Inversify wiring in `pwa/`) in parallel → verify each (`make php.stan`, `make pwa.quality`) → commit.

Do **not** spawn subagents for tasks that share state mid-flight — two agents editing the same migration, the same `services.yaml`, the same Inversify container module, or both touching `api/src/Shared/`.

---

## Conventions

### Protected `main` (hard rule for agents)

- **Never force-push `main`** — no `git push --force` / `--force-with-lease` / `--force-if-includes` to `main`, ever.
- **Never merge into `main` without explicit per-merge permission from the user** — neither a local `git merge`/`git rebase` onto `main` nor merging a PR (web UI, `gh pr merge`, MCP). Prepare the branch/PR and stop. Approval for one PR/branch does not carry over to the next.

### Finishing substantial work means committed **and pushed** (hard rule for agents)

A local commit is not a deliverable. It is invisible to CI, to review, and to the user, and it dies with the worktree — so when a substantial piece of work is done and its gates are green, **commit and push the branch in the same breath**, without waiting to be asked.

- **Trigger:** any change worth a commit message beyond a typo — a feature slice, a fix, a review pass applied, a docs sweep. If you would summarise it to the user as "done", it gets pushed.
- **Push the feature branch, never `main`** (see *Protected `main`* above). Pushing a branch is not merging it; opening the PR is still the separate, permitted step, and merging still needs per-merge permission.
- **Gates first.** Push only behind the required checks for what you touched (`make php.quality`, `make pwa.quality`, the relevant tests), each from a fresh run with its printed exit code.
- **Report the remote state, not the local one.** "Committed" and "pushed" are different claims; say which one is true. If a push is deliberately withheld, say so and why — silence reads as pushed.

The rule exists because the silent failure is expensive and asymmetric: a code review of PR #553 produced thirteen patches, they were committed locally and never pushed, and the PR merged from the unchanged head — the review's whole output was stranded on a branch nobody could see, including a guard that answered 500 instead of 422 on a reachable path. Recovering it needed a cherry-pick onto a follow-up branch. Nothing warned; a merged PR looks identical whether or not your work reached it.

### Confirm branch creation & topology (hard rule for agents)

- **Get explicit authorization before creating a new branch**, and before **splitting one task across multiple branches** (e.g. a `docs/` branch plus a separate `feat/` branch for related work). Don't decide branch topology unilaterally.
- Propose a branch plan (name, base, how the work is sliced) and wait for the user's OK before running `git checkout -b` / `make worktree.create`.
- "Edit on a branch" approval is **not** a blanket OK to spin up *additional* branches later — re-confirm each new branch.
- Default to **one branch / one PR** unless the user asks to split.
- This is a confirmation gate on the *number/topology* of branches; it does not loosen the worktree rule (feature/multi-edit work still belongs in an isolated worktree — just confirm the plan before creating it) or "Protected `main`" above.

### Branch naming

| Type    | Format                  | Base                  |
|---------|-------------------------|-----------------------|
| Feature | `feat/<scope>-<slug>`   | `main`                |
| Fix     | `fix/<scope>-<slug>`    | `main`                |
| Hotfix  | `hotfix/<scope>-<slug>` | latest production tag |
| Chore   | `chore/<slug>`          | `main`                |
| Docs    | `docs/<slug>`           | `main`                |
| CI      | `ci/<slug>`             | `main`                |

`<scope>` is `api`, `pwa`, or a bounded context (`backoffice`, `frontoffice`, `shared`). Keep branches short-lived; rebase onto base rather than merging it back in.

### Commit messages

[Conventional Commits](https://www.conventionalcommits.org/): `<type>(<scope>): <subject>` — subject lower-case, imperative, no trailing period. Types: `feat | fix | docs | style | refactor | perf | test | build | ci | chore | revert`. Full pre-commit hook setup in [`docs/contribution-guide.md`](docs/contribution-guide.md).

**A commit that ships a story names it in the subject** — `feat(shared): keep person ids out of audit_log.metadata (G-2) (#636)`. This is not decoration: it is the only input to check B of `make bmad.status.audit`, which reports a story still marked `review`/`in-progress` whose tag appears in a commit that **changed something other than documentation**. Drop the tag and the check does not weaken, it goes **silent** — measured across the gdpr-hardening epic, where the eight code commits named no story at all and the only subjects carrying `G-…` were the `docs(…)`/`chore(bmad)` ones the check discards by design, so a story merged at `in-progress` drew no warning. The tag belongs on the commit that carries the **code**; putting it only on the context or closing commit is what produces a green audit over a drifted file.

### Dependency updates (dependabot)

Dependabot opens **one PR per dependency** across four weekly ecosystems (`.github/dependabot.yaml`: npm, github-actions, composer, docker). A dependency is keyed on the full `<owner>/<repo>/<path>`, so **each sub-path of an action repository counts as its own** — `actions/checkout` moves all 11 of its pin sites in one PR, while `github/codeql-action` splits into `init`, `analyze` (`codeql.yml`) and `upload-sarif` (`ci.yml`). That split cannot go green: `init` and `analyze` validate a shared config version at runtime, so a job straddling two releases dies with `Loaded a configuration file for version 'X', but running version 'Y'`. **Such a red is the split itself, not a regression to investigate** — the sub-paths only go green moving together in one commit. `codeql-action` is therefore **grouped in `.github/dependabot.yaml`** and arrives as a single PR; it is the only multi-sub-path action in the tree, and any future one needs its own group rather than a hand-batch every release (the detection one-liner lives in `/deps-update` step 3A). **Sub-paths are not the only way a split cannot go green.** Two packages can be coupled through an API neither declares: the reach lives in `vendor/`, and a requirement is a *lower bound*, so the constraint cheerfully admits the release that breaks it — only a `conflict` entry would refuse it and neither carries one. Measured — `phpstan/phpstan` 2.2.5→2.2.8 **alone** fatals `php.rector.dry-run` (`MissingPrivatePropertyException` on `PHPStan\Parser\RichParser::$container`, which Rector reads through `PrivatesAccessor`), while the **co-moved pair does not**; #739 moves both because rector 2.6.1 raises its floor to `^2.2.6`, so read a dependabot PR's diff before believing its title. **That A/B is the detector, not the error string** (`/deps-update` step 3B) — `composer why-not` is silent in the breaking direction and names the pair in the other, so run it both ways, and note rector 2.6.1 repairs one reach into `RichParser` while keeping two. Distinct again from a fixer bump activating rules it already shipped — the same dry-run wanted 39 files rewritten — which survives any batching, is real work, and turns a dependency-only batch into a source-touching PR that forfeits the checklist exemption (step 3C). Batch with `/deps-update` (`--dry-run` to inventory only); it classifies by the path segment after `dependabot/` (never by substring — `dependabot/github_actions/docker/bake-action-…` is an *actions* bump), never merges the dependabot branches for npm/composer (each rewrites the whole lockfile and they conflict pairwise) but re-resolves the ranges in one install, and stops for branch authorization before creating anything. **That install is not by itself the batch**: `npm install` moves nothing whose locked version already satisfies its range, so a bump needing no manifest edit (a dependabot PR touching only `package-lock.json` — `@types/react` under a `^19` pin) is dropped in silence and the batch ships naming a version its lock does not hold. Nothing goes red, and the *added: 0, removed: 0* supply-chain assertion passes over it — that check proves nothing was **gained**, never that everything was **moved**. Read every claimed version back out of the resolved lock before committing (`/deps-update` step 7).

### Code comments (`api/src`, `pwa/src`, tests)

A comment must explain the non-obvious *why* of the **current** code, standing on its own with no diff context. Two patterns are banned from anything merged to `main`:

- **Change-relative comments** — never describe the change or what was there before ("previously…", "replaces the old X", "now uses Y instead of…"). A month later the "before" doesn't exist and the comment is unverifiable noise. Git history and the PR carry that record.
- **Story / requirement IDs** — `Story 1.7`, `NFR4`, AC numbers, ticket refs. Fine as scaffolding *while* developing a task, but sweep your own diff and delete them before the final commit; traceability lives in the PR, commit messages, and spec artifacts.

No mass cleanup of existing files. Instead apply the **boy scout rule**: when editing a file for any reason, also remove this kind of stale comment you find in it — leave the file better than you found it.

### Do not touch

- `api/config/reference.php` — auto-generated.
- `api/vendor/`, `pwa/node_modules/` — package-manager managed.
- `api/var/` — Symfony runtime cache and logs; never commit.
- `api/migrations/` once merged — generate new ones via `make db.diff`. Editing a migration on the current feature branch is allowed; editing an applied/merged one is not.

### Temporary / scratch files

Write any ad-hoc temporary file Claude creates (hand-off prompts, intermediate outputs, working notes, throwaway scripts) under the project-root `tmp/` directory — never the system scratchpad, bare `/tmp`, or anywhere else in the tree. `tmp/` is gitignored, so its contents stay out of every commit. This overrides any default scratch-directory behaviour.

### bmad working artifacts

Files under `_bmad-output/implementation-artifacts/` are **transient working artifacts**, not durable docs.

- **Done spec → delete it.** A `spec-*.md` is a quick-dev design contract whose intent is spent once the work ships. When its frontmatter `status:` is `done`, remove it from the tree — git history + shipped tests + PR carry the record. Keep in-progress specs (`Status: ready-for-dev`, etc.). Grep the filename before deleting so no Markdown link breaks. `/prune-done-specs` sweeps them (`--dry-run` to preview).
- **`deferred-work.md` is pending-only.** A live registry, not a changelog: on resolving an item, **delete its bullet** rather than annotating it "done" inline. If the resolving PR also added it, restore the file to `origin/main` so the net diff is empty. (The pending registry was migrated to GitHub issues #194–#207.)
- Keep the live registries (`deferred-work.md`, `sprint-status.yaml`); never delete those for being "done".
- **`sprint-status.yaml` markers drift by default.** Nothing moves them on its own, so a story sits at `review` and its epic at `in-progress` long after the work is finished. **Move a story's key to `done` when the code review of the complete task is done** — that review is the last step that reads the whole task at once, so it is the moment the marker can be set against evidence instead of against an intention. Merging is the wrong trigger: it happens outside the session that did the work, often days later, and nobody is prompted by it. The marker therefore runs *ahead* of the merge by design — **a `done` key is not evidence the branch shipped**; read git for that. `make bmad.status.audit` remains the backstop for the ones that never moved at all (offline; also runs from a `SessionStart` hook, silent when clean).

### Markdown link style

The repo's IDE Markdown linter rejects link targets that don't resolve to a concrete file. When writing/editing any `.md`:

- **Link only to concrete files.** No trailing-slash directory hrefs (`[api/docs/](api/docs/)`) — pick a representative file or use inline code: `` `api/docs/` ``.
- **Don't link to globs** like `[…](docs/rules/*.md)`. Use inline code: `` `docs/rules/*.md` ``, optionally plus a link to one specific rule file.

Fix violations you spot while editing a file for another reason.

### Docs density (`docs/`)

`docs/` is durable reference, and every line there is maintenance debt — high density is the rule:

- **Which folder a doc belongs to and how its file is named** → [`docs/rules/documentation.md`](docs/rules/documentation.md): the folder taxonomy plus kebab-case-by-topic filenames, never sequence-numbered (ADRs included).
- **State decisions and constraints, not the process that produced them.** No workflow narrative, step scaffolding, readiness checklists, or "this document builds collaboratively" boilerplate — that belongs in `_bmad-output/` working artifacts and dies with them. BMAD/workflow output gets **distilled** before landing under `docs/`, never copied verbatim.
- **Prefer extending the doc that owns the topic over creating a new `.md`.** A new file must answer a question no existing doc owns; it gets an entry in `docs/index.md`. Point-in-time reports and plans whose work shipped get deleted (git preserves them).
- **ADRs (`docs/adr/`)** follow the style of [`docs/adr/bank-bankaccount-modeling.md`](docs/adr/bank-bankaccount-modeling.md): context, numbered decisions with discarded alternatives inline, the non-obvious why — target ≤ ~150 lines. The current-state description belongs in the architecture docs, not the ADR.

### Keeping docs up to date

Update the matching file as part of any PR that changes:

- **New Make targets or commands** → this file (`CLAUDE.md`), [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md), the relevant `make/*.mk` module, and [`docs/development-guide-api.md`](docs/development-guide-api.md) / [`docs/development-guide-pwa.md`](docs/development-guide-pwa.md) when the workflow surface changes.
- **New / renamed `src/` directories** → [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md), [`docs/architecture-api.md`](docs/architecture-api.md) or [`docs/architecture-pwa.md`](docs/architecture-pwa.md), and [`docs/source-tree-analysis.md`](docs/source-tree-analysis.md).
- **Architecture decisions** → [`docs/architecture-api.md`](docs/architecture-api.md) / [`docs/architecture-pwa.md`](docs/architecture-pwa.md), plus [`docs/integration-architecture.md`](docs/integration-architecture.md) when cross-deployable.
- **Domain events / Messenger transports** → [`docs/architecture-api.md`](docs/architecture-api.md).
- **API endpoints, controllers, or response shapes** → `api/docs/` and [`docs/architecture-api.md`](docs/architecture-api.md).
- **Error contract (markers, status mapping, redaction, `debug` block, per-error log line shape, `exception_category` taxonomy)** → [`docs/api-error-contract.md`](docs/api-error-contract.md). Adding a marker interface, changing its mapping, or changing the per-error log line (fields, declaration order, level tiering, `exception_category` dispatch) is mandatory here (NFR26).
- **PWA module boundaries / Inversify bindings** → `pwa/docs/` and [`docs/architecture-pwa.md`](docs/architecture-pwa.md).
- **Deployment / Compose / CORS / Mercure / mailer** → [`docs/deployment-guide.md`](docs/deployment-guide.md) and [`pwa/docs/production-deployment.md`](pwa/docs/production-deployment.md); local prod rehearsal → [`docs/erpify-local-test-deployment.md`](docs/erpify-local-test-deployment.md); VPS promotion + remote DB access → [`docs/vps-deployment.md`](docs/vps-deployment.md).
- **Security-sensitive change** → `PRODUCTION_SECURITY_CHECKLIST.md` (authoritative — see [`docs/rules/security.md`](docs/rules/security.md)).

When a rule here conflicts with `docs/rules/*.md`, [`api/CLAUDE.md`](api/CLAUDE.md), or [`pwa/CLAUDE.md`](pwa/CLAUDE.md), flag the conflict rather than silently picking one.

---

## Docs to consult

- [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md) — full command catalog, layout tables, recipes, gotchas.
- [`docs/index.md`](docs/index.md) — generated documentation index.
- [`docs/integration-architecture.md`](docs/integration-architecture.md) — how FrankenPHP / Next / Symfony share `localhost`.
- [`docs/architecture-api.md`](docs/architecture-api.md) — API layering, domain events, Messenger, audit table.
- [`docs/architecture-pwa.md`](docs/architecture-pwa.md) — PWA layering and module boundaries.
- [`docs/api-error-contract.md`](docs/api-error-contract.md) — RFC 9457 Problem Details: marker → status map, env-aware `debug`, redaction, performance budgets.
- [`docs/deployment-guide.md`](docs/deployment-guide.md), [`pwa/docs/production-deployment.md`](pwa/docs/production-deployment.md) — prod Compose, mailer, DNS, CORS, Mercure, smoke tests.
- [`docs/vps-deployment.md`](docs/vps-deployment.md) — VPS promotion + remote database access (CLI / GUI over SSH, pinned internal IP).
- [`docs/development-guide-api.md`](docs/development-guide-api.md), [`docs/development-guide-pwa.md`](docs/development-guide-pwa.md) — day-to-day workflows.
- [`docs/contribution-guide.md`](docs/contribution-guide.md), [`docs/source-tree-analysis.md`](docs/source-tree-analysis.md).
- [`api/README.md`](api/README.md), `api/docs/`, [`pwa/README.md`](pwa/README.md), `pwa/docs/` — deployable-specific details.
