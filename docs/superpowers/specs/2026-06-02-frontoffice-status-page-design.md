# FrontOffice public status page — design spec

**Date:** 2026-06-02
**Scope:** `pwa` / `frontoffice`
**Branch:** `feat/frontoffice-status-page` (off `main`)

## Summary

Move the FrontOffice health check off the landing page into a dedicated **public
status page** at `/status`, styled as a faithful-but-simple analogue of
Atlassian Statuspage / Slack's status page. The page auto-runs the health check
on load (no button) and presents an aggregate banner plus a per-component row.
The landing page is simplified to remove the health card, and `/status` is
surfaced via "Status" links in the navbar and footer.

No backend changes: the page reuses the existing `FrontOfficeCheckHealth` use
case (`GET /api/v1/health`). The existing internal admin page at
`/backoffice/health` is kept unchanged.

## Goals

- A public, unauthenticated `/status` route reachable from the landing.
- Status is fetched **automatically on mount** (Atlassian behaviour), with a
  secondary manual **Refresh** action. No polling/realtime.
- Atlassian/Slack visual idiom: an aggregate status banner ("All Systems
  Operational" / degraded / disruption) plus a list of monitored components
  (today only "FrontOffice API").
- Simplify the landing page by removing the FrontOffice health card.
- Keep the internal `/backoffice/health` page as-is.

## Non-goals

- Uptime history / 90-day graphs.
- Incident history or incident records.
- Subscribe-to-updates.
- Polling or realtime (Mercure) refresh.
- i18n — UI strings stay in English, consistent with the rest of the app.
- BackOffice health on the public page (FrontOffice only).

## Approach

A public page in the **marketing design language** (same as the landing),
reusing the existing FrontOffice health use case. Rejected alternatives:

- **Reuse the backoffice design system** (`AsyncBoundary`, `@/components/erpify`)
  — `pwa/CLAUDE.md` forbids cross-importing the backoffice and marketing design
  languages; the public page must match the landing, not the admin shell.
- **A full status "module"** (new bounded context, component registry, polling,
  incidents) — over-engineered for a simple page; YAGNI.

## Architecture

### Route & rendering

- New route: `pwa/src/app/status/page.tsx` (`"use client"` — it runs the check
  on mount via the Inversify container).
- Reuses `Navbar` and `Footer` from `pwa/src/app/_components/` so the public
  chrome matches the landing. The navbar's required `onGetStarted` handler
  navigates to `/backoffice`.
- Add `STATUS: "/status"` to `Routes` in
  `pwa/src/context/shared/domain/types/routes.ts` (with a doc-comment marking it
  a public route). All internal navigation uses `<Link href={Routes.STATUS}>`.
- Public route by design (no auth), like the landing — called out in the PR.

### New components (marketing language, in `app/status/_components/`)

- **`StatusBanner`** — large aggregate banner with colored bar + icon + headline:
  - *checking* → neutral/blue + spinner: "Checking system status…"
  - *operational* (HTTP ok and `status === "ok"`) → green + check:
    "All Systems Operational"
  - *degraded* (HTTP ok but `status !== "ok"`) → amber: "Partial Service Disruption"
  - *disrupted* (transport/HTTP error) → red: "Service Disruption"
  - Subline: "as of {datetime}" via
    `dateTimeProvider.formatIsoToLocalDateTime`, only when a server datetime is
    present.
- **`ComponentStatusRow`** — one row per monitored component: name on the left
  ("FrontOffice API"), status pill with colored dot on the right
  ("Operational" / "Disrupted"). Rendered by mapping over a components array —
  **today only FrontOffice**. Adding a component later is one more array entry;
  no speculative scaffolding now.
- **`deriveSystemStatus(...)`** — pure function mapping a `HealthCheck` result or
  an error to the UI status enum. Isolated and unit-testable. Lives next to the
  components (the "operational/disrupted" semantics are presentation, not
  domain — the domain stays unaware of UI labels).
- A secondary **Refresh** button (ghost) to re-run the check manually.

### Data flow & error handling

- `useEffect` on mount → `container.get<CheckHealth>("FrontOfficeCheckHealth").run()`.
- Success → `operational` / `degraded` per `status`; error → `disrupted`.
- **Security/privacy:** anonymous users are **never** shown `ProblemDetails`,
  `correlation-id`, or stack traces — only a friendly disruption message. The
  internal `/backoffice/health` page keeps its technical detail.

### Landing page changes (`pwa/src/app/page.tsx`)

- Remove the "FrontOffice API" `FeatureCard` and all its state (`healthStatus`,
  `checkHealth`) and now-unused imports (`CheckHealth`, `dateTimeProvider`,
  `Activity`, `container`).
- Keep a single "Admin BackOffice" card, **centered** (`grid-cols-1`,
  `max-w-md mx-auto`) so the layout reads as intentional. `goToBackOffice`
  stays.

### Discovery of `/status`

- **Navbar** (`app/_components/Navbar.tsx`): a new `<Link href={Routes.STATUS}>`
  "Status" entry in both the desktop and mobile menus, mirroring the existing
  Dev Tools link pattern. Test ids `navbar__link-status` /
  `navbar__link-status--mobile`.
- **Footer** (`app/_components/Footer.tsx`): a new "Status" link as
  `<Link href={Routes.STATUS}>`.

## Testing

- **Unit (Vitest):** pure test of `deriveSystemStatus` plus render tests of
  `StatusBanner` / `ComponentStatusRow` across the four states. The page's data
  fetching is covered by e2e.
- **E2E (Playwright):** new `pwa/tests/e2e/frontoffice/status.spec.ts` — visit
  `/status`, assert the "All Systems Operational" banner and the "FrontOffice
  API → Operational" row appear **without clicking** (auto-load).
- **Update `landing.spec.ts`:** remove the "runs frontoffice API health check"
  test (the button is gone); add a test that the "Status" link navigates to
  `/status`.
- **`tests/e2e/helpers/health-assertions.ts`:** replace `expectFrontOfficeHealthOk`
  with `expectStatusPageOperational` (new test ids); leave `expectBackOfficeHealthOk`
  untouched.
- New unique `data-testid`s: `status-page__banner`, `status-page__refresh`,
  `status-page__component-frontoffice` (respecting the uniqueness guard
  `tests/data-testid-uniqueness.test.ts`).

## Security review

- `/status` is an intentional public route (no auth), consistent with the
  landing — noted in the PR.
- Health response (`{ status, service, datetime }`) carries no secrets/PII.
- No `ProblemDetails` / `correlation-id` / stack trace leakage to anonymous
  users — friendly disruption message only.
- Internal links use static `Routes` constants via `<Link>`; no dynamic URL
  interpolation, so `safeHref` is not required here.
- No `dangerouslySetInnerHTML`; all strings render as escaped text.
- No CSP/header or CORS changes — the page makes the same same-origin call to
  `/api/v1/health` the landing already makes today.

## Docs to update (as part of the PR)

- `docs/architecture-pwa.md` — new public route + note that it reuses the
  FrontOffice health use case.
- `docs/source-tree-analysis.md` and `docs/claude-code-quickref.md` — new
  `app/status/` route directory.

## Out-of-scope follow-ups (file as issues if wanted)

- Real component health (DB / dependency checks) behind the health endpoint.
- A second public component (BackOffice API) once a public BackOffice health
  endpoint is desired.
- Uptime history / incidents / subscribe — the larger Atlassian feature set,
  each needing new backend storage.
