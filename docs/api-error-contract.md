# API Error Contract — RFC 9457 Problem Details

> Authoritative one-pager for the uniform error contract every `/api/*` non-2xx response is expected to honour. Single mapping site: [`api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php`](../api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php). Single listener: [`api/src/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponder.php`](../api/src/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponder.php).

## Body shape

The wire body is a JSON object owned by [`ProblemDetails`](../api/src/Shared/ErrorContract/Application/ProblemDetails.php) (`toArray()` lines 34–50). Deterministic key order is `type, title, status, detail?, instance, correlation-id, <extensions>`:

```json
{
  "type": "bank-not-found",
  "title": "Bank not found.",
  "status": 404,
  "detail": null,
  "instance": "01926e83-7b5a-7d40-9c8f-2f9b5d3e1a2c",
  "correlation-id": "01926e83-7b5a-7d40-9c8f-2f9b5d3e1a2c",
  "violations": [],
  "debug": { "exception_class": "...", "message": "..." }
}
```

| Field            | Required | Source                                                                          |
|------------------|----------|---------------------------------------------------------------------------------|
| `type`           | yes      | Opaque category identifier (e.g. `not-found`, `validation-failed`)              |
| `title`          | yes      | Short human-readable summary                                                    |
| `status`         | yes      | Equals the HTTP status line                                                     |
| `detail`         | no       | Optional human-readable detail                                                  |
| `instance`       | yes      | Per-error UUIDv7, minted by `ExceptionResponder`                                |
| `correlation-id` | yes      | Per-request UUIDv7, minted/propagated by `CorrelationIdListener`                |
| `<extensions>`   | varies   | Type-specific (e.g. `violations` for `validation-failed`, `debug` outside prod) |

`detail` is the only optional core field — when `null`, it is OMITTED from the wire body (see `ProblemDetails::toArray()`). `extensions` carries per-type members appended after the core fields. Reserved keys (`type, title, status, detail, instance, correlation-id, violations, debug`) are stripped from `DomainException::context()` before serialization so domain code cannot accidentally clobber wire fields.

## Media type and caching headers

- `Content-Type: application/problem+json` (RFC 9457 §3 — no `charset` parameter; the media type mandates UTF-8).
- `Cache-Control: no-store` (NFR — error responses MUST NOT be cached by proxies / CDNs).
- `X-Correlation-Id: <uuidv7>` — per-request UUIDv7, mirrors body `correlation-id`. Written on **every** main response (not just errors) by `CorrelationIdListener::onResponse` (`kernel.response`, priority `-1024`).
- `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` (IETF `draft-ietf-httpapi-ratelimit-headers`) and the legacy de-facto `X-RateLimit-*` aliases — written on **every** main `/api/*` response by `RateLimitListener::onResponse` (`kernel.response`, priority `-128`). `Retry-After` is ALSO written on the rejected (429) path (RFC 9110 §10.2.3). Values are derived from the per-request snapshot stamped on `kernel.request` and use delta-seconds (not epoch).

Encoding: `\json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`. Symfony `Response` (not `JsonResponse`) is used so `Content-Type` and the encoding pipeline stay under `ProblemDetailsResponder` control.

## Marker interface → HTTP status table

The mapping is the constant `ProblemDetailsFactory::MARKER_STATUS_MAP` (see [`api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php`](../api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php)). The default `type` per marker is `MARKER_DEFAULT_TYPE_MAP`. **Do not duplicate the values here — this table is a navigation aid; the source is the constant** (NFR25).

| Marker (`api/src/Shared/ErrorContract/Domain/Exception/`) | HTTP status | Default `type`            |
|---------------------------------------------|-------------|---------------------------|
| `NotFound`                                  | 404         | `not-found`               |
| `Conflict`                                  | 409         | `conflict`                |
| `Forbidden`                                 | 403         | `forbidden`               |
| `Unauthenticated`                           | 401         | `unauthenticated`         |
| `InvariantViolation`                        | 422         | `invariant-violation`     |
| `InvalidInput`                              | 400         | `invalid-input`           |
| `RateLimited`                               | 429         | `rate-limited`            |
| `InvalidSearchCriteria`                     | 422         | `invalid-search-criteria` |
| `ServiceUnavailable`                        | 503         | `service-unavailable`     |
| Plain `DomainException` (no marker)         | 500         | `domain-error`            |

`InvalidSearchCriteria` covers semantically invalid search criteria — invalid filters (unknown/un-filterable field, operator not allowed for the field, value not matching the field's required format or being blank), an un-sortable order field, and out-of-range pagination. Its concrete exceptions live under `api/src/Shared/Search/Domain/Exception/` (`UnknownSearchField` → `unknown-search-field`, `UnsupportedSearchOperator` → `unsupported-search-operator`, `InvalidSearchValue` → `invalid-search-value`, `UnknownSortField` → `unknown-sort-field`, `InvalidPagination` → `invalid-pagination`, `InvalidCursor` → `invalid-cursor`). `unknown-search-field`, `unsupported-search-operator` and the format checks of `invalid-search-value` are thrown by the shared filter applier; `unknown-sort-field` is thrown by the shared search repository when `sort` falls outside the repository's `sortFieldMap()` allow-list (before any SQL runs, so the field is never interpolated into DQL) — its `context` carries only `{field}`, never interpolated into the title. `invalid-search-value` fires for any value the field's mapping cannot accept: a malformed UUID against a UUID column (`requiresUuidValues`) or a malformed / lax datetime against a timestamp column (`requiresDateTimeValues`) — each would otherwise reach Postgres as a 22xxx error turned 500 — and is also raised by the domain `Filter` constructor for a blank value (empty after a Unicode-aware trim), so that invariant holds for every adapter (HTTP, CLI, message handlers). Its `context` carries only `{field, position}`, never the offending value. Accepted datetime bounds are byte-canonical ISO-8601 carrying a real-world UTC offset — `Z` or `+00:00` — at exactly second, millisecond (3-digit, the JS `Date.prototype.toISOString()` form) or microsecond (6-digit) precision; any other fractional width or non-canonical digit form (e.g. `2026-6-01T…`) is an `invalid-search-value`, never coerced to the nearest instant (the applier's round-trip gate, `FilterApplier::isCanonicalUnder`). `invalid-pagination` is raised by the `SearchCriteria` constructor when `limit` falls outside its `[1, MAX_LIMIT]` range (cursor-only navigation has no page number since PR3, so there is no `page`/`MAX_PAGE` check) — likewise an all-adapter invariant, with the HTTP boundary DTO (`SearchQuery`) rejecting the same value earlier as a 422 `validation-failed`. Its `context` carries only `{limit, max}` — a bare integer, never client-identifying input. `invalid-cursor` is raised by the keyset engine when a pagination cursor fails validation by any of its four causes — signature, version, payload, fingerprint, in that DAG order. The wire response is deliberately INDISTINGUISHABLE across causes (identical `type`, identical title, empty `context`); only the cause travels — in the structured log line, never the raw cursor (NFR1). A cursor whose payload `dir` contradicts the wire `after`/`before` parameter is the same 422 `invalid-cursor` (integrity binding, AR21), never a silent navigation fallback. The whole family maps to **422** (not 400): the criteria are *well-formed but semantically invalid* query input, so they join the wire DTO `validation-failed` (also 422) under the pragmatic industry convention (Rails, Laravel, GitHub) that 422 covers any well-formed-but-unprocessable input, body or query. 400 is reserved for a malformed request *target* — a path id that is not a well-formed UUID (`InvalidInput` → `invalid-uuid`); the specific search marker travels only on `DomainException` instances and never collides with it.

`ServiceUnavailable` is the sole **5xx** marker and the sole marker that does **not** extend `ClientError`: it maps to **503** for an operational fault where the request cannot be decided because a dependency is unreachable, so it must reach Sentry rather than be suppressed as an expected client outcome. `MarkerStatusMapContractTest::testMarkerIsClientErrorIffStatusIs4xx` pins the `ClientError ⇔ 4xx` equivalence, so a future 5xx marker cannot silently extend `ClientError`. Its concrete exceptions are two, both converting a DBAL failure at the adapter that owns it:

- [`Iam/Session/Domain/Exception/SessionStoreUnavailable`](../api/src/Iam/Session/Domain/Exception/SessionStoreUnavailable.php) — raised by the session repository on a connection failure so the Session Admission Gate fails closed (a store outage is a 503, never a raw 500 nor a silent pass-through).
- [`Shared/Persistence/Domain/Exception/TransientTransactionFailure`](../api/src/Shared/Persistence/Domain/Exception/TransientTransactionFailure.php) — `type` **`transient-transaction-failure`**, overriding the marker default. Raised by [`DoctrineTransactionManager`](../api/src/Shared/Persistence/Infrastructure/DoctrineTransactionManager.php) when the transaction it wraps throws DBAL's `RetryableException` (on PostgreSQL: `40P01` deadlock detected, `40001` serialization failure — both arrive as `DeadlockException`). Untranslated these were a bare 500 `unhandled-exception`, telling a client the server is broken when the identical request retried is expected to succeed. **503 and not 409**: there is no conflicting state for the caller to reconcile, only a lock order two transactions took in opposite directions. The driver exception is kept as `previous`, so the SQLSTATE reaches the `dev`/`test` debug chain and Sentry (which receives this class because `ServiceUnavailable` is the one marker that is not a `ClientError`) — but **not the per-error log line**, which is built from the thrown exception's own class and message and walks no `previous` chain. In prod an operator reading stderr sees `transient-transaction-failure` and cannot separate `40P01` from `40001`; widening the log line to the chain would put the failing statement in it, and the two states share one response and one remedy. **Scope:** the sanctioned transaction seam only — a caller that still holds the EntityManager and calls `wrapInTransaction` itself is not covered, which is the grandfathered debt that seam exists to absorb.
  **What this status does NOT certify:** that the HTTP operation is safe to replay. What was retryable is the *transaction*; a use case that commits one transaction, performs an external effect, and then deadlocks in a second would repeat that effect on a retry. 503 is the honest answer for "the database refused this, try again" and is strictly better than the bare 500 it replaces, but it is not an idempotency guarantee, and no client should read it as one. If a non-idempotent multi-transaction use case ever surfaces this, the fix is at that use case — an idempotency key or a single transaction boundary — not a different status.

  **A degraded health probe is deliberately NOT this marker.** `GET /api/v1/backoffice/health/database` answers `200` with `data.status: error` when its `SELECT 1` fails, because the two are different axes: the status code says the application could admit and decide the request, `data.status` says what the dependency check found. Routing that through `ServiceUnavailable` would replace the success envelope with `application/problem+json` — costing the PWA the `service`/`datetime` it renders, since `FetchHttpClient` throws on `!res.ok` before parsing a body — and, because this marker is the one that is not a `ClientError`, would emit a Sentry event per probe for the whole duration of an outage. The genuine "cannot decide this request" case is already covered on the correct axis: with Postgres unreachable, `SessionAdmissionGate` lets `SessionStoreUnavailable` propagate before any controller runs, so the probe answers `503` without the health module owning a single line of it.
  **What makes the retry advice honest.** `wrapInTransaction` closes the EntityManager on any failure and nothing else reopens it (`DoctrineConnectionResetListener` is `dev`/`test` only), so under worker mode the kernel carries a closed manager into the next request and the invited retry would answer 500 `EntityManagerClosed` — reads survive a closed manager, the first `flush` does not. The adapter therefore resets the manager in a `finally`, keyed on state (`!isOpen()` **and** no transaction still running) and never on the exception type: units of work nest, and a reset while an outer transaction is open would swap the manager out from under work the caller is still inside.


`Conflict` carries four exceptions raised by infrastructure rather than by a use case — counted from the tree, because the number here was wrong before it was ever incremented: [`Shared/Persistence/Domain/Exception/ReferentialIntegrityViolation`](../api/src/Shared/Persistence/Domain/Exception/ReferentialIntegrityViolation.php) — `type` **`referential-integrity-violation`** → **409**, raised by [`DoctrineTransactionManager`](../api/src/Shared/Persistence/Infrastructure/DoctrineTransactionManager.php) when a foreign key is rejected at flush. **409 and not the 503 its sibling translation gets**: a deadlock resolves itself on retry, this does not — the request cannot succeed until the referencing rows are gone. It is deliberately generic; a use case that can name the dependents catches it and answers better (bank deletion → 409 `bank-in-use` with its account count, keeping this one as `previous`). What it exists for is every *other* path, which without it leaves a bare 500.

The second is [`Shared/Persistence/Domain/Exception/ConcurrentUniqueWrite`](../api/src/Shared/Persistence/Domain/Exception/ConcurrentUniqueWrite.php) — `type` **`concurrent-unique-write`** → **409**, raised by the Doctrine ports when a unique index refuses a write. `#[UniqueEntity]` is a SELECT and the write is an INSERT, and nothing makes the pair atomic, so a competing request can commit the same value in between. **409 and not the 422 the check would have produced**, deliberately: naming the field means asking the database which value collided, and a failed commit has already closed the EntityManager (`UnitOfWork::commit()` calls `close()` in its `finally`), so the `UniqueEntity` re-check cannot run. The 409 states what the server knows; a retry gets the precise 422, because by then the competing row is committed and the SELECT sees it. It carries a `resource` extension (`bank` / `bank-account` / `identity-user`) so an operator can tell which port refused — the sole payload distinguishing the three, which is why `RepositoryUniqueViolationTest` asserts it per port and a port added without a case there can ship the wrong one green.

The driver's own message is **not** carried as `previous`, and that is the one place this type diverges from its two siblings above, which keep it on purpose so the SQLSTATE reaches the dev/test `debug` chain. The reason is the value: `DETAIL: Key (iban)=(…) already exists.` names it, and `bank_account.iban` is `#[PersonalData]`. Once translated the exception is a `ClientError`, so it never reaches Sentry and the log line never walks the chain — the exposure would be the dev/test `debug` block alone. The cost is real and is paid knowingly: when a **bank** loses the race, nothing records whether `short_name` or `name_normalized` fired.

The remaining two are [`Organization/Membership/.../UserAlreadyMember`](../api/src/Organization/Membership/Domain/Exception/UserAlreadyMember.php) — `type` **`user-already-member`**, the same unique-index translation with a *specific* answer its module can give — and [`Shared/Event/Domain/Exception/EventStreamConcurrencyConflict`](../api/src/Shared/Event/Domain/Exception/EventStreamConcurrencyConflict.php) — `type` **`event-stream-conflict`**. Five ports, three spellings for one database event, and nothing enumerates them. The fifth — `identity_user.email` — reused `concurrent-unique-write` rather than inventing a fourth spelling, so the drift this paragraph predicted is one port later than feared, not avoided: nothing would have stopped it going the other way.

**Two consequences worth stating rather than discovering.** `Conflict extends ClientError`, so this is Sentry-suppressed and logs at `warning` — correct for a delete racing an insert, and a deliberate trade for a foreign key broken by a schema fault, which now stops paging anyone. And the seam is shared: every caller of `TransactionManager::transactional()` inherits this answer, GDPR erasure paths included, not only the use cases migrated with it.

**Why no gate fires, and it is not the reason it looks like.** `ErrorContractGateTest` scans `api/src/Shared/ErrorContract/Domain/Exception/` only, so an exception declared anywhere else is invisible to it whether or not it introduces a marker — the same blind spot `TransientTransactionFailure` already sits in. This paragraph is the control (NFR26), not a formality alongside one.

The Session Admission Gate has two deliberately distinct outcomes for an authenticated `/api` request whose registry session is not admissible: a **missing, revoked or time-expired** session throws [`SessionNoLongerActive`](../api/src/Iam/Session/Domain/Exception/SessionNoLongerActive.php) — the existing `Unauthenticated` marker with `type()` overridden to **`session-expired`** → **401** "re-login" (reusing `Unauthenticated`, not a Symfony `AuthenticationException`, keeps it Sentry-suppressed and lets the PWA route to sign-in preserving `?next=`); a **store it cannot reach** surfaces as the `ServiceUnavailable` 503 above. Confusing the two (a 5xx operational fault vs a 4xx identity error) is the exact failure the fail-closed design forbids.

Marker resolution honours implements-clause order, intersected with the canonical marker list (`firstMatchingMarker`, lines 444–456). Subclasses may override `DomainException::type()` to return a more specific opaque identifier. A concrete exception implementing two or more markers must declare an explicit `TYPE` constant / `type()` override — enforced by a CI gate test (`MarkerStatusMapContractTest`) — so its resolution never silently depends on implements-clause order. Markers are framework-free — no HTTP / ORM / transport imports allowed inside `Shared/ErrorContract/Domain/Exception/`.

> **Adding a marker interface or changing its mapping requires updating this page.** `ErrorContractGateTest` enforces the first half: every `.php` at any depth under `api/src/Shared/ErrorContract/Domain/Exception/` must be cited on this page as a backticked token (`` `Forbidden` ``), checked against the directory's current contents — so a marker this page never names fails the gate in any checkout, on any branch. A citation anywhere in the prose satisfies it — the gate reads presence, not placement — but a name appearing only inside a fenced code sample does not count. On top of that, the table above must hold exactly one row per marker in `MARKER_STATUS_MAP`: a marker with no row fails, and so does a row left behind by a marker that no longer exists. Only the **status value** in a row escapes machine checking — the constant is the source, the table a navigation aid — so changing a mapping stays manual discipline.

### Symfony framework exception bridge

| Symfony exception                                        | HTTP status            | `type`                                                                    |
|----------------------------------------------------------|------------------------|---------------------------------------------------------------------------|
| `Validator\Exception\ValidationFailedException` *        | 422                    | `validation-failed` (+ `violations[]`)                                    |
| `Security\Core\Exception\AccessDeniedException`          | 403                    | `forbidden`                                                               |
| `Security\Core\Exception\AuthenticationException`        | 401                    | `unauthenticated`                                                         |
| `HttpKernel\Exception\UnsupportedMediaTypeHttpException` | 415                    | `unsupported-media-type`                                                  |
| `HttpKernel\Exception\HttpExceptionInterface`            | from `getStatusCode()` | `HTTP_STATUS_TYPE_MAP` — the marker default per status, else `http-error` |
| Anything else (`\Throwable`)                             | 500                    | `unhandled-exception`                                                     |

\* The factory walks `getPrevious()` so wrapped `ValidationFailedException` (e.g. inside Symfony's `RequestPayloadValueResolver` 422 wrapper used by `#[MapRequestPayload]` / `#[MapQueryString]`) is unwrapped and re-emitted as a **422** carrying the structured `violations[]` extension in place of Symfony's generic, unstructured 422 body. `violations[]` shape: `[{field, message, code}, ...]`.

**415 is the one status in `HTTP_STATUS_TYPE_MAP` no marker backs, and it earns its own `type` for the reason the others do.** No domain code raises it: the refusal is Symfony's argument resolver rejecting a body whose format the endpoint does not accept, so a form-encoded or multipart request against a route mapping a `#[StrictRequestPayload]` is answered before any handler runs. The format list is the attribute's own default rather than a declaration repeated at each site — `StrictRequestPayloadGateTest` refuses the bare `#[MapRequestPayload]` anywhere in `api/src`, so the subclass is the only spelling and every payload it maps is JSON-only by construction. **Not every write endpoint produces a 415**, and reading it that way is the error worth naming: an endpoint that maps no payload has no resolver to refuse it, so `POST /login` answers a non-JSON body with a **400** from `json_login`, and the three POST routes that take no body at all (`users/{id}/unlock`, `sessions/revoke-current`, `sessions/revoke-others`) answer whatever their own handling decides. Under the generic `http-error` bucket that refusal is indistinguishable on the wire from any other unmapped status, so a client routing on `type` alone cannot tell "resend this as JSON" from a failure it can do nothing about. The map entry is pinned by `ProblemDetailsFactoryTest::testHttpStatusTypeMapHasExactlyTheCanonicalNineEntries` and by the concrete producer in `testUnsupportedMediaTypeHttpExceptionCarriesItsOwnTypeRatherThanTheGenericBucket`; because a marker-free status is invisible to the marker-mirror assertion beside them, it is declared in that file's `MARKER_FREE_BRIDGE_STATUSES` — otherwise the mirror silently degrades into a subset check any new entry satisfies.

#### `message` selection for denormalization violations

A denormalization failure reaches the validator as a violation the argument resolver renders from the error's expected types. When there are none — a query parameter naming a backed-enum case that does not exist has the right type, just not an admissible value — the template degrades to `This value was of an unexpected type.` and the actionable sentence is carried separately in the violation's `hint` parameter. `message` is then taken from `hint`, so the wire says `The data must be one of the following values: "detailed", "light"`.

The substitution is deliberately narrow: **`hint` is preferred only over that one uninformative template, never as a general override.** It is not a wire-safe channel — the same denormalizer marks as user-presentable a sibling message naming the target class, and emitting it would place an internal FQCN in a public error body. Wherever the resolver had expected types it also had something to say, so the rendered message already wins.

Narrowing by template is necessary but not sufficient, because the set of producers is open: any denormalizer, vendor or ours, may throw with no expected types and a user-presentable message, and its sentence would reach the wire unread. So a `hint` containing a **backslash** — the marker of a namespaced identifier, which no sentence authored for a caller carries — is dropped in favour of the template. All three branches are pinned end-to-end by `api/features/shared/error_contract/validation_violations.feature`, which also asserts the body carries no `Erpify` namespace token.

**422** is the contract for any *well-formed but semantically invalid* input (RFC 9110 §15.5.21) — request body, query-string DTOs (`validation-failed`), and the `invalid-search-criteria` family alike — distinct from **400 `invalid-input`** for a malformed *request target*. Route ids are guarded by [`Uuid::ensure()`](../api/src/Shared/Uuid/Domain/Uuid.php), which throws [`InvalidUuidException`](../api/src/Shared/Uuid/Domain/InvalidUuidException.php) (`InvalidInput` → 400 `invalid-uuid`) *before* any repository lookup; a well-formed id with no row is 404. So `GET /banks/{id}`: malformed id → **400 `invalid-uuid`**, absent → **404**, body/DTO validation → **422 `validation-failed`**. See ADR [`adr/filters-search-criteria.md`](./adr/filters-search-criteria.md).

### Login admission errors (identity lifecycle)

The login handshake grades its failure by how far it got — `credentials → identity → admission → session` — so "authenticated" never leaks as "admitted". The [`UserChecker`](../api/src/Iam/Identity/Infrastructure/Security/UserChecker.php) raises the failure and [`ProblemDetailsAuthenticationFailureHandler`](../api/src/Iam/Identity/Infrastructure/Security/ProblemDetailsAuthenticationFailureHandler.php) maps it:

| Identity state              | Moment   | Wire result                    | Why                                                                                                                                                                                                                                                                                                             |
|-----------------------------|----------|--------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `INVITED` (no credential)   | pre-auth | **401 `unauthenticated`**      | Indistinguishable from an unknown email or a wrong password. `checkPreAuth` throws a plain `AccountStatusException`, which `expose_security_errors: None` re-wraps into a `BadCredentialsException` before the handler runs, collapsing it into the single neutral 401. The password is never verified.            |
| `SUSPENDED`                 | post-auth | **403 `account-suspended`**    | Credential proved, admission refused. `checkPostAuth` throws a `CustomUserMessageAccountStatusException` (exempt from the re-wrap), which reaches the handler and becomes `AccountSuspended` — a `Forbidden` `DomainException` overriding `type()` to `account-suspended` (the `InvalidSearchCriteria` marker-plus-`type()` pattern). Carries a real next step. |
| `DEACTIVATED`               | post-auth | **403 `account-deactivated`**  | Same mechanism as `SUSPENDED`. `AccountDeactivated` overrides `type()` to `account-deactivated` (same marker-plus-`type()` pattern), so a client tells this terminal admission wall apart from a generic infrastructural `forbidden` (an origin/CSRF rejection) that shares the 403. A retired identity, distinct by design from a reversible suspension. |
| `ACTIVE + locked`           | post-auth | **403 `account-locked`**       | A timestamp gate orthogonal to the status: after `MAX_FAILED_ATTEMPTS` failures the identity is locked for `LOCK_DURATION`, and a proven login within the window is refused. `checkPostAuth` throws a `CustomUserMessageAccountStatusException` (`LockedAccountException`, exempt from the re-wrap) which becomes `AccountLocked` — a `Forbidden` `DomainException` overriding `type()` to `account-locked`. Carries a real next step (reset the password). Runs AFTER the status arms, so `SUSPENDED + locked` shows the suspended wall, never `locked`. A wrong-password attempt on a locked account stays the uniform pre-identity 401, so an anonymous caller never sees `locked`. |

All three 403 walls carry no `AccessDeniedException` in their chain, so `UnauthenticatedAccessListener` (which would rewrite that to a 401) leaves them alone; all three abort authentication, so **no session cookie or resumable token is minted** — the "no session before admission" guarantee is structural, not cleanup. `Forbidden extends ClientError`, so the walls are Sentry-suppressed and log at `warning`. No new marker interface is introduced (all three reuse `Forbidden`), so the drift gate does not fire — this section is the manual record NFR26 requires. The `invalid-token` type is documented in the next two sections (invitation accept and password recovery — the two surfaces that realize it); the operational 503-family gate and the `session-expired` 401 are documented under the marker table above.

### Invitation accept (opaque single-use token)

The public invitation-accept write (`POST /api/v1/backoffice/invitations/accept`) raises one opaque failure — [`InvalidToken`](../api/src/Iam/Invitation/Domain/Exception/InvalidToken.php) — for a token that cannot be accepted in ANY of the five cases: already-used, revoked, expired, already-accepted, or non-existent.

| Case                                             | Wire result                | Why                                                                                                                                                                                                                                    |
|--------------------------------------------------|----------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Any of {used, revoked, expired, accepted, absent} | **400 `invalid-token`**    | `InvalidToken` is an `InvalidInput` `DomainException` overriding `type()` to `invalid-token` (the `AccountSuspended` marker-plus-`type()` pattern — no new marker interface, so the drift gate does not fire). All five raise the same exception with the same `title`, so the response is byte-identical and the reason is never revealed. |

The re-login that follows a successful accept cannot add a failure type to this table, and that is a decision rather than a happy accident. It runs post-commit through [`ReauthenticateDeviceBestEffort`](../api/src/Iam/Identity/Infrastructure/Security/ReauthenticateDeviceBestEffort.php), so an identity walled between the commit and the mint is contained: the failure goes to the log at `critical` and the response stays **204**. The invitation is already accepted and its single-use token already spent by then, so a 403 would report a failure that did not happen and invite a retry the token can no longer serve. The walled identity meets the same wall on its next request, where the answer can be truthful.

400 (not 401 or 404): a dead token is a malformed request *target*, like a malformed route UUID (`invalid-uuid`) — 401 would conflate with a credentials failure, and 404 would leak whether the invitation exists. The `InvalidInput` marker keeps the status uniform across the five cases, which is the whole opacity requirement (SI-13). The invited email is never surfaced, and the raw token never appears in a response, log or event. On success the flow answers **204** with the session cookie (mirroring login) — never a body that could echo identity.

### Password recovery errors (forgot / reset)

The forgot-password endpoint answers a **uniform 202** whatever the email (unknown or any identity state), so it produces no error type: enumerating accounts through its response is impossible by construction (only an `ACTIVE` identity does any work, and that work is never observable to the anonymous requester). The reset-password endpoint grades exactly like the login walls, one axis at a time:

| Reset outcome                                   | Wire result                    | Why                                                                                                                                                                                                                                                                       |
|-------------------------------------------------|--------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Dead token (malformed / unknown / expired / used) | **400 `invalid-token`**        | The four death cases collapse to ONE opaque response — [`InvalidResetToken`](../api/src/Iam/Identity/Domain/Exception/InvalidResetToken.php), an `InvalidInput` `DomainException` overriding `type()` to `invalid-token` (the `InvalidUuidException` marker-plus-`type()` pattern). The reason never travels. The same wire type an invitation-acceptance dead link produces, so a dead reset link is indistinguishable from a dead invitation link (opacity across surfaces); a distinct exception class per context keeps the isolation. |
| Valid token, identity `SUSPENDED` **at the wall** | **403 `account-suspended`**    | A valid token proves email control → graduated to post-identity specificity (as at login). Reuses `AccountSuspended`; no token is consumed and nothing mutates. |
| Valid token, identity `DEACTIVATED` **at the wall** | **403 `account-deactivated`**  | Same graduation, reusing `AccountDeactivated` (its specific `account-deactivated` type); no token consumed, no mutation. |
| Identity walled **after** the commit, during the re-login | **204** — the reset happened | The re-login runs post-commit through [`ReauthenticateDeviceBestEffort`](../api/src/Iam/Identity/Infrastructure/Security/ReauthenticateDeviceBestEffort.php). By the time it runs the token is spent, the credential replaced, every session revoked and the notice mailed, so the refusal is contained at `critical` rather than allowed to decide the status: answering 403 here would deny a mutation that fully committed and invite a retry with a link that no longer exists. Identical treatment on the change and accept surfaces — the three flows share one containment rather than three copies free to disagree. |

No new marker interface is introduced (the dead-token case reuses `InvalidInput`, the walls reuse `Forbidden`), so the drift gate does not fire — this table is the manual NFR26 record. A malformed request body (blank/oversized `token`, malformed forgot `email`, or a `password` outside the [password policy](../api/src/Shared/Validation/Infrastructure/PasswordPolicy.php) — 8–128 **code points**, at least one non-whitespace character) is the standard **422 `validation-failed`** at `#[MapRequestPayload]` mapping, orthogonal to the opaque token check. The ceiling is worth stating because it moved: this endpoint used to accept 255 characters where the change and invitation surfaces accepted 128, so one password answered 204 here and 422 there. All three now carry the same constraint object.

### Authenticated password change (`POST /api/v1/me/password`)

The self-service credential change is post-identity by construction — the caller already holds a session — so it grades on the two things the recovery flow cannot express: whether the caller still knows the credential it is replacing, and whether the replacement is a replacement at all.

| Change outcome                          | Wire result                          | Why                                                                                                                                                                                                                                                                                                            |
|-----------------------------------------|--------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `currentPassword` does not match         | **403 `invalid-current-password`**   | [`InvalidCurrentPassword`](../api/src/Iam/Identity/Domain/Exception/InvalidCurrentPassword.php), a `Forbidden` `DomainException` overriding `type()` (the `AccountLocked` marker-plus-`type()` pattern). **403, never 401:** the PWA's `FetchHttpClient` diverts any non-handshake 401 to `/login?reason=session-expired`, so a 401 would evict from the application someone who merely mistyped. Nothing mutates — no hash, no event, no revocation, no email. |
| `newPassword` equals the stored credential | **422 `new-password-must-differ`**   | [`NewPasswordMustDiffer`](../api/src/Iam/Identity/Domain/Exception/NewPasswordMustDiffer.php), an `InvariantViolation` `DomainException` overriding `type()`. Not a wire-level validation: deciding it needs the stored hash, so it is raised inside the aggregate's transaction. Letting it through would publish a "password changed" fact, revoke every other device and mail a security notice for a change that did not happen. |
| Identity not `ACTIVE`                   | **403 `account-suspended` / `account-deactivated`** | `User::ensureActive()`, weighed immediately after the row lock and before any comparison, so a walled identity pays no KDF. Same wall, same position and same vocabulary as the reset flow — the PWA already reads these two types on the login and reset surfaces, and the change form renders them through its persistent banner. The aggregate's [`InvalidIdentityTransition`](../api/src/Iam/Identity/Domain/Exception/InvalidIdentityTransition.php) (409) stays underneath as a defensive floor, exactly as it already is for `resetPassword()`: reaching it means the use case failed to wall first, not that a caller found a path. Reachable only in the degraded case where a status change did not revoke the identity's sessions. |
| Per-identity budget spent               | **429 `rate-limited`**               | [`PasswordChangeThrottle`](../api/src/Iam/Identity/Infrastructure/Security/PasswordChangeThrottle.php) consumes the `password_change_per_identity` budget at the controller edge, keyed on the caller's identity id, before the payload is weighed — so a saturated identity pays no KDF. Reuses `RateLimitExceeded`/`RateLimited`, and the response carries `Retry-After` plus the `RateLimit-*` families describing **that** budget (see *Rate limiting* below). **The refusal is visible, and this endpoint is the only place that is legitimate:** the two recovery budgets stay silent because a per-target 429 there would answer "this account exists" to an anonymous prober, while here the caller already holds the identity the budget is keyed on. |

Neither type introduces a marker interface (403 reuses `Forbidden`, 422 reuses `InvariantViolation`, 429 reuses `RateLimited`), so the drift gate does not fire — this table is the manual NFR26 record. A malformed body — absent/blank `currentPassword`, a `newPassword` outside the [password policy](../api/src/Shared/Validation/Infrastructure/PasswordPolicy.php) (8–128 **code points**, at least one non-whitespace character), or **any member the payload does not declare** (`#[StrictRequestPayload]`) — is the standard **422 `validation-failed`** with `violations[]` naming the offending field, raised at mapping before the use case runs.

The re-login that follows a successful change is [`ReauthenticateDeviceBestEffort`](../api/src/Iam/Identity/Infrastructure/Security/ReauthenticateDeviceBestEffort.php), shared with the reset and invitation-accept flows. It re-reads the aggregate and applies the same `ensureActive()` wall before minting, because `Security::login()` runs only the `checkPreAuth` half of the admission wall while the session-minting listener fires regardless — so without it the window between the revoke and the login (a blocking, unrouted SMTP send wide) could mint an `ACTIVE` session for an identity just suspended. **All three flows contain that refusal identically:** each reaches the re-login after its own transaction has committed, so none of them may let it decide the status code. The failure goes to the log at `critical` and the response keeps the status its mutation earned.

### Marker-less domain exceptions (crypto-shredding & integrity guards)

Not every `DomainException` carries a marker. The crypto-shredding capability (`api/src/Shared/Crypto/Domain/Exception/`) throws four deliberately **marker-less** exceptions:

| Exception | `type` | Thrown by | HTTP-reachable with real inputs today |
|-----------|--------|-----------|---------------------------------------|
| `DekDestroyed`             | `dek-destroyed`               | `SodiumEnvelopeEncryptor::decrypt` (Epic 3 read path) / `mint` tombstone race                          | No             |
| `DecryptionFailed`         | `decryption-failed`           | `SodiumEnvelopeEncryptor::decrypt`/`unwrap` & `DbalKeystore::wrappedDekFor` — AEAD open / corrupt bytes | No             |
| `InvalidEncryptionScopeId` | `invalid-encryption-scope-id` | `EncryptionScopeId::of` on a malformed scope built from trusted internal data                          | No             |
| `InvalidKek`               | `invalid-kek`                 | `SodiumEnvelopeEncryptor` constructor — `AUDIT_KEK` length check                                       | No (misconfig) |

Declaring no marker, they map through [`ProblemDetailsFactory`](../api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php) to **500** (`firstMatchingMarker` → `null`). Each carries an explicit `TYPE`, so the wire `type` is its own identifier — **not** the `domain-error` default of the marker-less row above, which applies only when `type()` is empty (`resolveDomainType`). Being marker-less they are **not** `ClientError`: they are **not** suppressed in Sentry, log at `error` (status ≥ 500), and carry `exception_category=domain_error`. That is correct — one firing signals corrupted or tampered stored state, an integrity fault operators must see, never a client mistake.

None is HTTP-reachable with real inputs today. `InvalidKek` guards a misconfigured `AUDIT_KEK` — a *missing* key fails at boot (env resolution), while a *wrong-length* key trips on first use of the lazily-instantiated encryptor (the first audited PII mutation), surfaced as a 500; never a client input. `InvalidEncryptionScopeId` derives from trusted internal audit-resource data — a wiring fault, not client input (the audit boundary enforces UUID resource ids, so no non-UUID scope reaches this call). `DekDestroyed` / `DecryptionFailed` reach HTTP only through the write/seal path ([`PiiDiffSealer::seal`](../api/src/Shared/Audit/Infrastructure/Persistence/PiiDiffSealer.php) → `encrypt`, during the Doctrine flush of any PII-bearing mutation — the DEK unwrap and keystore read run there), and there only on corrupted stored state or a should-never-happen tombstone race — where a 500 is the right answer. The decrypt/read path that would surface these as an *expected* outcome has no HTTP route yet; it belongs to Epic 3 (authorized audit-trail read).

Redaction: `DekDestroyed` / `DecryptionFailed` carry only `{encryptionScopeId}` (a `<ResourceType>:<uuid>` label — internal, non-PII); `InvalidKek` / `InvalidEncryptionScopeId` carry no context (the offending value is never included). Nothing here needs the redaction denylist.

The drift gate (`ErrorContractGateTest`) covers only markers under `api/src/Shared/ErrorContract/Domain/Exception/`, so it does **not** enforce this section — documenting a marker-less `DomainException` that can reach HTTP is manual discipline (see the Review checklist).

> **Deferred decision for Epic 3 — do not skip.** Before the Epic 3 decrypt/read route ships, `dek-destroyed` and `decryption-failed` need a deliberate status *on that path*: `dek-destroyed` is an expected post-erasure outcome (candidate **410 Gone**), while `decryption-failed` stays a **5xx** integrity fault (keep it Sentry-visible). Assign these by **translating at the read boundary** — the read handler catches the crypto exception and throws a read-specific, marker-carrying exception. Do **not** add a marker to `DekDestroyed` / `DecryptionFailed` themselves: they are shared with the write/seal path where 500 is correct, and a 4xx marker (`extends ClientError`) would silence a real integrity fault there in Sentry.

## How to add a new error

Worked example: `GET /api/backoffice/banks/{id}` with an unknown id throws, so the PWA receives a Symfony HTML error page instead of a 404 problem details body. Reaching the right body takes **no controller edit, no listener edit and no DI config**.

1. Define the domain exception under your bounded context's `Domain/Exception/` directory.
2. Have it `extends Erpify\Shared\ErrorContract\Domain\Exception\DomainException`.
3. Have it `implements` ONE of the canonical marker interfaces from the table above.
4. Throw it from your application service / domain entity.
5. Done. The listener at `ExceptionResponder` builds the body via the factory; you write zero HTTP code, you register nothing in DI.

```php
<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

final class BankNotFound extends DomainException implements NotFound
{
    public static function withId(string $id): self
    {
        return new self(
            type: 'bank-not-found',
            title: 'Bank not found.',
            context: ['bank_id' => $id],
        );
    }
}
```

Application handler:

```php
$bank = $this->banks->find($id) ?? throw BankNotFound::withId($id);
```

`curl -i /api/backoffice/banks/does-not-exist` returns:

```text
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
Cache-Control: no-store
X-Correlation-Id: 019045c3-7b8a-7c4e-9f30-000000000001

{"type":"bank-not-found","title":"Bank not found.","status":404,
 "instance":"019045c3-7b8a-7c4e-9f31-a2b7d1e4f5c6",
 "correlation-id":"019045c3-7b8a-7c4e-9f30-000000000001",
 "bank_id":"does-not-exist"}
```

The `bank_id` extension is the `context` array, with reserved keys stripped and the redaction denylist applied.

## Who may mint a `type`

Two processes mint `type`, and until #824 nothing said so. The API mints one per domain failure
(the table above, plus `ProblemDetailsFactory`'s marker and status defaults). The **client** mints
three, for failures that never reached a server and therefore have no API problem to carry:

| `type`                        | Minted when                                                       | `status` |
|-------------------------------|--------------------------------------------------------------------|----------|
| `network-error`               | `fetch` rejected — offline, DNS, CORS, server down. Never sent.     | `0`      |
| `request-timeout`             | the client aborted its own request. **May have been received and applied.** | `0`      |
| `malformed-response-envelope` | a 2xx body failed its `ResponseGuard`                               | the 2xx  |

The rule: **these three names belong to the client, and the API mints none of them.** No prefix and
no registry — a `type` is an opaque category identifier, and prefixing `client:` would read as a URI
scheme that does not exist while showing up in every `data-problem-type` selector and in the UI.

What makes it worth stating is the collision, not the aesthetics. A 408/504 marker claiming
`request-timeout` is a plausible next addition here; the moment it exists, a client-minted and a
server-minted problem are indistinguishable to the `data-problem-type` selectors and to the four
`switch (problem.type)` sites under `pwa/src/app/backoffice/**`, so a caller picks a recovery action
from a name that means two different things. Adding a fourth client-minted type means adding it to
`pwa/src/context/shared/http-client/domain/HttpClient.ts` with its `ProblemDetails.type` doc comment,
to this table, and to the error gallery.

Gate: `pwa/tests/client-minted-problem-types.test.ts` — it derives the names from the constants
(never a hand-kept list), fails when the API names one of them anywhere under `api/src`, when a
constant is declared without the doc marker, and when the gallery has no exemplar. It matches the
**quoted literal**, not the literal in type position: `type: 'x'` is only how a `DomainException`
subclass spells it, while the factory spells its defaults `Marker::class => 'x'`, and a
position-matching check was measured passing over a planted collision in that second family. It runs
in the PWA suite because the namespace is the PWA's; an API developer therefore sees it in CI rather
than in `make php.quality`.

`status: 0` is a client sentinel and never appears on the wire. `ProblemDisplay` prints a pill from
the status, and status 0 is the one case where it reads the `type` as well: `request-timeout` renders
**Timed out**, everything else at status 0 renders **No response** — which is accurate for a transport
failure and the opposite of what a timeout means.

## PWA consumption example

A form creating bank accounts can hit validation failures, not-found, forbidden and unexpected 500s. The client routes on `body.type` (FR44 — `type` is the contract-level signal; status is the transport-level signal):

```ts
const res = await fetch(`/api/backoffice/banks/${id}`);
if (!res.ok) {
  const problem = await res.json();
  switch (problem.type) {
    case 'validation-failed':
      // render field errors from problem.violations
      return showFieldErrors(problem.violations);
    case 'unauthenticated':
      return redirectToLogin();
    case 'bank-not-found':
      return showNotFoundUi();
    case 'forbidden':
      return showAccessDenied();
    default:
      // 4xx → toast with title + Error ID for support
      // 5xx → generic "something went wrong, Error ID: ..."
      return showToast(problem.title, problem.instance);
  }
}
```

When QA reports an intermittent 500, `problem.instance` pasted into the ticket locates the single log line by `instance=`; its `correlation_id` then yields the full request trail. Client-side error handling changes only when new `type` identifiers appear.

## Extending the redaction denylist

The denylist of context keys stripped before serialization lives at [`api/src/Shared/ErrorContract/Application/RedactionDenylist.php`](../api/src/Shared/ErrorContract/Application/RedactionDenylist.php) — the `RedactionDenylist::KEYS` constant (lines 42–50). Match scope is exact-key, case-insensitive ASCII, single-level (no recursion into nested arrays). **Strip semantics, not sentinel** — a denylisted key is removed entirely; its value is NOT replaced with `[redacted]`. The presence of a key labelled `password` is itself a signal.

Procedure to add a key:

1. Append the new (lowercase ASCII) key to `RedactionDenylist::KEYS`.
2. Add four parameterised rows to `RedactionDenylistTest::denylistCasingProvider` (lower / upper / title / mixed casing).
3. Run `make php.unit c='--filter RedactionDenylist'`. The assertion `testDataProviderRowCountMatchesKeysCountTimesFour` fails CI if the rows are missing (NFR8).
4. Update this section if the procedure itself changes.

The denylist is applied AFTER the reserved-key `unset()` layer and BEFORE the whitelist branch, so a denylisted `JsonSerializable` value cannot survive via the whitelist (`ProblemDetailsFactory::redactKeys`, lines 417–423).

## Redacting the logged `request_uri`

`RedactionDenylist` matches KEY names of a map and never looks inside a value, so it cannot protect a log field whose VALUE is built from caller-controlled bytes. Two are: `request_uri`, and `exception_message` when a validation failure is in the throwable chain (see the per-error log line above). `request_uri` goes through [`api/src/Shared/ErrorContract/Application/RequestUriRedaction.php`](../api/src/Shared/ErrorContract/Application/RequestUriRedaction.php) on **both** emission paths — `buildLogContext()` and `emitLastResort()`. What it redacts:

| Key                            | Match                | Why                                                              |
|--------------------------------|----------------------|------------------------------------------------------------------|
| `RedactionDenylist::KEYS`      | substring, ci        | secrets — `?token=<id>.<secret>`, Mercure's `?authorization=`     |
| `actorId`, `resourceId`        | exact, ci            | person ids; the audit screen filters by them                      |
| `correlationId`                | exact, ci            | not a person id — the trail of one reconstructs a session         |
| `filters[N][value]`, `…[value][]` | pattern           | the positional search grammar carries the same ids to the API     |

**Sentinel (`REDACTED`), not strip** — the opposite of the map denylist, deliberately: a URI's diagnostic value is its shape, so `filters[0][field]=actorId` survives while its value does not, and an operator can still tell a filtered request from an unfiltered one. The access log no longer participates in this vocabulary — it keeps no query string at all — so the sentinel's job here is entirely to keep the per-error line readable.

The sink is what makes this a leak rather than verbosity: in prod Monolog writes to `php://stderr` behind `fingers_crossed`, so one 5xx flushes the buffered WARNING lines of unrelated 4xx into the json-file Docker driver — bounded by size, still no TTL, no owner of erasure. A person id landing there outlives the erasure the application confirmed to the subject.

The identity axes are **not** folded into `RedactionDenylist::KEYS`: that list is substring-matched against problem-details extension keys too, and `actorId`/`resourceId`/`correlationId` are Resource DTO property names, so adding them there would silently start stripping fields out of response bodies. Adding a key here means extending `RequestUriRedaction::IDENTITY_KEYS` plus a row in `RequestUriRedactionTest::provideRedactedCases`.

### What a key is reduced to before it is matched

An axis is matched **whole**, so the match has to run against what the key names rather than against the bytes the caller chose to spell it with. Two reductions run first, both in the over-matching direction, because over-redacting a log costs a diagnostic while under-redacting it costs an identifier that outlives its own erasure:

- **Padding is stripped** (`RequestUriRedaction::PADDING_BYTES`: whitespace and control bytes). Without it `?actorId%00=`, `?actorId%0A=`, `?actorId%20=` and `?actor+Id=` each miss the whole match. Such a request answers 4xx — no DTO property carries the padded name — and 4xx is precisely what the `fingers_crossed` buffer holds and flushes on the next 5xx, so the value reaches the sink regardless of the status.
- **The key is decoded repeatedly**, up to `MAX_DECODE_PASSES` (five `urldecode` calls, so six candidate forms), because the positional grammar travels percent-encoded and a caller may wrap it further. The reduced form is what the next decoding starts from, not merely what the comparison sees: `%250%0A0actorId` only heals into a decodable escape once the padding is gone. **A nested URI carried in a VALUE is decoded the same way** (`decodeUntilQuerySurfaces`) — an asymmetry where the key was read through six forms and the value through one let `?next=%252F…%253FactorId%253D<id>` through untouched.

**Declared residual — a trailing malformed escape.** `?actorId%=`, `?actorId%zz=` and `?filters%5B0%5D%5Bvalue%5D%=` are not redacted by any of the three sinks: PHP's `urldecode` leaves the invalid bytes and converges, and the PWA's `decodeURIComponent` throws and the catch returns the unreduced candidate. `?token%=` survives only because the denylist is substring-matched, which is not a rule that generalises. This is declared rather than closed because healing arbitrary malformed escapes means guessing what the caller meant, and a wrong guess redacts real parameters; it is listed here so the next reader finds it stated rather than discovering it.

### Two sinks hold this vocabulary; the third stopped needing one

`RequestUriRedaction` (per-error log line) and `pwa/src/context/shared/observability/domain/redaction.ts` (Sentry event) hold it, and neither imports the other.

**The API and the PWA reduce identically** — same padding class, same `MAX_DECODE_PASSES`, same `MAX_NESTED_URI_DEPTH`, and the value decoded until a query surfaces on both. A level or a decoding one side performs and the other does not is not a smaller guarantee; it is the same identifier kept out of one sink and let into the other, and Sentry's retention is reached by no erasure path either.

**Caddy was never able to be held to that, so it stopped trying.** Its `format filter` matches a parameter name literally: no wildcard, no normalisation, no decoding. That was not a smaller version of this rule but a different kind of thing, and it failed in the direction that matters — `?actorId%00=<id>`, `?actor+Id=<id>`, `?filters%255B0%255D%255Bvalue%255D=<id>` and, measured against the running stack, the `in` operator's ordinary `filters[0][value][]=<address>` all reached the access log **in clear** while both other sinks redacted them. The access log now drops the query string whole (`api/frankenphp/Caddyfile`), so it holds no vocabulary that can drift from these two. That closes the enumeration's residuals — the trailing malformed escape above included, on that sink only — and spends the access log's ability to say which field or operator a request used; the decision and its cost are recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7.

`RedactionVocabularyParityTest` fails when the identity axes diverge between the two deployables. It compares vocabularies only — not the two search-value patterns, the two denylists, or the bounds, all mirrored by hand. The access log is held instead by two gates of its own: `CaddyfileAccessLogRedactionGateTest` reads the file, and `AccessLogQueryContainmentGateTest` sends nine spellings of a value through the running Caddy and asserts none survives into the line it wrote.

## Environment-aware `debug` extension

Behavior is keyed off `%kernel.environment%` (injected via `#[Autowire('%kernel.environment%')]` — never `$_ENV` / `getenv()`). The decision lives in `ProblemDetailsFactory::buildDebugExtension()` (lines 482–504) and `resolveDebugMode()` (lines 464–471).

| Env                                                         | `debug` extension shape                                                                                                                              |
|-------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| `dev`                                                       | full: `exception_class`, `message`, `file`, `line`, `previous_chain` (cycle-safe walk of `getPrevious()`)                                            |
| `test`                                                      | full (same as `dev`)                                                                                                                                 |
| `staging`                                                   | minimal: `exception_class` + `message` only (no `file`, no `line`, no chain)                                                                         |
| `prod`                                                      | omitted entirely; the terminal `unhandled-exception` branch's `title` is replaced by the safe literal `"An unexpected error occurred."` (FR35, NFR7) |
| anything else (`'ci'`, `'production'`, empty, uppercase, …) | falls through to `prod` semantics (default-deny — NFR13)                                                                                             |

Anonymous-class FQCNs are sanitised (`\0/path:line$N` suffix stripped) so the embedded path cannot leak through `exception_class` in staging mode (`sanitiseExceptionClass`, lines 546–551).

## Observability: `instance` vs `correlation-id` (FR49)

Two UUIDv7 identifiers, two different scopes — distinguishing them is the difference between debugging one failure and tracing one request.

- **`instance`** — UUIDv7 minted per **ERROR**. One per failure event. Source: `ExceptionResponder::__invoke` mints it fresh every time it builds a body. Use it to **grep the single log line for that one failure**. End users can cite it from a PWA toast (Journey 3 — Priya's 3am pager) so support can find the exact server-side record.
- **`correlation-id`** — UUIDv7 minted per **REQUEST**. Source: `CorrelationIdListener::__invoke` (`kernel.request`, priority `1024`). Either propagated from a strict-validated inbound `X-Correlation-Id` header or freshly minted. Mirrored in the body's `correlation-id` field, written to the `X-Correlation-Id` response header, and emitted in every PSR-3 log line for the request's lifetime. Use it to **trace the full request lifecycle across logs / traces / metrics** (ingress → controller → Messenger → DB).

Per-error log line context (one PSR-3 write per error, default `app` channel):

```text
instance, correlation_id, type, status,
exception_class, exception_category, exception_message,
request_uri, request_method
```

`exception_message` is the throwable's own message unless a `ValidationFailedException` is found
anywhere in the `getPrevious()` chain — the chain and not the top-level type, because
`#[MapRequestPayload]` throws an `HttpException(422)` wrapping it whose own message is the violation
list already imploded, and that is the shape every command DTO produces. When one is found the field
becomes `<validated type>: N violation(s) on <path> (<constraint code>), …`, bounded in path length
and violation count.
The reason is that a validation failure's message is its whole violation list rendered, and a
violation message is interpolated with the values it is about — i.e. the request payload. This
application's payloads carry a natural person's IBAN, holder name and alias, and a log file is a
sink no erasure path reaches, so the values are what does not get written. Which fields failed is
shape, not payload: it is what makes the record diagnostic and it survives. `RedactionDenylist`
cannot cover this — it strips by KEY, and the key here is `exception_message` (see *Redaction*).
The client still receives the violation messages through `violations[]` (subject to the body cap
documented above, which pops from the tail); the constraint
messages themselves are what must avoid interpolating a value worth protecting, which is why
`BankAccount`'s `#[Assert\Bic]` and the commands' `BicMatchingIban` both declare an `ibanMessage`
of their own instead of taking the Symfony default that spells the IBAN into the text. The reduction
holds on **both** emission paths, `emitLastResort()` included.

Level tiering (in order, first match wins):

| Match                                                                              | Level      |
|------------------------------------------------------------------------------------|------------|
| `throwable instanceof \LogicException && !$throwable instanceof DomainException`   | `critical` |
| `type === "unhandled-exception"`                                                   | `critical` |
| `status >= 500`                                                                    | `error`    |
| `status` 4xx                                                                       | `warning`  |

Non-domain `\LogicException` is pinned ahead of the marker check so a future custom marker that mistakenly maps a programmer error onto a 4xx still wakes on-call.

**Why the `DomainException` exclusion?** PHP's SPL hierarchy puts `\DomainException` under `\LogicException`, so the project's `Erpify\Shared\ErrorContract\Domain\Exception\DomainException` is *also* a `\LogicException` at the language level. Domain exceptions are expected business outcomes (`bank-not-found`, validation conflicts, …), not platform errors — they must keep their status-based level (`warning` for 4xx, `error` for 5xx). The `!$throwable instanceof DomainException` guard preserves that contract while still pinning genuine programmer errors (e.g. `\LogicException` thrown from a value-object invariant when `ext-intl` is missing).

**The 4xx row is coupled to the prod log sink, and raising it is a PII decision, not a verbosity one.** `main` is a `fingers_crossed` handler at `action_level: error` with a 50-record buffer nested onto `php://stderr` — bounded by size alone, still no TTL, no owner of erasure. The buffer holds a `security.DEBUG` record on every authenticated request whose `username` is the person's email address (Symfony's `ContextListener` logs `getUserIdentifier()`, and `SecurityUser` answers with the email). Because `ExceptionResponder` sets the response and stops propagation, HttpKernel's `ErrorListener` never runs on `/api/*` and `excluded_http_codes: [404, 405]` never applies there — so `warning` sitting strictly below `error` is the only thing keeping an API 404 from flushing that buffer. Move either number and every API client error dumps it. Gate: `ApiClientErrorBufferCouplingGateTest`.

### `exception_category` — SRE-routable taxonomy

`exception_category` is a stable, queryable label derived from the SPL hierarchy and the project's `DomainException` marker. The order of the dispatch is load-bearing: `DomainException` is checked first so a project subclass that ever descended from `LogicException` / `RuntimeException` is still classified as `domain_error`.

| Value              | Source                                                  | What it means                                                                            | On-call action |
|--------------------|---------------------------------------------------------|------------------------------------------------------------------------------------------|----------------|
| `programmer_error` | `\LogicException` and descendants                       | Build / platform / contract is broken (e.g. `ext-intl` missing, invariant violated).     | Page           |
| `runtime_error`    | `\RuntimeException` and descendants                     | Environmental / input failure not preventable at coding time (transient I/O, bad bytes). | Triage         |
| `domain_error`     | `Erpify\Shared\ErrorContract\Domain\Exception\DomainException`        | Expected business outcome (4xx for the most part).                                       | Log only       |
| `engine_error`     | `\Error` and descendants (`TypeError`, `ParseError`, …) | Engine-level failure.                                                                    | Page           |
| `unknown`          | Anything else implementing `Throwable`                  | Not in the SPL split — investigate.                                                      | Investigate    |

`exception_category` is **orthogonal** to `type` (RFC 9457 marker) and `status` (HTTP code) so SRE filters do not depend on framework-specific FQCNs. Routing examples for the existing Monolog stack ([`api/config/packages/monolog.yaml`](../api/config/packages/monolog.yaml)):

```text
exception_category=programmer_error                   → PagerDuty critical
exception_category=engine_error                       → PagerDuty critical
exception_category=runtime_error AND status >= 500    → PagerDuty warning
exception_category=domain_error                       → log only
```

Unhandled exceptions reach Sentry through the **SentryBundle `kernel.exception` listener** (dev + prod, not test — [`sentry.yaml`](../api/config/packages/sentry.yaml)), which captures the raw throwable with full stack trace at priority `128`, ahead of `ExceptionResponder` (priority `16`, which still builds the response since Sentry sets none). `exception_category` is queryable in Sentry too. The `before_send` callback **drops expected client errors before transmission** ([`SentryBeforeSend`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryBeforeSend.php) composes the drop-decision [`SentryEventFilter`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventFilter.php) with the PII [`SentryEventScrubber`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php)) — see **Client-error suppression in Sentry** below. The Monolog Sentry handler (commented in `monolog.yaml`) is a deliberate non-default: `ExceptionResponder`'s single PSR-3 line carries no throwable, so the listener path yields richer events and avoids double-reporting.

Grep by `instance` for the single failure entry; grep by `correlation_id` for the full request trail; filter by `exception_category` to separate platform-broken from triage-normal.

### Client-error suppression in Sentry

Expected 4xx outcomes are user / state errors, not actionable faults: a 409 `bank-in-use` (deleting a bank that still has associated accounts), a 422 validation error, a 404. Left unfiltered they reach Sentry as `handled: no`, `level: error` and bury real faults under volume — 50 users mis-clicking "delete" become 50 non-actionable issues. So `before_send` drops them.

The drop keys on the [`ClientError`](../api/src/Shared/ErrorContract/Domain/Exception/ClientError.php) marker, **not** on `exception_category=domain_error`. The distinction is load-bearing. Every 4xx marker in the marker→status table above (`NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, `InvalidInput`, `RateLimited`, `InvalidSearchCriteria`) `extends ClientError`, so any exception implementing one is suppressed transitively — there is no per-class denylist to maintain. But a **marker-less `DomainException` maps to 500** (its own `type`, or `domain-error` when `type()` is empty) and is therefore *not* a `ClientError`; it keeps flowing to Sentry. Filtering on `domain_error` instead would wrongly hide those 500s.

- Decision site: [`SentryEventFilter`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventFilter.php) (`$hint->exception instanceof ClientError` → `return null`), composed with the PII [`SentryEventScrubber`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php) by [`SentryBeforeSend`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryBeforeSend.php) (the `before_send` wired in [`sentry.yaml`](../api/config/packages/sentry.yaml)).
- Invariant: `MarkerStatusMapContractTest::testMarkerIsClientErrorIffStatusIs4xx` pins `ClientError ⇔ 4xx` against `MARKER_STATUS_MAP`. Add a 5xx marker and the test fails unless that marker deliberately does **not** extend `ClientError` — forcing a conscious "should this reach Sentry?" decision instead of silently leaking a 4xx or hiding a 5xx.

## Listener layout

| Listener                            | Event              | Priority | Path scope         |
|-------------------------------------|--------------------|----------|--------------------|
| `CorrelationIdListener::__invoke`   | `kernel.request`   | 1024     | all main requests  |
| `RateLimitListener::onRequest`      | `kernel.request`   | 512      | `/api/*` only      |
| `ExceptionResponder::__invoke`      | `kernel.exception` | 16       | `/api/*` only      |
| `RateLimitListener::onResponse`     | `kernel.response`  | -128     | `/api/*` only      |
| `CorrelationIdListener::onResponse` | `kernel.response`  | -1024    | all main responses |
| `SearchExceptionListener` (legacy)  | `kernel.exception` | 32       | search routes      |
| `SentryBundle\…\ErrorListener`      | `kernel.exception` | 128      | dev + prod         |

The Sentry `ErrorListener` (dev + prod, not test) runs first at `128` but only *captures* the throwable — it sets no response, so `ExceptionResponder` (16) still builds the RFC 9457 body unchanged.

`ExceptionResponder` checks `$event->hasResponse()` first — if a higher-priority listener already produced a response, it leaves it alone and does **not** log. Listener priority ordering vs. Nelmio CORS is pinned by (`ExceptionResponderListenerPriorityTest`).

## Rate limiting

`RateLimitListener` enforces the `anonymous_api` policy declared in [`api/config/packages/rate_limiter.yaml`](../api/config/packages/rate_limiter.yaml) on every `/api/*` main request, keyed by `Request::getClientIp()`. The listener is intentionally **pre-router** (priority 512 > Symfony's `RouterListener` 32) so endpoint enumeration through 404 paths still consumes the budget. On rejection it throws [`RateLimitExceeded`](../api/src/Shared/ErrorContract/Domain/Exception/RateLimitExceeded.php) — a concrete `DomainException` implementing the `RateLimited` marker — so the standard `ExceptionResponder` pipeline emits the conforming RFC 9457 429 envelope (`type=rate-limited`). **No `JsonResponse` shortcut on the rate-limit path** (NFR26).

**Per-target budgets are spent at the controller edge, and only one of them answers 429.** `password_recovery_per_email` and `token_action_per_selector` are silent by contract — their surfaces are pre-identity, so a per-target 429 would be an existence oracle, and exhaustion folds into the endpoint's own uniform outcome (the 202 of forgot, the opaque `invalid-token` wall of a completion). `password_change_per_identity` is the exception and refuses out loud, because its caller already holds the identity the budget is keyed on.

**The accepted side of that split is a real cost, not a footnote.** A 204 from `POST /me/password` carries the per-IP numbers (120/min) and a 429 from it carries the per-identity ones (10 / 15 min), and no header names which budget is being described — the IETF draft's `RateLimit-Policy` partition key is not emitted. A client reading `RateLimit-Remaining: 118` can therefore be refused on its very next request. The alternative, stamping the per-target snapshot on the accepted path too, was declined because it makes the family mean a different thing on one endpoint than on every other; the cost of the choice is that clients must treat these headers as advisory and read the 429 body, which carries the numbers of the budget that actually refused.

**One snapshot owns the headers.** Whichever limiter decides, it stamps a [`RateLimitSnapshot`](../api/src/Shared/ErrorContract/Infrastructure/Http/RateLimitSnapshot.php) on the request and `RateLimitListener::onResponse` renders it: `RateLimit-Limit` / `-Remaining` / `-Reset`, their legacy `X-` twins, and `Retry-After` on the rejected path only (RFC 9110 §10.2.3). A per-target limiter stamps **only when it refuses**, so the families keep one meaning across the API and change subject exactly on the response the other budget produced — without that, a per-identity 429 would ship no `Retry-After` and a `RateLimit-Remaining` counted off the per-IP bucket the request had barely touched.

**The key the budget was counted on never leaves the process.** `RateLimitExceeded` carries `limiterKey` as a property, and it is deliberately absent from the exception's `context()` — the map this pipeline promotes to Problem Details extensions, which is also what the per-error log line renders. On `password_change_per_identity` that key is the caller's own identity id, so serialising it would write a person's id into a response body and a log, two sinks no erasure path reaches, on the one budget whose key is a person rather than an IP. `RateLimitExceededTest::testTheLimiterKeyIsNeverPromotedToTheSerialisedContext` is what keeps that true: the map is one line away from carrying it, and a comment does not go red.

For correct per-client granularity behind FrankenPHP / a load balancer, set `framework.trusted_proxies` (env `SYMFONY_TRUSTED_PROXIES`) so `X-Forwarded-For` is honoured by `getClientIp()`. Without trusted proxies the limiter keys on the immediate connection IP — still safe (it over-limits a NAT pool) but not granular per real client. For multi-worker / multi-host deploys, swap the limiter's storage from the default `cache.rate_limiter` pool to a shared Redis pool so the budget is consistent across processes.

## Performance Budgets

pinned listener performance budgets. The benchmark harness lives at `api/tests/Bench/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponderBenchmarkTest.php` and runs through a real Symfony kernel via `WebTestCase`, so the measurement window captures the full listener path (factory mapping → body cap → `\json_encode` → `Response` write → PSR-3 log emission), exactly as it runs in production.

| Path | Budget                                 | Route                        | Status |
|------|----------------------------------------|------------------------------|--------|
| 4xx  | p99 ≤ **5 ms** (CI hardware baseline)  | `/api/test/_throw-not-found` | 404    |
| 5xx  | p99 ≤ **20 ms** (CI hardware baseline) | `/api/test/_throw-runtime`   | 500    |

Each path runs 100 warm-up iterations to seed opcache / classloader, then 1000 measured iterations whose per-iteration `\hrtime(true)` deltas are sorted to derive the p99. The runtime check applies a +50% shared-CI headroom (7.5 ms / 30 ms) over the raw NFR2 numbers so a real listener regression (a conditional sleep, a sync I/O, a serializer pipeline introduction) trips the gate while sub-percent jitter under shared CPU contention does not.

### Hard contractual invariants

These are pinned by always-on PHPUnit contract tests under `api/tests/Unit/Shared/ErrorContract/Application/` (NOT the opt-in benchmark group):

- **NFR4 — body serialisation:** native `\json_encode` with `JSON_THROW_ON_ERROR` only. No Symfony Serializer component, no normalizer, no reflection-based encoder anywhere under `Shared/ErrorContract/Application/` or `Shared/Http/Infrastructure/`. Pinned by `NativeJsonEncodeContractTest::testNoSerializerImports` and `NativeJsonEncodeContractTest::testEveryJsonEncodeUsesJsonThrowOnError`.
- **NFR5 — log write path:** the injected `Psr\Log\LoggerInterface` is the only logger contract on the error path. No Symfony Messenger dispatch, no `react/async`, no `amphp`, no `spatie/async`, no Swoole — synchronous PSR-3 writes (Monolog default stderr) are the contract. Pinned by `LoggerInterfaceContractTest::testListenerLoggerDepIsPsr3Only` (reflection on the constructor) and `LoggerInterfaceContractTest::testNoCustomAsyncInfrastructureInListenerOrFactory` (source-text grep).

### Running the benchmark

```bash
make php.bench           # opt-in; default `make php.unit` skips this group
```

The bench is **not** CI-blocking. The contract tests above are CI-blocking (NFR4 / NFR5); the budget numbers themselves (NFR2) are documented and measurable on demand.

## Test surface

| Test class                                                                                        | Pinning                                         |
|---------------------------------------------------------------------------------------------------|-------------------------------------------------|
| `ProblemDetailsFactoryTest`                                                                       | full factory contract                           |
| `MarkerStatusMapContractTest`                                                                     | per-marker status + type pin                    |
| `ExceptionResponderTest`                                                                          | listener happy path + last-resort body          |
| `ExceptionResponderFunctionalTest`                                                                | wire-level integration                          |
| `ProblemDetailsApiSchemaSweepTest`                                                                | every `/api/*` route conforms                   |
| `ExceptionResponderListenerPriorityTest`                                                          | priority + Nelmio CORS                          |
| `BannedDoctrineApisTest`, `NoDatabaseDependenciesContractTest`, `StatelessPropertiesContractTest` | worker-mode safety                              |
| `NativeJsonEncodeContractTest`, `LoggerInterfaceContractTest`                                     | NFR4 / NFR5 contracts                           |
| `ConstantTimeAuthBranchingContractTest`, `ConstantTimeAuthBranchingBenchmarkTest`                 | NFR9                                            |
| `RedactionDenylistTest`                                                                           | denylist semantics + extension procedure (NFR8) |
| `RequestUriRedactionTest`                                                                         | logged `request_uri` carries no secret or person id |
| `ErrorContractGateTest`                                                                           | no catch-and-respond; this page documents every marker |
| `ConstraintMessageValueGateTest`                                                                  | no constraint message interpolates the value it rejected |
| `ExceptionResponderValidationRedactionTest`                                                       | `exception_message` carries no violation value, on either emission path and through the mapping wrapper |

Behat features under `api/features/shared/error_contract/` pin the wire contract end-to-end (correlation-id propagation, instance UUIDv7, violations extension).

## Review checklist

Use this when reviewing a PR that touches `api/src/Shared/ErrorContract/Domain/Exception/` or `api/src/Shared/ErrorContract/Application/`, or that adds a `DomainException` anywhere that can reach HTTP:

- [ ] Did the PR add a new marker interface? **Update the marker → HTTP status table above.**
- [ ] Did the PR change a value in `MARKER_STATUS_MAP` or `MARKER_DEFAULT_TYPE_MAP`? **Update the table above** (the table is a navigation aid; the values themselves come from the constant).
- [ ] Did the PR change the body shape (`ProblemDetails::toArray()`)? **Update the "Body shape" section.**
- [ ] Did the PR add a key to `RedactionDenylist::KEYS`? **Update the "Extending the redaction denylist" section** if the procedure changed; the new key itself does not need to be listed here (it lives in the constant).
- [ ] Did the PR add or configure a **validation constraint** whose message interpolates the value it rejected (`{{ value }}`, `{{ iban }}`, …)? Violation messages are rendered into `exception_message` and into the Sentry event — **give the constraint a message that names the rule**. Gate: `ConstraintMessageValueGateTest`.
- [ ] Did the PR add a query parameter that carries a secret or a person id? **Extend `RequestUriRedaction`** — a value the logged `request_uri` keeps has no owner of erasure. Nothing to extend at the Caddy edge: the access log keeps no query string at all, and `CaddyfileAccessLogRedactionGateTest` reds a `query` enumeration reappearing there.
- [ ] Did the PR change the env-aware `debug` shape? **Update the "Environment-aware `debug` extension" section.**
- [ ] Did the PR change the listener priority or CORS interaction? **Update the "Listener layout" section.**
- [ ] Did the PR add a **marker-less** `DomainException` outside `api/src/Shared/ErrorContract/Domain/Exception/` that can reach HTTP? It maps to **500** with its declared `type` (or the `domain-error` default if it declares none) and is **not** Sentry-suppressed — **document it in the "Marker-less domain exceptions" section above.** The drift gate does not catch this.

> **Adding a marker interface or changing its mapping requires updating this page**.
