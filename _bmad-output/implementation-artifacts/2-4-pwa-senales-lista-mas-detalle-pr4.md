---
baseline_commit: 6d38408f311d083862cf5754d4eec98606839163
---

# Story 2.4: PWA · Señales de contador en lista y detalle (PR4)

Status: review

## Story

As a usuario del backoffice,
I want ver el número de cuentas asociadas en la tabla de bancos y en la vista de detalle de cada banco,
so that tenga visibilidad inmediata de las dependencias antes de proceder con borrado u otras acciones.

## Acceptance Criteria

1. **Given** la lista de bancos, **When** se renderiza la tabla, **Then** se muestra la columna "ACCOUNTS" con el valor `accountCount` de cada banco, posicionada tras "Status", de ~72px de ancho, oculta por debajo de `lg` (misma regla que "Updated"/"Created").
2. **Given** la lista de bancos, **When** `accountCount > 0`, **Then** el recuento es un enlace clickable que navega a `/backoffice/banks/{id}/accounts` (superficie 2.3); si `accountCount === 0`, aparece atenuado y no enlaza (invariante #1: la señal no es autoritativa, no bloquea `DELETE`).
3. **Given** la vista de detalle de un banco (`/backoffice/banks/{id}`), **When** se renderiza la sección de metadatos, **Then** aparece un campo "Associated accounts" que muestra "N · View accounts" (enlace a `/backoffice/banks/{id}/accounts`) cuando `N > 0`, o "None" en `text-muted` cuando `N === 0`.
4. **Given** el dominio PWA, **When** se hidrata el modelo `Bank`, **Then** `accountCount` es un campo `number` nunca `null` (el API garantiza ≥ 0 para cualquier banco).

## Tasks / Subtasks

- [x] **T1** — Ampliar el tipo dominio `Bank` con `accountCount` (AC: #4)
  - [x] T1.1 — Añadir `accountCount: number` a `BankPrimitives` e inicializarlo en `Bank.fromPrimitives`
  - [x] T1.2 — Ampliar el constructor de `Bank` con `accountCount: number`
  - [x] T1.3 — Ampliar los type-guards `isBankPrimitives` y `isBankSingleResponse` en `ApiBankRepository.ts` para validar `accountCount` como `number`

- [x] **T2** — Añadir la ruta `accounts` a `bankRoutes.ts` (prerequisito de T3 y T4) (AC: #2, #3)
  - [x] T2.1 — Añadir `accounts: (id: string): string` como `${BANKS_BASE}/${encodeURIComponent(id)}/accounts`

- [x] **T3** — Columna ACCOUNTS en `BanksTable.tsx` (AC: #1, #2)
  - [x] T3.1 — Añadir columna `accounts` tras "Status", antes de "Updated": `colClassName: "w-[72px] max-lg:hidden"`, `className: "hidden lg:table-cell"`, `align: "right"`, header: `"ACCOUNTS"`, **no** `sortable`
  - [x] T3.2 — Añadir `import Link from "next/link"` al fichero (`BanksTable.tsx` actualmente NO lo importa — solo importa `useRouter` de `next/navigation`)
  - [x] T3.3 — Render cell: `N > 0` → `<Link href={safeHref(bankRoutes.accounts(row.id))} className="text-[var(--erpify-brand)] tabular-nums text-xs hover:underline" data-testid={`banks-table__accounts-${row.id}`}>N</Link>`; `N === 0` → `<span className="text-muted-foreground tabular-nums text-xs" data-testid={`banks-table__accounts-${row.id}`}>0</span>`
  - [x] T3.4 — Asegurar que `safeHref` envuelve el href y que `encodeURIComponent` ya está aplicado por `bankRoutes.accounts`

- [x] **T4** — Campo "Associated accounts" en la página de detalle `[id]/page.tsx` (AC: #3)
  - [x] T4.1 — Añadir campo en el `<dl>` bajo el bloque de metadatos, ocupando `sm:col-span-2`
  - [x] T4.2 — Render: `bank.accountCount > 0` → `"{N} · "` + `<Link href={safeHref(bankRoutes.accounts(bank.id))} ...>View accounts</Link>` (text-brand); `0` → `<span className="text-muted-foreground">None</span>`
  - [x] T4.3 — Añadir `data-testid="banks-detail__field-accounts"` al `<dd>`

- [x] **T5** — Tests Vitest (AC: todos)
  - [x] T5.1 — Unitario `Bank.fromPrimitives` con `accountCount` presente (`3`) y `0` (ambos `number`, nunca undefined)
  - [x] T5.2 — Unitario `BanksTable`: renderiza columna ACCOUNTS; `N > 0` → `<a>` con href; `N === 0` → `<span>` sin `href`
  - [x] T5.3 — Unitario `BankDetailPage` (renderiza campo "Associated accounts" con link cuando `N > 0`, "None" cuando `0`)

- [x] **T6** — `make pwa.quality` — ESLint + Prettier verdes (obligatorio antes de declarar done)

## Dev Notes

### Contexto de esta story

Story 2.4 es el **lado PWA de la feature `accountCount`** cuyo API ya está mergeado en esta rama (PR #248: `BankSearcher`, `BankDetailFinder`, `AccountCountsByBank`). El API entrega `accountCount` como entero en el payload de lista y detalle. Esta story solo consume ese campo — cero cambios en API o backend.

**Dependencias:**
- **PR1 (Story 2.1) ya mergeado en esta rama.** El API devuelve `accountCount: int` en lista (`GET /banks`) y detalle (`GET /banks/{id}`). El campo está en el grupo de serialización `GROUP_ACCOUNT_COUNT` — solo aparece en esos dos endpoints de lectura, **no** en POST/PUT.
- **Story 2.3 (ruta `/backoffice/banks/{id}/accounts`)** no existe aún en esta rama. La ruta de destino del enlace (`bankRoutes.accounts(id)`) se registra en esta story; el fichero de página (`app/backoffice/banks/[id]/accounts/page.tsx`) lo creará Story 2.3 en una rama separada (PR #249 stacked). El link redirige a 404 hasta que Story 2.3 mergee — comportamiento esperado.

**Invariante #1 (dual-truth — carga desde architecture-bank-associated-accounts.md):** `accountCount > 0` hace el link visible pero **no bloquea `DELETE`**. El guard optimista es Story 2.5. Esta story solo muestra el dato — ninguna lógica de borrado cambia aquí.

**Invariante #4 (stale-tolerance):** `accountCount` refleja el último fetch; no hay Mercure para cuentas en v1. La columna nunca se "auto-actualiza" en la tabla — es estático hasta el siguiente fetch.

### Archivos a modificar

| Archivo | Operación | Notas |
|---|---|---|
| `pwa/src/context/backoffice/bank/domain/Bank.ts` | MODIFICADO | `BankPrimitives` + `Bank` class + `fromPrimitives` |
| `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts` | MODIFICADO | `isBankPrimitives` + validación `accountCount` |
| `pwa/src/app/backoffice/banks/_lib/bankRoutes.ts` | MODIFICADO | añadir `accounts: (id) => …` |
| `pwa/src/app/backoffice/banks/_components/BanksTable.tsx` | MODIFICADO | nueva columna ACCOUNTS |
| `pwa/src/app/backoffice/banks/[id]/page.tsx` | MODIFICADO | nuevo campo "Associated accounts" en dl/dd |

**No tocar:**
- `BanksCards.tsx` — el diseño no especifica `accountCount` en la vista de tarjetas (la señal vive en la tabla y el detalle).
- `BankRepository.ts` (dominio) — la interfaz de búsqueda no cambia.
- `SearchBanks.ts`, `FindBank.ts` — use cases de aplicación, sin cambios.
- Nada en `api/` — esta story es solo PWA.

### Estado actual de los ficheros (lo que vas a modificar)

**`Bank.ts`** — solo tiene 5 campos: `id`, `name`, `shortName`, `createdAt`, `updatedAt`. Constructor y `fromPrimitives` deben ganar `accountCount: number`.

**`ApiBankRepository.ts`** — `isBankPrimitives` valida los 5 campos con `typeof ... === "string"`. Añadir `typeof value.accountCount === "number"`. La respuesta del servidor ya lo envía; sin esta validación el type-guard rechazaría el payload entero en tiempo de ejecución.

**`bankRoutes.ts`** — actualmente tiene `list`, `new`, `detail(id)`, `edit(id)`. Añadir `accounts(id)` siguiendo el mismo patrón: `${BANKS_BASE}/${encodeURIComponent(id)}/accounts`.

**`BanksTable.tsx`** — construye columnas en `buildBanksColumns(...)`. El orden actual: Code → Name → Status → Updated (`hidden lg`) → Created (`hidden xl`) → Actions. Insertar ACCOUNTS entre Status y Updated. La columna no se pasa por `onBankDeleteFailed`/`onBankDeleted` (es solo de lectura); puede salir fuera de `buildBanksColumns` como constante o dentro. **Importante: el fichero NO importa `Link` de `next/link` actualmente** — añadir el import. Para la columna ACCOUNTS, el row completo es el argumento del cell render (el `Bank` object, que ya tiene `accountCount`).

**`[id]/page.tsx`** — la sección `<dl>` tiene: Name, Code, Created, Updated, Identifier (span-2). Insertar "Associated accounts" antes o después de "Identifier" (preferible antes, como metadato funcional antes del técnico). El componente local `Field` recibe `label`, `value`, `valueClassName`, `valueTitle`, `icon`, `testId`. Para este campo necesitas contenido mixto (texto + link), así que **no** uses `Field` — escribe el `<div>` directamente con `<dt>` y `<dd>` personalizados (o extiende `Field` con un `children` prop si lo ves limpio, pero el fichero ya tiene el patrón inline para `Identifier`). La pattern más simple: un `<div className="banks-detail__field sm:col-span-2">` (o sin span-2 si el contador es una fila normal de un ítem) con `<dt>` y `<dd>` customizados.

### Reglas de diseño (DESIGN.md / UX)

- **Columna ACCOUNTS (tabla):**  
  - Ancho: `w-[72px]`, alineación: derecha.  
  - `N > 0`: texto `text-xs tabular-nums` + color `text-[var(--erpify-brand)]` en reposo (se convierte en enlace); hover/focus: underline del link.  
  - `N === 0`: `text-muted-foreground text-xs tabular-nums`, no interactivo.  
  - Header: `"ACCOUNTS"`, no ordenable.  
  - Breakpoint oculto: `hidden lg:table-cell` (igual que Updated/Created).

- **Campo "Associated accounts" (detalle):**  
  - Label: `"Associated accounts"` (igual que otros campos en `<dt>`).  
  - `N > 0`: `{N} · <Link>View accounts</Link>` (brand color). Usar `Link` de `next/link`.  
  - `N === 0`: `<span className="text-muted-foreground">None</span>`.  
  - El campo ocupa `sm:col-span-2` como "Identifier" (es una línea completa).

- **safeHref obligatorio** en todo `href` dinámico (`bankRoutes.accounts(id)` ya aplica `encodeURIComponent`; envolver igualmente con `safeHref` en el JSX).

- **BEM naming:** `banks-table__col--accounts` para la columna (a efectos de CSS targeting futuro).

### Reglas de testing

- **Vitest** es el único runner disponible localmente (no hay Playwright local por el blocker de ownership del worktree — ver memoria `pwa-e2e-local-ownership-blocker.md`).
- Tests unitarios en `pwa/tests/` mirroring `pwa/src/`.
- Usar `@testing-library/react` + `@testing-library/jest-dom`.
- Para `BanksTable` y `BankDetailPage` renderiza componentes reales; mock de `router` con `vi.mock("next/navigation")`.
- No snapshots para lógica de negocio. Consultar `getByRole("link")` para verificar el enlace en `N > 0`; `queryByRole("link")` devuelve `null` en `N === 0`.

### Learnings de Story 2.1 (spec anterior)

- El campo transitorio `accountCount` en la entidad PHP usa `GROUP_ACCOUNT_COUNT` (no `GROUP_DETAIL`) para no contaminar las respuestas POST/PUT. En la PWA esto es transparente: el adapter solo ve el JSON de los endpoints de lectura, donde `accountCount` siempre está presente.
- La spec 2.1 detectó que `BankDetailFinder` (nuevo) compone `BankFinder` + `AccountCountsByBank` en lugar de modificar `BankFinder` directamente — el write-path queda puro. En la PWA esto solo impacta en que `GET /banks/{id}` devuelve `accountCount` (ya comprobado en Behat). El adapter no necesita saber qué finder se usó en el servidor.
- Behat verifica anti-N+1 (31 bancos = 2 queries). La PWA confía en ese contrato; no replica el test.

### Seguridad (checklist CLAUDE.md)

- **XSS:** `bankRoutes.accounts(id)` aplica `encodeURIComponent` internamente. Envolver con `safeHref(...)` en el JSX (regla absoluta del CLAUDE.md). No interpolar `id` directamente en template literal de `href`.
- **CSRF / Open redirect:** los href apuntan a rutas locales (`/backoffice/banks/...`); no hay redirección externa.
- **PII:** `accountCount` es un entero; no es PII. No se toca IBAN en esta story.
- **Storage / Clipboard:** no aplica.
- **Headers/CSP:** sin cambios.
- **Dependencias:** sin paquetes nuevos.

### Referencias

- Arquitectura del Epic 2: [`_bmad-output/planning-artifacts/architecture-bank-associated-accounts.md`](../../_bmad-output/planning-artifacts/architecture-bank-associated-accounts.md) — PR4 spec (§Implementation Decomposition)
- Spec story previa 2.1: [`_bmad-output/implementation-artifacts/spec-2-1-api-account-count-read-model.md`](spec-2-1-api-account-count-read-model.md)
- Epics: [`pr213-docs` worktree `_bmad-output/planning-artifacts/epics.md`] — Story 2.4 (§Story 2.4: PWA · Señales lista + detalle)
- UX DESIGN.md: [`_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-10/DESIGN.md`](../../_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-10/DESIGN.md) — components: `account-count-cell`, `associated-accounts-field`
- Reglas PWA: `pwa/CLAUDE.md` §XSS prevention, §Shared building blocks
- `pwa/src/context/backoffice/bank/domain/Bank.ts` — type a modificar
- `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts` — type-guards a ampliar
- `pwa/src/app/backoffice/banks/_lib/bankRoutes.ts` — rutas de navegación
- `pwa/src/app/backoffice/banks/_components/BanksTable.tsx` — tabla a ampliar
- `pwa/src/app/backoffice/banks/[id]/page.tsx` — detalle a ampliar

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- T1: `accountCount: number` añadido a `BankPrimitives`, constructor de `Bank` y `Bank.fromPrimitives`. Type-guard `isBankPrimitives` extendido con `typeof value.accountCount === "number"`.
- T2: `accounts(id)` añadido a `bankRoutes` con `encodeURIComponent` aplicado internamente.
- T3: Columna ACCOUNTS insertada en `BanksTable.tsx` entre Status y Updated (`w-[72px] max-lg:hidden`, `align: right`, no sortable). Import `Link` de `next/link` añadido. Render: link brand-colored cuando N>0, span muted cuando N=0. `safeHref` aplicado en ambos casos.
- T4: Campo "Associated accounts" añadido en el `<dl>` de la página de detalle antes de "Identifier", con `sm:col-span-2`. Render: `N · View accounts` (link) cuando N>0, "None" (muted) cuando N=0. `data-testid="banks-detail__field-accounts"` en el `<dd>`.
- T5: 6 tests unitarios nuevos en `tests/context/backoffice/bank/domain/Bank.test.ts` (2) y `tests/app/backoffice/banks/banksAccountCount.test.tsx` (4). Todos los tests existentes actualizados para incluir `accountCount` en los fixtures de `Bank.fromPrimitives`. Suite completa: 102 ficheros / 579 tests — todos verdes.
- T6: `make pwa.quality` pasa con exit 0 (ESLint, Prettier, TypeScript strict).

### File List

**Modificados:**
- `pwa/src/context/backoffice/bank/domain/Bank.ts`
- `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts`
- `pwa/src/app/backoffice/banks/_lib/bankRoutes.ts`
- `pwa/src/app/backoffice/banks/_components/BanksTable.tsx`
- `pwa/src/app/backoffice/banks/[id]/page.tsx`
- `pwa/tests/app/backoffice/banks/_fixtures.ts`
- `pwa/tests/app/backoffice/banks/banksTableIdentity.test.tsx`
- `pwa/tests/app/backoffice/banks/bankDetailRedesign.test.tsx`
- `pwa/tests/app/backoffice/banks/banksCardsIdentity.test.tsx`
- `pwa/tests/app/backoffice/banks/banksCardsLayout.test.tsx`
- `pwa/tests/app/backoffice/banks/bankTruncationTooltips.test.tsx`
- `pwa/tests/app/backoffice/banks/banksStackedList.test.tsx`
- `pwa/tests/app/backoffice/banks/banksPaginationEmptyRecovery.test.tsx`
- `pwa/tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts`
- `pwa/tests/context/backoffice/bank/infrastructure/ApiBankSearchNavigator.test.ts`
- `pwa/tests/context/backoffice/bank/infrastructure/bankRealtime.test.ts`

**Creados:**
- `pwa/tests/context/backoffice/bank/domain/Bank.test.ts`
- `pwa/tests/app/backoffice/banks/banksAccountCount.test.tsx`

## Change Log

- 2026-06-13: Implementación completa de Story 2.4 — columna ACCOUNTS en tabla de bancos, campo "Associated accounts" en detalle, dominio `Bank` extendido con `accountCount`, ruta `bankRoutes.accounts(id)`, type-guard actualizado, 6 tests nuevos, fixtures de tests existentes actualizados. Estado: review.
