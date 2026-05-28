# Requirements

Tools you need on your **host** machine to work with this repository (the API itself runs in Docker).

## Required

- **Docker** and **Docker Compose** (v2) — to build and run the Symfony stack (image **`context: ./api`**, Compose files at the repo root). See [api/README.md](../api/README.md) and the root [README](../README.md).
- **GNU Make** — optional but expected if you use the root [`Makefile`](../Makefile) targets (`make docker.up`, `make app.dev`, etc.).

You do **not** need PHP on the host to run the app if you use Docker; use [`api/bin/sf`](../api/bin/sf) or, from the **repository root**, `docker compose -f compose.yaml -f compose.dev.yaml exec php bin/console` when `php` is not on your `PATH`.

## Recommended: `jq`

Install **[jq](https://jqlang.org/)** (a command-line JSON processor).

### Why it matters here

The health endpoint at `https://localhost/api/v1/health` returns a JSON body reporting status (`"status": "ok"`). When you probe it with `curl` — or inspect any API / Problem Details response — `jq` makes the output readable and lets you assert on its structure.

- **With `jq` installed:** pretty-print the response (`curl -sk https://localhost/api/v1/health | jq .`) and validate the payload (`jq -e '.status == "ok"'`). That checks real JSON structure, not just a text pattern.
- **Without `jq`:** you can still read the raw body and `grep` for `"status":"ok"`, but that is more brittle (e.g. unusual spacing or unexpected extra fields are handled less cleanly than with `jq`).

So `jq` is **not strictly required**, but installing it is recommended for clearer output and stricter checks when you inspect API responses.

### Install

| Platform | Command |
| -------- | ------- |
| Debian / Ubuntu | `sudo apt update && sudo apt install jq` |
| Fedora | `sudo dnf install jq` |
| macOS (Homebrew) | `brew install jq` |
| Windows | Use [official binaries](https://jqlang.org/download/) or install via [Chocolatey](https://community.chocolatey.org/packages/jq) / [Scoop](https://scoop.sh/#/apps?q=jq) |

Verify: `jq --version`.
