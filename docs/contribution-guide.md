# Contribution Guide

_For detailed rules, cross-reference [`project-context.md`](./project-context.md) and `docs/rules/*.md` — those are authoritative._

## Before you start

1. Load [`project-context.md`](./project-context.md) into your tool/IDE context — it encodes the non-obvious rules AI agents and humans both need.
2. Skim [`architecture-api.md`](./architecture-api.md) or [`architecture-pwa.md`](./architecture-pwa.md) for the part you're changing.
3. If you're touching multi-part behavior, read [`integration-architecture.md`](./integration-architecture.md).
4. If you're touching error handling on `/api/*`, read [`api-error-contract.md`](./api-error-contract.md) — never bypass the RFC 9457 pipeline with manual `JsonResponse` error bodies.

## Branches

- Trunk: `main`. Never force-push `main`.
- Feature: `feat/<scope>-<slug>` (e.g. `feat/bank-export`).
- Fix: `fix/<scope>-<slug>`.
- Chore / CI / docs: `chore/...`, `ci/...`, `docs/...`.
- Keep branches short-lived. **Rebase** onto `main` rather than merging `main` in repeatedly.

## Commits (Conventional Commits, enforced)

```text
<type>(<scope>): <subject>

<optional body: explain WHY, not what>

<optional footer(s): Closes #123>
```

- **Types**: `feat | fix | docs | style | refactor | perf | test | build | ci | chore | revert`.
- **Subject**: lower-case, imperative, no trailing period.
- Body lines wrapped reasonably; reference issues in the footer.
- Validation runs via pre-commit / commitlint hooks.

### Pre-commit hooks (install once)

```bash
pip install pre-commit
pre-commit install
pre-commit install --hook-type commit-msg
detect-secrets scan > .secrets.baseline    # if not already present
```

Hooks run on every commit: trailing whitespace, EOF fixer, YAML/JSON/TOML validation, large-file / merge-conflict / case-conflict / mixed-line-ending / private-key / AWS-credential / secret detection, Conventional Commit validation, PHP syntax checks.

**If a hook fails:** fix the underlying issue, re-stage, create a **new** commit. Never `--amend` after a hook failure (the original commit didn't happen). Never `--no-verify` without explicit authorisation.

## Before committing — security checks

Walk through `docs/rules/security.md`. In particular:

- No hardcoded secrets / API keys / tokens. No `.env` files committed.
- No debug code: `var_dump`, `print_r`, `dd()`, `console.log`, `console.debug`.
- SQL only via Doctrine DBAL parameterised APIs or ORM.
- If security-relevant files changed, update `PRODUCTION_SECURITY_CHECKLIST.md` in the same commit.

## Tests must pass

- `make app.test` (aggregate) — equivalent to `make ci.test`.
- All existing and new tests **100% green** before opening a PR.
- New code in `Domain/` (API) or `context/<bc>/domain/` (PWA) must have unit tests.

## Linters

- Run `make app.quality` (PHP + JS aggregate) before pushing.
- Individual tools: `make php.quality`, `make pwa.quality`. Auto-fix variants: `php.rector`, `php.cs-fixer`, `php.cs`, `pwa.lint`, `pwa.format`.

## Pull requests

- Target `main`. Title mirrors the primary commit's Conventional Commit subject.
- Body: **what** changed, **why**, **test plan** (bulleted checklist). Screenshots for UI changes.
- CI must be green (`ci.yml` + CodeQL). If you touched files that SuperLinter covers, also run `make super-lint.fast`.
- Require one review minimum. Security-sensitive changes must include the checklist update in the PR body.
- **Never** force-push shared branches without coordinating.

## Coding rules — load-bearing summary

See [`project-context.md`](./project-context.md) for the full set. Highlights:

- **DDD / Hexagonal discipline.** `Domain` is framework-free. No cross-context reach-ins.
- **PHP**: `declare(strict_types=1);` everywhere, PSR-12, exceptions for errors, no global state, attribute-only routing.
- **TypeScript**: `strict: true`, named exports under `src/context/**`, no `React.FC`, respect server/client boundary.
- **Doctrine 3 / DBAL 4**: no `flush($entity)`, no `fetchAll()`, no `Connection::query()`.
- **Tailwind 4**: no `tailwind.config.js` — CSS-first via `@theme`/`@config`.
- **Playwright**: `baseURL` is resolved in `pwa/playwright.config.ts` — `PLAYWRIGHT_BASE_URL ?? (CI ? https://localhost : http://127.0.0.1:3000)` — never hard-coded in a spec. The default host is `127.0.0.1`, so `https://localhost` cookies do not reach it.
- **Messenger**: handlers idempotent; `messenger_worker` is a separate Compose service in prod/ci.
- **Make-first**: run commands from repo root via `make` targets, not raw `docker compose` / `composer` / `npm`.

## Reporting issues

- Security issues: report privately first — do not open a public issue.
- Bug reports should include: reproduction, expected vs actual, env (`ENV`, browser), relevant logs (`make docker.logs`).
