---
stepsCompleted: ['step-01-document-discovery', 'step-02-prd-analysis', 'step-03-epic-coverage-validation', 'step-04-ux-alignment', 'step-05-epic-quality-review', 'step-06-final-assessment']
documentsInScope:
  - _bmad-output/planning-artifacts/epics-images.md
  - docs/adr/images-vs-documents-conservation-contract.md
  - docs/adr/media-vs-documents-upload-boundary.md
documentsOutOfScope:
  prd: 'not present — architecture-only addendum, no PRD/brief for this feature (repo convention)'
  ux: 'not present — ux-ERPify-2026-06-26 and ux-ERPify-2026-07-06 checked, neither covers images/upload'
  otherEpics:
    - epics.md
    - epics-gdpr-hardening.md
    - epics-auth-foundation.md
    - epics-users-admin.md
    - epics-rbac-authorization-model.md
    - epics-identity-invitation-lifecycle.md
    - epics-regulatory-audit-trail.md
    - epics-backlog-resolution.md
---

# Implementation Readiness Assessment Report

**Date:** 2026-08-24
**Project:** ERPify

## Scope

Assessment of the `Shared/Images` epic (module first slice) — `epics-images.md` — against its
architecture source, ahead of `4-implementation`. No PRD/UX documents exist for this feature by design
(architecture-only addendum pattern used across this brownfield repo for backend infra work with no
open product questions); their absence is not a gap to flag.

## Document Discovery

- **PRD**: not found — expected, no PRD for this feature.
- **Architecture**: not found under the generic `*architecture*.md` pattern in `planning_artifacts/` —
  the actual source is `docs/adr/images-vs-documents-conservation-contract.md` (accepted, design, no
  code yet), superseding `docs/adr/media-vs-documents-upload-boundary.md` (D1/D2 still live).
- **Epics & Stories**: `epics-images.md` is in scope. 8 other `epics-*.md` files exist in
  `planning_artifacts/` — all belong to already-shipped epics from unrelated prior work; not
  duplicates, out of scope for this assessment.
- **UX Design**: 2 runs found (`ux-ERPify-2026-06-26`, `ux-ERPify-2026-07-06`), neither covers
  images/upload. Out of scope.

No duplicates requiring resolution.

## PRD Analysis (adapted — no PRD exists)

No PRD/brief exists for this feature (see Scope). The FR/NFR extraction this step normally performs
against a PRD was already done against the ADR in `epics-images.md`'s own step-01
(`bmad-create-epics-and-stories`), including two external adversarial passes. Re-deriving it from
scratch here would duplicate that work rather than validate it, so this step instead **cross-checks**
the ADR's decisions/invariants against `epics-images.md`'s Requirements Inventory directly.

### Functional Requirements (source: ADR decisions D1–D7)

FR1 (D1) · FR2 (D2 + interim rule) · FR3 (D3) · FR4 (D4) · FR5 (D5) · FR6 (D6 first-slice deliverables)
· FR7 (D6 third entry point / `ImageProcessor` seam). **Total FRs: 7** — one per ADR decision, D1–D6
covered 1:1, D6 split across FR6/FR7 because it names two distinct deliverables (the slice itself, and
the reusable seam). **D7** ("evidence semantics, for the context that does not exist yet") is correctly
excluded — it explicitly constrains a future `Documents` context, not this slice.

### Non-Functional Requirements (source: ADR invariants 1–6, plus D6's port contract and D1's resource-limit clarification)

NFR1–NFR6 map 1:1 to the ADR's six numbered invariants. NFR7 (`ImageStorage` write/delete contract) and
NFR8 (decoder resource limits) are not separately numbered in the ADR but are derived directly from D6's
text and D1's "size limit ≠ conservation classification" clarification (surfaced by the adversarial
pass, not invented). **Total NFRs: 8.**

### Additional Requirements

Captured in `epics-images.md`'s own Additional Requirements section: the Symfony 8.1
`mergeParamsAndFiles` hard constraint, the `ImageStorage` port asymmetry and transactional-boundary
findings from the ADR's own recorded adversarial pass (2026-07-29), the rescue inventory from the
withdrawn `Shared/Media` implementation (`08f8199`), and the decision firewall consolidating everything
step-02/step-03 must not reopen.

### PRD Completeness Assessment

N/A as a PRD — but as an architecture source, the ADR is unusually complete for a "no code yet" design:
6 numbered invariants, explicit consequences, and its own recorded adversarial pass. No FR/NFR gap found
in this cross-check.

## Epic Coverage Validation

Cross-checked each FR against actual **story-level AC text** in `epics-images.md`, not just the
coverage-map claim (a map entry pointing at an epic nobody's story tests is coverage of name, not of
fact — this is exactly what step-04 of `bmad-create-epics-and-stories` caught for FR3/FR7 before this
report existed).

| FR  | Requirement                                   | Epic coverage                                                                    | Status                                   |
|-----|------------------------------------------------|-----------------------------------------------------------------------------------|-------------------------------------------|
| FR1 | Conservation contract as the pipeline's boundary | Story 1.1, AC "decode → validate → normalize → re-encode → digest, en ese orden" | ✓ Covered                                 |
| FR2 | `UploadImage` sole entry point, no contract param | Story 1.1, AC "no existe firma ni camino que lo permita"                        | ✓ Covered                                 |
| FR3 | No promotion between contracts                 | Story 1.1, AC "no existe ningún método... que reclasifique"                      | ✓ Covered (added in step-04 of CE, 2026-08-24) |
| FR4 | `Images` manages canonical reps, not preservation | Story 1.2, minimal `Image` state AC (no legal-hold/retention/custody fields) + structural absence of any evidence-handling code (decision firewall) | ✓ Covered — **boundary requirement, tested by absence, not by a dedicated AC** |
| FR5 | Consumer-owned lifecycle, reliable `delete()`  | Story 1.2, `delete()` idempotency/failure ACs + deletion-order AC                 | ✓ Covered                                 |
| FR6 | First-slice deliverables complete              | Sum of Stories 1.1 + 1.2 + 1.3 (UploadImage, Image, pipeline, storage, port, read route) | ✓ Covered — **composite requirement, satisfied epic-wide, no single AC** |
| FR7 | `ImageProcessor` reusable seam                  | Story 1.1, AC "invocable de forma aislada e independiente de `UploadImage`"       | ✓ Covered (added in step-04 of CE, 2026-08-24) |

### Missing Requirements

None. FR4 and FR6 are legitimately boundary/composite requirements rather than single-behavior ACs —
noted explicitly rather than padded with a redundant AC that would test nothing new.

### Coverage Statistics

- Total FRs: 7
- FRs covered in epics: 7
- Coverage: 100% (5 direct ACs, 2 structural/composite)

## UX Alignment Assessment

### UX Document Status

Not found — 2 UX runs exist in `planning_artifacts/ux-designs/` (`ux-ERPify-2026-06-26`,
`ux-ERPify-2026-07-06`), neither covers images/upload (already verified in step-01 of both this report
and `bmad-create-epics-and-stories`).

### Alignment Issues

None — no UX document to align.

### Warnings

None. UX is **not implied** for this epic: the epic explicitly scopes no PWA surface (no consumer
wired — `Bank.logoImageId`/`User.avatarImageId` don't exist), and Story 1.3's read route is documented
as infrastructure proof (`GET /images/{imageId}` — "no es una API de producto lista para exponerse"),
not a user-facing screen. A UX gap warning would be a false positive here; the absence is scope, not an
oversight.

## Epic Quality Review

Applying the create-epics-and-stories standards without compromise — including where they cut against
this epic's own explicit scope.

### 🟠 Major (documented deviations, not defects)

**1. Epic title/goal are platform-centric, not end-user-centric.** By the literal rubric ("Infrastructure
Setup — not user-facing" is a named red flag), "Shared/Images — subida y lectura de representaciones
fungibles" reads as a technical milestone: no screen changes, no end user of ERPify benefits from this
epic alone — the "user" in its own story format is a future bounded context, not a person. This is a
real tension with the rubric, not explained away. **Accepted with rationale**: (a) this repo has a
direct precedent for exactly this shape — `epic-auth-foundation` ("prerequisite de E3, gated on nada
(greenfield)") shipped as a pure foundation epic with no end-user screen of its own; (b) the "platform
epic, no screen yet" framing was made explicit and reasoned through with Sergio during step-02 of
`bmad-create-epics-and-stories` for this exact epic, not defaulted into silently. Not a defect to fix;
a rubric mismatch this repo has already resolved once and resolves the same way again.

**2. Story sizing (~25–30 files/story) exceeds BMAD's default single-session sizing guidance.**
Deliberate, explicit user preference stated at the start of this epic's creation ("PRs grandes, menos
stories"), reasoned through across two adversarial passes rather than defaulted. Accepted.

### 🟡 Minor (fixed during this review)

**3. Story 1.2's deletion-order AC read as a forward dependency on Story 1.3.** "la ruta de lectura
(Story 1.3) ya trata esa ausencia..." — present tense, as if Story 1.3 already existed. Story 1.2 is
in fact completable and testable in isolation (repository + storage, no HTTP); the AC was stating a
cross-story invariant, not a prerequisite. **Fixed**: reworded to state explicitly that this is not a
forward dependency, and cast the Story 1.3 half as a requirement that story must honor when built, not
an existing fact.

### Compliance checklist

- [x] Epic can function independently (single epic, no forward epic dependency)
- [x] Stories appropriately sized *for the explicit user preference on record* (not BMAD's generic default)
- [x] No forward dependencies (fixed #3 above; 1.1 → 1.2 → 1.3 confirmed unidirectional)
- [x] Database tables created when needed (`Image` table in Story 1.2, not before)
- [x] Acceptance criteria are specific and testable (no vague criteria found — spot-checked across all
  three stories; every AC names a concrete precondition/action/outcome)
- [x] Traceability to FRs maintained (Epic Coverage Validation above, 7/7)
- [ ] **Epic delivers end-user value** — does not, by design; see Major-1 above (accepted deviation)

## Summary and Recommendations

### Overall Readiness Status

**READY**

### Critical Issues Requiring Immediate Action

None. Every gap this assessment found was fixed inline during the assessment itself, not deferred:

- FR3/FR7 missing story-level ACs — closed during `bmad-create-epics-and-stories` step-04 (before this
  report started).
- Story 1.2's deletion-order AC reading as a forward dependency on Story 1.3 — reworded during this
  report's step-05.

### Accepted Deviations (not blocking, documented with rationale)

1. Epic is platform/infrastructure-shaped, not end-user-value-shaped — matches this repo's
   `epic-auth-foundation` precedent, decided explicitly with Sergio, not a default.
2. Story sizing (~25–30 files) exceeds BMAD's generic single-session guidance — explicit user
   preference ("PRs grandes, menos stories"), reasoned through across two external adversarial passes.

### Recommended Next Steps

1. `[SP] Sprint Planning` (`bmad-sprint-planning`, required, phase `4-implementation`) — produces the
   sprint plan / `sprint-status.yaml` entries this epic's three stories will follow in sequence.
2. When ready to open a PR for `docs/shared-images-epics-q66r`: the branch already carries a recorded
   `## Adversarial pass` section in `epics-images.md`, satisfying the pre-PR gate.
3. First story to implement (per Sprint Planning's ordering): Story 1.1 (domain + canonicalization
   pipeline) — it is the only one with no dependency on the others.

### Final Note

This assessment found 0 critical issues, 2 accepted deviations (documented, not defects), and fixed 1
minor wording issue in place. FR coverage: 7/7. No PRD/UX gap (both correctly absent by scope). The
epic and its three stories are ready for `4-implementation`.

**Assessed:** 2026-08-24 · Sergio + Claude (bmad-check-implementation-readiness)
