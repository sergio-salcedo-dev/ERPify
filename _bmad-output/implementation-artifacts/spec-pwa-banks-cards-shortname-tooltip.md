---
title: 'Tooltip del shortName en la vista de tarjetas de Banks'
type: 'bugfix'
created: '2026-06-04'
status: 'done'
route: 'one-shot'
---

# Tooltip del shortName en la vista de tarjetas de Banks

## Intent

**Problem:** En la vista de tarjetas, un shortName de 50 chars se trunca sin forma de leerlo completo: su tooltip (`TruncatedText`) se montaba pero nunca podía abrirse, porque el overlay de navegación de la tarjeta (el `after:absolute after:inset-0` del link del título) interceptaba el hover. En la vista de lista (sin overlay) el tooltip sí funciona.

**Approach:** Mismo tratamiento que checkbox y acciones — `relative z-10` en el span del shortName para sacarlo de debajo del overlay, más `openOnRowFocus={false}` explícito (hover-only; el tab stop de la tarjeta es el link del título, que posee el tooltip del Name). Trade-off aceptado por Sergio: el click sobre el texto del código deja de navegar al detalle (el resto de la tarjeta sigue navegando). Verificado en navegador real (Chrome via playwright-cli): hover abre el tooltip con el valor íntegro; hit-testing confirma overlay intacto en el resto de la superficie.

## Suggested Review Order

**El fix (una clase + una prop)**

- El shortName gana `relative z-10` + `openOnRowFocus={false}`; el comentario del call site explica la asimetría con la celda de la tabla (que no debe ganar z-index)
  [`BanksCards.tsx:103`](../../pwa/src/app/backoffice/banks/_components/BanksCards.tsx#L103)
- El comentario de anatomía de la tarjeta ahora nombra al código entre los elementos a `z-10` sobre el overlay
  [`BanksCards.tsx:76`](../../pwa/src/app/backoffice/banks/_components/BanksCards.tsx#L76)

**Test de regresión**

- Fija el contrato de stacking: el shortName lleva `relative z-10` y el link del título no gana un z-index que compita (volvería a tapar el tooltip en silencio)
  [`bankTruncationTooltips.test.tsx:42`](../../pwa/tests/app/backoffice/banks/bankTruncationTooltips.test.tsx#L42)

**Defer registrado**

- Cobertura e2e (hover real + trade-off de click) añadida a la entrada PR 6 de contención — jsdom no hace hit-testing
  [`deferred-work.md:100`](deferred-work.md#L100)
