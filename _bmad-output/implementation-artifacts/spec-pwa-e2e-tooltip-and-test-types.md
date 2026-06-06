---
title: 'Fix CI: aserción e2e del tooltip de shortName y TS2741 en tests/'
type: 'bugfix'
created: '2026-06-07'
status: 'done'
route: 'one-shot'
context: []
baseline_commit: '42d47b674591ed69c62a71adf62a986ac1e50995'
---

# Fix CI: aserción e2e del tooltip de shortName y TS2741 en tests/

## Intent

**Problem:** El e2e «cards: hovering the truncated shortName reveals the full-value tooltip» (añadido en el sweep de banks, sin posibilidad de correr en local) falla en todos los push a `main` (runs 27070740124, 27074711537): asume que el tooltip devuelve el input crudo, pero la API canonicaliza los códigos cortos a mayúsculas ASCII (`NormalizedText::toAsciiUpper`) al crear. Además, `deferred-work.md` arrastraba un TS2741 pre-existente en `bankTruncationTooltips.test.tsx` (prop requerida `onBankDeleteFailed` ausente), invisible para CI porque Next filtra los diagnósticos de ficheros `*.test.*`/`*.spec.*` y Vitest no typechecka.

**Approach:** Asertar el tooltip contra `cardBank.shortName` (el valor persistido que devolvió la API, mismo patrón que ya usa `longName()`); pasar un no-op `onBankDeleteFailed` en el render del test unitario; eliminar la sección diferida resuelta y re-diferir el gate `tsc --noEmit` como entrada propia con el mecanismo corregido tras la revisión adversarial (filtro por regex de nombre de fichero en `runTypeCheck` de Next — las fixtures de `tests/` sí están gateadas — más caveats de `incremental`/`tsbuildinfo` verificados).

## Suggested Review Order

**Aserción e2e contra el valor canónico de la API**

- El porqué del fallo de CI: la API canonicaliza, el test esperaba el input crudo
  [`banks-containment.spec.ts:153`](../../pwa/tests/e2e/backoffice/banks-containment.spec.ts#L153)

- La semilla mixed-case que sigue siendo válida como input de creación
  [`banks-containment.spec.ts:43`](../../pwa/tests/e2e/backoffice/banks-containment.spec.ts#L43)

**Cierre del TS2741 en el test unitario**

- No-op consistente con los renders hermanos (líneas 28/35/61); el test no ejercita el delete
  [`bankTruncationTooltips.test.tsx:43`](../../pwa/tests/app/backoffice/banks/bankTruncationTooltips.test.tsx#L43)

**Re-deferral del gate tsc + hallazgo diferido de la revisión**

- Entrada re-diferida con el mecanismo real de Next y caveats verificados en `42d47b6`
  [`deferred-work.md:67`](./deferred-work.md#L67)

- Fragilidad pre-existente (`.toLocaleUpperCase()` vs `toAsciiUpper`) diferida desde la revisión adversarial
  [`deferred-work.md:81`](./deferred-work.md#L81)
