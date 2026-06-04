# ERPify — Claude Code instructions

Monorepo with two deployables sharing one Compose stack: a Symfony HTTP API on FrankenPHP and a Next.js PWA. Nested `CLAUDE.md` files ([`api/CLAUDE.md`](api/CLAUDE.md), [`pwa/CLAUDE.md`](pwa/CLAUDE.md)) auto-load inside their subtree — this file is the monorepo-wide baseline.

**Stack:** PHP 8.5 · Symfony 8 · FrankenPHP (Caddy embedded) · PostgreSQL 18 · Doctrine ORM · Symfony Messenger · Mercure · Next.js 16 (App Router) · TypeScript · Tailwind 4 · Inversify · Vitest · Playwright · PHPUnit · Behat

> Full command catalog, repo layout tables, "adding new code" recipes, and gotchas → [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md). Run `make help` for the live target list.


## What to do

- Every time Claude makes a mistake → you add a rule
- Every time you repeat yourself → you add a workflow
- Every time something breaks → you add a guardrail
-
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
make php.quality                       # Full PHP lint sweep — REQUIRED at end of any PHP task.
make db.diff                        # Generate migration from entity diff (then make db.migrate).
make pwa.dev                        # Next dev (Turbopack, host :80) — needs pwa/.env.local.
make pwa.test                       # Vitest + Playwright (also: make pwa.test.unit/.e2e).
make pwa.quality                    # ESLint + Prettier — REQUIRED at end of any PWA task.
make app.quality                    # All linters (PHP + PWA).
make app.test                       # All tests (PHP + PWA).
make ci                             # Full CI (ci.quality + ci.test).
```

**Always start the stack with `make app.dev` or `make docker.up`.** Bare `docker compose up -d` skips composer install on cold checkouts and the `pwa.install.if-missing` guard.

**Per-worktree stacks — isolated, no collision with `main`.** You can bring a worktree's stack up without touching the primary checkout's. `make/config.mk` derives `COMPOSE_PROJECT_NAME` from the checkout automatically: the primary keeps the bare `erpify` (fixed ports `80/443/15432/8025`, volumes untouched); a linked worktree under `.claude/worktrees/` gets `erpify-<dir-slug>` and, in dev, publishes host ports ephemerally (`0` → a random free port) so it never collides with `main` or other worktrees. Run `make app.dev` / `make docker.up` from inside the worktree — `make` targets then exec into *that* stack (so checks/tests see the worktree's code). Worktree stacks are driven via `docker compose exec` and the internal network (`MINK_BASE_URL`, pwa→php), not fixed host ports — so the random ports don't matter for the checks/tests a worktree runs.

**Creating a worktree.** `make worktree.create BRANCH=<branch>` adds a linked worktree under `.claude/worktrees/` on a *new* branch off `BASE=` (default `main`). A random 4-char suffix is appended to **both** the branch and the dir slug, so the branch, the dir, and its `erpify-<slug>` Compose project never collide — `feat/foo` and `fix/foo` can coexist, and re-running is always safe. `NAME=<dir-base>` overrides the derived dir slug (still suffixed); `START=true` also brings the new stack up via `make app.dev`, otherwise it just prints the `cd … && make app.dev` next step.

**Tearing a worktree down.** `make worktree.remove NAME=<dir>` removes one (its isolated `erpify-<slug>` stack + volumes, the worktree, then its branch); `make worktree.remove-all` clears every linked worktree. Both are **local only** — nothing is pushed to the remote. `FORCE=true` discards a dirty worktree and deletes a not-fully-merged branch (a squash-merged branch looks unmerged to git, so the merged-PR case needs `FORCE=true`). `make worktree.list` shows the `NAME` values. Degraded states are handled too: if the worktree's dir was deleted out-of-band, the stack is torn down by project name instead; and if only the branch survived a half-finished removal, `NAME=<branch>` deletes just that branch.

**Browsing a worktree's UI (on demand).** The default is browse-from-`main`. When you do need a worktree's UI in the browser, opt that run into a fixed, non-colliding port instead of adding any new tooling — every `*_PORT` is `?=`, so a value you pass wins over the ephemeral `0`:

```bash
HTTPS_PORT=8443 make docker.up      # from inside the worktree; pick any free port ≠ main's 443
# then open https://localhost:8443  — HTTPS_PORT also feeds DEFAULT_URI / Mercure, so internal URLs stay consistent
```

Set additional `*_PORT` vars (`HTTP_PORT`, `MAILPIT_UI_PORT`, `POSTGRES_PORT`, …) the same way if you need those surfaces too; leave the rest ephemeral. `make docker.info` prints the resolved project + ports. Full details → [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md).

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

Both sides follow **DDD + Hexagonal / Clean Architecture**, with dependencies pointing inward. **Do not** import frameworks (Symfony, Doctrine, Next, Inversify, HTTP clients, ORM) inside `Domain/` — adapters go in `Infrastructure/`, orchestration in `Application/`. Documented exception: API domain entities may carry passive metadata attributes (`#[ORM]`, `#[Assert]`, `#[Groups]`) — see [`docs/rules/architecture.md`](docs/rules/architecture.md). Full rule set: `docs/rules/*.md` (architecture, clean-code, database, frontend, php-standards, security, solid-principles, testing).

---

## Required checks

- **PHP edits** → run `make php.stan` on every changed file before declaring the task done; fix anything reported. At the end, run `make php.quality` and fix anything new it reports.
- **PWA edits** → run `make pwa.quality` at the end.
- **HTTP error responses** → never bypass the RFC 9457 pipeline with manual `JsonResponse` error bodies. Adding a marker interface or changing its mapping requires updating [`docs/api-error-contract.md`](docs/api-error-contract.md) (NFR26). The drift gate is `make php.lint.error-contract`.
- **Migrations** → generate via `make db.diff`. You may only edit a migration created on the current feature branch. Once merged into `main`, it is immutable — create a new migration instead.

---

## Working principles

1. Don't assume. Don't hide confusion. Surface tradeoffs.
2. Minimum code that solves the problem. Nothing speculative.
3. Touch only what you must. Clean up only your own mess.
4. Define success criteria. Loop until verified.
5. Learn from your errors; don't repeat them. Follow up with a plan or doc when warranted.

---

## Security review on every change (backend AND frontend)

Every PR — even a "small" one — MUST be self-reviewed for the most common
attack classes BEFORE asking for human review and BEFORE pushing the final
commit. This applies to both `api/` and `pwa/`. If a class doesn't apply,
say so in the PR description; don't silently skip.

For each diffed file, walk this checklist:

**Frontend (`pwa/`)**
- **XSS** — never write `dangerouslySetInnerHTML`, `innerHTML`,
  `document.write`, `eval`, or `new Function(string)`. Wrap every dynamic
  `href` / `src` / `router.push` URL in `safeHref(...)` from `@/lib/safeHref`
  and `encodeURIComponent` the dynamic path segment. Static `aria-label` /
  `title` only — see `pwa/CLAUDE.md` for the full rule list.
- **CSRF / Open redirect** — Validate that any URL coming from query
  params, location state, or API payload is same-origin or relative
  before navigating; reject `data:`, `javascript:`, `file:`, `vbscript:`.
- **Untrusted input → DOM attributes** — explicit allowlists for
  `target`, `rel`, `download`. External `target="_blank"` always pairs
  with `rel="noopener noreferrer"`.
- **Storage / clipboard** — never put secrets, JWTs, or PII into
  `localStorage` / `sessionStorage`; use httpOnly cookies. Clipboard
  writes go through `<CopyButton>` which never trusts the value as HTML.
- **Headers** — confirm `next.config.ts#headers()` (CSP,
  `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, COOP/CORP, HSTS) hasn't regressed. Don't add
  `'unsafe-eval'` to production CSP and don't widen `connect-src` /
  `script-src` without explicit reason.
- **Dependencies** — `npm audit` clean, no new transitive package whose
  ownership you haven't verified.

**Backend (`api/`)**
- **Injection** — every Doctrine query parameterised (`:placeholder` or
  query builder bindings), no raw `${}`/`.$variable.` interpolation
  reaching SQL or DQL. No `Process::fromShellCommandline($userInput)` —
  use the array form. No `unserialize($userInput)`.
- **Authentication / Authorization** — every new controller / handler
  declares the security voter or `IsGranted` it expects; absence is a
  conscious public route, called out in the PR.
- **Input validation** — every DTO carries Symfony Validator constraints
  (`#[Assert\…]`), enforced by `#[MapRequestPayload]` / `#[MapQueryString]`
  at mapping time; other inputs (route ids, uploads) go through the shared
  `Validator::ensure()` (`Shared/Application/Validation`) before any
  domain call. Validate IDs are UUIDs, not arbitrary strings.
- **Mass assignment / hidden fields** — entity setters / serializer
  groups never expose audit fields (`createdAt`, `updatedAt`, `id`) to
  client-supplied payloads.
- **Output encoding / serialization** — JSON-only responses, no HTML
  rendered server-side (no Twig templates emitting user content). Error
  payloads follow RFC 9457 (Problem Details) without leaking stack
  traces in non-dev environments.
- **Secrets** — never logged, never returned, never committed. Confirm
  `.env`/`*.local` are not in the diff. Use `APP_SECRET`,
  `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD` env-driven —
  see `PRODUCTION_SECURITY_CHECKLIST.md`.
- **CORS / CSRF / Mercure** — the allowlist hasn't broadened; the
  Mercure JWT secret rotation policy is preserved.
- **Migrations & data** — never seed PII or secrets; never
  `DROP TABLE` outside an explicit destructive migration; the `down()`
  method is reversible.
- **Domain events / Messenger** — handlers are idempotent; transports
  authenticated; payloads scrubbed of secrets.

**Process**
- Run `make php.quality` and `make pwa.quality` locally before pushing —
  PHPStan / Psalm / ESLint catch many of the above implicitly.
- For security-sensitive changes (auth, input parsing, file uploads,
  SQL, headers, CSP), update `PRODUCTION_SECURITY_CHECKLIST.md` and
  [`docs/rules/security.md`](docs/rules/security.md) if a new pattern is introduced.
- When a finding is genuinely out of scope, file a follow-up issue
  rather than ship-and-forget.

If you cannot answer "yes" to every applicable item, fix it in the same
PR or call it out explicitly in the PR description and link a tracking
issue. Silent skips are the most common path to a CVE.

---

## Parallelizing work with subagents

When a task decomposes into independent subtasks (different bounded contexts, different files, no shared state), spawn parallel subagents rather than working sequentially. Each subagent must receive a self-contained prompt with full context.

Example: plan → subagent A (API: domain entity + Doctrine mapping + migration in `api/`) + subagent B (PWA: route + component + Inversify wiring in `pwa/`) running in parallel → verify each (`make php.stan`, `make pwa.quality`) → commit.

Do not spawn subagents for tasks that share state mid-flight — e.g. two agents editing the same migration, the same `services.yaml`, the same Inversify container module, or both touching `api/src/Shared/`.

---

## Conventions

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

### Do not touch

- `api/config/reference.php` — auto-generated.
- `api/vendor/`, `pwa/node_modules/` — package-manager managed.
- `api/var/` — Symfony runtime cache and logs; never commit.
- `api/migrations/` once merged — generate new ones via `make db.diff`. Editing migrations on the current feature branch is allowed; editing applied/merged migrations is not.

### Markdown link style

The repo's IDE Markdown linter rejects link targets that don't resolve to a concrete file. When writing or editing any `.md`:

- **Link only to concrete files.** No trailing-slash directory hrefs (`[api/docs/](api/docs/)`). Pick a representative file or use inline code: `` `api/docs/` ``.
- **Don't link to globs** like `[…](docs/rules/*.md)`. Use inline code: `` `docs/rules/*.md` ``, optionally followed by a link to one specific rule file.

Fix violations you spot while editing a file for another reason.

### Keeping docs up to date

Update the matching file as part of any PR that changes:

- **New Make targets or commands** → this file (`CLAUDE.md`), [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md), the relevant `make/*.mk` module, and [`docs/development-guide-api.md`](docs/development-guide-api.md) / [`docs/development-guide-pwa.md`](docs/development-guide-pwa.md) when the workflow surface changes.
- **New `src/` directories or renamed ones** → [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md), [`docs/architecture-api.md`](docs/architecture-api.md) or [`docs/architecture-pwa.md`](docs/architecture-pwa.md), and [`docs/source-tree-analysis.md`](docs/source-tree-analysis.md).
- **Architecture decisions** → [`docs/architecture-api.md`](docs/architecture-api.md) / [`docs/architecture-pwa.md`](docs/architecture-pwa.md), and [`docs/integration-architecture.md`](docs/integration-architecture.md) when cross-deployable.
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
