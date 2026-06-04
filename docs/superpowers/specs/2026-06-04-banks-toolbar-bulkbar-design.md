# Banks list — toolbar search promotion + floating bulk-action bar

**Date:** 2026-06-04
**Status:** approved by owner (design review in-session)
**Scope:** `pwa/src/app/backoffice/banks/` list page only. Card view structurally unchanged. No API changes.

## Origin

Free-form design exploration of `/backoffice/banks` run in Google Stitch (project `10355369656243580827`, design system distilled from `pwa/DESIGN.md`). Six divergent variants were generated (3 table, 3 cards) plus faithful light/dark baselines. The owner shortlisted **"Toolbar-First Management View"** (screen `e9d0a87abc484b35bb3fae81eab1171a`, dark twin `6b52143e19734150a25a73f1772571d9`) for the table view and chose to keep the card view as-is. This spec adopts that direction's two real ideas and rejects its speculative ones.

> The Stitch dark renders use the older elevated-grey band; main has since moved to the navy-slate ramp (`04f293f`). Irrelevant to implementation — every surface consumes semantic tokens, so dark mode inherits the current ramp with no component branching.

## What changes

### 1. Toolbar: promote the name search out of the filters panel

Current: the toolbar row (`BanksFilters`) shows view toggle + density toggle (via `leading`) on the left and the "Filters" button on the right; the name search lives inside the collapsible panel.

Target toolbar row, left → right:

```
[view toggle] [density toggle]  ──spacer──  [search input] [Filters (n)]
```

- The search input is always visible, debounced (existing 300 ms behavior), and binds the **existing** `filter.name` state — no new filter semantics, URL/state handling unchanged.
- The collapsible panel keeps: short name, created from/to, sort by, sort direction. The name field is **removed from the panel** (single home for each control).
- The Filters count badge **stops counting `name`** — the control is no longer hidden, so counting it would be noise.
- Placeholder: `Search by name…`; visible label provided via the established `FormField`/label pattern of the toolbar (no placeholder-as-label — a11y non-negotiable). Mobile (`< sm`): the search input takes the full toolbar width above the Filters button (same stacking the toolbar already does).
- Reset behavior (panel Reset) continues to clear `name` too — state is shared, only placement moves.

### 2. Bulk bar: inline row → floating bottom-center surface

Current: `BanksBulkBar` renders an inline `bg-card border rounded-lg p-2` row between toolbar and list; appearing/disappearing shifts the layout.

Target: same component and behavior, repositioned as a floating affordance:

- Wrapper: `fixed bottom-6 left-1/2 -translate-x-1/2 z-50` (below dialogs/toasts in stacking, above content).
- Surface: `bg-card border border-border rounded-xl shadow-elevation-4 px-4 py-2` — token white-elevated in light; in dark the same tokens yield the navy-slate elevated surface (drop shadow acceptable: floating affordances are the sanctioned exception in dark).
- Contents unchanged: "N selected" count, **Clear**, **Delete** (destructive variant) with the existing confirmation dialog (named records + "+N more", inline `ProblemDisplay` on failure, optimistic bulk delete with rollback).
- The always-mounted polite live region for coalesced selection counts stays as-is; `Esc` clears selection under the existing transient-layer precedence rules.
- **Clearance:** while the bar is mounted, the list container gets bottom padding (≈ `pb-20`) so the pagination row is never obscured. No other layout shift.
- Reduced motion: no entry animation beyond what tokens already allow (instant mount is acceptable; if a transition is added it must collapse under `prefers-reduced-motion`, which `globals.css` already enforces globally).

### 3. Explicitly rejected from the mock (speculative — do not implement)

- **Columns** visibility control — `DataTable` has no column-visibility support; out of scope.
- **Export** — no export feature exists in the product.
- **Deactivate** — not a real bank operation.
- **Global top-bar search** — the app-shell top-bar icons are deliberately fake (owner-approved, phase A); untouched.
- No new statuses or fields — the bank surfaces show code, name, New/Active recency, created/updated only.

## Components touched

| File | Change |
| --- | --- |
| `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx` | Toolbar row gains the search input; name field removed from panel; badge count excludes `name`. |
| `pwa/src/app/backoffice/banks/_components/BanksBulkBar.tsx` | Positioning/surface classes; no behavioral change. |
| `pwa/src/app/backoffice/banks/page.tsx` | Bulk-bar mount point (floating, conditional bottom padding on the list container). |
| Tests under `pwa/tests/` mirroring the above | Updated/added (below). |

No new dependencies. No new tokens (everything maps to existing aliases). No Inversify changes. Entity template note: this toolbar + floating-bar arrangement becomes part of the phase-C replicable entity pattern.

## Accessibility

- Search input: visible label, `aria` wiring per `FormField` conventions; debounce does not steal focus.
- Floating bar: keeps `role`/live-region semantics it has today; focus order unchanged (bar is appended at the end of the page flow in DOM while positioned visually fixed — verify focus order stays logical; if DOM placement moves, keep it adjacent to the list it acts on).
- Hit targets ≥ 24×24; bar controls keep `title` + short static `aria-label` + sr-only fallbacks.
- Contrast: all-token surfaces — AA holds in both themes by construction.

## Keyboard

Unchanged: `↑↓` rows, `Enter` opens, `Space` selects, `Esc` clears selection (respecting transient layers).

**New (small, in scope):** the `/` shortcut from the documented `DataTable` keyboard contract (`pwa/DESIGN.md`) is currently **not implemented anywhere**. With the search promoted to an always-visible control it finally has a target: a page-level `keydown` handler focuses the toolbar search on `/`, ignored when focus is already in an input/textarea/contenteditable or a transient layer (menu/dialog/tooltip) is open. This closes a documented-but-unbuilt contract rather than adding new surface.

## Responsive

- `≥ sm`: single toolbar row as diagrammed.
- `< sm`: toggles row, then full-width search, then Filters button (the toolbar already stacks; search joins the stack). Floating bar spans `max-w-[calc(100vw-2rem)]` with wrap, staying centered.
- `< md` stacked card-rows (mobile table) unaffected.

## Testing

- **Unit (Vitest):**
  - typing in the toolbar search filters by name (debounced) and panel Reset clears it;
  - badge count excludes `name`, still counts shortName/date filters;
  - bulk bar renders floating wrapper classes when `count > 0`, list container gains clearance, a11y attributes intact;
  - existing `BanksBulkBar` behavior tests keep passing (Clear, Delete dialog, error display).
- **E2E (Playwright, CI-only per the local-e2e environment blocker):** keyboard walk — `/` focuses search (and is inert while typing in another field or with a dialog open), type to filter, select rows, floating bar appears, bulk delete via dialog, `Esc` precedence.
- **Quality gates:** `make pwa.quality` clean; security checklist (no new sinks, no dynamic hrefs, no storage writes beyond existing prefs).

## Success criteria

1. Name search reachable in one interaction from page load (no panel opening) in both views.
2. Selecting rows never shifts the table/cards layout; the bar floats and pagination stays reachable.
3. All existing banks unit + e2e suites green; new tests cover the moved behaviors.
4. Both themes verified visually (light first, then dark) with zero component-level theme branching.
