---
baseline_commit: 81dc9b6d88aafc2ff251dfc8240e1a84a4f7f710
---

# Story 2.1: Vocabulario y builder de filtros compartido en la PWA

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a desarrollador de la PWA,
I want tipos `Filter`/`FilterOperator` y un `buildSearchParams` en `context/shared` que serialicen exactamente la gramática del contrato,
so that toda lista presente y futura componga filtros server-side sin duplicar serialización.

## Contexto de ejecución (leer antes de empezar)

- **Dónde se trabaja:** worktree `.claude/worktrees/shared-search-filters-aj0w/`, rama
  `feat/shared-search-filters-aj0w`, **PR #180**. La Épica 1 (historias 1.1–1.8) ya está `done` en
  ESTA rama (no en `main`): el contrato HTTP genérico `filters[N][field|operator|value]` existe y está
  verificado por Behat. Esta historia construye el **espejo TypeScript** de ese contrato.
- **Primera historia de la fase 2 (PWA).** Es trabajo nuevo y autocontenido: crea vocabulario + builder
  puros, sin tocar todavía la lista de banks. La migración server-driven de banks es la **Story 2.2**,
  que consumirá lo que aquí se crea. Diseña la API pública pensando en ese consumidor (ver «Lo que
  consumirá la Story 2.2»).
- **Cero cambios de UI, cero red.** No hay componentes, ni fetch, ni endpoints nuevos. Son ~3 ficheros
  fuente (tipos + builder + barrels) y 1 de test. No ejecutes `make php.*` (no se toca PHP).
- **Gate de cierre:** `make pwa.quality` + Vitest del nuevo test en verde. Ejecútalos desde **dentro del
  worktree** (los `make` targets entran en el stack del worktree).

## Acceptance Criteria

1. **Vocabulario de dominio** — En `pwa/src/context/shared/domain/Search/` se crean `Filter.ts` e
   `index.ts`. `FilterOperator` se define como **union type + const** (`'eq' | 'in' | 'contains' | 'gte' | 'lte'`),
   **nunca** un `enum` de TS. Solo **named exports** (regla de `src/context/**`). El subdirectorio sigue la
   convención PascalCase con `index.ts` de los siblings de `context/shared`.

2. **Serialización wire exacta** — `pwa/src/context/shared/infrastructure/Search/buildSearchParams.ts`
   serializa una lista de filtros produciendo **exactamente** la gramática wire D1/D2:
   `filters[N][field]`, `filters[N][operator]`, `filters[N][value]` (escalar) o `filters[N][value][]`
   (lista, para `in`), con índices **contiguos desde 0**. Una lista vacía o ausente **no produce ningún
   query param de filtro**.

3. **Tests Vitest** — `pwa/tests/context/shared/infrastructure/Search/buildSearchParams.test.ts` cubre:
   filtro escalar (`eq` y `contains`), filtro de lista (`in`), varios filtros combinados (índices 0,1…) y
   ausencia de filtros (lista vacía → sin params). TS 6 strict y `make pwa.quality` pasan en verde.

## Tasks / Subtasks

- [x] **Task 1 — Vocabulario `Filter` / `FilterOperator` en `domain/Search/`** (AC: #1)
  - [x] Crear `pwa/src/context/shared/domain/Search/Filter.ts` con:
    - [x] `FilterOperator` como **const object + union derivada**, mismo idioma exacto que
      `pwa/src/context/shared/domain/types/sorting.ts` (`export const X = {…} as const;
      export type X = (typeof X)[keyof typeof X];`). Valores: `Eq:'eq'`, `In:'in'`, `Contains:'contains'`,
      `Gte:'gte'`, `Lte:'lte'`.
    - [x] Tipo `Filter` como **union discriminada** por operador: rama escalar (`value: string`) para
      todos los operadores menos `in`, y rama lista (`value: string[]`) para `in`. `field` es el nombre
      **público** del campo (jamás un path DQL). Ver «Forma recomendada de los tipos».
  - [x] Crear `pwa/src/context/shared/domain/Search/index.ts` (barrel) re-exportando `FilterOperator`
    (valor) y los tipos `Filter` (+ sus ramas si se nombran), siguiendo el idioma de
    `infrastructure/Validation/index.ts` (`export { … }` para valores, `export type { … }` para tipos).
  - [x] `domain/Search/` no importa nada de Next, Inversify, HTTP ni infraestructura (regla `domain/`).

- [x] **Task 2 — Builder `buildSearchParams` en `infrastructure/Search/`** (AC: #2)
  - [x] Crear `pwa/src/context/shared/infrastructure/Search/buildSearchParams.ts` que reciba
    `readonly Filter[]` y devuelva `URLSearchParams` (composable con sort/cursor en la 2.2).
  - [x] Para cada filtro en índice `N` contiguo desde 0: emitir `filters[N][field]` y
    `filters[N][operator]`; si el operador es `in` (valor lista), emitir un `filters[N][value][]` **por
    cada** item; en caso contrario, un único `filters[N][value]`.
  - [x] Lista vacía → `URLSearchParams` sin entradas (`params.toString() === ''`).
  - [x] Crear `pwa/src/context/shared/infrastructure/Search/index.ts` (barrel) exportando
    `buildSearchParams`, por paridad con los siblings de `infrastructure/` (Validation, Observability, …).

- [x] **Task 3 — Tests Vitest (red → green)** (AC: #3)
  - [x] Escribir primero `pwa/tests/context/shared/infrastructure/Search/buildSearchParams.test.ts` y
    verlo fallar antes de implementar el builder.
  - [x] Casos: (a) escalar `eq`, (b) escalar `contains`, (c) lista `in` con ≥2 valores
    (`getAll('filters[0][value][]')` devuelve la lista), (d) ≥2 filtros combinados con índices 0 y 1,
    (e) lista vacía → sin params. Aserciones sobre el objeto `URLSearchParams` (`get` / `getAll`), que
    opera con claves decodificadas.

- [x] **Task 4 — Gates de cierre** (AC: #1, #2, #3)
  - [x] `make pwa.test.unit c='tests/context/shared/infrastructure/Search/buildSearchParams.test.ts'` en verde.
  - [x] `make pwa.quality` (ESLint + Prettier) en verde sobre los ficheros nuevos.

## Dev Notes

### Contrato wire (fuente de verdad: Épica 1, ya en esta rama)

La gramática que `buildSearchParams` debe producir es la que el backend ya valida y aplica:

- **Decisiones D1/D2** (`_bmad-output/planning-artifacts/epics.md`): claves
  `filters[N][field]` / `filters[N][operator]` / `filters[N][value]` (escalar) o `filters[N][value][]`
  (lista); índices contiguos desde 0; tokens de operador en **minúsculas** = backing string del enum PHP.
- **Enum PHP** (`api/src/Shared/Domain/Search/FilterOperator.php`): tiene **7** casos
  (`eq`, `in`, `contains`, `gt`, `gte`, `lt`, `lte`). El espejo TS expone **solo 5**.
- **DTO PHP** (`api/src/Shared/Application/Http/Search/FilterQuery.php`): confirma la forma del `value` por
  operador → **escalar** (string) para `eq`/`contains`/`gt`/`gte`/`lt`/`lte`; **lista no vacía** de strings
  para `in`. El `field` es el nombre público; la pertenencia a la allow-list la decide el applier
  server-side (no el cliente).

**Ejemplo concreto de salida** para
`[{field:'name',operator:'contains',value:'banc'}, {field:'shortName',operator:'in',value:['ES','PT']}]`:

```
filters[0][field]=name
filters[0][operator]=contains
filters[0][value]=banc
filters[1][field]=shortName
filters[1][operator]=in
filters[1][value][]=ES
filters[1][value][]=PT
```

(`URLSearchParams.toString()` percent-codifica los corchetes —`%5B`/`%5D`—; el backend los decodifica con
normalidad. Las aserciones de test usan `get`/`getAll`, que trabajan sobre claves decodificadas.)

### Decisión de operadores: 5 en TS frente a 7 en PHP (NO es un bug)

El enum PHP tiene `gt`/`gte`/`lt`/`lte`, pero el espejo TS expone **`eq | in | contains | gte | lte`**:

- **Incluidos:** `gte`/`lte` los necesita banks (rango `createdFrom`/`createdTo`, ver Story 2.2); `contains`
  lo usa banks (name/shortName); `eq`/`in` no tienen aún consumidor en banks pero **el contrato genérico y
  los tests de esta historia los ejercitan** (AC #3 exige `eq`, `contains`, `in`).
- **Excluidos `gt`/`lt`:** sin consumidor PWA y sin test → YAGNI. Es coherente con la doctrina del propio
  enum PHP («add a case only when a real consumer needs it») y con la regla DRY/KISS/YAGNI del proyecto.
  Añadir `gt`/`lt` al union TS más adelante es trivial (una línea) cuando aparezca un consumidor real.
- El `epics.md` describe el union como «en paridad con el enum PHP tras la Story 1.7»: tómalo como
  **paridad de los operadores con consumidor/test**, no paridad 1:1 del conjunto completo.

### Forma recomendada de los tipos (`domain/Search/Filter.ts`)

```ts
export const FilterOperator = {
  Eq: "eq",
  In: "in",
  Contains: "contains",
  Gte: "gte",
  Lte: "lte",
} as const;

export type FilterOperator = (typeof FilterOperator)[keyof typeof FilterOperator];

/** Operadores que portan un único valor escalar (`filters[N][value]`). */
export interface ScalarFilter {
  field: string;
  operator: Exclude<FilterOperator, typeof FilterOperator.In>;
  value: string;
}

/** El operador `in` porta una lista (`filters[N][value][]`). */
export interface ListFilter {
  field: string;
  operator: typeof FilterOperator.In;
  value: string[];
}

export type Filter = ScalarFilter | ListFilter;
```

La union discriminada hace que «escalar ⇒ string» y «`in` ⇒ string[]» sea correcto por construcción
(espejo del polimorfismo D5 del DTO PHP) y deja que el builder estreche el tipo del `value` sin casts.
Si prefieres un único `interface Filter { …; value: string | string[] }`, es aceptable pero pierde esa
seguridad y obliga a `Array.isArray` defensivo en el builder; la union discriminada es la recomendada.

### Forma recomendada del builder (`infrastructure/Search/buildSearchParams.ts`)

```ts
import type { Filter } from "@/context/shared/domain/Search";

export function buildSearchParams(filters: readonly Filter[]): URLSearchParams {
  const params = new URLSearchParams();

  filters.forEach((filter, index) => {
    const prefix = `filters[${index}]`;
    params.append(`${prefix}[field]`, filter.field);
    params.append(`${prefix}[operator]`, filter.operator);

    if (Array.isArray(filter.value)) {
      filter.value.forEach((item) => params.append(`${prefix}[value][]`, item));
    } else {
      params.append(`${prefix}[value]`, filter.value);
    }
  });

  return params;
}
```

- **Devuelve `URLSearchParams`** (no string): la Story 2.2 lo compondrá con otros params (cursor, sort,
  direction) y lo pasará al `HttpClient`. Mantén el builder enfocado SOLO en filtros (sort/paginación
  quedan fuera de su responsabilidad — son parámetros distintos del contrato, ver epics).
- **No valida**: serializa lo que recibe. Mapear campos de UI vacíos → `Filter[]` (con `.trim()`) es
  responsabilidad del llamante (Story 2.2). Esto refleja el diseño PHP (validación de shape en mapping,
  semántica en applier) y mantiene el builder puro y testeable.
- `Array.isArray(filter.value)` estrecha a `ListFilter`/`ScalarFilter` con la union discriminada; evita
  importar el valor `FilterOperator` solo para comparar.

### Lo que consumirá la Story 2.2 (diseña la API pensando en esto)

- `banksFilterSort.ts` (`src/app/backoffice/banks/_lib/`) filtra hoy **client-side**:
  `name`→`contains`, `shortName`→`contains`, `createdFrom`→`gte`(createdAt), `createdTo`→`lte`(createdAt).
  La 2.2 mapeará `BanksFilter` → `Filter[]` y los serializará con este builder hacia
  `GET /api/v1/backoffice/banks`.
- `BankRepository.search()` (`context/backoffice/bank/domain/BankRepository.ts`) hoy no recibe argumentos;
  la 2.2 cambiará su firma para aceptar filtros y serializarlos en `ApiBankRepository`. **No** cambies esa
  interfaz en esta historia — aquí solo se crea el vocabulario/builder reutilizable.

### Convenciones de código (obligatorias)

- **TS 6 strict**, sin `any` ni `@ts-ignore`. `target: ES2017` (`forEach`, arrow, template literals OK).
- **Solo named exports** bajo `src/context/**` (sin default export).
- **No `enum` de TS** — union + const (idioma de `sorting.ts`). Mismo patrón ya usado por `SortDirection`,
  `NotificationLevel`, `Theme`, `NodeEnv`.
- Subdirectorio **PascalCase** (`Search/`) con `index.ts` barrel — como `infrastructure/Validation/`,
  `Observability/`, `DateTimeProvider/`.
- Importa con el alias `@/` (no `../../../`).
- Sin comentarios decorativos ni de narración de linters; solo el «why» no obvio.

### Testing

- Vitest 4 (`pwa/vitest.config.ts`); tests en `pwa/tests/**` espejando `src/`. Patrón AAA, una conducta
  por test, nombres por comportamiento.
- Ejecuta un solo fichero: `make pwa.test.unit c='tests/context/shared/infrastructure/Search/buildSearchParams.test.ts'`.
- Red-green: el test va primero y falla antes de existir el builder.

### Project Structure Notes

- Rutas alineadas con la estructura PWA (`context/<bc>/{domain,infrastructure}`): `domain/Search/` para el
  vocabulario puro, `infrastructure/Search/` para el adaptador de serialización. Sin conflictos con la
  estructura existente; `Search/` no existe aún en `context/shared` (confirmado).
- Variación menor frente al `epics.md`: el AC #2 nombra solo `buildSearchParams.ts`; se añade además su
  `index.ts` barrel por paridad con todos los siblings de `infrastructure/` (import limpio para la 2.2 vía
  `@/context/shared/infrastructure/Search`). Sin impacto funcional.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 2.1] — ACs, gramática D1/D2, decisión de operadores.
- [Source: api/src/Shared/Domain/Search/FilterOperator.php] — enum PHP (7 casos; backing string = wire).
- [Source: api/src/Shared/Application/Http/Search/FilterQuery.php] — forma del `value` por operador (D5).
- [Source: pwa/src/context/shared/domain/types/sorting.ts] — idioma union + const a replicar.
- [Source: pwa/src/context/shared/infrastructure/Validation/index.ts] — idioma del barrel.
- [Source: pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts] — filtros client-side actuales (consumidor 2.2).
- [Source: pwa/src/context/backoffice/bank/domain/BankRepository.ts] — firma `search()` que cambiará en 2.2.
- [Source: docs/project-context.md] — reglas TS/PWA (named exports, no enum, strict, barrels).
- [Source: pwa/CLAUDE.md#Where shared code goes] — `context/shared/{domain,infrastructure}` para código port-backed.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context)

### Debug Log References

- `make pwa.test.unit c='tests/context/shared/infrastructure/Search/buildSearchParams.test.ts'` → 5/5 en verde.
- `make pwa.quality` (ESLint + Prettier) → EXIT 0, sin errores ni warnings.
- `tsc --noEmit` (TS 6 strict, en el contenedor pwa del worktree) → EXIT 0, sin errores de tipo.

### Completion Notes List

- Vocabulario `Filter`/`FilterOperator` creado en `context/shared/domain/Search/` como **union discriminada**
  por operador (escalar `string` / `in` lista `string[]`), espejo del polimorfismo D5 del DTO PHP.
- `FilterOperator` como **const object + union derivada** (idioma de `sorting.ts`), **no** `enum` TS. Expone
  5 operadores (`eq | in | contains | gte | lte`); `gt`/`lt` omitidos por YAGNI (sin consumidor/test PWA),
  coherente con la doctrina del enum PHP.
- `buildSearchParams` en `context/shared/infrastructure/Search/` serializa a la gramática wire D1/D2 sobre
  `URLSearchParams` (composable con sort/cursor en la 2.2). Lista vacía → sin params. No valida (puro).
- Barrels `index.ts` añadidos en domain y infrastructure por paridad con los siblings (import limpio
  `@/context/shared/{domain,infrastructure}/Search` para la 2.2).
- Sin cambios de UI/red/PHP. La interfaz `BankRepository.search()` NO se tocó (es alcance de la Story 2.2).

### File List

- `pwa/src/context/shared/domain/Search/Filter.ts` (nuevo)
- `pwa/src/context/shared/domain/Search/index.ts` (nuevo)
- `pwa/src/context/shared/infrastructure/Search/buildSearchParams.ts` (nuevo)
- `pwa/src/context/shared/infrastructure/Search/index.ts` (nuevo)
- `pwa/tests/context/shared/infrastructure/Search/buildSearchParams.test.ts` (nuevo)

## Change Log

| Fecha      | Versión | Descripción                                                    | Autor  |
|------------|---------|----------------------------------------------------------------|--------|
| 2026-06-08 | 0.1     | Historia creada (create-story)                                 | Sergio |
| 2026-06-08 | 1.0     | Implementación: vocabulario + builder + tests; lista para review | Amelia (dev) |
