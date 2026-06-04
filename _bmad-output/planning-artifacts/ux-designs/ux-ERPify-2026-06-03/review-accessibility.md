# Adversarial accessibility review — ERPify entity-list spines (WCAG 2.2 AA)

Reviewer: adversarial a11y lens (bmad-ux). Scope: `DESIGN.md`, `EXPERIENCE.md`, `implementation-tailwind.md`, `audit-banks.md`, `.working/research-banks-ui-inventory.md`. Stack: React 19 + Shadcn (Radix) + Tailwind 4, English UI, desktop-first B2B, dark mode deferred, reduced-motion committed.

All contrast ratios below are computed with the WCAG relative-luminance formula (sRGB), alpha tints composited over their actual surface. Method verified against the documents' own claims (their "≈5.9:1 / ≈3.4:1" for `text-muted` / `text-subtle` reproduce as 5.77 / 3.25 — close enough that the doc's rounding is generous but directionally honest).

## Overall verdict

**Conditional pass with two blocking contrast defects the spines silently inherit.** The contention contract (table-fixed budgets, clamps, truncate-keeps-DOM-text) is sound and most a11y claims verify. But the spines declare `StatusBadge` and the status-dot "conserved" without auditing them — and the *existing* badge fails 1.4.3 by a wide margin (colored label on same-hue 15% tint: 2.19–3.90:1 where 4.5:1 is required), while the 6px status dot fails 1.4.11 (2.54–3.19:1 for success/warning where 3:1 is required). The badge is the redesign's signature "dot-first" element, so "conserved" propagates a real AA failure into every list. Separately, three high-severity specification gaps will surface in implementation: Radix Tooltip does not fire on touch (the chosen primitive cannot satisfy the touch path the spine promises), the tri-state header checkbox has no implementation path defined (today's native checkbox sets no `indeterminate`), and optimistic-delete / "Refresh list" focus destinations are unspecified. One premise in the tasking is itself wrong and is flagged.

Counts: **2 critical · 4 high · 4 medium · 3 low.**

Top 3:
1. **[critical, 1.4.3]** Conserved `StatusBadge` label (colored text on 15% same-hue tint) is 2.19:1 (success) / 2.72:1 (warning) — far under 4.5:1; "conserved" ships an AA failure into the dot-first signature element.
2. **[critical, 1.4.11]** 6px status dot at success `#10b981` = 2.54:1 and warning `#d97706` = 3.19:1 vs white — under the 3:1 graphical-object floor; the dot is *the* color channel in "dot-first".
3. **[high, 1.4.13 + touch]** Tooltip-si-truncado is specified on Radix Tooltip, which (a) has 700ms default delay and (b) does not open on touch/tap — so the truncated value has *no* access path on touch, contradicting the spine's "touch todo visible / valor a un focus de distancia".

---

## Findings

### Critical

#### C1 — Conserved StatusBadge label fails contrast (1.4.3 Contrast Minimum, AA)
**Where:** `EXPERIENCE.md` State/Component Patterns + `DESIGN.md` `status-badge` ("dot-first … el texto da el significado", label 11px); `audit-banks.md` lists StatusBadge under "lo que ya está bien". The spines treat the badge as conserved and only re-specify its geometry (20px h, 6px dot, 11px label), never its contrast.
**Reality:** The shipped `StatusBadge.tsx` renders colored text on a same-hue 15%-alpha tint (`bg-success/15 text-success`, etc.). Composited over white, label-on-tint measures:
- success `#10b981` → **2.19:1**
- warning `#d97706` → **2.72:1**
- danger `#dc2626` → **3.81:1**
- info `#5e6ad2` → **3.90:1**

Over `surface-row-selected #eef0fb` each drops further (1.97 / 2.42 / 3.40 / 3.46:1). The 11px medium (500) label is normal-size text (well under the 18.66px-bold / 24px large-text threshold), so the floor is **4.5:1**. All four variants fail; success/warning fail badly. The neutral variant (`text-muted` 5.77:1) passes.
**WCAG:** 1.4.3 (AA).
**Fix:** Either darken the label tokens to a 4.5:1-passing shade of each hue on the tint (e.g. derive `--status-success-fg` ≈ a `#0f7a5a`-class green, validated at ≥4.5:1 over both white and `#eef0fb`), or switch the badge label to `--erpify-text` (#08090a, 18.9:1 on the tint) and let the dot carry hue. Add this to `DESIGN.md.status-badge` as an explicit contrast clause and to the e2e/contrast gate. **This must be specified in the spine, not left to "conserved" — the conserved component is non-compliant.**

#### C2 — Status dot fails non-text contrast (1.4.11 Non-text Contrast, AA)
**Where:** `DESIGN.md` `status-badge.dotSize: 6px`, "el punto da el color"; the dot is a graphical object conveying meaning (state), and in "dot-first" it is the primary color channel.
**Reality:** 6px dot vs the white/tint surface around it:
- success `#10b981` → **2.54:1**
- warning `#d97706` → **3.19:1** (marginal)
- danger `#dc2626` → 4.83:1 (pass)
- info/brand `#5e6ad2` → 4.70:1 (pass)

success fails 3:1; warning is on the line and fails once the dot sits on the selected-row tint.
**WCAG:** 1.4.11 (AA — graphical objects required to understand content need 3:1). Also relevant to 1.4.1 (Use of Color, A): if the dot is the differentiator and it's hard to perceive, the always-present text label is what rescues 1.4.1 — which is an argument to *keep* the text label readable (see C1), not a reason to dismiss C2.
**Fix:** Use the saturated/darker hue for the **dot fill** specifically (the dot can be a deeper shade than the label) so success/warning clear 3:1 on both white and `#eef0fb`; or add a 1px darker ring around the dot. Note this in `DESIGN.md`.

### High

#### H1 — Tooltip-si-truncado on Radix Tooltip: touch has no value-access path, and 1.4.13 needs explicit config (1.4.13 Content on Hover or Focus, AA; 1.1.1/touch)
**Where:** `EXPERIENCE.md` "Estrategia de texto largo" + Accessibility Floor ("cumple 1.4.13 — hoverable, dismissible (Esc), persistente; se abre también con focus"); `implementation-tailwind.md` §5 (Radix Tooltip, "Radix: hoverable, Esc cierra").
**Reality (verified against Radix Tooltip docs):**
1. **Touch:** Radix Tooltip opens on hover/focus only; it has no documented touch/tap trigger. On touch the truncated cell/title has **no** tooltip and (per spine) no `title` either — the value is reachable only by navigating to detail. The spine's "el valor completo siempre queda a un clic o un focus de distancia" and "touch todo visible" are not met for truncated text on touch. This is the regression-causing case the audit fixed (`title` worked on hover) being replaced by something that works on *neither* hover-less touch.
2. **1.4.13 "hoverable":** satisfiable only with `disableHoverableContent={false}` (the default) **and** a `TooltipProvider` actually mounted — and the repo currently has **no Tooltip primitive installed** (no `components/ui/tooltip.tsx`, no `TooltipProvider`). The contract depends on a component that does not yet exist; nothing in the spine pins the provider config.
3. **Delay:** Radix default `delayDuration` is **700ms**. 1.4.13 doesn't mandate a delay value, but a 700ms hover delay on a *truncation* affordance is poor; spec should pin a shorter delay for this use.
4. **Focus-into-content:** 1.4.13 "hoverable" requires the user be able to move pointer *over the content*; Radix supports this, but the content is not focusable and Esc is the only keyboard dismiss — fine for AA, but the spine should state that the tooltip is informational (`role="tooltip"`, not focus-trapping).
**WCAG:** 1.4.13 (AA) for the config; the touch gap is a functional-equivalence/Use-of-content issue (no SC strictly mandates tooltips on touch, but the spine *claims* the value is always reachable — that claim is false on touch).
**Fix:** (a) Specify the touch fallback explicitly — on coarse-pointer, either keep an accessible disclosure (tap reveals full value, e.g. the existing `title` retained on touch, or tap-to-expand-into-detail) so truncated values stay reachable; (b) pin `TooltipProvider delayDuration={150–300}` and `disableHoverableContent={false}` in the spine/recipe; (c) add the missing `components/ui/tooltip.tsx` to the prerequisites list in `implementation-tailwind.md`.

#### H2 — Tri-state header checkbox: `aria-checked="mixed"` mandated but no implementation path; today's checkbox sets no indeterminate (4.1.2 Name/Role/Value, A; 1.3.1)
**Where:** `EXPERIENCE.md` Accessibility Floor + Component Patterns ("Header checkbox tri-state con `aria-checked='mixed'`").
**Reality:** The current header control is a **native** `<input type="checkbox" checked={allSelected}>` (`DataTable.tsx:418`) with `handleSelectAll` and **no `indeterminate` wiring**. A native checkbox only exposes `aria-checked="mixed"` when JS sets the DOM `.indeterminate` property (it is not a React attribute and not derivable from `checked`). The spine asserts the end state but the redesign delta is silent on *how* — and since Banks has no Shadcn/Radix `Checkbox` primitive installed either, there's no obvious source of `data-state="indeterminate"`. Left as-is, the header shows checked/unchecked only and the partial-selection state is invisible to AT.
**WCAG:** 4.1.2 (A) — correct role/state; 1.3.1 (A).
**Fix:** Specify in the spine/recipe: bind a ref and set `ref.current.indeterminate = someSelected && !allSelected` in an effect (native path), or install Shadcn `Checkbox` and pass `checked="indeterminate"`. Add an explicit acceptance note that the header exposes `aria-checked="mixed"` when 0 < selected < total.

#### H3 — Focus management on optimistic delete and "Refresh list" is unspecified (2.4.3 Focus Order, A; 2.4.11 Focus Not Obscured, AA)
**Where:** `EXPERIENCE.md` State Patterns ("Borrado optimista: fila desaparece al confirmar"), Confirm destructivo ("se deshabilita … 'Refresh list'"), Key Flow 2 ("Refresh → la selección se recalcula a 22 … confirma"). Accessibility Floor covers tooltip/aria-live but **not** where focus lands after destructive mutations.
**Reality:** When the focused/active row is deleted optimistically and removed from the DOM, browser focus falls back to `<body>` — the keyboard user loses position (directly contradicting Flow 1's "mismo scroll, misma fila focada" promise applied to deletion). Likewise, pressing "Refresh list" inside the confirm dialog re-renders the list and re-enables Delete: focus destination (back to the re-enabled Delete? to the recomputed count? dialog stays open?) is undefined. Radix Dialog returns focus to the trigger on *close*, but these flows mutate content while open or remove the trigger row.
**WCAG:** 2.4.3 (A) Focus Order; 2.4.11 (AA, WCAG 2.2) Focus Not Obscured; supports 3.2.x predictability.
**Fix:** Add to the Accessibility Floor: after optimistic row removal, move focus to the next sibling row (or previous if last, or the table/empty-state if none) and announce via the existing `aria-live`. After "Refresh list", keep the dialog open and move focus to the now-re-enabled Delete (or to the ProblemDisplay if still failing). Specify these as contract, not leave to implementer.

#### H4 — Conditional `tabIndex=0` on truncated spans creates viewport-dependent tab order and non-interactive focusable elements (2.4.3 Focus Order, A; 4.1.2)
**Where:** `implementation-tailwind.md` §5 `TruncatedText`: `tabIndex={truncated ? 0 : undefined}`, truncation detected via `ResizeObserver`.
**Reality:** Whether a name/code span is in the tab order now depends on whether it is *visually* truncated, which depends on column width → **viewport size, density toggle, and zoom**. Same content is tabbable at 1280px and skipped at 1440px; resizing mid-session re-orders the tab sequence. Also, a `<span>` with `tabIndex=0` and no role is a non-interactive element injected into focus order — an ARIA anti-pattern (focusable thing with no role/action; screen-reader users hear an unlabeled, role-less stop). It compounds: in a 25-row × 2-truncatable-column table that's up to ~50 extra tab stops the keyboard user must traverse to reach pagination.
**WCAG:** 2.4.3 (A) — meaningful, stable focus order; 4.1.2 (A) — a focusable element should have an appropriate role; relates to 2.4.7 noise. (1.4.13 *does* require the value be reachable by keyboard, but row focus + an explicit affordance is a cleaner route than per-span tab stops.)
**Fix:** Prefer surfacing the full value through the already-focusable **row** (the spine's keyboard model already focuses rows with ↑/↓; the tooltip can key off row focus or a single per-row "show full name" mechanism), or make the truncated span a real button/`role="button"` with an accessible name when (and only when) truncated, with a stable presence. At minimum, document that tab order varies with truncation and justify it; do not ship a role-less `tabIndex=0` span as the standard.

### Medium

#### M1 — Focus ring contrast is marginal on tinted surfaces (1.4.11 Non-text Contrast, AA)
**Where:** `DESIGN.md` `table-row.focusRing: '2px --erpify-focus-ring (#7170ff), inset'`.
**Reality:** `#7170ff` as a UI-component focus indicator vs adjacent surface: 3.84:1 on white, **3.61:1** on page bg, **3.49:1** on row-hover `#f3f4f5`, **3.38:1** on selected `#eef0fb`. All clear 3:1 — *but* an **inset** ring sits with the row background on its inner edge and the cell background on its outer edge; on the selected row the ring (`#7170ff`) against the selected fill (`#eef0fb`) is 3.38:1, passing but with almost no margin, and any future darkening of the ring or lightening of the tint breaks it. (Note: WCAG 2.2's stronger **2.4.13 Focus Appearance** — which the tasking cites — is **AAA, not AA**; see "Premise corrections". At AA only 1.4.11 applies and it passes.)
**WCAG:** 1.4.11 (AA) — passes, flagged as low-margin.
**Fix:** No change required for AA. Recommend documenting the ring as ≥3:1-validated against all four list surfaces so a future token tweak is gated; consider a 2px outer (non-inset) or doubled light/dark ring for robustness.

#### M2 — Reduced-motion coverage omits the sticky-header scroll-driven shadow and the realtime "List updated" reveal (2.3.3 Animation from Interactions, AAA — and consistency of the stated commitment)
**Where:** `EXPERIENCE.md` Accessibility Floor (reduced-motion: "sin transiciones de reveal/hover/sheet; reveals sin fade; toast no anima"); `implementation-tailwind.md` §8 only resets `transition` on `.entity-card__actions, .banks-table__row-actions`; §2 adds a `@supports (animation-timeline: scroll())` keyframed `table-head-shadow`.
**Reality:** The spine names reveal/hover/sheet/toast but the recipe's reduced-motion block covers only the two reveal selectors. The **scroll-driven sticky-header shadow animation** (§2) is a scroll-linked animation and is **not** disabled under `prefers-reduced-motion`. Sheet (RecordSheet/Dialog) and toast entrance are asserted as covered but no recipe disables them (Sonner "respects prefers-reduced-motion by default" is noted as *unverified* — "verificar versión"). So the committed scope (a stated concern) is under-delivered.
**WCAG:** 2.3.3 (AAA — not AA), but the team *committed* reduced-motion as a concern, so this is a contract-fidelity gap rather than an AA failure. (2.2.2 Pause/Stop/Hide, A, could bite only if any of these animate >5s or loop — none do.)
**Fix:** Extend the §8 `@media (prefers-reduced-motion: reduce)` block to (a) disable `.banks-table__head` `animation` (or gate the whole `@supports` block behind `@media (prefers-reduced-motion: no-preference)`), (b) disable sheet/dialog transition, and (c) actually verify the Sonner version's reduced-motion handling rather than asserting it.

#### M3 — Detail H1 clamp: full accessible name must be guaranteed, not just "visible in the NAME field" (1.3.1 / 2.4.6 Headings, A/AA; 4.1.2)
**Where:** `DESIGN.md` `detail-h1` (clamp 2 lines) + `EXPERIENCE.md` ("el campo NAME de la ficha lo tiene completo"); `implementation-tailwind.md` §6 sets `line-clamp-2` on `<h1>` with `title={undefined}`.
**Reality:** `line-clamp` is CSS-only, so the full text **is** in the DOM/accessibility tree (good — the truncate-keeps-text claim holds; verified as a correct claim below). The H1's accessible name is therefore the full string — *acceptable*. The residual concern is purely sighted-keyboard: a sighted user who can't perceive the clamped overflow has the value in the NAME field below, which is fine. **No AA failure here** — but the spine should state explicitly that the H1's accessible name is the full value (it is, via DOM text) so no future "truncate text in JS" refactor breaks it. Downgraded from the tasking's implied concern.
**WCAG:** 2.4.6 (AA) / 1.3.1 (A) — currently satisfied; flagged as a guardrail.
**Fix:** Add a one-line note: "H1 keeps the full string in the DOM (CSS clamp only); never truncate the heading text in JS." No code change.

#### M4 — `aria-live="polite"` selection counter: announcement content and rapid-update behavior under-specified (4.1.3 Status Messages, AA)
**Where:** `EXPERIENCE.md` Component Patterns / State Patterns ("`aria-live='polite'` para el contador"; verified present in `BanksBulkBar.tsx:48`).
**Reality:** `polite` is the correct choice (selection count is non-urgent) — that claim is **correct**. Two gaps remain: (1) during `Shift+↓` range selection the count changes on every keypress; a live region that re-announces "3 selected, 4 selected, 5 selected…" floods the buffer. (2) The bar mounts only when selection > 0, so the region may be inserted *with* content — some AT don't announce content present at insertion time; the live region should be present (empty) before the first selection. The spine specifies the attribute but not the debounce or persistent-container pattern.
**WCAG:** 4.1.3 (AA, WCAG 2.1+).
**Fix:** Keep the live region mounted (empty) at all times so updates are announced; debounce/coalesce announcements during rapid range selection (announce the final count after a short idle, or announce deltas only on selection-commit). State this in the Accessibility Floor.

### Low

#### L1 — Hover/selected surfaces are luminance-identical; differentiation is hue-only (1.4.1 Use of Color, A — mitigated)
**Where:** `DESIGN.md` Colors: hover `#f3f4f5`, selected `#eef0fb`, asserted "claramente distinguible … incluso en monitores mal calibrados".
**Reality:** hover vs selected = **1.03:1** luminance — visually they differ essentially only in hue (neutral vs cool). On the doc's own "miscalibrated office monitor" scenario, hue is precisely the unreliable channel, so the "claramente distinguible" claim is overstated. **Not an AA failure** because the spine also mandates a non-color signal (checked box + ring) for selection, satisfying 1.4.1.
**WCAG:** 1.4.1 (A) — satisfied via the redundant checkbox/ring; flagged because the prose claim is inaccurate.
**Fix:** Soften the claim, or nudge `surface-row-selected` slightly darker/more saturated so the luminance delta is non-trivial. Low priority given the redundant signal.

#### L2 — `< md` table→stacked-cards conversion: no keyboard/focus model specified for the collapsed layout (2.4.3 Focus Order, A; 1.3.2)
**Where:** `EXPERIENCE.md` Responsive ("la tabla se convierte en lista de filas-tarjeta apiladas … `[ASSUMPTION]`").
**Reality:** The desktop keyboard model (↑/↓ row nav, Space select, Enter open) is defined for the `<table>`. The `<md` view replaces the table with stacked cards — but whether the same roving-tabindex/arrow model applies, whether each stack is a focusable row-equivalent, and whether `aria-sort`/selection semantics survive the DOM restructure is unspecified (and `[ASSUMPTION]`). Reading order and focus order must match the visual stack.
**WCAG:** 1.3.2 (A) Meaningful Sequence; 2.4.3 (A).
**Fix:** Specify that the stacked view preserves selection/activation semantics and a sensible focus order (or document it as out-of-scope for v1 with a tracked follow-up), since it's an `[ASSUMPTION]`.

#### L3 — `Esc` overloaded (clear selection vs close tooltip/popover/dialog) — precedence undefined (no specific SC; usability/2.1.1 robustness)
**Where:** `EXPERIENCE.md` Interaction Primitives: "`Esc` — limpia selección; cierra tooltip/popover/dialog superior".
**Reality:** When a tooltip is open *and* there is an active selection, one Esc must do exactly one thing (close the top layer) — the spine lists both behaviors without precedence. Radix layers consume Esc for the topmost overlay; the spine should state Esc resolves the topmost transient layer first and only clears selection when no overlay is open, to avoid clearing a 23-row selection by accident while dismissing a tooltip.
**WCAG:** none directly (2.1.1 Keyboard, A, is met); usability/robustness.
**Fix:** Document Esc precedence: topmost open overlay first; selection-clear only when no overlay is open.

---

## Premise corrections (tasking assumptions that are inaccurate)

- **2.4.13 Focus Appearance is AAA, not AA** in WCAG 2.2. The tasking groups it with "3:1 no-texto (2.4.13/1.4.11)". For an AA mandate, the focus-indicator requirement is **1.4.11 Non-text Contrast (3:1)** only; 2.4.13's stricter geometry/area rules are AAA and out of the AA contract. M1 is assessed against 1.4.11 (passes). Flagging so the team doesn't over-scope.
- **`text-muted` / `text-subtle` doc ratios are honest.** Documents claim ≈5.9:1 / ≈3.4:1; computed 5.77 / 3.25. The A1 finding (ban `text-subtle`/`text-faint` < 18px) is **correct** and the `meta-text-rule` flooring small text at `text-muted` is sound.

## Verified claims (checked and correct — not findings)

- **`text-muted #62666d` ≥ 4.5:1 on every list surface:** white 5.77:1, page bg 5.42:1, row-hover 5.24:1, row-selected 5.08:1. The `meta-text-rule` floor holds (1.4.3 ✓).
- **`text-subtle #8a8f98` fails AA for small text** (3.25:1 on white) — banning it < 18px (A1 / `meta-text-rule`) is correct.
- **Body text `#08090a`** is 17.5–19.9:1 across surfaces (1.4.3 ✓ comfortably).
- **`truncate` / `line-clamp` keep the full string in the DOM/accessibility tree** — CSS-only; the spine's "el valor completo siempre queda accesible" holds for screen readers at the table-cell, card-title, and detail-H1 level (the AT reads the full text even when visually clipped). The risk is only if someone truncates in JS — guarded by M3.
- **Row action buttons meet Target Size (Minimum):** 28px compact ≥ 24×24, 32px comfortable ≥ 24×24 → **2.5.8 (AA) passes outright**, no exception needed; the density toggle does not drop any target below 24px. (The tasking's worry is unfounded — correctly so.)
- **Comfortable-mode 40px touch target** claim (Accessibility Floor) exceeds 2.5.8 (24px) and approaches the AAA 2.5.5 (44px); fine for AA.
- **`aria-live="polite"` (not assertive) for the selection counter** is the correct urgency level (4.1.2/4.1.3 ✓) — only the content/debounce detail (M4) needs spec.
- **Preserved DataTable semantics** — `aria-sort`, `aria-selected`, `scope="col"`, `caption` sr-only, focus-visible 2px, ↑/↓/Enter/Space — are present in `DataTable.tsx` and correctly conserved (4.1.2, 1.3.1, 2.1.1 ✓).
- **Focus ring 1.4.11:** `#7170ff` ≥ 3:1 on all four list surfaces (3.38–3.84:1) — passes AA (flagged low-margin in M1).
- **Status colors as text** (e.g. `text-success` used as standalone label on white = 2.54:1) would fail — but the badge always pairs the colored label with the always-present text *string* and the dot, and 1.4.1 is satisfied by the text label; the contrast issue is C1, not a use-of-color failure.
- **No infinite scroll / paginated, referenceable positions** — supports cognitive accessibility and predictable focus; consistent with 2.4.3 / 3.2.x.
