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
- [ ] The **standalone Bank Accounts UI** (`/backoffice/bank-accounts` list +
      `/backoffice/bank-accounts/{id}` detail) surfaces the **full canonical IBAN**
      (PII) sourced from `GET /api/v1/backoffice/bank-accounts` (global list) and
      `GET /api/v1/backoffice/bank-accounts/{id}` (detail) — both **consciously public**
      like the sibling nested route (the API has no auth layer yet). Mitigations already
      in place: the IBAN is **masked at the presentation edge** (`maskIban` / `IbanCell`),
      the backend never masks; and the realtime channel keeping the list/detail live is
      **PII-free** — the Mercure broadcast carries only `{ type, id, bankId }` and drives
      a refetch, never the IBAN (see the *Realtime wire contract* section of
      [`docs/architecture/event-catalog.md`](docs/architecture/event-catalog.md)). Same
      two invariants as the nested route: the IBAN value is **never logged**, and these
      are **not** public-by-design endpoints — **route-level RBAC gating is required
      before production**, requiring authentication when the API firewall lands (a
      pre-prod follow-up under the same auth rollout,
      [#240](https://github.com/sergio-salcedo-dev/ERPify/issues/240) / #222).
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
      withheld) and rendered as **escaped text** in the investigation UI, never
      `dangerouslySetInnerHTML`; never use in a trust or authorization decision.
      **Both audit read routes** — `GET /audit/timeline` and
      `GET /audit/events/{id}` (the #377 investigation surface) — are **RBAC-restricted**
      via `#[IsGranted('auditTrail.read')]`: an under-privileged caller gets a **403
      `forbidden`**, an anonymous one a **401 `unauthenticated`**, both through the RFC
      9457 pipeline. Every **authorized** read **self-audits** a durable `security`
      `AUDIT_TRAIL_READ` row written before the response is sent — the auditor is audited
      (ISO 27001:2022 base: A.5.18 restricted access rights, A.8.15 append-only +
      restricted access + logging of access to logs, A.8.17 clock-synced `occurred_on`).
      **`ADMIN` deliberately holds `auditTrail.read`** beside `AUDIT_READER`, as a declared
      policy row with a recorded justification and revisit trigger
      (`docs/adr/authorization-model-boundaries.md` D3) — **not** as an unexamined default,
      which is the finding an assessor raises. The trail is **append-only by construction
      and access-restricted, but NOT tamper-evident**: no hash chain, signature or checksum
      column exists, so never assert integrity beyond what the mutation paths give. Note the
      **five-year floor covers `change` rows only**; `security` rows (access, denials, the
      GDPR and role-change records) carry a 365-day privacy *ceiling* and are pruned — do
      not cite the floor as a retention guarantee over access evidence.
      Because the operating role can read the record that audits it, the record's
      attribution is guarded on the write side: **erasure refuses any subject still
      carrying `ADMIN`** (409 `administrator-erasure-requires-demotion`), so an
      administrator cannot pseudonymise a peer's attribution without first demoting them.
      That subsumes the ≥1-active-admin invariant on the erasure path; the invariant still
      binds on role and status transitions. **The demotion is itself recorded** —
      `ChangeUserRoles` writes an explicit `USER_ROLES_CHANGED` `security` row with the
      subject and both role sets, because `User` deliberately does **not** implement
      `AuditedEntity` (a field-level diff would carry `password_hash` into the trail) and
      the generic access hook audits only `GET`. Without that row the refusal would be
      procedure without evidence; **never satisfy it by marking `User` as audited**.
      Known gap: the **sole** active administrator can be neither demoted nor erased, so
      their erasure requires onboarding a second administrator first (pre-existing).
      The writer parameterises every value (no string-interpolated SQL). **GDPR erasure is
      implemented** as an in-place, irreversible anonymisation: `audit:gdpr:erase
      <actor-id>` overwrites the subject's `actor_id` with a single fresh random UUID
      and redacts `ip` / `user_agent` to `[REDACTED]` and sets the materialised,
      queryable, non-PII `actor_erased` flag in one `UPDATE`, never deleting a row, and
      self-audits a `security` `GDPR_ERASURE_EXECUTED` entry carrying only the resulting
      pseudonym (never the original id). **Subject erasure is distinct** (never merged —
      ADR D15): `bank-account:gdpr:erase-subject <id>` removes the live account and
      destroys its DEK, so the PII in the append-only trail becomes permanently unreadable
      while the rows survive; it self-audits `GDPR_SUBJECT_ERASED`. **The erasure is not
      defeated by an in-flight write:** `activity` entries are written synchronously (ADR
      D3.1), so audit PII never sits queued in `messenger_messages` (nor in the shared
      `failed` transport) where a later consume could re-insert an already-anonymised
      `actor_id` after the erasure `UPDATE` (issue #376). The only residual is the
      request-duration window a request already in flight shares with `security`/`change`.
      Retention by level
      (`activity` vs `security`, the scheduled prune — the table's only `DELETE`) is
      tracked separately; the `change` level carries a 5-year floor. A live PII table must
      never exist without a documented retention/erasure policy.
- [ ] `identity_user` stores a **credential** (`password_hash`) and **PII** (`email`). The
      `password_hash` is never logged, returned, serialized, or audited: `User` deliberately
      does **not** implement `AuditedEntity`, so it stays out of the `onFlush` change diff (a
      credential leak), and the domain VO `HashedPassword` is opaque to the algorithm —
      hashing lives in Infrastructure. `User` is **hard-deleted** (no soft delete), keeping
      GDPR erasure of the email satisfiable. Hashing lives in the Infrastructure `PasswordHasher`
      adapter (used by the `organization:administrator:create` CLI that bootstraps the first admin);
      the plaintext is never printed or logged, and credentials are never seeded through migrations
      (dev/test use a fixture with a bcrypt hash).
- [ ] **Single-use tokens (`Shared/Token/SingleUseToken`):** the one building block invitation and
      password reset share, so their token security cannot diverge. High-entropy (256-bit CSPRNG),
      **hashed at rest** (SHA-256 — the raw token is handed to the bearer once and **never** persisted or
      logged), TTL-bound, and **verified in constant time** (`\hash_equals`, no short-circuit on the
      secret). A fast hash (not a slow KDF) is correct precisely because the token is already
      uniformly random. Verification is **opaque**: a used, expired, or non-matching token all fail as
      the same plain `false` — the cause is never revealed. **Its HTTP consumers are the invitation accept and
      the password reset** (below).
- [ ] **Invitation accept (`POST /api/v1/backoffice/invitations/accept`):** the first public write of the
      invitation flow — a `PUBLIC_ACCESS` route **inside** the `main` firewall (so `Security::login` reuses the
      native anti-fixation `migrate(true)` + session minting; a half-accepted invitation never yields a session
      because minting runs only **after** the retire-then-act transaction commits). The opaque
      `<invitationId>.<secret>` token is **never rendered, logged, or persisted raw** — only its SHA-256 digest is
      stored (`iam_invitation.token_hash`). Every dead-token case (used, revoked, expired, already-accepted,
      non-existent) collapses to one **byte-identical `400 invalid-token`** (SI-13 opacity); the invited email is
      never surfaced. **CSRF is defence-in-depth, not the primary control:** the primary same-origin gate is
      `AcceptInvitationOriginListener` (403, mirror of the login guard) plus the opaque single-use token; the
      **stateless CSRF token** (`framework.csrf_protection.stateless_token_ids: [invitation_accept]` +
      `#[IsCsrfTokenValid]`, session-free) is the second layer, with `check_header` off/deferred. Be precise
      about what that token proves: `SameOriginCsrfTokenManager::isTokenValid()` length-checks the value
      (>= 24) and then asks `isValidOrigin()`, which **returns on `Sec-Fetch-Site` alone when the browser sent
      it** and falls back to comparing `Origin`/`Referer` only when it did not; the alternative path is a
      double-submit cookie. The client mints a fresh nonce per request and sets no cookie, so the
      double-submit half never engages — validity rests on the same-origin check. It is a token in the
      sense that its **presence** is required, not one whose value is verified.
      The token is read from the **`X-CSRF-Token` header**, not the request body
      (`tokenSource: SOURCE_HEADER`), for two reasons: the body is the application contract, which
      `#[StrictRequestPayload]` enforces by rejecting undeclared members; and a missing header makes
      `getTokenValue()` return `null` → `InvalidCsrfTokenException`, **before** any origin reasoning.
      That is fail-fast ordering, **not** a barrier independent of `Origin`/`Referer`: the header requirement
      only rejects a same-origin request that omits it, a cross-origin one is already refused by the origin
      listener, and a caller able to forge `Origin` can set a custom header just as easily. The CSRF token
      never admits a request on its own, so it is never grounds to relax the origin guard.
      CORS must echo the header (`nelmio_cors.allow_headers`) or a cross-origin preflight blocks the call
      before it is sent; the browser default is same-origin, which needs no preflight.
      **Naming foot-gun:** `tokenKey: 'X-CSRF-Token'` (where `#[IsCsrfTokenValid]` reads the submitted token)
      is a different axis from `check_header`, which governs the *cookie* half and reads a header named after
      `cookie_name` (Symfony default `csrf-token`). Turning `check_header` on would have Symfony look for
      `csrf-token`, **not** our `X-CSRF-Token` — two similar-looking headers with unrelated jobs.
      Password hashing is Infrastructure (the DTO enforces the 8..128 policy at the boundary) and is **deferred
      behind the token check**: a dead accept link never pays an argon2id run (no unauthenticated KDF
      amplification). The accept is capped **per selector** (`token_action_per_selector` limiter); exhaustion
      folds into the same opaque `invalid-token`, never a per-selector 429.
- [x] **Every payload-mapping endpoint accepts JSON only.** All eleven `#[StrictRequestPayload]` sites
      declare `acceptFormat: ['json']`, so a form-encoded or `multipart/form-data` body is refused with
      415 at the argument resolver, before any controller or handler runs. Uniformity is the control:
      the API declares no `#[MapUploadedFile]` argument anywhere, and a route that silently accepted a
      multipart body would be the seam through which a file part could re-enter. Verify with
      `git grep -n '#\[StrictRequestPayload' -- api/src` — every attribute site must carry the format
      list. Gate: `BankCreateAcceptsJsonOnlyFunctionalTest`.
- [ ] **Password reset (`POST /api/v1/backoffice/forgot-password` · `/reset-password`):** the credential-recovery
      surface, mirroring the invitation flow. Forgot answers a **uniform 202** for every email/identity state
      (only an `ACTIVE` identity mints a token, and that work is never observable to the anonymous requester) — no
      account enumeration (SI-12). The reset link is a selector-verifier `<id>.<secret>`: only the SHA-256 digest
      is stored (`identity_password_reset_token.token_hash`), the raw token is **never rendered, logged, or
      persisted**. Every dead-token case (used, expired, unknown, malformed) collapses to one **byte-identical
      `400 invalid-token`** (SI-13, cross-surface opacity with the invitation link — a distinct exception class
      per context, one wire type). A successful reset **consumes the token atomically** (a conditional delete
      whose affected-row count is the single-use guard, so a concurrent replay collapses to `invalid-token`), sets
      the credential, clears the lockout, and **revokes every session** (best-effort teardown; a store outage is
      swallowed because the credential change de-authenticates natively). A non-`ACTIVE` identity hits the
      post-identity wall (403). Same-origin is guarded by `PasswordResetOriginListener` (mirror of the login
      guard); session mint (auto-login + `migrate(true)` regeneration) and the stateless CSRF token id share the
      invitation wiring. The user row is re-read **under a pessimistic lock** inside the completing transaction:
      concurrent forgots serialise their supersede (only the latest token lives) and an admin suspension committed
      between load and commit still walls the reset (TOCTOU re-check).
- [ ] **Pre-identity cross-cutting hardening (login · invitation accept · forgot/reset):**
      every pre-identity rejection pays the same **constant-time floor** (`PreIdentityTimingFloor`, one password
      verification of the firewall's own hasher) — malformed/unknown login identifiers, the `INVITED` pre-auth
      rejection and **every** forgot outcome, so latency correlates with nothing (SI-12). A dead reset/accept
      link never runs the KDF (hashing is deferred until the token proves live). Token hygiene (SI-13):
      `Referrer-Policy: no-referrer` on `/accept-invitation` + `/reset-password`, the client strips `?token=`
      from the URL/history on mount, and Caddy's access log **redacts the `token` query parameter** (gate:
      `CaddyfileAccessLogRedactionGateTest`). Recovery rate limits are **neutral per target**: forgot is capped
      per email (`password_recovery_per_email`) and a saturated target still gets the uniform 202 with the work
      silenced (plus the timing floor); token endpoints are capped per selector; only IP-global limits may 429.
      Security emails come from `MAILER_SECURITY_FROM` (monitored, replyable — a blank/no-reply value **fails
      loudly** outside dev/test) and refuse to emit a non-HTTPS link outside dev/test; token-bearing emails stay
      **synchronous best-effort** (never routed through a Messenger transport, which would serialise the raw
      token); only the token-free password-changed notification rides the async reactor. Retention: expired
      reset tokens are swept by `identity:password-reset-tokens:prune` (schedule it in prod cron). **GDPR
      identity erasure** runs through `FulfilIdentityErasure` (chained + atomic): it hard-deletes the identity
      plus its reset tokens (`GDPR_SUBJECT_ERASED`), anonymises every audit row the subject authored, hard-deletes
      its `iam_session` rows (no residual `ip`/`device` PII) and self-audits `GDPR_ERASURE_EXECUTED` — all in one
      transaction, rolled back as a unit on any failure. It is reachable both by the `identity:gdpr:erase-subject`
      CLI and, new here, by the **ADMIN-only `DELETE /api/v1/backoffice/users/{id}`** (gated `#[IsGranted('users.erase')]`,
      route-id `Uuid::ensure`'d, all errors through the RFC 9457 pipeline — never a manual body; a 204 carries no
      body to leak). The console surfaces it as a **type-to-confirm** destructive action (the admin retypes the
      subject's email); the redirect is `safeHref`-wrapped and no PII/JWT is written to client storage. The write
      keeps ≥1 active administrator (409) and refuses self-erasure (409 `self-erasure-forbidden`).
- [ ] **Session firewall (`security.yaml`, `main`):** `json_login` over an **httpOnly** session cookie
      (`SameSite=Lax`, `Secure=auto`) — no JWT/token in the client. A failed login flows through the RFC 9457
      pipeline as a **401 `unauthenticated`** (never a manual `JsonResponse`); the message is **normalised to a
      single "Invalid credentials."** so "unknown email" and "wrong password" are indistinguishable — no user
      enumeration (`hide_user_not_found` stays on). **Login CSRF (forced login)** is closed by a **same-origin
      `Origin` guard** on the login POST (`LoginOriginListener`, throws `403 forbidden` through the RFC 9457
      pipeline): `json_login` fires on the route's `_format: json` default, **not** the `Content-Type`, so a
      cross-site `text/plain` form with a JSON body would otherwise reach it as a CORS simple request — neither
      `SameSite=Lax` nor the non-broadened CORS policy stops forced login (they gate reading the response, not
      sending the request). `json_login` validates no CSRF token; the **stateless double-submit CSRF token is now
      wired** (its first consumer is the invitation accept POST above), configured session-free via
      `framework.csrf_protection.stateless_token_ids`. CORS / Mercure are **not** broadened. **Access-control baseline:** `access_control` is **default-deny** — every `/api`
      route requires an authenticated session except an explicit allowlist (login, the two public recovery routes
      `forgot-password` / `reset-password` — same-origin-guarded by `PasswordResetOriginListener`, a mirror of
      `LoginOriginListener` keyed on the reset route names — health probes, dev hot-reload). An
      unauthenticated request to a protected route is a **401 `unauthenticated`** through the pipeline:
      `UnauthenticatedAccessListener` rewrites the firewall's `AccessDeniedException` to an `AuthenticationException` for
      anonymous callers (so 401, not 403), while an authenticated-but-under-privileged caller still gets 403 — the shape
      the audit read routes' `#[IsGranted('auditTrail.read')]` relies on. **The `Bank` routes are now
      permission-gated:** every `Bank` controller carries `#[IsGranted('bank.{read,write,delete}')]` (`read`
      auto-granted from the `VIEWER` tier up, `write` from `EDITOR`, `delete` from `MANAGER`), decided by the
      same `PermissionVoter` — so a role-less authenticated caller gets **403 `forbidden`** and an anonymous one
      **401 `unauthenticated`**, retiring the authenticate-only catch-all as their sole gate (a bank is never
      closed, only deleted, so there is no `bank.close`). **Deploy backfill (a runbook, not a schema change):**
      assign a tier role to any pre-existing **non-`ADMIN`** principal *before* the gate ships, or it loses bank
      access it held under the catch-all; greenfield today means only the bootstrap `ADMIN` (wildcard) exists, so
      no data migration is required. **The `BankAccount` routes are gated the same way:** every `BankAccount`
      controller — including the nested `GET /banks/{id}/accounts`, which the **same** `bankAccount.read`
      guards as the flat collection (a resource is governed, not a route) — carries
      `#[IsGranted('bankAccount.{read,write,delete,changeStatus}')]`. `read`/`write`/`delete` tier as usual;
      `changeStatus` (`PATCH /bank-accounts/{id}/status`) is a domain operation, **not** a tier verb, so it is
      reachable only through an explicit grant to `MANAGER` (and `ADMIN` via the wildcard) — an `EDITOR`
      holding `write` is refused it. The tier backfill above already covers these routes; no extra data
      migration. Sessions use the **native file handler** (single-container only) — a shared
      handler (Postgres/Redis) is a follow-up before horizontal scaling. ADR
      [`docs/adr/auth-rbac-subsystem.md`](docs/adr/auth-rbac-subsystem.md).
- [ ] **Persisted per-identity lockout (`identity_user.failed_attempts` / `locked_until`):** a second line
      behind the ephemeral per-IP+email `login_throttling` (built-in, `max_attempts: 5`). After **10** consecutive
      failed attempts against a **resolved** identity it is locked for **15 minutes** (`checkPostAuth` refuses even
      a proven login → **403 `account-locked`**, minting no session); a successful login or a lapsed window clears
      it. The counter increments only for an email that resolves to a row, so an **unknown email writes and emits
      nothing** — the pre-identity 401 stays indistinguishable, and a wrong password on a locked account still
      returns the uniform 401 (an anonymous caller never sees `locked`). Catches a distributed credential-stuffing
      run that sprays one account from many IPs and so evades the per-IP throttle. The lock is orthogonal to
      `IdentityStatus` (a locked identity stays `ACTIVE`); the lock trip emits a PII-free `UserLocked` domain event.
      A store fault while **recording** a failed attempt is absorbed best-effort, so the failure path stays the
      uniform 401 — never a leaked **500** nor a resolved-vs-unknown status-code oracle (the increment is lost,
      tolerable during a DB incident); a store fault while **clearing** on a successful login re-maps to a
      retryable **503 `service-unavailable`**. An attempt the aggregate ignores (a locked or non-`ACTIVE`
      resolved identity) opens no transaction, so a sustained attack on a locked account costs no per-attempt write.
- [ ] **Server-side session registry & admission gate (`iam_session`):** login mints a `Session` aggregate and the
      **Session Admission Gate** re-reads it on every authenticated `/api` request, so "authenticated" means "has a
      **live, revocable** session", not merely "holds a cookie". The gate is **fail-closed**: a revoked or
      time-expired session → **401 `session-expired`** (re-login), an unreachable store → **503 `service-unavailable`**
      (never a fail-open pass-through). **Sign-out revokes server-side:** `POST /sessions/revoke-current` (this
      device) revokes the current registry row **and** invalidates the native session so the cookie is dropped, and
      `POST /sessions/revoke-others` revokes every other row — so "log out" leaves no resumable session behind on a
      shared machine (the client never relies on merely clearing its own state). `iam_session` stores
      **operational PII** — `ip` (plaintext, short-lived) and
      `device` (**normalised server-side** from the `User-Agent` to a bounded label, **never** the raw client string,
      closing stored-injection + free-text PII). The table is **not** an `AuditedEntity`, so the IP never enters the
      five-year audit trail; lawful basis is **legitimate interest** (account security / session management), not
      consent. **Retention policy:** the native GC prunes the file store, **not** this table — a prune command
      (`REVOKED` older than 30 days; `ACTIVE` whose `expiresAt` is older than 90 days; immediate deletion on subject
      erasure) is follow-up [#468](https://github.com/sergio-salcedo-dev/ERPify/issues/468). Because there is no
      physical FK on `user_id`, a user hard-delete does not cascade — the purge-on-erasure reactor is deferred to
      [#470](https://github.com/sergio-salcedo-dev/ERPify/issues/470) (no user-erasure event exists yet). **Deploy
      note (one-time):** native sessions minted **before** this
      ships carry no `iamSessionId`, so the gate 401s them — a single forced global logout at the II-7 deploy
      (acceptable, named).

## 7. Deploy & verify

- [ ] `make deploy.local` (or `scripts/deploy/deploy-local.sh`) reaches a 200 on
      `https://$SERVER_NAME/api/v1/health`.
- [ ] `docker compose … ps` shows every service healthy under the prod overlay.
- [ ] `make docker.down.clean-volumes` and `db.reset` are **never** run against
      a prod stack.
