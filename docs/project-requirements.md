# Requirements

Tools you need on your **host** machine to work with this repository (the API itself runs in Docker).

## Required

- **Docker** and **Docker Compose** (v2) — to build and run the Symfony stack under `api/` (FrankenPHP/Caddy). See [api/README.md](../api/README.md) for the usual `docker compose` workflow.
- **GNU Make** — optional but expected if you use the root [`Makefile`](../Makefile) targets (`make up`, `make health`, etc.).

You do **not** need PHP on the host to run the app if you use Docker; use [`api/bin/sf`](../api/bin/sf) or `docker compose exec php bin/console` from `api/` for Symfony commands when `php` is not on your `PATH`.

## Recommended: `jq`

Install **[jq](https://jqlang.org/)** (a command-line JSON processor).

### Why it matters here

The root `make health` target calls `curl` against `https://localhost/api/v1/health` and checks that the response is HTTP 200 and that the JSON body reports a healthy status (`"status": "ok"`).

- **With `jq` installed:** the Makefile pretty-prints the response (`jq .`) and validates the payload with `jq -e '.status == "ok"'`. That checks real JSON structure, not just a text pattern.
- **Without `jq`:** the same target still works: it prints the raw body and uses `grep` to look for `"status":"ok"`. That is slightly more brittle (e.g. unusual spacing or unexpected extra fields are handled less cleanly than with `jq`).

So `jq` is **not strictly required**, but installing it is recommended for clearer output and stricter checks when you run `make health`.

### Install

| Platform | Command |
| -------- | ------- |
| Debian / Ubuntu | `sudo apt update && sudo apt install jq` |
| Fedora | `sudo dnf install jq` |
| macOS (Homebrew) | `brew install jq` |
| Windows | Use [official binaries](https://jqlang.org/download/) or install via [Chocolatey](https://community.chocolatey.org/packages/jq) / [Scoop](https://scoop.sh/#/apps?q=jq) |

Verify: `jq --version`.
