# Security Best Practices

## General Security
- Validate and sanitize all user inputs
- Use parameterized queries/prepared statements
- Implement proper authentication and authorization
- Follow principle of least privilege
- Never trust user input
- Keep dependencies up-to-date
- Use HTTPS in production
- Implement CSRF protection

## Request bodies are closed sets

- **Every request body maps through `#[StrictRequestPayload]`** (`Shared/Http/Infrastructure`), never bare
  `#[MapRequestPayload]`. It bakes `ALLOW_EXTRA_ATTRIBUTES => false`, so a body carrying members the payload
  does not declare is answered `422 validation-failed` naming each surplus member, rather than executing the
  recognised part and reporting `200` for the whole request. Enforced by `StrictRequestPayloadGateTest`.
  The policy reaches exactly as far as the attribute does: a body consumed by a firewall authenticator
  instead of an argument resolver (`json_login` on `POST /login`) never passes through it, and the gate
  cannot see it. Those surfaces stay permissive — treat that as a known edge, not as coverage.
- **`#[MapQueryString]` stays permissive on purpose.** An unknown query parameter is ambient (analytics,
  cache-busting, a pasted campaign URL), not an instruction; failing a read over one would be self-inflicted.
- **Transport credentials travel in headers, not the body.** A body member is application data; anything the
  framework consumes (CSRF tokens) is read from a header — `#[IsCsrfTokenValid(..., tokenKey: 'X-CSRF-Token',
  tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]`. This keeps the request DTO modelling only the application
  contract, and it fails fast: a missing header throws `InvalidCsrfTokenException` before any origin
  reasoning. It is **not** an origin-independent barrier — see the next bullet.
- **Do not overstate what a stateless CSRF token proves.** `SameOriginCsrfTokenManager::isTokenValid()`
  length-checks the value (>= 24), then asks `isValidOrigin()`, which **returns on `Sec-Fetch-Site` alone
  when the browser sent it** (`'same-origin' === $header`) and only falls back to comparing `Origin`/`Referer`
  when it did not. The alternative path is a double-submit cookie. A client that mints a fresh nonce per
  request and sets no cookie never engages the double-submit half, so validity rests entirely on the
  same-origin check — the token is required to be *present*, not verified to be *right*. Call it a stateless
  CSRF token, not a double-submit token, unless the cookie half is actually wired.
- **The header transport does not make the token an independent control.** Requiring `X-CSRF-Token` only
  rejects a *same-origin* request that omits it; a cross-origin one is already refused by the origin check,
  and any caller that can forge `Origin` can set a custom header just as easily. Never remove or relax an
  origin guard on the grounds that the CSRF token covers it — the token alone admits nothing.
- **Two similarly-named headers, unrelated jobs.** `tokenKey` is where `#[IsCsrfTokenValid]` reads the
  submitted token (ours: `X-CSRF-Token`). `framework.csrf_protection.check_header` is a *different* axis: it
  governs the cookie half and reads a header named after `cookie_name` (Symfony default `csrf-token`).
  Enabling `check_header` would make Symfony look for `csrf-token`, not `X-CSRF-Token`.

## Pre-identity surfaces (login, invitation accept, forgot/reset)
- **Constant-time floor:** every pre-identity rejection pays one unit of password-hashing work through the
  shared `PreIdentityTimingFloor` port before answering, so response latency never correlates with whether an
  account exists or what state it is in. New pre-identity branches (future magic-link, MFA, …) must pay the
  same floor. The proof is always a STRUCTURAL test (the work is invoked on every branch) — wall-clock timing
  assertions are banned as flaky.
- **No KDF for dead tokens:** on token-consuming endpoints, hash the submitted password only AFTER the token
  resolves live (a deferred closure built in the HTTP adapter), or a garbage POST becomes an unauthenticated
  argon2id amplification vector.
- **Neutral per-target rate limits:** a per-account/per-selector budget must NEVER change the response shape
  when exhausted — fold saturation into the surface's uniform outcome (forgot keeps its 202 with the work
  silenced; token endpoints keep the opaque `invalid-token`). A per-target 429 here is an oracle over which
  accounts/selectors exist and are under attack. **The test is who is asking, not how the budget is keyed:**
  a per-target budget may answer 429 once the caller has already proved it holds the target, which is why
  `password_change_per_identity` on `POST /me/password` refuses out loud and nothing on this surface does.
- **Token hygiene:** a single-use token travels ONLY in the emailed link and the request body — never in a
  log (Caddy redacts the `token` query parameter), never in a Messenger transport (token-bearing emails are
  synchronous best-effort), never left in browser history/`Referer` (token screens send
  `Referrer-Policy: no-referrer` and strip `?token=` on mount).
- **Security sender:** user-facing security emails come from `MAILER_SECURITY_FROM` — a monitored, replyable
  mailbox validated fail-loud outside dev/test (`SecuritySenderAddress`); the operational `MAILER_FROM` may
  stay no-reply.

## Password policy
- **One constraint object, no options.** Every surface that *creates* a credential — the authenticated change,
  the reset completion, the invitation accept, the bootstrap CLI — carries
  `Shared\Validation\Infrastructure\PasswordPolicy`, and the PWA mirrors it in
  `context/backoffice/user/application/schemas/auth/passwordPolicy.ts`. The limits are constants of the class,
  never attribute arguments: the policy previously lived as six literals across three DTOs and drifted (the
  reset surface accepted 255 characters while the other two accepted 128, in production, with no gate able to
  see it), and a configurable `#[PasswordPolicy(max: …)]` puts those literals straight back with better
  syntax. A caller that needs different numbers is asking for a different policy.
- **Never on a credential that already exists.** `currentPassword` carries `NotBlank` plus a DoS ceiling and
  nothing else. It may have been minted under an older or wider rule, so asserting today's policy on it would
  lock its owner out of the very endpoint that would fix it.
- **Count code points, on both sides of the wire.** The server measures with `mb_strlen`, the client with
  `[...value].length` — never JavaScript's `String.length`, which counts UTF-16 units, so five astral
  characters read as ten on one side and five on the other and the client accepts what the server refuses.
- **Whitespace-only is refused with `mb_trim`, never `trim` and never `\S`.** `NotBlank` admits eight spaces;
  an ASCII `trim()` admits eight U+00A0 or U+3000, and a `\S` regex without the `u` modifier matches the bytes
  those characters are made of. The resulting credential cannot be reliably retyped.
- **Never trim a password anywhere.** Verification runs through `json_login`, framework-owned, which does not
  trim. Storing `hash(trim(x))` while verifying `x` is a permanent, irreversible lockout.

## Security Checklist Maintenance
- The `PRODUCTION_SECURITY_CHECKLIST.md` file MUST be kept up-to-date at all times
- When making changes that affect security-related files, I MUST review and update the checklist accordingly
- If adding new security-sensitive files or configurations, I MUST add corresponding entries to the checklist
- If modifying files referenced in the checklist, I MUST verify the checklist items are still accurate
- The checklist should reflect the current state of the codebase, not just production deployment requirements

## Pre-Commit Security Checks

Before ANY commit, I MUST perform security checks on all changed files:

### General Security Checks
- [ ] No hardcoded passwords, API keys, tokens, or secrets in code
- [ ] No `.env` files or sensitive configuration files are being committed
- [ ] No debug code (var_dump, print_r, console.log) left in production code
- [ ] No commented-out code containing sensitive information
- [ ] No test credentials or mock secrets in committed code

### File-Specific Security Checks

#### Docker/Nginx Configuration Files
- [ ] No default passwords or credentials
- [ ] CORS origins are properly configured (not wildcard `*` unless necessary)
- [ ] Server names are not catch-all (`_`) in production configs
- [ ] SSL/TLS configuration is secure
- [ ] Security headers are properly configured
- [ ] Rate limiting is implemented where appropriate
- [ ] Xdebug is disabled in production configurations

#### PHP Files
- [ ] `make semgrep.scan` is clean — mechanises three of the checks below for input reaching a query, a shell or a redirect from the Symfony `Request`. It is a floor, not a substitute: it covers those three flows only, so every remaining item is still checked by hand
- [ ] All user inputs are validated and sanitized
- [ ] SQL queries use prepared statements (no string concatenation)
- [ ] No eval() or dangerous functions without proper sanitization
- [ ] Error messages don't leak sensitive information
- [ ] File uploads are properly validated
- [ ] Authentication and authorization checks are in place
- [ ] CSRF protection is implemented for forms
- [ ] Every persisted `Types::GUID` entity column is classified in `api/.person-reference-policy`, and one holding a person's id names the file that erases it — nothing in the schema references `identity_user`, so deleting the identity cascades nowhere and leaves the id behind in every table nobody was told to clean. `make php.lint.person-reference` gates it; it verifies that a classification exists and is wired, never what the classification means
- [ ] No domain event whose aggregate is a natural person is routed to a persisted transport — `async`/`failed` have no TTL, no prune, and no erasure path, so the queued id outlives the subject's erasure. Classify the `aggregateType` in `api/.persistent-transport-policy`; `make php.lint.persistent-transport` gates it

#### Environment/Configuration Files
- [ ] No `.env` files are committed (check `.gitignore`)
- [ ] Default values are changed from insecure defaults
- [ ] Production environment variables are not exposed

#### Database Files
- [ ] No test data or development credentials in migration scripts
- [ ] Database initialization scripts use parameterized queries
- [ ] User permissions are properly configured
- [ ] No sensitive data in seed files
- [ ] Deletes are hard deletes — soft delete only under the documented exceptions in [`database.md`](database.md) (GDPR erasure must stay satisfiable)
- [ ] `identity_user` holds a **credential** (`password_hash`) and **PII** (`email`): the `password_hash` is never logged, returned, serialized, or audited — `User` deliberately does **not** implement `AuditedEntity`, so it stays out of the `onFlush` change diff (a credential leak), and the domain VO `HashedPassword` is opaque to the algorithm (hashing lives in Infrastructure). `User` is **hard-deleted** (no soft delete), keeping GDPR erasure of the email satisfiable. See ADR [`../adr/auth-rbac-subsystem.md`](../adr/auth-rbac-subsystem.md)
- [ ] **Auth failures flow through the RFC 9457 pipeline, never a manual `JsonResponse`.** The session firewall's `json_login` failure handler **re-throws** so `ExceptionResponder` builds the 401 `unauthenticated`; the message is **normalised** to one string so unknown-email and wrong-password are indistinguishable (no user enumeration — keep `hide_user_not_found` on). The session cookie is **httpOnly + `SameSite=Lax` + `Secure`**; login CSRF (forced login) is closed by a same-origin `Origin` guard on the login POST (`LoginOriginListener`) — `json_login` fires on the route's `_format: json` default, **not** the `Content-Type`, so a cross-site `text/plain` form carrying a JSON body would otherwise reach it as a CORS simple request, and neither `SameSite=Lax` nor the CORS policy stops forced login (they gate reading the response, not sending the request). `json_login` validates no token; stateless-token CSRF for mutating routes stays wire-on-consumer. CORS / Mercure are not broadened. **`access_control` is default-deny:** every `/api` route needs an authenticated session unless explicitly allowlisted (login, health, dev hot-reload); an unauthenticated hit on a protected route becomes a **401 `unauthenticated`** via `UnauthenticatedAccessListener`, which rewrites the firewall's `AccessDeniedException`→`AuthenticationException` for anonymous callers (401, not 403) while an authenticated-but-forbidden caller still gets 403 — the audit read routes enforce `#[IsGranted('auditTrail.read')]`. See ADR [`../adr/auth-rbac-subsystem.md`](../adr/auth-rbac-subsystem.md)
- [ ] `audit_log` is a **PII table** (`actor_id`, `ip`, `user_agent`); its `ip` / `user_agent` / `metadata` are client-controlled (tainted — escape on render, never trust). GDPR erasure is implemented as an in-place irreversible anonymisation (`audit:gdpr:erase`: `actor_id` → one fresh random UUID per subject, `ip` / `user_agent` → `[REDACTED]`, and the materialised non-PII `actor_erased` flag set — all in one `UPDATE`; never row deletion; self-audited as a `security` `GDPR_ERASURE_EXECUTED` entry holding only the pseudonym). Retention-by-level (scheduled prune) is tracked separately — see [`../../PRODUCTION_SECURITY_CHECKLIST.md`](../../PRODUCTION_SECURITY_CHECKLIST.md). ISO 27001:2022 base mapping: **A.5.18** (restricted access rights — the audit reads are permission-gated), **A.8.15** (append-only event log + restricted access + logging of access to logs) and **A.8.17** (clock-synchronised `occurred_on`, sealed from the system clock). The two read routes (`GET /audit/timeline` and `GET /audit/events/{id}`) are **RBAC-restricted** via `#[IsGranted('auditTrail.read')]` (under-privileged → 403 `forbidden`, anonymous → 401 `unauthenticated`) and **self-audited** — each authorized read emits a durable `security` `AUDIT_TRAIL_READ` row (write-before-send). The write-side `change` diff is surfaced read-only via the canonical `GET /audit/events/{id}` resource and rendered as **escaped text** in the investigation UI — never `dangerouslySetInnerHTML`
- [ ] **PII in a write diff is crypto-shredded, never stored in clear** (A.5.12, ADR [`../adr/regulatory-audit-trail.md`](../adr/regulatory-audit-trail.md)). Owning modules classify personal-data fields with a passive `#[PersonalData]` attribute (`BankAccount`: `holderName`/`iban`; `Bank`: none); the `onFlush` capture AEAD-encrypts (libsodium) those diff columns under a per-subject DEK (envelope: DEK wrapped by the env-custodied `AUDIT_KEK`, kept in a Postgres keystore keyed by `EncryptionScopeId`), leaving non-PII in clear. The row references its `encryption_scope_id`; the read UI shows a sealed sentinel, never the ciphertext. **Subject erasure** (`bank-account:gdpr:erase-subject`) destroys the DEK — the ciphertext is permanently unreadable, the append-only rows survive — and is **never merged** with actor erasure (`audit:gdpr:erase`): distinct GDPR triggers, distinct loci (ADR D15). The `change` level has a 5-year retention floor (regulatory evidence)

#### Docker Files
- [ ] Base images are from trusted sources
- [ ] No unnecessary packages installed
- [ ] Containers run as non-root users where possible
- [ ] No exposed ports without proper justification

### Security Checklist Update Process
When committing changes that affect security:
1. Review all changed files against the security checks above
2. Check if any changed files are referenced in `PRODUCTION_SECURITY_CHECKLIST.md`
3. If files are referenced, verify checklist items are still accurate
4. If new security concerns are introduced, add them to the checklist
5. If security issues are fixed, update the checklist accordingly
6. Document any security-related changes in commit messages

### Security Issue Detection
If I detect any security issues during pre-commit checks:
- I MUST alert the user immediately
- I MUST NOT proceed with the commit until the issue is addressed
- I MUST suggest specific fixes for the security issue
- I MUST update `PRODUCTION_SECURITY_CHECKLIST.md` if a new issue type is discovered

### Security Checklist Reference
- Always refer to `PRODUCTION_SECURITY_CHECKLIST.md` for comprehensive security requirements
- The checklist contains specific file paths and line numbers for security-critical items
- Use the checklist as a guide when reviewing code changes
- Update the checklist when security configurations change
