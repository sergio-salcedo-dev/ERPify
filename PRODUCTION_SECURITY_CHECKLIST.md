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
      versions. Each carries its own `$`-anchored `PUBLIC_ACCESS` entry in
      `api/config/packages/security.yaml`, and **the anchoring is the control**: a
      pattern spanning the prefix exempts every route nested under it, which is how
      the database probe came to be anonymous. The two exemptions rest on different
      consumers and must be reasoned about separately: `/api/v1/health` is reached
      anonymously by the §8 smoke test **and by the public `/status` page**, which
      mounts outside `RequireAuth` — retiring that exemption bounces an anonymous
      visitor to `/login`, since the PWA's HTTP client treats a 401 outside the auth
      handshake as an expired session. `/api/v1/backoffice/health` has no anonymous
      consumer in the PWA — its page mounts behind `RequireAuth` — but it does have
      one **outside** it: the deploy runbooks curl it unauthenticated
      (`api/docs/production-ready/hardening.md`, `server-setup.md`), and an operator
      holding a shell holds no session cookie. Closing it is therefore a change to a
      documented operational procedure, not a config edit. Two
      invariants: **any deep health check is authenticated** — the dependency probe
      `/api/v1/backoffice/health/database` falls through to
      `IS_AUTHENTICATED_FULLY`, pinned by an `@anonymous` 401 scenario in
      `api/features/backoffice/health/database.feature` — and no liveness route
      ever grows dependency status. Tracked in
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
      `ChangeUserRoles` writes an explicit `USER_ROLES_CHANGED` `security` row naming the
      subject in the resource columns and carrying both role sets, because `User`
      deliberately does not implement `AuditedEntity` (a field-level diff would carry
      `password_hash` into the trail) and the generic hook audits only `GET`. Without that
      row the refusal would be procedure without evidence; **never** satisfy it by marking
      `User` as audited. Naming the subject in `resource_id` is safe only because erasure
      anonymises **both** axes in one transaction — the resource axis alongside `actor_id`
      — so a self-demotion no longer co-locates a real id with its own pseudonym. That
      property is load-bearing: if a future row names a person in `resource_id` whose type
      has no declared eraser, it reintroduces the crosswalk.
      Known gap: the **sole** active administrator can be neither demoted nor erased, so
      their erasure requires onboarding a second administrator first (pre-existing).
      The writer parameterises every value (no string-interpolated SQL). **GDPR erasure is
      implemented** as an in-place, irreversible anonymisation: `audit:gdpr:erase
      <actor-id>` overwrites the subject's `actor_id` with a single fresh random UUID
      and redacts `ip` / `user_agent` to `[REDACTED]` and sets the materialised,
      queryable, non-PII `actor_erased` flag in one `UPDATE`, never deleting a row, and
      self-audits a `security` `GDPR_ERASURE_EXECUTED` entry carrying only the resulting
      pseudonym (never the original id). **That command is not the sentinel's only writer.**
      The resource-axis pass writes the same `[REDACTED]` over `ip` / `user_agent`, but
      only where `actor_type = anonymous` — a row an unauthenticated self-service path
      (failed login, throttled recovery) wrote about the subject. There the row records
      **no discriminant for whose address it holds**: it may be the subject triggering
      their own lockout or recovery, and once `resource_erased` is raised no detective
      control can ever surface it, so the erasure is the only chance. No discriminant is
      sealed at write time today, and minting one is its own change with its own cost: the
      requester is unauthenticated and supplies only a *claimed* identity, so the only
      candidate is a heuristic (does the address match one the subject has held on an
      `iam_session`?) bought by making the audit writer read a second context's PII at
      capture time. Weighed and not taken — not impossible.
      **The accepted cost, stated rather than hidden:** where the
      requester was a stranger — an attacker locking a victim out — that attacker's address
      is destroyed too. The **fact** survives even though the value does not, though not for
      the reason it first appears: `ip` **is** client-influenced — `getClientIp()` honours
      `X-Forwarded-For` from any hop inside `SYMFONY_TRUSTED_PROXIES` — but Symfony drops
      every forwarded value failing `FILTER_VALIDATE_IP`, so the sentinel specifically
      cannot be forged into that column. A sentinel there therefore came from one of the two
      passes, and `actor_erased` tells them apart (TRUE = actor pass; FALSE beside
      `resource_erased = TRUE` = this one). `user_agent` carries no such filter and is
      forgeable as the literal; only `ip` supports the inference.
      **This is a new insider capability, not a widened one, and an admin-initiated erasure
      is not forensically neutral.** The actor pass matches `actor_id = <subject>`, so every
      column it overwrites belonged to the person being erased; this pass matches on the
      resource, so it destroys request metadata belonging to whoever *acted* — an admin
      erasing a brute-forced account destroys the attacker's address, which no admin action
      could do before. It leaves `actor_erased` FALSE, because that actor was never
      identified and so was never erased: **`ip = '[REDACTED]'` does not imply
      `actor_erased`**, and nothing may derive one from the other. Two mutation paths
      sharing one normative sentinel — not a fourth mutation policy on the table.
      **Subject erasure is distinct** (never merged —
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
- [ ] **Authenticated password change (`POST /api/v1/me/password`):** the only credential write that starts from
      a live session, so it is graded post-identity rather than uniformly. **Re-proving the current password is
      the authorization**, and it is also the CSRF control: the endpoint carries no `#[IsGranted]` (every identity
      acts on its own — the `^/api` `IS_AUTHENTICATED_FULLY` rule plus the Session Admission Gate are the access
      decision) and no `#[IsCsrfTokenValid]`, like the other authenticated writes, because a cross-site forgery
      does not know the current password and `#[StrictRequestPayload(acceptFormat: ['json'])]` refuses a form
      post. A wrong current password is **403 `invalid-current-password`**, never 401 — a 401 would bounce the
      caller to the login screen for a typo — and it writes nothing: no hash, no event, no revocation, no email.
      A new password equal to the stored one is **422 `new-password-must-differ`**, decided inside the
      transaction (it needs the stored hash) so a no-op change cannot emit a security notice. **Neither plaintext
      leaves `Infrastructure`:** the use case receives closures over the stored `HashedPassword` and handles only
      booleans, so no password can reach a log line, an exception `context`, or `event_store` — whose
      `PasswordChanged` payload is empty. The user row is re-read **under a pessimistic lock** inside the
      transaction, so a concurrent reset and a concurrent change serialise on it. Post-commit, in this order:
      **every session is revoked** (`RevokeSessionsBestEffort`, containment first) then the password-changed mail
      is sent (best-effort, so a hung mailer can neither roll the change back nor hold the revoked sessions open);
      `Security::login()` afterwards mints a replacement registry session with the anti-fixation `migrate(true)`
      regeneration, so the device that made the change walks away signed in. That teardown is **best-effort by
      construction**: it swallows its own failure and the 204 carries no word on it, so "every other device is
      out" is an intention, not a guarantee the response proves — which is why the UI copy and the notification
      mail both point at *Active sessions* instead of asserting it. The login is likewise contained (a failure
      logs `critical` and still answers 204: the credential is already gone, so a 5xx would invite a retry that
      can no longer succeed). `Security::login()` re-enters only `checkPreAuth`, so it does **not** re-run the
      SUSPENDED/DEACTIVATED/locked walls on its own; `ReauthenticateDevice` — shared with the reset and
      invitation-accept flows, which had the identical window — re-reads the aggregate and applies
      `ensureActive()` before minting, so an admin action landing between the commit and the mint no longer
      leaves a fresh session for a walled identity. It restores **two** of those three arms: `ensureActive()`
      matches on `IdentityStatus`, and the lockout arm is deliberately not re-applied because the two flows
      that could meet one clear it inside their own transaction and the third consumes a token that already
      proves control of the mailbox. **All three flows contain that refusal identically**
      (`ReauthenticateDeviceBestEffort`): each reaches the re-login after its own transaction committed, so
      letting the wall decide the status would deny a mutation that happened and invite a retry the spent
      credential or single-use token can no longer serve. The refusal is real either way — no session is
      minted, and the walled identity meets the same wall on its next request, where the answer is truthful. `newPassword` is bounded 8–128 **code points** with at least
      one non-whitespace character, by the single `PasswordPolicy` constraint the reset, invitation-accept and
      bootstrap-CLI surfaces also carry (it used to be six literals, and the reset surface had already drifted
      to 255); `currentPassword` deliberately carries no policy — it may predate today's rule, and asserting it
      would lock its owner out of the endpoint that would fix it — beyond a 255-character DoS ceiling.
      **Attempts are budgeted per identity and refusals are recorded.** `password_change_per_identity`
      (sliding window, 10 / 15 minutes by default, mirroring the persisted lockout) is consumed at the
      controller edge before the payload is weighed, and exhaustion is a **visible 429** — legitimate only here,
      where the caller already holds the identity the budget names. A wrong current password writes an
      `INVALID_CURRENT_PASSWORD` row at `security` level, synchronously, **resource-less** (`actor_id` already
      seals the subject; naming it as the resource would put `actor_id == resource_id` on the
      `audit_log.resource_id` person axis) with only the route in `metadata`. The two halves ship together on
      purpose: a synchronous write per attempt on an unbudgeted endpoint is a write amplifier handed to the
      attacker it records. Still **no CDC row**: `User` stays out of `AuditedEntity`, because a field-level diff
      is the one thing that could carry `password_hash` into the trail. The durable record of a *successful*
      change remains the `event_store` row.
- [ ] **Recovery-throttle exhaustion is observed internally, and the observation is budgeted.**
      A throttled `POST /forgot-password` answers the uniform 202 with the work silenced, which is
      deliberate and unchanged — a per-account 429 would be an existence oracle and would let an
      attacker keep superseding a live token. What used to be missing was the *internal* counterpart:
      the refusal raises no exception and changes no response, so neither generic audit hook could see
      it, and an administrator denied their only recovery edge left no trace. `PASSWORD_RECOVERY_THROTTLED`
      (`security`) now records it, behind `recovery_throttle_audit_per_email` — one claim per
      canonicalised address per hour, because the throttle cannot guard what reports it and a row per
      refusal is a synchronous write per attempt handed to the attacker. The row names the subject when
      the address resolves and nothing when it does not; **the address itself is never written**, in
      any column or encoding. **Three residues, none of them closed:** the claim is spent before the
      write, so an `audit_log` outage costs one window of silence per address (the swallowed `warning`
      is the only signal, and the Monolog→Sentry bridge is deliberately unwired); the budget lives in
      the rate-limiter cache pool, so a redeploy, a `cache:clear` or a second FrankenPHP worker
      produces extra rows for one siege; and the row says *that* exhaustion happened and *when*, never
      *how much* — a six-request accident and a hundred-thousand-request siege look identical, volume
      being left to `anonymous_api` and the access log. A third: two callers crossing the window boundary
      together can both be granted, because the limiter carries `lock_factory: null` — a duplicate row is
      operator noise, an unbounded row count would be the amplifier. **A timing channel found by review was
      measured and then closed rather than accepted:** written in-band, the first refusal of a window cost a
      `SELECT` plus an `INSERT` while later ones cost neither, which measured 14.70 ms of median differential
      (positive in twenty pairs of twenty, against 5.10 ms of noise) — enough for a caller to time. The
      projection now runs on `kernel.terminate`, after the response is on the wire; re-measured identically the
      differential is −0.37 ms, positive in ten pairs of twenty. **Two couplings an operator must know before
      turning the knobs:** widening `RATE_LIMIT_RECOVERY_THROTTLE_AUDIT_INTERVAL` multiplies the row ceiling in
      proportion, and narrowing `RATE_LIMIT_PASSWORD_RECOVERY_PER_EMAIL_LIMIT` as a siege response lowers the
      cost of each row from six requests to two. **One property is stated rather than hidden:**
      an authorised trail reader can tell a resolvable address from an unresolvable one by the presence
      of `resource_id`. It is not reachable by the attacker — the read sits behind `auditTrail.read` —
      and it would become one only if the trail were exfiltrated or a lower tier gained that read.
- [ ] **Pre-identity cross-cutting hardening (login · invitation accept · forgot/reset):**
      every pre-identity rejection pays the same **constant-time floor** (`PreIdentityTimingFloor`, one password
      verification of the firewall's own hasher) — malformed/unknown login identifiers, the `INVITED` pre-auth
      rejection and **every** forgot outcome, so latency correlates with nothing (SI-12). A dead reset/accept
      link never runs the KDF (hashing is deferred until the token proves live). Token hygiene (SI-13):
      `Referrer-Policy: no-referrer` on `/accept-invitation` + `/reset-password` + `/backoffice/audit` and its
      subtree (the audit URL names the people under investigation, so it leaves the tab no more readily than a
      token does). **Read that header for what it is:** it is delivered with a DOCUMENT, so it governs a deep
      link or a refresh and not a client-side navigation into the screen from elsewhere in the back-office,
      where the initial document's policy still applies. It is defence in depth; the edge is what closes the
      log. The client also strips `?token=` from the URL/history on mount, and Caddy's access log **redacts
      every secret- and identity-bearing query parameter**: `authorization`, `token`, the audit screen's
      `actorId`/`resourceId`/`correlationId`, and the `filters[0..19][value]` grammar every list surface
      serializes its filter values into — which also covers the account-holder name and the user email
      filters. **The range is bound to `SearchQuery::MAX_FILTERS`, not to what this UI emits**, because the
      entry is written before Symfony validates anything: a 422 or a 404 is logged like any other request, so
      the enumeration has to cover every index the API will accept from any client. An index at or beyond the
      cap is rejected by validation but still logged, so a caller who fabricates one — and therefore already
      knows the identifier — can plant it there; Caddy's grammar has no wildcard and that stays open. **Caddy also drops the `Referer` header**, because a
      log line records more than its URI: for a same-origin API call the referring document is the screen the
      ids live on, so an unfiltered `Referer` reproduces in clear exactly what the `uri` filter blanked, on the
      same entry. The **application** log answers the same vocabulary through a Monolog processor over every
      record carrying a `request_uri` — the framework's own router listener writes one at INFO, and prod's
      `fingers_crossed` buffer flushes it on any 5xx — and the **Sentry** event is scrubbed on `request.url`,
      `request.query_string` and the `Referer` header before it leaves the process, on both deployables.
      **An identifier does not have to travel under a name anyone listed.** Every rule above matches a
      parameter name, and an expired session redirects to `/login?next=<the whole audit URL>`, so the ids
      arrive inside a value. A value that is itself a URI is therefore followed — to the same bound on both
      deployables — and redacted by the same vocabulary; a value with nothing to redact is returned byte for
      byte, so the shape an operator reads a request by survives. **Nor does it have to travel under the name
      spelled the way our own UI spells it.** A whole match is missed by one byte of padding (`?actorId%00=`,
      `%0A`, `%20`, `actor+Id`) and by one extra layer of percent-encoding, and both shapes still reach the
      sink: the request answers 4xx, and 4xx is exactly what `fingers_crossed` buffers and flushes on the next
      5xx. Keys are therefore stripped of whitespace/control bytes and decoded repeatedly before matching, in the
      **application log and the Sentry event** — reductions, decode bound and nesting bound mirrored across the
      two, because a spelling one side unwraps and the other does not is the same identifier kept out of one
      sink and let into the other. **Caddy is the exception and stays one**: its filter matches a parameter
      name literally, with no wildcard, normalisation or decoding, so `?actorId%00=`, `?actor+Id=` and a
      double-encoded `filters%255B0%255D%255Bvalue%255D=` are redacted in the other two sinks and reach the
      **access log in clear** — measured on the running stack, not inferred. Closing it there means dropping
      the query string from the access log entirely; that trade is open, not made.
      `RedactionVocabularyParityTest` fails the build when the two deployables' vocabularies drift or when an
      identity axis is missing from the edge's enumeration. That index range
      is not left to a reader to keep
      true: the gate (`CaddyfileAccessLogRedactionGateTest`) derives it from the Caddyfile and fails when a PWA
      criteria builder outgrows it, so a twenty-first filter axis breaks the build instead of un-redacting silently.
      The cost is accepted and real — a redacted value axis means the access log can no longer answer "which
      filter was applied". Recovery rate limits are **neutral per target**: forgot is capped
      per email (`password_recovery_per_email`) and a saturated target still gets the uniform 202 with the work
      silenced (plus the timing floor); token endpoints are capped per selector. **A per-target budget may only
      429 where the caller has already proved it holds the target** — which is the authenticated password change
      above and nothing on this pre-identity surface, where a visible refusal would answer "this account exists"
      to a prober. The rule is about who is asking, not about the limiter's scope.
      Security emails come from `MAILER_SECURITY_FROM` (monitored, replyable — a blank/no-reply value **fails
      loudly** outside dev/test) and refuse to emit a non-HTTPS link outside dev/test; token-bearing emails stay
      **synchronous best-effort** (never routed through a Messenger transport, which would serialise the raw
      token); the token-free password-changed notification is sent the same way, by `CompletePasswordReset` through
      `SendPasswordChangedEmailBestEffort`, post-commit and after the session revocation. Retention: expired
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
- [x] **Corrupt identity rows fail closed on the auth path, at BOTH re-hydration sites.** The identity is
      re-read on every authenticated request, and two stored shapes are unrepresentable in the domain: a
      `roles` value no `Role` case backs, and a `password_hash` `HashedPassword` refuses. Neither may 500 on
      the credential path, and the two answers differ on purpose. The **role** is discarded and the identity
      admitted — authorization is grant-only, so an unrecognised value concedes nothing and dropping it can
      only narrow, while refusing would turn a value that grants nothing into a lockout with no
      administrative unlock for whoever carried the retired case. The **credential** fails closed: refused as
      the same `UserNotFoundException` an unknown email gets, timing floor included, so the fault stays loud
      in the logs and silent in the response. Both sites are guarded — `UserProvider` for the row it loads,
      and `SecurityUser::isEqualTo` (`EquatableInterface`) for the copy deserialised from the session, which
      the firewall compares on every request and which reaches that comparison through no provider. That
      second guard also owns the guarantee both password-replacement flows rest on when they swallow a failed
      session revoke: a credential change de-authenticates the sessions that predate it.
- [x] **The tolerance has a detective counterpart.** Because both shapes are handled silently and well,
      nothing surfaces the drift: no exception, no log line, every gate green over a table that is rotting.
      `identity:integrity:inspect` reads the raw columns (never through the aggregate, which filters the
      orphan out on the way in) and exits `SUCCESS` / `FAILURE` / `INVALID` — the third so a failed read is
      never mistaken for a clean table. It reports role values by name and credentials by **count**: an
      identity id is a person reference and this output reaches operator terminals and job logs. A run that
      finds something writes exactly one `security` `STORED_IDENTITY_DRIFT_DETECTED` row, resource-less,
      actor `system`. **It also runs unattended**: `IdentityMaintenanceSchedule` ticks the same inspection
      daily on the already-wired `scheduler_identity_maintenance` transport, and a finding raises one `error`
      line carrying counts and never the stored values — log storage has its own retention and no declared
      owner of its erasure. A failed probe propagates instead, so a control that could not run reaches the
      error tracker while a finding stays a log line. It joined the existing identity schedule rather than
      minting a transport of its own: same context, same daily cadence, one fewer pairing to keep alive.

## 7. Known weaknesses — open, must be closed or consciously accepted before the first customer

Every item here is a **known** gap, not a suspicion. They are listed together because each is
described in its own topic above or in an ADR, where a reader looking for *this* question will not
find them. Closing one means striking it here **and** correcting whatever above describes the
mitigated state. Accepting one means recording who accepted it and against which customer.

- [ ] **The audit trail is not tamper-evident.** No hash chain, signature or checksum column exists
      in any migration; append-only is a property of the mutation paths, not cryptography. Anyone
      with database credentials can rewrite history undetectably — the app's RBAC is irrelevant to
      that. **Never claim integrity beyond "append-only by construction" to an assessor.** Closing
      it is hash-chaining or WORM storage, filed as a revisit trigger in
      [`docs/adr/regulatory-audit-trail.md`](docs/adr/regulatory-audit-trail.md) D5.
- [x] **GDPR erasure reaches the `resource_*` columns** — closed. The owning context anonymises them
      via `AuditResourceAnonymiser`, with the **same pseudonym** as the actor pass and inside the same
      transaction, so no row survives holding a fresh pseudonym beside the subject's real id. The
      shared module supplies the `UPDATE` and never learns which types denote people; the
      classification lives in `api/.audit-resource-types`, enforced by `make php.lint.audit-resource`,
      and `identity:gdpr:reconcile-subject-references` reports any erased identity the trail still
      names. Verify when adding a person-denoting `resource_type`: classify it, wire its erasure, and
      cover it with a Behat scenario — the gate checks declaration and wiring, not runtime reach.
- [x] **GDPR erasure is not defeated by the Messenger transport tables** — closed for the one event that
      reached them. `messenger_messages` and the `failed` queue have neither TTL nor prune and no erasure
      path touches them, so a queued message naming a person outlives that person's erasure. The rule is
      now declarative: an "aggregate id alone" payload may ride a persisted transport only if the aggregate
      is not a natural person. Classification lives in `api/.persistent-transport-policy`, enforced by
      `make php.lint.persistent-transport`, which resolves each routing key to the events Messenger would
      really send — class parents, interfaces, namespace wildcards, a bare `'*'` and `#[AsMessage]` all
      route without naming a class, and a gate reading keys as class names would miss every one. Verify when
      adding an event: classify its aggregate, and do not route a person's aggregate off the request.
      **Two limits, stated so a green build is not read as more than it is:** the gate classifies the
      *aggregate*, not the payload — a non-person aggregate carrying a person's id (`Iam.Session`'s
      `userId`) is out of its reach — and it says nothing about `event_store`, which keeps the real
      `aggregate_id` forever regardless of routing (below). `Iam.Invitation` left that first limit by
      becoming a `person` aggregate: its six events now name the invited user and carry an empty payload,
      because their previous `aggregate_id` was the **selector** of the acceptance link and this log has no
      TTL. The classification is `person` on what the id denotes, not on what the type is called — reading
      the name `Iam.Invitation` and correcting it back to `non-person` would file a person's id as safe to
      queue.
- [x] **A persisted reference to a person has a named owner of its erasure, and that owner executes it** —
      closed for every `Types::GUID` column an entity declares. No object graph crosses a module boundary, so
      a context needing a person holds their id; `membership.user_id` and `iam_invitation.invited_user_id`
      cross a context by id. No column anywhere in the schema references `identity_user`, so deleting an
      identity cascades to nothing and every reference owes its erasure to a use case; these two simply had
      none, and the subject's real id stayed behind. Both are now hard-deleted inside the erasure
      transaction through their owning context's published use case. The rule is declarative:
      `api/.person-reference-policy` classifies every such column as `non-person` or
      `person :: <file that erases it>`, `#[PersonSubjectReference]` declares it at the property, and
      `make php.lint.person-reference` fails the build when a column is unclassified, a line matches no
      column, a declared owner does not execute the deletion, or the attribute and the registry disagree.
      Verify when adding an entity column that holds someone's id: classify it, and name what erases it.
      **Two limits, stated so a green build is not read as more than it is:** the gate cannot judge the
      classification — a false `person` refutes itself because nothing erases it, but `non-person` written
      over a person's id passes, and review is the only control on that direction — and it derives from
      entity properties, so references born in configuration and tables with no Doctrine entity
      (`audit_log.*`, `event_store.aggregate_id`) are outside it.
- [ ] **The person-reference axis has no DETECTIVE control and no backfill.** The gate above is static: it
      proves a deletion is *written*, never that a row *went*. Two consequences, both open. (1) Any subject
      erased before this shipped left its `membership.user_id` / `iam_invitation.invited_user_id` row behind,
      and nothing in the codebase would ever name those rows again — they are not migrated or swept here.
      (2) A future write path that creates a person-referencing row without going through the erasure chain
      reintroduces the residue silently. The sibling axis already answers this with
      `identity:gdpr:reconcile-subject-references`, whose docblock states the reasoning
      (*"divergence surfaced beats divergence assumed away"*); the equivalent join is not built. Its scope is
      **four** columns, not the two this axis closed: `membership.user_id`, `iam_invitation.invited_user_id`,
      `iam_session.user_id` and `identity_password_reset_token.user_id` carry the same defect exactly, and
      none of them has a foreign key — nothing in the schema references `identity_user` at all.
      Tracked as **G-1c** in the GDPR-hardening epic. The ordering note against G-3b — which schedules the
      same reconciler — is **moot under the option G-1c ships**: one reconciler extended with a lister per
      owning context, so a schedule created first picks the new axis up with no revisit. It bites only if
      that decision is reopened toward one reconciler per context, and is kept as that tripwire. The
      **backfill half is measured away** — no production environment exists, so there are no real erased
      subjects with surviving orphan rows; the story is prospective. Close it before claiming the axis is
      enforced at runtime rather than at build time.
- [ ] **`event_store` retains a person's real id past their own erasure.** Every dispatched event is
      appended with its real `aggregate_id`, and no erasure path touches the table. As the `aggregate_id`:
      `PasswordResetCompleted`, `UserSuspended`, `UserDeactivated`, `UserRolesChanged`, `UserLocked`,
      `PasswordResetRequested`, plus `AllSessionsRevoked` and `OtherSessionsRevoked` — those last two are
      coarse facts about the USER, so their `aggregate_id` is the `userId` and their payload is empty, the
      same shape as the leak this entry closes, and so are the six `Invitation*`, whose envelope names the
      invited user. In the payload: `SessionStarted` and `SessionRevoked` (`userId`) — and, in rows written
      before the invitation envelope moved, `Invitation*`'s `invitedUserId`. Those rows are deliberately not
      migrated, which is why the erasure matches **by value across both axes** rather than by a remembered
      list of columns or keys.
      It is not reachable by the crypto-shredding used in `audit_log`: `aggregate_id` is `UUID NOT NULL`, a
      stream key and an index (`event_store_stream_version_uniq`, `event_store_aggregate_idx`), and a
      lookup table is barred by [`docs/adr/audit-activity-log.md`](docs/adr/audit-activity-log.md) D4. The
      only viable route — the id being born a per-subject derived substitute whose derivation secret the
      erasure destroys — touches every event, projection replay and checkpoint, so it is a persistence
      strategy decision and ADR material, tracked as a story in the GDPR-hardening epic. Nothing in the
      repo declares `event_store` erasable today
      ([`docs/adr/regulatory-audit-trail.md`](docs/adr/regulatory-audit-trail.md) separates it, as the
      business log, from the retention-bound PII-erasable trail).
- [ ] **`audit:gdpr:erase` is not atomic.** The anonymisation `UPDATE` commits and the
      `GDPR_ERASURE_EXECUTED` self-audit is written *after*, outside any transaction — a crash
      between them leaves the erasure done with no evidence of it, and the original id no longer
      matches anything, so a re-run falsely reports "nothing to erase". Accepted while the only
      trigger is a synchronous operator command (`audit-activity-log.md` D4). **Its revisit trigger
      fires** if #555 lands a second mutation statement, or the day a non-CLI trigger appears:
      route it through `TransactionManager`, never a raw DBAL transaction nested under
      `wrapInTransaction` (no `nest_transactions_with_savepoints` is configured).
- [ ] **The sole active administrator cannot be erased.** Demotion is refused by the ≥1-admin
      invariant, self-erasure by its own guard, and no peer exists to erase them — so their right to
      erasure requires onboarding a second administrator first. Pre-existing and named in
      [`docs/adr/authorization-model-boundaries.md`](docs/adr/authorization-model-boundaries.md) D3;
      it becomes a real obligation the moment a single-administrator installation has a customer.
      **Mitigated, not closed:** [`docs/deployment-guide.md`](docs/deployment-guide.md) § *Provisioning
      administrators* now instructs the operator to onboard a second administrator at install time, and
      [`docs/adr/administrative-recovery-channel.md`](docs/adr/administrative-recovery-channel.md) D4
      records why it must stay a **recommendation** — a ≥2 floor would make erasing an administrator
      require a third. An unenforced recommendation cannot make the invariant hold in an installation
      that declined it, so this entry stays open; closing it means an enforced guarantee or an
      acceptance naming the customer.
- [x] **A role change leaves an attributable record — closed by #555 (2026-07-27).** `User` still
      stays out of the `AuditedEntity` CDC, because a field-level diff would carry `password_hash`
      into the trail, and the generic hook still audits only `GET`. What closed the gap is an explicit
      row: `ChangeUserRoles` writes a `USER_ROLES_CHANGED` `security` entry naming the subject in the
      resource columns and carrying both role sets in `metadata`. Naming a person there is safe only
      because erasure over `resource_*` is owned by the context that owns the person and runs in the
      same transaction. The erasure refusal that requires demoting an administrator first is therefore
      now backed by a traceability control, and may be cited as one.
- [ ] **A lockout notice can be delivered to nobody, and the control will record it as delivered.**
      Closed in configuration and defended in code, but it depends on a deployment value and so stays
      listed. `NotifyLockedIdentities` stamps a 24-hour suppression window only on a send that reported
      success — and Symfony's `null://` transport reports success for mail it silently drops. A stock
      deploy used to reach it: `MAILER_DSN` defaulted to `null://null` in every compose file and
      `MAILER_SECURITY_FROM` appeared in none, falling through to a reserved domain that receives
      nothing while passing the non-blank / non-no-reply guard. Both are now `${VAR:?}` on all three
      prod services, listed in `PROD_REQUIRED_KEYS`, and refused at send time by
      `DeliverableSecurityTransport` (discard transport) and `SecuritySenderAddress` (reserved sender
      domain). **The residual is that non-delivery on this channel is unobservable from the outside:**
      the notice is unsolicited, so no recipient reports its absence — the `warning` its best-effort
      wrapper logs on every failed tick is the only signal, and it is a log line, with the
      Monolog→Sentry bridge deliberately unwired. Closing this means an alert on that line.
- [ ] **The lockout notice's single-delivery property rests on a replica pin, not on a lock.**
      The five-minute sweep sends before it stamps (stamping first would turn a mailer outage into a
      day of silence about a live lock), and `IdentityMaintenanceSchedule` is `->stateful()` but not
      `->lock()`ed. Two scheduler replicas would therefore race send-then-stamp and deliver the notice
      twice — at someone whose account an attacker is already driving. `compose.prod.yaml` pins
      `scheduler_worker` to `replicas: 1` and says so at the pin; nothing enforces it. Closing this
      means a scheduler lock, or a deployment check that refuses to scale that service.
- [ ] **`api/storage-test/` is outside `.gitignore`.** It is a Flysystem test-storage directory the
      suite writes into, so any `git add -A` commits whatever a test last wrote — into a **public**
      repository. Today the residue is a 91-byte 1×1 PNG; the exposure grows the day a test writes
      realistic fixture data. Add the path to `.gitignore` and delete the residue.
- [x] **No shipped dependency tracks an untagged upstream branch — closed 2026-08-12.**
      `symfony/mercure-bundle` was pinned to `0.4.x-dev` because the `v0.4.2` tag extended a class
      deprecated in Symfony 8.1 and `failOnDeprecation="true"` turned the suite red, so the only
      alternative was suppressing a real deprecation gate. Upstream tagged `v0.4.3` on 2026-08-11
      carrying the fix (`8708c813`, verified an ancestor of the tag: `compare/v0.4.3...8708c813` →
      `ahead 0, behind 5`). The pair then moved on together to `symfony/mercure ^0.8` +
      `symfony/mercure-bundle ^0.5`, the line upstream actually maintains: `v0.4.3` narrowed its own
      requirement to `symfony/mercure ^0.6.1|^0.7`, so staying on it would have coupled the tree to
      the abandoned 0.4/0.7 pair and put a future advisory in Mercure's JWT-minting path out of
      reach. The bundle keeps `protocol_version: 0.x` by default, so the hub contract is unchanged.
      Its `stability-flags` entry dropped with the pin, leaving two, both `require-dev`.
      **Verified by forcing the broken path, not by a green run:** on a genuinely cold container
      cache `v0.4.2` exits 1 with `Deprecations: 1` naming
      `HttpKernel\DependencyInjection\Extension`, and the tagged line exits 0 over 2698 tests.
      The `upstream-pin-watch` CI job and `make composer.check.mercure-pin` were removed with the
      pin they watched — anchored to a `v0.4.2` baseline they would have stayed red for ever — and
      the knowledge they carried is now a gate rather than prose: `make php.lint.composer-stability`
      fails any branch constraint or `@`-stability flag in `require`.
      Closed [#593](https://github.com/sergio-salcedo-dev/ERPify/issues/593).
- [x] **`failOnDeprecation` was structurally blind in CI — fixed 2026-08-12.** A deprecated *class*
      triggers at file scope, so it fires once per process, while the container compiles. Any
      `bin/console` call under `APP_ENV=test` compiles it first, and CI runs several inside
      `php.quality.dry-run` **before** the suite (`ci.yml`: PHP lint precedes PHPUnit). `Kernel::getCacheDir()`
      forked only for Behat, so console and PHPUnit shared `var/cache/test`: PHPUnit loaded the warm
      container, never autoloaded the class, and reported green over a real deprecation. The gate
      that justified the mercure pin had therefore never once fired in CI. PHPUnit now compiles into
      its own directory (`PHPUNIT_RUNNING`, set in `tools/phpunit/bootstrap.php` the same three ways
      Behat sets its own flag — `getenv()` sees neither `$_ENV` nor `$_SERVER` alone, which is why a
      first attempt via phpunit.xml's `<server>` silently did nothing). **Measured on one tree, one
      version, the CI order:** shared cache → `Tests: 2698`, 0 deprecations, green; forked cache →
      `Tests: 2698`, `Deprecations: 1`, red.
- [ ] **38 direct composer dependencies are behind, because dependabot's composer lane was aimed at
      a directory with no manifest.** `.github/dependabot.yaml` declared `directory: /` while the
      manifest is `api/composer.json`, so the weekly version-update lane produced **zero** PRs in
      four months (npm produced ~40 over the same window). The two composer PRs that did land
      ([#138](https://github.com/sergio-salcedo-dev/ERPify/pull/138),
      [#536](https://github.com/sergio-salcedo-dev/ERPify/pull/536)) came through **security**
      updates, which walk the dependency graph and ignore the config's `directory:` — the back door,
      not the lane. Nothing went red; PRs simply never arrived. The config is fixed here
      (`directory: /api`), which restores the lane but does **not** apply the backlog. Measured
      2026-08-12: 38 direct packages outdated, most of them the Symfony `8.1.2`–`8.1.4` patch line
      against an installed `8.1.0`/`8.1.1`; `composer audit` reports no known advisory, so there is
      no live exposure — the risk is that the next one would also have gone unproposed. **Before a
      customer deployment:** land the catch-up as one consolidated batch (`/deps-update`, which
      re-resolves the ranges in a single install and reads every claimed version back out of the
      lock) and re-run `composer audit`.
- [ ] **A stolen session can deny the owner a credential *rotation*, but not an *eviction*.** Both budgets a
      session holder can reach are keyed by something they already have: `password_change_per_identity`
      (10 / 15 min, a visible 429) by the identity itself, and `password_recovery_per_email` (5 / hour, whose
      exhaustion is **silent by contract**, so the owner meets the uniform 202 and no email arrives) by the
      address `GET /me` hands them. Neither one is the security objective. **Eviction is, and it has a path no
      budget gates:** `POST /sessions/revoke-others` carries no limiter of any kind — every throttle in the
      repo lives in `Iam/Identity` or `Iam/Invitation`, none in `Iam/Session` — needs only a live session, and
      ships in the PWA as *Active sessions*. The owner's route to a live session is untouched: the credential
      still works, a stolen session feeds no failed attempts into the lockout (`LoginAttemptRegistrar` is
      reached only from the login failure handler, never from the password-change path), and the persisted
      lock is enforced at `UserChecker::checkPostAuth` alone, never by `SessionAdmissionGate`. So the sequence
      is **sign in → `GET /sessions` (the intruder's row is listed, with its device label) → `revoke-others`**,
      and the delayed rotation is then hygiene against an adversary who no longer holds anything.
      **The attacker holds the same weapon and the race still resolves for the owner:** either party can fire
      `revoke-others` and evict the other, with no budget on either side, but **re-entry is not symmetric** —
      the owner returns with the credential, while a revoked cookie is dead and no path re-mints one without
      the password. Each round costs the owner one login.
      **What survives is the composition, and it belongs to
      [#602](https://github.com/sergio-salcedo-dev/ERPify/issues/602), not here:** an attacker who *also*
      drives the per-email lockout (10 failures → `PT15M`, needing ≥2 source addresses to clear the per-IP
      throttle) denies the owner the very session eviction requires. Until #602 closes, what the product owes
      is **ordering guidance — evict first, rotate second** — in the UI copy and the password-changed mail.
      `revoke-others` carrying no limiter is deliberate and load-bearing: it is the one edge an adversary
      cannot spend. **Do not "harden" it.**
- [ ] **A credential change can sign a second browser tab out of the application, and it is accepted.** Both
      flows that replace a credential from a live browser — `ChangeMyPassword` and `CompletePasswordReset` —
      revoke **every** session and mint a replacement onto the requesting tab. A request already in flight from
      another tab of the same browser carries the old cookie, meets the gate's `401 session-expired`, and
      `FetchHttpClient` diverts it to `/login?reason=session-expired` irreversibly — so a change that fully
      succeeded reads, in that tab, as being thrown out. The alternative is `revoke-others`, which needs three
      new cross-context seams to know which session is the caller's own; the exposure is a stale tab meeting a
      login screen, never an access leak. **Both flows are named on purpose:** the reset surface has carried the
      identical window since it shipped, and recording only the change flow would leave the register
      inconsistent and invite the next reviewer to re-raise the other half as new.
      **Accepted 2026-08-05 (Sergio):** no customer, and the cost of closing it is three seams across two
      contexts for a recoverable UX papercut. Re-assess if a session-scoped revoke becomes cheap for another
      reason, or the first time a user reports it.
- [ ] **A person's id still reaches the access log through the URL *path*, and it is accepted.** Caddy's
      access-log filter operates on `request>uri query`, so it is structurally incapable of touching a path
      segment — and the application log's `request_uri` leaves the path alone by the same decision, so the
      residual is one residual across both logs rather than a difference between them. The producer is
      `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts`, which composes
      `/api/v1/backoffice/users/<uuid>`, where the uuid is the person's id. The sink has no owner of erasure:
      no compose file declares a `logging:` driver, so it is the default json-file driver with neither rotation
      nor TTL, and nothing in `FulfilIdentityErasure` can reach it. An id that lands there outlives the erasure
      the application confirmed to the subject. Closing it is not a wider `replace` but a different mechanism —
      a log-level rewrite of `uri`, a `logging:` driver whose retention the erasure path can act on, or no
      access log for `/api/*` at all. **Accepted for now:** there is no production deployment, and the
      query-side leak that *was* closed is the one with volume — it fired on every keystroke of a filter,
      against one entry per user record opened here.
- [ ] **The Next.js container logs the full request URL, person ids included — measured in dev, unverified in
      prod.** The audit screen navigates to `/backoffice/audit?actorId=<uuid>&resourceId=<uuid>`, Caddy
      reverse-proxies the document to the PWA, and the container prints
      `GET /backoffice/audit?actorId=… 200 in 73ms` to stderr — the same json-file driver with no rotation and
      no TTL that Caddy's access log uses, and one that Caddy's `query` filter cannot reach because it is a
      different process. **Caddy's redaction does not cover this**; anyone reading "the access log redacts
      `actorId`" as "no log holds it" would be wrong. `pwa/next.config.ts` also sets
      `logging.fetches.fullUrl: true`, widening what server-side fetches record.
      **Scope, stated with its limit:** that line is emitted by `next dev`, and production runs the standalone
      `node server.js` (`pwa/Dockerfile`), where it was **not** observed — but nor was it verified against a
      running production image, so treat prod as unconfirmed rather than clean. Closing it means a `logging:`
      driver with a retention the erasure path can act on, or keeping the ids out of the document URL — which
      the deep-link design deliberately does not do.
- [ ] **The repository is public and now documents this posture in detail.** `ADMIN` reads the trail
      that audits it, the bootstrap provisions exactly one administrator, the trail is not
      tamper-evident, and the PR/issue history carries reproductions of defects found in review.
      None of it is exploitable without an authenticated `ADMIN` and there is no production
      deployment today, which is why it was published deliberately rather than withheld. **Re-assess
      before the first customer or any public deployment**, whichever comes first: decide per item
      whether it stays public, and remember that redaction after indexing is not retroactive.

## 8. Deploy & verify

- [ ] `make deploy.local` (or `scripts/deploy/deploy-local.sh`) reaches a 200 on
      `https://$SERVER_NAME/api/v1/health`.
- [ ] `docker compose … ps` shows every service healthy under the prod overlay.
- [ ] `make docker.down.clean-volumes` and `db.reset` are **never** run against
      a prod stack.
