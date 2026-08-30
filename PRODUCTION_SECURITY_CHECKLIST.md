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

- [ ] **Image bytes live on the `image_storage` named volume, mounted at `/app/storage` by both `php` and
      `messenger_worker`.** It is not optional and it admits no residual: with no volume the storage adapter
      writes into the container's writable layer, so every redeploy empties the store while every `image`
      row survives — and nothing reports it, because this module deliberately keeps no bookkeeping that
      could find the divergence. The prod image creates the mount point owned by `www-data` before anything
      mounts over it, since Docker seeds a new named volume from the image's directory ownership and the
      runtime user is unprivileged. Verify after deploy: the path is a mountpoint, it is writable by the
      runtime user, it is not inside the source tree, it is not served by Caddy, and bytes written before a
      `--force-recreate` are still readable after it.

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
      `SENTRY_AUTH_TOKEN`, never `NEXT_PUBLIC_`.
- [ ] Sentry source maps are **uploaded, not published**. Upload is on only when
      `SENTRY_AUTH_TOKEN` **and** `SENTRY_ORG` are both set at build time; the
      project slug defaults to `erpify-pwa-${NEXT_PUBLIC_APP_ENV}` so one image
      cannot ship its maps to the other environment's project.
      `deleteSourcemapsAfterUpload: true` in `pwa/next.config.ts` is a **pin,
      not a switch** — the option already defaults to `true` in @sentry/nextjs
      10.70.0, so writing it makes a future default flip or a casual removal a
      visible change rather than a silent republish. The option that would
      genuinely republish the maps is `filesToDeleteAfterUpload`, which
      **overrides** the flag outright: a narrow glob there deletes only what it
      names and serves the rest. It must stay absent. The token reaches the build as a **BuildKit secret**
      (`--mount=type=secret,id=sentry_auth_token`), never as a build `ARG` —
      `docker history` prints build args, and this token grants *write* access to
      the Sentry project. Both invariants are gated by
      `pwa/tests/sentry-sourcemap-exposure.test.ts`; a green proves the two
      declarations, never what a real build emits, and never that a CDN or a
      workflow artifact is not serving a copy from somewhere else.
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
- [ ] **Exactly one health route is public: `/api/v1/health`.** It is liveness-only —
      a static payload (status, service name, server time) with no DB / Mercure /
      Messenger probing, no PII, no versions — and it holds the sole `$`-anchored
      `PUBLIC_ACCESS` health entry in `api/config/packages/security.yaml`. It has two
      three anonymous consumers that cannot present a session: the §8 deploy smoke
      test (`scripts/deploy/deploy.sh` requires exactly `200`), the CI smoke steps
      (`.github/workflows/ci.yml`), and the public `/status` page, which mounts
      outside `RequireAuth`. Retiring it bounces an anonymous
      visitor to `/login`, since the PWA's HTTP client treats a 401 outside the auth
      handshake as an expired session. Its anonymity is pinned by an `@anonymous`
      scenario in `api/features/frontoffice/health/get.feature` — otherwise nothing
      would go red when someone closed it.
- [ ] **`/api/v1/backoffice/health` requires a session, like the rest of `/api`.** It
      is served by the same PHP process off the same static payload, so the public
      route answering already proves that process alive; its own page mounts behind
      `RequireAuth`, and the deploy runbooks now curl the public route instead.
      Pinned by an `@anonymous` 401 scenario in
      `api/features/backoffice/health/get.feature` that also asserts the body carries
      no `data` node — the status alone cannot tell a firewall rejection apart from a
      controller that answered.
- [ ] **Two invariants over the health surface.** _Any deep health check is
      authenticated_ — the dependency probe `/api/v1/backoffice/health/database`
      falls through to `IS_AUTHENTICATED_FULLY`, pinned by an `@anonymous` 401
      scenario in `api/features/backoffice/health/database.feature`. And _no liveness
      route ever grows dependency status_ — both liveness features assert `0 requests
    got executed across all doctrine connections`.
- [ ] **Every public exemption is classified, and the classification is gated.** The
      firewall is default-deny, so an `access_control` line is the entire
      authorization story for an unauthenticated caller — and nothing used to read
      that list. Each entry is classified in `api/.public-access-exemptions` by its
      anonymous consumer and by whether it matches one route or a subtree;
      `make php.lint.public-access` fails on an unclassified exemption, an orphaned
      line, a pattern that differs textually, a form that disagrees with what it
      matches, a prefix exemption whose subtree is no longer bounded by the file it
      names, and the loss of the terminal default-deny rule that makes the rest
      exemptions at all. **Two spellings admit an anonymous caller without naming
      `PUBLIC_ACCESS`** and both are collected: a rule keyed on `route:` instead of
      `path:`, and one whose `roles` are absent or empty — the latter makes
      `AccessListener` return before deciding anything. **And the anchor is not the
      invariant**: `^/api/v1/health.*$` is anchored and still reopens the subtree
      whose loss of anchoring made the database probe anonymous, so `exact` means a
      literal path and a prefix must name what bounds it. The registry header
      enumerates what a green does not prove.
- [ ] `GET /api/v1/backoffice/banks/{id}/accounts` returns the **full canonical
      IBAN** (PII) and is **consciously public** like the rest of `/backoffice`
      (the API has no auth layer yet). The masking is presentational on the PWA
      only — the backend never masks. Two invariants: the IBAN value is **never
      logged** (the access-audit message carries only `bankId` + timestamp), and
      when the API firewall lands this route must require authentication (it is
      **not** a public-by-design endpoint, unlike health). Tracked in
      [#240](https://github.com/sergio-salcedo-dev/ERPify/issues/240) (auth rollout,
      sibling of the health exemption #222).
- [ ] **A validation message may name the rule, never the value.** "The IBAN value is never
      logged" was an invariant nobody enforced: `Assert\Bic`'s default `ibanMessage`
      interpolates `{{ iban }}`, `ValidationFailedException::getMessage()` renders the whole
      violation list, and `ExceptionResponder` wrote it into `exception_message` — measured
      end to end, a real IBAN in `var/log` on a live request. `RedactionDenylist` cannot see
      it: it strips by KEY, and the key is `exception_message`. Two controls now hold the
      invariant: `ConstraintMessageValueGateTest` refuses any constraint message under `src`
      that interpolates a non-configuration placeholder, and `ExceptionResponder` rebuilds
      `exception_message` from the validated type, the declared property paths and the
      constraint codes whenever a validation failure appears **anywhere in the throwable
      chain** (the HTTP edge wraps it in an `HttpException`, so a top-level check misses every
      mapped DTO). A property path is emitted only when the validated type declares it —
      `UnknownPayloadMemberListener` uses the surplus member's own name as the path, so a body
      keyed by an IBAN would otherwise put it back. **Still open:** the same unreduced message
      reaches **Sentry**, whose `SentryEventFilter` drops only project `ClientError` markers —
      a validation 422 is not one — and whose scrubber declares exception messages out of
      scope.
- [ ] The **standalone Bank Accounts UI** (`/backoffice/bank-accounts` list +
      `/backoffice/bank-accounts/{id}` detail) surfaces the **full canonical IBAN**
      (PII) sourced from `GET /api/v1/backoffice/bank-accounts` (global list) and
      `GET /api/v1/backoffice/bank-accounts/{id}` (detail) — both **consciously public**
      like the sibling nested route (the API has no auth layer yet). Mitigations already
      in place: the IBAN is **masked at the presentation edge** (`maskIban` / `IbanCell`),
      the backend never masks; and the realtime channel keeping the list/detail live is
      **PII-free** — the Mercure broadcast carries only `{ type, id, bankId }` and drives
      a refetch, never the IBAN (see the _Realtime wire contract_ section of
      [`docs/architecture/event-catalog.md`](docs/architecture/event-catalog.md)). Same
      two invariants as the nested route: the IBAN value is **never logged**, and these
      are **not** public-by-design endpoints — **route-level RBAC gating is required
      before production**, requiring authentication when the API firewall lands (a
      pre-prod follow-up under the same auth rollout,
      [#240](https://github.com/sergio-salcedo-dev/ERPify/issues/240) / #222).
- [x] **IBAN search moved off the GET query string (#426).** `iban` was a filterable
      field on `GET /api/v1/backoffice/bank-accounts` (`eq`/`contains`, unreachable from
      the PWA UI but reachable directly), so the integral IBAN could be sent as a
      query-string value — a parameter can reach a proxy/CDN access log or be cached by
      an intermediary keyed on the URL regardless of any single deployment's Caddy
      redaction config (see the whole-query-string strip discussed in root `CLAUDE.md` →
      "Putting a value in a query string"). `iban` is now **removed entirely** from the
      collection's `searchFieldMap()` (a filter naming it is 422 `unknown-search-field`,
      like `status`/`currency`). Exact lookup by IBAN goes through a dedicated
      `POST /api/v1/backoffice/bank-accounts/iban-lookup` instead: same `bankAccount.read`
      permission gate, the IBAN travels only in the body (never logged by Caddy, which
      does not log request bodies), the malformed-IBAN 422 never echoes the rejected
      value (mirrors `ConstraintMessageValueGateTest`'s rule for constraint messages), and
      the not-found 404 (`bank-account-not-found`) carries no `iban` context key — unlike
      the by-id 404, which does echo the (non-sensitive) id.
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
      **five-year floor covers `change` rows only**; `security` rows (access, denials and
      role-change records) carry a 365-day privacy _ceiling_ and are pruned — do
      not cite the floor as a retention guarantee over access evidence. **The one exception
      is the trail's own proof that it executed an erasure** (`GDPR_SUBJECT_ERASED`,
      `GDPR_ERASURE_EXECUTED`, listed in `AuditErasureEvidence` and classified in
      `api/.audit-evidence-actions`, which `make php.lint.audit-evidence` keeps equal to that
      closed set in both directions — be exact about what that buys: it proves the two agree
      for actions a writer declares as a **string constant taken through its constructor**, and
      it never judges a classification, so an `ordinary` line over real evidence, an inline
      literal, or a backed enum all pass; the registry header enumerates the rest):
      the prune skips those,
      because the `dek_keystore` tombstone they answer for is kept for ever and the
      reconciler anti-joins the two with no date bound, so an expiring proof makes that
      pair unsatisfiable. Be exact about the cost when citing it: those rows are minted in
      the request cycle on the HTTP path, so **the acting administrator's `ip` and
      `user_agent` are retained indefinitely along with their `actor_id`** — clearable only
      if that administrator is themself erased, since the actor-axis pass matches by
      `actor_id`. **Say the precondition with it, or the sentence promises a route that is
      refused:** _identity_ erasure is rejected while the subject still carries `ADMIN` (409
      `administrator-erasure-requires-demotion`, `FulfilIdentityErasure`), so on that route
      clearing those columns requires demoting them first — which the ≥1-admin invariant
      permits only while a **second active administrator exists**. It does not require that
      administrator to _act_: nothing guards self-demotion, so one principal may drop their own
      `ADMIN` unilaterally, and the invariant is about who survives, not who performs. Be
      equally exact about the other direction: this gates the identity route, **not** the
      actor-axis anonymiser. The operator CLI `audit:gdpr:erase` reaches those same columns for
      any UUID with **no role check at all**, so a sole administrator's rows are clearable
      there. What the CLI paths do not do is _write_ `ip`/`user_agent` on rows they mint — they
      run off-request as `system`, with both columns NULL. **Weighed and accepted:** stripping
      request metadata at write time would take attribution off the one class of row whose
      purpose is attribution, and a bounded floor for `GDPR_ERASURE_EXECUTED` alone splits
      one rule in two for a difference no reader infers from the rows. Revisit at the first
      of — a production deployment that erases a real subject, an administrator who leaves
      without being erased, or a DPO review. Only the first is machine-observable, and **this
      paragraph does not observe it**: issue #718 holds the candidate predicate (a production
      count of exempt rows still carrying `ip`/`user_agent` with `actor_erased = FALSE`) and is
      the artefact with an inbox. Do not read a record in four files as a wake-up.
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
      requester is unauthenticated and supplies only a _claimed_ identity, so the only
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
      resource, so it destroys request metadata belonging to whoever _acted_ — an admin
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
      is a different axis from `check_header`, which governs the _cookie_ half and reads a header named after
      `cookie_name` (Symfony default `csrf-token`). Turning `check_header` on would have Symfony look for
      `csrf-token`, **not** our `X-CSRF-Token` — two similar-looking headers with unrelated jobs.
      Password hashing is Infrastructure (the DTO enforces the 8..128 policy at the boundary) and is **deferred
      behind the token check**: a dead accept link never pays an argon2id run (no unauthenticated KDF
      amplification). The accept is capped **per selector** (`token_action_per_selector` limiter); exhaustion
      folds into the same opaque `invalid-token`, never a per-selector 429.
- [x] **Every payload-mapping endpoint accepts JSON only, and it is the type's default that says so.**
      `StrictRequestPayload::__construct` defaults `acceptFormat` to `['json']`, so a form-encoded or
      `multipart/form-data` body is refused with 415 at the argument resolver, before any controller or
      handler runs — and an endpoint added tomorrow inherits the refusal by writing nothing. The thirteen
      attribute sites carry no format list of their own; the guarantee is one line in one type rather than
      a declaration repeated thirteen times, which is what makes an omission impossible instead of merely
      unlikely. Uniformity is the control: the API declares no `#[MapUploadedFile]` argument anywhere, and
      a route that silently accepted a multipart body would be the seam through which a file part could
      re-enter. Verify the two halves separately, because no single grep sees both — the attribute's name
      also appears in docblocks, so counting `#[StrictRequestPayload` matches prose as well as sites:
      (1) the default holds — `git grep -n 'acceptFormat' -- api/src` names only the constructor in
      `Shared/Http/Infrastructure/StrictRequestPayload.php`, so no call site has widened it; (2) the
      default means what it says — `StrictRequestPayloadTest::itRefusesAFormEncodedBodyWithoutBeingAskedTo`
      and `itAcceptsAJsonBodyWithoutBeingAskedTo` put a payload declaring no format through Symfony's own
      resolver and assert the 415 and the 200, so the pin is the status a caller receives rather than the
      value the constructor stored. Gate: `BankCreateAcceptsJsonOnlyFunctionalTest`.
      **The default is no longer the only control, because it was never the whole one.** The resolver gates
      its format check on TRUTHINESS (`RequestPayloadValueResolver:242`), so a falsy `acceptFormat` does not
      loosen the check, it skips it — and a call site could therefore disable the refusal while leaving the
      default untouched. The constructor now refuses a falsy value outright, mirroring that predicate rather
      than enumerating the values satisfying it: a list of `null`, `[]` and `''` reads as exhaustive and
      admits `'0'`, measured accepting a form-encoded body through the real resolver. **Residual:** the
      refusal is raised while Symfony builds the attribute per request (`ArgumentMetadataFactory`), so a
      mis-declared site is a runtime 500 on that endpoint — not a build failure, and invisible to CI. And
      grep (1) above cannot see such a site at all, since the widening would be an attribute argument rather
      than the string `acceptFormat`; what covers that direction is the constructor, not the recipe.
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
      does not know the current password and `#[StrictRequestPayload]`, JSON-only by its own default, refuses a form
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
      mail both point at _Active sessions_ instead of asserting it. The login is likewise contained (a failure
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
      is the one thing that could carry `password_hash` into the trail. The durable record of a _successful_
      change remains the `event_store` row.
- [ ] **Recovery-throttle exhaustion is observed internally, and the observation is budgeted.**
      A throttled `POST /forgot-password` answers the uniform 202 with the work silenced, which is
      deliberate and unchanged — a per-account 429 would be an existence oracle and would let an
      attacker keep superseding a live token. What used to be missing was the _internal_ counterpart:
      the refusal raises no exception and changes no response, so neither generic audit hook could see
      it, and an administrator denied their only recovery edge left no trace. `PASSWORD_RECOVERY_THROTTLED`
      (`security`) now records it, behind `recovery_throttle_audit_per_email` — one claim per
      canonicalised address per hour, because the throttle cannot guard what reports it and a row per
      refusal is a synchronous write per attempt handed to the attacker. The row names the subject when
      the address resolves and nothing when it does not; **the address itself is never written**, in
      any column or encoding. **Three residues, none of them closed:** the claim is spent before the
      write, so an `audit_log` outage costs one window of silence per address (the swallow is the only
      signal; it now logs at `error` on the always-on `observability` channel, so it survives to the
      container's stderr, but the Monolog→Sentry bridge is deliberately unwired and nothing pages); the budget lives in
      the rate-limiter cache pool, so a redeploy, a `cache:clear` or a second FrankenPHP worker
      produces extra rows for one siege; and the row says _that_ exhaustion happened and _when_, never
      _how much_ — a six-request accident and a hundred-thousand-request siege look identical, volume
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
      log. The client also strips `?token=` from the URL/history on mount, and **Caddy's access log carries
      no query string at all**: the filter strips everything from the `?` and keeps the path. It does not
      enumerate sensitive parameter names, and that is the correction rather than a detail — an enumeration
      stood here for months and, measured against the running stack by `AccessLogQueryContainmentGateTest`,
      contained **one of nine** spellings of a value. The `in` operator's own spelling
      (`filters[N][value][]`, `filters[N][value][0]`), any filter index past the enumerated range, a
      double-encoded key and a plain parameter name nobody had listed each reached this log in clear.
      Caddy's `query` filter substitutes the value of an exactly named parameter and has no wildcard, so its
      reach was whatever a person remembered to write. **The threat model the old acceptance was signed
      against was the wrong one**: it argued that whoever plants an index past the cap already knows the
      identifier, which answers confidentiality and answers nothing about **retention** — the harm is that
      the deployment then holds a person's identifier in a sink bounded by size alone, with no TTL and no
      owner of erasure, and any client can force that for free. Both cliffs (index and sub-index) are closed by the
      strip rather than by a longer list. **Caddy also drops the `Referer` header**, because a
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
      assign a tier role to any pre-existing **non-`ADMIN`** principal _before_ the gate ships, or it loses bank
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
- [ ] **A third recovery edge for the persisted lockout above: `POST /backoffice/users/{id}/unlock`**
      (`ADMIN`-only, `#[IsGranted('users.unlock')]`, `users` opts out of tier auto-grant). #602 named the two
      existing recovery edges — a successful login, a completed password reset — as both attacker-cuttable by
      anyone who merely knows the target's email, with no lever an administrator could reach for instead. Wraps
      the already-idempotent `User::clearLockout()`; the response's `unlocked` field surfaces its own mutation
      signal rather than always claiming a recovery happened, and the call is audited (`SECURITY`,
      `ACCOUNT_UNLOCKED_BY_ADMIN`) whether or not it mutated anything — the lever being invoked is itself the
      fact worth keeping. **An administrator may never unlock their own identity** (409
      `self-unlock-forbidden`, refused before any row is touched): granting that would make `users.unlock` a
      second, credential-independent path into one's own account, defeating the lockout it exists to recover
      from. **Residual, explicitly out of scope here:** an installation with a single administrator has nobody
      to invoke this lever if that administrator is themselves locked out — #602 stays open on that gap and on
      the detection/notification half (`NotifyLockedIdentities`), tracked separately.
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
      consent. **Retention policy — wired and unattended; see the limits below before calling it enforced:** the
      native GC prunes the file store, **not** this table, and nothing else removes a row for age — revocation is
      logical and keeps the row, expiry is a read predicate — so this sweep is the only thing bounding how long
      `ip`/`device` live. `PruneRetiredSessions` drops a row when the **first** of its windows elapses: 30 days
      after `revokedAt` for a revoked row, 90 days after `expiresAt` for any row. Only the revocation branch
      names a status, deliberately — a bulk revocation stamps `revokedAt = now` on rows already long expired, so
      judging a revoked row by its revocation alone would let a password change _extend_ the life of a row dead
      for months. With the seven-day session TTL the ceiling is ~97 days after the login that minted the row.
      It runs on demand as `iam:session:prune` and daily as a tick on `IdentityMaintenanceSchedule`.
      **Subject erasure never waits for it:**
      `FulfilIdentityErasure` hard-deletes the subject's rows through `PurgeUserSessions` inside the erasure
      transaction, and because there is no physical FK on `user_id` that explicit deletion is the whole of what
      the erasure owes here — nothing cascades. `DbalSessionPersonReferences` is the detective half, reporting any
      `user_id` in this table that no live identity backs — though only while the row survives, so a broken
      erasure path stops being reported once the sweep removes its evidence. **What a green build does not
      prove:** that the daily tick ever fired. Folding it into an existing schedule mints no transport, so
      `make php.lint.schedule-consumption` is satisfied by a transport that already existed and never sees the
      tick; one unit assertion is the only mechanical guard that it is registered at all, and nothing at any
      level observes that it _ran_ — the handler is silent on success, as both sibling prunes are. **Closing
      that** means an operational signal (a log line the prune emits, or an alert on the tick), not another
      test. **Concurrency, accepted:** the sweep is one unbounded `DELETE` with no ordering or advisory lock,
      unlike `audit_log`'s batched prune, so it can deadlock with the erasure transaction that deletes from the
      same table; one daily tick against an operator action, and the loser gets a retryable 503. **Deploy note
      (one-time):** native sessions minted **before** the registry
      shipped carry no `iamSessionId`, so the gate 401s them — a single forced global logout at the II-7 deploy
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
described in its own topic above or in an ADR, where a reader looking for _this_ question will not
find them. Closing one means striking it here **and** correcting whatever above describes the
mitigated state. Accepting one means recording who accepted it and against which customer.

- [x] **Post-auth open redirect through `?next=`, closed 2026-08-21.** `safeInternalPath` accepted a
      destination whose second character was a TAB, LF or CR and returned it unchanged, because
      `String.trim()` strips those three only at the **ends** while the WHATWG URL parser strips them
      **anywhere**. Measured against `new URL(v, "https://app.example/x")`: `/<TAB>/evil.com`,
      `/<LF>/evil.com` and `/<CR>/evil.com` each satisfied the guard's "a single leading slash not
      followed by a slash or a backslash" and each resolved to `https://evil.com/`. Live vector:
      `…/login?next=/%09/evil.com` — `URLSearchParams.get()` decodes `%09` to a raw TAB before the
      guard sees it, `safeHref` blocks only script-bearing schemes, and Next's router leaves the
      origin on a `mpaNavigation`. The redirect fired **after** a successful sign-in, which is the
      moment a "your session expired, sign in again" phishing page is most credible. The guard now
      resolves the candidate against a sentinel origin and compares — the authoritative check the
      pagination navigator already used — rather than enumerating shapes with a regex. Three
      whitespace cases are pinned in `pwa/tests/context/shared/navigation/domain/safeInternalPath.test.ts`,
      and the old regex reds them. **Residual:** `safeHref` is unchanged and still admits an absolute
      `https://` URL by design; it is `safeInternalPath` that a redirect target must additionally pass.

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
- [ ] **Backup object archives carry the dump's personal data and no longer expire on their own.** The
      paired backup wrote `objects-<stamp>.tar.gz` beside each dump, and its retention `find` was the only
      thing that ever deleted them. That surface was removed, so the pattern named an artifact nothing
      produces and it was retired — which means a host that ran the paired backup keeps those archives, with
      the same PII as the dump, until an operator sweeps them by hand
      (`docs/vps-deployment.md` § Backups → _Orphan object archives_). `backup-prod.sh` warns on every run
      when it finds any, so the obligation is surfaced rather than only documented; the sweep is not a
      one-off, because a host still on a pre-removal checkout keeps producing them. **Unchecked on purpose:
      it closes when the deploy has landed and the sweep has been run twice, `RETENTION_DAYS + 1` days
      apart.**
- [x] **GDPR erasure is not defeated by the Messenger transport tables** — closed for the one event that
      reached them. No erasure path touches `messenger_messages`: the `async` queue has neither TTL nor
      prune, and the `failed` queue is swept only by a 30-day retention window, so a queued message naming
      a person still outlives that person's erasure. The rule is
      now declarative: an "aggregate id alone" payload may ride a persisted transport only if the aggregate
      is not a natural person. Classification lives in `api/.persistent-transport-policy`, enforced by
      `make php.lint.persistent-transport`, which resolves each routing key to the events Messenger would
      really send — class parents, interfaces, namespace wildcards, a bare `'*'` and `#[AsMessage]` all
      route without naming a class, and a gate reading keys as class names would miss every one. Verify when
      adding an event: classify its aggregate, and do not route a person's aggregate off the request.
      **Two limits, stated so a green build is not read as more than it is:** the gate classifies the
      _aggregate_, not the payload — a non-person aggregate carrying a person's id (`Iam.Session`'s
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
      proves a deletion is _written_, never that a row _went_. Two consequences, both open. (1) Any subject
      erased before this shipped left its `membership.user_id` / `iam_invitation.invited_user_id` row behind,
      and nothing in the codebase would ever name those rows again — they are not migrated or swept here.
      (2) A future write path that creates a person-referencing row without going through the erasure chain
      reintroduces the residue silently. The sibling axis already answers this with
      `identity:gdpr:reconcile-subject-references`, whose docblock states the reasoning
      (_"divergence surfaced beats divergence assumed away"_); the equivalent join is not built. Its scope is
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
      `GDPR_ERASURE_EXECUTED` self-audit is written _after_, outside any transaction — a crash
      between them leaves the erasure done with no evidence of it, and the original id no longer
      matches anything, so a re-run falsely reports "nothing to erase". Accepted while the only
      trigger is a synchronous operator command (`audit-activity-log.md` D4). **Its revisit trigger
      fires** if #555 lands a second mutation statement, or the day a non-CLI trigger appears:
      route it through `TransactionManager`, never a raw DBAL transaction nested under
      `wrapInTransaction` (no `nest_transactions_with_savepoints` is configured).
- [ ] **A GDPR erasure CLI never answers its own confirmation.** All three (`identity:gdpr:erase-subject`,
      `bank-account:gdpr:erase-subject`, `audit:gdpr:erase`) call `SymfonyStyle::confirm()`, whose default a
      run that cannot be asked would otherwise take for an operator's answer — reporting `0` for an erasure
      it never performed, which a compliance job reading `$?` cannot distinguish from a completed one. Three
      guards, in the same order in each: refuse before asking when the run cannot be asked; re-read
      `isInteractive()` **immediately after** `confirm()`, because the question helper demotes the input
      rather than raising; and refuse a stdin a previous read already exhausted, which
      `QuestionHelper::doReadInput()` (`while (!feof(...))`) answers with the default without raising at all
      — reachable through the console's own single-alternative prompt for a mistyped command name, which
      drains a pipe whose last byte is not a newline. `--force` is the unattended path. **Residuals, none
      gated:** a command line the console cannot *bind* raises before `execute()` and exits `1`, which no
      guard here can reach; the guards are three copies with no gate holding them equal, and the deferred
      registry states why an AST gate on the re-read alone would not have caught the defect that produced
      them; and `--dry-run` still prints the matching row count to the same caller, so what the guards close
      is the exit code as an existence oracle, never the count as information.
- [ ] **An erasure count is read from the store, never minted by a type fallback.** Every Doctrine adapter
      running a DQL bulk statement had to narrow `AbstractQuery::execute()`'s declared `mixed`, and seven
      reached independently for `\is_int($affected) ? $affected : 0`. That fallback mints the one value its
      callers read as evidence: the count flows through `IdentityErasureResult` out of
      `identity:gdpr:erase-subject`, and `SessionRepository::deleteAllForUser()` promises a legitimate `0`
      for a subject with no rows — so a real zero and a fabricated one were indistinguishable to every
      caller, in the direction that looks safe. `AffectedRows::from()` narrows or raises, and refuses a
      negative for the same reason. The password-reset single-use guard joins it: reading a non-int as
      `false` kept the property but told a live token's holder it was spent. **Residuals, none gated:** the
      gate counts DQL statements against narrowings per file and so cannot see a guard call that is DEAD —
      an unused closure or an unreachable branch balances the arithmetic, and review is the only control on
      that direction; it never judges whether the count is CORRECT, so a statement missing a predicate
      passes; and it does not reach the DBAL family, where `Connection::executeStatement()` returns
      `int|numeric-string` and ten sites narrow it by hand with an `(int)` cast — `DbalKeystore::destroy()`,
      the crypto-shredding tombstone, among them. That cast converts rather than fabricates, which is why it
      is a residual and not the same defect, but nothing holds it there.
- [ ] **The sole active administrator cannot be erased.** Demotion is refused by the ≥1-admin
      invariant, self-erasure by its own guard, and no peer exists to erase them — so their right to
      erasure requires onboarding a second administrator first. Pre-existing and named in
      [`docs/adr/authorization-model-boundaries.md`](docs/adr/authorization-model-boundaries.md) D3;
      it becomes a real obligation the moment a single-administrator installation has a customer.
      **Mitigated, not closed:** [`docs/deployment-guide.md`](docs/deployment-guide.md) § _Provisioning
      administrators_ now instructs the operator to onboard a second administrator at install time, and
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
      `scheduler_worker` to `replicas: 1` and says so at the pin.
      **Half of it is now enforced, and the half that is not is the reason this stays open.**
      `ScheduleConsumptionGateTest` refuses any of the three root compose files giving that consumer
      more than one replica, and refuses `compose.prod.yaml` declaring none — so a duplicated clock
      _committed to the repository_ is a red build. It cannot see one asked for at the command line:
      measured, `docker compose up -d --scale <svc>=2` leaves two containers running for a service
      declaring `replicas: 1`, exit 0, exactly as for one declaring none. Compose treats the value as a
      default, never a ceiling. Closing this therefore still means a scheduler lock (`symfony/lock`,
      declined in #261 with that asymmetry as the recorded cost) or a deployment-side check that
      refuses to scale the service.
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
- [x] **`failOnDeprecation` was structurally blind in CI — fixed 2026-08-12.** A deprecated _class_
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
- [ ] **A stolen session can deny the owner a credential _rotation_, but not an _eviction_.** Both budgets a
      session holder can reach are keyed by something they already have: `password_change_per_identity`
      (10 / 15 min, a visible 429) by the identity itself, and `password_recovery_per_email` (5 / hour, whose
      exhaustion is **silent by contract**, so the owner meets the uniform 202 and no email arrives) by the
      address `GET /me` hands them. Neither one is the security objective. **Eviction is, and it has a path no
      budget gates:** `POST /sessions/revoke-others` carries no limiter of any kind — every throttle in the
      repo lives in `Iam/Identity` or `Iam/Invitation`, none in `Iam/Session` — needs only a live session, and
      ships in the PWA as _Active sessions_. The owner's route to a live session is untouched: the credential
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
      [#602](https://github.com/sergio-salcedo-dev/ERPify/issues/602), not here:** an attacker who _also_
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
- [x] **The security stack's four person-data carriers do not reach the buffered application log.** The
      console `command` key is a fifth reach, closed separately and by a separate rule — see the entry below
      for [#788](https://github.com/sergio-salcedo-dev/ERPify/issues/788). The security stack
      names the person on every authenticated request: `ContextListener` logs `username =>
    getUserIdentifier()` at DEBUG (and here that identifier IS the email — `SecurityUser::getUserIdentifier()`
      returns `$this->user->email()`), on login `AuthenticatorManager` logs the token OBJECT, whose
      `__toString()` spells the address out again, and a session token the firewall cannot use is logged whole
      under `received` — on one branch the raw SERIALIZED token, address and password hash together. All of
      them sit behind prod's `fingers_crossed` handler at `level: debug`, and a request is about seven records
      into a fifty-record buffer, so any 5xx flushes them to `php://stderr` — the Docker json-file driver no
      compose file gives a rotation, a TTL or an owner of erasure. Closed by
      `Shared/Monitoring/Infrastructure/Monolog/PersonDataRedactionProcessor`, a **key** rule over four carriers
      (`username`, `impersonator_username`, `token`, `received`), replacing the value whole with `REDACTED` —
      whole rather than parsed, because a `TokenInterface` is `Stringable` and Monolog's formatter spells it out
      downstream of every processor. Logger-scoped, so it runs ahead of the buffer AND ahead of the
      handler-scoped `PsrLogMessageProcessor` that interpolates the message. Everything an incident needs
      survives: `provider`, `authenticator`, `firewall_name`, `token_class`, the message, and `exception` —
      untouched on purpose, since `HttpCodeActivationStrategy` reads it to decide what flushes at all.
      **Exact match, not the substring rule `RedactionDenylist` uses for response bodies:** measured, a
      substring on `token` destroys `token_class`, a class name holding no person datum.
      **What it does not cover**, each measured rather than assumed: the record's MESSAGE (which is NOT quiet —
      see below); a carrier nested inside another value or riding in `extra`; and the `doctrine` channel, which
      logs every statement's bound parameters — the login `SELECT … WHERE t0.email = ?` carries the address
      under a nested `params` key. That last one is bounded by the DBAL logging middleware, read from the
      compiled containers rather than from the option that configures it: `Doctrine\DBAL\Logging\Middleware`
      appears in the test container and appears **nowhere** in the prod one, so it is a property of the
      `kernel.debug` and not of the environment name — `doctrine.dbal.logging` defaults to `%kernel.debug%`
      and `config/packages/doctrine.yaml` never sets it, so one `APP_DEBUG` on the prod stack turns that
      carrier on into the same unrotated sink. `compose.prod.yaml` sets no `APP_DEBUG` at all and relies on
      Dotenv's `'prod' !== $env` default; pinning it there would make the claim structural instead of
      default-dependent, and is not done here. The console `ErrorListener`'s `command` key is deliberately
      NOT in this carrier set: a whole-value sentinel would have destroyed the one diagnostic that line
      carries, so it earned a rule of its own — `ConsoleCommandRedactionProcessor`, which keeps the command
      NAME and drops the argv after it. The
      MESSAGE is reachable by a processor in exactly one shape, and it is that one: `{command}` is
      interpolated by the handler-scoped `PsrLogMessageProcessor` DOWNSTREAM of every logger processor, so
      redacting the context value redacts the interpolated slot with it. Every other message-side vector
      stays out of reach — identifiers `sprintf`-composed into a message before Monolog sees it
      (`CurlHttpClient` writes `Request: "%s %s"` on the buffered `http_client` channel), and the `{message}`
      slot beside `{command}`, which carries the throwable's own text.
      **Enrolment is the whole control, and its two halves are verified in two different containers.** In
      test, `PersonDataRedactionArrivalTest` drives a real login plus a real
      authenticated request and asserts no swept record reaches the prod formatter carrying the address, and
      separately that every one of the container's channel loggers carries the rule — behaviour alone is green
      for any channel a request happened not to write on. In **prod**, the container compiled by
      `make php.lint.prod-container` is the evidence, and it is read rather than assumed: 14 channel loggers,
      14 `pushProcessor` sites for this rule, `monolog.logger.security` and `monolog.logger.request` among
      them, on the **logger** and not on a handler — so the record is redacted before `FingersCrossedHandler`
      buffers it. **The count is a reading, not a gate** — nothing recomputes it, so reproduce it with
      `make php.lint.prod-container` plus a grep, over a PURGED `var/cache/prod` (a warmup on a stale cache
      answers with the previous build). What IS gated is the property that lets the test container stand in
      for prod: the class carries no `#[When]`/`#[WhenNot]`, asserted in `PersonDataRedactionArrivalTest`, so
      it cannot be conditioned out of production while every test stays green. Unenrolment takes BOTH
      mechanisms, measured on four arms with the cache purged each time: tag + autoconfiguration → 14 sites;
      tag with `autoconfigure: false` → 14; no definition at all → 14; tag removed AND `autoconfigure: false`
      → 0 — and only that last arm makes the test formatter emit the address inside the login record's
      stringified token.
- [x] **The console error listener no longer writes argv under `command`**
      ([#788](https://github.com/sergio-salcedo-dev/ERPify/issues/788)). `symfony/console`'s `ErrorListener`
      logs the full argv on two paths, measured against the installed source: `:46` writes
      `'command' => (string) $event->getInput()` at CRITICAL on a `ConsoleErrorEvent`, and `:67` writes the
      same string at DEBUG on ANY non-zero exit. The `console` channel is inside prod's `fingers_crossed`
      handler (it excludes only `deprecation` and `observability`) and CRITICAL is above
      `action_level: error`, so that record does not merely sit in the buffer — it FLUSHES it to
      `php://stderr`.
      **Which invocations reach that CRITICAL path is narrower than "a failing command", and the first
      version of this entry got it wrong.** An adversarial pass measured that every `#[AsCommand]` here
      taking person data catches `Throwable` and returns `Command::FAILURE` — `EraseIdentitySubjectCommand`,
      `CreateInitialAdministratorCommand` and `CreateInvitationCommand` all do — so none of them raises a
      `ConsoleErrorEvent`, and their own failures reach only `:67` at DEBUG, which buffers without activating
      and is DISCARDED by `FingersCrossedHandler::flushBuffer()` when no `passthru_level` is configured (this
      deployment configures none). The live producer of `:46` is an invocation the console cannot BIND — an
      unknown option, a wrong arity, a mistyped command name — which raises outside the command's own `try`
      and carries whatever the operator typed. `identity:gdpr:erase-subject <uuid> --typo` puts a person's
      identifier into the record of the erasure that identifier was to end;
      `organization:administrator:create <email> <password> --typo` puts a password IN CLEAR beside the
      address it belongs to. The DEBUG path is the second producer and needs no error at all: it reaches the
      sink whenever something else in the same process activates the buffer.
      **The argv-carrying commands are not enumerated, and that is the design** — the rule is structural, so
      it covers a command nobody listed. For the record, the ones taking person-valued argv today are
      `identity:gdpr:erase-subject`, `bank-account:gdpr:erase-subject`, `audit:gdpr:erase`,
      `iam:invitation:create`, `iam:invitation:resend`, `iam:invitation:revoke` and
      `organization:administrator:create`. Closed by
      `Shared/Monitoring/Infrastructure/Monolog/ConsoleCommandRedactionProcessor`, which reduces the value to
      the command NAME plus a `REDACTED` sentinel and is logger-scoped, so it runs ahead of the buffer and
      ahead of the handler-scoped `PsrLogMessageProcessor` that interpolates `{command}` into the message.
      **By STRUCTURE, not by enumeration**, and that is the load-bearing choice: a list of sensitive command
      names reads as higher fidelity and is the shape that already failed twice here — Caddy's access-log
      `query` filter matched parameter NAMES and let nine spellings of a value through, and #389/#803 closed
      the same class. A command added later is in clear by default under a list, and nothing reds.
      **What it costs** is argv: an operator can no longer tell from this log which subject an erasure was run
      against. `audit_log` is where that question belongs — it carries actor and resource ids and, unlike this
      sink, has an owner of erasure. **Declared degradation:** an invocation LEADING with a global option
      (`-v <command>`, `--env=prod <command>`) loses the name too, since locating it past an option needs the
      command's own input definition, which a processor does not have and must not guess; measured over this
      repository the shape IS invoked, once: `api/frankenphp/docker-entrypoint.sh` runs `php bin/console -V`,
      the first console call the prod container makes (`make sf` and both compose `command:` arrays spell
      `bin/console <command>` first, and `make/php-quality.mk`'s `cache:clear --env=prod` puts its option
      after the name). That invocation carries no argument at all, so the failure direction there is a
      redacted line rather than a leaked one. **Residuals, none of which this rule reaches.** The argv is also in the host
      PROCESS LIST, so a password passed positionally is disclosed to every local process regardless of this
      rule. The throwable's own text is untouched: measured through a real Monolog pipeline, a value the
      console refused survives three times in the same record — `context.message`,
      `context.exception.message` and the `{message}` slot of the record message — which is why the heading
      above says "under `command`" rather than "no longer writes its argv". And the processor's command-name
      shape check still admits a value glued to the name using only characters a command name may contain: a
      bare uuid after a `-` or a `:`. It cannot carry an address (`@` and `.` are refused) or a password with
      any symbol in it. Closing that last spelling means matching against the REGISTERED command names rather
      than a shape, which couples the processor to the console registry — recorded as follow-up rather than
      done blind. `organization:administrator:create` keeps the password OPTIONAL with a hidden prompt and says so
      in the argument description; making it prompt-only was weighed and declined here because the e2e seed
      (`make/pwa.mk`) invokes it non-interactively. Gates: `make php.lint.log-carriers`
      (`ConsoleCommandCarrierGateTest` — the listener still writes the carrier, and the processor is still
      tagged `monolog.processor` with no `handler:` scope) plus `ConsoleCommandRedactionProcessorTest`.
- [x] **`console` and `messenger` stay INSIDE the buffered handler, and that is the decision, not an
      oversight** ([#804](https://github.com/sergio-salcedo-dev/ERPify/issues/804)). Unlike the API request
      path — whose 4xx line is `warning`, below `action_level`, so it buffers without activating — both
      channels have producers at levels the handler ACTIVATES on. Enumerated against the installed sources:
      on `console`, the error listener's CRITICAL, carrying `exception`, the throwable's `message`, and
      `command`; on `messenger`, exactly one CRITICAL, in `SendFailedMessageForRetryListener`, when an
      envelope is dropped after its last retry, carrying the message CLASS NAME, the transport `message_id`,
      the retry count, and the throwable plus its message. `Worker` and
      `SendFailedMessageToFailureTransportListener` log at INFO alone, so neither activates anything.
      **The message PAYLOAD is not among the carriers** — the retry listener binds the message object and puts
      only `$message::class` into the record — which matters because for an event about a person the aggregate
      id IS the personal datum. So the only person-data carrier either channel had was `command`, closed
      above. **Excluding either channel was the obvious fix and is wrong twice over:** it would remove every
      command and worker failure from the deployed log, which is the only place an operator learns a consumer
      is dying, and it would not close the argv leak either — the DEBUG path writes the same string, and it
      reaches the sink whenever anything else in that process activates the buffer, so the record would be
      lost rather than redacted. (That last clause is conditional and is stated as such: on its own a DEBUG
      record is discarded, since the handler declares no `passthru_level`.)
      **Residual, undefended by construction:** `error` and `exception` carry a throwable's own message on any
      channel, so a throwable composed from a person datum reaches this sink through them. That is the same
      class as residual four below and has no rule in this repository. Gate: `make php.lint.log-carriers`
      (`BufferedChannelAmplificationGateTest`). It pins both channels as still buffered — reading the
      `channels:` key in BOTH forms, since rewriting it as an allowlist (`["request", "security"]`) removes
      them while a denylist-only check stays green — derives the producer universe from the `monolog.logger`
      tags in the installed vendor configuration rather than keeping a list by hand, refuses the PSR-3
      `log($level, …)` form outright because no per-level matcher can classify it, and asserts the retry
      listener's log context as a WHOLE literal rather than denylisting two payload spellings. Every one of
      those four is a false green an adversarial pass demonstrated against the first version.
- [ ] **The container log sink is bounded in SIZE but still has no TTL and no erasure path**
      ([#805](https://github.com/sergio-salcedo-dev/ERPify/issues/805)). No compose file declared a `logging:`
      block, so every container ran Docker's json-file driver at its unbounded default: any record reaching it
      outlived every other retention control this application has — the `audit_log` prune, the
      `messenger_messages` prune, the GDPR erasure use cases — each of which has an owner, while this had
      none. Every service now declares `driver: json-file` with `max-size: "10m"` and `max-file: "5"`, capping
      each container at ~50 MiB. The values are LITERALS, for the reason `deploy.replicas` is a literal on
      `scheduler_worker`: a bound an env file can widen is not a bound. **What is closed is unbounded growth,
      and nothing more.** Rotation evicts by VOLUME, so a busy deployment reaches a bounded window of hours
      while an IDLE one keeps its oldest line indefinitely — there is still no age-based expiry, and nothing
      in `FulfilIdentityErasure` can reach this sink. A person id that lands here can still outlive the
      erasure the application confirmed to the subject, which is why this entry stays unchecked. Closing it
      needs a different mechanism — a log driver whose retention the erasure path can act on, or shipping to
      a sink that has one — and is deliberately not attempted while there is no production deployment and no
      customer, mirroring how the mail-boundary residuals are accepted. Gate:
      `make php.lint.log-retention` (`BoundedContainerLogRetentionGateTest`), which resolves the three root
      compose files the way Compose merges them, refuses an interpolated bound, refuses a top-level `include:`
      (whose services the sweep cannot see — measured: one `include:` line added an unbounded `otel_collector`
      to `docker compose config` with the gate reporting green), and pins the service set so a file that
      stopped declaring services cannot pass by having nothing to check. A green proves the DECLARATION only:
      nothing here reads the host daemon, and nothing sees a container started outside these files.
      **A second producer writes into the same sink and no rule here touches it.** The bound now covers every
      container, `database` included — but the CONTENT axis stops at Monolog. PostgreSQL's default
      `log_min_error_statement = error` writes the offending statement to the `database` container's stderr,
      and a unique violation carries `DETAIL: Key (email)=(someone@example.test) already exists`. A duplicate
      registration is enough to produce it. Not verified against a running stack (this was found by reading);
      recorded here rather than left for the next reader to rediscover.
- [ ] **A person's id still reaches the access log through the URL _path_, and it is accepted.** Caddy's
      access-log filter strips the query string and keeps the path, so a path segment is untouched by
      construction — and the application log's `request_uri` leaves the path alone by the same decision, so
      the residual is one residual across both logs rather than a difference between them. The producer is
      `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts`, which composes
      `/api/v1/backoffice/users/<uuid>`, where the uuid is the person's id. The sink has no owner of erasure:
      the compose files bound the driver by size alone, so it still has no TTL and no age-based expiry,
      and nothing in `FulfilIdentityErasure` can reach it. An id that lands there outlives the erasure
      the application confirmed to the subject. Closing it is not a wider `replace` but a different mechanism —
      a log-level rewrite of `uri`, a `logging:` driver whose retention the erasure path can act on, or no
      access log for `/api/*` at all. **Accepted for now:** there is no production deployment, and the
      query-side leak that _was_ closed is the one with volume — it fired on every keystroke of a filter,
      against one entry per user record opened here.
      **In the application log the same id is spelled twice in one record, and both spellings are this one
      residual.** Symfony's router listener writes `route_parameters` beside `request_uri`, and a route
      parameter IS a path segment: measured on the running stack,
      `GET /api/v1/backoffice/users/01a01aff-87d3-7902-a5f3-c986d20d7feb` produces
      `route_parameters.id = 01a01aff-87d3-7902-a5f3-c986d20d7feb` and a `request_uri` whose path ends in the
      same bytes. `PersonDataRedactionProcessor` therefore leaves `route_parameters` alone: redacting one
      spelling while the other stands in the same record removes nothing and costs an operator the route
      parameters. **This spelling is not covered by the acceptance above**, which was written about the path
      and Caddy's query filter; it needs its own sign-off. The declination is bounded rather than absolute,
      and the bound is prose: the two coincide for the deployed route table, whose every placeholder is a
      uuid, and `route_parameters` carries the DECODED value where `request_uri` stays encoded — so a route
      that comes to carry a person-valued placeholder with a percent-encodable byte makes `route_parameters`
      the only spelling, and nothing reds. `DeclinedRouteParameterCarrierTest` pins the other direction only:
      the day `RequestUriRedactionProcessor` starts redacting paths it reds, and `route_parameters` must then
      be added to `CARRIERS`. When the path closes, both close with it.
- [x] **A person's email, an account holder's name and an IBAN reached the access log through the `in`
      operator's array spelling** — closed, and closed by changing the mechanism rather than lengthening a
      list. The filter enumerated sensitive parameter names, and Caddy matches a name exactly with no
      wildcard, so `filters[N][value][]` and `filters[N][value][0]` — the spellings a list value takes —
      matched nothing. Measured against the running stack, the enumeration contained **one of nine**
      spellings of a value; a double-encoded key and a plain parameter name nobody had listed also passed.
      It was reachable through the API, not only by hand: `FieldMapping`'s default operator set granted
      `In` to every field that did not name its own, which included `email` on the user register and
      `holderName`/`iban` on bank accounts. Two changes, each falsified by provoking its red: Caddy now
      drops the query whole (`AccessLogQueryContainmentGateTest` sends nine spellings through the running
      stack, including one on a 2xx, and asserts none survives), and `In` is opt-in at `FieldMapping`
      (`SearchOperatorSurfaceGateTest`), so the four person-data fields that never used it no longer admit
      it while the bank name and short code, which nine scenarios in
      `api/features/backoffice/bank/search.feature` do reach with a list, declare it — that count is stated
      with the extraction that produces it at `DoctrineBankRepository::searchFieldMap()`, so a reader re-runs
      it rather than trusting it.
- [ ] **The access log's filter is overridable from the environment at three points, and nothing detects
      it.** `{$CADDY_GLOBAL_OPTIONS}` (`api/frankenphp/Caddyfile:7`), `{$CADDY_EXTRA_CONFIG}` (`:17`) and
      `{$CADDY_SERVER_LOG_OPTIONS}` (`:25`) are each documented options (`api/docs/options.md`). The last
      expands inside the `log` block **ahead of** `format filter`, so setting it to another encoder replaces
      every rule below it; the first two can introduce a global logger or a whole second site block carrying
      no `format filter` at all. Any of the three does it from a deployment, without touching this
      repository, with every gate in the tree green — the whole-query strip included. No test here can see
      it, because none reads a deployment's environment. **This is a bigger hole than any spelling the strip
      closes**, and it bounds what every other claim on this page about the access log is worth. Dev and
      test set the third deliberately, to point the log at a file the effect gate can read; that use sets
      the destination and leaves `format filter` alone.
- [ ] **Everything a client controls OUTSIDE the query string still reaches the access log in clear.** The
      strip covers `request>uri` from the first `?` **or `%3f`** — the encoded delimiter is matched
      case-insensitively because the literal-only pattern was measured letting `/api/v1/health%3Ffoo=<value>`
      through whole. Measured against the running stack, each of these still lands: the URL **path**
      (`/api/v1/backoffice/users/<uuid>`, the long-standing residual); a `;`-delimited path parameter
      (`/api/v1/health;p=<value>`); and **every request header except
      `Referer`**, which is the only one dropped (`User-Agent`, `X-Anything` and any other are logged
      verbatim; `Cookie` and `Authorization` are redacted by Caddy's own credential handling). So the
      statement "any client can force the deployment to retain a person's identifier here" is **still true
      after the strip** — the strip removed the axis a legitimate UI drives, not the axis a hostile caller
      drives. Closing it needs a header allowlist plus a path mechanism, or no access log for `/api/*`.
      **The accepted cost, stated rather than hidden:** the `Referer` delete is global to the site, while the
      leak it answers is confined to the back-office documents whose own URL carries a person id — the user
      detail route (`/backoffice/users/<uuid>`) and the audit screen's `?actorId=`/`?resourceId=`
      (`pwa/src/app/backoffice/users/[id]`, `pwa/src/app/backoffice/audit`). Every other request on this
      deployment — the PWA's own documents, static assets, `/.well-known/mercure`, and any `/api/*` call from
      a screen that names no id — now records no referring URL either. What is given up is the referring URL
      as investigative signal: an entry can no longer say which page a request came from, so a CSRF report
      cannot be corroborated from the log, a link arriving from a phishing page or any third-party host
      cannot be traced back to where it was published, and an incident timeline loses the navigation order it
      would otherwise reconstruct. Per-route filtering was not taken, and the file is the reason: the site
      declares ONE `log` block (`api/frankenphp/Caddyfile:20`) and its `format filter` carries no request
      matcher, so every rule inside it applies to every entry that logger writes. Scoping the delete to the
      two screens therefore means a second logger declared beside it — its own encoder and its own copy of
      every rule — plus a directive in the matched route to send requests there; two copies of a redaction
      rule set are free to drift, and the copy that drifts is the one nobody is reading. Weighed against a
      signal no investigation on this deployment has yet needed, the duplication loses; it is not impossible,
      and a deployment that comes to depend on `Referer` should reopen it rather than quietly re-add the
      header. **Nothing gates the trade-off itself.** `CaddyfileAccessLogRedactionGateTest` asserts the
      delete is PRESENT, which is the opposite direction — it would red on restoring the
      signal, and can say nothing about whether losing it site-wide was the right price. This paragraph is
      the only record.
- [ ] **The embedded Mercure hub logs subscriber topics in clear, on a logger this site's filter does not
      govern.** Measured: `GET /.well-known/mercure?topic=<value>` produces an access line reading
      `?REDACTED` **and**, on stderr, `http.handlers.mercure … "topics": ["<value>"]`. They are separate
      loggers, which is why the site's `format filter` reaches one and not the other.
      `api/frankenphp/Caddyfile` enables `anonymous` and `subscriptions` unconditionally, so this is
      unauthenticated. The application's own topics are bank and bank-account UUIDs, so normal operation
      leaks nothing — this is an injection channel into the same unrotated, un-TTL'd, unerasable sink, and it
      is pre-existing rather than introduced. Closing it needs a `log` override for the
      `http.handlers.mercure` logger, or dropping `subscriptions`.
- [x] **A person's address is declared unmarked in 27 parameter declarations outside the pinned mail
      surface — closed by #811, extended by its follow-up (both 2026-08-20).** `#[SensitiveParameter]`
      covered only declarations named `recipientEmail` (`SensitiveRecipientAddressGateTest` pins that set);
      the same value crossed this codebase unmarked under `$email`, `$to`, `$identifier`, `$emailIdentifier`
      and `$raw`. `PersonAddressParameterGateTest` replaces the name-keyed rule with a registry
      (`api/.person-address-parameter-policy`) keyed on SITE instead — every parameter or promoted property
      found carrying a person's address is classified `sensitive` (must carry `#[SensitiveParameter]`) or
      `excluded :: <reason>` (a reviewed, non-person value — an env-derived operational mailbox). This is the
      "definition a gate can evaluate" the previous entry said was missing: both `$emailIdentifier`
      parameters of `ReauthenticateDevice`/`ReauthenticateDeviceBestEffort` and the rest of #769's population
      are registered and marked, and the gate refuses a currently-declared `Email`-typed site with no line at
      all. The follow-up adversarial pass on #811 additionally found and closed
      `EmailAddressRedaction::apply()`/`blankEveryTokenHoldingAnAt()` (unmarked, real PII-carrying strings)
      and registered `SecuritySenderAddress::__construct($address)` as `excluded` (env-derived, same shape as
      `PlainTextNotificationMailer::$mailFrom`).
      **What stays open, by the registry's own header — a residual, not a reason this item is unclosed**: the
      string-typed population (the majority of the registry) was found by human adversarial review, not
      mechanically — there is no dataflow/taint engine in this toolchain that could soundly rediscover an
      arbitrary `string $foo` becoming a person's address, so a _new_ unmarked site still needs the next
      review pass; only parameters typed exactly `Email` are mechanically guaranteed a line. Also unreachable,
      same as before: vendor frames (`SmtpTransport::doRcptToCommand`), anonymous classes/closures/plain
      functions, and `getMessage()` (a separate axis — the mail boundary, `RedactingMailer`/`RedactingTransport`).
      `zend.exception_ignore_args = On` (`api/frankenphp/conf.d/10-app.ini`) still holds the line
      unconditionally today; the attribute is the narrower half that survives a change to that ini.
- [ ] **The Next.js container logs the full request URL, person ids included — measured in dev, unverified in
      prod.** The audit screen navigates to `/backoffice/audit?actorId=<uuid>&resourceId=<uuid>`, Caddy
      reverse-proxies the document to the PWA, and the container prints
      `GET /backoffice/audit?actorId=… 200 in 73ms` to stderr — the same json-file driver, bounded by size but
      with no TTL, that Caddy's access log uses, and one that Caddy's `query` filter cannot reach because it is a
      different process. **Caddy's redaction does not cover this**; anyone reading "the access log redacts
      `actorId`" as "no log holds it" would be wrong. `pwa/next.config.ts` also sets
      `logging.fetches.fullUrl: true`, widening what server-side fetches record.
      **Scope, stated with its limit:** that line is emitted by `next dev`, and production runs the standalone
      `node server.js` (`pwa/Dockerfile`), where it was **not** observed — but nor was it verified against a
      running production image, so treat prod as unconfirmed rather than clean. Closing it means a `logging:`
      driver with a retention the erasure path can act on, or keeping the ids out of the document URL — which
      the deep-link design deliberately does not do.
- [ ] **A recipient's address does not reach a log through a mail failure raised inside `api/src`, and five
      residuals around that boundary are recorded — four accepted, one re-opened.**
      `SmtpTransport::assertResponseCode()` quotes the server's reply verbatim, and the command whose reply
      fails on a rejected recipient is `RCPT TO:<address>` — so a refusal names the person, and every component
      that swallows that throwable and logs it, plus `ErrorDetailsStamp` in `messenger_messages` and the error
      reporter that reads throwables outside the logging stack, would hold an address no erasure path can
      reach. `RedactingTransport` decorates **`mailer.transports`** and translates every failure into
      `MailDeliveryFailed`, whose message is COMPOSED from a class name, an SMTP code, an RFC 3463 enhanced
      status and the origin file and line, which chains no `previous`, and whose
      `getDebug()` is empty by construction because the transport's own exception accumulates the whole SMTP
      conversation there.
      **The boundary is the transport and not `MailerInterface`, and that is measured rather than stylistic:**
      the compiled production container has three consumers of the transport collection and only one is the
      mailer — `MailerTestCommand` calls `send()` on it with no `try`, and Messenger's `MessageHandler` would
      do the same from the worker if `SendEmailMessage` were ever routed. That count describes today, not a
      property: the framework wires a fourth, the notifier's email channel, absent here only because no
      notifier is configured. Decorating the id rather than its consumers is what makes the count harmless —
      anything wired to the collection inherits the translation by construction. `MailerBoundaryEnrolmentTest`
      pins the collection itself for that reason, and both named consumers on top, plus the absence of a `When`
      or `WhenNot` condition that would remove the decorator from production while leaving every gate green.
      **Which path actually reaches a durable sink, stated because the two differ.** `MessageHandler` runs in
      the worker, which is PID 1, so its stderr is the json-file driver — bounded by size, still no TTL, no
      owner of erasure — and `ErrorDetailsStamp` would persist the message in `messenger_messages`. That is the path worth the
      control, and it is **live rather than latent**: an earlier reading called it latent because
      `SendEmailMessage` is unrouted, which is true of that message and not of the sink. Two paths reach it
      without it. `SendEmailOnBankChanged` handles `BankCreated`/`BankUpdated`, both routed `async`, and
      **re-throws** a mail failure so Messenger retries and finally stamps `ErrorDetailsStamp`. And the
      scheduled lockout notice reports through `SendAccountLockedEmailBestEffort`, bound in `services.yaml` to
      `monolog.logger.observability` — a channel the prod `main` handler excludes **by name**, so its stream is
      always on and no 5xx is needed to flush it. Before this translation, every rejected recipient on either
      path was written verbatim to the worker's stderr. `mailer:test`, by contrast, is run by an operator
      through `docker exec`, whose stderr is **measured not to reach the driver** (marker written to a
      container's stderr via `docker exec`: zero occurrences in `docker logs`); it lands in the operator's
      terminal. Its SEND is closed here regardless, because the boundary costs nothing extra to place correctly
      and the sink is a property of how the command happens to be invoked. Its ASSEMBLY is a different
      question, reachable from no boundary this repository can place, and it is residual two.
      **Assembly inside `api/src` is inside the boundary as well, and THAT half is closed.** `Mime\Address`
      refuses a non-compliant value with a message quoting it, and that throw happens while the message is being
      BUILT — upstream of any transport decorator, where the `*BestEffort` wrapper logs it raw. `RedactingMailer`
      is the single place `api/src` holds a MIME message: the four person mail paths hand it strings and it
      assembles and sends in one call, so the assembly throw becomes the same composed `MailDeliveryFailed` —
      class, origin and the NAME of the refused argument, with no reply code and no enhanced status, because
      nothing has spoken to a server (measured: `550-5.1.1` is a value `Address` refuses, and the status pattern
      reads `5.1.1` straight back out of the refusal, so a scan there would fabricate a diagnosis out of the
      rejected value). The argument name is carried because the origin cannot separate a deployment that can
      send no mail at all from one stored address being bad: all four of a refused `from`, an empty `from`, a
      refused `recipientEmail` and an empty `recipientEmail` are one parser refusing at one line of one vendor
      file. It costs no confidentiality — the name comes from a five-case enum and `from` is env-derived at
      every call site. It is structural rather than a habit: `MailAssemblyBoundaryGateTest` pins the set of
      `api/src` files allowed to name `Symfony\Component\Mime`, `Symfony\Bridge\Twig\Mime` or the
      `Symfony\Component\Mailer` component, so a second construction site — or a class holding the mailer or
      its transport directly — is an explicit edit rather than a silent reopening. Namespace prefixes rather
      than exact types, because the exact types were measured evadable one `use` at a time: a planted
      `TemplatedEmail`, `NotificationEmail`, concrete `Mailer` and `Transport\TransportInterface` each left the
      interface-exact gate green. What this replaces was a bound nothing watched: every form
      `#[Assert\Email(mode: STRICT)]` admits is also admitted by the `MessageIDValidation` that `Address` uses,
      an undocumented agreement between two vendor validators that no test pinned (pinning it was considered and
      declined — it would freeze an accidental implementation detail as though it were the contract) and that
      could not report its own expiry. **What it does not cover is a message assembled outside `api/src`, which
      is residual two, and a `MessageEvent` subscriber**: `Mailer::send()` dispatches one of its own between the
      assembly `try` and the transport `try`, so a subscriber mutating the message there raises outside both.
      Measured on the compiled container — `mailer.mailer` takes `messenger.default_bus` and `event_dispatcher`,
      so the dispatch happens; no subscriber in this tree touches a message today.
      **Residual one — the recipient in the console error listener's `command` field. CLOSED by
      [#788](https://github.com/sergio-salcedo-dev/ERPify/issues/788); the description below is what it was
      before.** `ErrorListener.php:46` logs `['exception' => …, 'command' => $inputString, 'message' => …]` at
      `critical`, and `$inputString` is the command line: measured, `bin/console mailer:test
      alice@example.test` yielded `mailer:test 'alice@example.test'`. Where the translation runs it cleans
      `exception` and `message`; it **cannot touch `command`**, because that field is the operator's
      invocation rather than the failure — which is why the closure came from a logger processor instead.
      `ConsoleCommandRedactionProcessor` reduces the value to `mailer:test REDACTED`, and because
      `PsrLogMessageProcessor` is handler-scoped it interpolates `{command}` downstream of that, so the
      record's own message loses the address in the same pass. The `console` channel is still inside the prod
      `main` handler and `critical` is still above its action level, so the line still flushes — what changed
      is what it carries, not whether it is written. **That argument once covered a second sink and did
      not hold there**: `sentry/sentry-symfony`'s `ConsoleListener` sets `extra['Full command']` to the whole
      argv line and captures it on `ConsoleErrorEvent`, so the address reached a third-party tracker with
      retention of its own and no erasure path. `RedactionDenylist` could not help — it is a rule about KEY
      names and the key is `Full command`. That half is **closed, not accepted**: `SentryEventScrubber` redacts
      addresses in `extra` VALUES, at any depth, the way it already did for `query_string`, `url` and `Referer`.
      What remains is the Monolog line, and closing that means not naming a person on a command line.
      **Residual two — a message built OUTSIDE `api/src` reaches no boundary, and `mailer:test` is the live
      instance.** `MailerTestCommand` builds its own `Email` and calls `to()` on the operator's argument, which
      is upstream of `RedactingMailer` and upstream of the transport decorator, so `Mime\Address` raises
      `RfcComplianceException` quoting that argument verbatim and nothing translates it. Measured before
      #788, `bin/console mailer:test alice-the-victim` produced ONE `console.CRITICAL` record carrying the
      value FIVE times across four fields — the record `message` twice, then `context.exception.message`,
      `context.command` and `context.message`. **Two of those five are now gone, and the other three are the
      residual.** `context.command` is redacted at the carrier and the record message's `{command}` slot is
      interpolated from it; `context.exception.message`, `context.message` and the record message's
      `{message}` slot are all the throwable's own text, which no processor here reads. That reduction is
      derived from the redaction rule plus the already-recorded handler-scoping of `PsrLogMessageProcessor`,
      not from a fresh run of the stack. The prod `main` handler excludes
      `["!deprecation","!observability"]` and not `console`, and `critical` is above its action level, so the
      record flushes to `nested` = `php://stderr`. Sink as in residual one: an operator's terminal when the
      command is invoked through `docker exec` (measured: zero occurrences in `docker logs`), and the json-file
      driver with a size bound but no TTL and no owner of erasure when it is invoked any other way. Its Sentry half
      is **closed**: `SentryEventScrubber` now redacts `exception.values[].value` — the field the tracker titles
      an issue with — and the event's own message, alongside the `extra` pass. Closing the Monolog half means
      either not naming a person on that command line, or shadowing the vendor command with one that validates
      its argument before an `Email` sees it; the second is declined here, because a debugging command is a thin
      reason to own a vendor command's lifecycle. The notifier's email channel has the same shape and is absent
      only because no notifier is configured.
      **Residual three — `CreateInvitationCommand` prints the recipient to stdout** on both the success and the
      send-failure branch. Its Sentry half is closed with residual one's — the address rode the same
      `Full command` extra — and what remains is stdout, whose sink is the operator's terminal.
      **Residual four — a vendor exception message is a general carrier of caller data.** A database driver
      quoting a violated unique value is the same shape. No rule in this repository covers that class; closing
      it is a survey with no bounded scope, and only the mail surface is closed here.
      **Residual five — `RoundRobinTransport` logs a raw throwable**, but only if something constructs it with
      a logger. Measured: `Transport.php:154,157` build it as `new $class($args[, $period])` and never pass one,
      so Symfony's own wiring leaves it on the `NullLogger` default — a `failover://a||b` DSN does **not** arm
      that line. Reachable only by constructing the transport by hand.
      **Accepted 2026-08-18 (Sergio):** there is no production deployment and no customer. Residuals one, three
      and five stand on the arguments measured above — an operator-invoked **terminal** for one and three, once
      the tracker half of that sink was measured and **closed rather than accepted**, and Symfony's own wiring
      never arming five. Residual four is deferred knowingly rather than dismissed: the carrier is real and a
      database driver quoting a violated unique value has the same shape; what is declined is the survey that
      would bound the class, not the risk.
      **Residual two is RECORDED rather than accepted, and its history is the reason it is spelled out.** It was
      briefly carried as closed, on a claim — "the one place a MIME message is built" — that was true of
      `api/src` and stated without that scope, so the deletion of a residual rested on a boundary the vendor
      command walks around. It reopens here narrower than the one it replaces: not "construction happens before
      the boundary" (that is closed for every path this repository writes) but "construction outside `api/src`
      is not reachable from inside it".

      **Accepted 2026-08-20 (Sergio):** same basis as residuals one, three and five — no production deployment
          and no customer — plus one fact specific to this residual, verified rather than assumed: `mailer:test` has
          no automated caller anywhere in this repository. It is absent from every compose file, every GitHub
          Actions workflow, and every `Makefile`/`*.mk`/script under the tree; every reference to it is in a test or
          in this document. The exposure therefore requires an operator to type a real person's address as this
          command's argument, which is the same operator-discretion basis residuals one and three already stand on.
          **Owning the vendor command's lifecycle to close it is declined** (SRP/DIP: a debug command is a thin
          reason for `api/src` to take over a framework command's validation and future compatibility — see the two
          remediations weighed and declined in the paragraph above). **Expiry: re-assess before the first production
          deployment or the first customer, whichever comes first** — same trigger as the repository's public-posture
          item below, and the same trigger that unwinds residuals one, three and five.

- [x] **A person's identifier reached Sentry inside a request URL, on two surfaces, closed 2026-08-21.**
      `scrubSentryEvent` parsed `event.request.url` and `query_string` parameter-by-parameter but
      sent every other structured surface through the key-based `scrubDeep` only, and a request URL
      is a **string** under a key no denylist will ever hold.
      **Surface one — breadcrumbs.** The SDK adds one per `fetch` and one per history entry, carrying
      the URL under `data.url` / `data.from` / `data.to`.
      **Surface two — spans, and this is the one that shipped further.** `sentryInitOptions` wires the
      same function to `beforeSendTransaction`, `browserTracingIntegration` is a default integration
      with `traceFetch: true`, and `getFetchSpanAttributes` writes the raw URL to `url`, `http.url`
      and `url.full` plus the bare query to `http.query` — four attributes, on 20 % of traced fetches
      in production. The span NAME is sanitised by the SDK, which is exactly what made this easy to
      miss. `contexts.trace.data` carries the same attributes. Measured before the fix: a search
      filtering on an email came back from `scrubSentryEvent` **identical**, the address in clear
      three times over, while the same value inside a breadcrumb on the same event came back
      `REDACTED`. The vocabulary was already right; it was never pointed at that surface.
      Same class as the access-log defect closed in #389/#803, and the same reason it matters:
      Sentry has retention of its own that no erasure path reaches, so an identifier that arrives
      outlives the erasure the application confirmed to the subject.
      The URL pass now runs over **every** structured surface — `extra`, `contexts`, `user`,
      `breadcrumbs`, `spans`, `tags`, and the `request` sub-objects (`data` / `headers` / `cookies`)
      — after the denylist pass, rather than over a named list of the ones believed to carry URLs;
      naming the surfaces is what produced this defect. Values are matched by **shape** (`https?://`,
      `//` or `/` with a `?`, or a bare leading `?` for `http.query`) rather than by key name, because
      a key list is what failed for this class twice. Pinned by rows in
      `pwa/tests/.../scrubSentryEvent.test.ts`, each falsified by mutating the source and watching it
      go red.
      **Residual one — free text is out of scope, and this is narrower than it first reads.** A
      breadcrumb's `message`, `event.message` and a captured `Error.message`/stack are not rewritten,
      so a URL quoted inside prose reaches the tracker intact. This is the same scope the module has
      always kept, matching the API scrubber. A `console` breadcrumb's `data.arguments` is **not** in
      this residual — it is an array under `data` and the pass walks it.
      **Residual two — a URL embedded MID-string is not seen**, since the shape test reads the start
      of the value. A value that *is* a URL is covered; a value that *mentions* one is not.
      **Residual three — two spellings that are shapes and still escape.** A relative URL with no
      leading `/` (`api/audit?actorId=…`) and a query living inside the fragment
      (`https://app/x#/route?actorId=…`, where `scrubUrl` splits on `#` first) both carry a query and
      both pass through. Neither is produced by this application today — `browserApiBase()` returns
      `""`, so every request this transport issues starts with `/`, and nothing here uses hash
      routing — but a third-party script or a future relative fetch would leak. Leading whitespace is
      a third, hand-authored only.
      **Residual four — a green proves the transformation, never the sink.** These are unit rows over
      synthetic events; nothing here observes what a running SDK attaches, and `beforeSend` /
      `beforeSendTransaction` are the only interception points, so an SDK field outside the surfaces
      enumerated above is untouched by construction.
      **Accepted 2026-08-21 (Sergio):** residuals one to four, on the same basis as the access-log
      residuals above — no production deployment and no customer — and on the narrower fact that all
      four require an identifier to travel somewhere the shape rule does not read, rather than under
      a name the vocabulary failed to enumerate, which is the direction that actually shipped.
      **Expiry: re-assess before the first production deployment or the first customer**, whichever
      comes first.

- [ ] **Image bytes: what the storage root guarantees, and the four things it does not.** Residuals of
      `Shared/Images`, listed together because each is invisible from the others.
      **One — the root is provisioned by the deployment, conditionally, and that condition is the control.**
      `compose.yaml` mounts the named volume `image_storage` at `/app/storage` for both `php` and
      `messenger_worker`, and the entrypoint creates `<STORAGE_LOCAL_PATH>/images` **only when that path is
      genuinely a mount point** (its device differs from its parent's). The application refuses to invent
      the root: `flysystem.yaml` sets `lazy_root_creation: true` and the adapter guards the root's existence
      before every operation including the write — and it is the guard, not the flag, that delivers this;
      the flag only defers the library's own `mkdir` to the first write. Measured, that substitution is not
      a slow leak but an immediate one: with the root absent every `delete()` answers success, so the
      application reports erasures of bytes that are somewhere else entirely. A deployment that drops the
      volume therefore fails loudly on every image operation instead of writing into a layer that dies with
      the container. **What still has to be verified at deploy time is that the volume is mounted at all** —
      nothing here reports a deployment that simply never uses images.
      **Two — a lost deletion request is silent and permanent.** `ImageDeletionRequested` is queued; if it is
      never consumed, or dead-letters and ages out of the 30-day `failed` retention, the bytes and the row
      stay alive indefinitely with no monitoring on that axis. **How easily it dead-letters is the half that
      was never measured**: no `retry_strategy` existed anywhere in `api/config`, so the whole `async`
      transport ran on Symfony's default of three retries at 1 s, 2 s and 4 s — a storage outage longer than
      seven seconds was enough. The transport now declares ten attempts over about three hours, which makes
      the residual rare rather than closing it; the 30-day prune destroys the request either way, and
      `event_store` cannot re-dispatch it because the consumer is a message handler and not a projector. There is deliberately no reconciliation
      between rows and stored objects: the bookkeeping that would find an orphan is the same bookkeeping the
      module refuses to build, so nothing can distinguish "never asked for" from "asked for and lost". A
      second latent case joins it the day a consumer adds a foreign key into `image`: the row deletion would
      then fail identically on every retry, dead-lettering with the row alive and the bytes already gone.
      **Three — an orphaned object is the likeness itself, and nothing can ever find it again.** A store that
      succeeded followed by a persist that did not leaves canonical bytes whose identifier was handed to no
      caller, with no row and no reference. For an avatar those bytes are the person's picture, not an opaque
      id — this is the residual with the strongest personal-data content of the four, and the only one that
      is permanently unerasable **by construction** rather than by an absent control: with no bookkeeping,
      no query can enumerate them. Bounded by the failure rate, and by nothing else. (A write the substrate
      corrupted is *not* in this bucket: the adapter removes an object its integrity check refused.)
      **Four — `event_store` keeps the `ImageId` for ever, and routing changes nothing.**
      `PersistDomainEventMiddleware` appends every dispatched event with its real `aggregate_id` before
      Messenger picks a transport; identity erasure rewrites by the value of the **subject's** identifier,
      which an `ImageId` is not, so the row survives. Note that no registry in this repository will ever ask
      for an owner here: `api/.person-reference-policy` classifies a column by whether it holds a natural
      person's identifier, and an image id is not one — so a future `User::$avatarImageId` gets a
      `non-person` line and no `PersonReferenceSource`. The obligation is the consuming epic's, and it has
      no detective control. Recorded in
      [`docs/adr/image-deletion-signal-transport.md`](docs/adr/image-deletion-signal-transport.md) D3, which
      also states what classifying `Shared.Image` as `person` does **not** buy — it forced the decision into
      a diff, and nothing more. Closing two and three is a reconciliation the consuming epic would have to
      justify against the no-bookkeeping decision; closing four is that epic's too, since only a consumer
      knows whether a given image denotes a person.

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
