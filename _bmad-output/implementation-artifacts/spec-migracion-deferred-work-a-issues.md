---
title: 'Migración de deferred-work.md a GitHub issues'
type: 'chore'
created: '2026-06-10'
status: 'done'
route: 'one-shot'
---

# Migración de deferred-work.md a GitHub issues

## Intent

**Problem:** `deferred-work.md` resultó ser el registro vivo de obligaciones pendientes (no un artefacto cerrado borrable): sink de Datadog, source-maps de Sentry, rate-limiting del túnel `/monitoring`, gate `tsc --noEmit`, guards de `FieldMapping`, y la cobertura e2e de cursor+sort pineada a la extinta Story 2.2. Un fichero local no es un tracker: los items no se asignan, no se priorizan y se pierden.

**Approach:** Migrar los 24 items vivos a 14 issues de GitHub (#194–#207), deduplicando (privacy-theater→#194, prep notes→#195, rate-limiting×2→#197, stub parity×2→#206) y reasignando el item de keyset a PR3 del ciclo del ADR con su contrato corregido (422 `invalid-cursor`, no fallback a offset). El fichero se conserva como sink del workflow quick-dev, reducido a tabla-índice de issues + histórico de resueltos.

## Suggested Review Order

1. [`deferred-work.md`](./deferred-work.md) — el resultado: tabla item→issue, nota de migración, histórico con la postura de perf (Seq Scan consciente) preservada.
2. [#200](https://github.com/sergio-salcedo-dev/ERPify/issues/200) — el único hallazgo major del review adversarial: el escenario e2e debe assertar 422 `invalid-cursor` (contrato PR3 del ADR keyset), no el fallback a offset actual.
3. [#194](https://github.com/sergio-salcedo-dev/ERPify/issues/194)–[#207](https://github.com/sergio-salcedo-dev/ERPify/issues/207) — los 14 issues; los de triggers múltiples (#199, #204, #205) llevan triggers independientes marcados para que no se cierren con sub-items pendientes.
