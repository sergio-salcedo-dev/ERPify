# Contribution Guide

_For detailed rules, cross-reference [`project-context.md`](./project-context.md) and `.cursor/rules/*.mdc` — those are authoritative._

## Before you start

1. Load [`project-context.md`](./project-context.md) into your tool/IDE context — it encodes the non-obvious rules AI agents and humans both need.
2. Skim [`architecture-api.md`](./architecture-api.md) or [`architecture-pwa.md`](./architecture-pwa.md) for the part you're changing.
3. If you're touching multi-part behavior, read [`integration-architecture.md`](./integration-architecture.md).

## Branches

- Trunk: `main`. Never force-push `main`.
- Feature: `feat/<scope>-<slug>` (e.g. `feat/bank-export`).
- Fix: `fix/<scope>-<slug>`.
- Chore / CI / docs: `chore/...`, `ci/...`, `docs/...`.
- Keep branches short-lived. **Rebase** onto `main` rather than merging `main` in repeatedly.

## Commits (Conventional Commits, enforced)

```
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

Walk through `.cursor/rules/security.mdc`. In particular:

- No hardcoded secrets / API keys / tokens. No `.env` files committed.
- No debug code: `var_dump`, `print_r`, `dd()`, `console.log`, `console.debug`.
- SQL only via Doctrine DBAL parameterised APIs or ORM.
- If security-relevant files changed, update `PRODUCTION_SECURITY_CHECKLIST.md` in the same commit.

## Tests must pass

- `make test` (aggregate) — equivalent to `make ci.test`.
- All existing and new tests **100% green** before opening a PR.
- New code in `Domain/` (API) or `context/<bc>/domain/` (PWA) must have unit tests.

## Linters

- Run `make lint` (PHP + JS aggregate) before pushing.
- Individual tools: `make php.lint`, `make pwa.lint`. Auto-fix variants: `php.rector`, `php.cs-fixer`, `php.cs`, `pwa.lint.fix`, `pwa.format.fix`.

## Pull requests

- Target `main`. Title mirrors the primary commit's Conventional Commit subject.
- Body: **what** changed, **why**, **test plan** (bulleted checklist). Screenshots for UI changes.
- CI must be green (`ci.yml` + CodeQL). If you touched files that SuperLinter covers, also run `make ci.superlint`.
- Require one review minimum. Security-sensitive changes must include the checklist update in the PR body.
- **Never** force-push shared branches without coordinating.

## Coding rules — load-bearing summary

See [`project-context.md`](./project-context.md) for the full set. Highlights:

- **DDD / Hexagonal discipline.** `Domain` is framework-free. No cross-context reach-ins.
- **PHP**: `declare(strict_types=1);` everywhere, PSR-12, exceptions for errors, no global state, attribute-only routing.
- **TypeScript**: `strict: true`, named exports under `src/context/**`, no `React.FC`, respect server/client boundary.
- **Doctrine 3 / DBAL 4**: no `flush($entity)`, no `fetchAll()`, no `Connection::query()`.
- **Tailwind 4**: no `tailwind.config.js` — CSS-first via `@theme`/`@config`.
- **Playwright**: `baseURL: http://localhost:3000`, not `:80`.
- **Messenger**: handlers idempotent; `messenger_worker` is a separate Compose service in prod/ci.
- **Make-first**: run commands from repo root via `make` targets, not raw `docker compose` / `composer` / `npm`.

## Reporting issues

- Security issues: report privately first — do not open a public issue.
- Bug reports should include: reproduction, expected vs actual, env (`ENV`, browser), relevant logs (`make docker.logs`).
