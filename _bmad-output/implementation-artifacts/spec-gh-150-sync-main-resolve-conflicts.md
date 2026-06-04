---
title: 'PR #150: sync con main (conflicto bulk bar) + fixes Sonar'
type: 'chore'
created: '2026-06-05'
status: 'done'
route: 'one-shot'
---

# PR #150: sync con main (conflicto bulk bar) + fixes Sonar

## Intent

**Problem:** El PR #150 (`feat/pwa-banks-delete-persistent-error-es2n`) quedó `CONFLICTING` contra `main` tras mergearse `f7fb04e` (toolbar quick-search + sticky bulk bar, que mueve `BanksBulkBar` debajo de `AsyncBoundary` en la lista de bancos), y SonarCloud reportaba 5 issues abiertos en el PR (1 CRITICAL S3776 + 3 MAJOR + 1 MINOR).

**Approach:** Rebase del único commit del PR sobre `origin/main` resolviendo el hunk único de `page.tsx`: se acepta la posición sticky de `main` para `BanksBulkBar` (tras `AsyncBoundary`) y se conserva `MutationError` anclado sobre la tabla (contrato UX). Encima, un commit con los 5 fixes de Sonar (extracciones `DeleteErrorPanel`/`detailTopics` para bajar la complejidad cognitiva, split del ternario anidado, helper de test a scope de módulo, `.dataset`, spread sin `?? {}`) más un de-flake del seam de apertura del dropdown Radix en `bankListDelete.test.tsx` (reintento acotado del open, aserciones single-shot) que la integración con el toolbar destapó.

## Suggested Review Order

**Resolución del conflicto (semántica del merge)**

- `MutationError` permanece anclado sobre la tabla — contrato UX «superficie persistente sobre el origen»
  [`page.tsx:606`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/src/app/backoffice/banks/page.tsx#L606)

- `BanksBulkBar` una sola vez, tras `AsyncBoundary` — contrato sticky de `main`; el foco resuelve por testid, no por posición DOM
  [`page.tsx:708`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/src/app/backoffice/banks/page.tsx#L708)

**Complejidad cognitiva S3776 (CRITICAL) — detalle de banco**

- `DeleteErrorPanel` extraído a nivel de módulo: saca el ternario anidado profundo de `BankDetailPage`; paridad exacta (testids, acción solo en 404)
  [`[id]/page.tsx:354`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/src/app/backoffice/banks/[id]/page.tsx#L354)

- Su uso: el closure `onRefresh` conserva limpiar problem → armar foco → `loadBank()`
  [`[id]/page.tsx:285`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/src/app/backoffice/banks/[id]/page.tsx#L285)

- `detailTopics(id)` extraído con su comentario de defensa UUID→IRI; expresión idéntica
  [`[id]/page.tsx:54`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/src/app/backoffice/banks/[id]/page.tsx#L54)

**S3358 — ternario anidado en `handleBulkDelete`**

- Split en `fallbackDetail` + ternario simple, espejo del idioma ya usado en `loadBanks` (línea 151)
  [`page.tsx:441`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/src/app/backoffice/banks/page.tsx#L441)

**De-flake + fixes de test (S7721 · S7761)**

- `openDeleteItem`: reintento acotado del open del dropdown Radix (el churn post-toolbar cerraba el menú recién abierto); la aserción final propaga — no enmascara regresiones
  [`bankListDelete.test.tsx:147`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/tests/app/backoffice/banks/bankListDelete.test.tsx#L147)

- `confirmDeleteOf` a scope de módulo (S7721) y reutilizado por las 3 copias inline del primer describe
  [`bankListDelete.test.tsx:160`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/tests/app/backoffice/banks/bankListDelete.test.tsx#L160)

- `.dataset.testid` con narrowing `instanceof HTMLElement` (S7761); `undefined` sigue fallando el `toContain`
  [`bankListDelete.test.tsx:217`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/tests/app/backoffice/banks/bankListDelete.test.tsx#L217)

**Periféricos (S7744)**

- `...overrides.extensions` — spread de `undefined` es no-op garantizado; `?? {}` era ruido
  [`banks-api.ts:121`](../../.claude/worktrees/pwa-banks-delete-persistent-error-es2n/pwa/tests/e2e/fixtures/banks-api.ts#L121)
