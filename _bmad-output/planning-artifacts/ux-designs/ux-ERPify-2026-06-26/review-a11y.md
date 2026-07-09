# A11y review — Audit Investigation UI (WCAG 2.2 AA · keyboard · SR)

**Verdict: NEEDS-WORK** — one concrete AA contrast breaker; otherwise an unusually a11y-aware pair of spines (S6847, roving tabindex, color-not-sole-signal, focus-restore, aria-live, hit targets, PII-as-text are all consciously handled). Fix the blocker + four should-fixes and this is READY.

---

## BLOCKER

1. **Forensic text rendered sub-AA.** (DESIGN § Colors delta → `<RedactedValue>` / `<ActorChip anonymized>`; EXPERIENCE § PII rules 1–2) `[REDACTED]` uses `{color.text-faint}` and `anonimizado (GDPR)` uses `{color.text-subtle}`, both on `{color.bg-subtle}`. In light that is ~2.2:1 / ~2.7:1 — the system's own ramp doc labels faint "sub-AA by design" and subtle "not a body tier." This is **informative** text (GDPR/forensic), not decorative → fails WCAG 1.4.3 (≥4.5:1). **Fix:** label uses `{color.text-muted}` (≈4.8:1 on `bg-subtle`) or `{color.text}`; keep the gray as the chip *fill* only, never the text.

## SHOULD-FIX

1. **In-row controls have no keyboard path.** (EXPERIENCE § Interaction Primitives "Teclado (timeline)"; DESIGN `<AuditTimelineTable>`) Roving tabindex makes each row a single tab stop (`↑/↓`), yet `⋯` and the copy chips are listed as focusable ("el `⋯` se abre con Enter/Space estando enfocado"; chips in the focus-visible list). With one tab stop per row and no intra-row navigation defined, those controls are keyboard-unreachable → risks WCAG 2.1.1. **Fix:** adopt `role="grid"` with `←/→` (or Tab) intra-row movement, **or** declare in-row controls mouse-only and name the drawer (Enter) as the keyboard-equivalent path — then drop the "`⋯` focusable in-row" claim.

2. **Disabled Journey toggle reason is AT-invisible.** (EXPERIENCE § Voice/Interaction "Toggle Jornada deshabilitado"; DESIGN toggle) `disabled` + hover `tooltip` ("Fija un actor para reconstruir su jornada") hides the explanation from keyboard and SR users — disabled controls aren't focusable and don't surface tooltips. The climax flow depends on understanding why Journey is unavailable. **Fix:** `aria-disabled="true"` (stays focusable) + `aria-describedby` → the hint, or render the hint as visible/`aria-live` text beside the toggle.

3. **Rowgroup→header association unspecified/muddled.** (EXPERIENCE § Accessibility Floor; DESIGN `<AuditTimelineTable>` "fila-cabecera de grupo (`role="rowgroup"` + cabecera)") A single divider *row* cannot be `role="rowgroup"`, and "cabecera asociada" names no binding mechanism — without it SR users lose the day/session temporal context that is the point. **Fix:** rowgroup = `<tbody>` (or `div role="rowgroup"`) wrapping the day/session rows; the date/session label sits in a `<th scope="rowgroup">` header row (or rowgroup `aria-labelledby` → label id).

4. **Mobile card-row re-opens the S6847 hazard.** (EXPERIENCE § Responsive "< md") The stacked, single-tab-stop card-row is a focusable composite row **outside a native table** — exactly the jsx-a11y S6847 case the desktop `<table>` avoids; the spine's "no `div role=button` per row" claim silently holds only on desktop. **Fix:** scope the claim to ≥md and pick the mobile path explicitly (accept S6847 per the `BanksStackedList` precedent, or wrap the activator in a native control).

## NIT

1. **Silent state changes.** (EXPERIENCE § State / Interaction) Debounced filter refresh, a pivot ("Seguir a este actor") rewriting filters, and Journey flipping to enabled are all silent to SR. Add a `polite` region announcing result count after filtering and "actor fijado; modo Jornada disponible" after a pivot.
2. **Single-select semantics on segmented controls.** (DESIGN `<AuditFilterBar>` level segmented; mode toggle) Specify `role="radiogroup"`+radios or toggle buttons with `aria-pressed`/selected state — "segmentado"/"toggle" visuals don't convey current selection to AT alone.
3. **Filter-count badge in the accessible name.** (DESIGN `<AuditFilterBar>` "Filtros" + badge) Fold the active count into the button name (`aria-label="Filtros, 1 activo"`); a purely visual numeric badge leaves SR users hearing only "Filtros".
4. **Drawer section headers as structure, not decoration.** (EXPERIENCE § Detalle "— Qué — / — Quién —…") Render section dividers as real headings or `<dl>` group labels so the drawer's Qué/Quién/Sobre qué/Correlación/Metadata structure is programmatically exposed.
5. **Deep-link focus-restore target.** (EXPERIENCE § Detalle + OQ-3 `?entry=<id>`) Define where focus lands on close when the drawer opened cold with no origin row (matching row if present, else H1 / filter bar) — focus-restore is undefined for the deep-link path.
6. **Copy-chip target size in compact density.** (DESIGN `<AuditTimelineTable>` compact 36px) Verify `<CorrelationIdChip>` + inline copy affordances meet 24×24 (SC 2.5.8) where several share a 36px row.
7. **Group-header navigability.** (EXPERIENCE § Interaction "Teclado (Jornada)") Specify whether day/session headers join the roving sequence and how a session header announces correlation + `HH:MM–HH:MM` window + count.
8. **Anonymized actor in the row degrades silently (OQ-1).** Until the 4.1 read model exposes `actorAnonymized`, an erased actor reads to SR as a normal user with a random UUID in the timeline; the "anonymized" treatment exists only in the drawer. Acceptable as flagged, provided the drawer (Enter) stays the SR-reachable disclosure — keep the keyboard path to it solid (see SHOULD-FIX 1).
