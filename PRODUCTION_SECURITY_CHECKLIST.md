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
      generated (`openssl rand …`), not reused from dev. **Rotating `APP_SECRET` later silently unmatches every
      stored evidence idempotency key** (HMAC-SHA256 under `kernel.secret`): a client retrying an upload across
      the rotation files the same evidence twice — a duplicate, never a loss. Recorded in §7.
- [ ] `AUDIT_KEK` (audit crypto-shredding key-encryption key) is used as **raw 32-byte**
      key material — `make prod.env.check` refuses any value that is not exactly 32 bytes,
      and the app throws `InvalidKek` on the first audited write if one gets past it.
      Generate a printable 32-byte key with `openssl rand -base64 24` (24 random bytes →
      32 base64 chars, no padding); `-hex 32` (64 chars) or `-base64 32` (44 chars)
      produce the wrong length. Freshly generated, not reused from dev.
      Custodied **outside the app** (env / secret manager), never beside the DEKs it
      wraps and never committed. Destroying a DEK (subject erasure) is irreversible by
      design. KEK rotation is **not yet automated**: rotating `AUDIT_KEK` in place makes
      every existing DEK unreadable, so a batch re-wrap tool is a prerequisite (not yet
      shipped) — do not rotate the live KEK until it exists. Required in prod: checked by
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
      `NEXT_PUBLIC_APP_ENV`, `NEXT_PUBLIC_SENTRY_DSN` and
      `NEXT_PUBLIC_SENTRY_ENVIRONMENT` (a tag) are allowed; the
      `pwa/tests/next-public-env-allowlist.test.ts` guard (in `make pwa.test.unit`)
      fails the build on any other. The Sentry DSN is intentionally public
      (write-only, browser-embeddable); the real Sentry secret is
      `SENTRY_AUTH_TOKEN`, never `NEXT_PUBLIC_`.
- [ ] Sentry source maps are **uploaded, not published**. Upload is on only when
      `SENTRY_AUTH_TOKEN` is set at build time together with `SENTRY_ORG` —
      or without it for an org-scoped `sntrys_` token, which carries its
      organisation; the project slug defaults to `erpify-pwa-${NEXT_PUBLIC_APP_ENV}`
      so one image cannot ship its maps to the other environment's project.
      **Client maps** exist only while upload is on (the SDK turns
      `productionBrowserSourceMaps` on for a Turbopack build), land in
      `.next/static` — served at `/_next/static` — and are deleted after the
      upload, succeeded or not, by the SDK's post-compile hook, which a
      Turbopack build runs unless `useRunAfterProductionCompileHook` is `false`.
      `deleteSourcemapsAfterUpload: true` in
      `pwa/next.config.ts` is a **pin, not a switch**: the SDK already applies it
      when it turns client maps on, and writing it makes a changed default or a
      casual removal a visible change rather than a silent republish. Four
      settings would genuinely publish them and must stay out:
      `filesToDeleteAfterUpload`, which **overrides** the flag outright so a
      narrow glob serves everything it does not name;
      `useRunAfterProductionCompileHook: false`, which keeps the maps and drops
      the hook that deletes them; `unstable_sentryWebpackPluginOptions`, whose
      `sourcemaps` the SDK spreads last and so replaces the deletion glob; and
      `productionBrowserSourceMaps: true`, which generates maps in a build with
      upload off, where nothing deletes them. **Server maps** are emitted into
      `.next/server` on every production build and the SDK's deletion covers `static/**` only, so the standalone
      output carries them. A step after the build (`find .next/standalone -type f -name '*.map' -delete`) removes
      them before the `prod` stage copies the tree, and after the upload, which runs inside the build. Measured on
      a real build: 75 maps, 300 KB, and the standalone server boots and answers 200 without them. The
      token reaches the build as a **BuildKit secret**
      (`--mount=type=secret,id=sentry_auth_token`), never as a build `ARG` or an
      `ENV` — `docker history` prints args, `docker inspect` prints env, and this
      token grants *write* access to the Sentry project. An absent or empty
      mount — or a personal token with no `SENTRY_ORG` — leaves upload off and
      says so in the build log, while a broken one fails the build; the exact
      cases are listed once, in `docs/rules/security.md` → "Build-time secrets",
      and run by `pwa/tests/read-sentry-token.test.ts`. The upload
      step itself runs with `silent: false`, so a refused credential or a failed
      upload is printed rather than swallowed. Gated by
      `pwa/tests/sentry-sourcemap-exposure.test.ts`; a green proves the
      declarations, never what a real build emits, and never that a CDN or a
      workflow artifact is not serving a copy from somewhere else.
- [ ] Associating Sentry releases with commits is **opt-in** on
      `SENTRY_REPOSITORY`, and it needs the Sentry **GitHub integration**, which
      is a third party granted read access to the repository's code and history.
      Install it only on this repository, never on the whole account. The
      release name is the commit SHA `make` exports (`make/config.mk`), which is
      public by design: it is inlined into the client and server bundles and
      set in the API containers' runtime environment (the shipped `pwa` image
      carries no such `ENV`; only its builder stage did). With
      `SENTRY_REPOSITORY` empty, nothing is associated: the plugin's `auto`
      fallback reads a `.git` the build context does not contain.
- [ ] Sentry events are scrubbed before send: every `dataCollection` category set
      explicitly to its restrictive value (an unset one is a grant in SDK 11), the
      identity axes filtered from query params at the source, a `beforeSend`
      denylist scrub in parity with the API's `SentryEventScrubber` and the same
      scrub over streamed spans in `beforeSendSpan` (`scrubSentryEvent` /
      `scrubSentrySpan`, shared `redaction` keys), with `traceLifecycle: "stream"`
      pinned so `SENTRY_TRACE_LIFECYCLE` cannot switch that hook off; deliberate `telemetry.*`
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
      (→ `security`), with `ip` (`Request::getClientIp()`, which honours `X-Forwarded-For`
      only from a hop in `SYMFONY_TRUSTED_PROXIES` — empty in `compose.prod.yaml`, so prod
      records the TCP peer; see §7) and `user_agent` (trimmed to the
      column width) sealed onto every entry. **Write capture is also wired:** every
      create/update/delete of an aggregate marked `AuditedEntity` records a `change`
      row with a field-level before/after diff, written synchronously inside the
      business transaction so the row is atomic with the write (out-of-band flushes —
      fixtures, seeds — are not captured). `Bank` (a catalog) is audited in clear; a
      **PII-bearing diff is crypto-shredded**: `BankAccount`'s `#[PersonalData]` fields
      (`holderName`, its search twin `holderNameNormalized`, `iban`, `alias`) are
      AEAD-encrypted (libsodium) under a per-subject DEK before
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
      pair unsatisfiable. Be exact about what an exempt row keeps when citing it: those rows
      are minted in the request cycle on the HTTP path, so they carry **the acting
      administrator's `actor_id`, `ip` and `user_agent`**. The row and its `actor_id` are kept
      **for ever**; `ip` and `user_agent` are set to `NULL` by the same prune run once the row
      is past the threshold of **its own level** — the `AuditRetentionThreshold` the `DELETE`
      applies there (365 days for `security` today), never a literal — by a second statement
      in `DbalAuditLogPruner` under the same advisory lock. It writes `NULL`, never the
      `[REDACTED]` sentinel, and never rewrites a row an erasure pass already redacted —
      `actor_erased` rows (the actor pass) and anonymous-actor rows with `resource_erased`
      (the resource pass, the only rows it writes the sentinel on) — so that pass's evidence on
      the row survives it. The levels run in `AuditLevel::cases()` order, so a level whose
      statement keeps failing stops the `DELETE` and the clear of every later level until it
      is fixed; the run fails loudly, it does not skip. Pinned by
      `ExemptRowRequestMetadataFunctionalTest` (cleared past the level's threshold, untouched
      at and inside it, untouched once an erasure pass redacted it, and no row left matching
      the predicate per level), `AuditPruneStatementGateTest` (`ORDER BY id FOR UPDATE`,
      `NULL`, both redaction terms, the `action` term) and `AuditPrunePlanFunctionalTest`
      (a `LockRows` over an `id` order, no sequential scan in the statement or its subquery).
      **Inside the window the only removal path is the actor-axis erasure**, which matches by
      `actor_id`. **Say the precondition with it, or the sentence promises a route that is
      refused:** _identity_ erasure is rejected while the subject still carries `ADMIN` (409
      `administrator-erasure-requires-demotion`, `FulfilIdentityErasure`), so on that route
      clearing those columns requires demoting them first — which the ≥1-admin invariant
      permits only while a **second active administrator exists** — and that administrator has
      to perform it: a self-targeted role change is refused (409 `self-role-change-forbidden`).
      The operator CLI `audit:gdpr:erase` reaches those same columns for any UUID with **no
      role check at all**. The CLI paths write `ip`/`user_agent` `NULL` on the rows they mint.
      **Residual, accepted:** the `actor_id` of an exempt row outlives anything that could check
      it for `GDPR_ERASURE_EXECUTED` (its `change` rows go at five years); one exemption for both
      actions is the recorded choice (`docs/adr/audit-activity-log.md` D4). Reopened if the
      adviser requires the address kept for the limitation period, which would give exempt rows
      a threshold of their own. The `action IN` term of the clear is redundant with running it
      after its level's `DELETE` and no behavioural test can redden it; only the source gate
      reads it.
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
      — so a row naming one person on both axes (a demotion written while self-targeted role
      changes were still admitted) cannot co-locate a real id with its own pseudonym. That
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
      `resource_erased = TRUE` and `actor_type = anonymous` = this one). `user_agent` carries
      no such filter, but capture rewrites a value equal to the sentinel (trimmed,
      case-insensitive) as `[client-supplied] <value>` (`AuditRedaction::neutraliseCaptured()`),
      so on rows captured once that rule is deployed the exact literal in either column is
      written only by the two erasure statements. Rows persisted before it were not
      backfilled and may still hold a client-sent literal; the full predicate above still
      attributes a sentinel correctly on them, because each pass overwrites every non-blank
      value in the columns it redacts. The prefix is itself forgeable and proves only that
      the value differs from the sentinel, never that the server minted it.
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
      `actor_id` after the erasure `UPDATE` (issue #376). What a request already in flight
      can still commit afterwards — shared with `security`/`change` — is rewritten by the
      erasure re-sweep (§7, ADR D4.2).
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
- [ ] **A stored hash is upgraded to the configured hasher on the first login that proves it.** `UserProvider`
      implements `PasswordUpgraderInterface`, which is what makes `json_login` attach the upgrade badge, so
      Symfony's `PasswordMigratingListener` re-encodes the submitted password whenever the configured hasher
      reports the stored hash `needsRehash()` — a bcrypt cost other than the configured one (`auto` defaults to
      13), or an algorithm `auto` still verifies but no longer mints. It runs on `LoginSuccessEvent`, after the
      credential was verified and the identity admitted, so a **failed login pays nothing new** and the
      pre-identity timing floor is untouched; a successful login pays one extra hash, once per account, plus a
      one-row `UPDATE` that can wait behind a concurrent credential write on the same row.
      `RehashPasswordBestEffort` stores it through `UserRepository::replacePasswordHashIfUnchanged()`, a
      compare-and-swap **the store decides in one statement** — only over the exact hash the login verified.
      A reset or a change committing in between is therefore never overwritten by an encoding of the secret it
      superseded, and — the half that matters for containment — a refusal hydrates nothing, so the session the
      login mints still carries the hash it proved and is signed out on its next request by
      `SecurityUser::isEqualTo`. A locked re-read of the aggregate would have advanced that session onto the new
      credential and kept it alive past the reset. **Its accepted cost:** the loser of two simultaneous first
      logins on one legacy hash is refused as well and signed out once, since a refusal cannot tell a re-encoding
      of the same secret from a new one. It is not a credential change: no domain event, no audit row, no mail,
      no session revoke, no lockout relief and no `updated_at` touch. It is best-effort — a store fault rolls
      back, leaves the old hash (which still verifies) and an untouched aggregate, and is reported on
      `observability` by exception **class** only, since the failed statement carried both hashes. **What it
      does not cover:** an account whose owner never logs in again keeps its old hash for ever, and
      `needsRehash()` is symmetric — lowering the cost in `security.yaml` rolls out a downgrade the same way
      raising it rolls out an upgrade. Pinned by `LoginPasswordRehashFunctionalTest` (real firewall, real
      Postgres: bcrypt at a foreign cost and argon2id land at the configured cost, the session survives its
      next request read against the row and is signed out when the row moves under it, a Postgres-raised fault
      still admits the login, clears the lockout and mints one session, a failed login and a current hash are
      left byte-for-byte alone), `DoctrineUserRepositoryTest` (the refusal leaves the managed aggregate
      untouched), `RehashPasswordBestEffortTest` and `UserProviderTest`.
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
      non-existent) collapses to one **byte-identical `400 invalid-token`** (token opacity, `docs/adr/identity-invitation-lifecycle.md` D11); the invited email is
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
      account enumeration (`docs/adr/identity-invitation-lifecycle.md` D10). The reset link is a selector-verifier `<id>.<secret>`: only the SHA-256 digest
      is stored (`identity_password_reset_token.token_hash`), the raw token is **never rendered, logged, or
      persisted**. Every dead-token case (used, expired, unknown, malformed) collapses to one **byte-identical
      `400 invalid-token`** (D11 of that ADR, cross-surface opacity with the invitation link — a distinct exception class
      per context, one wire type). A successful reset **consumes the token atomically** (a conditional delete
      whose affected-row count is the single-use guard, so a concurrent replay collapses to `invalid-token`), sets
      the credential, clears the lockout, and **revokes every session** (best-effort teardown; a store outage is
      swallowed because the credential change de-authenticates natively). A non-`ACTIVE` identity hits the
      post-identity wall (403). Same-origin is guarded by `RecoveryOriginListener` (mirror of the login
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
      `Security::login()` afterwards mints a replacement registry session with the anti-fixation id rotation, so
      the device that made the change walks away signed in on an id it did not hold. The rotation is applied by
      the `RotateSessionIdOnLogin` listener on every `main` login: the firewall's own `migrate(true)` skips a
      login as the identity the session already carried — this flow, and `POST /login` from a signed-in
      device — so without it the id held before would carry the new login.
      That teardown is **best-effort by construction**: it swallows its own failure and the 204 carries no word
      on it, so "every other device is out" is an intention, not a guarantee the response proves — which is why the UI copy and the notification
      mail both point at _Active sessions_ instead of asserting it. The login is likewise contained (a failure
      logs `critical` and still answers 204: the credential is already gone, so a 5xx would invite a retry that
      can no longer succeed). `Security::login()` re-enters `checkPreAuth`, which walls SUSPENDED/DEACTIVATED
      only as a raw security exception, and never the lockout, which `LockedIdentityLoginRefusalListener` applies
      on the passport event that path does not dispatch; `ReauthenticateDevice` — shared with the reset and
      invitation-accept flows, which had the identical window — re-reads the aggregate and applies
      `ensureActive()` before minting, so an admin action landing between the commit and the mint leaves no fresh
      session for a walled identity and is answered with the domain 403. The lockout arm is deliberately not
      re-applied: `ensureActive()` matches on `IdentityStatus`, and the two flows
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
      rejection and **every** forgot outcome (`identity-invitation-lifecycle.md` D10). The floor is ADDED to each branch,
      not a deadline each is padded to, so it hides only differentials smaller than its own variance — which is
      why the forgot route's heavier ACTIVE work (supersede transaction, mail send) no longer precedes its answer:
      every address does the same in-band work (budget claim, floor, one request attribute) and that work runs
      on `kernel.terminate` — see §7, *`/forgot-password` latency* (closed). A dead reset/accept
      link never runs the KDF (hashing is deferred until the token proves live). Token hygiene (same ADR, D11):
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
      the deployment then holds a person's identifier in a sink with no owner of erasure, for as long as its
      retention keeps the line (about 15 days, §7), and any client can force that for free. Both cliffs
      (index and sub-index) are closed by the strip rather than by a longer list. **Caddy also drops the `Referer` header**, because a
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
      CLI and, new here, by the **ADMIN-only `POST /api/v1/backoffice/users/{id}/erase`** (gated `#[IsGranted('users.erase')]`,
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
      route requires an authenticated session except an explicit allowlist (login, the three public recovery routes
      `forgot-password` / `reset-password` / `recovery/redeem` — same-origin-guarded by `RecoveryOriginListener`, a mirror of
      `LoginOriginListener` keyed on all three of those route names — health probes, dev hot-reload). An
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
      behind the ephemeral per-IP+email `login_throttling` (`CanonicalLoginRateLimiter`: 5 failures a minute per resolved mailbox and client address, 25 per client address). After **10** consecutive
      failed attempts against a **resolved** identity it is locked for **15 minutes**, refused BEFORE the password
      is verified (`LockedIdentityLoginRefusalListener`) with the uniform **401 `unauthenticated`** behind the
      timing floor, minting no session; a reset, a recovery-secret redemption, a change of password, an administrator's unlock or
      a lapsed window clears it. The counter increments only for an email that resolves to a row, so an **unknown email writes and emits
      nothing** — the pre-identity 401 stays indistinguishable, and a locked account answers the right and the
      wrong password alike. Once the lock has committed no password is verified, so the guessing is bounded in
      number as well as in rate (closed in §7, *The persisted lockout confirmed a correct password*) — up to the
      guesses already in flight when it commits, which the per-IP throttle and concurrency bound; a locked attempt
      counts nothing and does not extend the window. The lock is orthogonal to
      `IdentityStatus` (a locked identity stays `ACTIVE`); the lock trip emits a PII-free `UserLocked` domain event.
      A store fault while **recording** a failed attempt is absorbed best-effort, so the failure path stays the
      uniform 401 — never a leaked **500** nor a resolved-vs-unknown status-code oracle (the increment is lost,
      tolerable during a DB incident); a store fault while **clearing** on a successful login re-maps to a
      retryable **503 `service-unavailable`**. The identity is resolved by address under `SELECT … FOR UPDATE`
      and the aggregate decides on THAT row: reading it unlocked let the increment be computed against state
      another transaction had already replaced — a recovery-secret redemption or an administrative unlock
      clearing the lock, and this path restoring `locked_until` from a snapshot taken before it, with the
      attacker retrying continuously inside the window. An attempt the aggregate ignores still costs no write
      and emits nothing; what it costs is the BEGIN/COMMIT **and the row lock**, so concurrent failed attempts
      against one already-locked address serialise on that identity's row — bounded by an attempt that already
      ran a credential verification. **An unknown address takes the same transaction and the same locked read**,
      over a row that is not there, and commits without writing — so the transaction is not an existence signal
      and leaves no durable trace to tell the two apart. What an unknown address still skips is the counter's
      UPDATE on an `ACTIVE` identity and the hydration of a row (§7).
- [ ] **A third recovery edge for the persisted lockout above: `POST /backoffice/users/{id}/unlock`**
      (`ADMIN`-only, `#[IsGranted('users.unlock')]`, `users` opts out of tier auto-grant). #602 named the two
      existing recovery edges — a successful login, a completed password reset — as both attacker-cuttable by
      anyone who merely knows the target's email, with no lever an administrator could reach for instead. Wraps
      the already-idempotent `User::clearLockout()`; the response's `unlocked` field surfaces its own mutation
      signal rather than always claiming a recovery happened, and the call is audited (`SECURITY`,
      `ACCOUNT_UNLOCKED_BY_ADMIN`) whether or not it mutated anything — the lever being invoked is itself the
      fact worth keeping. **An administrator may never unlock their own identity** (409
      `self-unlock-forbidden`, refused before any row is touched, and recorded as a `SELF_TARGETED_ACT_REFUSED`
      `security` row rather than an `ACCOUNT_UNLOCKED_BY_ADMIN`): granting that would make `users.unlock` a
      second, credential-independent path into one's own account, defeating the lockout it exists to recover
      from. **The residual this left — an installation with a single administrator has nobody to invoke the lever —
      is closed by the recovery secret below**, which is the edge that depends on no peer. The
      detection/notification half (`NotifyLockedIdentities`) shipped in #683 and #857; the stolen-session
      composition is recorded in §7 (*A stolen session can deny the owner a credential rotation, but not an
      eviction*) and #602 closed with it, 2026-11-05.
- [ ] **The console's invitation levers — resend (`POST /backoffice/users/{userId}/invitation/resend`) and
      revoke (`DELETE /backoffice/users/{userId}/invitation`) — are bounded and attributed.** Both are
      `ADMIN`-only (`users.resendInvitation`, `users.revokeInvitation`). Every resend kills the link the invitee
      already holds and mails a new one, so an unbounded resend let one administrator session — stolen or
      scripted — keep a person's link permanently dead and flood their mailbox. `InvitationResendThrottle`
      spends `invitation_resend_per_invitee` (5 / day between deploys — the limiter state lives in the `app`
      cache pool, which a deploy resets — keyed by an HMAC of the case-folded invitee id under `kernel.secret`,
      so the stored bucket names nobody, and shared by every administrator)
      after the id is validated and before any row is touched, and answers a visible 429. Each caller may spend
      at most its own share of it (`invitation_resend_per_actor_invitee`, 2 / day per invitee, spent first), so
      one session cannot drain it for the others. The cost is that a drained bucket blocks every administrator's
      HTTP resend of that person until it refills (§7); the console command `iam:invitation:resend`, which
      neither budget covers, is the way round it. Each act writes a `security` row naming the invitee (`INVITATION_RESENT`,
      `INVITATION_REVOKED`), because the event store records the transition and never who caused it. The row is
      written by `Iam/Identity`'s `RecordInvitationAudit` — the context that owns and erases the person — under
      the invitee's `identity_user` row lock, nested in the lever's transaction after the invitation rows: the
      order `FulfilIdentityErasure` takes them in, so the row either commits before the erasure's pass over the
      trail or finds the identity gone and is never written (`SubjectAuditWriteSerialisationGateTest`,
      `PersonResourceErasureGateTest`).
- [ ] **The recovery secret (`identity_recovery_secret`): the lockout edge that depends on no peer.** A
      `<selector>.<secret>` credential in its own aggregate, one row per identity (UNIQUE on `user_id`).
      **Minted** from a live session against a re-proof of the current password and shown in clear exactly
      once, in the minting response — only the digest is persisted, so nothing can display it again.
      **Redeemed** anonymously at `POST /backoffice/recovery/redeem`: it establishes a session, **revokes every
      other session of the identity** and clears the lockout, and that session survives every later re-locking
      because the admission gate reads the session row and never `locked_until`. The eviction commits in the
      transaction that consumes the secret, after the secret is re-verified under lock, so neither happens
      without the other; it is not best-effort, because no credential changes and nothing else de-authenticates
      the other sessions. `revoke-others` locks the identity's active sessions in the same id order and refuses
      (401 `session-expired`) a caller whose own row was revoked while it waited, so a stolen session cannot
      revoke the redeemed one from a request admitted a moment before the eviction. Never delivered by email or
      printed by a CLI (that is what keeps it out of the vendor's reach), and never in a query string. The
      redemption spends
      `token_action_per_selector` and **nothing keyed by an address or an identity** — keying it either way
      would put the recovery channel in the namespace the attack already occupies. Every death case
      (malformed, unknown, lapsed, wrong, already consumed, budget exhausted) answers one byte-identical
      400 `invalid-token`; a valid secret over a non-`ACTIVE` identity is the one identified 403, and the row
      is not consumed. **Revoked** at `POST /me/recovery-secret/revoke`, which re-proves the current password like the other
      two: a stolen session cannot change the password or mint, but an ungated revoke let it destroy the
      channel permanently and then hold the account shut with the email-keyed lockout — the very attack this
      credential answers. Destroying a recovery capability is as sensitive as granting one. Minting, the
      password change and the revoke share ONE per-identity credential-proof budget
      (`CurrentPasswordProofThrottle`) — a bucket of its own would hand a stolen session twice the guesses
      against the same password, since none of the three feeds the persisted lockout. The membership is
      gated: every route of `api/.route-manifest.json` is classified in `api/.credential-proof-policy`, and
      `make php.lint.credential-proof` fails when a `credential-affecting` route's action does not spend that
      budget and verify the submitted password before invoking a use-case method that calls
      `ProveCurrentPassword::ensure()` (blind spots in the registry header). Full record:
      [`docs/adr/administrative-recovery-channel.md`](docs/adr/administrative-recovery-channel.md) D7.
      **Four residuals, each accepted rather than closed:**
      **(a)** the secret is valid for **ten years** — `SingleUseToken` makes "no expiry" unrepresentable and a
      short window would silently destroy the channel of somebody with no shell to notice.
      **Accepted for good 2026-11-05 (Sergio), and #870 closed with it.** A year with re-minting, or 90 days,
      was declined because each reproduces on a timer the failure the design rejected when it let a secret
      survive a password change — a channel dead the day it is needed, for a customer with no shell and no
      second human to notice — and a reminder would have to ride the vendor's mail relay, which I-2
      disqualifies for this channel. Rotating the secret inside the redeem response was declined because a
      lost response spends the only secret and turns a one-time theft into a permanent, undetectable one. An
      in-app expiry notice reaches only somebody who can sign in, so it cannot protect the owner who needs
      the secret because they cannot. **What no observer here sees**: the audit row carries no selector (I-1's
      corollary), so a redemption cannot be tied to a minted secret, and the customer's custody of the
      written-down copy is invisible to the system entirely. **Reopen when** a second bearer credential adopts
      a multi-year lifetime (two share a root cause: write a product-wide credential-lifetime policy rather
      than a second ten years), the deployment model changes so the customer has a shell or console or a
      second administrator the software can rely on, or a redemption arrives for a secret minted years
      earlier.
      **(b)** possessing it equals possessing a recovery credential until redemption, revocation, expiry or
      subject erasure — it survives a password rotation by design, and the profile screen listing it with both
      instants and a credential-gated revoke is the whole of what makes that governable. Possession is also an
      **eviction** capability: redeeming signs out every session of the identity, the owner's included, so a
      leaked secret redeemed by someone else ends the owner's sessions as well as admitting the holder — the
      owner's way back is the password, and that way is shut while an attacker keeps the email-keyed lockout
      sealed. The gate costs the owner a wait of up to one window when the shared budget has just been spent.
      That residual is **not** self-inflicted only: all three routes that drain the bucket require a live
      session, and `cookie_samesite: lax` (`api/config/packages/framework.yaml`) means a cross-site form post
      carries none, so nobody can drain it from _outside_ — but a **stolen** session is a live session, and
      ten requests from it spend the bucket — the controller consumes it before the proof runs, so the value of
      `currentPassword` does not matter — and delay the owner's mint, rotation and revoke by up to one window
      (default 15 minutes), renewable for as long as that session lives. It stops when the stolen session is evicted —
      `revoke-others` from the owner's own session, or a redemption — and eviction spends no budget.
      **(c)** the **selector is a denial capability**: whoever learns one can spend that selector's budget and
      hold the channel shut in silence without authenticating. It is contained by construction (it is the row's
      key, so events name the user, and it reaches no audit row, log or URL, and no DTO but the minting
      response that delivers it) and bounded by the threat
      model — that denial is dominated by the cheaper email-keyed attack #602 opens with, not by the
      selector's entropy, which is a UUID v7 and therefore not a per-id CSPRNG draw.
      **(d)** the secret, the lockout and the session are **three state machines no transaction spans**, so
      single use means *at most one persisted consumption*, never *at most one authentication*. The session is
      established BEFORE the row is retired — inverted, a failed session mint would leave the secret spent and
      the administrator with nothing to present — so a partial failure is retryable and the endpoint does not
      promise 204 through it. One such partial state is provoked rather than suffered: another session of the
      identity revoking the redeemed one before the consuming transaction locks the set. The redemption then
      evicts every other session but does **not** consume, and answers 503 `transient-transaction-failure`;
      the retry succeeds, because nothing left alive can repeat the interference without the password.
      **Mislaying it is permanent loss of the channel**, which is the cost B1 was chosen with; what follows
      contractually (vendor rescue by writing to the database, or reinstallation) is not settled here.
- [ ] **Server-side session registry & admission gate (`iam_session`):** login mints a `Session` aggregate and the
      **Session Admission Gate** re-reads it on every authenticated `/api` request — the `^/api` boundary of
      `ApiRequestMatcher`, matched over the **decoded** path as the firewall and router match it, so a
      percent-encoded `/%61pi/…` is inside it rather than past it — so "authenticated" means "has a
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
      session revoke: a credential change de-authenticates the sessions that predate it. It compares the
      lifecycle status too, so a suspension or deactivation whose post-commit revoke failed still ends every
      live session of that identity on its next request (`session.feature`, `SecurityUserEqualityTest`).
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

- [x] **A GDPR erasure racing a recovery-secret redemption reinserted the subject into `audit_log`, on the ACTOR
      axis — found already closed, pinned 2026-11-25.** The item described the compensation row
      (`RECOVERY_SECRET_REDEMPTION_COMPENSATED`) as written post-commit with no lock, so an erasure committing inside
      that window left the subject back in the table. The tree no longer does that: `RecordRecoverySecretAuditBestEffort`
      writes all five recovery-secret actions inside `IdentityRowLock::whileHeld()` (`FOR KEY SHARE` on the identity
      row), and `FulfilIdentityErasure` holds that row `FOR UPDATE` from its administrator re-check to its commit,
      ahead of the trail pass — the same serialisation as the lockout, lockout-notice and recovery-throttle writers.
      Both orders end clean: the writer first, and the erasure's pass sees the committed row and anonymises both
      columns; the erasure first, and the writer's locked read finds no subject and writes nothing. What was missing
      was the proof, and the register: `LateAuditWriterErasureSerialisationFunctionalTest` covered only
      `RECOVERY_SECRET_MINTED`, and read only the resource axis. It now drives all five actions through the real race
      (a second process holding the row) and reads BOTH axes (`resource_id` and `actor_id`) afterwards. Those races
      run with no session token, so the actor of a row they write is the system; the case the item turned on — a row
      sealed from the subject's own token, which names them as ACTOR — is a separate test that writes it and then
      erases, in sequence, leaving no row naming them and the row itself anonymised on both axes. **No test runs the
      erasure-first order with an actor-sealed row:** that order is the writer finding no subject, and the lock that
      yields it is the one the race tests exercise. **Not established:** which commit
      introduced the lock — the checkout's history is shallow and `git log -S` reached only a documentation commit —
      so this records that it holds at this head, not when it started to. The residual the item cited stays what it
      was: a writer outside `Iam/Identity/Application` is outside `SubjectAuditWriteSerialisationGateTest`, which reads
      that one directory.
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
      **The pass clears what is committed when it runs**, so it is closed only together with the item below.
- [ ] **`audit_log.metadata` is a closed vocabulary, and one shape still admits text the server did not
      choose.** `AuditLogEntry::create()` refuses any key outside `AuditMetadataKey`, any value outside its key's
      shape and any `metadata` over 16 KiB, so a producer can no longer drop an `iban` or a request body into the
      one column no erasure pass rewrites. **Closed 2026-11-25: `orphan_role_values`** carried a sample of the raw
      `identity_user.roles` text the integrity probe could not explain — whatever the corruption wrote there, a
      person's email included. It now admits only a value shaped like a role name (`[A-Z][A-Z0-9_]{0,63}`; a cell
      longer than that is the sentinel, never a prefix of itself), the
      `<null>` sentinel (reserved: the one producer today hands over strings, so it is exercised by the vocabulary test
      only) or `<non-role-shaped>`, decided in one place (`AuditMetadataShape::storedValue()`) that
      the producer calls and the shape agrees with; the complete raw list stays in the inspection's own output,
      which an operator reaches deliberately. This governs new writes, not old rows: a row written before
      this change keeps what it held, and there is none in this deployment. **What remains:** (a) **the clear fields
      of a `change` diff** (a `Bank`'s `name` and `shortName`; every `#[PersonalData]` field is sealed) carry what
      a user typed and are not reached by the anonymisers, which rewrite columns and never this JSON — the detail
      route now serves only classified fields (next item), but the row keeps them; and a role-shaped word that is
      somebody's name passes the pattern. (b) **A UUID under a key is indistinguishable from a person's id**: the
      shape admits a pseudonym under `anonymized_actor_id` exactly as it would admit the real id, so what keeps a
      person's id out is the identity erasure's acceptance witness (`features/backoffice/users/erase.feature`),
      not the vocabulary. Decision and costs: ADR [`docs/adr/audit-activity-log.md`](docs/adr/audit-activity-log.md) D4.

- [ ] **A row naming the subject that commits after the erasure's pass is kept out by a lock, and the gate
      over it sees one directory.** The four post-commit writers that name a `User` in `resource_id` — the
      lockout, the lockout notice, the recovery throttle and the recovery-secret projections — each write under
      the subject's `identity_user` row lock (`IdentityRowLock`, `FOR KEY SHARE`), which `FulfilIdentityErasure`
      holds `FOR UPDATE` from its administrator re-check to its commit, ahead of the trail pass; so each row
      either commits first and is redacted, or waits and finds no subject. A login holds the same row
      (`SessionSubjectLock::holdForAdmission()`, `FOR SHARE`, reading the status it waited for) before it writes
      its session, so a session and its `SessionStarted` are purged and rewritten with the rest or never
      written — and a status write in flight is waited for, so a suspension's revoke-everything never runs before
      a session minted alongside it (`LoginRacingASuspensionFunctionalTest`, which observes the wait in
      `pg_stat_activity` rather than timing it); a bulk revocation (`RevokeAllSessions`, after a role, status or
      password change) holds it too, so its `AllSessionsRevoked` is rewritten with the rest or never written.
      Pinned against a second Postgres connection holding and deleting
      the row in `LateAuditWriterErasureSerialisationFunctionalTest` and `LoginRacingAnErasureFunctionalTest`.
      `SubjectAuditWriteSerialisationGateTest` pins every site under `Iam/Identity/Application` that writes an
      audit row naming its subject, and reds one written without a serialising mechanism. What stays open:
      **(a)** the gate is blind to a writer outside that directory, to the resource type spelled as a literal or
      reached under an alias, and to a write reached through a callable, a helper in another file or a trait —
      such a writer reopens the window with every gate green, and only
      `identity:gdpr:reconcile-subject-references` would report the row it leaves; **(b)** the lock refuses to
      run inside a transaction the caller already opened, because nested there the bound and the commit would
      both be the caller's — so a writer moved inside one loses its row with an `error` report on `observability`
      instead of committing it; **(c)** a wait that exhausts the 2 s `lock_timeout` drops the row and reports it
      on `observability`. When the erasure behind it commits, no row was owed; when it rolls back — an
      administrator refusal, a later failure in the chain — the identity is live and a `security` row it was
      owed is lost, the evidence surviving only as that report; **(d)** in the lockout
      notice's race the MAIL may still reach the address of a person whose erasure is committing, since it is
      sent before the stamp and the audit row: a notification, not a record, and nothing of it persists here.
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
- [ ] **A person's id persisted through configuration has no owner of its erasure.** The axis above is
      derived from entity properties, so an id that reaches storage along a path no property declares — a
      Messenger route, a scheduled message, a route default, a service argument, an integration or an
      external event — is declared by nothing, and no rule names who erases it. Deferred for sequencing,
      not excluded: closing it means deciding who owns a reference whose writer is configuration rather than
      a context, a question the property-derived registry cannot answer. The transport half is fenced by
      `api/.persistent-transport-policy` only by `aggregate_id`: a person-aggregate event stays unrouted,
      but one whose id is safe while its PAYLOAD names a person (`SessionStarted`, `SessionRevoked`) is out
      of its reach. The rest is unmeasured.
      Revisit when a person's id is first seen reaching storage through configuration.
- [x] **The person-reference axis has no DETECTIVE control and no backfill.** The gate above is static: it
      proves a deletion is _written_, never that a row _went_. Two consequences, both now closed. (1) Any subject
      erased before this shipped left its `membership.user_id` / `iam_invitation.invited_user_id` row behind,
      and nothing in the codebase would ever name those rows again — they are not migrated or swept here.
      (2) A future write path that creates a person-referencing row without going through the erasure chain
      reintroduces the residue silently. The sibling axis already answers this with
      `identity:gdpr:reconcile-subject-references`, whose docblock states the reasoning
      (_"divergence surfaced beats divergence assumed away"_); the equivalent join is not built. Its scope is
      **four** columns, not the two this axis closed: `membership.user_id`, `iam_invitation.invited_user_id`,
      `iam_session.user_id` and `identity_password_reset_token.user_id` carry the same defect exactly, and
      none of them has a foreign key — nothing in the schema references `identity_user` at all.
      Closed by #634, which gives each of the four columns a `PersonReferenceSource` the reconciler collects,
      and #635, which schedules it with an alarm. The ordering note against G-3b — which schedules the
      same reconciler — is **moot under the option #634 ships**: one reconciler extended with a lister per
      owning context, so a schedule created first picks the new axis up with no revisit. It bites only if
      that decision is reopened toward one reconciler per context, and is kept as that tripwire. The
      **backfill half is measured away** — no production environment exists, so there are no real erased
      subjects with surviving orphan rows; the story is prospective.
- [x] **`event_store` retains a person's real id past their own erasure.** Every dispatched event is
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
      lookup table is barred by [`docs/adr/audit-activity-log.md`](docs/adr/audit-activity-log.md) D4.
      Closed by #640 with a different route: inside the erasure transaction, one parameterised `UPDATE`
      rewrites the subject's id to a fresh random UUID in the column and in the serialised `payload` and
      `metadata`, by value and case-insensitively, so it reaches every event holding it without anyone
      listing them. It is the log's one sanctioned mutation, recorded as D12 of
      [`docs/adr/event-store-and-projections.md`](docs/adr/event-store-and-projections.md).
- [x] **`audit:gdpr:erase` was not atomic — closed 2026-11-25.** The anonymisation `UPDATE` committed and the
      `GDPR_ERASURE_EXECUTED` self-audit was written _after_, outside any transaction — a crash between them left the
      erasure done with no evidence of it, and the original id no longer matched anything, so a re-run falsely
      reported "nothing to erase". Both now run in one `EraseActorAuditTrail` transaction through `TransactionManager`
      (never a raw DBAL transaction nested under `wrapInTransaction`, which has no savepoints): a failed self-audit rolls
      the rewrite back, the original id still matches and the run is safe to repeat. The command's `ERASED_UNRECORDED`
      exit code (3), which existed only to say "erased but unrecorded", is retired because that state is no longer
      reachable. Pinned by `EraseActorAuditTrailTest` (both writes inside the boundary, in order; no evidence for a
      statement that matched nothing; a failure leaves it) and by `EraseActorAuditTrailAtomicityFunctionalTest`, which
      runs against Postgres with nothing enclosing the use case and shows the original ids restored after a failing
      audit — and goes red if the work runs outside a transaction. **Residual:** a connection lost at the `COMMIT`
      itself leaves an outcome nobody can know from the caller; the command's message points at `--dry-run` (zero rows
      for the original id means it committed). A job that treated exit code 3 specially no longer sees it, which fails
      toward the safe reading.
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
      guard here can reach; and `--dry-run` still prints the matching row count to the same caller, so what
      the guards close is the exit code as an existence oracle, never the count as information — which is
      also why no separate record is owed for the count surviving on the confirmation path. **No longer
      three copies:** `identity:gdpr:erase-subject` and `bank-account:gdpr:erase-subject` inherit the
      sequence from `ConfirmedErasureCommand` and cannot order it wrong; `audit:gdpr:erase` keeps its own,
      because it reads the row count between the refusal and the question, and
      `ConfirmationGuardAdjacencyGateTest` holds the union of both routes to the same two guards — a text
      sweep over the shape, which never judges the semantics.
- [ ] **An erasure count is read from the store, never minted by a type fallback.** Every Doctrine adapter
      running a DQL bulk statement had to narrow `AbstractQuery::execute()`'s declared `mixed`, and seven
      sites across four adapters reached independently for the same fallback — six spelled
      `\is_int($affected) ? $affected : 0`, the seventh `\is_int($affected) && $affected > 0`. It mints the
      one value its callers read as evidence: the count flows through `IdentityErasureResult` out of
      `identity:gdpr:erase-subject`, and `SessionRepository::deleteAllForUser()` promises a legitimate `0`
      for a subject with no rows — so a real zero and a fabricated one were indistinguishable to every
      caller, in the direction that looks safe. A bulk statement ALWAYS yields a count, which is what makes a
      default there an invention; `AffectedRows::from()` narrows or raises, and refuses a negative for the
      same reason. The password-reset single-use guard joins it: reading a non-int as `false` kept the
      property but told a live token's holder it was spent. **Residuals, none gated:** the only preventive
      control is `IntNarrowingConfinementGateTest`, which confines the literal `is_int(` to the guard's own
      file and claims NOTHING further — an `(int)` cast, a `?? 0`, an `if` or a `match` all ship green, and
      two stronger gates were built and measured broken before it (one carried six parser defects of its
      own; the other rewarded a file that narrowed its DBAL count while fabricating its DQL one, and turned
      red once BOTH were narrowed). Review is the control on every other spelling. The DBAL family is
      untouched: `Connection::executeStatement()` returns `int|numeric-string`, and its call sites narrow by
      hand and inconsistently — most with an `(int)` cast, which converts rather than fabricates, but
      `DbalKeystore::destroy()` (the crypto-shredding tombstone) does neither, returning `$affected > 0`
      directly on the union. That comparison is correct for both members today; nothing holds it there.
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
      prod services — which makes them required keys for `make prod.env.check` (`scripts/deploy/lib/prod-env.sh`, which also refuses a reserved-domain or no-reply sender before start) — and refused at send time by
      `DeliverableSecurityTransport` (discard transport) and `SecuritySenderAddress` (reserved sender
      domain). **The residual is that non-delivery on this channel is unobservable from the outside:**
      the notice is unsolicited, so no recipient reports its absence — the `warning` its best-effort
      wrapper logs on every failed tick is the only signal, and it is a log line, with the
      Monolog→Sentry bridge deliberately unwired. Closing this means an alert on that line.
- [x] **The lockout notice's single-delivery property rests on a replica pin, not on a lock — accepted
      for good 2026-11-05.**
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
      default, never a ceiling.
      **Accepted 2026-11-05 (Sergio), and #864 closed with it.** The supported topology is ONE consumer of
      `scheduler_identity_maintenance`; a second one is unsupported, and the consequence of running it is that
      the notice **and** its `ACCOUNT_LOCKOUT_NOTIFIED` audit row are delivered twice (no erasure interaction:
      both rows name an identity that is still live). What a deployment-side count would have bought was
      weighed and is not buildable here — nothing in the repository can see a `--scale`. A scheduler lock
      (`symfony/lock`) stays declined for the reasons recorded in #261. A claim-before-send (an atomic
      conditional stamp ahead of the SMTP exchange) was weighed and declined: it replaces a duplicate with a
      lost notice when the process dies between the claim and the send, and the notice's whole job is to
      tell a person about a live lock. **Reopen when** a duplicate is observed — more than one
      `ACCOUNT_LOCKOUT_NOTIFIED` row for one `resource_id` within one day (a re-notification after a fresh
      lock comes a day later, so two rows a day apart are not one) — or a deployment scales that consumer past
      one, or the send-then-stamp race this rides on is picked up for a fix, which closes the audit duplicate
      with it.
- [x] **`api/storage-test/` was outside `.gitignore` — closed 2026-11-25.** The path is now ignored beside
      `api/storage/`, so a `git add -A` cannot commit whatever a test last wrote into a **public**
      repository. The directory does not exist in a clean checkout and no test, config or compose file names
      it, so there was no tracked residue to delete; the original 91-byte PNG it described is not in the tree.
      **Residual:** nothing in the tree writes there today, so the line guards a path another checkout or an
      older suite may still create, and no gate holds it.
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
      **Superseded 2026-10-20:** mercure-bundle 0.6 defaults to protocol 1.0, which the embedded 0.x
      hub refuses, so `config/packages/mercure.yaml` pins `protocol_version: '0.x'` explicitly and
      `MercureHubProtocolFunctionalTest` asserts it on the container's own hub.
      **Superseded 2026-10-22:** the embedded hub is now dunglas/mercure 1.x in compatibility mode; what
      that leaves open is its own item, "The embedded Mercure 1.x hub runs in compatibility mode".
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
      `bin/console` call under `APP_ENV=test` compiles it first, and CI then ran several inside
      `php.quality.dry-run` **before** the suite (`ci.yml` ran PHP lint before PHPUnit). `Kernel::getCacheDir()`
      forked only for Behat, so console and PHPUnit shared `var/cache/test`: PHPUnit loaded the warm
      container, never autoloaded the class, and reported green over a real deprecation. The gate
      that justified the mercure pin had therefore never once fired in CI. PHPUnit now compiles into
      its own directory (`PHPUNIT_RUNNING`, set in `tools/phpunit/bootstrap.php` the same three ways
      Behat sets its own flag — `getenv()` sees neither `$_ENV` nor `$_SERVER` alone, which is why a
      first attempt via phpunit.xml's `<server>` silently did nothing). **Measured on one tree, one
      version, in that CI order:** shared cache → `Tests: 2698`, 0 deprecations, green; forked cache →
      `Tests: 2698`, `Deprecations: 1`, red.
      **Superseded 2026-10-30 ([#1145](https://github.com/sergio-salcedo-dev/ERPify/pull/1145)):** the lint
      sweep is its own CI job (`api-lint`), so PHP lint no longer precedes PHPUnit in `ci.yml`. The private
      directory stays load-bearing all the same: `php.unit` depends on `db.test.prepare`, whose
      `bin/console` calls boot the `test` container before the suite does.
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
- [ ] **`npm audit` reports 25 advisories in `pwa/` (2 low, 23 moderate), and none has a fix inside
      its consumers' ranges.** Measured 2026-11-02 with npm 12.0.1; they reduce to three roots, the
      rest being the cascade npm lists per dependent. **`minimatch` <11.0.1** (DoS via glob-pattern
      amplification): 3.1.5 under `eslint-plugin-react`/`eslint-plugin-jsx-a11y`, 10.2.6 under
      `eslint`, `typescript-eslint`, `ts-morph` and `glob` — every one matches patterns this repo
      writes, never a caller's. **`glob` 13.0.6** (AbortSignal cancellation bypass) under
      `@sentry/bundler-plugins`, which pins `^13.0.6`; it is the one path `npm audit --omit=dev`
      still reports, and it is build-time only — `@sentry/nextjs` imports it from its `config/`
      loaders and nowhere in its runtime. **`deepmerge` 4.3.1** (prototype poisoning) under the
      `shadcn` CLI, where no fixed release exists. The fixes are majors (`minimatch` 11, `glob` 14,
      first published 2026-10-27) no consumer has adopted; npm's own `audit fix --force` would
      **downgrade** the plugins, and an `overrides` entry forcing `minimatch` 11 breaks
      `eslint-plugin-react`, which calls the 3.x default export where 11 exports `{ minimatch }`. The high (`systeminformation` <5.33.16, Windows command
      injection, under `shadcn` → `@dotenvx/dotenvx`) was closed in #1166 by a lock-only move to
      5.33.16. **Before a customer deployment:** re-run `npm audit --omit=dev`; the build-time
      `glob` path closes when `@sentry/bundler-plugins` takes `glob` ≥14.0.2.
- [ ] **The invitation emails are sent after the commit, best-effort and in no guaranteed order — narrowed
      2026-11-25, not closed.** `SendInvitation` and `ResendInvitation` commit the token rotation and then mail the
      link synchronously, outside the transaction (`SendInvitationEmailBestEffort`). Two consequences. Two concurrent
      resends of one person are serialised by the invitation row lock, but their SMTP sends race after the commit, so
      the email that arrives last can carry the link the other resend already killed. And an SMTP exchange slower than
      the PWA's 30 s client timeout shows the operator a `request-timeout` over a resend that committed. **The first is
      now narrowed:** `CurrentTokenInvitationEmailSender` decorates `InvitationEmailSender` and refuses to hand a link
      to the mailer unless it is still the live one of a `SENT` invitation (the selector names the row, the secret is
      verified against the stored digest in constant time, the TTL and the status are read), so a link a later resend
      replaced is dropped instead of mailed; the best-effort wrapper above it already turns that refusal into "not
      accepted", which the resend answers as `invitation-email-not-sent` for the older request. That shrinks the window
      from a whole SMTP exchange to the gap between one read and one call. **It does not close it** — two sends that
      both pass the check before either resend commits can still cross — and closing it needs the row held through
      the SMTP exchange, which would block an accept or a revoke for as long as a mail server takes; that was weighed
      and declined (owner, 2026-11-25). A messenger-routed send is closed by policy: the invitee is a person and
      `Iam.Invitation` is classified so in `api/.persistent-transport-policy`. The second consequence is untouched and
      both are bounded today: the resend budget allows 5 / day per invitee and 2 per caller, the console tells the
      operator to resend again, and a refused send answers 503 rather than a false 204. The decorator costs one
      indexed read per send, deliberately a fresh one (`findByIdFresh()`: a plain `find()` answers from the entity this
      request already manages and would never see a resend another process committed). It is pinned by
      `CurrentTokenInvitationEmailSenderTest` (live, replaced by a later resend, expired, no longer `SENT`, absent,
      four malformed shapes) and by `CurrentTokenInvitationEmailSenderFunctionalTest`, which replaces the digest behind
      this process's back with a raw `UPDATE`, shows that a plain read would let the old link through, and shows the
      decorator refusing it, the best-effort wrapper reporting `false`, the replacement still mailed, and the container
      resolving the port to the decorator.
      **When an outbox earns its place** — any one of: a second transactional email producer that needs
      at-least-once delivery rather than best-effort; the 30 s timeout observed against real SMTP latency; an
      invitee who reports holding only a dead link after a resend; or invitations sent in bulk, where a
      synchronous SMTP exchange per row stops fitting a request. **And the shape it must take:** a token-bearing
      email may never sit in a Messenger transport (`docs/rules/security.md`, "Token hygiene"), so the outbox
      would enqueue the INTENT — "deliver the current link of invitation X" — and mint the token when the
      message is delivered, not enqueue the rendered email. That moves the token rotation off the request, so
      the resend's answer stops meaning "the old link is dead", and that contract change is the real cost to
      weigh. **Accepted:** not recorded (opened 2026-10-26); the narrowing and the declined lock are the owner's of
      2026-11-25.
- [ ] **Three administrator identities acting together can hold one invitee's HTTP resend shut for a day.** The
      per-invitee budget on `POST /backoffice/users/{userId}/invitation/resend` (`invitation_resend_per_invitee`,
      5 / day, shared by every administrator — §6) is what stops the lever from killing a person's link for ever,
      and sharing it means it can be drained. One identity cannot drain it alone: each caller may spend at most
      its own share (`invitation_resend_per_actor_invitee`, 2 / day per invitee), spent first, so a single stolen
      session — or one operator retrying through a mail outage — kills at most two of a person's links a day and
      leaves the rest to the other administrators. Three distinct administrator identities can still spend all
      five, and then nobody resends that person over HTTP until the window refills. The invitee keeps the last
      link that was delivered, inside its three-day TTL; after a mail outage they hold none, because each 503 has
      already rotated it. **Way round:** the console command `iam:invitation:resend <invitationId>`, which a shell
      operator runs and neither budget covers. Every resend that spent the budget is attributed
      (`INVITATION_RESENT`, `security`). **Revisit when** an operator reports a resend refused with 429 that they
      did not cause, or the console surfaces invitation resend to a role other than `ADMIN`. **Accepted:** not
      recorded (opened 2026-10-25; narrowed 2026-10-26 by the per-caller share).
- [x] **A stolen session can deny the owner a credential _rotation_, but not an _eviction_ — closed 2026-11-05 (#602).** Both budgets a
      session holder can reach are keyed by something they already have: `password_change_per_identity`
      (10 / 15 min, a visible 429) by the identity itself, and `password_recovery_per_email` (5 / hour, whose
      exhaustion is **silent by contract**, so the owner meets the uniform 202 and no email arrives) by the
      address `GET /me` hands them. Neither one is the security objective. **Eviction is, and it has a path no
      budget gates:** `POST /sessions/revoke-others` carries no limiter of any kind — every throttle in the
      repo lives in `Iam/Identity` or `Iam/Invitation`, none in `Iam/Session` — needs only a live session, and
      ships in the PWA as _Active sessions_. The owner's route to a live session is untouched: the credential
      still works, a stolen session feeds no failed attempts into the lockout (`LoginAttemptRegistrar` is
      reached only from the login failure handler, never from the password-change path), and the persisted
      lock is enforced at login alone (`LockedIdentityLoginRefusalListener`), never by `SessionAdmissionGate`. So the sequence
      is **sign in → `GET /sessions` (the intruder's row is listed, with its device label) → `revoke-others`**,
      and the delayed rotation is then hygiene against an adversary who no longer holds anything.
      **The attacker holds the same weapon and the race still resolves for the owner:** either party can fire
      `revoke-others` and evict the other, with no budget on either side, but **re-entry is not symmetric** —
      the owner returns with the credential, while a revoked cookie is dead and no path re-mints one without
      the password. Each round costs the owner one login. That asymmetry holds for a non-administrator; an
      _administrator's_ stolen session could once mint its own re-entry — invite an address it controls and grant
      it `ADMIN` — which evicting the stolen session does not reach; that now costs the caller's password
      (ii below). **What survives is the composition, which
      [#602](https://github.com/sergio-salcedo-dev/ERPify/issues/602) closed on 2026-11-05:** an attacker who _also_
      drives the per-email lockout (10 failures → `PT15M`, needing ≥2 source addresses to clear the per-IP
      throttle) denies the owner the very session eviction requires. **A live recovery secret now closes that
      composition for the identity holding it.** Any `ACTIVE` identity can mint one from its own session
      against a re-proof of its current password (`POST /me/recovery-secret`; a second mint answers 409, never
      a replacement), so a stolen session can neither mint nor displace one, and it cannot revoke one either,
      because revocation re-proves the password too. Redeeming it clears the lock, establishes a session the
      admission gate keeps through every later re-locking, and **revokes every other session of the identity
      in the transaction that consumes the secret** — so the stolen session is dead by the time the secret is spent,
      and a revoked cookie cannot be re-minted without the password. The race between a redemption and a stolen
      session's `revoke-others` is closed on both sides of the lock: `revoke-others` locks the identity's active
      sessions in id order and refuses a caller whose own row was revoked while it waited, and a redemption whose
      fresh session was revoked a moment before its own lock evicts the rest **without** consuming and answers 503,
      so the retry meets nobody left to interfere. Nor can the stolen session reach the redeemed session through a
      role or status change aimed at its own identity, which is refused (ii).
      **What still survives, each outside what an eviction can reach:**
      **(i) An identity with no live secret** — never minted, spent and not yet re-minted, or lapsed (a lapsed
      row counts as none — `GET /me/recovery-secret` reports it as `exists: true, live: false` — and minting over it answers 409
      until it is revoked). The composition is then intact: another administrator's `users.unlock` clears the
      lock only until the attacker's next ten failures re-seal it — seconds, not a lockout window — and for a
      **sole administrator** even that lever is absent (nobody else holds `users.unlock`, and the product
      refuses it over one's own identity with `self-unlock-forbidden`). Redeeming spends the secret, so every
      recovery reopens this window until the owner re-mints — and the re-mint spends the shared
      `password_change_per_identity` budget, which the evicted session may have drained before it died, so it
      can wait up to one window.
      **(ii) What an administrator's stolen session does to OTHER identities now costs the caller's password.**
      Handing out `ADMIN` (`POST /backoffice/invitations` with the role in the set) and every role or status
      change (`PATCH /backoffice/users/{id}/roles`, `…/status`) re-prove the caller's current password and spend
      the one per-identity budget the credential routes share, in the controller and before the use case
      (`ProveActorPassword`; ADR `administrative-recovery-channel.md` D11). A stolen session does not hold the
      password, so it can no longer plant an administrator or suspend, deactivate or demote the owner, and a
      wrong, missing or over-budget proof writes nothing. Roles and status ask whoever the target is — deciding
      that the target is an administrator means loading it first, a check-then-act window — and the invitation
      asks only when the set carries `ADMIN`, decided from the payload alone. The three routes are
      `credential-affecting` in `api/.credential-proof-policy`, pinned by name. **What survives**: (1) an
      attacker who also holds the password; (2) a stolen session can still invite identities holding a role
      **below** `ADMIN` (a planted `MANAGER` is a foothold the owner has to find in the users register); (3)
      the console invitation command (`iam:invitation:create`), which drives the invitation use case as the
      operator and so is outside the proof by design; (4) the shared budget — a stolen session can spend it
      and delay the owner's own admin acts, every window, until the owner evicts it with `revoke-others` (not
      budget-gated, so the eviction is never delayed), as in §6 (b); and ordinary use is capped by it too: ten
      proofs in fifteen minutes, so a bulk edit of eleven users is refused until the window refills. Self-targeted role and status writes stay refused (409
      `self-role-change-forbidden` / `self-status-change-forbidden`), which closes the race in which such a
      write, admitted before the eviction and queued on the user row, committed after the redemption and its
      post-commit teardown revoked the redeemed session. Each of the four self-refusals writes a resource-less
      `SELF_TARGETED_ACT_REFUSED` row at `security` level (`metadata` holds the problem `type` and the route),
      where a 409 would otherwise reach no `audit_log` row at all — the generic hook records successful reads
      only. There is no deployment yet, so there are no administrators planted or demoted before the proof
      existed to go and look for. One UX residual: in the invitation form the proof field sits under the
      invitee's email, so a browser's credential manager may offer to save the inviter's password beside that
      address; no autocomplete token avoids it (`off` is ignored, `new-password` is worse), and the structural
      fix is a separate confirm step with no email field beside it, as the roles and status dialogs already are.
      **(iii) A leaked secret is an eviction weapon as well as a way in.** Whoever redeems it signs out every
      session of the identity, the owner's included, and the owner's way back is the password — shut for as
      long as the same attacker keeps the email-keyed lockout sealed. The trade is deliberate: an owner who kept
      their session through a thief's redemption could `revoke-others` the thief, who had no secret left to
      return with, and that defence is given up to close the stolen-session race, on the argument that the
      secret is shown once and held offline while a session cookie is exposed on every request. The owner
      seeing the secret disappear, and being signed out with it, is the detection property.
      **(iv) A leaked secret can evict without being spent.** Two concurrent redemptions of it, the first one's
      session revoking the second's through `revoke-others`, end both interrupted: every session is evicted,
      nothing consumes, and the holder can repeat it within the per-selector throttle. The detection property of
      (iii) does not fire — the secret never disappears — and only `RECOVERY_SECRET_REDEMPTION_INTERRUPTED` audit
      rows attest it. Accepted by Sergio on 2026-09-28 as a variant of (iii), which already presupposes the leak;
      ADR `administrative-recovery-channel.md` D10.
      **What the product delivers for (i):** every `ADMIN` session that has never minted a secret is held at a
      blocking step in the back office until it does (`RecoveryEnrolmentGate`) — **a client-side step, not a
      server control**: a direct API call by an un-enrolled administrator is not stopped, and a readiness read
      that fails leaves the step down for that document: the explanation, the existing
      mint form unchanged — the secret is shown once and held until the owner confirms it is saved — and a way to
      sign out, with no skip. It blocks only an ACTIVE administrator whose readiness is `not-live` / `none`, never
      `unknown` or `expired` (a lapsed row occupies the slot and a mint over it can only answer 409), and never a
      session opened by REDEEMING a secret, because that secret was just spent and minting needs the password the
      owner may not hold. The redeemed state lives in the tab's memory, so **a reload after a redemption meets the
      step**: the owner then signs out and uses *Forgot password*, which the redemption notice already names. A
      redeemed owner who reloads and has lost the password is stopped until a reset mail arrives — a narrow case
      accepted because the stricter direction is the one a stale flag fails toward. What the block does not
      close: the window after a redemption until the owner re-mints (the banner warns; the owner has a live
      session meanwhile), an administrator whose secret has lapsed (banner only), and an owner who never signs
      in again. (2), ordering guidance for an owner who can still sign in — evict first, rotate second — exists in
      the UI copy and the password-changed mail: the mail tells a reader who did not make the change to redeem
      their recovery secret or reset from the sign-in page — either one signs every other device out — and only
      then set a new password, with the security mailbox as the fallback; the change-password form opens with
      the same order and a link to *Active sessions*. A redemption whose session is confirmed says the second
      half itself: its toast states the other sessions were signed out, and the dashboard it lands on says once
      what is still owed — a new password, since redemption changes none, and a new secret, since the used one is
      spent — never that the account is now safe. A redemption whose session probe fails gets neither: its copy
      says only that the secret is spent and to open the back office. That notice lives in the tab's memory until
      a dashboard mounts, and it is consumed by that mount: a reload loses it, so does a session probe that fails
      while the dashboard is showing it (the page unmounts and comes back without it), and if the redirect lands
      elsewhere it waits and shows at that tab's next dashboard visit. The sign-in page links the recovery page,
      so the mail's "use your recovery secret … from the sign-in page" names a reachable step.
      **Accepted 2026-11-05 (Sergio), and #602 closed with it:** the residuals (i) as bounded above, (iii) and
      (iv) — the last two were accepted earlier (2026-09-25, 2026-09-28) and are unchanged — and the three
      survivors of (ii). **Reopen when** a lockout is observed on an identity with no live secret, an
      administrator's recovery finds identities or grants they did not create, or a role below `ADMIN` is found
      to be enough to hold the owner out.
      `revoke-others` carrying no limiter is deliberate and load-bearing: it is the one edge an adversary
      cannot spend. **Do not "harden" it.**
- [x] **A failed login no longer carries an existence signal shaped like a transaction, a seed cost or an
      over-long password.** Three differentials on one path, each closed by construction.
      **(1) The transaction.** Measured against the running dev stack before the fix (n≈35 per class,
      production-cost bcrypt; p50 and ROC AUC only — p95/p99 and the already-locked and same-identity-concurrency
      cases #881 listed were not measured, because the fix makes both branches issue the same round trips rather
      than arguing the gap is small): with `LoginAttemptRegistrar::recordFailure()` skipping its transaction for
      an address that resolved to no row, a failed login for an unknown address answered ~20 ms faster at p50
      than one for an existing `ACTIVE` identity, separable at AUC ≈ 0.60 — small, and classifiable with enough
      samples, the outcome #881 said obliges both branches to pay the same. Every well-formed address now opens
      the transaction and runs the same `SELECT … FOR UPDATE`, and an unknown one commits without writing —
      pinned in `LoginAttemptRegistrarExistenceShapeTest`, which compares the port calls of a known and an
      unknown address and asserts the locked read runs inside the transaction. The price is a transaction per
      failed login against a non-existent address; the locking read over no row locks nothing, and the attempt
      has already paid a credential verification and passed the login throttle. A deadlock or referential fault
      only an existing identity can meet is absorbed like any store fault, so it cannot surface as a 503/409
      where an unknown address gets the 401 (`ProblemDetailsAuthenticationFailureHandlerTest`).
      **(2) The seed cost.** The fixture seed hashed every identity at bcrypt cost 4 while the timing floor pays
      one verification of the hasher the environment configures — cost 13 under `auto` in dev — so on any
      environment loaded from the fixtures a known address answered a wrong password in ~53 ms and an unknown
      one in ~594 ms. Seeds are minted through that same configured hasher (`SeedCredentialProvider`), pinned
      by `SeededCredentialCostFunctionalTest` (the floor's dummy and every seeded hash cost what the configured
      hasher costs, and every credentialed entry in `User.yaml` routes through the provider) and
      `SeedCredentialProviderTest` (at a cost the tree does not configure). The fixtures never reach production;
      a production hash minted at another cost is the general form of the same fault. It is rehashed at the
      configured cost on the account's next **successful** login (§6: `UserProvider` implements
      `PasswordUpgraderInterface`, `RehashPasswordBestEffort` stores it), and a failed login rehashes nothing —
      so what survives is an account that has not signed in since the cost changed: its stored hash verifies at
      its own cost while an unknown address pays one verification at the configured one (the floor's dummy is
      minted by the configured hasher), so a wrong password against it answers faster than an unknown address
      when that cost was lower, and slower when it was higher. The same stale hash separates an `ACTIVE`
      account from a walled one: a `SUSPENDED`, `DEACTIVATED` or locked identity is refused before verification
      and pays the floor's dummy at the configured cost, while an `ACTIVE` unlocked one pays its own stored hash
      — so under the same condition the latency also reports status and lock state, not only existence.
      **(3) The over-long password.** `NativePasswordHasher::verify()` returns false without hashing for a
      password over `PasswordHasherInterface::MAX_PASSWORD_LENGTH` (4096) bytes, and nothing in the firewall
      bounded it first — measured on the dev stack, one request: a known address answered ~18 ms, an unknown
      one ~320 ms. `OverlongPasswordTimingListener` refuses such a password on `CheckPassportEvent` ahead of
      the first listener that resolves the user, paying the floor once, so both answer ~320 ms (measured) and
      the refusal still counts against the login throttle, which every login failure spends
      (`LoginThrottleSpendFunctionalTest`).
- [ ] **What a failed login for an existing identity still pays that an unknown address does not is UNMEASURED.**
      An `ACTIVE` identity below the threshold pays the counter's UPDATE (plus its `event_store`/outbox rows on
      the attempt that trips the lock, and on that same attempt the lockout projection's own transaction —
      BEGIN, a `SELECT … FOR KEY SHARE` of the row and the `audit_log` INSERT, since that projection
      serialises on the subject's row against an erasure; pinned as the only extra round trips in
      `LoginAttemptRegistrarExistenceShapeTest`); every existing identity pays the hydration of its row inside
      the locked read; and its locked read can WAIT behind another transaction holding that row — a concurrent failed
      attempt against the same address, a successful login's clear, an unlock or a redemption — where a read
      over no row never waits. An attacker controls that concurrency, though each wait is short because the
      KDF runs before the transaction. All of it sits under a KDF of hundreds of milliseconds and rides the same
      transaction, so the plausible answer is noise — but the transaction differential above was ALSO plausibly
      noise and measured classifiable, so this is a hypothesis, not a claim. `security.yaml` states it at that
      strength. Closing it by construction would mean writing on behalf of an address that names no row, which
      is not on the table. **Related, and also open:** `PasswordHashingTimingFloor` mints its dummy hash lazily,
      so the first floor paid by each FrankenPHP worker costs a hash plus a verification — one slower answer per
      worker lifetime, on whichever pre-identity branch reaches it first.
- [x] **A credential change no longer signs a second browser tab out of the application.** Both
      flows that replace a credential from a live browser — `ChangeMyPassword` and `CompletePasswordReset` —
      revoke **every** session and mint a replacement onto the requesting tab. A request already in flight from
      another tab of the same browser carries the old cookie, meets the gate's `401 session-expired`, and
      `FetchHttpClient` diverts it to `/login?reason=session-expired` irreversibly — so a change that fully
      succeeded reads, in that tab, as being thrown out. **Closed in the client, with no new server seam:** the two
      flows leave an instant in `localStorage` (`erpify:credential-replaced`, written on success only — nothing
      about who or why), and a gated 401 that arrives within 30 s of it reloads the CURRENT page instead of
      bouncing to `/login`, once per marker per document (`credentialReplacement.ts`, the `credential-replaced`
      departure reason). The cookie is shared by every tab of the browser, so the reload runs under the new
      session; a session that really ended answers the page's own `/me` with a 401 and reaches the login screen
      by the ordinary route. The server-side alternative, `revoke-others`, would have needed three new
      cross-context seams to know which session is the caller's own, and bought nothing this does not.
      **Not covered, and why that is acceptable:** (1) the reload discards the stale tab's in-memory state, so
      an unsaved form there is lost — the same loss the login bounce caused; (2) the in-flight request that
      raised the 401 still fails, and its error may paint for the instant before the reload (the
      `credential-replaced` reason deliberately raises no curtain, so the message is not "session expired");
      (3) with storage blocked the marker cannot be written, and the previous behaviour — a login screen —
      returns; (4) a request that meets a 401 more than 30 s after the change is not attributed to it; (5) a
      second browser or device, whose sessions are genuinely revoked, correctly goes to the login screen.
      **Both flows are named on purpose:** the reset surface carried the identical window. History: accepted
      2026-08-05 (Sergio) while it cost three seams; closed once a client-only fix made it cheap.
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
      carrier on into the same unrotated sink. `compose.prod.yaml` pins `APP_DEBUG: "0"` as a literal on the three PHP services (`php`,
      `messenger_worker`, `scheduler_worker`), so the claim is structural and no longer depends on Dotenv's
      `'prod' !== $env` default; `ProdDebugPinGateTest` keeps it pinned. It does not see an `-e APP_DEBUG=1` asked for
      on a command line. The console `ErrorListener`'s `command` key is deliberately
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
      buffers it. **The enrolment is a gate**: `PersonDataRedactionEnrolmentGateTest` reads the DUMPED production container
      (the compiled class, one `new \Monolog\Logger('<channel>')` at a time) and fails naming every channel logger that
      does not push the rule, with `security`, `request` and `app` required to be present and a floor on the number
      found so a changed dumper shape reds instead of passing over nothing. It runs under `php.lint.log-carriers`,
      whose prerequisite is `php.lint.prod-container`: a prod kernel never revalidates its cache, so the clear and
      rebuild is what makes the reading fresh. Measured falsification: scoping the tag to one channel names eight
      loggers. It does not cover a logger built at run time. What IS gated is the property that lets the test container stand in
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
      erasure the application confirmed to the subject, which is why this entry stays unchecked. **Caddy's
      access log is no longer in this sink** ([#879](https://github.com/sergio-salcedo-dev/ERPify/issues/879)):
      it writes its own file with its own budget and a TTL (the URL-path entry below). What remains here is
      Monolog's prod output — the flushed `fingers_crossed` buffer and the always-on `observability` channel —
      the embedded Mercure hub's `http.handlers.mercure` logger, and every other container's stdout/stderr
      (`database`, `pwa`, the workers). Closing it
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
      `/api/v1/backoffice/users/<uuid>`, where the uuid is the person's id. The access log has a TTL but no
      owner of erasure: it is written to `/var/log/caddy/access.log` on the `caddy_access_log` volume, rolled
      every day at 00:00 UTC (and at 32 MiB) with rolled files deleted after 14 days — literals in
      `api/frankenphp/Caddyfile`, so a line lives about 15 days at most — **once the process has written its
      first line**: the roll scheduler and the pruning start on the first write after a (re)start, so a
      restart followed by no traffic at all keeps the files it found until a request arrives — and nothing in
      `FulfilIdentityErasure` can reach it, so an id that lands there outlives the erasure the application
      confirmed to the subject by up to that window. The volume is in no backup (`scripts/deploy/backup-prod.sh`
      dumps Postgres only), so a restore cannot bring back a line the retention already deleted. The
      application log's `request_uri` lands in the container json-file sink instead, which still has no TTL
      (the entry above). Closing it is not a wider `replace` but a different mechanism —
      a log-level rewrite of `uri`, a retention the erasure path can act on, or no
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
- [ ] **The access log's filter can be bypassed from the environment at two points, and nothing detects
      it.** `{$CADDY_GLOBAL_OPTIONS}` (`api/frankenphp/Caddyfile:8`) and `{$CADDY_EXTRA_CONFIG}` (`:17`) are
      each documented options (`api/docs/options.md`), and both expand **outside** the site's `log` block:
      the first can declare a global logger of its own, the second a whole second site block carrying no
      `format filter` at all. Either does it from a deployment, without touching this repository, with every
      gate in the tree green — the whole-query strip included. No test here can see it, because none reads a
      deployment's environment. **This is a bigger hole than any spelling the strip closes**, and it bounds
      what every other claim on this page about the access log is worth. The `log` block itself expands
      nothing: its destination and retention are a literal `output file` block, and
      `CaddyfileAccessLogRedactionGateTest` pins that block whole and refuses any `{$…}` inside `log`, so a
      deployment can no longer point the log back at stderr or replace the encoder ahead of `format filter`.
      Dev, test and prod therefore write the same file. Not checked against a running Caddy:
      `{$CADDY_SERVER_EXTRA_DIRECTIVES}` (`:128`) also expands inside the site block, outside `log`, and
      whether a directive placed there (a second named `log`, a `log_append` field) can reach these entries
      unfiltered has not been measured — read it as a possible third point until it is.
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
      loggers, which is why the site's `format filter` reaches one and not the other — and why they land in
      different sinks: the access line in `/var/log/caddy/access.log`, the hub's line in the container log.
      `api/frankenphp/Caddyfile` enables `anonymous` and `subscriptions` unconditionally, so this is
      unauthenticated. The application's own topics are bank and bank-account UUIDs, so normal operation
      leaks nothing — this is an injection channel into the container's json-file sink, rotated by volume
      only, with no TTL and no erasure path, and it is pre-existing rather than introduced. Closing it needs
      a `log` override for the `http.handlers.mercure` logger, or dropping `subscriptions`.
- [ ] **The embedded Mercure 1.x hub runs in compatibility mode, which accepts more than the
      application speaks.** The FrankenPHP 1.13 image (taken for PHP 8.5.12, CVE-2026-109618 /
      CVE-2026-109619, #1072) embeds dunglas/mercure v1.1.4, which refuses top-level
      `publisher_jwt`/`subscriber_jwt` unless compatibility mode is named, and refuses two unnamed hubs —
      `SERVER_NAME` lists the origin and `php:80`, so the site block yields two servers. `frankenphp/Caddyfile`
      therefore sets `protocol_version_compatibility 8` and `name erpify`; the shared name is also what pools
      both servers onto one bolt transport. Measured against the v1.13.2 binary and the image's own binary
      (same mercure version and build tags): a `mercure`-claim token is validated with no `aud` and no
      `exp` required, as the 0.x hub did; a wrong key, a missing token, an expired `exp` and a foreign
      `Origin` on a cookie publish are refused; a private update reaches the `mercureAuthorization` cookie
      on `?topic=` and never an anonymous subscriber, across both servers. **What it accepts beyond that:**
      a well-formed protocol-1.0 token (`typ at+jwt`, `authorization_details`) whose `aud` names the hub,
      for publish and for subscribe, through the `__Secure-mercure_access_token` cookie it reads first.
      **What it serves beyond that:** an unauthenticated
      `/.well-known/oauth-protected-resource/.well-known/mercure` on every server, prod included, stating
      the resource URL, the 1.0 cookie name and that the subscriptions API is on — informational, no
      `Cache-Control`. Closing it means moving the keys into an `issuer` block, the bundle to protocol 1.0
      and the PWA subscriber in one change; any half alone may break publish or subscribe **partially and
      silently**, depending on the `aud` the bundle mints against the host the hub derives. Open; not
      accepted.
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
      `GET /backoffice/audit?actorId=… 200 in 73ms` to stderr — Docker's json-file driver, bounded by size but
      with no TTL (unlike Caddy's access log, which has a file of its own with a 14-day TTL), and one that
      Caddy's `query` filter cannot reach because it is a different process. **Caddy's redaction does not
      cover this**; anyone reading "the access log redacts `actorId`" as "no log holds it" would be wrong. `pwa/next.config.ts` also sets
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
      **Moved 2026-10-20 (Sentry SDK 11):** spans are streamed and never pass through `beforeSend` or
      `beforeSendTransaction` (the latter no-ops), and the query arrives as a bare `url.query` with
      no `?`, which the shape rule cannot recognise — measured passing in clear on every traced fetch
      and every outgoing-request breadcrumb. Spans are now scrubbed by `scrubSentrySpan` in
      `beforeSendSpan`, a key naming a query is scrubbed as one, and the identity axes are also
      filtered at the source through `dataCollection.urlQueryParams`. That hook fails OPEN in the
      SDK (a throw sends the span unscrubbed), so `scrubSentrySpan` catches and empties the span.
      **Residual four — a green proves the transformation, never the sink.** These are unit rows over
      synthetic events; nothing here observes what a running SDK attaches, and `beforeSend` /
      `beforeSendSpan` are the only interception points, so an SDK field outside the surfaces
      enumerated above is untouched by construction.
      **Accepted 2026-08-21 (Sergio):** residuals one to four, on the same basis as the access-log
      residuals above — no production deployment and no customer — and on the narrower fact that all
      four require an identifier to travel somewhere the shape rule does not read, rather than under
      a name the vocabulary failed to enumerate, which is the direction that actually shipped.
      **Expiry: re-assess before the first production deployment or the first customer**, whichever
      comes first.

- [ ] **Image bytes: what the storage root guarantees, and the twelve things it does not.** Residuals of
      `Shared/Images`, listed together because each is invisible from the others. One to four are the
      write and deletion paths; five to ten are opened by the authenticated read route
      `GET /api/v1/images/{imageId}` and are not closed by it; eleven is the publish guard's own reach, twelve the upload
      seam's.
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
      `event_store` cannot re-dispatch it because the consumer is a message handler and not a projector.
      `ImageAttachment` is the one publisher of that request: every replace and remove reaches it through that
      service. There is deliberately no reconciliation
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
      **The attach protocol shrinks the bucket and does not empty it.** `ImageAttachment` queues the release of
      the NEW id in a fresh transaction when the attach fails, so a failed attach, a vanished owner and a rolled-back
      transaction no longer strand bytes. What still does: a crash or an out-of-memory kill between the store and
      the attach, a failure inside `UploadImage` after `store()`, and a compensation that itself fails — which
      includes the owner-row contention that failed the attach failing the compensation's own re-read. That failure
      is logged by exception class on the `observability` channel (`images.attachment.compensation_failed`) and
      replaces nothing of the original failure the caller sees. **The log carries the class only on purpose**: the
      new id is an identifier of an image that may be a face, and the sink has no erasure path. The consequence is
      that no log line leads to an orphan.
      Two shapes need two comparisons, and nothing runs either: an object whose row was never written (a crash
      between the store and the row) is found only by comparing the storage listing with the `image` table, and one
      from a failed compensation, which has both a row and an object, only by comparing the `image` rows with the
      holders' columns.
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
      **Five — enumeration is bounded by an IP-keyed limiter and by nothing sharper.** An `ImageId` is
      UUIDv7, so it is time-ordered and cheaper to enumerate within a window than a v4 would be, and any
      authenticated session may read any image. A global limiter does apply — `RateLimitListener` gates every
      `/api/*` request that is not an `OPTIONS`, without consulting authentication — but its bound is softer
      than its number suggests: the pool is local to a deployment (the configuration itself says a
      multi-worker deployment behind a balancer needs a shared Redis), and with `lock_factory: null`
      concurrent workers *"may over- or under-count"*. **A limit per identity and per route now exists, and what it bounds is narrower than the whole axis.**
      `image_read_per_identity_route` (600 per minute) is spent by `GET /api/v1/images/{imageId}` before
      anything is looked up, a `304` included, and `image_write_per_identity_route` (30 per 10 minutes) WILL be spent by every
      image write route that follows the convention, before its body is read — spending it is the consumer
      controller's call (item twelve); both are keyed by an HMAC of the identity and the route name, so
      the cache pool holds no person's id, and answer 429 with `Retry-After`. The numbers are decisions of this
      story, not measurements: 600 admits a 100-row list page six times over, and bounds a revalidation loop to
      ten full reads a second. They share the `anonymous_api` pool's properties — local to a deployment, with
      `lock_factory: null` over-counting under concurrency — and they sit beside, not instead of, the per-IP
      bucket, which an office behind one address still shares. `ImageId` is never an authorization mechanism and
      never a secret.
      **What defends the frontier is now a tripwire rather than a promise.** The argument for having no voter
      (D1 of [`docs/adr/image-read-route-contract.md`](docs/adr/image-read-route-contract.md)) is that no
      consumer relation exists to vote on, and its second half — that the first real consumer brings its
      own policy — was prose that nothing enforced; an external security review named
      the exact failure that permits, a consumer wired without an owner check turning a documented
      provisional frontier into a silent cross-user read. `ImageConsumerAuthorizationGateTest` holds a registry
      `file::property => anchor in D1`, its universe derived from `api/src`: an aggregate outside `Shared/Images` holding
      an image reference with no entry reds it, and so does an entry whose anchor D1 does not record, so that
      question is forced into the diff that first raises it. The bot's own suggested fix — gating the route behind a permission granted to no
      role — was measured and refused: it answers 403 to every caller, which deletes the slice rather than
      deferring the decision. The gate matches a property NAME (or an ORM column name) ending in `image_id`, so a
      reference under another spelling, in a join table or in a JSON column is still invisible to it.
      The first consumer, `Bank::$logoImageId`, is registered under `D1:logo`: audience is the authenticated session,
      the read is unaudited and the one-hour window stands.
      **And the conditional request inverts the usual self-limiting.** A `304` is gated on the same verified
      read as a `200` — the full object off disk plus its SHA-256 — because the storage port exposes no
      existence predicate; the cost is declared at the controller. What is NOT declared there is that the
      client stops paying: a loop of matching `If-None-Match` requests receives a couple of hundred bytes
      each while forcing a full read and hash per request, where the same loop answered `200` would cost the
      caller the whole body and self-limit. Nothing bounds aggregate bytes served, and the per-IP budget is
      sized for page navigation (120/min) while this route is one request per rendered image rather than one
      per navigation — so an office behind one NAT shares it, and a 429 arrives inside an `<img>` as a broken
      image with no diagnosis. The per-identity read budget above bounds one session's loop, never an office's
      shared address; the bank logo, the first consumer, inherits it on every list page, and each further consumer does.
      **Six — a cached copy outlives the erasure, shortened to an hour rather than closed.** A conforming
      client does not revalidate while the response is fresh, so once the bytes and the row are gone every
      viewer keeps serving the image for up to `max-age` with no request reaching a server that could answer
      404. The route emits `max-age=3600` rather than the year first asked for (D3 of the same ADR), which shortens the exposure
      without turning every view into a full read; it is still the residual with the most personal-data
      content this route adds, and it is not immediate deletion. A replace mints a new id and queues the release of
      the old one through `ImageAttachment`, so a copy cached under the old id is exactly this residual. What the hour does **not** cover, said
      rather than implied: a copy the viewer downloaded, a screenshot, a page already open with the bytes in
      memory, and the stale behaviour of an offline user agent. And the ambiguity that decides the ceiling is
      recorded rather than resolved: the conservation ADR's *"stop being retrievable by any route, cached
      URLs included"* was written about server routes and CDN-fronted variant URLs, and reading it as
      governing the viewer's own browser cache is an INTERPRETATION — if that reading is confirmed, no finite
      window satisfies it and the answer is `no-store`.
      **The bank logo adds a second cost of the same kind, accepted on 2026-11-17 (Sergio): a logo-only change pushes
      nothing.** `Bank::changeLogo()` records no domain event (a decision, not a business fact: the trace is the
      `BANK_UPDATED` audit diff and the release is `ImageDeletionRequested`), so another client with the bank open
      shows the old logo until its next load, and once the queue drains that id falls to the placeholder. A
      rename pushes the CURRENT logo id, resolved at publish time, so it never blanks one. Reopening path: a
      separate `BankLogoChanged` event preserving null; no production history is lost by adding it later.
      **Seven — the object is materialised whole in one process, bounded by what the row DECLARES.** The
      storage port returns the canonical object as a single string, and a memory exhaustion is a fatal error
      rather than a `Throwable`: the RFC 9457 pipeline would not run and the response would not be Problem
      Details at all. The read use case therefore refuses an object whose `Image::byteSize()` exceeds
      `erpify.images.max_served_bytes` **before** calling `read()`. What the guard cannot see is the whole
      residual: `byteSize` is what the row says, not what the object weighs. The digest verification closes
      that gap after the fact, which is a detection and not a prevention — and it detects it under one name:
      an object that diverges from the row's declared size is reported as a digest mismatch, so "these bytes
      were corrupted" and "these are not the whole object" are one verdict on the observability axis.
      Separating them was considered and declined: it costs a third verdict, a reporter method and a log
      producer to name a subset of a condition the digest check already refuses to serve, and nothing in the
      tree can currently produce it. The budget itself is no longer hand-picked — it is derived from
      `max_output_dimension` and gated against `memory_limit` at both ends by `ImagePixelBudgetGateTest`, so
      an object the pipeline can legitimately emit can no longer be stored and then permanently unservable.
      **The INGESTION side of the same axis was measured in this story and half-closed.** The
      canonicalization pipeline peaks at roughly 10 bytes per input pixel (GD holds a truecolor frame at
      4 B/px and `ResizeModifier` clones it), and `memory_limit` was never declared by this repository at
      all — it was the base image's 128M. Measured, that made an ordinary 12 MP phone photo a FATAL error
      3.3x inside the 40 MP ceiling configured to accept it, and a fatal is not a `Throwable`: no Problem
      Details response is produced and in worker mode the worker itself goes down. The budget is now
      declared (`256M`) and the ceiling lowered to 20 MP to match, with `ImagePixelBudgetGateTest` refusing
      a commit that moves either without the other. **What stays open is the OUTER bound, which is the
      container rather than the ini**: prod caps the php service at 1 GiB and FrankenPHP runs about two
      workers per core, so `workers x memory_limit` overcommits and a concurrent burst of large uploads is
      bounded by `PHP_MEM_LIMIT` — where an overrun is an OOM-kill of the container, not of one request.
      **Decided 2026-11-16 (Sergio): the accepted measure is the arithmetic, with no global in-flight
      counter.** One write costs about 200 MB at 20 MP against the 256M `memory_limit`; prod caps the php service
      at 1 GiB and FrankenPHP runs about two workers per core, so the bound is `PHP_MEM_LIMIT` and the worker count
      together with the per-identity write budget above. **That budget bounds requests per window, not
      concurrency**: one identity can open several large uploads at once, each a worker thread at up to 200 MB, so a
      single session can reach the OOM-kill of the whole service as well as several identities can — an OOM-kill of
      the container rather than of one request. That stays accepted until a deployment shows the load, and the
      counter that would close it is declined.
      **Eight — nothing records who read what.** Deliberate, and recorded as D2 of the same ADR: the route name begins
      `shared_`, which is the one exclusion of the generic activity audit that fits object serving, so a
      successful read writes no `audit_log` row. A single `resource_type` cannot be both person-denoting (an
      avatar) and not (a logo), and the shared route has no consumer relation to vote on (each consumer's policy is
      its `D1:` paragraph). It is a scope boundary, not an oversight — and it means this route has no forensic
      record of access at all.
      **Nine — the container log has no isolation between the application's channels, so a read failure can
      evict unrelated history.** The default channel and `observability` share one retention budget
      (`json-file`, 10m x 5) and eviction is **by volume, not by ownership or age**, so a sustained failure on
      this path displaces the history of subsystems that have nothing to do with it — and nothing alerts,
      because no collector reads that channel and the Sentry handler is commented out. The correct framing is
      shared-sink eviction between independent producers rather than "too many logs", and the correct control
      point is the logging infrastructure itself, which is transverse and not this module's. **Caddy's access
      log has left that budget** ([#879](https://github.com/sergio-salcedo-dev/ERPify/issues/879)): it writes
      `/var/log/caddy/access.log` on its own volume, rolled daily and kept 14 days, so a failure flood on this
      path can no longer evict request history, nor a request flood these records. What #879 did
      not change is the sharing between Monolog's channels, and between them and the Mercure hub's logger —
      one budget per container, still evicted by volume, still with no TTL.
      **The read path's own contribution is now bounded, and the bound is narrower than it sounds.**
      `FailureSignalWindow` admits one record per `(operation, category)` per 60 seconds in both emitters, so
      a caller can no longer choose how much this deployment writes by looping one broken image. Its state is
      process memory and FrankenPHP runs several workers, so the bound is per worker and the aggregate scales
      with the worker count; and it covers the `observability` line only — a 5xx still writes the
      default-channel `error` record that flushes the buffered handler, which belongs to another channel and
      another module. What it costs is frequency: the log keeps that a permanent failure is still happening
      and stops carrying how often it was retried. Reasoning, and the measurement that reversed the earlier
      decision to leave it unbounded:
      [`docs/adr/image-read-failure-signal-bound.md`](docs/adr/image-read-failure-signal-bound.md) D2 and D4.
      **Ten — the served `Content-Type` is a database column, and the allowlist that closes it lives only at
      the writer.** `ImageGetController` sets the response type verbatim from `Image::mediaType()`; the
      entity guarantees only that the value is non-blank, and Doctrine hydrates without the constructor, so
      even that runs at write time alone. The four-type allowlist is a `private const` on the WRITE path and
      the read edge deliberately inherits it rather than restating it. Today exactly one production site
      constructs an `Image` and its media type comes from `MediaTypeEncoderFactory`, and
      `ImageWriterSurfaceGateTest` now refuses a second one appearing silently — but that gate reads CODE,
      and the exposure is a ROW: a data migration, a restore, or a context writing SQL directly is invisible
      to it. What bounds the consequence instead of the cause is the response, which is the first in the tree
      to carry `Content-Security-Policy: default-src 'none'; sandbox` and
      `Cross-Origin-Resource-Policy: same-origin` — the body is inert whatever the column says, and
      `X-Content-Type-Options: nosniff` is not what does that, since it PINS a declared type rather than
      questioning it. This is also the first `/api/*` route returning a browser-renderable body at all; the
      API origin still publishes no CSP, no CORP and no `X-Frame-Options` of its own, so every other route
      relies on returning JSON.
      **Eleven — what the publish guard does not see.** `ImageDeletionRequested` now has its one publisher
      (`ImageAttachment`), observed end to end by `ImageAttachmentFunctionalTest` from a second connection, and the
      guard, its build-time twin and the send-boundary gate are described in
      [`docs/adr/image-deletion-signal-transport.md`](docs/adr/image-deletion-signal-transport.md) D3. What stays
      open there: a message that is not a `DomainEvent` and is routed only off `async` is not guarded at all
      (`ScanDocumentMessage`, the scan wake-up entry below, only while it stays routed to `async`); a second bus, or
      a `send()` straight to a transport, passes through nothing, and `MessengerSendBoundaryGateTest` sees only
      `api/src` text — its header lists what else it cannot see, and a `TransportNamesStamp` outside `api/src` is
      refused at runtime only; `isTransactionActive()` proves a transaction is open, not that it is the business
      write's; a business write already autocommitted outside a transaction is not undone by the refusal; a
      received envelope passes by design; and the answer for `async` is memoised per process, because the DSN is
      fixed at start. A second use case reusing the registered creating site trips no gate. The boot-time assertion stays (`event:transport:assert-transactional`, gated by
      `AsyncTransportBootAssertionGateTest`) and §8 keeps the manual `exec` verification.
      **Twelve — the upload seam: what drains, what never reaches it, and what is only convention.**
      (a) A body the application refuses before reading it — an anonymous request, a 403, a 404, a refused
      length — is still drained by `DiscardRawBodySpoolListener` so its spool can be matched and destroyed, for up
      to the listener's time budget (about 120 s per request, the same posture as the Documents listener, accepted);
      a slow client therefore holds a worker for that long even on a refusal. (b) **The drain is skipped when the
      request is refused before routing**: the per-IP `anonymous_api` 429 answers at priority 512 on `kernel.request`,
      and a `PUT` under the lifted 20 MiB matcher whose path has no route ends in the router's 404. Neither carries
      `RawBodyRoute::ATTRIBUTE`, so the plaintext spool survives until the worker serves another request. The
      Documents twin has the same hole. (c) What holds a consumer's route to the seam is a gate for the edge and a
      convention for the order. `ImageWriteRouteAgreementGateTest` reads the router's inventory and each route's
      source and reds a `PUT` the Caddy `@imageWrite` matcher admits that lacks `RawBodyRoute::ATTRIBUTE` (its
      plaintext spool would survive until the worker serves another request) or never reaches `ImageAttachment` (it
      would publish no release), and a route carrying the attribute or reaching `ImageAttachment` that no alternative
      of the matcher admits; the matcher's own shape stays in `UploadRoutePathAgreementGateTest`. It does not see the
      ORDER inside a controller: that the throttle is spent before the body is read is pinned by
      `BankLogoControllerOrderTest` for the bank logo only, so any other consumer that reads the body first spends
      its budget after the cost with every gate green. Nor does it see a consumer reaching `ImageAttachment` through
      a path its reflection does not follow (a service locator, a callable, a property it does not take by
      constructor type). The through-Caddy proof of the lifted bound is `BankLogoThroughCaddyTest` (a 2 MiB image is
      stored, a cut body is `411` and stores nothing).

- [ ] **A row naming the subject, committed by a request already in flight, is rewritten by the erasure re-sweep
      on its next tick — unless it commits after the subject's window closed.** The erasure's passes reach
      the rows that exist when they run, and a request that loaded the subject before the identity row went can
      commit one afterwards. Three review rounds each found more writers able to. The session writers among
      them — `StartSession` and the revocations — are serialised with the erasure (the item above, and the
      erasure purging sessions before the business-log pass; a re-login that revokes another identity's
      correlated row is serialised with that owner's erasure by the row lock alone), which leaves the access log and the four
      request-boundary security audit listeners on the actor axis as the live class. An enumeration that failed
      three times is not a control, so that class is closed by mechanism instead, and the mechanism backs up
      the serialised writers too: the erasure inserts the subject into `identity_erasure_resweep` inside its own transaction, and
      `ResweepErasedSubjects` re-runs the actor, resource, business-log and session passes for every subject
      still scheduled, on the five-minute `identity_maintenance` schedule, whoever wrote the row
      (`docs/adr/audit-activity-log.md` D4.2). A subject's window is one hour from the instant the erasure built
      its row, inside the erasure's transaction — so a slow erasure commit shortens the post-commit coverage —
      and "next tick" means the next one the single `scheduler_worker` actually runs: a tick waiting behind
      another, a restart or a stopped worker delays it. The re-sweep repeats only those four passes; the
      reset-token, recovery-secret, membership and invitation purges are not repeated, because their late
      writers contend on `identity_user` or `iam_invitation` and so cannot commit after the erasure. The late writers of `Iam/Identity` that run after their own
      commit stay serialised on `identity_user` (§7, the item above), which closes their window outright rather than bounding
      it. What remains, **accepted by Sergio (product owner) on 2026-10-21 together with the mechanism** (an earlier record dated it 2026-10-06, which could not be traced to him):
      - **a row committed after the subject's window closed is not reached.** Nothing bounds how long a
        request runs — `max_execution_time` does not count time spent waiting on the database, and neither
        Caddy nor Postgres sets a timeout — so the hour is a margin chosen far above any sane request, not a
        guarantee;
      - **a Postgres timeout would not close that either**: the `kernel.terminate` writers insert in
        autocommit, after the request's own work, so no statement or transaction timeout reaches the late
        insert itself;
      - **the subject's real id stays in `identity_erasure_resweep` until the first successful tick after its
        window closes** — an hour and up to one period on a healthy scheduler, longer while a subject's sweep
        keeps failing or the scheduler is down. The column has a named owner of its erasure (the re-sweep
        deletes the row on the tick that closes its window) and a detective source that reports a row older
        than two windows;
      - **a late row gets the tick's own fresh pseudonym**, not the erasure's, so it does not link to the rest
        of the subject's anonymised trail or stream. Keeping the link would need the id→pseudonym table D4
        vetoes.
      Re-affirm or close before the first customer.
- [x] **A `change` row's `metadata.changes` map holding a personal field in clear was served by the audit detail
      route past its subject's erasure — closed 2026-11-25.** Nothing on the erasure path reaches `metadata`, and
      the level only proves who wrote a map, not what a field in it holds: a `change` row that never went through
      `PiiDiffSealer` (written before sealing existed, or inserted directly in the database) was served verbatim.
      The read side is now closed by default (`AuditDiffDisclosure`): within a `change` row a field is served in
      clear only when it is a sealed envelope or the registry names it as a non-personal field of the row's resource
      type; any other field keeps its place in the diff with `old` and `new` both withheld, so the client still
      renders that something changed. A row with no resource type, an unknown type and a field that is not a
      well-formed `old`/`new` pair are withheld the same way, and a "sealed-looking" side carrying any key beside
      `__enc__` is not trusted as sealed. The registry holds strings (the audit module may not import the modules
      it records) and is tied to the entities by `AuditDiffDisclosureRegistryTest`, which requires each type's clear
      fields to equal its ORM-mapped fields minus its `#[PersonalData]` ones — a new column, or one that becomes
      personal, reds the test instead of being served by accident. **Residuals:** this governs what the route
      serves, not what the row stores — the clear fields stay in `metadata`, and the table is readable under the
      access it already demands; a field wrongly left unmarked `#[PersonalData]` is served in clear and review is
      the only control on that direction; an audited aggregate that is not ORM-mapped is outside the registry test.
- [ ] **The repository is public and now documents this posture in detail.** `ADMIN` reads the trail
      that audits it, the bootstrap provisions exactly one administrator, the trail is not
      tamper-evident, and the PR/issue history carries reproductions of defects found in review.
      None of it is exploitable without an authenticated `ADMIN` and there is no production
      deployment today, which is why it was published deliberately rather than withheld. **Re-assess
      before the first customer or any public deployment**, whichever comes first: decide per item
      whether it stays public, and remember that redaction after indexing is not retroactive.

- [ ] **The adversarial pass on security, GDPR and audit-surface work is no longer required, and nothing
      enforces it.** Retired by decision on 2026-08-31 (PR #903): the `PreToolUse` hook, the
      `pull_request` workflow, the script and its make targets are all deleted, and `CLAUDE.md`'s
      requirement became a recommendation. This is a **consciously accepted** removal of a control, not
      an oversight. **Accepted 2026-08-31 (Sergio)**, on the basis that a developer who knows the rule
      does not need a machine to hold them to it; recorded here because it is the kind of thing a reader
      of this file must not have to infer from a diff. What it costs, measured rather than supposed: the
      gate existed because prose alone lost **three** times with the author knowing it each time (#616
      merged with its pass arriving in #618, which then merged without its own — and that follow-up pass
      found a GRAVE; #770's fix and record landed nine and ten minutes after the PR opened, under a body
      claiming otherwise; and BR-2/#650 earlier, under a weaker wording, returned three GRAVE from a pass
      that ran after the PR opened). A **fourth** case, `img-1-2`, happened with the gate live and is the
      one that matters most here: it opened ahead of its external pass by explicit instruction, carrying
      a self-administered record that satisfied the form the gate measured. What it does **not** cost: the gate only ever proved the *form*
      — a record present on the branch — and never whether the read was hostile, whether the findings
      were real, or whether anyone but the author was involved. The surviving control is the three-layer
      code review, which `CLAUDE.md` owes to every story **and, since 2026-09-01, to any change touching
      security, GDPR or audit surface even when it is not a story** — the gap that extension closes is that
      a chore, a hotfix or a docs PR over auth code has no story to attach a debt to, and the PR that
      retired the gate was itself exactly that shape. **Re-assess before the first customer**:
      decide whether an unenforced convention is the control you want over auth, erasure and audit code.

- [ ] **A request or worker message that leaks a transaction loses the audit rows written inside it.**
      `RequestTransactionBoundaryListener` and `MessageTransactionBoundaryListener` roll back a transaction a
      unit of work left open and report it at `critical` on the `observability` channel, naming the route or
      message class. The `kernel.terminate` writers (`AccessLogAuditListener`, `RecoveryThrottleAuditListener`)
      run after that rollback and keep their rows. What the unit wrote **inside** the leak does not: a use case's
      `AuditLogger` row (a module's `activity` row among them) is rolled back with the work it describes, so the
      trail has no entry for an action that, consistently, did not commit either. A request-boundary security
      row is not lost but refused — `RequestBoundarySecurityAudit` will not write inside an open transaction and
      answers 5xx. **Detection:** the `critical` line is the only record that the unit ran; nothing correlates it
      with the missing row. **Residuals:** a handled message whose handler leaks is redelivered after the
      transport's redelivery timeout and repeats any side effect outside the database each time (accepted by
      the owner on #1036); a batch handler, and a leak after the worker's running tick, are not contained (none
      exists today); only the default connection is covered.

- [x] **A client chose its own IP through `X-Forwarded-For` wherever its connection reached PHP from a
      private address, closed 2026-10-23.** `compose.yaml` trusts every RFC1918 range as a proxy and prod
      inherited it, while FrankenPHP is the edge and runs PHP inside the Caddy that accepted the connection — so
      no hop exists that could vouch for the header, and the client's own value reached `getClientIp()`
      whenever the TCP peer was private: Docker's userland proxy forwarding an IPv6 client to the IPv4 bridge,
      a hairpin, a NAT. That is the key of `login_throttling` (both its limiters), `anonymous_api` and every
      other IP-scoped budget, and the `ip` the audit trail and the session registry record. `compose.prod.yaml`
      now sets `SYMFONY_TRUSTED_PROXIES` empty; a deployment with a real load balancer names that address
      alone (`.env.prod.example`). `ProdTrustedProxiesGateTest` holds the files to that: the prod overlay
      declares it with an explicit empty default, no other root compose overlay widens it, the template ships
      no active line, and nothing under `config/`, `src/` or `public/` sets the trusted proxies itself. **Residuals:** the gate reads files, never the
      deploy, so a deployment can still widen it from its own env file; and an IPv6 client still reaches PHP as
      the bridge gateway when the `frontend` network has no IPv6, so all such clients share one limiter bucket —
      an over-limit and a denial of service between them, no longer a bypass. Enabling IPv6 on that network, or
      `userland-proxy: false` on the daemon, is a host decision this repository cannot pin.

- [x] **A request body reaching PHP is bounded at 1MB, closed 2026-10-23.** Symfony reads a JSON body whole,
      so an anonymous POST to the login route could cost a worker as much memory as the client cared to send.
      `request_body` in `api/frankenphp/Caddyfile` now caps every request the front controller serves, keyed on
      the rewritten `index.php` rather than on an `/api*` spelling, because `/index.php/api/…` reaches the same
      routes. `RequestBodyBoundGateTest` observes it through the running Caddy, on both spellings: a body under
      the bound reaches the login whole (401), one over it never does. **Residuals:** the gate observes the
      development Caddy, never the prod image, and pins the bound between 0.9 MB and 2 MB, not where exactly;
      `{$CADDY_SERVER_EXTRA_DIRECTIVES}` and `{$CADDY_EXTRA_CONFIG}` can add a `request_body` of their own from a
      deployment's environment; bodies to the PWA, the Sentry tunnel and the hub are outside the bound; a client
      past it sees its request fail rather than a guaranteed 413. The image write routes have their own
      cookie-gated matcher, `@imageWrite`, bounded at `erpify.images.max_input_bytes` (20 MiB) and kept in step
      with its `@boundedBody` exclusion and the three planned public paths by `UploadRoutePathAgreementGateTest`;
      **the matcher is proved on text, and its through-Caddy measurement (a body over 1 MB reaching PHP whole)
      is `BankLogoThroughCaddyTest`, beside the bank logo route.** A body Caddy cuts reaches PHP
      as a clean, shorter stream, so what refuses a truncated image is the mandatory `Content-Length` equal to the
      bytes read (`ImageRequestBodyReader`, 411 `image-length-required`), not the bound.

- [x] **The persisted lockout confirmed a correct password, and so did the suspended and deactivated walls,
      closed 2026-11-02.** Each was read AFTER the firewall verified the password, so the right password was the
      one answer that differed (a 403) while nothing counted the guesses: a locked identity records no failure
      and a walled one is never locked. All three are now refused before verification with the uniform 401 behind
      the `PreIdentityTimingFloor` — `SUSPENDED`/`DEACTIVATED` in `UserChecker::checkPreAuth`, the lock in
      `LockedIdentityLoginRefusalListener` on `CheckPassportEvent`, because `Security::login()` runs
      `checkPreAuth` too and the recovery-secret redemption signs in before it lifts the lock. D12 of
      `identity-invitation-lifecycle.md` was re-decided to match: on login a password proves no identity a
      refusal may reveal. Pinned by `login.feature` (correct password on each state → 401, no cookie, the lock
      neither counted nor extended), `LoginPreIdentityOpacityFunctionalTest` (byte-identical body and
      `Content-Type` across every pre-identity case) and the unit tests of both classes, which pin the timing
      floor structurally (wall-clock assertions are banned here). **Residuals:** a status write
      landing between verification and the session mint still answers `account-suspended` /
      `account-deactivated`, a race on an administrator's write that `DbalSessionSubjectLock` grades rather than
      leaks; a walled or locked member typing the right password sees a stranger's message — a locked one learns
      why from the lockout notice and can reset, a walled one only from the administrator or by redeeming a
      recovery secret, since the reset flow mints no token for a non-`ACTIVE` identity; the lock is read from the
      snapshot the request resolved, so guesses already in flight when the tenth failure commits are still
      verified, and a correct one among them signs in and clears the lock (not new: the post-verification read had the same
      window, and re-reading at the mint would refuse the redemption, which signs in before it lifts the lock);
      and the floor equalises the dominant term (one unit of password hashing) but not the failure write: a
      wrong password on an unlocked `ACTIVE` account also updates its row, which a refusal does not — the same
      residual every pre-identity 401 here already carries.

- [x] **The login throttle was declared and never spent, closed 2026-11-02.** `login_throttling` peeks its budget
      on `CheckPassportEvent` and spends it on `LoginFailureEvent`, which `AuthenticatorManager` dispatches only
      after the failure handler returns — and `ProblemDetailsAuthenticationFailureHandler` threw, to reach the
      RFC 9457 pipeline on `kernel.exception`. So no failure ever spent a token: neither the 5-per-minute bucket
      per address+IP nor the 25-per-minute one per IP bounded anything, the handler's throttled branch was
      unreachable, nothing rate-bounded one source tripping any account's lock, and every document citing the
      throttle as a bound was citing an inert control, with the 401 uniform and every test green. The handler
      now records the attempt and RETURNS a placeholder response, the event is dispatched, and
      `UniformLoginFailureListener`, its last listener, throws the same uniform 401 — so the failure still reaches
      `kernel.exception`, and with it the error tracker's own listener, which a response built in the handler
      would have bypassed. The listener acts only on a failure that handler answered (a request marker), so an
      authenticator added later keeps its own failure response. Pinned by `LoginThrottleSpendFunctionalTest`,
      which peeks the firewall's real budget from a listener between the throttle and the thrower, asserts one
      token spent, and asserts the thrower is the last listener actually registered, and by
      `UniformLoginFailureListenerTest`.
      **Residuals:** a person who mistypes five times within a minute is refused for up to a minute even with the
      right password, with the uniform message and no hint, since a distinguishable "too many attempts" is the
      oracle D10 forbids; a shared address (NAT, an office) answers every login with the 401 for up to a minute
      after 25 failures from it; the throttle **attenuates the lockout denial of service, it does not prevent
      it** — the per-address bucket is a fixed window anchored on its first hit, so five failures at the end of
      one window and five at the start of the next are the ten that lock an account within seconds, re-armable
      every fifteen minutes, and the per-IP bucket lets one source lock a few accounts a minute (the persisted
      lock carries no source dimension by decision, `administrative-recovery-channel.md`); the key is the full
      client IP, so an IPv6 client rotating inside its own /64 gets fresh buckets; the budget lives in
      `cache.rate_limiter` (the app pool, a filesystem under `var/cache` in prod), so it is shared by one
      container's workers, split across replicas and lost on a cache clear or a deploy, and without
      `symfony/lock` concurrent requests may over- or under-count by a few, as `rate_limiter.yaml` records for
      the other limiters; and a throttled
      attempt answers fast — it pays no floor, being refused before the identity is resolved — which reports the
      bucket's state for the address as typed and IP, the same for an address that exists and one that does not,
      so it is no existence oracle.

- [x] **The login throttle keyed on the address as typed, not as resolved — closed 2026-11-25.**
      `login_throttling` bucketed on the username lower-cased, while `Email` also strips edge whitespace and
      zero-width characters and normalises to NFC, so spellings resolving to one identity each drew a fresh
      per-address budget and the per-IP bucket (25 a minute) was the only bound on one identity from one
      source. `CanonicalLoginRateLimiter` is now the firewall's `login_throttling.limiter`: it keys the local
      bucket by `RecoveryBudgetKey::forEmail()`, the rule every other per-address budget here already uses, and
      keeps the framework's two fixed windows (5 per mailbox and client address, 25 per client address), now
      declared as `login_local` / `login_global` in `rate_limiter.yaml` because a custom limiter replaces the
      firewall's own block. Keys stay HMACs, so neither the mailbox nor the client address reaches the cache
      pool in clear. Pinned by `CanonicalLoginRateLimiterTest` (seven spellings of one mailbox share one budget,
      a non-address still spends one, the stored keys name neither the mailbox nor the address) and by
      `LoginThrottleSpendFunctionalTest`, which drives the limiter the container wires. **Residuals:**
      the HTTP path itself is not covered end to end, because the test kernel keeps `cache.rate_limiter` on an
      array adapter that the services resetter empties between requests, so a sequence of requests cannot
      observe an accumulating budget; and the secret behind the HMAC is `kernel.secret`, so rotating it resets
      every bucket; and a request with no client address at all shares one bucket per kind with every other such request
      (`getClientIp()` is `null` only where no `REMOTE_ADDR` exists, which a FrankenPHP deployment does not produce, but
      a trusted-proxy misconfiguration that collapses every client onto the proxy's address has the same shape, and
      the framework's default limiter had it too). The persisted lockout keys on the resolved row and was never
      affected.

- [x] **`/forgot-password` latency separated an active account from every other address — closed.**
      `RequestPasswordReset::request()` ran a `FOR UPDATE` transaction (supersede, insert, event publish) and a
      synchronous mail send before the 202 for an `ACTIVE` address only; the floor was added to every branch
      rather than padded to, so it hid nothing. Now `RequestPasswordResetController` does the same in-band work
      for every address (budget claim, `PreIdentityTimingFloor`, one request attribute) and
      `PasswordResetRequestListener` runs the whole use case on `kernel.terminate`, after the response — the
      route the recovery-throttle audit projection already took. The plaintext token is still minted and mailed
      in one process and never dispatched: `SendEmailMessage` stays unrouted. **Residuals:** (a) a request
      that dies before its terminate phase loses the reset, indistinguishable from a refused mail — the
      requester re-asks; (b) the work still occupies the worker after the response, so a requester who can
      saturate workers could infer load, not account state; (c) a failure after the response is reported on the
      `observability` channel and never reaches the requester; (d) **unmeasured against a real relay** — the
      closure is by construction (no differing work before the response), not by a paired timing run; (e) the
      guarantee rests on the SAPI flushing the response before terminate listeners run.

- [x] **Production CSP admitted inline script — closed.** `script-src` was `'self' 'unsafe-inline'`, so the
      policy added nothing against an injected script. `pwa/src/proxy.ts` now mints a nonce per request and
      sets `script-src 'self' 'nonce-…' 'strict-dynamic'` (no `'unsafe-inline'`; `'unsafe-eval'` only in
      development) on the forwarded request, the response and `x-nonce`; `next.config.ts` no longer emits a CSP
      (two headers intersect), the root layout reads `headers()` and hands the nonce to next-themes. `img-src`
      is `'self'` plus the API origin. Pinned by `pwa/tests/proxy.test.ts`,
      `pwa/tests/context/shared/content-security-policy/` and `pwa/tests/next-config-headers.test.ts`.
      **Verified** against `next build` + `next start` in Chromium: `/`, `/login`, `/status` and a 404 send a
      fresh nonce, every `<script>` carries it, no CSP violation, hydration and the theme toggle work, and a
      parser-inserted un-nonced inline script is blocked. **Residuals:** `style-src` still has `'unsafe-inline'`
      (React `style` attributes cannot take a nonce); every route is now rendered per request (the 67 static
      routes became dynamic — a cost accepted for the control); `'strict-dynamic'` makes any nonced or
      script-created script trusted, so an injection that reaches a trusted script's DOM sink still runs;
      browsers without `'strict-dynamic'` fall back to `'self'`. Not verified: the Docker/Caddy path, the dev
      server (HMR with `'unsafe-eval'`), or `/monitoring` delivering a real event to Sentry.

- [x] **The Mercure subscriber cookie outlives the session that obtained it — closed.** The two realtime
      authorize controllers set `mercureAuthorization`, a JWT the hub accepts without checking revocation.
      `POST /sessions/revoke-current` now expires it (`Authorization::createClearCookie()`, best-effort so a
      logout never fails), and `mercure.default_cookie_lifetime` is pinned to 900 s (it was unset: with
      `session.cookie_lifetime` at 0 symfony/mercure 0.9 issued a session cookie whose JWT `exp` was +1 hour).
      **Residual:** revoking *other* sessions, a suspension or a deactivation cannot reach another browser's
      cookie, so that holder can open new private bank and bank-account streams (ids only, no IBAN or holder
      name) for up to 15 minutes, and a stream already open keeps delivering until it closes (the hub checks
      the JWT only at connect time); the PWA's re-authorise is refused once the session is gone. The
      15-minute value is set in `mercure.yaml` and no test pins it. Both authorize
      endpoints write the same cookie name and path and `createCookie()` does not merge, so authorising one
      channel replaces the other's topics — harmless while only one stream is open per page.

- [ ] **A minted session can still end unreachable, in two narrow ways, and the mint's admission rule lives
      twice.** `StartSession` retires the session the device's cookie carried in the same transaction that
      mints the new one — and, when the identity no longer admits a login once its row is held, it still
      commits that retirement before refusing, since the minting listener drops the native session either way
      and the cookie is the same proof of possession the success path relies on. What stays open is bounded by
      `PruneRetiredSessions` (~97 days after the login, see §6): **(a)** a failure writing the new correlation
      after the commit leaves the NEW row `ACTIVE` with no cookie naming it; **(b)** a store failure that rolls
      the mint back rolls the retirement back with it, while the listener still drops the cookie, so the
      EARLIER row is left `ACTIVE` and equally unreachable. Neither grants anything — no bearer holds either
      row — but each is listed in _Active sessions_ as a device until the sweep removes it. **The login waits
      behind any open write of the identity's row** (`FOR SHARE` in `DbalSessionSubjectLock`): the status,
      role, password, reset, recovery and unlock writers and the failed-login counter already take the row
      `FOR UPDATE`, which `FOR KEY SHARE` waited for too; the invitation acceptance, the lockout-notice stamp
      and the password-rehash swap take only a plain `UPDATE`, so a login now also waits for those
      single-statement writes. **The admission rule is coupled** to `User::ensureActive()`, because the session
      context may not read the aggregate: `DbalSessionSubjectLockAdmissionTest` holds the two in agreement over
      every `IdentityStatus` case and reds on a new one, but a rule changed consistently in both places passes,
      and review is the only control on whether that change is right.

- [ ] **Accepted risks watched by an open issue — the register.** Each row is a residual deliberately
      accepted rather than fixed, and each issue stays **open** for as long as the acceptance stands: it is the
      artefact that holds the revisit trigger. `.github/workflows/accepted-risk-live-state.yml` reads every
      row of the table below — on each pull request, on each push to `main` and daily — and reds when a row's
      issue is closed, so a closed issue means removing its row in the same change. It also refuses a
      register it cannot read in full: the first cell stays `[#N](https://github.com/<owner>/<repo>/issues/N)`,
      the bold lead-in above and the `Issue` header are how it finds the table, and the table stays one
      unbroken block, with no blank line, comment or other text inside it or after it within this item.
      Beware of GitHub's closing keywords: a pull
      request or commit saying "closes #N" or "fixes #N" closes that issue on merge — after the pull request's
      checks have run — which is how #418 was once closed by a change that only documented who would close
      it. The job therefore also refuses a pull request whose body (or sidebar link) will close a watched
      issue while its row or tag still stands; a keyword in a commit message is caught only after the merge,
      by the `main` run. Closing one means either fixing the risk or re-deciding it — never tidying the
      backlog. The reasoning lives in each issue; this list exists so a reader of §7 sees every watched
      acceptance in one place. **Accepted** states who accepted it and when **only where the issue records it**;
      `not recorded` is a gap in the record to close, never an acceptance by default. No row is accepted against
      a customer, because none exists yet — each has to be re-affirmed or closed before the first one.

  | Issue | Accepted risk | Revisit when | Accepted |
  |---|---|---|---|


- [x] **A production restore could take the scriptable non-production path, closed 2026-10-23.**
      `restore-prod.sh` classified its target from the FIRST `SERVER_NAME` in the env file, with its
      own parser, and never refused a duplicate — `restore.prod` does not run `prod.env.check` — while
      Compose serves the LAST. A file declaring `SERVER_NAME=erpify.local` and then a real domain, or
      a comma-joined `prod.example.com,erpify.local` (Caddy's own list syntax, which the word split read
      as one local token), let `RESTORE_YES=1` skip the typed confirmation and `ALLOW_PROD_RESTORE`
      against a live database. The guard is now `scripts/deploy/lib/prod-env.sh server-is-local`: last
      occurrence, blanks AND commas, scheme/port/brackets/case understood, and an empty, absent or
      duplicated `SERVER_NAME` counts as production. Pinned in `ProdEnvReadingGateTest`; that the script
      calls it is pinned in `ProdEnvProjectGateTest`. **Residual:** the guard reads the env file, not an
      exported `SERVER_NAME`, which Compose would prefer.

- [ ] **Backup and restore re-derive their compose project on every run, from the env file's TLS
      profile.** `prod-env.sh project` answers `erpify-prodlocal` for a `tls internal` file and the
      checkout's own project otherwise; nothing records which project a stack was STARTED under. Editing
      `CADDY_SERVER_EXTRA_DIRECTIVES` between a stand-up and a `make backup.prod`/`restore.prod` therefore
      moves those commands to the other project — a backup of an empty stack, or a restore into one.
      Mitigated by the guide's rule that a box is promoted only after `make deploy.local.down`, and by
      `make ENV=prod docker.info` printing the resolved project. Closing it means recording the project at
      stand-up (a label or a state file) and refusing a run that resolves a different one.

- [ ] **The domain security signals reach Sentry, nothing alerts on them, and it is accepted.**
      `SentryEventFilter` reports the domain `Unauthenticated` (401), `Forbidden` (403, `AccountSuspended` among
      them) and `RateLimited` (429) raised on an HTTP request, together with the framework's own 401/403/422,
      so a guessing run or a lockout wave arrives as events (`docs/api-error-contract.md` → "Client-error
      suppression in Sentry").
      No alert rule exists in the tree or is provisioned by any deploy step, so nobody is paged. Sentry
      groups by exception type and stack, never by account or source address, so even a frequency alert
      sees a volume — it cannot tell one account sprayed from many accounts probed — and a burst spends the
      event quota the other issues share. **One 401 is not reported at all:** `SessionNoLongerActive`
      (`session-expired`) implements `RoutineOutcome`, because every returning user raises it; a stolen
      cookie replayed after its owner revoked it raises the same exception and leaves no signal. The row
      still knows the difference (`status`, `revokedAt`, `expiresAt`, until the retention sweep deletes it),
      but `SessionAdmissionGate` reads it through `findActiveById()`, which filters on `status = ACTIVE AND
      expires_at > now` and so answers null for all three causes. Closing it needs frequency alert rules on
      those issues plus a rate limit per issue, and — for the revoked-cookie case — a gate that tells revoked
      from expired and emits a deduplicated security event carrying no session id.
      **Accepted 2026-10-26 (Sergio):** no customer, and the Sentry organisation is on the free plan, so no alert
      rule and no per-issue rate limit is configured. The control in their place is **triage by hand**: every issue
      is read, and none is resolved without a comment on it stating why it occurred and how it is handled — the PR
      that fixed it, or why it is expected and what already answers it — swept at least weekly and before every
      deploy, inside the retention window. That comment trail is the data this entry's alert rules are to be
      designed from: which issues recur, at what volume, and which of them ever meant an attack. Sentry ages events
      out after the plan's retention window, so each verdict is also a row in the ledger of
      [`docs/runbooks/sentry-issue-triage.md`](docs/runbooks/sentry-issue-triage.md), which holds the procedure.
      What it does not buy is detection while nobody is looking, and the quota burst is unbounded: a guessing run
      can spend the month's events and hide every later issue until the quota resets. Each sweep reads the
      organisation's dropped-event stats first, because nothing else would notice the quota running out. Re-assess
      before the first customer, on leaving the free plan, or the first time a sweep finds the quota spent.
- [ ] **A restore from an older backup resurrects keys destroyed since, and nothing detects it.** The
      crypto-shredding keystore lives in the database being restored: `DbalKeystore::destroy()` sets
      `destroyed_at` and nulls `wrapped_dek` on the row, and a dump taken before that instant carries the row
      live. After the restore the subject's audit scope is readable again, while
      `DbalSubjectErasureReconciler` — an anti-join of destroyed keys against `GDPR_SUBJECT_ERASED` evidence,
      both restored together — has nothing to report. The erasure the application confirmed to the subject is
      undone with every gate green. It is not only the key: the same restore brings back the subject's identity
      and bank-account rows. The bound is the offsite retention window — **about three months** with the
      provisional tiers (daily 14, weekly 4, monthly 3; one dump per snapshot, counted in snapshots rather
      than calendar days) — which is why those tiers are **not lengthened without a privacy decision**.
      **Open — owner: Sergio; accept it in writing (who, when, against which customer) or close it before the
      first customer.** The only mitigation today is the operator procedure in `docs/vps-deployment.md`
      § Disaster-recovery readiness, gate 3, which depends on a person remembering and has three residuals:
      `restore-prod.sh` restarts `php` and `messenger_worker` itself and has no hold option, so the API serves
      resurrected data for the seconds before the operator stops them again (a `RESTORE_HOLD` option on the
      script would close that window and is a decision, not yet made); in a total loss the pre-restore audit
      rows cannot be copied out, so the list of erasures to repeat is **incomplete by construction** and rests
      on an off-VPS operator log that is itself personal data about erased people; and the reconciler stays
      green throughout. **Closing it needs a ledger of destroyed scope ids that survives outside the restore
      boundary** (append-only, opaque ids only — first check that no scope id is derived from a person id) and
      a post-restore step that re-destroys every id in it. **Closed for Documents (doc-7), still open for the
      identity, bank-account and audit erasures:** the Documents erasure writes the random scope id to a ledger
      on a SECOND host (a restic repository behind an append-only REST server, apart from the backup repository
      — a different repository from the offsite backups, not a copy riding with them), before it destroys the
      key, and `restore.prod` replays it over the restored database before any writer or sweep restarts, staying
      in recovery mode when it cannot be read in full (`docs/adr/documents-custody-log.md` D11,
      `docs/runbooks/documents-erasure-ledger.md`). Whether it holds is proved by the restore drill, which is a
      person's act: the `drill` proof record is not committed until the owner has run it.
      The per-document keys live in the same table, and the blobs are in no database dump; the Documents read
      switch stays closed until a valid `restore` record and a valid `drill` record exist.
      KEK rotation is not an available bound (re-wrap tooling is unshipped, § 1), and keeping the KEK beside an
      old dump makes a resurrected DEK decryptable, so the dump's retention is the only dial.
- [ ] **The offsite repository's append-only protects against deletion, not disclosure.** The VPS must hold
      the restic password and a credential that can read the repository to run a backup, so whoever
      compromises it can decrypt the whole offsite history of dumps, personal data included. That history is
      as long as the retention above. Closing it means a design where the VPS can write but not read the
      history (a per-run key, or a push-only relay), which is not designed; accepting it is a decision to
      record. Untested alongside it: whether the upload-only credential permits `restic backup` at all (it
      must take and remove lock files) — gate 2 below is the test.
- [ ] **A stream unseal hands out plaintext before it has verified the end of the object.**
      `SodiumStreamEnvelopeEncryptor::unseal()` opens one 64 KiB chunk at a time, so a stream cut short, extended or
      altered after its first chunks has already written an authenticated prefix to its sink when the exception
      arrives; only the FINAL tag proves the object is whole. Every consumer therefore treats its sink as discarded
      on any exception, and one that forwards the plaintext as it arrives (an HTTP response, a scanner stream)
      must decide, before it ships, what a partial delivery means. **Settled for the scan (doc-4) and for the audited
      read path (doc-5).** The scan's scanner is the only consumer of the stream and a scan that
      ends in an exception posts no verdict at all: a partial plaintext reaches clamd, but clamd's answer is
      read only after the producer completed (`ClamdScanner` sends the zero-length terminator after `unseal()`
      returned), a failed authentication or missing final chunk is `ScanFailed` (`DecryptionFailed`, an alarm,
      no retry), and a transport fault is a return with no verdict; none of them can make a document `Ready`.
      The same story owned the second face of this: an `unseal()` that has already unwrapped the DEK keeps
      opening chunks after a concurrent `destroy()` commits, so crypto-shredding holds for every read that
      starts after the tombstone and not for one already in flight. For the scan that is bounded and harmless:
      a scan in flight when a document is erased is aborted by its next heartbeat (30 s) or by
      `DekDestroyed` on the next chunk's key use, whichever first, and its verdict compare-and-set matches
      nothing on an `Erased` row, so the in-flight bytes go to the scanner only and never to a caller. The
      read (`ReadDocument`) settles the partial delivery with two passes: pass 1 unseals into a digest and
      compares sha256 and size with the row BEFORE the first byte, so a cut, extended or altered object is a
      refusal with a status, and pass 2 re-hashes while streaming and withholds the last byte until the whole
      stream matched, so any abort after the headers is a body one byte short of its `Content-Length`. **What
      stays open is the second face, for the read:** a read already past its key unwrap keeps opening chunks
      after a concurrent `destroy()` commits, so bytes of an erased document can still reach the caller who was
      reading when the erasure committed (the abort comes on the next key use or when pass 2 re-checks nothing
      has changed, and no read is serialised with erasure); reads that start after the tombstone get `gone`.
      Whether to serialise readers with erasure is the erasure story's decision. Two neighbours, both accepted by construction:
      the plaintext chunk buffers are ordinary PHP strings that are not wiped (of the key material, the DEK and
      the stream state are wiped with `sodium_memzero()` on a best-effort basis, since the copy libsodium returns
      from `init_push()`/`init_pull()` may remain in memory until PHP frees it), and the per-document keys are
      wrapped by the same `AUDIT_KEK` as the audit keys, the only KEK the module has, so one key-encryption key
      protects both; separating them is a rotation decision with no owner yet.
- [ ] **The Documents scan holds evidence plaintext in places no control here reaches.** The scan
      unseals an original and streams the plaintext to clamd, so for the duration of a scan it exists in the
      worker's memory, on the TCP connection between the worker and `scanner`, and in clamd's memory and its
      `TemporaryDirectory`. What is closed: the directory is a tmpfs (`/run/clamav-scan`, 512 MiB), the
      scanner's `memswap_limit` equals its `mem_limit` (no swap to page plaintext into), core dumps are off for
      the scanner, `php`, `messenger_worker` and `scheduler_worker`, and nothing in the application writes
      plaintext to a file (`DocumentScannerLimitsGateTest` holds the declarations). What is not: **(1) the
      host's `kernel.core_pattern`** is one setting for every process on the kernel: a piped handler
      (systemd-coredump, apport) is the host's, not the container's, and whether it honours the container's
      `ulimit -c 0` is the handler's choice. The VPS's pattern has not been read; **(2) tmpfs
      pages and process memory** are readable by root on the host and by anything with `CAP_SYS_PTRACE`, and
      the plaintext chunk buffers are ordinary PHP strings that are not wiped; **(3) the hop is unencrypted**:
      `tcp://scanner:3310` crosses the `backend` network, which is internal (no route out, no published
      port) but not a trust boundary against a co-resident container; **(4) the scanner is on the egress
      network** because the image's `freshclam` refreshes signatures from `database.clamav.net`, so a compromised
      clamd could exfiltrate what it is sent; narrowing that means a signature mirror reachable from the
      backend network and a scanner with no egress, which is not built; **(5) a signature reload pauses
      scanning** (`ConcurrentDatabaseReload no`, chosen so the container's 2 GiB suffices) and documents wait
      `Received` for those seconds, deliberately. **Open — accepted while no deployment is live; closing (1)
      is a host configuration check before the first customer, (3) and (4) are design work with no owner.**
- [ ] **The Documents scan has five operational blind spots, each accepted by the design of doc-4.**
      **(1) Outage detection latency:** a scanner that fails its probe claims nothing and logs nothing, so the rows
      it has not yet claimed are reported only by the stuck-document bound (10 hours); the outage-return alarm
      (5 returns) covers only rows a claim already touched. **(2) Signature freshness is unmonitored:** if
      `freshclam` cannot reach the ClamAV mirror, clamd keeps answering `OK` from stale signatures and nothing
      raises it. **(3) A poison archive that kills clamd mid-stream** is accounted as outage returns (cap 10, about
      6.35 hours of backoff) rather than 3 attempts, because the re-probe finds the daemon restarting: the contract's
      accounting, and slower to reach `ScanFailed` than a per-document fault deserves. **(4) The scanner's tmpfs was
      not measured against an expanding archive:** 512 MiB against `MaxScanSize` 400M × `MaxThreads` 3 was proved
      only for 100 MiB flat streams, and a full tmpfs fails the scan (a return), not the host. **(5) A scan pass
      starts no document after 60 seconds, but the one in flight can run to clamd's `MaxScanTime` plus a 30 second
      margin** on the same consumer as the upload reaper, so the reaper's one-minute cadence can slip by about two
      minutes under a slow scan (the staging sweep's margin was computed with room for it). **Open — accepted;
      owner: the Documents scan.**
- [ ] **Nothing reconciles a Document tombstone until the Documents erasure story delivers the Document-driven
      reconciler.** `DbalSubjectErasureReconciler` excludes Document scopes by construction (a Document's
      erasure is recorded in its own custody, not as a `GDPR_SUBJECT_ERASED` entry) and no other reader takes
      them, so a Document scope destroyed without its custody record raises no alarm. **Open — owner: the
      Documents erasure story; it does not go live before that reconciler exists.**
- [ ] **A code rollback past the `dek_keystore.destroy_reason` migration breaks `destroy()`.** The migration's
      `down()` is not run by a code rollback, and the previous release's `destroy()` then violates
      `dek_keystore_destroy_reason_check` and fails. **Accepted — basis: no deployment is live, so there is no
      release to roll back to; revisit with the first production deploy.**
- [ ] **The Documents custody log is no longer absolutely append-only, and nothing but a source gate says
      so.** A person's erasure rewrites `document_custody.actor_id` (and `document.uploader_id`, and nulls the
      idempotency hash) to the nil UUID with one sanctioned `UPDATE`; `make php.lint.document-custody` holds
      the set of statements `api/src` issues against the log to that one. No database role separation,
      `GRANT`, `REVOKE` or trigger exists in the tree, so whoever holds the application's database credential
      — or a PostgreSQL administrator — can still rewrite or delete a custody row, and the gate sees neither
      a migration nor runtime-built SQL. Custody is not claimed cryptographically immutable; hash-chaining or
      a restricted role are separate decisions (`docs/adr/documents-custody-log.md` D3). **Accepted — the user,
      2026-11-11, moving the revisit to the first deploy that opens ingest. Basis (unchanged): no deployment is
      live, and ingest stays off in production until a restore proof exists, so no production document is filed
      yet.**
- [ ] **A document or custody row committed by an in-flight action of an erased person, after the purge
      statement's snapshot, keeps the person's id until the re-sweep.** The purge's `UPDATE`s match rows that
      exist when they run. For a document filed through ingest the window is closed by the lock order
      (`docs/adr/documents-custody-log.md` D6, every pair interleaved by `UploadLockOrderPairsFunctionalTest`):
      the purge takes the person's intent rows before their documents, so a commit either finished first and
      its document is rewritten by the next statement, or waits on the purge and aborts on an expired intent. It
      stays open for any later writer of a document or custody row that does not pass through the intent row.
      `ResweepErasedSubjects` repeats the statements for one window, and `DbalUploadIntentActorPersonReferences`,
      `DbalDocumentUploaderPersonReferences` and `DbalDocumentCustodyActorPersonReferences` report any id left
      once it closes, through `identity:gdpr:reconcile-subject-references`. Idempotency replay protection for the
      erased actor ends with the erasure (accepted: the hash is nulled by the same purge). **Open — owner: each
      later story that writes a document or custody row on a person's behalf outside the intent lock; the
      detective sources are the control meanwhile.**
- [ ] **The `document_storage` volume has no backup and no proven restore yet; ingest stays off in
      production until one is proven.** Evidence ingest writes sealed bytes to the volume
      (`DOCUMENTS_STORAGE_PATH`, `staging/` and `final/`), and a database dump is not a backup of evidence. What
      is in place: the entrypoint's storage boot probe (stage, promote, delete on the deployed volume, refusing to
      start on any failure, in `php` and in both workers), the switch `documents.ingest_enabled` (false in
      production, 404 on both ingest routes), and the proof-record format with its validator
      (`docs/runbooks/documents-restore-proof.md`; `DocumentsIngestProofGateTest` fails when tracked production
      configuration opens ingest without a valid restore proof for the declared backup mechanism). What is not:
      the backup itself and the first proof, which whoever proves a restore commits. The read route exists and
      brought its own switch, `documents.read_enabled` (false in production, 404 on the route), which
      `DocumentsReadProofGateTest` holds to a valid `restore` record AND a valid `drill` record with
      `ledger_replay_verified`, for the declared backup mechanism. **Open — owner: whoever provisions the evidence
      backup; the read route stays switched off until then.**
- [ ] **The Documents read route (doc-5): what its audit row, its cost and its switch do not cover.** (1) The
      `DOCUMENT_READ` row (level `security`, written after pass 1 and before pass 2) records that access was
      GRANTED, not that the transfer completed, and a read refused after authorisation (410, 409, 5xx) writes no
      row; an aborted transfer is an `observability` alarm and not an audit row. (2) Every read costs two
      decrypt passes over up to `MAX_ORIGINAL_BYTES` and the route has no per-caller rate limit, and each read holds a
      PHP thread for the whole transfer, bounded per stall (a 60 s budget re-armed at every chunk) and not in total,
      so a slow ADMIN client occupies a thread; the caller set is ADMIN only, which is the bound. (3) `DocumentsReadProofGateTest` reads the tracked configuration and the
      compiled prod container: a value the deploy environment injects is outside it, and the erasure-ledger
      override is a database flag (`erasure_ledger_override`, `DbalErasureLedgerOverride`, bound to the
      `ErasureLedgerOverride` alias — doc-7), which the gate cannot see either (it reads no service alias) — the proof
      records are the control. **Open — owner: whoever deploys, for the injected value.**
- [ ] **A sealed original filename names whoever it names, and survives a person's erasure until its document
      is erased.** The name travels in the evidence PUT's query, is validated and sealed under the document's own
      scope in the commit transaction, and is never persisted in clear; but it can carry the uploader's name or a
      third party's, and a person's erasure never erases a document (the uploader is not the subject), so the
      ciphertext stays until that document is erased. Its ciphertext LENGTH also leaks the name's length
      until then. Kept out of the access log (Caddy strips every query), the Monolog `request_uri` and per-error
      log line and Sentry's `url`/`query_string`/transaction data (`RequestUriRedaction::QUERY_KEYS`, and the whole
      query on the upload route), audit metadata and events. **Accepted — the user, 2026-11-04.**
- [ ] **The Documents listing and the custody trail return sealed original filenames with no byte-read audit row
      (doc-6).** `GET /api/v1/documents` and `GET /api/v1/documents/{id}/custody` open the sealed name of every
      listed document (the trail on its first page only) and answer it to an ADMIN (`document.custody`), but write
      no `DOCUMENT_READ`-class row: CAP-9 audits byte reads only, so the only record is the generic access-log row
      (`ROUTE_DOCUMENTS_LIST`, and `ROUTE_DOCUMENTS_CUSTODY` naming the document as its resource), which carries no
      name, digest or custody actor (it names the ADMIN who made the call, as every audit row does), is `activity`
      level and ages out with the audit retention. A listing therefore proves
      that a page was served, never which names a caller saw. **Gating is the erasure-ledger override alone:**
      `documents.read_enabled` does not close these routes (they serve no bytes), so after a restore that proceeded
      without the ledger an erased document's key is back and its name would open here; the override flag (a database
      row, doc-7) is what answers 410 on both routes while it is set. A name that does not open lists `null` with no log line from the request; the alarm is the
      daily `ReportDocumentNamesHandler` line on the `observability` channel (counts per cause and at most 20
      document ids, never a name), which opens the head of the table inside a 60 s budget and, when the run did not
      finish, writes a `warning` line with `complete: false` even if no name failed, so a store too large for one run
      is only covered where it reaches and says so. **Accepted — the user,
      2026-11-17 (ADMIN only; no listing audit row).**
- [x] **The identity erasure route did not re-prove the caller's password, though the Documents erasure did — closed 2026-11-25.**
      `POST /api/v1/documents/{id}/erase` already spent the shared `CurrentPasswordProofThrottle` budget and re-proved the
      caller's current password before any ledger write, because a stolen session could otherwise erase documents for good
      and evicting it undoes nothing; `backoffice_user_erase` erased an identity and its trail on the permission alone.
      It is now `POST /api/v1/backoffice/users/{id}/erase` carrying `currentPassword` (the shared HTTP client sends no body
      with a `DELETE`, and the document erasure already had a verb and path of its own), classified
      `credential-affecting` in `api/.credential-proof-policy` and pinned with the other erasures by
      `CredentialProofGateTest`. The proof precedes the id check, the self-erasure refusal and the lookup, so a
      malformed id, an absent identity and a self-targeted request all answer `403 invalid-current-password` to a caller
      who has not proved the password — the route is never an existence oracle. `erase.feature` pins the absent and the
      malformed id, and `UserEraseFunctionalTest` pins all three, the self-targeted one included; the spent budget answering 429
      to the correct password is `UserEraseControllerTest::aSpentBudgetRefusesBeforeThePasswordIsEvenChecked` and the
      last scenario of `erase.feature`. The console
      keeps its type-to-confirm step and adds the password dialog behind it (two brakes: which identity, and whose
      session). **Residuals:** `identity:gdpr:erase-subject` drives the same use case from the console with no session
      and asks no password — the proof is the controller's, as for the other two authority writes, so a holder of a
      shell is not stopped by it; `CredentialProofGateTest` reads call order inside the controller and cannot see
      whether the proof precedes the lookup *inside* a use case, so the Behat scenarios are the only control on that
      direction; and the shared per-identity budget (5 per window) means an administrator who erases several
      members in a row will meet a 429 from it.
- [ ] **The digest, size and timestamps of an erased document survive its erasure (doc-7).** Erasure destroys the key,
      nulls the sealed filename and deletes the sealed object, but the row keeps its sha256 digest, byte size, retention
      fields and timestamps, and its custody trail stays. Anyone who holds a known file can confirm it is the one
      that was filed (possession of a known file), and the trail says that an erasure happened, when and under which
      closed reason. Kept on purpose: the digest is what proves what was held. **Accepted — the owner's design, 2026-11-04.**
- [ ] **Derivatives of a document: what the release closes and what it leaves (doc-8).** A derivative is an `image`
      row carrying the origin `Documents.Document`, admitted only for a `Ready` document under `FOR SHARE`, and
      released after the erasure by a 5-minute tick that re-queries by origin until none is left, asking about a
      document again only four hours after its last request, with an `error` line eight hours after the `Erased` custody
      row, and a four-hourly sweep by origin releases derivatives whose document row is gone, whose release was already
      confirmed or whose document is not `Ready` (ADR `images-vs-documents-conservation-contract.md` D8). Five things stay
      open. **One — bytes with no row,
      narrowed.** A failure after the store re-reads the row and deletes bytes no committed row holds, handing a failed
      delete to the transport; what still strands them is a crash between the store and that re-read, a row that cannot
      be read (`images.derivative.discard_undecided`) and a queue that refuses the request
      (`images.derivative.discard_failed`) — both lines carry the image id, the one handle an operator has, which names
      a rendering of a document and no person. **Two — image storage restored on its own.** `backup-prod.sh` dumps the
      database and not `image_storage`, so restoring an older storage snapshot brings back derivative bytes whose rows
      are gone, and nothing keyed on an origin can find them; the same holds for any image. (A restored DATABASE is
      covered: the replay reopens the derivative markers, and the orphan sweep finds rows whose release was confirmed.) **Three — the classification
      of `image.origin_id`.** It is `non-person` because the only origin type names a document; a future origin type
      denoting a natural person makes it a person column, and only review would notice. **Four — what a deletion that
      keeps failing costs.** One request per image every four hours for as long as the row lives, each appended to
      `event_store` for ever and dead-lettered into `failed` for 30 days; the alarm names the document after eight hours,
      and nothing else stops it. **Five — nobody can read a derivative.** The fungible image route refuses an image with
      an origin (fail closed, since any authenticated session may call it while a document is ADMIN-only and audited),
      and no route of the owner exists; the first producer story decides who reads a preview and under which audit. The
      `ImageId` of a derivative also stays in `event_store` for ever, as item four of the `Shared/Images` residuals says
      for every image. **Open — owner: the Documents epic (the first derivative producer).**
- [ ] **Document versioning: what the series erasure, the successor exclusion and the series page do, and two things
      the closing does not carry (doc-9).** One to four state behaviour a reader of the routes would otherwise have to
      infer, and none is an accepted risk; five and six are open.
      **One — a series erasure terminates.** `POST /api/v1/documents/series/{seriesId}/erase` first closes the series
      (`document_series.closed_at`, one statement in its own transaction, outside every erasure transaction so
      `ErasureLimits::TRANSACTION_LOCKS` stays 3), and only then lists and erases every version that is not `Erased`,
      newest first, each in its own ledger entry and transaction. A take that finished before the closing is listed;
      one that starts after it is refused (409 `document-series-closed`), so no version is filed or reopened in a
      series whose erasure has begun — a failed first version included, whose reopen takes the series row for that, and the closing is definitive except for one ending: when the call that
      closed the series fails with its first ledger entry certainly not durable and has erased nothing, it opens the
      series again before the 503, so an intact series is not left closed for ever by an unreachable ledger. Any other
      failure stops the run where it stands (the versions already erased stay erased, the series stays closed) and the
      operator reads `remaining` and repeats.
      **Two — a slot conflict is retryable.** The successor exclusion reads the slot without a lock under the series
      lock (ADR `documents-custody-log.md` D12), so an erasure or a quarantine committing concurrently can make a new
      version or a reopen answer `document-version-slot-taken` for a slot that is free a moment later: a conflict the
      caller retries by declaring again, never a double successor, since no path makes a version live outside that
      lock. The refused commit's sealed bytes and key fall into the aborted-upload class (D6), whose tombstones are
      never pruned. **Three — who may file a version.** The new-version declare asks `document.write` AND
      `document.download` (RBAC, no row-level voter), so a tier that may file evidence but not download it cannot file
      a version of a document it could not read; the route answers an absent predecessor like a non-`Ready` one, so it
      is no existence oracle, and a refused declare still says that some live version holds the slot, to a caller who
      may read the document. **Four — the series page names uploaders.** `GET /api/v1/documents/series/{seriesId}`
      (ADMIN, `document.custody`) returns the uploader id of every version that is not `Erased`, as the listing does
      (an erased version's is withheld by the statement, and an erased person reads as the nil UUID), with the generic
      access-log row as its audit record. **Five — the closing is a database fact only.** `document_series.closed_at`
      is written by the series erasure and by nothing else, and neither `ReplayErasure` (the restore step and the
      completion sweep) nor a restore to a dump older than the closing, nor a down and up of the migration, brings it
      back: versions that survived a partial series erasure can then accept successors again until the erasure is
      run again. The ledger keeps the scope id of each version erased, never the closing. **Six — a series erasure
      is one synchronous request over N versions.** Each version is durable and the run is repeatable, but a request
      cut off between versions surfaces as an unmapped exception and not as `ErasureOutcomeUnknown`, and the new-version
      declare and its commit do not consult `ErasureLedgerOverride`, as the first-version declare does not.
      **Seven — two concurrent runs over one series.** Run A closes the series and fails with its first ledger entry
      certainly not durable; run B, started after A's closing, saw it closed and so never reopens it, and B's own
      ledger write succeeds. A opens the series again under B, and a version filed before B's closing statement at the
      end of its run is live and was not in B's list. B closes the series again before it reads `remaining`, so the
      series ends closed and the version shows in `remaining`, which the operator repeats on. No lock orders two runs
      (`ErasureLimits::TRANSACTION_LOCKS` stays 3), and the window needs the ledger to fail for one run and answer for
      the next within the length of one run.
      **Open — owner: the Documents epic.**
- [ ] **Offsite backups keep the ciphertext of an erased document, and an erasure depends on the offsite channel
      (doc-7).** The sealed objects of an erased document are deleted from the volume, but a backup of the volume (or a
      database dump holding the wrapped key) taken before the erasure still holds the ciphertext; the residual closes
      only by the key staying destroyed on every restore, which is the ledger's job. Because the ledger entry is written
      to a second host BEFORE the key is destroyed, an erasure cannot be performed while that host is unreachable
      (`erasure-ledger-unavailable`, 503): erasures are rare and the legal term is a month. **Accepted — the user, 2026-11-05.**
- [ ] **Whoever holds the erasure ledger's write credential can erase a live document with no voter (doc-7).** The
      credential and the repository password live on the VPS (the erasure writes the entry; the completion sweep lists
      the ledger from there), so a VPS compromise yields them, and an entry appended for a LIVE scope is finished by the
      completion sweep within minutes: the document's key is destroyed and its row `Erased` with the reason
      `ledger-completion` and no actor. Signing the ledger was declined as YAGNI. A stock append-only REST server has no
      read-only credential (measured), so the sweep and the restore use the same one. **Accepted — the owner's design.**
- [ ] **The ledger is listed whole under a 60 s limit, every tick, with no cache (doc-7).** Every restic call runs `--no-cache`
      and the completion sweep lists ALL snapshots every five minutes, so its cost grows with the number of erasures ever
      performed and nothing prunes it (an append-only repository cannot be). Measured on a local `rest-server --append-only`
      (loopback, tmpfs, restic 0.18.1, one run each): `record()` 0.63 s with one entry, 0.56 s at ~200, 0.62 s at ~1,000 and
      0.73 s at ~5,000; `entries()` 0.55 s, 0.57 s, 0.62 s and 0.79 s. Against the 10 s write timeout and the 60 s listing
      limit that is two orders of magnitude of room on loopback, **but the numbers carry no network latency**: `snapshots`
      reads one file per entry, so over a WAN link the listing grows by roughly the round-trip time per entry divided by the
      connections restic opens. **Accepted growth limit: about 2,000 entries (erasures are rare and the legal term is a month)
      until the second host is measured over its real link; past it, re-measure before it silently becomes the alarm line
      "cannot be read in full".** Pagination or a ledger of segments is the design that removes the limit, and is not built.
      **Open — owner: the owner, at the restore drill (record `snapshots --json` latency from the VPS).**
- [ ] **The erasure ledger is a single copy, a deleted entry leaves no trace, and a leaked repository password cannot be
      rotated while the repository is append-only (doc-7).** Losing the ledger fails closed: `restore.prod` stays in
      recovery mode, and only the documented override lets a restore proceed, accepting that keys destroyed since the
      backup may be live again (reads answer 410 for as long as the override stands; a person clears it with
      `documents:erasure:clear-override`, which refuses unless the ledger is readable in full and replayed, and which
      holds vacuously for a new empty repository, so the decision to clear is the operator's). The append-only server is the only
      control on a deleted entry — `restic check` of a repository missing a snapshot file finds nothing wrong. `restic key
      remove` is refused, so rotating the repository password needs an administrator session on the ledger host
      (`docs/runbooks/documents-erasure-ledger.md`). An independent copy of the four ledger credentials is kept off the
      VPS and the drill proves the ledger readable from it alone. **Accepted — the user, 2026-11-05 (single copy).**
- [ ] **Idempotency replay protection ends with the actor's erasure.** The person purge nulls the key hash on
      every upload intent and document of the actor, so a replay of an erased actor's key is a fresh upload.
      **Accepted — the user, 2026-11-04.**
- [ ] **Aborted-upload keystore tombstones are never pruned.** Every claimed upload that does not become a
      document leaves one `dek_keystore` row (`destroy_reason = aborted-upload`), including a claim whose key was
      never minted — pruning one would let a late writer mint a live key under a scope believed dead. Growth is
      watched, not bounded: the daily `ReportDocumentStorageMessage` logs the counts and an `error` line above
      500 a day. **Accepted — the user, 2026-11-04.**
- [ ] **A slow evidence PUT pins a PHP worker thread for up to its deadline, bounded by the in-flight caps,
      not eliminated.** 2 streaming uploads per actor and 4 globally, against a production pool fixed at 12
      threads (8 worker threads), so at most half the worker threads stream; a 100 MiB upload at the minimum
      accepted 256 KiB/s holds its thread for up to 460 s. The `upload_tmp_dir` tmpfs holds each upload's spool
      while it is read (destroyed when its response leaves — next entry but one) and is sized for a spool
      retained by every worker thread: 900m against a 2g, swap-free container. **Measured on the production image (2026-11-06):** four
      concurrent 100 MiB uploads at 256 KiB/s took 400.0 s each while unrelated `GET /me` answered in 6.1 ms on
      average (15.6 ms max; 6.7 ms / 29.9 ms at rest); eight retained spools (four real, four simulated) plus four
      concurrent maximum-size uploads left ~1.0 GB unreclaimable (shmem 868 MB, anon 99 MB, kernel 47 MB) with
      no OOM kill and no spool outside the tmpfs, the remaining use being reclaimable page cache of the document
      volume. **Accepted for the thread pin — the user, 2026-11-06.**
- [ ] **Two actors can hold the whole global Streaming cap.** Two actors at the per-actor cap (2) fill the global
      cap (4) and, trickling at the 256 KiB/s floor, can hold every slot for up to 460 s and repeat within the
      declare budget, so every other actor's claim is refused with 429 and no `Retry-After` for that long. Only
      authenticated callers holding `document.write` can do it; it is the consequence of the fixed pool (the
      thread-pin entry above). **Accepted — the user, 2026-11-08.**
- [ ] **An evidence PUT's plaintext spool is destroyed when its response leaves, except where it cannot be
      identified or no response is produced.** PHP caches every byte read from a request body in a `php*` file in
      `upload_tmp_dir` (from 16 KiB on), held open by the worker thread until its next request; and the server reads
      the rest of a refused PUT's body after the application returns, into the same cache (measured: an anonymous
      401, a 410 and a 422 each answered only once the whole body had arrived, and left a spool of it). So it would
      outlive its upload — filed, aborted, refused, aborted by a person's erasure — and a later crypto-shred.
      `DiscardUploadSpoolListener` reads the upload route's body to its end in the last response listener, and
      `PhpUploadBodySpool` truncates (freeing the tmpfs pages although the thread still holds the file) and
      unlinks the one spool whose size and leading bytes match exactly what was read. **Measured on the dev stack
      (2026-11-08):** no spool left after a filed 17 KB, 2 MB and 100 MiB upload, an upload aborted mid-body, an
      anonymous 401, a 410 and a 422 length mismatch, the tmpfs at 0 %; an upload streaming at 256 KiB/s while
      another completed was filed with its exact digest (`UploadSpoolConcurrencyGateTest`; destroying every spool
      instead aborts it with 422, measured). What remains: a request ending with no response (a fatal error, a
      killed worker) keeps its spool until the thread's next request; two in-flight uploads of the same bytes are
      both left, since either spool could be the other's; a body under 16 KiB never leaves memory; any other
      route's body (bounded at 1 MB by Caddy) is still spooled until the thread's next request (measured on
      `/api/v1/health`). **Those four: Accepted — the user, 2026-11-11.** The production image was not re-measured —
      the mechanism is PHP's own and the image runs the same PHP, but its process user differs; that is a stated
      fact, not part of the acceptance.
- [ ] **A PUT to the canonical upload path has its whole body received before its response; only a request
      carrying a session cookie may send 100 MiB.** Measured on the dev stack (2026-11-08): an unauthenticated PUT
      (401), a 410 and a 405 elsewhere answered only once the whole body had arrived, at the client's own pace, so
      a client trickling a body pins a worker thread for as long as it trickles, outside the Streaming caps the
      thread-pin entry counts on. **Mitigated:** the Caddyfile lifts the 100 MiB bound on that path only for a
      request carrying the session cookie (`PHPSESSID`); without it the PUT falls to the 1MB bound every other route
      has — measured, an anonymous 3 MB PUT is read to 1,000,001 bytes and the same PUT with a cookie to 3,000,000
      (`UploadPathAnonymousBoundGateTest`), while an authenticated 1.1 MB upload is filed
      (`UploadFilenameAccessLogContainmentGateTest`); `UploadRoutePathAgreementGateTest` pins the condition in both
      matchers against the cookie name the application issues. **What remains:** Caddy does not judge the cookie, so
      any request presenting one — an authenticated caller with `document.write` or without it, or anyone who sends
      the cookie name with a made-up value — keeps the 100 MiB bound until the application refuses it, and that
      refusal comes only after the body has been received. **Accepted — the user, 2026-11-11.**
- [ ] **A scan wake-up that cannot be published delays a document's promotion, not only a hint.** The wake-up is
      published inside the transaction that sets `promoted_at` (the bus refuses an `async`-routed message outside
      one, `RequireTransactionalPublishMiddleware`), so a down or misconfigured `async` transport, or a failed
      `messenger_messages` insert, rolls the mark back: the object has moved to `final` and the row stays
      unpromoted until a roll-forward tick retries it, which the scan keyed on `promoted_at` waits for. The delay is
      the roll-forward age plus the one-minute tick, and each failing row is logged by its id; the writer logs a
      warning with the document's id. The sweep stops after five failures in a row and never examines more than ten
      times its limit per tick, so an outage cannot hold the single-replica scheduler. **Recorded — a consequence of
      the transactional-publish guard (#872); revisit if the scan stops keying on `promoted_at`.**
- [ ] **Rotating `APP_SECRET` silently unmatches every stored idempotency key.** The key hashes on upload intents
      and documents are HMAC-SHA256 under `kernel.secret`, so after a rotation no replay matches its earlier
      declare: a client retrying across the rotation files the same evidence twice — a duplicate, never a loss —
      and nothing reports it. No separate secret is introduced. **Recorded — the user chose documentation over a
      separate secret, 2026-11-08.**
- [ ] **What a browser does with an early 422/410 while it is still sending the body was not measured.**
      `curl` stops on h1.1 and h2; a browser `fetch` may keep sending until the server closes. **Open — owner: the
      first UI story that uploads evidence.**

- [ ] **Console failures print a masked summary, and the mask is a floor.** Every command under an
      `Infrastructure/Cli/` directory prints `ThrowableSummary::of($throwable)` and never the throwable's own
      message, because a driver quotes the values involved and the output reaches a container log no erasure
      path touches. `ConsoleFailureMessageGateTest` refuses a `getMessage()` call there. The summary replaces
      UUIDs, address-shaped tokens and IPv4/IPv6 addresses; **it does not catch** a 32-hex id, a quoted local
      part containing a space, a compressed IPv6 form, or a name, holder or account number quoted inside a
      `Failing row contains (…)`. Closing that fully means printing only the SQLSTATE and the constraint name
      and dropping everything after `DETAIL:`, at the price of an operator-facing message that no longer says
      which value collided. **Open — owner: the operator-experience decision; revisit the first time a failing
      row is seen carrying a name or an IBAN in a log.**

## 8. Deploy & verify

- [ ] `make deploy.local` (or `scripts/deploy/deploy-local.sh`) reaches a 200 on
      `https://$SERVER_NAME/api/v1/health`.
- [ ] `docker compose … ps` shows every service healthy under the prod overlay.
- [ ] **`async` is transactional on every service that publishes.** For `php`, `messenger_worker` and
      `scheduler_worker` (each replica of a scaled one, `--index=N`) run
      `docker compose … exec -T <service> php bin/console event:transport:assert-transactional` and read the
      exit code rather than grepping the text: it must be 0, with `"async" is the Doctrine transport on the
      application connection` reported. The entrypoint already refuses to boot on a bad
      `MESSENGER_TRANSPORT_DSN`, and the publish guard refuses the publication itself on a process that
      skipped it, so this box is the early, loud reading of what the guard would otherwise report one request
      at a time: a service whose compose definition replaces `entrypoint:` or runs a command other than
      `php`, `frankenphp` or `bin/console` would otherwise first show it as a 500 on its first publication.
      `exec` asks the environment of the service container that is actually running; a one-off container
      (`docker compose run --entrypoint …`, `docker run`) is never reached by this check, so list those with
      `docker compose … ps --all` and `docker ps` and remove any that is not yours. A failure may mean domain
      events would be published outside the transaction that produced them; the printed `[ERROR]` says which
      cause. Do not take traffic until it passes.
- [ ] `make docker.down.clean-volumes` and `db.reset` are **never** run against
      a prod stack.
- [ ] **Disaster-recovery gate 1: a restore drill ran on the real VPS, before the first customer.** The
      local rehearsal proved the procedure on a dump too small for any timing; it proved nothing about the
      VPS. It is a destructive restore over the live database, so afterwards the drill moves to a scratch
      host. Evidence to record: backup, upload, download-and-decrypt, restore and application-recovery
      durations (the RTO), the production refusal (`ALLOW_PROD_RESTORE` + typed phrase), and one
      authenticated login afterwards. Procedure: `docs/vps-deployment.md` § Disaster-recovery readiness.
      Gate 3 of that section is the §7 item above, not a box here.
- [ ] **Disaster-recovery gate 2: a restore from the offsite repository alone succeeded** on a host that
      never held the VPS's disk, using only the independently custodied restic password and the secrets
      backup, with the git ref the dump was taken under. The same run proves the credential: with the VPS's
      exact credential and backend, two consecutive `restic backup` runs succeed, `restic check` passes and
      `restic forget` is refused. If the backup fails on lock removal, switch to `rest-server --append-only`
      or keep the delete-capable credential off the VPS. The drill host mails nobody and is wiped afterwards.
- [ ] **Backup failure is noticed from outside the VPS.** The cron line pings a dead-man's switch only
      after dump, read-back and offsite upload all exited 0, the check alerts on a missing ping, a first
      manual ping turned it *up*, and its grace period exceeds the duration measured in gate 1. Sentry's free
      plan has no alerting, so nothing else would say a night was skipped.
- [ ] **`AUDIT_KEK` and `.env.prod.local` are backed up** to a secrets store reached by a credential
      different from the data backups', re-saved on every change. The one KEK is kept as long as any live
      data or retained dump exists (losing it makes the dumps useless); a retired KEK, once rotation exists,
      is kept as long as the oldest dump it opens.
