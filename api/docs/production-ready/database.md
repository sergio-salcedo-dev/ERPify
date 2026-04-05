# Database Configuration, Migrations & Fixtures

ERPify uses PostgreSQL 18 managed by Docker Compose. This document covers
credentials, data persistence, backups, port security, and how Doctrine
migrations and fixtures behave in production.

---

## Credentials

`compose.yaml` builds `DATABASE_URL` dynamically from four environment variables,
so you never have to keep the two in sync by hand.

### Development / test defaults

The repository ships with safe-for-local-use defaults that take effect when no
override is provided:

| Variable | Default |
|---|---|
| `POSTGRES_USER` | `erpify_user` |
| `POSTGRES_PASSWORD` | `erpify_password` |
| `POSTGRES_DB` | `erpify_db` |
| `POSTGRES_VERSION` | `18` |
| `POSTGRES_PORT` | `15432` (host-side only) |

These values appear in:
- `api/.env` → `DATABASE_URL` used by Symfony
- Root `compose.yaml` → `POSTGRES_*` env on the `database` service and the `DATABASE_URL`
  env on the `php` service

The database is exposed on host port **15432** so you can connect from a local
client (e.g. DBeaver, TablePlus, `psql`) without touching the container:

```console
psql "postgresql://erpify_user:erpify_password@localhost:15432/erpify_db"
```

> [!NOTE]
>
> Inside the Docker network the `php` container always connects on the standard
> port `5432`. The `15432` mapping is **host-side only** and has no effect on
> the application.

### Production credentials

**Never use the development defaults in production.**
Set the following in `api/.env.prod.local` on the server (see [secrets.md](secrets.md)):

```dotenv
# api/.env.prod.local — on the server only, never committed to git
POSTGRES_USER=erpify_prod
POSTGRES_PASSWORD=<strong-random-password>
POSTGRES_DB=erpify_prod
POSTGRES_VERSION=18
```

`compose.yaml` will automatically assemble `DATABASE_URL` inside the `php`
container from these values:

```
postgresql://erpify_prod:<password>@database:5432/erpify_prod?serverVersion=18&charset=utf8
```

Generate a safe password (alphanumeric only — avoids URL-encoding issues):

```console
openssl rand -base64 24 | tr -d '+/='
```

> [!CAUTION]
>
> Do not use special characters (`@`, `#`, `/`, `?`) in `POSTGRES_PASSWORD`.
> They would break URL parsing in `DATABASE_URL`.

> [!IMPORTANT]
>
> Do **not** publish port `15432` (or any database port) in production.
> Remove or omit the `ports:` block under `database` in `compose.prod.yaml`.
> PostgreSQL must only be reachable from within the Docker internal network.

---

## Data Persistence

All PostgreSQL data is stored in the `database_data` Docker named volume.
Confirm it exists before and after every deployment:

```console
docker volume ls | grep database_data
```

> [!WARNING]
>
> Running `docker compose down --volumes` or `make clean` **permanently
> destroys** the `database_data` volume and all data in it.
> Never run these commands on a production server.

---

## Backups

Set up automated backups **before** going live. A minimal approach using
`pg_dump` inside the running container:

```console
docker compose -f compose.yaml -f compose.prod.yaml exec database \
  pg_dump \
    --username="${POSTGRES_USER}" \
    --format=custom \
    "${POSTGRES_DB}" > backup-$(date +%Y%m%dT%H%M%S).pgdump
```

**Recommended setup:**

- Schedule with `cron` (e.g. daily at 03:00).
- Ship the file to off-site storage (S3, GCS, Backblaze B2, etc.).
- Test restores regularly — a backup you have never restored is not a backup.

Restore from a `.pgdump` file:

```console
docker compose -f compose.yaml -f compose.prod.yaml exec -T database \
  pg_restore \
    --username="${POSTGRES_USER}" \
    --dbname="${POSTGRES_DB}" \
    --clean \
    < backup-<timestamp>.pgdump
```

---

## Never Expose the Database Port

The `database` service in `compose.yaml` does **not** publish any port to the
host — PostgreSQL is only reachable from within the Docker internal network
by the `php` container. Keep it this way.

If you need direct one-off access (e.g. to inspect data), use:

```console
docker compose -f compose.yaml -f compose.prod.yaml \
  exec database psql --username="${POSTGRES_USER}" "${POSTGRES_DB}"
```

Never add a `ports:` block under `database` in production.

---

## Migrations

Migration files live under `migrations/<year>/` and follow the naming
convention `Version<timestamp>.php`. They are included in the production image
at build time.

### Automatic migrations on startup

`frankenphp/docker-entrypoint.sh` runs the following on every container start
when `DATABASE_URL` is set:

```bash
php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
```

The `--all-or-nothing` flag wraps all pending migrations in a single
transaction: if any migration fails, the whole set is rolled back and the
container exits with an error — preventing a half-migrated schema.

### Manual migrations (staged deploy)

If you prefer to run migrations manually before bringing the new container up:

```console
# 1. Run migrations against the live database
docker compose -f compose.yaml -f compose.prod.yaml \
  exec php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing

# 2. Then restart the app
docker compose -f compose.yaml -f compose.prod.yaml up --wait --detach
```

> [!IMPORTANT]
>
> Always back up the database before deploying a version that includes schema
> changes. See [Backups](#backups) above.

### Adding a new migration

Migrations are generated in development and committed to git. The workflow is:

```console
# In the dev container
php bin/console doctrine:migrations:diff   # generates migrations/<year>/Version*.php
```

Review the generated SQL, commit it, and the next production deploy will pick
it up automatically.

---

## Fixtures

`DoctrineFixturesBundle` is registered only for the `dev` and `test`
environments in `config/bundles.php`:

```php
Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],
```

This means `doctrine:fixtures:load` is **not available** in `prod` — the
command simply does not exist. Do not change this registration to `all`.

> [!CAUTION]
>
> Never run `doctrine:fixtures:load` against the production database under any
> circumstances. Fixtures are seeding tools for development and testing only;
> loading them in production will wipe or pollute real data.
