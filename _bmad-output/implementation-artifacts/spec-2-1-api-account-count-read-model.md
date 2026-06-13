---
title: 'API · Read-model batched del contador de cuentas (accountCount en lista de bancos)'
type: 'feature'
created: '2026-06-12'
status: 'done'
baseline_commit: '827ff5871bebafa046b1aa72d775063b3c300737'
context:
  - '{project-root}/docs/project-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture-bank-associated-accounts.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** La lista de bancos no expone cuántas cuentas tiene cada banco. La PWA necesita esa señal (`accountCount`) para el contador de la lista, la antesala del delete-guard y la recuperación del `409 bank-in-use` (Sentry `ERPIFY-API-DEV-6`), pero resolverla con `countByBankId()` por fila sería un N+1.

**Approach:** Añadir `accountCount: int` a la **lista** (`GET /banks`, una query agregada `GROUP BY bank_id` por página) y al **detalle** (`GET /banks/{id}`, count del id vía el mismo puerto). El read-model vive en el contexto `BankAccount` (puerto de lectura `AccountCountsByBank`), no en `Bank`; las ensambladuras `BankSearcher` (lista) y `BankFinder` (detalle) lo consumen y enriquecen el/los item(s). Es Story 2.1 / PR1 del Epic 2 (arquitectura `architecture-bank-associated-accounts.md`).

## Boundaries & Constraints

**Always:**
- **Una sola query agregada por página** (`COUNT(ba.id) ... WHERE ba.bank_id IN (:ids) GROUP BY ba.bank_id`). Prohibido `countByBankId()` en bucle. Reusa el índice `IDX_53A23E0A11C8FB41`.
- El read-model **pertenece al contexto `BankAccount`** (puerto `AccountCountsByBank` + impl Doctrine), solo lectura (CE-3/CE-4). `Bank`/`DoctrineBankRepository` **nunca** consulta `bank_account`.
- **Misma semántica en lista y detalle**: `accountCount` es un entero **≥ 0**, **nunca `null`**; banco sin cuentas → `0` (en el ensamblado, no en SQL). Página vacía (0 bancos) → **no** se emite la query agregada (evitar `IN ()`).
- `accountCount` es un escalar derivado; la API **no** codifica semántica de navegación (invariante #1). Tolerante a staleness (invariante #4).

**Never:**
- No `#[ORM\OneToMany]` Bank→BankAccount ni JOIN cross-agregado (se referencia por id; CE-3).
- No tocar la infra Shared de paginación/serialización (`SearchResponder`, `ResourceResponder`, `PaginationMeta`) — el envelope no cambia.
- No migraciones (índice ya existe; denormalizar `bank.account_count` es escape-hatch futuro).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Lista: N bancos, algunos con cuentas | `GET /banks` | Cada item gana `accountCount` = recuento real; **exactamente 1** query agregada adicional por página | N/A |
| Detalle: banco con 3 cuentas | `GET /banks/{id}` | `accountCount: 3` | N/A |
| Banco sin cuentas (lista o detalle) | id ausente del mapa | `accountCount: 0` (nunca `null`) | N/A |
| Página vacía (0 bancos) | page.items == [] | No se ejecuta la query agregada; `data: []` | N/A |
| Mapa con bancos fuera de página | counts de ids no presentes | Se ignoran (solo se enriquecen los items presentes) | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Backoffice/BankAccount/Domain/Repository/AccountCountsByBank.php` -- NUEVO: puerto de lectura query-side `countsByBankIds(list<string> $bankIds): array<string,int>` (CE-3/CE-4).
- `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineAccountCountsByBank.php` -- NUEVO: impl batched `GROUP BY bank_id`; `$bankIds === [] ⇒ []` sin query.
- `api/src/Backoffice/Bank/Application/BankSearcher.php` -- MODIFICADO (lista): inyecta `AccountCountsByBank`; tras `search()`, batch-cuenta los ids de la página (vía `getId()`) y `assignAccountCount()` por item.
- `api/src/Backoffice/Bank/Application/BankDetailFinder.php` -- NUEVO (detalle): compone `BankFinder` + `AccountCountsByBank`; usado por `BankGetController`. `BankFinder` queda puro (Spec Change Log #2).
- `api/src/Backoffice/Bank/Domain/Entity/Bank.php` -- MODIFICADO: propiedad transitoria (no `#[ORM]`) `int $accountCount = 0`, `getAccountCount(): int` bajo `#[Groups([self::GROUP_ACCOUNT_COUNT])]`, `assignAccountCount(int): void`, + const `GROUP_ACCOUNT_COUNT`.
- `api/src/Backoffice/Bank/Infrastructure/Controller/{BankSearchController,BankGetController}.php` -- MODIFICADOS: añaden `GROUP_ACCOUNT_COUNT` a sus grupos de lectura (POST/PUT quedan fuera, Spec Change Log #1).
- `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineBankAccountRepository.php` -- REFERENCIA: patrón `countByBankId` espejado para el batched.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Backoffice/BankAccount/Domain/Repository/AccountCountsByBank.php` -- puerto de lectura creado -- aísla el read-model en el contexto dueño (CE-3) y segrega lectura/escritura (CE-4).
- [x] `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineAccountCountsByBank.php` -- query batched + guard de lista vacía; `#[AsAlias]` (autowiring, sin `services.yaml`).
- [x] `api/src/Backoffice/Bank/Domain/Entity/Bank.php` -- campo transitorio `accountCount` + getter + `assignAccountCount`, bajo el grupo dedicado `GROUP_ACCOUNT_COUNT` (ver Spec Change Log #1).
- [x] `api/src/Backoffice/Bank/Application/BankSearcher.php` -- inyecta el puerto y enriquece los items de la página (lista); `Bank` no toca `bank_account`.
- [x] `api/src/Backoffice/Bank/Application/BankDetailFinder.php` (NUEVO) + `Infrastructure/Controller/BankGetController.php` -- handler de lectura del detalle que enriquece; `BankFinder` queda puro (ver Spec Change Log #2).
- [x] `api/src/Backoffice/Bank/Infrastructure/Controller/{BankSearchController,BankGetController}.php` -- añaden `GROUP_ACCOUNT_COUNT` a sus grupos de lectura.
- [x] `api/tests/Unit/Backoffice/Bank/Application/BankSearcherTest.php` (+ `FakeBankSearchRepository`, `FakeAccountCountsByBank`) -- enriquecimiento, edge 0, página vacía, una sola llamada batched.
- [x] `api/tests/Unit/Backoffice/Bank/Application/BankDetailFinderTest.php` -- count del banco encontrado; 0; 404 y 400 intactos.
- [x] Integración de `DoctrineAccountCountsByBank` -- cubierta por Behat (ver Spec Change Log #3) en vez de un test PHP separado.
- [x] Behat (`features/backoffice/bank/{search,get}.feature`) -- `accountCount` por item + valor (JPMorgan→1, Wells Fargo→0); anti-N+1 (31 bancos = 2 queries, no 1+31); detalle valor 1/0; guard de página vacía cubierto por el escenario "returns no results" (1 query). Query-counts de todos los escenarios de lista/detalle reconciliados a la query extra.

**Acceptance Criteria:**
- Given la lista de bancos, when se pide una página, then cada item incluye `accountCount` y se ejecuta **exactamente una** query agregada adicional (`GROUP BY bank_id`) para toda la página (no N por fila).
- Given el detalle de un banco con cuentas, when `GET /banks/{id}`, then `accountCount` es el recuento real; con 0 cuentas, `0` (nunca `null`).
- Given el read-model del contador, then vive en `BankAccount` (`AccountCountsByBank`) y `DoctrineBankRepository`/`Bank` nunca consultan `bank_account`.
- Given una página sin bancos, when se ensambla, then no se ejecuta la query agregada y `data` es `[]`.

## Spec Change Log

**#1 — `accountCount` en grupo dedicado, no en `GROUP_DETAIL`.** Hallazgo en implementación: `GROUP_DETAIL` lo serializan también POST y PUT; emitir `accountCount` ahí mostraría un `0` engañoso en la respuesta de PUT (banco con cuentas → 0, porque el write-path no enriquece). Amendment: el campo usa `GROUP_ACCOUNT_COUNT` (patrón `GROUP_READ_URLS`), activado **solo** por los dos endpoints de lectura (lista + GET detalle). Evita: payload de write con contador stale. KEEP: el campo es un read-projection, nunca parte del estado transaccional de `Bank`.

**#2 — Detalle vía `BankDetailFinder`, no modificando `BankFinder`.** Hallazgo: `BankFinder.find()` lo comparten `BankDeleter` y `BankUpdater` (write-path); enriquecer ahí acoplaría el load del agregado al read-model de otro contexto y metería una query redundante en cada borrado/edición. Amendment: `BankFinder` queda puro; un `BankDetailFinder` (Application) compone `BankFinder` + `AccountCountsByBank` y lo usa solo `BankGetController`. Evita: acoplamiento write→read-model y query redundante en el delete.

**#3 — Integración por Behat, no test PHP separado.** El harness de integración preferido del repo es Behat; los escenarios de lista/detalle ejercitan la query batched real (valores, anti-N+1, guard de vacío) end-to-end. Un `DoctrineAccountCountsByBankTest` PHP duplicaría esa cobertura. Evita: redundancia de tests.

## Design Notes

**Por qué campo transitorio en `Bank` y no un normalizer ni un DTO nuevo.** `Bank` ya es la superficie de serialización directa (sin capa DTO de lista) y ya porta `#[Groups]` (excepción documentada). El normalizer derivado del repo (`BankLogoUrlNormalizer`) lee del propio entity, pero `accountCount` viene de una query batched a nivel de **página** y `ResourceNormalizer::toList()` solo recibe `groups` — sin seam para inyectar el mapa sin modificar la infra Shared. Un campo transitorio enriquecido por la Application es el cambio mínimo. Precedente cross-context: `BankDeleter` ya inyecta un puerto de `BankAccount` desde la Application de `Bank`.

**Enriquecimiento (BankSearcher):** `getId()` es `?string` (el `id()` no-null es `protected`), así que se filtran nulls antes de `countsByBankIds()` y se guarda el null en el bucle. Una sola llamada batched por página.

**Query (impl Doctrine, DQL):** `SELECT ba.bankId AS bankId, COUNT(ba.id) AS cnt FROM BankAccount ba WHERE ba.bankId IN (:ids) GROUP BY ba.bankId` → `array<string,int>`. `$ids === [] ⇒ return []` antes de consultar (evita `IN ()`).

## Verification

**Commands (todos ejecutados, verdes):**
- `make php.stan` -- 0 errores (level max).
- `make php.unit c="--filter 'BankSearcherTest|BankDetailFinderTest'"` -- 7/7.
- `make php.behat c='features/backoffice/bank'` -- 87 escenarios verdes (incl. anti-N+1 y valores).
- `make php.quality` -- EXIT 0 (cs-fixer, phpcs, stan, rector, phpmd, mapping).

## Suggested Review Order

**Read-model (diseño núcleo)**

- Puerto de lectura query-side, segregado del write-side (CE-3/CE-4) — el contrato a leer primero.
  [`AccountCountsByBank.php:20`](../../api/src/Backoffice/BankAccount/Domain/Repository/AccountCountsByBank.php#L20)

- Una query `GROUP BY` por página + guard de lista vacía (anti-N+1, evita `IN ()`).
  [`DoctrineAccountCountsByBank.php:29`](../../api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineAccountCountsByBank.php#L29)

**Enriquecimiento lista + detalle**

- Lista: una llamada batched con los ids de la página; null-safe; `Bank` no toca `bank_account`.
  [`BankSearcher.php:28`](../../api/src/Backoffice/Bank/Application/BankSearcher.php#L28)

- Detalle: handler dedicado (write-path puro); keyea por el id canónico del agregado (fix de casing).
  [`BankDetailFinder.php:30`](../../api/src/Backoffice/Bank/Application/BankDetailFinder.php#L30)

- Grupo de serialización dedicado para no filtrar `accountCount` en respuestas de POST/PUT.
  [`Bank.php:57`](../../api/src/Backoffice/Bank/Domain/Entity/Bank.php#L57)

- Solo los dos endpoints de lectura activan el grupo.
  [`BankGetController.php:33`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankGetController.php#L33)
  [`BankSearchController.php:45`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php#L45)

**Tests**

- Enriquecimiento, edge 0/vacío, una sola llamada batched.
  [`BankSearcherTest.php:1`](../../api/tests/Unit/Backoffice/Bank/Application/BankSearcherTest.php#L1)

- Count del detalle, 404/400 intactos, y el fix de casing del id.
  [`BankDetailFinderTest.php:39`](../../api/tests/Unit/Backoffice/Bank/Application/BankDetailFinderTest.php#L39)

- Anti-N+1 y valores end-to-end (lista 31→2 queries; detalle 1/0; POST sin `accountCount`).
  [`search.feature:28`](../../api/features/backoffice/bank/search.feature#L28)
  [`get.feature:18`](../../api/features/backoffice/bank/get.feature#L18)
