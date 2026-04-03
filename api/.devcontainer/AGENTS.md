# ERPify — API workspace context

You are working on **ERPify**, a monorepo whose backend is the Symfony application in **`api/`**. In the Dev Container, the workspace root is **`/app`** (usually the same folder as `api/` on the host). Monorepo siblings such as **`docs/project-requirements.md`** or the root **`Makefile`** may live one level above `api/` on disk; they are only visible inside the container if that parent directory is part of the mount (otherwise use the host copy or open the repo root in the editor).

## Stack

- **Symfony 8** on **PHP ^8.5**, served by **FrankenPHP** with **Caddy** (symfony-docker template). Real-time: **Mercure**; optional **Vulcain** preloading.
- PHP namespace: **`Erpify\`** (`composer.json` → `src/`, tests → `Erpify\Tests\` under `tests/`).
- Application code is organized by bounded-context style folders under `src/` (e.g. `Backoffice/...`). Prefer **constructor injection**, **`declare(strict_types=1);`**, and Symfony **attributes** for routing (`#[Route]`).

## Everyday commands (inside the container, cwd `/app`)

| Task | Command |
| ---- | ------- |
| Symfony console | `bin/console` (or `php bin/console`) |
| Composer | `composer ...` |
| PHPUnit | `APP_ENV=test bin/phpunit` (config: `tools/phpunit/phpunit.dist.xml`) |
| Behat | `composer behat-tools-install` once, then `APP_ENV=test bin/behat` (wrapper uses `tools/behat/`; `MINK_BASE_URL` defaults to `http://php` via root `Makefile` when using `make php.behat`) |
| Clear cache | `bin/console cache:clear` |

On the **host**, the root **`Makefile`** runs Compose from `./api` (e.g. `make up`, `make health`, `make php.unit`, `make sf c='about'`). See `docs/project-requirements.md` at the repo root for host tooling expectations.

## Tests and CI

- **PHPUnit** is a dev dependency; wrapper is `bin/phpunit` pointing at `tools/phpunit/phpunit.dist.xml`.
- **Behat** lives in a separate Composer tree under `tools/behat` to avoid version clashes with Symfony 8; use `composer behat-tools-install` before running scenarios.
- **GitHub Actions** (`.github/workflows/ci.yaml`): Docker build, `docker compose up --wait`, HTTP checks. Keep changes compatible with that workflow.

## Project documentation

- **Dev Container / AI agents (full guide):** `docs/agents.md` — firewall, YOLO mode, other agents (Codex, opencode), troubleshooting.
- **Symfony Docker template topics:** `docs/*.md` under this tree (TLS, Xdebug, MySQL, Makefile alignment, etc.).
- **Repository-wide requirements:** `docs/project-requirements.md` (relative to monorepo root).

## Dev Container: outbound firewall

This environment uses an outbound firewall that blocks traffic except to **allowlisted** domains. If `curl`, `composer`, or `npm` fails against a new host, that host must be added to the allowlist.

Edit **`.devcontainer/init-firewall.sh`** and add the domain to the **`ipset=`** line in the dnsmasq block (domains are `/`-separated, ending with the ipset name):

```bash
ipset=/github.com/anthropic.com/.../NEW_DOMAIN.COM/allowed-domains
```

Rebuild the Dev Container for the change to apply. For the current default allowlist and agent-specific domains, see **`docs/agents.md`**.
