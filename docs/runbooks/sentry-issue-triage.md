# Runbook — Sentry issue triage

No alert rule is configured, so nothing pages anybody; triage by hand is the control in their place
(accepted in [`PRODUCTION_SECURITY_CHECKLIST.md`](../../PRODUCTION_SECURITY_CHECKLIST.md) §7, "The domain
security signals reach Sentry, nothing alerts on them, and it is accepted"). This page is how an issue is
triaged — by a person or by an agent (Claude, Codex) — and the ledger the alert rules are to be designed
from. It covers every project in the organisation, the API's and the PWA's. What the API sends at all is
decided by `SentryEventFilter`: [`docs/api-error-contract.md`](../api-error-contract.md) → "Client-error
suppression in Sentry"; the PWA's SDK options are
`pwa/src/context/shared/observability/infrastructure/sentryInitOptions.ts`.

## Why the ledger lives here and not in Sentry

Sentry keeps an issue's events for the plan's retention window only (about 30 days on the free plan — check
the organisation's subscription page, the figure is not pinned anywhere in this repo). Once they age out, the
counts and the timeline go with them, so a comment on the issue is not a durable record. The ledger below is.

## Cadence

Nothing prompts a read, so the cadence is the control: a **sweep** at least weekly and before every deploy or
rehearsal, well inside the retention window — an issue left unread past it ages out untriaged and leaves no
trace. A sweep:

1. Checks the organisation's stats for dropped or rate-limited events (the Sentry MCP's `find_dropped_events`
   answers it). Any quota drop is a ledger row of its own and triggers the §7 re-assessment.
2. Lists `is:unresolved` across every project, regressions included, and triages each issue below.
3. Lands its rows in one `docs(…)` pull request — `main` is protected, so the ledger moves by PR, batched per
   sweep rather than per issue.

## Triaging one issue

1. Read the issue and its event timeline: exception, culprit, request host, route, status, count and spread.
   The **request host** decides the source (`erpify.local` is the rehearsal). The `environment` tag says
   `rehearsal` only where the env file sets `SENTRY_ENVIRONMENT=rehearsal` — enforced by
   `scripts/deploy/lib/prod-env.sh` for the tls-internal rehearsal since #1121 — and events from before that
   say `prod` whatever produced them. A worker or console event has no request: read the `console.command`
   or Messenger context instead.
2. Classify it as exactly one verdict, by precedence **attack > bug > noise > expected**, judged on the whole
   timeline rather than the first events — Sentry groups by type and stack, so one issue mixes benign and
   hostile occurrences, and a lockout wave looks like an `expected` lockout repeated:
   - **attack** — the volume, spread or shape points at hostile traffic. Say why, and what contained it (the
     lockout, the rate limiter, CORS).
   - **bug** — the code is wrong, or the infrastructure failed (`Handled by` then names the operational
     action). Fix it in a PR, then resolve the issue against that PR.
   - **noise** — the event should never have been sent. The fix is in `SentryEventFilter`, the SDK
     configuration or `sentryInitOptions.ts`, through a PR; never an inbound filter set in the Sentry UI,
     which no gate in the tree sees.
   - **expected** — a legitimate outcome the application already answers correctly (a framework 4xx, a
     wrong password, a single lockout). Name what answers it.

   A secondary finding (an attack that also exposed a bug) goes in `Cause`.
3. Comment on the issue before changing its status, in this shape:

   ```text
   Triage: <attack|bug|noise|expected>
   Cause: <one sentence — why it occurred>
   Handled by: <PR #n | the class/line that answers it | why no change is needed>
   Ledger: docs/runbooks/sentry-issue-triage.md
   ```

   **Neither the comment nor a ledger row carries a person's data**: no email, no username, no id taken from
   a URL path or query, no IP, no `user.geo`, no session id. Name a route by its template
   (`/api/v1/backoffice/users/{id}`) and a person by role ("an administrator"); describe the shape ("one
   account, 40 attempts in ten minutes"), never the subject. The ledger is git history, which no erasure
   path reaches.
4. Resolve it (`bug`, `noise`, `expected`), or leave an `attack` unresolved until its burst has stopped for
   seven days and resolve it then. Never ignore or archive an issue, and never resolve one without the
   comment: an issue resolved in silence is indistinguishable from one nobody read.
5. Append its ledger row. Rows are never edited: an issue Sentry reopens as a regression is triaged again and
   gets a new row with the same id, and an `expected` that keeps regressing is the first input for its
   alert rule.

## Ledger

One row per triage. `Source` is `rehearsal`, `production` or `dev`, decided by the request host. `Events` is
the count at triage time — the number the retention window erases.

| Triaged    | Issue             | Source    | Exception — message (status)                                                   | Events | Verdict  | Handled by                                                  |
|------------|-------------------|-----------|--------------------------------------------------------------------------------|--------|----------|-------------------------------------------------------------|
| 2026-10-26 | ERPIFY-API-PROD-1 | rehearsal | `AccessDeniedException` — "The user is not appropriately authenticated." (401) | 2      | expected | Security firewall; framework 4xx reported by design (#1121) |
| 2026-10-26 | ERPIFY-API-PROD-2 | rehearsal | `CustomUserMessageAuthenticationException` — "Invalid credentials." (401)      | 2      | expected | `ProblemDetailsAuthenticationFailureHandler`                |
| 2026-10-26 | ERPIFY-API-PROD-3 | rehearsal | `AccessDeniedHttpException` — "Cross-origin request rejected." (403)           | 1      | expected | `RecoveryOriginListener`                                    |

The three rows above are tagged `environment: prod` in Sentry: they predate the rehearsal tag, and their host
is `erpify.local`.
