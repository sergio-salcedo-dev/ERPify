---
baseline_commit: 15319aa628bafa4f8c5deedfc54f29547276fd72
---

# Story 2.3: PWA · Read context BankAccount + superficie de cuentas

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a usuario del backoffice,
I want una pantalla en la ruta `/backoffice/banks/{id}/accounts` para visualizar la lista de cuentas de un banco (Holder, IBAN enmascarado/desenmascarado, Alias, Currency, Status),
so that pueda verificar las cuentas asociadas antes de una acción de borrado o gestión.

## Acceptance Criteria

1. **Tabla de cuentas.** Al navegar a `/backoffice/banks/{id}/accounts` se muestra una tabla (reutilizando `DataTable` base) con columnas en este orden: **Holder** (flex, truncate+tooltip), **IBAN** (enmascarado por defecto + toggle revelar + `CopyButton`), **Alias**, **Currency**, **Status** (`StatusBadge`). Cabecera sticky; las filas **no** navegan (v1).
2. **IBAN — máscara presentacional + reveal + copia.** El IBAN llega íntegro del backend y se enmascara **solo en cliente** con formato `ES•• ···· 1234` (código de país + medio enmascarado + últimos 4), fuente mono. Un toggle (ojo, `aria-pressed`, nombre accesible "Show IBAN" / "Hide IBAN", Enter/Space, target ≥40px, foco visible) revela el valor íntegro momentáneamente; se re-oculta solo a los ~10 s o al perder foco/hover. El `CopyButton` copia **siempre** el IBAN íntegro, independientemente del estado de revelado. El valor del IBAN **nunca** se escribe en logs/consola/storage (invariante #3, PII).
3. **Solo puertos de lectura (CE-4).** La capa de datos de la PWA inyecta exclusivamente puertos de lectura (`SearchBankAccounts` / navigator); **cero** capacidad de escritura sobre `BankAccount` se cablea en este read context. Se inyecta la **interfaz** de dominio, no el adaptador concreto.
4. **Un único contrato wire (CE-1).** El adaptador consume el envelope keyset final del Epic 1 — `{ data: [...], pagination: { hasNext, hasPrev, count, links: { next, prev } } }` con params `after`/`before` — y **rechaza** la forma legacy `{ cursor, hasMorePages }`. La paginación se hace **siguiendo `links.next`/`links.prev`** (opacos, server-issued), nunca construyendo cursores en cliente.
5. **Estados de carga / vacío / error.** Carga fría → skeleton de filas; vacío → `EmptyState` "This bank has no associated accounts."; error de fetch → se captura con `AsyncBoundary` y muestra `ProblemDisplay` (variant `panel`) + `CorrelationIdChip` con el `correlation-id` del problema. La `id` de banco malformada se guarda con `isUuid` antes de cualquier fetch.
6. **Seguridad de navegación.** Cualquier `href`/`router.push` con la `id` pasa por `safeHref(...)` + `encodeURIComponent(id)`.
7. **Tests.** Vitest: render de tabla, máscara/reveal/copia del IBAN, mapeo del envelope, rechazo de la forma legacy. Playwright: carga en frío, error boundary, copia del IBAN. (e2e local bloqueado → ver Testing Notes: unit + quality local, e2e en CI.)

## Tasks / Subtasks

- [x] **Task 1 — Read context de dominio `bankaccount` (puertos de lectura)** (AC: #3, #4)
  - [x] Crear `pwa/src/context/backoffice/bankaccount/domain/BankAccount.ts`: `BankAccountPrimitives` (`id, holderName, iban, bic, alias, currency, status`) + clase con `static fromPrimitives()`. Espejo de `bank/domain/Bank.ts`.
  - [x] Crear `pwa/src/context/backoffice/bankaccount/domain/BankAccountRepository.ts`: puerto **solo lectura** (`search(bankId, criteria): Promise<BankAccountSearchPage>`), `BankAccountSearchCriteria` (`{ filters, sort, limit }`), `BankAccountSearchPage` (`{ accounts: BankAccount[] } & PageEnvelope`). **No** declara create/update/delete.
  - [x] Crear `pwa/src/context/backoffice/bankaccount/application/BankAccountSearchNavigator.ts`: puerto `follow(link): Promise<BankAccountSearchPage>`.
  - [x] Escribir test (red) para el mapper/guards antes del adaptador (Task 2).
- [x] **Task 2 — Adaptadores infra + guards de contrato wire** (AC: #4)
  - [x] Crear `pwa/src/context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository.ts`: `@injectable`, `@inject("HttpClient")`; guard `isBankAccountSearchResponse` (valida envelope v2, **rechaza** `{cursor,hasMorePages}`), mapper `toBankAccountSearchPage`. `search()` envía `filters`/`sort`/`limit` (clamp a `WIRE_MAX_LIMIT`=100), **nunca** un cursor.
  - [x] Crear `pwa/src/context/backoffice/bankaccount/infrastructure/ApiBankAccountSearchNavigator.ts`: copia `ApiBankSearchNavigator` (guard `assertSameOriginRelative`, fetch del link verbatim, comparte guard/mapper con el repo).
  - [x] Añadir endpoint en `pwa/src/context/shared/infrastructure/api/ApiEndpoints.ts`: `BACKOFFICE.BANKS.ACCOUNTS(bankId)` → `/api/v1/backoffice/banks/{id}/accounts` (id `encodeURIComponent`-d).
  - [x] Verde: tests de mapper/guards pasan (envelope v2 mapea; legacy rechazado por el guard → `malformed-response-envelope` en el HttpClient).
- [x] **Task 3 — Use case de lectura** (AC: #3)
  - [x] Crear `pwa/src/context/backoffice/bankaccount/application/SearchBankAccounts.ts`: `@inject("BackOfficeBankAccountRepository")` la **interfaz**; método `run(bankId, criteria)`.
  - [x] Test unit del use case con repo fake.
- [x] **Task 4 — Binding Inversify (solo lectura)** (AC: #3)
  - [x] En `Container.ts`: `"BackOfficeBankAccountRepository"` → `ApiBankAccountRepository` (singleton), `"BackOfficeBankAccountSearchNavigator"` → `ApiBankAccountSearchNavigator` (singleton), `"BackOfficeSearchBankAccounts"` → `SearchBankAccounts`. **Cero** use cases de escritura.
- [x] **Task 5 — Componente `IbanCell` (máscara/reveal/copia)** (AC: #2)
  - [x] Crear `IbanCell.tsx`: máscara `ES•• ···· 1234`; mono; toggle ojo (`Eye`/`EyeOff`, ghost, `aria-pressed`, "Show IBAN"/"Hide IBAN", Enter/Space nativo, ≥40px = `size-10`); auto-hide a 10s o on blur/mouseleave; `transition-opacity motion-reduce:transition-none`; `<CopyButton iconOnly>` copia siempre el íntegro. **Reveal controlado por la tabla (`revealedId`): un solo IBAN a la vez + re-máscara al paginar.**
  - [x] Vitest: máscara por defecto; reveal; auto-hide; copia íntegra en ambos estados; `aria-pressed`; nunca emite el IBAN a `console`.
- [x] **Task 6 — Tabla `BankAccountsTable`** (AC: #1)
  - [x] Crear `BankAccountsTable.tsx`: `<DataTable>` (layout fixed) columnas Holder/IBAN/Alias/Currency/Status; `rowKey`/`rowTestId` por UUID; `caption` sr-only; **sin** `onRowActivate`. Holder `<TruncatedText>`; IBAN `<IbanCell>` (dueño de `revealedId`, reset al cambiar `accounts`); Status `<StatusBadge>`; Alias `—` si `null`.
  - [x] Vitest: 5 columnas, orden, `null` alias, `rowTestId` por UUID.
- [x] **Task 7 — Paginación `BankAccountsPagination`** (AC: #4)
  - [x] Crear `BankAccountsPagination.tsx`: patrón `BanksPagination` (prev/next siempre renderizado, `disabled` cuando link `null` — D-A11y).
  - [x] `_lib/paginate.ts`: `BANK_ACCOUNTS_PAGE_SIZE_DEFAULT`/`_OPTIONS` (≤ `WIRE_MAX_LIMIT`, test lo fija).
- [x] **Task 8 — Ruta `/backoffice/banks/[id]/accounts/page.tsx`** (AC: #1, #4, #5, #6)
  - [x] Crear `page.tsx` como `"use client"`. `useParams<{id}>()`; guard `isUuid(id)` antes de fetch (id malformada → problema sintético `invalid-uuid`, sin red).
  - [x] Máquina de estado read-only: `activeLink` (null=criteria, no-null=replay navigator), `seqRef`, `navigateTo(link)`; reset de página al cambiar `pageSize`; re-máscara del IBAN gestionada por la tabla al cambiar `accounts`.
  - [x] Fetch vía `container.get(...)`; catch `HttpError` → `setProblem(err.problem)` con `genericProblem(...)` de fallback.
  - [x] Render: `<AsyncBoundary>` envolviendo tabla + paginación. Empty: "This bank has no associated accounts.". Error: `ProblemDisplay` + `CorrelationIdChip` (vía AsyncBoundary).
  - [x] Navegación (back link) con `safeHref` + `encodeURIComponent`.
- [x] **Task 9 — Tests e2e Playwright** (AC: #7)
  - [x] `tests/e2e/backoffice/bank-accounts.spec.ts` (route-mock): carga en frío + tabla; reveal + copia del IBAN; empty; error boundary (500 → `ProblemDisplay`+`CorrelationIdChip`). Local bloqueado por browsers → se ejecuta en CI.
- [x] **Task 10 — Quality gate** (AC: todos)
  - [x] `make pwa.quality` limpio (ESLint + Prettier + typecheck). Auto-review de seguridad frontend (XSS/safeHref/clipboard/PII) del diff: sin hallazgos.

## Review Findings

_Code review 2026-06-13 (3 capas: Blind Hunter, Edge Case Hunter, Acceptance Auditor). 4 patch · 0 decision · 1 defer · 12 descartados como ruido._

- [x] [Review][Patch] `maskIban` filtra el IBAN íntegro con entradas cortas/malformadas — `slice(0,2)`+`slice(-4)` se solapan para len ≤ 6 (`maskIban("ES12")` → `"ES•• ···· ES12"`); el guard `isBankAccountPrimitives` valida `typeof iban === "string"` pero no longitud. Romper el invariante #3 con input no confiable. Fix: guardar longitud mínima y enmascarar por completo si es demasiado corto. [pwa/src/app/backoffice/banks/[id]/accounts/_components/IbanCell.tsx:489-491]
- [x] [Review][Patch] El timer de auto-hide (~10s) se reinicia en cada re-render de un ancestro — `onReveal`/`onHide` son closures nuevos por render en `BankAccountsTable`, y el `useEffect` de `IbanCell` depende de `[revealed, onHide]`, así que cualquier re-render de la tabla con un IBAN revelado reinicia la cuenta de 10s (PII en pantalla más de lo que el spec garantiza). Fix: `useCallback` en los callbacks de la tabla o hide estable por ref. [pwa/src/app/backoffice/banks/[id]/accounts/_components/BankAccountsTable.tsx:409-411 + IbanCell.tsx:503-507]
- [x] [Review][Patch] La carga en frío usa el `DefaultLoadingSkeleton` genérico, no un skeleton de filas — diverge del spec (Task 8 "skeleton de filas") y del patrón establecido en `banks/page.tsx`, que pasa `loading={<BanksListSkeleton rows=.../>}`. Fix: pasar un slot `loading` con skeleton de filas al `AsyncBoundary`. [pwa/src/app/backoffice/banks/[id]/accounts/page.tsx (AsyncBoundary)]
- [x] [Review][Patch] Faltan tests para dos ítems explícitos de los AC: activación por teclado Enter/Space del toggle de revelado (AC#2) y aserción de que el IBAN nunca se escribe en storage (invariante #3, solo se testea `console`). Fix: añadir ambas aserciones. [pwa/tests/app/backoffice/banks/accounts/ibanCell.test.tsx]
- [x] [Review][Defer] El guard de unión cerrada hace fallar la página entera (`malformed-response-envelope`) si el backend añade alguna `currency` ≠ EUR o un 4º `status` — deliberado por CE-1 (un único contrato wire) pero superficie de error frágil: un cambio de contrato versionado tumba la pantalla en vez de degradar por fila. [pwa/src/context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository.ts:988-1002] — deferred, decisión de diseño por CE-1

## Dev Notes

> **El dev agent SOLO tiene este fichero.** Aquí está todo: contrato wire exacto, mapa de reuso, plan de ficheros, y las decisiones no obvias. No reinventes lo que ya existe en la feature `bank/`.

### Contrato API que se consume (congelado por Story 2.2 — verificado contra código vivo)

`GET /api/v1/backoffice/banks/{id}/accounts?after=&before=&limit=&sort=&direction=&paginationMode=` — **público** (no hay auth en el repo hoy; issue #240 abierta). `200` en éxito; errores RFC 9457 `application/problem+json`.

```ts
// Item (entidad BankAccount serializada con grupos identifiable + bankaccount:read)
interface BankAccountPrimitives {
  id: string;            // UUID v7
  holderName: string;    // OJO: la clave es "holderName", NO "holder"
  iban: string;          // ÍNTEGRO, canónico (UPPER, sin espacios) — PII, enmascarar SOLO en cliente
  bic: string | null;
  alias: string | null;
  currency: "EUR";       // ISO 4217 (->value del enum); hoy solo EUR
  status: "active" | "inactive" | "closed";  // label legible, NO el int de respaldo
}
// Envelope (clave de items = "data", NO "items")
interface BankAccountSearchResponse {
  data: BankAccountPrimitives[];
  pagination: {
    hasNext: boolean;
    hasPrev: boolean;
    count: number | null;        // null en paginationMode LIGHT (default)
    links: { next: string | null; prev: string | null };  // URLs relativas opacas — SEGUIRLAS
  };
}
// Problem (errores)
interface ProblemDetails {
  type: string;        // "invalid-uuid" (400) | "bank-not-found" (404) | "validation-failed" (422)
  title: string;
  status: number;
  detail?: string;     // normalmente ausente
  instance: string;
  "correlation-id": string;   // OJO: clave con guion, no "correlationId"
  // extensiones: bankId (404), violations:{field,message,code}[] (422), debug (solo dev/test)
}
```

**Gotchas que rompen integraciones si se ignoran:**
- La clave del item es **`holderName`** (no `holder`, aunque el epic lo abrevie así).
- El envelope envuelve items bajo **`data`** (no `items`).
- `status` es **label string** (`"active"`), no número.
- `iban` llega **sin enmascarar** — enmascarar en UI; **nunca** loggear.
- Paginar **siguiendo `pagination.links.next`/`.prev`** (pueden ser `null`); no construir cursores.
- El campo de correlación es **`"correlation-id"`** (con guion) → `problem["correlation-id"]`.

### Server vs Client component (DECISIÓN — conflicto doc↔código, resuelto)

El doc de arquitectura (`architecture-bank-associated-accounts.md` §PR3) dice *"Server Component, fetch vía DI"*. **El patrón real del repo lo contradice:** todas las páginas de datos del backoffice (`banks/page.tsx`, `banks/[id]/page.tsx`) son **`"use client"`** que obtienen use cases del `container` de Inversify en cliente; no hay fetching RSC. Además la 2.3 requiere interactividad cliente (toggle reveal del IBAN, copia, paginación keyset, auto-hide por timeout). **Decisión: `page.tsx` es client component**, espejo de `banks/page.tsx`. (El "Server Component" del doc no es alcanzable con el patrón DI-en-cliente actual ni con la interactividad requerida; queda registrado para que arquitectura lo reconcilie si procede.)

### Mapa de reuso (NO reinventar — copiar de la feature `bank/`)

| Necesidad | REUSAR (no crear) | Ubicación |
|---|---|---|
| Tabla base | `DataTable` (`columns`, `rowKey`, `rowTestId`, `caption`, `emptyState`) | `pwa/src/components/erpify/DataTable.tsx` (barrel `@/components/erpify`) |
| Texto truncado | `TruncatedText` | `@/components/erpify` |
| Badge de estado | `StatusBadge` | `@/components/erpify` |
| Carga/vacío/error | `AsyncBoundary` (`state`, `data`, `error`, `emptyHeading`, `errorAction`, `children:(data)=>…`) | `pwa/src/components/erpify/AsyncBoundary.tsx` |
| Render de Problem | `ProblemDisplay` (`problem`, `variant="panel"`) | `pwa/src/components/erpify/ProblemDisplay.tsx` |
| Chip correlación | `CorrelationIdChip` (`id`, `label`) — pasar `problem["correlation-id"]` | `pwa/src/components/erpify/CorrelationIdChip.tsx` |
| Copiar valor | `CopyButton` (`iconOnly`, `value`, `label`, `copiedLabel`) | `pwa/src/components/erpify/CopyButton.tsx` |
| HTTP client | `FetchHttpClient` (símbolo `"HttpClient"`), `HttpError` (`.problem`) | `pwa/src/context/shared/infrastructure/HttpClient/` |
| Envelope tipo | `PageEnvelope` (`{hasNext,hasPrev,count,links}`), `WIRE_MAX_LIMIT` | `pwa/src/context/shared/domain/Search/PageEnvelope.ts` |
| Navigator (cursor) | patrón `ApiBankSearchNavigator` (`assertSameOriginRelative` + fetch link verbatim) | `pwa/src/context/backoffice/bank/infrastructure/ApiBankSearchNavigator.ts` |
| Repo + guards + mapper | patrón `ApiBankRepository` (`isBankSearchResponse`, `toBankSearchPage`) | `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts` |
| Use case lectura | patrón `SearchBanks` (`@inject("BackOffice…")` la interfaz) | `pwa/src/context/backoffice/bank/application/SearchBanks.ts` |
| Máquina de página | `activeLink` + `seqRef` + `navigateTo` | `pwa/src/app/backoffice/banks/page.tsx` |
| Paginación UI | `BanksPagination` (siempre render; prev/next `disabled`) | `pwa/src/app/backoffice/banks/_components/BanksPagination.tsx` |
| Guards de id / navegación | `isUuid` (`@/lib/uuidV7`), `safeHref` (`@/lib/safeHref`), `encodeURIComponent` | `@/lib/*` |
| `genericProblem(...)` fallback | minta un Problem sintético en catch | copiar de `banks/page.tsx` |

**NET-NEW (no existe primitiva):** `IbanCell` (máscara/reveal/copia). Es la única pieza de UI verdaderamente nueva — el repo no tiene patrón reveal/mask. CopyButton sí existe y se reusa dentro.

### Layering / boundaries

- Nuevo read context **propio**: `pwa/src/context/backoffice/bankaccount/{domain,application,infrastructure}` — `BankAccount` es dueño de su superficie (no se embebe en la feature `bank/`). Inyectar **interfaz**, no concreto. **Solo puertos de lectura** (CE-4): cero `save()`/mutación cableada.
- Ruta bajo `pwa/src/app/backoffice/banks/[id]/accounts/` con `_components/` y `_lib/` co-localizados (underscore = no es segmento de ruta). No añadir `loading.tsx`/`error.tsx` (los estados los gestiona `AsyncBoundary`, como en `banks/`).
- `Container.ts` es un único `Container` plano con símbolos string (no `ContainerModule`, no `TYPES`). Las read use cases y write use cases se bindan bajo símbolos separados → un consumidor read-only inyecta solo los read.

### Seguridad / PII (invariante #3 — bloqueante)

- IBAN = PII financiera. Enmascarado **solo presentacional** (CSS/JS de UI); el string íntegro solo se pinta al revelar. **Nunca** a `console.log`, `localStorage`/`sessionStorage`, ni a ningún sink de telemetría. La auditoría de acceso ya la hace el backend (2.2) sin el IBAN.
- XSS: ningún `dangerouslySetInnerHTML`; el IBAN se renderiza como texto. `aria-label`/`title` estáticos.
- Navegación: `safeHref` + `encodeURIComponent(id)` en todo `href`/`router.push`.
- Clipboard: solo vía `<CopyButton>` (no escribe HTML).

### Scope fence (qué NO es de 2.3)

- **Fuera:** columna/contador "ACCOUNTS" en lista, clicabilidad y "Associated accounts: N" del detalle → **2.4**. Delete-guard popover + recovery del `409 bank-in-use` → **2.5**. Cualquier escritura/`save()` sobre `BankAccount` (CE-4). Página de detalle de cuenta individual (filas no navegan v1). Mercure/realtime (v1 estático). Backend (todo entregado por 2.1/2.2).
- **Dentro pero es decisión cerrada aquí:** un solo IBAN revelado a la vez + re-enmascarar al paginar (cierra el `[ASSUMPTION]` del UX spine).

### Testing Notes

- **Vitest** (`make pwa.test.unit`): `IbanCell` (máscara default, reveal, auto-hide, copia íntegra ambos estados, `aria-pressed`, no fuga a console), `BankAccountsTable` (5 columnas/orden/nulls/`rowTestId`), guards/mapper del repo (envelope v2 mapea; legacy `{cursor,hasMorePages}` lanza).
- **Playwright** (e2e): carga en frío, error boundary, copia. **Bloqueo local conocido:** no hay browsers Playwright para esta distro → correr unit+quality en local y dejar e2e para CI (ver memoria "PWA local e2e ownership blocker"). Verificación manual de navegador posible con `playwright-cli` + Chrome del sistema.
- `make pwa.quality` al final (REQUERIDO).

### Project Structure Notes

- **NUEVOS:** `pwa/src/context/backoffice/bankaccount/domain/{BankAccount,BankAccountRepository}.ts`, `.../application/{SearchBankAccounts,BankAccountSearchNavigator}.ts`, `.../infrastructure/{ApiBankAccountRepository,ApiBankAccountSearchNavigator}.ts`; `pwa/src/app/backoffice/banks/[id]/accounts/{page.tsx,_components/{IbanCell,BankAccountsTable,BankAccountsPagination}.tsx,_lib/paginate.ts}`.
- **A TOCAR (serial, fichero compartido):** `pwa/src/context/shared/infrastructure/DependencyInjection/Container.ts` (3 bindings read-only), `pwa/src/context/shared/infrastructure/api/ApiEndpoints.ts` (1 endpoint anidado).
- Convención de directorios PWA nuevos → si se documenta, actualizar `docs/architecture-pwa.md` y `pwa/docs/` (boundaries del nuevo context). No tocar `node_modules`.
- Comentarios: sin IDs de story/AC ni "previously…" en el código mergeado (barrer el diff antes del commit final).

### References

- [Epic 2 / Story 2.3](../planning-artifacts/epics.md) — user story + AC fuente.
- [Spec 2.2 (contrato congelado del endpoint)](spec-2-2-api-bank-accounts-endpoint.md) — envelope, IBAN canónico, status label, audit, 400/404.
- [architecture-bank-associated-accounts.md](../planning-artifacts/architecture-bank-associated-accounts.md) — CE-1..CE-4, invariantes #1–#4, clasificación PII del IBAN, layering PR3.
- UX: `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-10/DESIGN.md` (`accounts-table`, `iban-field`) y `EXPERIENCE.md` (IA, estados, microcopy "Show/Hide IBAN", "IBAN copied").
- Código vivo del endpoint (worktree): `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountSearchController.php`, `.../Domain/Entity/BankAccount.php`, `api/src/Shared/Infrastructure/Http/Responder/PaginationMeta.php`.
- Patrón PWA a espejar: `pwa/src/context/backoffice/bank/**`, `pwa/src/app/backoffice/banks/page.tsx`, `pwa/src/components/erpify/{DataTable,AsyncBoundary,ProblemDisplay,CorrelationIdChip,CopyButton}.tsx`.
- Reglas: `docs/rules/frontend.md`, `docs/rules/security.md`, `pwa/CLAUDE.md` (lista XSS/safeHref).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context) — dev-story workflow.

### Debug Log References

- Worktree `bank-accounts-pwa-tjox` (rama `feat/backoffice-bank-accounts-surface-tjox`), sobre los commits de 2.1/2.2 (API). `main` intacto.
- TDD por tarea: tests (red) antes de cada adaptador/componente; verde tras la implementación.
- Gates: `make pwa.test.unit` 602/602 (incluye guards `data-testid-uniqueness` y `next-public-env-allowlist`); `make pwa.quality` (ESLint + Prettier + `tsc --noEmit`) exit 0.
- Único fix de lint durante el desarrollo: re-máscara al paginar reescrita de `useEffect`→ajuste-en-render (evita `react-hooks/set-state-in-effect`, espejo del reset de página en `banks/page.tsx`).

### Completion Notes List

- 29 tests nuevos (8 data-layer + 8 IbanCell + 4 tabla + 4 paginación + 5 page + 4 e2e route-mock). e2e local bloqueado por browsers (ver memoria "PWA local e2e ownership blocker") → corre en CI.
- **CE-4 (read-only):** sólo se cablean puertos de lectura en `Container.ts`; el contexto `bankaccount` no declara ni bindea ninguna escritura.
- **CE-1 (un contrato wire):** `isBankAccountSearchResponse` acepta sólo el envelope v2 y rechaza `{cursor,hasMorePages}`; paginación siguiendo `links` verbatim (guard same-origin/relative).
- **PII / invariante #3:** el IBAN llega íntegro y se enmascara sólo en cliente; revelado controlado (uno a la vez, auto-hide 10s/blur/mouseleave, re-máscara al paginar); copia siempre íntegra vía `<CopyButton>`; test unit verifica que nunca se emite a `console`.
- **Decisión doc↔código:** `page.tsx` es client component (el patrón DI-en-cliente + interactividad lo exige); registrado en Dev Notes para que arquitectura lo reconcilie.
- **Doc de arquitectura:** `docs/architecture-pwa.md` §Bounded contexts es un árbol ilustrativo que ni siquiera lista el contexto `bank` ya enviado, por lo que **no** es un registro vivo por-contexto; no se modifica (el trigger condicional "si se documenta" no aplica). Sin cambios en `next.config.ts`, CSP ni dependencias.
- Seguridad (auto-review del diff): XSS (texto escapado, sin `dangerouslySetInnerHTML`, iconos `aria-hidden`), open-redirect (back link `safeHref`+`encodeURIComponent`; links de paginación validados antes del fetch), clipboard sólo vía `<CopyButton>`, sin storage de PII. Sin hallazgos.

### File List

**Nuevos (`pwa/src`):**
- `context/backoffice/bankaccount/domain/BankAccount.ts`
- `context/backoffice/bankaccount/domain/BankAccountRepository.ts`
- `context/backoffice/bankaccount/application/BankAccountSearchNavigator.ts`
- `context/backoffice/bankaccount/application/SearchBankAccounts.ts`
- `context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository.ts`
- `context/backoffice/bankaccount/infrastructure/ApiBankAccountSearchNavigator.ts`
- `app/backoffice/banks/[id]/accounts/page.tsx`
- `app/backoffice/banks/[id]/accounts/_components/IbanCell.tsx`
- `app/backoffice/banks/[id]/accounts/_components/BankAccountsTable.tsx`
- `app/backoffice/banks/[id]/accounts/_components/BankAccountsPagination.tsx`
- `app/backoffice/banks/[id]/accounts/_lib/paginate.ts`

**Modificados (`pwa/src`):**
- `context/shared/infrastructure/DependencyInjection/Container.ts` (3 bindings read-only)
- `context/shared/infrastructure/api/ApiEndpoints.ts` (endpoint `BANKS.ACCOUNTS`)

**Tests nuevos (`pwa/tests`):**
- `context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository.test.ts`
- `context/backoffice/bankaccount/infrastructure/ApiBankAccountSearchNavigator.test.ts`
- `context/backoffice/bankaccount/application/SearchBankAccounts.test.ts`
- `app/backoffice/banks/accounts/ibanCell.test.tsx`
- `app/backoffice/banks/accounts/bankAccountsTable.test.tsx`
- `app/backoffice/banks/accounts/bankAccountsPagination.test.tsx`
- `app/backoffice/banks/accounts/bankAccountsPage.test.tsx`
- `e2e/backoffice/bank-accounts.spec.ts`

## Change Log

| Fecha | Cambio |
|-------|--------|
| 2026-06-13 | Story creada (create-story). Contrato del endpoint verificado contra código vivo + spec-2-2 congelada. Decisión registrada: client component (conflicto con doc de arquitectura que pide Server Component). Decisión cerrada: un IBAN revelado a la vez + re-máscara al paginar. |
| 2026-06-13 | Implementación completa (dev-story). Contexto read-only `bankaccount` (dominio/aplicación/infra) + superficie `/backoffice/banks/[id]/accounts` (IbanCell, tabla, paginación, page). 29 tests; `pwa.quality` verde. Status → review. |
