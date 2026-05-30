# Production Security Checklist

Authoritative pre-production gate for ERPify. [`CLAUDE.md`](CLAUDE.md) cites this
file for every security-sensitive change; keep it in sync with the prod Compose
overlay (`compose.prod.yaml`), the env template (`.env.prod.example`), and the
deploy flow (`scripts/deploy/deploy-local.sh`). Companion runbook for a local
prod box: [`docs/erpify-local-test-deployment.md`](docs/erpify-local-test-deployment.md).

The prod profile is **byte-identical between a LAN test box and a public VPS
except `SERVER_NAME`, origins, and secrets** — verify that property holds when
you change anything here.

## 1. Secrets delivery

- [ ] Real secrets live only in a gitignored root `.env.prod.local` (copied
      from [`.env.prod.example`](.env.prod.example)). Never commit it; never
      paste secrets into `compose.*.yaml` or any tracked file.
- [ ] Compose reads them via `--env-file .env.prod.local` (wired in
      `make/config.mk` for `ENV=prod|staging`). Secrets do **not** flow through
      a service `env_file:` — compose interpolation cannot read that.
- [ ] `make prod.env.check` passes (no missing file, no empty/`CHANGE_ME`
      required key). The deploy script runs this before touching Docker.
- [ ] `APP_SECRET`, `POSTGRES_PASSWORD`, and `CADDY_MERCURE_JWT_SECRET` were
      freshly generated (`openssl rand …`), not reused from dev.
- [ ] `POSTGRES_PASSWORD` is URL-safe — generate with `openssl rand -hex`, not
      `-base64`. It is interpolated raw into `DATABASE_URL`, so `/` `+` `=` from
      base64 corrupt the DSN (`MalformedDsnException`, php boot fails).

## 2. No weak fallbacks

- [ ] Every required prod secret uses `${VAR:?msg}` in `compose.prod.yaml`, so a
      missing value **aborts startup by name** — it never falls back to the
      weak dev defaults (`erpify_password`, `!ChangeThis…!`) that `compose.yaml`
      keeps for `make app.dev`.
- [ ] `docker compose … config` on the prod overlay shows **no** `erpify_password`
      / `!ChangeThis…!` anywhere.

## 3. Container runtime hardening

- [ ] Every service sets `security_opt: [no-new-privileges:true]`.
- [ ] Every service drops all caps (`cap_drop: [ALL]`) and re-adds only the
      minimum (`php`: `NET_BIND_SERVICE`; `database`:
      `CHOWN,DAC_OVERRIDE,FOWNER,SETGID,SETUID`; `pwa`/`messenger_worker`: none).
      Widening beyond these is a conscious decision — document why.
- [ ] `pwa` runs `read_only` with a `/tmp` tmpfs. (php/database/worker keep a
      writable root by necessity — `var/`, pgdata.)
- [ ] CPU/memory ceilings are set (`*_CPU_LIMIT` / `*_MEM_LIMIT`), tuned to the
      host.
- [ ] Base images stay digest-pinned (`dunglas/frankenphp`, `postgres`,
      `node`/`debian`). Never unpin.
- [ ] Xdebug is disabled in prod images.

## 4. Network isolation

- [ ] Postgres sits on the `internal` `backend` network with **no published
      host port** — unreachable from host and internet.
- [ ] Only `php` (and the Mercure hub it embeds) is reachable from outside, via
      the `frontend` network and published 80/443.
- [ ] `messenger_worker` has egress (SMTP) via `frontend` and DB access via
      `backend`, but no published port.

## 5. TLS & origins

- [ ] LAN/test box: `CADDY_SERVER_EXTRA_DIRECTIVES=tls internal` — Caddy mints a
      cert from its own CA; clients import the root (see the runbook).
- [ ] Public VPS: `CADDY_SERVER_EXTRA_DIRECTIVES` is **empty** and `SERVER_NAME`
      is the real domain, so Caddy uses automatic ACME — no overlay edits.
- [ ] `NEXT_PUBLIC_SYMFONY_API_BASE_URL`, `DEFAULT_URI`, and `MERCURE_PUBLIC_URL`
      all match the served `https://$SERVER_NAME` origin.

## 6. App-layer (carried from `CLAUDE.md` security review)

- [ ] CORS allowlist not widened to wildcards; Mercure JWT secret rotation
      policy preserved.
- [ ] Error responses follow RFC 9457 with no stack-trace leakage outside dev.
- [ ] Migrations are reversible (`down()`); no PII/secrets seeded; no
      `DROP TABLE` outside an explicit destructive migration.
- [ ] Messenger handlers idempotent (at-least-once delivery).

## 7. Deploy & verify

- [ ] `make deploy.local` (or `scripts/deploy/deploy-local.sh`) reaches a 200 on
      `https://$SERVER_NAME/api/v1/health`.
- [ ] `docker compose … ps` shows every service healthy under the prod overlay.
- [ ] `make docker.down.clean-volumes` and `db.reset` are **never** run against
      a prod stack.
