---
title: 'Standalone Bank Accounts management section (global list + detail, wires #396)'
type: 'feature'
created: '2026-07-02'
status: 'in-review'
baseline_commit: '351b973fb390e7956b112386d5465310f9134f74'
context:
  - '{project-root}/docs/project-context.md'
  - '{project-root}/docs/architecture/event-catalog.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Las cuentas bancarias solo se ven anidadas bajo un banco (`/banks/{id}/accounts`); no hay hub global. El endpoint global `GET /bank-accounts` (ya existente) está sin consumir, y el topic Mercure de detalle por-cuenta está publicado+autorizado pero sin suscriptor (#396: dead end-to-end). Además, a diferencia de Bank, no existe topic de colección global, así que una lista global no puede refrescarse en vivo.

**Approach:** Sección standalone **Bank Accounts** en `/backoffice/bank-accounts`: entrada de menú bajo Treasury, **lista global** cross-bank con realtime, y **detalle** `/backoffice/bank-accounts/{id}` con realtime. El detalle se suscribe a `bankAccountTopics.detail(id)` → cablea #396. La lista requiere una pieza nueva de API (topic de colección global, espejo del de Bank) + su suscripción PWA.

**Renegociado (revisión PR #421):** la sección standalone es superficie de gestión **autónoma**, no solo lectura. **Edición** vive en `/backoffice/bank-accounts/{id}/edit` (bank-agnóstica — la cuenta lleva su `bankId`); reusa `BankAccountForm` con un `returnTo` inyectado que mantiene Cancel/Back dentro del hub. El **borrado** está disponible en detalle y filas con el mismo guard `CLOSED` que el flujo anidado. Los filtros buscan el banco por **nombre o shortname** (`contains` sobre el JOIN), no por id. La ruta anidada `/banks/{id}/accounts/{id}/edit` se **mantiene** para el flujo por-banco. Sigue aplazado: **crear** standalone (necesita selector de banco).

## Boundaries & Constraints

**Always:**
- El detalle consume el topic de detalle por-cuenta (cablea #396). La lista consume un topic de colección **global** nuevo, espejo del de Bank (`urn:erpify:backoffice:banks`).
- El broadcast realtime lleva `BankAccountSnapshot` PII-free (`{bankId,status,createdAt,updatedAt}`); la lista/detalle refrescan por refetch, nunca pintan PII desde el payload.
- IBAN es PII: enmascarar SIEMPRE en el borde con `maskIban`/`IbanCell`; nunca loguearlo íntegro.
- La lista global se modela con un **tipo de proyección de lectura dedicado** (`BankAccountCollectionRow`), NO extendiendo la entidad `BankAccount` — espejo del contrato de la API (`BankAccountCollectionResource`/`BankAccountCollectionRow`), coherente con la gobernanza de proyecciones (ADR D3).
- Reusar el contexto `bankaccount` y los componentes de `banks/[id]/accounts/_components/`; extender, no duplicar.
- `safeHref` + `encodeURIComponent` en todo href dinámico; sin `maxLength` en inputs (límites en Zod); test-ids únicos con prefijo BEM; comentarios solo del *porqué* del código actual (sin IDs de historia/NFR ni "antes/ahora"). Bounded-context: solo `Backoffice/BankAccount` + `Shared` importables.

**Ask First:**
- Migrar/redirigir la UI anidada `/banks/{id}/accounts` (por defecto se mantienen ambas).
- Introducir un `count` global (la API no lo expone hoy).
- Cambiar el nombre/scoping del topic global propuesto (`urn:erpify:backoffice:bankaccounts`).

**Never:**
- **Crear** standalone con selector de banco (aún aplazado; una cuenta nace bajo un banco).
- Meter IBAN/holderName en el payload del broadcast, en logs, storage o telemetría.
- Extender la entidad `BankAccount` con `bankName`/`bankShortName` (va en la proyección; el filtro `bank` es un `contains` sobre el JOIN, no una columna nueva).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Lista con datos | `GET /bank-accounts` devuelve página con `bankName` | Tabla cross-bank: columna Banco, Titular, IBAN enmascarado, estado; paginación keyset prev/next | — |
| Lista vacía / filtrada vacía | página `data: []` | EmptyState (variante sin/‑con filtro) | — |
| Filtro/orden | filtros `holderName`/`iban`/`alias`/`bank` (nombre o shortname); orden `holderName`/`createdAt`/`updatedAt` | Se envían como `filters[]`/`sort`/`direction`; `bank` es `contains` sobre `CONCAT(b.name,' ',b.shortName)`; se resetea el cursor | Campos fuera de contrato no se ofrecen |
| Column picker en tarjetas | vista = cards | El picker se oculta (afordancia solo de tabla; las tarjetas tienen layout fijo) | — |
| Lista realtime | evento en el topic global (created/updated/deleted) | `silentReload()` de la página visible | reconnect → recarga silenciosa |
| Detalle OK | `GET /bank-accounts/{id}` 200 | Ficha con IBAN enmascarado + reveal; Edit → `bank-accounts/{id}/edit` (standalone); Delete con guard `CLOSED` | — |
| Detalle Delete | click Delete | CLOSED → confirm → `DELETE` → toast + redirige a la lista; no-CLOSED → guard "Edit account" (standalone); fallo → `MutationError` bajo el H1 | 409 `bank-account-not-closed` es el guard autoritativo |
| Detalle realtime updated/deleted | evento en `bankAccountTopics.detail(id)` | updated → refresca; deleted → redirige a la lista; reconnect → recarga | id no-UUID → no se construye topic |
| Detalle inexistente | 404 (`bank-account-not-found`) | EmptyState NOT_FOUND + CorrelationIdChip | Otros → ProblemDisplay |

</frozen-after-approval>

## Code Map

- `api/src/Backoffice/BankAccount/Domain/MercureBankAccountTopic.php` -- añadir topic de colección global (`urn:erpify:backoffice:bankaccounts`) + su template de autorización.
- `api/src/Backoffice/BankAccount/Infrastructure/Messenger/RefreshRealtimeOnBankAccountChanged.php` -- publicar también en el topic global en cada cambio.
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountRealtimeAuthorizeController.php` -- conceder el template global en la cookie del suscriptor.
- `pwa/src/context/backoffice/bankaccount/domain/BankAccountCollectionRow.ts` -- **nuevo** tipo de proyección de lista global (id, bankId, bankName, bankShortName, holderName, iban, bic, alias, currency, status).
- `pwa/src/context/backoffice/bankaccount/domain/BankAccountRepository.ts` -- añadir `searchAll(criteria): Promise<BankAccountCollectionPage>`.
- `pwa/src/context/backoffice/bankaccount/application/SearchAllBankAccounts.ts` -- **nuevo** caso de uso global (espejo de `SearchBanks`).
- `pwa/src/context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository.ts` -- `searchAll` → `GET BANK_ACCOUNTS.LIST`; validador/mapper propios de la colección (preservan `bankName`/`bankShortName`).
- `pwa/src/context/backoffice/bankaccount/infrastructure/{BankAccountCrudRepository,BankAccountResourceNavigator,bankAccountResourcePage}.ts` -- **nuevos** adaptadores tipados sobre `BankAccountCollectionRow` para `useResourceList`.
- `pwa/src/context/backoffice/bankaccount/infrastructure/bankAccountRealtime.ts` -- añadir `bankAccountTopics.collectionAll` (global) sin romper el per-bank existente.
- `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` -- añadir `BANK_ACCOUNTS.LIST`.
- `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts` -- bind `BackOfficeSearchAllBankAccounts`, `BackOfficeBankAccountCrudRepository`, `BackOfficeBankAccountResourceNavigator`.
- `pwa/src/app/backoffice/bank-accounts/{_lib,_components,page.tsx,[id]/page.tsx}` -- **nueva** ruta (espejo de `banks/`, lista con realtime global, detalle con realtime detalle).
- `pwa/src/app/backoffice/banks/[id]/accounts/_components/{IbanCell,BankAccountsTable,...}.tsx` -- reutilizar (IbanCell/maskIban tal cual; tabla con columna Banco).
- `pwa/src/app/backoffice/_lib/backofficeMenu.ts` -- subItem "Bank Accounts" bajo Treasury.
- `docs/architecture/event-catalog.md` -- sección `Backoffice.BankAccount` + generalizar el marco Bank-only.

## Tasks & Acceptance

**Execution:**
- [x] `api/.../Domain/MercureBankAccountTopic.php` (+ reactor `RefreshRealtimeOnBankAccountChanged` + `BankAccountRealtimeAuthorizeController`) -- topic de colección global espejo del de Bank; publicar en cada cambio; autorizar su template -- habilita realtime de la lista global. Tests PHPUnit del reactor/authorize.
- [x] `pwa/.../bankaccount/domain/BankAccountCollectionRow.ts` + `BankAccountRepository.ts` -- tipo de proyección + `searchAll(criteria)` -- la lista cross-bank necesita `bankName` sin contaminar la entidad.
- [x] `pwa/.../bankaccount/infrastructure/ApiBankAccountRepository.ts` -- `searchAll` contra `GET /bank-accounts` con validador/mapper de colección que preservan `bankName`/`bankShortName`.
- [x] `pwa/.../shared/http-client/infrastructure/ApiEndpoints.ts` -- `BANK_ACCOUNTS.LIST = ${BACKOFFICE_PREFIX}/bank-accounts`.
- [x] `pwa/.../bankaccount/application/SearchAllBankAccounts.ts` + adaptadores de recurso (`BankAccountCrudRepository`/`ResourceNavigator`/`resourcePage`, tipados sobre `BankAccountCollectionRow`) + bindings en `Container.ts` -- habilitar `useResourceList` global.
- [x] `pwa/.../bankaccount/infrastructure/bankAccountRealtime.ts` -- `bankAccountTopics.collectionAll` (global) alineado al topic de API.
- [x] `pwa/app/backoffice/bank-accounts/_lib/*` -- `bankAccountRoutes` (`list`, `detail`), filter/sort/criteria/paginate alineados al contrato (filtros holderName/iban/alias/bankId; orden holderName/createdAt/updatedAt).
- [x] `pwa/app/backoffice/bank-accounts/page.tsx` (+ `_components`) -- lista global espejo de Banks; `useBankAccountRealtime([bankAccountTopics.collectionAll], …)` → `silentReload`; columna Banco; IBAN enmascarado; acciones de fila enlazan al flujo anidado (rows llevan bankId).
- [x] `pwa/app/backoffice/bank-accounts/[id]/page.tsx` -- detalle espejo de `banks/[id]`; `useBankAccountRealtime(isUuid(id)?[bankAccountTopics.detail(id)]:[], …)` -- **cablea #396**.
- [x] `pwa/app/backoffice/_lib/backofficeMenu.ts` -- subItem "Bank Accounts" (icon Wallet) tras "Banks" en Treasury (el título de sección se deriva solo).
- [x] `docs/architecture/event-catalog.md` -- sección `Backoffice.BankAccount` (eventos created/updated/status_changed/deleted; `aggregateType Backoffice.BankAccount`; `BankAccountSnapshot`={bankId,status,createdAt,updatedAt} PII-free, deleted lleva snapshot; nota Mercure: status_changed → wire `bank_account.updated`; ahora **2 topics de colección** —per-bank + global— y detalle CONSUMIDO) + generalizar framing Bank-only.
- [x] Tests -- unit contexto (`SearchAllBankAccounts`, `searchAll`+preservación bankName, adaptadores de recurso, `collectionAll` topic), unit páginas (lista: skeleton/retry/paginación/filtros/columna Banco/máscara IBAN/realtime; detalle: realtime updated/deleted/reconnect), e2e `bank-accounts-list.spec.ts` (+ fixture) lista global + realtime + detalle.
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` -- registrar que la nueva UI de lista/detalle expone IBAN (PII) desde un endpoint aún sin auth; mitigación de display = máscara; broadcast realtime PII-free; gating RBAC de ruta pendiente antes de prod.

**Acceptance Criteria:**
- Given un usuario en el backoffice, when abre el menú Treasury, then ve "Bank Accounts" y navega a `/backoffice/bank-accounts`.
- Given cuentas de varios bancos, when se carga la lista, then cada fila muestra su banco (bankName) y el IBAN enmascarado, con paginación keyset funcional.
- Given la lista abierta, when otro usuario crea/actualiza/elimina una cuenta, then la lista se refresca en vivo vía el topic de colección global (payload PII-free).
- Given la ficha de una cuenta abierta, when otro usuario la actualiza/elimina, then la ficha se refresca / redirige en vivo vía el topic de detalle (#396 cableado end-to-end).
- Given cualquier href dinámico (detalle/edit), when se renderiza, then pasa por `safeHref` + `encodeURIComponent`.

## Design Notes

- **Simetría con Bank:** Bank ya tiene topic de colección global (`urn:erpify:backoffice:banks`) + detalle; bank-account solo tenía per-banco + detalle. La pieza nueva de API es el topic global que faltaba — esto es lo que da realtime a la lista y completa la simetría.
- **`bankName` como proyección (Opción B):** la API ya modela `bankName`/`bankShortName` en `BankAccountCollectionRow` (JOIN, `Domain/Projection/`), fuera de la entidad, y expone 3 resources por vista. El PWA espeja esa disciplina: `BankAccountCollectionRow` para la lista, `BankAccount` (entidad) para detalle/write. Evita fuga de frontera de agregado y mantiene la entidad limpia.
- **PII en realtime:** el snapshot del broadcast es PII-free; la lista/detalle disparan refetch (como Banks), nunca renderizan desde el payload → el canal Mercure no filtra IBAN.
- **Sin count global** en el header: la API no expone `/bank-accounts/count`; se omite en el núcleo (Ask First si se quiere).

## Verification

**Commands (desde el worktree):**
- `make pwa.quality` -- expected: ESLint + Prettier limpios.
- `make php.stan` -- expected: sin errores en los ficheros PHP tocados (topic/reactor/authorize).
- `make php.quality` -- expected: barrido PHP completo verde (deptrac + bounded-context incluidos).
- `make pwa.test.unit` -- expected: verdes, incluidos los nuevos tests.
- `make php.behat` / `make pwa.test.e2e c='bank-accounts-list'` -- expected: el reactor/authorize y el spec de lista+realtime+detalle pasan (stack en vivo del worktree).

**Manual checks:**
- Menú Treasury muestra "Bank Accounts"; la lista carga cuentas cross-bank con banco + IBAN enmascarado; el reveal re-enmascara solo.
- Con la lista abierta, un create/update/delete desde otra pestaña la refresca en vivo; con el detalle abierto, un `PUT`/`DELETE`/`PATCH status` lo refresca/redirige en vivo.

## Spec Change Log

- **Security review (blind hunter) → filtro IBAN eliminado.** Hallazgo MAJOR: el filtro por IBAN enviaba el IBAN íntegro como query-string GET, filtrando PII a los logs de acceso del servidor/proxy — contradice el mask-everywhere del feature y persiste incluso con RBAC. **Aplicada la opción recomendada A**: se retira el filtro IBAN (la lista conserva holderName/alias/bankId; la columna IBAN sigue enmascarada). Esto **estrecha el I/O matrix congelado** (que listaba `iban` como filtro) por seguridad — desviación del intent congelado dirigida por seguridad, **pendiente de ratificación de Sergio** (revertible a transporte POST-body si se prefiere conservar la búsqueda por IBAN). KEEP: la vía de display del IBAN (`IbanCell`/`maskIban`, columna, reveal) queda intacta.
- **Security review (edge-case) → guard `isUuid` en el filtro `bankId`.** El campo libre mandaba cada valor parcial a la API (que exige UUID → 422), tumbando la lista mid-tecleo. Ahora solo emite el filtro con un UUID completo.
- **Review → race de refetch en el detalle.** `currentIdRef` descarta un refetch silent que resuelve tras cambiar el id de ruta (evita que el detalle de A pise al de B).

## Suggested Review Order

**Realtime wiring — el corazón del cambio (API global topic + #396)**

- La lista se suscribe al nuevo topic de colección global y refetcha en vivo (payload PII-free).
  [`page.tsx:97`](../../pwa/src/app/backoffice/bank-accounts/page.tsx#L97)
- El detalle se suscribe al topic por-cuenta: esto es lo que cablea el consumidor que faltaba end-to-end.
  [`[id]/page.tsx:138`](../../pwa/src/app/backoffice/bank-accounts/%5Bid%5D/page.tsx#L138)
- Topic de colección global nuevo, espejo del de Bank (contrato compartido API↔PWA).
  [`MercureBankAccountTopic.php:16`](../../api/src/Backoffice/BankAccount/Domain/MercureBankAccountTopic.php#L16)
- El reactor publica en los 3 topics (global + por-banco + por-cuenta) en cada cambio.
  [`RefreshRealtimeOnBankAccountChanged.php:81`](../../api/src/Backoffice/BankAccount/Infrastructure/Messenger/RefreshRealtimeOnBankAccountChanged.php#L81)
- El authorize concede el topic global en la cookie del suscriptor.
  [`BankAccountRealtimeAuthorizeController.php:37`](../../api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountRealtimeAuthorizeController.php#L37)
- El literal del topic PWA debe igualar el de la API.
  [`bankAccountRealtime.ts:10`](../../pwa/src/context/backoffice/bankaccount/infrastructure/bankAccountRealtime.ts#L10)

**Proyección de lectura (decisión #5, Opción B) + wiring**

- Tipo de proyección dedicado — `bankName` fuera de la entidad `BankAccount`.
  [`BankAccountCollectionRow.ts:18`](../../pwa/src/context/backoffice/bankaccount/domain/BankAccountCollectionRow.ts#L18)
- `searchAll` + validador/mapper de colección que preservan `bankName`/`bankShortName`.
  [`ApiBankAccountRepository.ts:242`](../../pwa/src/context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository.ts#L242)
- Adaptador de recurso para `useResourceList` (remap `{rows}`→`{items}`).
  [`BankAccountCrudRepository.ts:40`](../../pwa/src/context/backoffice/bankaccount/infrastructure/BankAccountCrudRepository.ts#L40)
- Endpoint `BANK_ACCOUNTS.LIST` + bindings DI.
  [`ApiEndpoints.ts:42`](../../pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts#L42)
  [`Container.ts:138`](../../pwa/src/context/shared/dependency-injection/infrastructure/Container.ts#L138)

**Fixes de seguridad de la revisión**

- Guard `isUuid` en el filtro `bankId` (evita el 422 mid-tecleo).
  [`bankAccountsSearchCriteria.ts:30`](../../pwa/src/app/backoffice/bank-accounts/_lib/bankAccountsSearchCriteria.ts#L30)
- Guard de id vivo contra el race del refetch silent.
  [`[id]/page.tsx:114`](../../pwa/src/app/backoffice/bank-accounts/%5Bid%5D/page.tsx#L114)

**Superficie de entrada (menú)**

- SubItem "Bank Accounts" bajo Treasury (el título de sección se deriva solo).
  [`backofficeMenu.ts:154`](../../pwa/src/app/backoffice/_lib/backofficeMenu.ts#L154)

**Tests (soporte)**

- E2E de lista global + detalle realtime.
  [`bank-accounts-list.spec.ts:14`](../../pwa/tests/e2e/backoffice/bank-accounts-list.spec.ts#L14)
