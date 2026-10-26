# Runbook — Sentry issue triage

No alert rule is configured, so nothing pages anybody; triage by hand is the control in their place
(accepted in [`PRODUCTION_SECURITY_CHECKLIST.md`](../../PRODUCTION_SECURITY_CHECKLIST.md) §7, "The domain
security signals reach Sentry, nothing alerts on them, and it is accepted"). This page is how an issue is
triaged — by a person or by an agent (Claude, Codex) — and the ledger the alert rules are to be designed
from. What reaches Sentry at all is decided by `SentryEventFilter`:
[`docs/api-error-contract.md`](../api-error-contract.md) → "Client-error suppression in Sentry".

## Why the ledger lives here and not in Sentry

Sentry keeps an issue's events for the plan's retention window only (about 30 days on the free plan — check
the organisation's subscription page, the figure is not pinned anywhere in this repo). Once they age out, the
counts and the timeline go with them, so a comment on the issue is not a durable record. The ledger below is.

## Triaging one issue

1. Read the issue and at least one event: exception, culprit, `environment` tag, request path and status.
   The `environment` tag separates the local prod rehearsal (`rehearsal`, set by `SENTRY_ENVIRONMENT`) from a
   real deployment.
2. Classify it as exactly one of:
   - **bug** — the code is wrong. Fix it in a PR, then resolve the issue against that PR.
   - **expected** — a legitimate outcome the application already answers correctly (a framework 4xx, a
     wrong password, a lockout). Name what answers it.
   - **attack** — the volume or shape points at hostile traffic. Say why, and what contained it (the
     lockout, the rate limiter, CORS).
   - **noise** — the event should never have been sent. The fix is in `SentryEventFilter` or the SDK
     configuration, through a PR; never an inbound filter set in the Sentry UI, which no gate in the tree sees.
3. Comment on the issue before changing its status, in this shape:

   ```text
   Triage: <bug|expected|attack|noise>
   Cause: <one sentence — why it occurred>
   Handled by: <PR #n | the class/line that answers it | why no change is needed>
   Ledger: docs/runbooks/sentry-issue-triage.md
   ```

4. Resolve it (`bug`, `expected`, `noise`) or leave it unresolved and say so in the comment (`attack` still
   in progress). Never resolve without the comment: an issue resolved in silence is indistinguishable from
   one nobody read.
5. Append one row to the ledger in the same PR as the fix, or in a `docs(…)` commit when there is no fix.

## Ledger

One row per triaged issue. `Events` is the count at triage time — the number the retention window erases.

| Triaged    | Issue             | Source          | Exception (status)                                           | Events | Verdict  | Handled by                                                  |
|------------|-------------------|-----------------|--------------------------------------------------------------|--------|----------|-------------------------------------------------------------|
| 2026-10-26 | ERPIFY-API-PROD-1 | local rehearsal | `AccessDeniedException` — not fully authenticated (401)      | 2      | expected | Security firewall; framework 4xx reported by design (#1121) |
| 2026-10-26 | ERPIFY-API-PROD-2 | local rehearsal | `CustomUserMessageAuthenticationException` — bad login (401) | 2      | expected | `ProblemDetailsAuthenticationFailureHandler`                |
| 2026-10-26 | ERPIFY-API-PROD-3 | local rehearsal | `AccessDeniedHttpException` — cross-origin recovery (403)    | 1      | expected | `RecoveryOriginListener`                                    |
