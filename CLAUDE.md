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
make php.bash                       # Shell into the php container (also: php.sh, php.exec).
make sf c='about'                   # Symfony console (also: make sf.cc, make sf.routes f='…').
make composer c='req vendor/pkg'    # Composer in container.
make php.unit c='--filter SomeTest' # PHPUnit (also: make php.behat, make php.test).
make php.stan                       # PHPStan — REQUIRED on every PHP file you change.
make php.quality                    # Full PHP lint sweep — REQUIRED at end of any PHP task.
make db.diff                        # Generate migration from entity diff (then make db.migrate).
make pwa.dev                        # Next dev (Turbopack, host :80) — needs pwa/.env.local.
make pwa.test                       # Vitest + Playwright (also: make pwa.test.unit/.e2e).
make pwa.quality                    # ESLint + Prettier — REQUIRED at end of any PWA task.
make app.quality                    # All linters (PHP + PWA).
make app.test                       # All tests (PHP + PWA).
make ci                             # Full CI (ci.quality + ci.test).
```

**Always start the stack with `make app.dev` or `make docker.up`.** Bare `docker compose up -d` skips composer install on cold checkouts and the `pwa.install.if-missing` guard.

### Worktrees

**Hard rule — every new feature (or any multi-edit task) starts in a worktree**, never in the primary `main` checkout (a live shared surface where concurrent git ops can wipe uncommitted/branch work). Once authorized (see *Confirm branch creation & topology* below), create it *first*. `/feature <scope> <slug>` wraps the mechanics; fixes/chores follow the same rule with their branch prefix. Per-worktree stacks are isolated — `make/config.mk` derives `COMPOSE_PROJECT_NAME` per checkout (primary keeps bare `erpify` with fixed ports `80/443/15432/8025`; a worktree gets `erpify-<slug>` with ephemeral host ports), so they never collide. Run `make` targets from inside the worktree and they exec into *that* stack (checks/tests see the worktree's code, over the internal network — random host ports don't matter).

- **Create** — `make worktree.create BRANCH=<branch>`: linked worktree under `.claude/worktrees/`, new branch off `BASE=` (default `main`), random 4-char suffix on branch+dir+project so re-running is always safe. `NAME=<dir-base>` overrides the dir slug; `START=true` also runs `make app.dev`. Seeds `bmad-*` skills automatically.
- **Remove** — `make worktree.remove NAME=<dir>` (stack + volumes + worktree + branch; **local only**). `make worktree.remove-all`; `make worktree.list` for `NAME` values. `FORCE=true` discards a dirty worktree / deletes a not-fully-merged branch (squash-merged PRs look unmerged → need `FORCE=true`). On `Permission denied` (root-owned bind mounts: `pwa/.next`, `node_modules`, `api/var`) run `make worktree.chown` (sudo, dev/test) and retry.
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
- **HTTP error responses** → never bypass the RFC 9457 pipeline with manual `JsonResponse` error bodies. Adding a marker interface or changing its mapping requires updating [`docs/api-error-contract.md`](docs/api-error-contract.md) (NFR26). Drift gate: `make php.lint.error-contract`.
- **Bounded-context isolation** → never import another business context's `Domain\`/`Application\`/`Infrastructure\` (inject foreign repositories, know its internals) — reference identities and react to events instead (`Erpify\Shared\…` is always importable). A genuine published cross-context seam goes in `api/.bounded-context-allowlist`. Gate: `make php.lint.bounded-context` (Level 1 fails; cross-context FKs are Level 2 warnings). Rule: [`docs/rules/database.md`](docs/rules/database.md).
- **Architecture boundaries (deptrac)** → `make php.deptrac` is the AST-aware gate over `api/src` enforcing hexagonal layering (Infrastructure → Application → Domain), bounded-context isolation (defence-in-depth alongside `php.lint.bounded-context`), and the Domain/Application external-dependency allowlist (only PSR/`symfony/uid`/passive-metadata attributes inward; frameworks confined to `Infrastructure/`). Config `api/tools/deptrac/deptrac.yaml`; pre-existing inner-layer framework deps are grandfathered in `tools/deptrac/deptrac.baseline.yaml` (a ratchet — new ones fail). Published cross-context seams mirror `api/.bounded-context-allowlist` in the config's `skip_violations`. Wired into `php.quality` / `php.quality.dry-run` (so CI gates on it). Rule: [`docs/adr/external-dependencies-in-domain.md`](docs/adr/external-dependencies-in-domain.md).
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
- **No security, GDPR, or audit-surface work reaches `done` without a recorded adversarial pass.** Self-certification does not count: a hostile read by someone other than the author (a fresh context, a different model, or a human) is the gate, and *where it was recorded* — PR description, review thread, or the story artifact — must be stated when the work is declared done. The rule exists because this class of defect is invisible to the checklist above: the failure modes that shipped here (drain the org to zero admins under concurrency; PII surviving its own erasure) each needed someone actively trying to break the change, and the two most sensitive stories of an epic are exactly the ones that tend to skip it. A pass that finds nothing still counts — record it and say so.
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
- **`sprint-status.yaml` markers drift by default.** Merging a PR moves nothing, so a story stays at `review` and its epic at `in-progress` long after shipping. `make bmad.status.audit` reports it (offline; also runs from a `SessionStart` hook, silent when clean). When you merge a story's PR, move its key to `done` in the same breath.

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
