# ERPify — monorepo workspace

You are working on **ERPify**, a monorepo opened at **`/workspace`** in the Dev Container (repository root on the host). The Symfony API lives in **`api/`** (also mounted at **`/app`** for FrankenPHP). **`pwa/`** is the Next.js app; Compose and the Makefile are at the repo root.

## Stack

- **Symfony 8** on **PHP ^8.5**, served by **FrankenPHP** with **Caddy** (symfony-docker template). Real-time: **Mercure**; optional **Vulcain** preloading.
- PHP namespace: **`Erpify\`** (`api/composer.json` → `api/src/`, tests → `Erpify\Tests\` under `api/tests/`).
- Application code under **`api/src/`** (bounded-context style). Prefer **constructor injection**, **`declare(strict_types=1);`**, and Symfony **attributes** for routing (`#[Route]`).

## Everyday commands (inside the container)

| Task | Command (typical) |
| ---- | ------------------- |
| Symfony console | `cd /workspace/api && bin/console` (same tree as **`cd /app && bin/console`**) |
| Composer | `cd /workspace/api && composer ...` |
| PHPUnit | `cd /workspace/api && APP_ENV=test bin/phpunit` (config: `api/tools/phpunit/phpunit.dist.xml`) |
| Behat | `cd /workspace/api && composer behat-tools-install` once, then `APP_ENV=test bin/behat` (`MINK_BASE_URL` defaults to `http://php` via root `Makefile` for `make php.behat` on the **host**) |
| PWA | `cd /workspace/pwa && npm …` |
| Compose / Make | On the **host**, from the repo root: **`make up`**, **`make health`**, etc. Inside this container, use **`docker compose`** from **`/workspace`** if the Docker CLI/socket is available, or run Composer/PHP commands in **`/workspace/api`**. |

Compose files are only at the **monorepo root** (`compose.yaml`, `compose.override.yaml`). There are no Compose files under `api/`.

## Tests and CI

- **PHPUnit** — dev dependency; wrapper `api/bin/phpunit`.
- **Behat** — isolated tree under `api/tools/behat`.
- **GitHub Actions** — [`.github/workflows/ci.yml`](.github/workflows/ci.yml) at the repo root.

## Project documentation

- **Dev Container / AI agents (full guide):** [`api/docs/agents.md`](../api/docs/agents.md)
- **Symfony Docker template topics:** `api/docs/*.md`
- **Repository-wide requirements:** [`docs/project-requirements.md`](../docs/project-requirements.md)

## Dev Container: outbound firewall

This environment uses an outbound firewall that blocks traffic except to **allowlisted** domains. If `curl`, `composer`, or `npm` fails against a new host, that host must be added to the allowlist.

Edit **`.devcontainer/init-firewall.sh`** and add the domain to the **`ipset=`** line in the dnsmasq block (domains are `/`-separated, ending with the ipset name):

```bash
ipset=/github.com/anthropic.com/.../NEW_DOMAIN.COM/allowed-domains
```

Rebuild the Dev Container for the change to apply. For the current default allowlist and agent-specific domains, see **`api/docs/agents.md`**.
