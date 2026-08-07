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
- `scheduler_worker` — **single-replica** consumer of the `scheduler_maintenance`, `scheduler_audit_maintenance` and `scheduler_identity_maintenance` transports (the daily `handled_domain_event` prune and hourly dead-letter check; the `audit_log` retention prune and crypto-shredding erasure reconciliation; the person-reference reconciliation). Symfony Scheduler derives ticks from an in-process clock, so it is isolated here to fire once; on the scaled `messenger_worker` pool it would emit one tick per replica. **Must stay at `replicas: 1`** — the lock-based single-pool alternative is tracked in #261. Every `#[AsSchedule]` owes its transport a slot in this command *and* in the dev `messenger_worker` one: the transport is created from the attribute and declared in no config, so a schedule nobody consumes ships dead with every check green — `make php.lint.schedule-consumption` is what refuses that.
- Mailer pipeline (async via Messenger).
- Mercure Hub — behind `/.well-known/mercure` (JWT-signed).

## Required env (prod fails to start without these)

- `APP_SECRET`
- `CADDY_MERCURE_JWT_SECRET`
- `POSTGRES_PASSWORD`

Plus SMTP credentials and the `SERVER_NAME` / `NEXT_PUBLIC_API_BASE_URL`
origin for the deployment host.

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

The invitee sets their own password when accepting, so no operator ever holds their credential. Note the
CLI prints the acceptance link in full — deliver it out of band and do not leave it in a shared terminal
([#648](https://github.com/sergio-salcedo-dev/ERPify/issues/648)).

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
- **Redeploying the previous image does not undo its migrations — `down()` never runs.** The old writer therefore meets the new schema, and the one shape that breaks under it is a `NOT NULL` column added **without a `DEFAULT`**: every `INSERT` from code that predates the column fails. It needs no rolling deploy and no second replica; one replica rolled back is enough. On `audit_log` the consequence is tiered by how the write is issued — the `change` tier writes inside `onFlush` with no `catch`, so the **business** write fails with the audit write. A `NOT NULL` column therefore keeps a persistent `DEFAULT`; if a `postGenerateSchema` listener is the table's source of truth, declare it there so `make db.diff` stays clean instead of dropping the default to silence the diff. Gate: `MigrationColumnDefaultGateTest` (a closed exemption list of the four migrations that predate it).
- Postgres holds all application state, so a restore is a single artifact: `STAMP=<stamp> make restore.prod` (runbook: [`vps-deployment.md`](./vps-deployment.md) § Backups).

## Operational notes

- `make docker.down.clean-volumes` drops volumes and is **destructive** — never on prod without explicit confirmation. On prod that includes `database_data` (all application state) and `caddy_data` (ACME account key and issued certificates).
- Do not run `db.reset` outside dev/ci.
- DNS, CORS origins, and Mercure cookie/CORS config: see [`pwa/docs/production-deployment.md`](../pwa/docs/production-deployment.md).
- Backups: `make backup.prod` takes a verified Postgres dump; cron, offsite sync, restore and the quarterly drill are in [`vps-deployment.md`](./vps-deployment.md) § Backups.
- Xdebug must be disabled in prod images.
