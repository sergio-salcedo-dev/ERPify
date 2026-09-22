---
stepsCompleted:
  - step-01-load-context
  - step-02-discover-tests
  - step-03-map-criteria
  - step-04-analyze-gaps
  - step-05-gate-decision
lastStep: step-05-gate-decision
lastSaved: '2026-06-21'
coverageBasis: user_journeys
oracleConfidence: medium
oracleResolutionMode: synthetic_source
oracleSources:
  - 'api/src/Backoffice/Bank/** (controllers, application, domain, projection)'
  - 'api/src/Backoffice/BankAccount/** (controllers, application, domain)'
  - 'pwa/src/app/backoffice/banks/** + pwa/src/context/backoffice/bank[account]/**'
  - 'docs/adr/bank-bankaccount-modeling.md'
  - 'user-provided inline scope: bank / banks / bank account / bank count'
externalPointerStatus: not_used
tempCoverageMatrixPath: '_bmad-output/test-artifacts/traceability/e2e-trace-summary.json'
gate_type: feature
gate_decision: FAIL
gate_practical_reading: CONCERNS
scope: end-to-end (Playwright) coverage only
---

# Traceability Report — Bank / Banks / Bank Account / Bank Count (E2E)

**Evaluator:** Sergio · **Date:** 2026-06-21 · **Scope:** End-to-end (Playwright) only.
**Oracle:** synthetic, inferred from source (no formal PRD/epics/OpenAPI exist for Bank) + inline user scope. **Confidence:** medium → result is **advisory**.

## Gate Decision: FAIL (deterministic) — practical reading: CONCERNS

**Rationale (deterministic):** P0 coverage is 100%, but overall *fully-E2E-covered* requirements = 16/25 ≈ **64%**, below the 80% minimum (gate Rule 2 → FAIL). The shortfall is **entirely non-core**: it is concentrated in P2/P3 secondary journeys, the **bank-account read edges**, the **IBAN PII auto-remask**, and the **newly-added `bank_count` projection / per-bank account-count display & realtime** — none of which is a core or destructive flow.

**Architect's calibrated reading:** practically this is a **CONCERNS**, not a true FAIL. All core CRUD + destructive flows (create, edit, delete, delete-preconditions) are fully E2E-covered, and P1 is 92%. The "FAIL" is a breadth signal: the newest feature (bank count) and a handful of read-side edges have **zero E2E**. Proceed, but fill the elevated-risk gaps below.

## Coverage Summary (E2E only)

| Metric                      | Value             |
|-----------------------------|-------------------|
| Total traceable journeys    | 25                |
| Fully E2E-covered (FULL)    | 16 (64%)          |
| Partially covered (PARTIAL) | 3                 |
| No E2E (UNIT-ONLY / NONE)   | 6                 |
| **P0**                      | **3/3 = 100%** ✅  |
| **P1**                      | **11/12 = 92%** ✅ |
| P2                          | 2/8 = 25%         |
| P3                          | 0/2 = 0%          |

E2E test inventory: **9 spec files**, ~143 active test cases, **0 skipped / fixme / only**.

## Traceability Matrix

Coverage key: **FULL** = critical path + key failure/alt states asserted in E2E · **PARTIAL** = E2E exists but a meaningful sub-path is missing · **UNIT-ONLY** = exists but only Vitest/component, no E2E · **NONE** = no E2E.

### Bucket: `banks` (list)

| ID   | Journey                                                                                                                           | Pri | Cov  | E2E evidence                                                                                                                                 |
|------|-----------------------------------------------------------------------------------------------------------------------------------|-----|------|----------------------------------------------------------------------------------------------------------------------------------------------|
| R-01 | List renders rows / empty / first-run                                                                                             | P1  | FULL | banks.spec (list: renders rows, empty state); banks-real-api-flows (renders seeded)                                                          |
| R-02 | List **load error + Retry** boundary                                                                                              | P2  | NONE | — (create-500/edit-404 covered in banks-form-errors, but list-load 500 has no E2E)                                                           |
| R-03 | **Create bank** (+422 validation, +overlong name, +diacritic canonicalization)                                                    | P0  | FULL | banks.spec (create ×3); banks-real-api-flows (create happy/validation/diacritic); banks-real-api-name-length (255 bounds ×3); banks-real-api |
| R-04 | **Edit bank** (+422 validation)                                                                                                   | P1  | FULL | banks.spec (edit ×2); banks-real-api-flows (update); banks-real-api                                                                          |
| R-05 | **Delete bank** (inline + detail + success redirect + failed DELETE → persistent error surface, never in dialog)                  | P0  | FULL | banks.spec (delete ×4); banks-real-api-flows (delete); banks-delete-preconditions                                                            |
| R-06 | **Delete preconditions** (409 in-use, stale 404, bulk pre-check, bulk partial restore, optimistic guard, error not following nav) | P0  | FULL | banks-delete-preconditions (6)                                                                                                               |
| R-07 | Filters (name, shortName AND-combine, createdAt range, no-matches, clear-all, count badge, chips)                                 | P1  | FULL | banks.spec (filters/sort, many); banks-real-api-flows (filter)                                                                               |
| R-08 | Sort (default A→Z, header cycle, panel↔header sync, None disables direction, applies to cards)                                    | P1  | FULL | banks.spec (filters/sort); banks-real-api-flows (sort)                                                                                       |
| R-09 | Pagination (default 25, next/prev, last page, page-size 50/100, reset on filter/sort)                                             | P1  | FULL | banks.spec (pagination ×8); banks-real-api-flows (pagination round-trip)                                                                     |
| R-10 | View toggle table↔cards + localStorage persistence + unknown-value fallback                                                       | P2  | FULL | banks.spec (view toggle ×4 + "the user…")                                                                                                    |
| R-11 | Responsive stacked-mobile + long-name containment (255 char, tooltips, mixed checkbox, cards clamp, detail H1)                    | P2  | FULL | banks.spec (responsive ×3); banks-containment (19)                                                                                           |
| R-12 | List realtime CRUD (create/update/delete appear/drop live via Mercure)                                                            | P1  | FULL | banks-realtime (list ×3)                                                                                                                     |
| R-13 | Bulk delete (multi-select → bulk bar → optimistic tombstone/restore)                                                              | P1  | FULL | banks-delete-preconditions (bulk pre-check, bulk partial)                                                                                    |
| R-14 | List chrome: density toggle + column-picker persistence                                                                           | P3  | NONE | — (shared-component unit tests only; no bank-side E2E)                                                                                       |

### Bucket: `bank` (single / detail / create / edit forms)

| ID   | Journey                                                                                                           | Pri | Cov     | E2E evidence                                                                  |
|------|-------------------------------------------------------------------------------------------------------------------|-----|---------|-------------------------------------------------------------------------------|
| R-15 | Detail view happy path + 404 not-found correlation chip                                                           | P1  | FULL    | banks.spec (view: renders details, 404 EmptyState+chip); banks-real-api-flows |
| R-16 | Detail realtime (remote update re-renders; remote delete → redirect to list)                                      | P1  | FULL    | banks-realtime (detail ×2)                                                    |
| R-17 | Form error surfaces (create-500 persistent/copyable/dismissible; edit-404 Refresh; error not following on cancel) | P1  | FULL    | banks-form-errors (3)                                                         |
| R-18 | Copy bank ID (row + detail)                                                                                       | P3  | PARTIAL | banks.spec (detail copy + flips button). Row-level copy: no explicit E2E      |

### Bucket: `bankaccount` (read-only context by design)

| ID   | Journey                                                                           | Pri | Cov     | E2E evidence                                                                                            |
|------|-----------------------------------------------------------------------------------|-----|---------|---------------------------------------------------------------------------------------------------------|
| R-19 | View bank's accounts (cold load, empty state, error boundary + correlation id)    | P1  | FULL    | bank-accounts.spec (4)                                                                                  |
| R-20 | IBAN reveal + copy **+ auto-remask (10s / blur / page-change) PII safety**        | P1  | PARTIAL | bank-accounts.spec (reveal + copy). **Auto-remask paths: no E2E**                                       |
| R-21 | Accounts pagination (cursor prev/next, page-size 25/50/100, reset on size change) | P2  | NONE    | — (no E2E; no pagination test in bank-accounts.spec)                                                    |
| R-22 | Accounts invalid-uuid client guard (malformed bank id → BAD_REQUEST, no network)  | P2  | NONE    | —                                                                                                       |
| —    | **Account create / edit / delete**                                                | —   | N/A     | **Does not exist** — read-only context (no POST/PUT/DELETE in API or UI). Correctly **no E2E expected** |

### Bucket: `bankcount` (account-count display + bank_count projection total)

| ID   | Journey                                                                                      | Pri | Cov       | E2E evidence                                                                                                       |
|------|----------------------------------------------------------------------------------------------|-----|-----------|--------------------------------------------------------------------------------------------------------------------|
| R-23 | accountCount **→ delete guard** (count>0 blocks delete, "View accounts" recovery)            | P0  | FULL      | banks-delete-preconditions (optimistic guard, single 409, View accounts) — *the count's only E2E-covered consumer* |
| R-24 | Per-bank **"Accounts" count column** (list >0 link / 0 muted) + detail "Associated accounts" | P2  | UNIT-ONLY | banksAccountCount.test.tsx / banksListCount.test.tsx (Vitest). **No E2E**                                          |
| R-25 | accountCount **live update** when an account is added/removed                                | P2  | NONE      | — (only refreshes on bank.* Mercure events; stale-tolerant by design; no E2E asserts the count value changing)     |
| R-26 | `GET /banks/count` projection total → header "N banks total" (+ live refresh)                | P2  | NONE      | — (header total has no E2E; the new event-sourced bank_count projection is unit/Behat-covered only)                |

> **Out of E2E scope (do not penalize E2E):** `bank_count` projector, DBAL read-model, projection-rebuild CLI, schema listener, `BankAccountCounter` ports (A-33/36–40/46–47). These are backend correctness, covered by PHPUnit `#[CoversClass]` + Behat (PR #326). E2E can only reach them indirectly via R-23/R-24/R-26.

## Gaps & Recommendations (priority-ordered)

**No P0 gaps.** No critical/destructive journey is uncovered. ✅

Elevated-risk gaps worth E2E (recommended, in order):

1. **bank_count display & realtime (R-24, R-25, R-26)** — *newest feature in this worktree (PR #326)*, zero E2E. Add specs: per-bank "Accounts" column renders count + links; header "N total" reflects create/delete live. **Highest value** because it's new + user-visible + currently only unit/Behat.
2. **IBAN auto-remask (R-20)** — PII safety mechanism (10s timer / blur / page-change). A regression here silently leaks PII. Add an E2E that reveals, waits, and asserts re-mask.
3. **Bank-account read edges (R-21 accounts pagination, R-22 invalid-uuid guard)** — no E2E; both are real user paths.
4. **List-load error + Retry (R-02)** — create/edit error surfaces are covered; the list's own load-500 boundary is not.
5. **List chrome persistence (R-14)** and **row-level copy (R-18)** — P3; acceptable to leave at unit level unless you want parity.

Suggested next skills:
- `bmad-qa-generate-e2e-tests` → generate the R-24/R-25/R-26 + R-20 specs (highest value).
- `bmad-testarch-test-review` → if the concern shifts to E2E *quality* (the known local Mercure flake in banks-realtime is environmental, not a coverage hole).

## Next Actions

- [ ] Add E2E for bank-count column + header total + live update (R-24/R-25/R-26).
- [ ] Add E2E for IBAN auto-remask PII safety (R-20).
- [ ] Add E2E for accounts pagination + invalid-uuid guard (R-21/R-22).
- [ ] (Optional) E2E for list-load error boundary (R-02).
- [ ] Promote these inferred journeys into formal acceptance criteria so future traces run at high confidence.
