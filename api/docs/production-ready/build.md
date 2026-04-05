# Building the Production Image

The `Dockerfile` uses a multi-stage build. The production target is
`frankenphp_prod`, which produces a slim, optimised image with no dev tools,
no Xdebug, and no dev Composer dependencies.

---

## Build Command

Run from the **monorepo root** (where `compose.yaml` and `compose.prod.yaml` live):

```console
docker compose \
  -f compose.yaml \
  -f compose.prod.yaml \
  build --pull --no-cache
```

| Flag | Why |
|---|---|
| `--pull` | Always fetches the latest base image (`dunglas/frankenphp:1-php8.5`) to include upstream security patches |
| `--no-cache` | Prevents Docker from using stale layer caches; required after `Dockerfile` changes (e.g. adding `pdo_pgsql`) |

> [!CAUTION]
>
> Omitting `--no-cache` after a `Dockerfile` change means Docker may serve an
> old layer that does not include your change. Always use it on first deploy and
> after any Dockerfile edit.

---

## What the Production Build Does

The `frankenphp_prod` stage runs the following automatically — you do not need
to trigger any of these manually:

| Step | Command | Effect |
|---|---|---|
| Install prod dependencies | `composer install --no-dev` | Excludes `phpunit`, `behat`, `maker-bundle`, etc. |
| Optimise autoloader | `composer dump-autoload --classmap-authoritative` | Replaces PSR-4 scanning with a static classmap for faster cold starts |
| Bake environment | `composer dump-env prod` | Writes `.env.local.php` — Symfony skips Dotenv entirely at runtime |
| Run post-install scripts | `composer run-script post-install-cmd` | Clears cache, installs assets |
| Strip Xdebug | *(not installed in base stage)* | Xdebug is only added in `frankenphp_dev`; the prod stage inherits from `frankenphp_base` |

---

## PHP Extensions

Extensions installed in the production image (from `Dockerfile`):

- `apcu` — opcode and user-data cache
- `intl` — internationalisation
- `opcache` — PHP opcode cache (production ini enables `preload`)
- `pdo_pgsql` — PostgreSQL database driver
- `zip` — archive support

If you need to add an extension (e.g. `redis`):

```dockerfile
# Dockerfile — frankenphp_base stage
RUN install-php-extensions \
    @composer \
    apcu \
    intl \
    opcache \
    pdo_pgsql \
    redis \   # <-- add here
    zip
```

Then rebuild with `--no-cache`.

---

## Tagging & Registries

To push the image to a container registry before deploying to the server, set
`IMAGES_PREFIX`:

```console
IMAGES_PREFIX=ghcr.io/your-org/erpify- \
docker compose \
  -f compose.yaml \
  -f compose.prod.yaml \
  build --pull --no-cache

docker compose \
  -f compose.yaml \
  -f compose.prod.yaml \
  push
```

On the production server, pull and start:

```console
IMAGES_PREFIX=ghcr.io/your-org/erpify- \
docker compose \
  -f compose.yaml \
  -f compose.prod.yaml \
  up --wait --detach
```
