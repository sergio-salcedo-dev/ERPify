# Deployment Guide

This guide summarises how ERPify is deployed. For the detailed PWA prod playbook, see [`pwa/docs/production-deployment.md`](../pwa/docs/production-deployment.md); for FrankenPHP-specific options see [`api/docs/options.md`](../api/docs/options.md) and [`api/docs/tls.md`](../api/docs/tls.md). To stand up the prod profile locally first, see [`erpify-local-test-deployment.md`](./erpify-local-test-deployment.md); to promote to a public VPS and reach the database remotely, see [`vps-deployment.md`](./vps-deployment.md).

## Infrastructure requirements

- Host with Docker + Docker Compose (v2).
- TLS termination via FrankenPHP/Caddy (dev uses Caddy's local CA; prod uses real certs).
- PostgreSQL (Compose service in this repo).
- Mercure Hub (served at `/.well-known/mercure` on the FrankenPHP origin).
- Outbound SMTP (for async mailer).

## Environments

Switch overlay via `ENV=dev|ci|staging|prod` (default `dev`).

| Env | Compose files |
|---|---|
| `dev` | `compose.yaml` + `compose.dev.yaml` |
| `ci` | `compose.yaml` + overlay chosen by CI |
| `staging` | `compose.yaml` + `compose.prod.yaml` |
| `prod` | `compose.yaml` + `compose.prod.yaml` |

## Prod services

Defined in `compose.prod.yaml` on top of the base stack:

- `php` — FrankenPHP + Symfony API (terminates TLS, reverse-proxies `/` to `pwa:3000`).
- `pwa` — Next.js production (`next start -p 80` inside the container).
- `postgres` — PostgreSQL.
- `messenger_worker` — **separate** Symfony Messenger consumer of the `async` transport (handlers must be idempotent; at-least-once delivery). Safe to scale horizontally (`ENV=prod make docker.up` then `docker compose … up -d --scale messenger_worker=N`): the Doctrine transport's `FOR UPDATE SKIP LOCKED` hands each queued message to exactly one replica.
- `scheduler_worker` — **single-replica** consumer of the `scheduler_maintenance`, `scheduler_audit_maintenance` and `scheduler_identity_maintenance` transports. Nine ticks: the daily `handled_domain_event` prune, the daily 30-day prune of the `failed` transport and the hourly dead-letter check; the `audit_log` retention prune and the crypto-shredding erasure reconciliation, both daily; the daily person-reference reconciliation, stored-identity inspection and retired-session prune, plus the five-minute lockout notice. Symfony Scheduler derives ticks from an in-process clock, so it is isolated here to fire once; on the scaled `messenger_worker` pool it would emit one tick per replica. **Must stay at `replicas: 1`**, and `make php.lint.schedule-consumption` now refuses any root compose file declaring more than one for it (and refuses `compose.prod.yaml` declaring none). That binds the repository, not the host: `docker compose up -d --scale scheduler_worker=2` was measured to start two containers anyway, exit 0 — Compose reads the value as a default, not a ceiling. The lock-based single-pool alternative was declined in #261. Every `#[AsSchedule]` owes its transport a slot in this command *and* in the dev `messenger_worker` one: the transport is created from the attribute and declared in no config, so a schedule nobody consumes ships dead with every check green — `make php.lint.schedule-consumption` is what refuses that.
    - **The scheduler's checkpoints live in PostgreSQL** (`cache.scheduler_checkpoint`, table `cache_items`, created by migration), not in the container's cache directory. That is what makes "daily" mean a day rather than a day *after the last deploy*: the pool the checkpoint used to sit on dies with the container, so each release re-anchored every period at the deploy instant and a release cadence at or below the period starved the tick outright — measured as 0 daily fires in seven days at cadences of 6 h, 12 h and 24 h. Restoring the database therefore restores the tick schedule with it; wiping `cache_items` costs one period of delay per schedule and nothing worse.
- Mailer pipeline (async via Messenger).
- Mercure Hub — behind `/.well-known/mercure` (JWT-signed).

## Required env (prod fails to start without these)

- `APP_SECRET`
- `CADDY_MERCURE_JWT_SECRET`
- `POSTGRES_PASSWORD`

Plus SMTP credentials and the `SERVER_NAME` / `NEXT_PUBLIC_API_BASE_URL`
origin for the deployment host.

`MAILER_SMTP_TIMEOUT` is optional (compose defaults it to `3` on `php`,
`messenger_worker` and `scheduler_worker`) but it is validated: `make
prod.env.check` refuses anything outside `1`–`300` seconds, and anything that
is not a plain number — `10s` is the spelling that otherwise passes every gate
and then fails every send. It bounds a single socket operation, not a whole
send; the arithmetic and the measurement are in
[docs-info/production-deployment.md](../docs-info/production-deployment.md).

The PWA prod image additionally **requires** `NEXT_PUBLIC_SENTRY_DSN` (the
`erpify-pwa-prod` project's DSN — Sentry → Settings → Client Keys (DSN)); the
`pwa` build aborts without it (`compose.prod.yaml` declares it `${VAR:?…}`). It
is **not a secret** — a write-only, browser-embeddable identifier baked into the
client bundle, with errors routed through the same-origin `/monitoring` tunnel
(no CSP `connect-src` widening). Dev uses the `erpify-pwa-dev` DSN, set in
`pwa/.env.local` (empty keeps the SDK inert). Source-map upload is **not** wired
yet, so prod stack traces stay minified (the `SENTRY_AUTH_TOKEN` secret is
deferred — see `_bmad-output/implementation-artifacts/deferred-work.md`).

Secrets are delivered through a **gitignored root `.env.prod.local`** (copy from
[`../.env.prod.example`](../.env.prod.example)), loaded via `--env-file` for
`ENV=prod|staging` (wired in `make/config.mk`). The prod overlay declares each
required secret as `${VAR:?msg}`, so a missing value aborts `docker compose` by
name — never a weak fallback. Validate before deploying:

```bash
make prod.env.check
```

## Observability (Sentry)

Error + performance monitoring for the API. The `SentryBundle` loads in **dev and
prod** (never `test`). It is gated by `SENTRY_DSN`:

- **dev** — empty by default → inert. Set `SENTRY_DSN` in `api/.env.local`
  (gitignored) to watch your own errors while developing; events are tagged
  environment `dev`. Errors only, no performance tracing.
- **prod** (test machine + VPS, identical config) — a real `SENTRY_DSN` is
  **required**, with performance tracing.

The prod vars are wired into the `php` and `messenger_worker` services in
`compose.prod.yaml` (read from `.env.prod.local` via `--env-file`), so a normal
`make deploy.local` carries them to the containers. `SENTRY_DSN` and
`SENTRY_TRACES_SAMPLE_RATE` are **required** — they are in `make prod.env.check`'s
keys and guarded by `${VAR:?}` in `compose.prod.yaml`, so a prod stand-up **aborts
by name** if either is missing.

To stand up a prod host:

1. Provision the org/project and obtain the DSN via the **Sentry MCP**
   (`.mcp.json`, remote OAuth).
2. Set in the host's gitignored `.env.prod.local` (template:
   [`../.env.prod.example`](../.env.prod.example)):
   - `SENTRY_DSN` — the project DSN (**required**).
   - `SENTRY_TRACES_SAMPLE_RATE` — performance sampling, **required**; `0.2` in
     prod, `0` to disable tracing while keeping error capture.
3. `make deploy.local` (first stand-up) or `make docker.up ENV=prod` (restart)
   picks them up; `make prod.env.check` validates them first.

The Sentry `environment` tag is just `APP_ENV` (`dev` / `prod`) — no extra var.

`send_default_pii: false` + the `SentryEventScrubber` `before_send` keep
PII/secrets off events (see [`../PRODUCTION_SECURITY_CHECKLIST.md`](../PRODUCTION_SECURITY_CHECKLIST.md)).

### Performance tracing & the messenger worker (important gotcha)

Sentry performance tracing treats both an **HTTP request** and a **console command
execution** as a *transaction*. That second one is a trap for this stack.

The SentryBundle's `TracingConsoleListener` opens **one** transaction when a console
command starts (`ConsoleCommandEvent`) and closes it on terminate. But the
`messenger_worker` runs:

```
php bin/console messenger:consume async --time-limit=3600 …
```

So with tracing on, that single `messenger:consume` run becomes **one transaction
spanning up to an hour**, which:

- is useless as an APM transaction (it's the whole consume loop, not a unit of work), and
- **accumulates spans in memory for the life of the worker** → memory growth in the
  long-lived FrankenPHP/worker process.

It fires at **any** non-zero sample rate — dev at `1.0` *and* prod at `~0.2` (sampled
20% of the time). The fix is the bundle's native exclusion, set in both `when@dev`
and `when@prod` of [`../api/config/packages/sentry.yaml`](../api/config/packages/sentry.yaml):

```yaml
tracing:
    console:
        excluded_commands:
            - 'messenger:consume'   # matches $command->getName(); worker never opens a transaction
```

This leaves only real HTTP requests traced. **Dev** traces at `traces_sample_rate: 1.0`
(100% — cheap locally, full DB/HTTP waterfall, N+1 visible) opt-in via the
`SENTRY_DSN` in `api/.env.local`; **prod** stays at `~0.2`. If you add a *new*
long-running console command, exclude it here too.

A second, **dev-only** worker gotcha (the same long-lived process, a different
failure mode) is documented separately: the worker can crash on a dev DI
container that the web `php` container recompiled out from under it — see
[`troubleshooting/sentry-messenger-worker-dev-cache-crash.md`](./troubleshooting/sentry-messenger-worker-dev-cache-crash.md).

## Prod hardening (compose.prod.yaml)

- Every service runs `no-new-privileges`, drops all Linux caps and re-adds only
  the minimum, and carries parametrizable CPU/memory ceilings.
- Every service declares `restart: unless-stopped`, **the database included** — and that one is
  not decoration: `depends_on` orders a `compose up`, it has no say in the daemon's own restart
  pass, so a database missing it returns only when an operator runs `make docker.up ENV=prod`,
  while `php`, `pwa` and the workers come back on their own and fail against a database that is
  not there. (Dev inverts this deliberately — see `compose.dev.yaml`.)
- Postgres is on an `internal` `backend` network with **no published host port**.
- `pwa` runs with a read-only root filesystem.
- `php` and `messenger_worker` disable core dumps (`ulimits.core: 0` in the base
  `compose.yaml`, all environments) — a crashing FrankenPHP otherwise dumps a
  ~1 GB core file into `/app/api` (bind-mounted into the repo tree in dev).
- TLS: a non-public host uses `CADDY_SERVER_EXTRA_DIRECTIVES=tls internal`
  (Caddy's own CA); a public domain clears it for automatic ACME — same overlay.

See [`../PRODUCTION_SECURITY_CHECKLIST.md`](../PRODUCTION_SECURITY_CHECKLIST.md).

## Reproducible local prod deploy (`erpify.local`)

To stand the prod profile up on a LAN box or laptop at `https://erpify.local`:

```bash
cp .env.prod.example .env.prod.local   # fill in the CHANGE_ME secrets
make deploy.local                      # preflight → up → migrate → smoke
```

Step-by-step (incl. internal-CA trust and `/etc/hosts`) and VPS promotion:
[`erpify-local-test-deployment.md`](erpify-local-test-deployment.md).

## Deploy process (operator view)

1. Build/push images (base images are **digest-pinned**: `dunglas/frankenphp:1-php8.5`, `debian:13-slim`, `node:24-alpine`; do not unpin).
2. On the host, pull images and run:

   ```bash
   ENV=prod make docker.up
   ```

3. Apply DB migrations:

   ```bash
   ENV=prod make db.migrate
   ```

   **Steps 2 and 3 are a window in which the new code runs against the old schema** — `docker.up` starts the new image before `db.migrate` touches the database, and both `scripts/deploy/deploy-local.sh` and [`vps-deployment.md`](./vps-deployment.md) step 4 use that same order. A migration whose new code stops writing a column still declared `NOT NULL` fails every `INSERT` for the length of that window: `Version20260819091752` drops `membership.roles`, so an invitation or an administrator bootstrap that commits before step 3 lands fails with `null value in column "roles" … violates not-null constraint`.

   **Reversing the order does not close it for a drop, it moves the outage** — migrating while the old image is still serving breaks that image's *reads* instead (§ Rollback), which is worse. A bare column drop has no safe order. Either accept the write-failure window, take the deploy as planned downtime, or split the change across two releases: leave the column with a `DEFAULT` in the release that stops writing it and drop it in the next one. That last option removes the hazard in both directions rather than documenting it.

4. **Provision the organization and its administrators** — first install only; see below.
5. Watch `messenger_worker` logs: `ENV=prod make docker.logs` (filter by service).
6. Run smoke tests per [`pwa/docs/production-deployment.md`](../pwa/docs/production-deployment.md).

### Provisioning administrators (first install)

Migrations create the schema and nothing else: there is no public sign-up, so until these run the
installation has no organization and nobody who can sign in.

```bash
ENV=prod make sf c='organization:provision "<organization display name>"'
ENV=prod make sf c='organization:administrator:create <email>'   # hidden prompt — see below
```

**Omit the password argument.** It is optional precisely so the command can prompt for it hidden; passed
as an argument it is visible in the host's process list and lands in shell history. The plaintext is
hashed in Infrastructure and never printed or logged either way.

**Hand custody over immediately.** Whoever runs the command chooses that first password, so at this
moment the operator — not the customer — holds the customer's administrative credential. The
administrator's first action must be **Account ▸ change password**, which is the first credential the
operator never saw. Treat the bootstrap password as a delivery token, not as a credential: single use,
out of band, replaced on first sign-in.

**Provision a second administrator wherever the organization can name one**, from the signed-in first
administrator (*Users ▸ invite*, role `ADMIN`) or by CLI:

```bash
ENV=prod make sf c='iam:invitation:create <second-email> ADMIN'
```

The invitee sets their own password when accepting, so no operator ever holds their credential. The command
prints nothing but a confirmation when the invitation email got out; add `--show-token` only if you intend to
deliver the link out of band, because the printed value is the whole credential and stdout survives in
scrollback, shell history and the logs of whatever ran the command. A send the mailer refused prints the token
regardless, with a warning saying why — that is the case where out-of-band hand-over is the invitee's only
remaining route.

Why it is worth doing, stated exactly, because the reasons that sound obvious are not the ones that hold:

- **It is the only thing that makes an administrator's own GDPR erasure satisfiable.** Erasure refuses any
  subject still holding `ADMIN`, so it must be demoted first, and demotion is refused while it would leave
  the organization with no active administrator. A sole administrator therefore cannot be erased at all —
  a real obligation the day the installation has a customer.
- **It does not protect against an administrator being locked out.** No transition lets one administrator
  clear another's lockout, and both budgets an attacker spends are keyed per email address, so a second
  administrator multiplies the attacker's cost by two and nothing else. Do not treat this as a mitigation
  for [#602](https://github.com/sergio-salcedo-dev/ERPify/issues/602); see
  [`adr/administrative-recovery-channel.md`](./adr/administrative-recovery-channel.md).

**It is a recommendation and the software does not enforce it** — deliberately. The enforced floor is *at
least one* active administrator. Raising it to two would make erasing an administrator require a **third**,
by the same demotion chain above, which is strictly worse than the gap it would be trying to close.

## CI/CD

- GitHub Actions workflows under `.github/workflows/`:
  - `ci.yml` — lint + test pipeline (runs `make ci.quality` + `make ci.test`).
- Static security analysis (CodeQL): the `codeql.yml` workflow scans the PWA (JavaScript/TypeScript) and the GitHub Actions workflows on push/PR and weekly; results land in **Security ▸ Code scanning** (needs Code Scanning enabled — GitHub Advanced Security on private repos). CodeQL has no PHP analyzer, so scan `api/` locally with `make codeql.run`.
- SuperLinter (container-based): `make super-lint.full` (requires `GITHUB_TOKEN`).
- Dependabot tracks Docker digests at `/api` and `/pwa` and composer/npm dependency updates.

## Rollback

- Images are immutable (digest-pinned). Redeploy the previous image tag.
- Roll back DB changes only if the migration is reversible — otherwise restore from the most recent Postgres backup and replay.
- **Redeploying the previous image does not undo its migrations — `down()` never runs**, so the old code meets the new schema. **Two** shapes break under that, and they break different halves of the application:
  - **A `NOT NULL` column added without a `DEFAULT` breaks the old *writer*.** Every `INSERT` from code that predates the column fails. It needs no rolling deploy and no second replica; one replica rolled back is enough. On `audit_log` the consequence is tiered by how the write is issued — the `change` tier writes inside `onFlush` with no `catch`, so the **business** write fails with the audit write. A `NOT NULL` column therefore keeps a persistent `DEFAULT`; if a `postGenerateSchema` listener is the table's source of truth, declare it there so `make db.diff` stays clean instead of dropping the default to silence the diff. Gate: `MigrationColumnDefaultGateTest` (a closed exemption list of the four migrations that predate it).
  - **A dropped column breaks the old *reader*, which is strictly worse — and no gate sees it.** Doctrine enumerates every mapped column in the entity `SELECT`, so the previous image asks for a column the migration removed and Postgres answers `ERROR: column … does not exist`: the failure is on reads, not confined to write paths, and both halves are individually correct so nothing goes red before the deploy. `Version20260819091752` drops `membership.roles`, and the previous image reads `membership` on **every successful login** — `SessionMintingSuccessListener` (`LoginSuccessEvent`) → `FindUserOrganizationId::of()` → `MembershipRepository::findByUserId()` — where the `catch (Throwable)` invalidates the session and rethrows. Rolling that image back onto the migrated schema is an authentication outage: nobody can log in, including the administrator who would fix it.
- **Rolling back past a column drop means running `down()` first, and no `make` target does it.** `make/db.mk` exposes `db.migrate` and nothing that reverses it. Take the schema back one migration **before** starting the old image:

  ```bash
  ENV=prod make sf c='doctrine:migrations:migrate prev --no-interaction'
  ```

  `down()` restores the column's **shape, not its content** — measured, `migrate prev` exits 0 and `Version20260819091752` re-adds `membership.roles` empty and `NOT NULL` with no default. So the order is `down()` **then** the old image, never the reverse, and the schema it leaves is again one only the old image can write to. That is enough here only because the dropped column had no reader in the previous image either (`Membership::hasRole()`/`roles()` had no caller in `api/src`); it was written and never consulted, which is why it was droppable at all. A drop whose column the old image genuinely read back is not recoverable this way — that one is the backup, not the migration.
- Postgres holds all application state, so a restore is a single artifact: `STAMP=<stamp> make restore.prod` (runbook: [`vps-deployment.md`](./vps-deployment.md) § Backups).

## Operational notes

- `make docker.down.clean-volumes` drops volumes and is **destructive** — never on prod without explicit confirmation. On prod that includes `database_data` (all application state) and `caddy_data` (ACME account key and issued certificates).
- Do not run `db.reset` outside dev/ci.
- DNS, CORS origins, and Mercure cookie/CORS config: see [`pwa/docs/production-deployment.md`](../pwa/docs/production-deployment.md).
- Backups: `make backup.prod` takes a verified Postgres dump; cron, offsite sync, restore and the quarterly drill are in [`vps-deployment.md`](./vps-deployment.md) § Backups.
- Xdebug must be disabled in prod images.
