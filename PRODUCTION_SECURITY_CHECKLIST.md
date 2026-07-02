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
- [ ] `APP_SECRET`, `POSTGRES_PASSWORD`, and `CADDY_MERCURE_JWT_SECRET` were freshly
      generated (`openssl rand …`), not reused from dev.
- [ ] `AUDIT_KEK` (audit crypto-shredding key-encryption key) is used as **raw 32-byte**
      key material — the app fails fast unless the value is exactly 32 bytes long.
      Generate a printable 32-byte key with `openssl rand -base64 24` (24 random bytes →
      32 base64 chars, no padding); `-hex 32` (64 chars) or `-base64 32` (44 chars)
      produce the wrong length and abort boot. Freshly generated, not reused from dev.
      Custodied **outside the app** (env / secret manager), never beside the DEKs it
      wraps and never committed. Destroying a DEK (subject erasure) is irreversible by
      design. KEK rotation is **not yet automated**: rotating `AUDIT_KEK` in place makes
      every existing DEK unreadable, so a batch re-wrap tool is a prerequisite (not yet
      shipped) — do not rotate the live KEK until it exists. Required in prod: listed in
      `make prod.env.check` and guarded by `${VAR:?}` on every PHP service in
      `compose.prod.yaml`, so a missing value aborts the deploy by name like the other
      prod secrets (otherwise the app boots and fails closed on the first audited write).
- [ ] `POSTGRES_PASSWORD` is URL-safe — generate with `openssl rand -hex`, not
      `-base64`. It is interpolated raw into `DATABASE_URL`, so `/` `+` `=` from
      base64 corrupt the DSN (`MalformedDsnException`, php boot fails).
- [ ] `SENTRY_DSN` (+ `SENTRY_TRACES_SAMPLE_RATE`) is set in `.env.prod.local`,
      never committed — provisioned through the Sentry MCP (`.mcp.json`). Both are
      **required** in prod (in `make prod.env.check` and guarded by `${VAR:?}` in
      `compose.prod.yaml`): the deploy aborts by name if either is missing, on the
      test machine and the VPS alike.

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
- [ ] `NEXT_PUBLIC_API_BASE_URL`, `DEFAULT_URI`, and `MERCURE_PUBLIC_URL`
      all match the served `https://$SERVER_NAME` origin.

## 6. App-layer (carried from `CLAUDE.md` security review)

- [ ] CORS allowlist not widened to wildcards; Mercure JWT secret rotation
      policy preserved.
- [ ] Error responses follow RFC 9457 with no stack-trace leakage outside dev.
- [ ] Sentry runs with `send_default_pii: false` (no headers/cookies/IP/user by
      default) plus the `SentryEventScrubber` `before_send`, which recursively
      strips the RFC 9457 denylist keys from event `extra`, the `request`
      sub-arrays and `query_string`. Secret-bearing keys outside the denylist,
      breadcrumbs and exception messages are out of that scope.
- [ ] No secret hides behind a `NEXT_PUBLIC_*` name — those are inlined into the
      browser bundle at build time. Only `NEXT_PUBLIC_API_BASE_URL`,
      `NEXT_PUBLIC_APP_ENV`, and `NEXT_PUBLIC_SENTRY_DSN` are allowed; the
      `pwa/tests/next-public-env-allowlist.test.ts` guard (in `make pwa.test.unit`)
      fails the build on any other. The Sentry DSN is intentionally public
      (write-only, browser-embeddable); the real Sentry secret is
      `SENTRY_AUTH_TOKEN` (source-map upload — not used yet, never `NEXT_PUBLIC_`).
- [ ] Sentry events are scrubbed before send: `sendDefaultPii: false` plus a
      `beforeSend` denylist scrub in parity with the API's `SentryEventScrubber`
      (`scrubSentryEvent` / shared `redaction` keys); deliberate `telemetry.*`
      causes pass through `serializeCause` (PII-scrubbed). No replay enabled.
- [ ] The public `/monitoring` Sentry tunnel (`tunnelRoute`, relays anonymous
      browser POSTs to ingest) is rate-limited per client IP in `pwa/src/proxy.ts`
      (`monitoringTunnelRateLimiter`, 60 POSTs / 60s; over-limit → `429` +
      `Retry-After`), so one source can't burn the Sentry quota. The IP is the
      rightmost (Caddy-appended, unspoofable) `X-Forwarded-For` entry; state is
      per-process/in-memory (single PWA instance — revisit for horizontal scale).
      Sentry's server-side limits remain the second line of defence.
- [ ] Migrations are reversible (`down()`); no PII/secrets seeded; no
      `DROP TABLE` outside an explicit destructive migration.
- [ ] Messenger handlers idempotent (at-least-once delivery).
- [ ] Dead-letter tooling is **operator-console only**, not an HTTP surface:
      `messenger:failed:status` reads the `failed` transport and may print stored
      exception messages to the console (no client exposure); the hourly backlog
      alarm (`ReportDeadLetterBacklogHandler`) logs **counts/ages only**, never
      payloads; `event:dedup:clear` deletes a claim via parameterised DBAL
      (`release()`), no string-interpolated SQL. No new auth surface.
- [ ] The health endpoints (`/api/v1/health`, `/api/v1/backoffice/health`) are
      **consciously public and liveness-only**: static payload (status, service
      name, server time) with no DB / Mercure / Messenger probing, no PII, no
      versions. Anonymous access is required by the §7 smoke test and the PWA
      dashboard health check. Two invariants: any *deep* health check
      (dependency status) must be authenticated or internal-only — never on
      these routes — and when an API firewall lands, these two paths need an
      explicit `PUBLIC_ACCESS` exemption. Tracked in
      [#222](https://github.com/sergio-salcedo-dev/ERPify/issues/222).
- [ ] `GET /api/v1/backoffice/banks/{id}/accounts` returns the **full canonical
      IBAN** (PII) and is **consciously public** like the rest of `/backoffice`
      (the API has no auth layer yet). The masking is presentational on the PWA
      only — the backend never masks. Two invariants: the IBAN value is **never
      logged** (the access-audit message carries only `bankId` + timestamp), and
      when the API firewall lands this route must require authentication (it is
      **not** a public-by-design endpoint, unlike health). Tracked in
      [#240](https://github.com/sergio-salcedo-dev/ERPify/issues/240) (auth rollout,
      sibling of the health exemption #222).
- [ ] `audit_log` (raw-DBAL append-only table) **contains live PII**: `actor_id`,
      `ip`, `user_agent`. Capture is now wired: generic `/api` navigation on
      `kernel.terminate` (→ `activity`) and permission denials on `kernel.exception`
      (→ `security`), with `ip` (`Request::getClientIp()`, trusted-proxy aware so a
      spoofed `X-Forwarded-For` cannot forge it) and `user_agent` (trimmed to the
      column width) sealed onto every entry. **Write capture is also wired:** every
      create/update/delete of an aggregate marked `AuditedEntity` records a `change`
      row with a field-level before/after diff, written synchronously inside the
      business transaction so the row is atomic with the write (out-of-band flushes —
      fixtures, seeds — are not captured). `Bank` (a catalog) is audited in clear; a
      **PII-bearing diff is crypto-shredded**: `BankAccount`'s `#[PersonalData]` fields
      (`holderName`/`iban`) are AEAD-encrypted (libsodium) under a per-subject DEK before
      the row is written (A.5.12 classification), so no personal data is ever stored in
      clear; the row references its `encryption_scope_id` and the read UI shows a sealed
      sentinel, never the ciphertext. Its `ip` / `user_agent` / `metadata` are
      **client-controlled (tainted)** — the `change` diff is surfaced read-only via
      the canonical `GET /audit/events/{id}` resource (diff-only: `ip`/`user_agent`
      withheld; consciously public like the rest of `/backoffice` until the auth gate)
      and rendered as **escaped text** in the investigation UI, never
      `dangerouslySetInnerHTML`; never use in a trust or authorization decision (ISO
      27001:2022 base: A.8.15 append-only + restricted access, A.8.17 clock-synced
      `occurred_on`); the writer
      parameterises every value (no string-interpolated SQL). **GDPR erasure is
      implemented** as an in-place, irreversible anonymisation: `audit:gdpr:erase
      <actor-id>` overwrites the subject's `actor_id` with a single fresh random UUID
      and redacts `ip` / `user_agent` to `[REDACTED]` and sets the materialised,
      queryable, non-PII `actor_erased` flag in one `UPDATE`, never deleting a row, and
      self-audits a `security` `GDPR_ERASURE_EXECUTED` entry carrying only the resulting
      pseudonym (never the original id). **Subject erasure is distinct** (never merged —
      ADR D15): `bank-account:gdpr:erase-subject <id>` removes the live account and
      destroys its DEK, so the PII in the append-only trail becomes permanently unreadable
      while the rows survive; it self-audits `GDPR_SUBJECT_ERASED`. Retention by level
      (`activity` vs `security`, the scheduled prune — the table's only `DELETE`) is
      tracked separately; the `change` level carries a 5-year floor. A live PII table must
      never exist without a documented retention/erasure policy.
- [ ] `identity_user` stores a **credential** (`password_hash`) and **PII** (`email`). The
      `password_hash` is never logged, returned, serialized, or audited: `User` deliberately
      does **not** implement `AuditedEntity`, so it stays out of the `onFlush` change diff (a
      credential leak), and the domain VO `HashedPassword` is opaque to the algorithm —
      hashing lives in Infrastructure. `User` is **hard-deleted** (no soft delete), keeping
      GDPR erasure of the email satisfiable. Hashing lives in the Infrastructure `PasswordHasher`
      adapter (used by the `identity:user:create` CLI); the plaintext is never printed or logged,
      and credentials are never seeded through migrations (dev/test use a fixture with a bcrypt hash).
- [ ] **Session firewall (`security.yaml`, `main`):** `json_login` over an **httpOnly** session cookie
      (`SameSite=Lax`, `Secure=auto`) — no JWT/token in the client. A failed login flows through the RFC 9457
      pipeline as a **401 `unauthenticated`** (never a manual `JsonResponse`); the message is **normalised to a
      single "Invalid credentials."** so "unknown email" and "wrong password" are indistinguishable — no user
      enumeration (`hide_user_not_found` stays on). **Login CSRF** is covered by the same-origin deployment + the
      **non-broadened CORS** policy + `json_login`'s **`application/json` requirement** (a cross-site form cannot
      send `application/json` without a CORS preflight the policy denies) + `SameSite=Lax`. `json_login`
      validates no CSRF token, so **no** stateless-token CSRF is configured — it is wired with the first
      authenticated **mutating** route that can consume it (wire-on-consumer). CORS / Mercure are **not** broadened. **Not yet gated:** there is **no `access_control`** — default-deny + the 401 on
      protected routes land in AF-1.3, and `#[IsGranted]` on the audit read routes lands in Epic 3, so those
      routes stay public until then. Sessions use the **native file handler** (single-container only) — a shared
      handler (Postgres/Redis) is a follow-up before horizontal scaling. ADR
      [`docs/adr/auth-rbac-subsystem.md`](docs/adr/auth-rbac-subsystem.md).

## 7. Deploy & verify

- [ ] `make deploy.local` (or `scripts/deploy/deploy-local.sh`) reaches a 200 on
      `https://$SERVER_NAME/api/v1/health`.
- [ ] `docker compose … ps` shows every service healthy under the prod overlay.
- [ ] `make docker.down.clean-volumes` and `db.reset` are **never** run against
      a prod stack.
