---
title: 'Type-safe enum mappings en pwa/'
type: 'refactor'
created: '2026-06-06'
status: 'done'
context: []
baseline_commit: '794c5b8c0355d5a83c44bc72617137ed616b2a03'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** 5 objetos de mapeo en `pwa/src` asocian conjuntos cerrados (reveal scope, líneas de clamp, densidad, tema) a clases CSS/iconos sin anotación `Record<TipoNombrado, V>`: o no tienen tipo (solo `as const`), o el tipo se deriva del mapa vía `keyof typeof` (el mapa manda sobre el tipo, invirtiendo la fuente de verdad). Añadir un miembro al conjunto no produce error de compilación en el mapa. Otros 12 mapas del codebase ya siguen el patrón seguro.

**Approach:** Hacer que el tipo nombrado sea la fuente de verdad y anotar cada mapa como `Record<TipoNombrado, V>`, reutilizando tipos existentes (`Theme`, `ListDensity`) y declarando uniones explícitas donde el tipo era derivado del mapa. Cero cambios de comportamiento: las cadenas de clases y los iconos renderizados quedan idénticos.

## Boundaries & Constraints

**Always:**
- Seguir el estilo conformante dominante del repo: anotación `Record<T, V>` (no `satisfies`), con computed keys `[Theme.X]` solo donde existe const object.
- Mantener los comentarios "Tailwind cannot build/generate classes from template literals" — son load-bearing.
- Valores de los mapas byte-a-byte idénticos a los actuales.
- Named exports; sin default exports bajo `src/context/**`.

**Ask First:**
- Si durante la implementación aparece un mapa inseguro adicional fuera de los 5 inventariados.
- Si cambiar un tipo exportado (`TruncatedTextLines`) rompe consumidores fuera de los archivos del Code Map.

**Never:**
- No introducir declaraciones `enum` de TS — la convención del codebase es const object + union type (`keyof typeof CONST`).
- No tocar los 12 mapas ya conformes ni los variant maps de `cva` (API de librería).
- No añadir reglas ESLint nuevas en este PR (posible follow-up).
- No cambiar valores de clases, breakpoints ni comportamiento visual.

</frozen-after-approval>

## Code Map

- `pwa/src/app/backoffice/banks/_components/BankRowActions.tsx:29-35` -- `REVEAL_CLASS` con `as const` + `type BankRowActionsReveal = keyof typeof REVEAL_CLASS` (patrón invertido).
- `pwa/src/components/erpify/TruncatedText.tsx:10-12` -- `CLAMP_CLASS` numérico + `export type TruncatedTextLines = keyof typeof CLAMP_CLASS` (invertido, tipo exportado).
- `pwa/src/components/erpify/DataTable.tsx:55,88-96,239` -- `ROW_HEIGHTS`/`HEADER_HEIGHTS` sin anotar + unión inline `"compact" | "comfortable"` duplicada en dos props pese a existir `ListDensity`.
- `pwa/src/components/erpify/DensityToggle.tsx:7` -- `ListDensity` (tipo fuente, exportado por el barrel; no se edita).
- `pwa/src/components/erpify/ThemeToggle.tsx:35-39` -- `ICON_FOR_THEME` con computed keys pero sin `Record<Theme, …>`; sus hermanos `NEXT_THEME`/`NEXT_ACTION_LABEL` sí lo tienen.
- `pwa/src/context/shared/domain/types/theme.ts` -- const object `Theme` (no se edita).
- Referencia de estilo: `pwa/src/components/erpify/EmptyState.tsx:18` (`Record<EmptyStateVariant, LucideIcon>`).

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/app/backoffice/banks/_components/BankRowActions.tsx` -- declarar `type BankRowActionsReveal = "row" | "card" | "none";` antes del mapa; anotar `const REVEAL_CLASS: Record<BankRowActionsReveal, string>`; eliminar `as const` y la derivación `keyof typeof` -- el tipo pasa a ser la fuente de verdad.
- [x] `pwa/src/components/erpify/TruncatedText.tsx` -- declarar `export type TruncatedTextLines = 1 | 2;`; anotar `const CLAMP_CLASS: Record<TruncatedTextLines, string>`; eliminar la derivación -- misma inversión, manteniendo el contrato público del tipo.
- [x] `pwa/src/components/erpify/DataTable.tsx` -- importar `type ListDensity` desde `./DensityToggle`; sustituir las dos uniones inline `"compact" | "comfortable"` (props, ~líneas 55 y 239) por `ListDensity`; anotar `ROW_HEIGHTS`/`HEADER_HEIGHTS` como `Record<ListDensity, string>` y quitar `as const` -- elimina la triplicación del conjunto.
- [x] `pwa/src/components/erpify/ThemeToggle.tsx` -- importar `type LucideIcon` de `lucide-react`; anotar `const ICON_FOR_THEME: Record<Theme, LucideIcon>` y quitar `as const` -- iguala a sus mapas hermanos ya anotados.
- [x] Sweep final -- `grep -rn "keyof typeof" pwa/src` y revisar que ningún mapa de conjunto cerrado quede sin `Record<…>`; confirmar que el inventario de 5 era completo.

**Acceptance Criteria:**
- Given los 4 archivos refactorizados, when se ejecuta el typecheck (`next build`), then compila sin errores y ningún mapa objetivo conserva `keyof typeof <MAPA>` como definición del tipo.
- Given un miembro hipotético añadido a `Theme`, `ListDensity`, `BankRowActionsReveal` o `TruncatedTextLines`, when se typechequea, then cada mapa anotado falla la compilación hasta añadir la key (verificar mentalmente/por inspección — no commitear la prueba).
- Given la suite Vitest existente, when se ejecuta completa, then pasa al 100% sin modificar ningún test (cero cambio de comportamiento).
- Given `DataTable`, when se inspeccionan sus props, then `density` usa `ListDensity` y no queda ninguna unión inline `"compact" | "comfortable"` en el archivo.

## Spec Change Log

## Design Notes

El repo no usa `enum` de TS: el patrón establecido es const object + `as const` + union derivada (`Theme`, `IconTone`, `SystemStatus`). `keyof typeof MAPA` es inmune a la desincronización pero define el conjunto de dominio implícitamente desde un detalle de presentación (las clases CSS); el patrón objetivo invierte la dirección — el tipo declara el conjunto y `Record` fuerza exhaustividad en el mapa. Quitar `as const` es seguro: la anotación `Record<T, string>` ya aporta el checking y `cn()` consume `string`.

```typescript
// Antes (mapa manda):
const REVEAL_CLASS = { row: "…", card: "…", none: "…" } as const;
type BankRowActionsReveal = keyof typeof REVEAL_CLASS;
// Después (tipo manda):
type BankRowActionsReveal = "row" | "card" | "none";
const REVEAL_CLASS: Record<BankRowActionsReveal, string> = { row: "…", card: "…", none: "…" };
```

## Verification

**Commands:**
- `make pwa.quality` -- expected: ESLint + Prettier sin errores nuevos.
- `make pwa.test.unit` -- expected: suite Vitest 100% verde (nota: el test del toast de `bankListDelete` flaquea ~40% bajo carga de CPU — reintentar una vez antes de investigar).
- `make pwa.production.build` -- expected: build OK — es el único gate que ejecuta el typecheck completo de TS (ESLint/Vitest no reportan errores de tipos puros).

**Manual checks (if no CLI):**
- Diff de cada mapa: valores idénticos byte a byte a los originales.

## Suggested Review Order

**Patrón tipo-primero — la inversión de fuente de verdad**

- Entry point: unión explícita declarada antes del mapa — el tipo manda, no el mapa
  [`BankRowActions.tsx:29`](../../pwa/src/app/backoffice/banks/_components/BankRowActions.tsx#L29)

- `Record<…, string>` fuerza exhaustividad; `as const` y `keyof typeof` eliminados
  [`BankRowActions.tsx:31`](../../pwa/src/app/backoffice/banks/_components/BankRowActions.tsx#L31)

- Variante con keys numéricas: `1 | 2` explícito reemplaza la derivación, contrato público intacto
  [`TruncatedText.tsx:9`](../../pwa/src/components/erpify/TruncatedText.tsx#L9)

**Reutilización de `ListDensity` — fin de la triplicación**

- Import type-only del tipo ya exportado por el barrel; sin ciclo (DensityToggle no importa DataTable)
  [`DataTable.tsx:17`](../../pwa/src/components/erpify/DataTable.tsx#L17)

- Las dos uniones inline `"compact" | "comfortable"` sustituidas en props
  [`DataTable.tsx:56`](../../pwa/src/components/erpify/DataTable.tsx#L56)

- Ambos mapas de alturas anotados contra el tipo compartido
  [`DataTable.tsx:89`](../../pwa/src/components/erpify/DataTable.tsx#L89)

**Anotación con computed keys (const object existente)**

- `Record<Theme, LucideIcon>` iguala a sus hermanos `NEXT_THEME`/`NEXT_ACTION_LABEL`; keys `[Theme.X]` se conservan
  [`ThemeToggle.tsx:35`](../../pwa/src/components/erpify/ThemeToggle.tsx#L35)

- Único cambio de import: `type LucideIcon` añadido al import existente
  [`ThemeToggle.tsx:5`](../../pwa/src/components/erpify/ThemeToggle.tsx#L5)
