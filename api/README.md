# Symfony Docker

> **ERPify monorepo:** service definitions (`php`, `database`, `pwa`, `messenger_worker`) live in the **repository root** [`compose.yaml`](../compose.yaml) with overlays [`compose.dev.yaml`](../compose.dev.yaml) and [`compose.prod.yaml`](../compose.prod.yaml). FrankenPHP in **`php`** reverse-proxies the Next app (`pwa:3000`) for HTML; there is no separate edge Caddy service. Prefer the root [`Makefile`](../Makefile) (e.g. **`make dev`**, **`make docker.up`**, **`make composer c='…'`**, **`make php.lint`**, **`make php.test`**, **`make db.migrate`**) — it is ENV-aware (`ENV=dev|staging|prod`) and wraps Compose/Composer/Symfony from the repo root. The **`php`** base image in [`Dockerfile`](Dockerfile) is pinned to a **`sha256`** digest and tracked by Dependabot. When syncing upstream **symfony-docker**, merge changes into the **root** Compose files. Domain events, Messenger (`messenger_worker`), and notification email: [`docs/domain-events-and-messenger.md`](../docs/domain-events-and-messenger.md).

A [Docker](https://www.docker.com/)-based installer and runtime for the [Symfony](https://symfony.com) web framework,
with [FrankenPHP](https://frankenphp.dev) and [Caddy](https://caddyserver.com/) inside!

Specially tailored for coding agents: the monorepo [Dev Container](https://containers.dev/) lives at [`.devcontainer/`](../.devcontainer/) (repo root) so you work in **`/workspace`** with **`api/`**, **`pwa/`**, and Compose together. It lets [Claude Code](https://claude.ai/claude-code) (and other assistants) run in autonomous mode inside a sandboxed environment.

![CI](https://github.com/dunglas/symfony-docker/workflows/CI/badge.svg)

## Getting Started

1. If not already done, [install Docker Compose](https://docs.docker.com/compose/install/) (v2.10+)
2. Run `docker compose build --pull --no-cache` to build fresh images
3. Run `docker compose up --wait` to set up and start a fresh Symfony project
4. Open `https://localhost` in your favorite web browser and [accept the auto-generated TLS certificate](https://stackoverflow.com/a/15076602/1352334)
5. Run `docker compose down --remove-orphans` to stop the Docker containers.

## Features

- Production, development and CI ready
- Just 1 service by default
- Super-readable configuration
- Blazing-fast performance thanks to [the worker mode of FrankenPHP](https://frankenphp.dev/docs/worker/)
- [Installation of extra Docker Compose services](docs/extra-services.md) with Symfony Flex
- Automatic HTTPS (in dev and prod)
- HTTP/3 and [Early Hints](https://symfony.com/blog/new-in-symfony-6-3-early-hints) support
- Real-time messaging thanks to a built-in [Mercure hub](https://symfony.com/doc/current/mercure.html)
- [Vulcain](https://vulcain.rocks) support
- Native [XDebug](docs/xdebug.md) integration
- [Hot Reloading](https://frankenphp.dev/docs/hot-reload/)
- [Dev Container](https://containers.dev/) support, optimized for AI coding agents
- [AI coding agents](docs/agents.md) with sandboxing out of the box
- Rootless, slim production image

**Enjoy!**

## Docs

1. [Options available](docs/options.md)
2. [Using Symfony Docker with an existing project](docs/existing-project.md)
3. [Support for extra services](docs/extra-services.md)
4. [Deploying in production](docs/production-ready/production.md)
5. [Monorepo production (Messenger, mailer, DNS)](../docs/production-deployment.md) (repo root `docs/`)
6. [Debugging with Xdebug](docs/xdebug.md)
7. [TLS Certificates](docs/tls.md)
8. [Using MySQL instead of PostgreSQL](docs/mysql.md)
9. [Using Alpine Linux instead of Debian](docs/alpine.md)
10. [Using a Makefile](docs/makefile.md)
11. [Updating the template](docs/updating.md)
12. [Troubleshooting](docs/troubleshooting.md)
13. [Using AI Coding Agents](docs/agents.md)

## License

Symfony Docker is available under the MIT License.

## Credits

Created by [Kévin Dunglas](https://dunglas.dev), co-maintained by [Maxime Helias](https://twitter.com/maxhelias) and sponsored by [Les-Tilleuls.coop](https://les-tilleuls.coop).
