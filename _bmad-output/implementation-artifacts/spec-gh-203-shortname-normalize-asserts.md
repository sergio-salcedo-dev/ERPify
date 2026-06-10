---
title: 'gh-203: lock API shortName canonicalization in PWA E2E (proactive non-ASCII) + drop misleading toLocaleUpperCase'
type: 'bugfix'
created: '2026-06-10'
status: 'done'
baseline_commit: 'bbed61d68830b6fd0babae1bdd3287066e77f75d'
context:
  - '{project-root}/api/src/Shared/Domain/ValueObject/NormalizedText.php'
  - '{project-root}/pwa/tests/e2e/backoffice/banks-containment.spec.ts'
  - '{project-root}/pwa/tests/e2e/fixtures/banks-real-api.ts'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Issue #203 flagged that the banks real-API E2E specs re-derived the API's `shortName`
canonicalization with `.toLocaleUpperCase()`, which does NOT strip diacritics the way
`NormalizedText::toAsciiUpper` (`Any-Latin; Latin-ASCII; Upper()`) does — green only because seeded
inputs are ASCII. Investigation: the literal asserts were already fixed in `main` (commit `123d537`,
2026-06-07) — `banks-real-api.spec.ts`, `banks-real-api-flows.spec.ts` and `banks-containment.spec.ts`
all assert the API-returned value. Two residual gaps remain: (a) **no** E2E ever seeds a non-ASCII
`shortName`, so the diacritic-stripping rule is unproven from the PWA; (b) `banks-realtime.spec.ts`
still calls `.toLocaleUpperCase()` on seed input (L98/139/191) — the last live instance of the
anti-pattern.

**Approach:** Add a proactive real-API E2E that seeds a diacritic-bearing `shortName` and asserts the
API-canonicalized value (de-accented + uppercased) in both the API response and the rendered detail
page; drop the misleading `.toLocaleUpperCase()` from the three realtime seed lines. Close #203 via the
PR footer.

## Boundaries & Constraints

**Always:** assert `shortName` against the API-returned value, never a locally re-computed rule; keep
the diacritic input short (≤ ~40 chars) so the canonical form stays within the entity's 50-char limit;
reuse existing fixtures (`createBank`, `uniqueRunPrefix`, `deleteBanksSafely`, `trackedIds` cleanup);
navigate to the detail by id to avoid list pagination/filter races.

**Ask First:** adding a brand-new spec file instead of extending `banks-real-api-flows.spec.ts`.

**Never:** touch `NormalizedText` or any `api/` code; re-implement the transliteration in TS; assert via
`.toLocaleUpperCase()` / `.toUpperCase()`; depend on Mercure or pagination in the new test; use a
diacritic that expands under `Latin-ASCII` (ß→SS, Æ→AE); leave any code-level `.toLocaleUpperCase()` in
`pwa/tests`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Diacritic shortName create | POST `shortName: "<prefix>-GLÉ"` | API response `shortName` contains `GLE` and no accented char; `banks-detail__shortname` renders that exact canonical value | N/A |
| Mixed-case ASCII (existing) | create input `"...-inl"` | API returns `...-INL`; asserts use the returned value | N/A |
| Realtime seed input | `createBank` shortName without `.toLocaleUpperCase()` | bank created; shortName not asserted; live row/name behavior unchanged | N/A |

</frozen-after-approval>

## Code Map

- `pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts` -- add the proactive diacritic test (final test in the serial describe; creates its own bank, navigates by id).
- `pwa/tests/e2e/backoffice/banks-realtime.spec.ts` -- drop `.toLocaleUpperCase()` from the three seed `shortName` inputs (L98/139/191).
- `pwa/tests/e2e/fixtures/banks-real-api.ts` -- `createBank` / `ApiBank` reused as-is (no change expected).
- `api/src/Shared/Domain/ValueObject/NormalizedText.php` -- the rule being locked (read-only).
- `api/src/Backoffice/Bank/Application/Command/CreateBankCommand.php` -- confirms `shortName` has only `NotBlank` + `Length(50)`, no charset constraint → diacritic input is accepted (read-only).

## Tasks & Acceptance

**Execution:**
- [x] `pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts` -- add `test("create — diacritic short name is canonicalized to ASCII upper by the API")`: create a bank whose `shortName` input carries a diacritic, assert the API response strips the accent + uppercases the segment, then assert `banks-detail__shortname` renders the API-returned canonical value -- proves the diacritic-stripping rule end-to-end (the only residual coverage gap).
- [x] `pwa/tests/e2e/backoffice/banks-realtime.spec.ts` -- remove `.toLocaleUpperCase()` from the three seed `shortName` inputs -- last live instance of the re-derivation anti-pattern; `shortName` isn't asserted there, so behavior is unchanged.
- [ ] PR footer `Closes #203` -- auto-closes the (already-shipped-plus-hardened) issue on merge; respects protected-main (user merges). _(done at PR-creation time; no remote ops in the implement step.)_

**Acceptance Criteria:**
- Given the full stack is up, when the new diacritic test runs, then it passes; the asserted `shortName` contains the de-accented uppercase segment and matches no accented character.
- Given a grep for `toLocaleUpperCase` / `toUpperCase` over `pwa/tests`, then no code match remains (rule-describing comments may stay).
- Given the existing banks real-API and realtime specs, then they still pass (no regression).
- Given `make pwa.quality`, then ESLint + Prettier pass on the changed files.

## Spec Change Log

## Design Notes

- Golden pair: input `…-GLÉ` → API `…-GLE` (`Latin-ASCII` folds `É`→`E`, then `Upper()`). A
  `toLocaleUpperCase()` would yield `…-GLÉ` (accent kept) — that exact divergence is what the test locks.
- Assert behaviorally on the accented segment (`expect(shortName).toContain("GLE")` +
  `expect(shortName).not.toMatch(/[ÉÈÊËéèêë]/)`) rather than recomputing the full canonical string — it
  proves the rule without re-implementing transliteration. The detail assertion uses the API-returned
  `bank.shortName`, so UI↔API parity is covered too.

## Verification

**Commands:** (from inside the worktree, stack up via `make app.dev`)
- `PLAYWRIGHT_BASE_URL=https://localhost make pwa.test.e2e c='banks-real-api-flows.spec.ts'` -- expected: all pass incl. the new test.
- `PLAYWRIGHT_BASE_URL=https://localhost make pwa.test.e2e c='banks-realtime.spec.ts'` -- expected: pass. NOTE: per project memory `banks-realtime.spec.ts` has a known LOCAL Mercure-handshake timeout unrelated to this diff — if it times out locally, confirm `main` fails the same way before blaming the change; CI is authoritative.
- `grep -rn "toLocaleUpperCase\|toUpperCase" pwa/tests` -- expected: comment lines only, no code.
- `make pwa.quality` -- expected: ESLint + Prettier clean.

## Suggested Review Order

**Proactive canonicalization coverage (the fix)**

- Entry point — the new test that locks the API's diacritic-stripping rule end-to-end.
  [`banks-real-api-flows.spec.ts:284`](../../pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts#L284)

- Behavioral assertions on the API-returned value: `-GLE` tail + pure printable ASCII.
  [`banks-real-api-flows.spec.ts:296`](../../pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts#L296)

- UI↔API parity: the detail renders exactly the canonical value the API returned.
  [`banks-real-api-flows.spec.ts:303`](../../pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts#L303)

- Supporting: the `createBank` fixture import the new test needs.
  [`banks-real-api-flows.spec.ts:5`](../../pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts#L5)

**Anti-pattern cleanup**

- Drop the misleading `.toLocaleUpperCase()` on never-asserted seed input (3 sites, L98/139/191).
  [`banks-realtime.spec.ts:98`](../../pwa/tests/e2e/backoffice/banks-realtime.spec.ts#L98)
