---
title: 'Sentry observability — API (Symfony/FrankenPHP) + repo Sentry MCP'
type: 'feature'
created: '2026-06-08'
status: 'done'
baseline_commit: '184637867a7cc6384260596d7299e0539871df03'
context:
  - '{project-root}/docs/api-error-contract.md'
  - '{project-root}/PRODUCTION_SECURITY_CHECKLIST.md'
  - '{project-root}/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** ERPify has no error/performance observability. The codebase is pre-staged for it (commented Sentry block in `monolog.yaml`, env conventions in `.env.example`), but nothing is wired. Production runs on a test machine then a VPS, with no visibility into unhandled exceptions or slow requests.

**Approach:** Wire the official `sentry/sentry-symfony` SDK into the API for automatic exception capture + performance tracing, gated so dev/test emit nothing (empty DSN = no-op) and only prod (test machine + VPS, identical config) sends. Add the official Sentry MCP to the repo `.mcp.json` (remote OAuth) so DSNs/projects are provisioned via the MCP. PWA Sentry is a separate, deferred deliverable.

## Boundaries & Constraints

**Always:**
- Empty/unset `SENTRY_DSN` ⇒ SDK inert: dev and test send zero events with no conditional code.
- Sentry SDK lives only in `Infrastructure/` + `config/`; `Domain/` stays framework-free.
- The RFC 9457 pipeline is untouched: `ExceptionResponder` keeps building the Problem Details response; Sentry's bundle listener captures the throwable *in addition*, never replacing the response or duplicating reports.
- `send_default_pii: false` **and** a `before_send` scrubber, aligned with the existing `RedactionDenylist` denylist (password/token/secret/authorization/cookie/ssn/iban).
- Identical config on the prod test machine and the VPS — differences only via injected env vars, never code.
- DSN/auth tokens via env only; never committed. The Sentry MCP uses remote OAuth (no token on disk).

**Ask First:**
- Any change to `ExceptionResponder` priority or the single per-error PSR-3 log line (NFR26 — would require updating `docs/api-error-contract.md`).
- Raising `traces_sample_rate` above ~0.2 in prod, or widening what `before_send` transmits.

**Never:**
- Hardcoding a DSN or auth token in any committed file.
- Touching the PWA (`pwa/`) — deferred to the follow-up spec.
- Self-hosted Sentry (SaaS chosen); replacing stderr logging with Sentry (it is an *additional* sink).
- Editing applied/merged migrations.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Unhandled exception, dev or test | `SENTRY_DSN` empty | RFC 9457 response unchanged; SDK no-op, zero network calls | N/A |
| Unhandled exception, prod | `SENTRY_DSN` set | Problem Details response built as today; event also sent to Sentry; PII/secrets scrubbed | Sentry transport failure must not affect the HTTP response |
| Messenger handler throws, prod | `SENTRY_DSN` set | Failure captured with per-message isolated scope | Existing `failed` transport retry behavior preserved |
| FrankenPHP worker, 2 requests from different users | worker mode, prod | 2nd event carries no user/scope leaked from the 1st | N/A |
| Secret/PII inside exception context | any env | `send_default_pii: false` + `before_send` strip it before transmission | N/A |

</frozen-after-approval>

## Code Map

- `.mcp.json` — repo MCP registry (has `postman`, `sonarqube`); add `sentry` remote.
- `api/composer.json` / `api/config/bundles.php` — add `sentry/sentry-symfony` (^5.10) + register `SentryBundle` (`all`). Flex may do both.
- `api/config/packages/sentry.yaml` — NEW; SDK config (dsn, tracing, messenger, before_send).
- `api/config/packages/monolog.yaml` — prepared Sentry block commented at lines 67–82 under `when@prod`; uncomment.
- `api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php` — NEW; `before_send` callback (Infrastructure).
- `api/src/Shared/Application/Problem/RedactionDenylist.php` — existing denylist to reuse for scrubbing.
- `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` — RFC 9457 listener (do NOT modify; reference only for priority ordering).
- `api/.env`, `api/.env.example` — env var declarations.
- `docs/api-error-contract.md`, `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/deployment-guide.md` — doc updates.
- `_bmad-output/implementation-artifacts/deferred-work.md` — mark PWA Sentry as the deferred follow-up.

## Tasks & Acceptance

**Execution:**
- [x] `.mcp.json` — add `"sentry": { "type": "http", "url": "https://mcp.sentry.dev/mcp" }` to `mcpServers` — repo-level Sentry MCP via remote OAuth, no secret on disk.
- [x] `api/composer.json` — `make composer c='req sentry/sentry-symfony'` — install SDK; let Flex register `SentryBundle` in `bundles.php` (verify `['all' => true]`).
- [x] `api/config/packages/sentry.yaml` — create: `dsn: '%env(SENTRY_DSN)%'`, `register_error_listener: true`, `options.environment: '%kernel.environment%'`, `options.traces_sample_rate: '%env(float:SENTRY_TRACES_SAMPLE_RATE)%'`, `options.send_default_pii: false`, `options.before_send` → scrubber service id, `messenger: { enabled: true, capture_soft_fails: true }` with per-message context isolation, `tracing: { dbal: { enabled: true }, http_client: { enabled: true } }`. Ignore `NotFoundHttpException`.
- [x] `api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php` — implement `__invoke(\Sentry\Event $event, ?\Sentry\EventHint $hint): ?\Sentry\Event`; strip request/extra/user keys matching `RedactionDenylist`. Autowire; reference its service id from `sentry.yaml`.
- [~] `api/config/packages/monolog.yaml` — DELIBERATELY NOT done (left commented). Capture is listener-driven (`register_error_listener`, full throwable + stack); the Monolog handler would only see `ExceptionResponder`'s contextless PSR-3 line and risk double-reporting. See Spec Change Log.
- [x] `api/.env` — add `SENTRY_DSN=` (empty) and `SENTRY_TRACES_SAMPLE_RATE=0` as all-env defaults so dev/test are inert.
- [x] `api/.env.example` — document `SENTRY_DSN` (server-only, populated only in prod) and `SENTRY_TRACES_SAMPLE_RATE` (≈0.2 in prod, 0 elsewhere); note the MCP-provisioned project.
- [x] `docs/api-error-contract.md` — short subsection: Sentry captures the raw throwable alongside the RFC 9457 pipeline; listener ordering unchanged; redaction parity with `before_send`.
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` + `docs/deployment-guide.md` — env-injected `SENTRY_DSN` (test machine + VPS), `send_default_pii: false`, `before_send` scrub, no committed secrets.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` — note PWA Sentry (SDK + `Telemetry` port adapter) proceeds as the next spec on this branch.

**Acceptance Criteria:**
- Given `APP_ENV=dev`/`test` with empty `SENTRY_DSN`, when an unhandled exception occurs, then the SDK makes no network call and the RFC 9457 response is byte-identical to before.
- Given a prod-like env with `SENTRY_DSN` set, when an unhandled exception occurs, then a scrubbed event reaches the Sentry transport without altering the Problem Details response or the per-error log line.
- Given `make php.stan` and `make php.quality`, when run after the changes, then both pass and the error-contract drift gate (`make php.lint.error-contract`) stays green.
- Given the container boots, when `make sf c='debug:config sentry'` runs in dev, then `dsn` resolves empty (inert) and the bundle is loaded.

## Spec Change Log

Implementation deviations from the drafted tasks (all within the frozen boundaries):

- **Bundle registered `prod`-only, not `all`.** Flex's recipe default is `['prod' => true]`, which is *stronger* than the drafted "all envs, empty-DSN gate": dev/test never load the SDK at all, so they cannot emit. Prod still gates on an empty DSN (verified inert: `dsn: ''`, `traces_sample_rate: 0.0`). `sentry.yaml` is `when@prod` accordingly. Boundary "dev/test send zero events" still holds — more strongly.
- **Monolog Sentry block left commented (task not executed).** Capture is via the bundle's `kernel.exception` `ErrorListener` (priority 128, `register_error_listener` default true), which gets the raw throwable + full stack trace. The commented Monolog handler would only receive `ExceptionResponder`'s contextless PSR-3 line and would double-report. Listener-driven is richer and single-path. Documented in `api-error-contract.md`.
- **`environment` + `SENTRY_ENVIRONMENT` added.** `environment: '%env(default:kernel.environment:SENTRY_ENVIRONMENT)%'` lets the test machine and the VPS (both `APP_ENV=prod`) be told apart in Sentry while defaulting to `prod`. Extra committed default `SENTRY_ENVIRONMENT=` (empty).
- **`before_send` `$hint` param dropped + `@api` added.** The cs-fixer/rector pass removed the unused `?EventHint $hint` (PHP still calls the 1-arg callable fine). `@api` on the class tells Psalm the YAML-referenced invokable is an entry point, not dead code (fixes `UnusedClass`).
- **Restored `bundles.php` FriendsOfBehat block.** Flex flattened `bundles.php` to a bare `return [...]`, clobbering the project's conditional `FriendsOfBehatSymfonyExtensionBundle` registration. Restored it alongside the Sentry line; diff is now `+Sentry` only.

### Review loop 1 (step-04 adversarial review) — patches

Findings from Blind/Edge/Acceptance reviewers, all classified `patch` (no intent_gap/bad_spec):

- **[CRITICAL, real] Empty-string `SENTRY_TRACES_SAMPLE_RATE` crashed the prod boot.** `%env(float:...)%` of an empty string throws `RuntimeException` (unlike the DSN, which fails inert). Fixed to `%env(float:default::SENTRY_TRACES_SAMPLE_RATE)%` — `default::` coalesces empty/unset to `null` → `0.0`. Order is load-bearing (`default::float:` evaluates `float` first and still throws). Verified: empty → `0.0`, `0.2` → `0.2`. Avoids: fail-closed-to-crash on a blanked-out var.
- **[HIGH, real] `query_string` PII leak.** The SDK stores `request.query_string` as a raw string, which the `is_array` guard skipped — `?token=`/`?password=`/`?iban=` leaked. Scrubber now parses, scrubs, and re-encodes it.
- **[MEDIUM, real] Nested values escaped the single-level filter.** `data['user']['password']` survived. Scrubber's `scrub()` is now recursive over `extra` and the `request` sub-arrays. `RedactionDenylist` itself (shared, contract-tested) was NOT changed.
- **Honoured spec `ignore_exceptions: NotFoundHttpException`** (was using only Flex's `FatalError` defaults) and set `register_error_listener: true` explicitly.
- **Doc/claim accuracy:** reworded `api-error-contract.md` ("could be extended to drop `domain_error`" — not shipped) and narrowed the `PRODUCTION_SECURITY_CHECKLIST.md` claim to the scrubber's real scope (extra/request/query_string; breadcrumbs + exception messages out of scope).
- **Added `SentryEventScrubberTest`** (4 cases: nested extra, nested request data, raw query_string, no-context passthrough).
- KEEP: the listener-driven (not Monolog) capture path and the `RedactionDenylist` reuse — confirmed correct by reviewers; preserve on any re-derivation.

### Post-review refinements (user request, 2026-06-08)

Frozen-intent renegotiation by the human, after the deploy wiring landed:

- **Sentry env vars delivered through the prod deploy.** `SENTRY_DSN` / `SENTRY_TRACES_SAMPLE_RATE` wired into `php` + `messenger_worker` in `compose.prod.yaml` (read from `.env.prod.local`), so `make deploy.local` carries them to the test machine and the VPS.
- **Required in prod.** Both added to `make prod.env.check`'s `PROD_REQUIRED_KEYS` and guarded by `${VAR:?}` in `compose.prod.yaml` — a prod stand-up aborts by name if either is missing (supersedes the earlier "optional / not a fail-to-start" framing).
- **`SENTRY_ENVIRONMENT` dropped.** Replaced the `%env(default:kernel.environment:SENTRY_ENVIRONMENT)%` fallback with plain `%kernel.environment%` — one fewer var; events tag as `dev` / `prod` automatically.
- **Sentry enabled in dev (not test).** Bundle is now `['dev' => true, 'prod' => true]` and `sentry.yaml` has a `when@dev` block (errors only, no tracing). Gated by `SENTRY_DSN`: empty in dev → inert; a developer opts in via `api/.env.local`. This relaxes the original frozen "dev and test send zero events" boundary **for dev** (human-approved); **test** still never loads the SDK, so the test suite is untouched (540 tests green). KEEP: test exclusion — re-enabling Sentry in test risks the listener-priority assertions.
- **Relocated to a `Shared/Monitoring` module (architecture review with Winston).** Ahead of the next task (adding Datadog as APM **and** an interchangeable telemetry sink), the scrubber moved from `Shared/Infrastructure/Monitoring/` to **`Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php`** (module-first, like `Media`/`Storage`; vendor under the layer, not above — the future `Telemetry` port is vendor-agnostic). Test + `before_send` service id + doc links updated. The port (`Monitoring/Domain/Telemetry`) and the `SentryTelemetry`/`DatadogTelemetry`/`CompositeTelemetry` adapters are **deliberately NOT created yet** — `DatadogTelemetry` needs the `dd-trace` SDK (would break the build) and the others have no callers (dead code / lint failure); they land in the Datadog task, designing the port with its real consumers.

## Design Notes

- **No duplicate reports / ordering:** `ExceptionResponder` (priority 16) builds the response; the SentryBundle error listener runs at lower priority and captures the throwable from the event — it does not see/replace the response. Do not reorder.
- **Env gating without conditionals:** empty `SENTRY_DSN` makes the PHP SDK skip transport entirely (verified SDK behavior). One var, empty by default in `.env`, injected only in prod — no `if (prod)` branches.
- **FrankenPHP worker scope leak (known issue getsentry/sentry-symfony#905):** in long-running worker mode, scope/user can bleed across requests. Mitigate with per-message context isolation for Messenger and ensure per-request reset (Symfony runtime resets the kernel; the bundle's request listener re-evaluates per request). Verify with the two-different-users manual check below.
- **Tracing:** `traces_sample_rate` env-driven; 0 in dev/test (no transactions), ~0.2 in prod. DBAL + HTTP-client instrumentation only.

## Verification

**Commands:**
- `make php.stan` — expected: no errors on changed PHP files.
- `make php.quality` — expected: full PHP lint sweep green.
- `make php.unit` — expected: existing error-contract / ExceptionResponder tests still pass.
- `make php.lint.error-contract` — expected: drift gate green (RFC 9457 contract intact).
- `make sf c='debug:config sentry --env=prod --resolve-env'` — expected: bundle loaded in prod, `dsn: ''` and `traces_sample_rate: 0.0` (inert until injected). In dev the extension is absent (prod-only bundle) — that absence IS the dev/test gate.

**Manual checks:**
- With a temporary non-empty `SENTRY_DSN` in a prod-like boot, trigger an exception and a Messenger handler failure; confirm events arrive in the Sentry project (created via MCP) with PII fields absent.
- Worker mode: issue two requests as different users; confirm the second captured event carries no user/scope from the first.

## Suggested Review Order

**Capture wiring & env gating (start here)**

- Entry point — the whole SDK config: prod-only gate, capture path, tracing, messenger, scrubber hook.
  [`sentry.yaml:15`](../../api/config/packages/sentry.yaml#L15)
- The strongest gate: bundle loads in `prod` only, so dev/test cannot emit.
  [`bundles.php:17`](../../api/config/bundles.php#L17)
- Crash-safe sampling: `float:default::` coalesces empty/unset → `0.0` (a bare `float:` would throw).
  [`sentry.yaml:29`](../../api/config/packages/sentry.yaml#L29)
- Worker scope isolation per message (FrankenPHP long-lived process).
  [`sentry.yaml:46`](../../api/config/packages/sentry.yaml#L46)

**PII scrubbing (highest-risk logic)**

- `before_send` entry: scrubs `extra`, request sub-arrays, and the raw `query_string`.
  [`SentryEventScrubber.php:45`](../../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php#L45)
- Raw `query_string` is a string, not an array — parse/scrub/re-encode so `?token=` can't leak.
  [`SentryEventScrubber.php:63`](../../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php#L63)
- Recursive strip at every depth, reusing the RFC 9457 `RedactionDenylist`.
  [`SentryEventScrubber.php:81`](../../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php#L81)

**Coexistence with the RFC 9457 pipeline**

- Listener table: Sentry captures at priority 128 (sets no response); `ExceptionResponder` still builds the body.
  [`api-error-contract.md:247`](../../docs/api-error-contract.md#L247)

**Repo MCP & env**

- Sentry MCP (remote OAuth, no secret on disk) for DSN/project provisioning.
  [`.mcp.json:23`](../../.mcp.json#L23)
- Env vars — empty DSN default, tracing rate, optional surface tag.
  [`.env:92`](../../api/.env#L92)

**Tests (peripheral)**

- Scrubber cases: nested request data and raw query-string leak paths.
  [`SentryEventScrubberTest.php:32`](../../api/tests/Unit/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubberTest.php#L32)
