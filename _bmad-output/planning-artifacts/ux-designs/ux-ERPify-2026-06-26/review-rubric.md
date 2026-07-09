# Spine Pair Review (rubric walker) — Investigación de Auditoría (Story 4.2)

**Verdict: READY-WITH-NITS** — Both spines are complete, internally consistent, and faithful to `.decision-log.md`. Every `{token}` reference resolves to an existing `pwa/DESIGN.md` CSS var; all five UX-DR trace to a concrete surface. One cross-spine component gap and a handful of density/consistency nits. No blockers.

## Coverage snapshot

- **EXPERIENCE.md required sections:** all 8 present (Foundation · IA · Voice and Tone · Component Patterns · State Patterns · Interaction Primitives · Accessibility Floor · Key Flows) + earned invented sections (PII & Forensic Integrity, Security review, Responsive & Platform, Anti-patterns, Open Questions). Key Flows has a named protagonist (Lucía), numbered steps, an explicit climax beat, and a PII variant path.
- **DESIGN.md section order:** canonical and order-locked — Brand & Style → Colors → Typography → Components → Do's and Don'ts (Layout/Elevation/Shapes omitted = inherited; legitimate).
- **Token resolution:** `{color.security}`, `{color.text-subtle}`, `{color.text-faint}`, `{color.bg-subtle}`, `{color.danger}`, `{color.focus-ring}`, `{font.mono}`, `{radius.micro}` all map to existing `--color-*` / `--font-mono` / `--radius-micro` vars in `pwa/DESIGN.md`. No dangling reference.
- **UX-DR trace:** DR1→`<AuditTimelineTable>`; DR2→`<AuditFilterBar>`; DR3→pivote + Journey mode; DR4→`<AuditEntryDrawer>`; DR5→`<ActorChip anonymized>`/`<RedactedValue>`/§ PII. DR5 at row-level is conditional on OQ-1 (documented backend dependency, graceful degradation specified).

---

## BLOCKER (0)

None.

---

## SHOULD-FIX (1)

- **`<AuditPagination>` has no DESIGN.md.Components row.** (EXPERIENCE.md § IA tree + § Component Patterns › Paginación reference `<AuditPagination>`; DESIGN.md.Components omits it.) Rubric #3 requires every named component in both spines. *Fix:* either add a one-line DESIGN.md row stating it inherits `BanksPagination` with no visual delta, or drop the new name and reference `BanksPagination` directly.

---

## NIT (6)

- **Process-narrative residue.** (EXPERIENCE.md § Component Patterns › Reconstrucción de jornada: "Dos piezas, decididas en coaching…") *Fix:* drop the "decididas en coaching" how-the-decision-was-made aside; keep the rule.
- **Token references bind by CSS-var convention, not a declared frontmatter map.** (Both spines; `pwa/DESIGN.md` has no Google-Labs YAML token block — it's CSS-var tables.) Every `{...}` still resolves, so not broken, but a rubric-#2 extractor finds zero frontmatter tokens. *Fix:* state once in DESIGN.md that `{color.x}` ≡ `--color-x` so extraction is unambiguous.
- **Permission-denied state names two components interchangeably.** (EXPERIENCE.md § State Patterns: "`<EmptyState permission-denied>` / `<AccessDeniedScreen>`".) *Fix:* pick one for the dormant (D1) state so 4.2b doesn't re-decide.
- **Metadata guard threshold is illustrative, not pinned.** (DESIGN.md `<MetadataBlock>` "p. ej. > 4 KB"; EXPERIENCE.md § State Patterns "guard de tamaño/profundidad".) Fine as impl detail. *Fix:* name a concrete byte/depth cap if byte-stable layout is wanted.
- **Repo-process compliance table inside an experience spine.** (EXPERIENCE.md § Security review.) Useful but it's the "section no downstream UX consumer reads" the rubric flags. *Fix:* acceptable to keep; consider moving to the 4.2b PR description.
- **Inline ID scaffolding (D4/D6/OQ-n/`heredado`/jsx-a11y S6847).** Load-bearing traceability in a planning artifact, so OK here — but per repo comment-hygiene it must not migrate verbatim into `docs/` or code. *Fix:* sweep IDs if any of this text is later distilled into `docs/`.

---

## Implementability

Screen 4.2b is buildable from the spines without re-deciding interaction, except the four documented Open Questions (OQ-1 row-anonymization flag — backend; OQ-2 action filter free-text vs select; OQ-3 `?entry=` deep-link as opt-in enhancement; OQ-4 humanization dictionary; OQ-5 timezone). All are acceptable per the gate. No undocumented ambiguity found. Note for orchestrator: UX-DR5 is fully buildable in the drawer (`[REDACTED]` tell) but row-level anonymization presentation depends on OQ-1 being resolved by 4.1/4.2a.

## Consistency with `.decision-log.md`

No contradictions. Spines faithfully reflect D0.1–D0.4, A1–A5, B1–B4, C1–C4, D1–D3, and the systemic inherited decisions (debounce 250 ms, compact-36px default, URL-only PII filters, `<AsyncBoundary>` states, read-only no-bulk).
