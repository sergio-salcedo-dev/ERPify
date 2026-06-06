---
title: 'Dedup CPD Sonar en PR #158 (tests bulk-actions de banks)'
type: 'refactor'
created: '2026-06-06'
status: 'done'
route: 'one-shot'
---

# Dedup CPD Sonar en PR #158 (tests bulk-actions de banks)

## Intent

**Problem:** El quality gate de SonarCloud en el PR #158 falla por densidad de líneas duplicadas nuevas (55/108 = 50.9%): `banksBulkActions.test.tsx` copiaba el harness de fixtures/mocks de `bankListDelete.test.tsx` (grupo CPD cruzado) y repetía el setup render→seleccionar→confirmar entre sus propios tests (3 grupos CPD internos).

**Approach:** Extraer la pareja canónica ACME/BETA a `_fixtures.ts` (compartida por ambos specs), sustituir las factorías `vi.mock` artesanales del spec nuevo por las ya existentes en `_mocks.ts` (`routerMock`/`containerMock`/`toastNotifierMock`, con spies `vi.hoisted`), y condensar el setup repetido en helpers locales (`failBetaDelete`, `rejectBetaReprobe`, `renderWithRows`, `selectRows`, `confirmBulkDelete`, `expectBetaRestoredAndReselected`) sin perder ni reordenar ninguna aserción.

## Suggested Review Order

1. [`_fixtures.ts`](../../pwa/tests/app/backoffice/banks/_fixtures.ts) — nueva fuente única de ACME/BETA; nota de inmutabilidad profunda (hallazgo de la review).
2. [`banksBulkActions.test.tsx`](../../pwa/tests/app/backoffice/banks/banksBulkActions.test.tsx) — el grueso: factorías de `_mocks.ts` + helpers locales; comprobar que cada `it` conserva sus aserciones originales.
3. [`bankListDelete.test.tsx`](../../pwa/tests/app/backoffice/banks/bankListDelete.test.tsx) — solo el swap de fixtures locales → `./_fixtures`; sus `vi.mock` artesanales quedan a propósito (código viejo, no cuenta para el gate).
4. [`deferred-work.md`](deferred-work.md) — entrada nueva: barrido suite-wide de fixtures/factorías en los 8 specs restantes, fuera de alcance.

## Verification

**Commands:**

- `make pwa.test.unit` — expected: suite completa en verde (462/462; los 17 tests de los dos specs refactorizados incluidos).
- `make pwa.quality` — expected: ESLint + Prettier sin hallazgos.
