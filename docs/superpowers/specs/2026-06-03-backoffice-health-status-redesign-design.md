# BackOffice health page — Atlassian-style status redesign

**Date:** 2026-06-03
**Scope:** `pwa` / `backoffice`
**Branch:** builds on `feat/frontoffice-status-page` (reuses the `api` telemetry
surface and the shared status model introduced there)

## Summary

Redesign the existing internal `/backoffice/health` page to adopt the same
Atlassian-style status idea as the public `/status` page — an aggregate status
banner plus a per-component row — but rendered in the **backoffice design
language** (design tokens + `@/components/erpify`), not the marketing palette.
The page auto-runs the BackOffice health check on mount (with a secondary
Refresh) instead of the current manual "Run Health Check" button. Unlike the
public page, this admin-facing page keeps showing the technical error detail
(`ProblemDetails` / correlation id) on failure, because it is an internal
diagnostic surface.

## Goals

- `/backoffice/health` shows an aggregate banner ("All Systems Operational" /
  degraded / disruption) + a per-component row for "BackOffice API".
- Auto-run `BackOfficeCheckHealth` on mount; a secondary **Refresh** re-checks.
- Backoffice design language (tokens, `@/components/erpify`) — never the
  marketing components from `app/status/_components/`.
- Reuse the pure status model (DRY) by extracting it to a shared home.
- Keep the admin-facing technical error detail on failure.
- Route the failure through telemetry with the `api` surface.

## Non-goals

- No polling / realtime refresh.
- No uptime history / incidents / subscribe.
- No FrontOffice service on this page (BackOffice only).
- No auth changes.

## Approach

Extract the pure `systemStatus` model to a shared location and reuse it from
both the public and backoffice pages; build backoffice-language presentation in
this page's own `_components/`. Rejected alternatives:

- **Duplicate a backoffice copy of the model** — violates DRY; the enum and the
  display copy would drift between the two status pages.
- **Reuse the marketing `StatusBanner` / `ComponentStatusRow`** — crosses the
  two design-language boundary `pwa/CLAUDE.md` forbids (marketing raw palette vs
  token-driven backoffice).

## Architecture

### Shared status model

- Move `pwa/src/app/status/_components/systemStatus.ts` →
  `pwa/src/lib/systemStatus.ts`. It stays pure (no framework, no palette): the
  `SystemStatus` enum, `deriveSystemStatus`, `systemHeadline`,
  `componentStatusLabel`.
- Decouple it from any specific `HealthCheck` class: `SystemStatusInput.result`
  becomes a structural `{ status: string; datetime: string } | null`. Both the
  frontoffice and backoffice `HealthCheck` classes satisfy this structurally.
- Update the public `/status` imports (`page.tsx`, `StatusBanner.tsx`,
  `ComponentStatusRow.tsx`) to import from `@/lib/systemStatus`, and move its
  unit test to `pwa/tests/lib/systemStatus.test.ts`.

### New component (backoffice language, `app/backoffice/health/_components/`)

- **`SystemStatusBanner`** — aggregate banner built with design **tokens**:
  - CHECKING → `bg-muted/50 border-border text-muted-foreground`, `Loader2`
    (spin).
  - OPERATIONAL → `bg-success/10 border-success/30`, `CheckCircle2`
    (`text-success`).
  - DEGRADED → `bg-warning/10 border-warning/30`, `AlertTriangle`
    (`text-warning`).
  - DISRUPTED → `bg-destructive/10 border-destructive/30`, `XCircle`
    (`text-destructive`).
  - Headline via `systemHeadline(status)`; subline `as of {datetime}` via
    `dateTimeProvider.formatIsoToLocalDateTime` when a server datetime is
    present. `role="status"` + `aria-live="polite"` + `aria-busy` while
    checking; icon `aria-hidden`.
- The per-component row reuses the design-system `<StatusBadge>` from
  `@/components/erpify` for the pill, with a local `SystemStatus → variant`
  mapping (OPERATIONAL→`success`, DEGRADED→`warning`, DISRUPTED→`danger`,
  CHECKING→`neutral`) and the label from `componentStatusLabel`. One monitored
  component today: "BackOffice API".

### Page (`pwa/src/app/backoffice/health/page.tsx`)

- `"use client"`; auto-runs `container.get<CheckHealth>("BackOfficeCheckHealth")`
  on mount via `useCallback` + `useEffect`; derives
  `view = deriveSystemStatus({ checking, failed, result })`.
- Renders the existing header, the `SystemStatusBanner`, a "Components" section
  with the single `<StatusBadge>` row, and a secondary ghost **Refresh** button
  (`title` + static `aria-label` + visible text + `aria-hidden` icon).
- **Admin error detail:** on failure, in addition to the DISRUPTED banner, it
  keeps surfacing the technical detail via `<ProblemDisplay variant="inline">`
  (the `ProblemDetails` from `HttpError`, or a fabricated transport-failure
  problem with a v7 `instance` / `correlation-id` as today). This is an internal
  diagnostic surface, so the detail is intentionally shown (unlike the public
  page).
- **Telemetry:**
  `telemetry.warn("BackOffice health check failed", { scope: apiScope("backoffice-health"), cause: err })`.

### Test ids

New unique ids: `backoffice-health__banner`, `backoffice-health__component-backoffice`,
`backoffice-health__refresh`. The old `backoffice-health-status` id is removed.

## Testing

- Move the model unit test to `pwa/tests/lib/systemStatus.test.ts` (update
  imports; pass structural snapshots).
- New unit test for the backoffice `SystemStatusBanner` (four states +
  aria-busy + aria-hidden icon).
- Update `pwa/tests/e2e/helpers/health-assertions.ts` `expectBackOfficeHealthOk`
  to assert the new banner ("All Systems Operational") + the
  `backoffice-health__component-backoffice` row ("BackOffice API" / "Operational").
- Update `pwa/tests/e2e/backoffice/dashboard.spec.ts` (desktop + mobile): drop
  the `Run Health Check` button click (the page auto-loads now) and assert the
  operational state via the updated helper.

## Security review

- `/backoffice/health` is an internal admin diagnostic page; showing
  `ProblemDetails` / correlation id there is intentional and appropriate (not a
  public leak). Auth posture unchanged.
- Failure detail routed to telemetry via `apiScope("backoffice-health")` —
  ops-only, silent in prod.
- No `dangerouslySetInnerHTML` / `eval`; tokens only; no dynamic URLs.
- No new dependencies; same same-origin `GET /api/v1/backoffice/health`.

## Docs

- Note in `docs/architecture-pwa.md` that `/backoffice/health` adopts the
  status-page style and reuses the shared `@/lib/systemStatus` model.

## Branch note

This builds on `feat/frontoffice-status-page` because it reuses the `api`
telemetry surface and the shared status model that live there. The branch then
covers both status pages (public `/status` + internal `/backoffice/health`); how
to land it is decided at finish time.
