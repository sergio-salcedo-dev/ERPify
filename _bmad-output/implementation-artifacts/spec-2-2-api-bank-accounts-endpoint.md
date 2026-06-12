---
title: 'API · Endpoint de cuentas por banco (GET /backoffice/banks/{id}/accounts)'
type: 'feature'
created: '2026-06-12'
status: 'done'
baseline_commit: '7bfc2ad'
context:
  - '{project-root}/docs/project-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture-bank-associated-accounts.md'
  - '{project-root}/docs/api-error-contract.md'
  - '{project-root}/PRODUCTION_SECURITY_CHECKLIST.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** No existe forma de listar las cuentas de un banco. La PWA (Story 2.3) necesita un endpoint paginado para ver holder, IBAN, alias, divisa y estado de las cuentas que bloquean el borrado de un banco (Sentry `ERPIFY-API-DEV-6`).

**Approach:** Nuevo endpoint read-only `GET /backoffice/banks/{id}/accounts` con paginación keyset (envelope final del Epic 1), servido por un read context `BankAccount` (`BankAccountSearchRepository` → `DoctrineSearchEngine`). La existencia del banco (404) se valida con un puerto reutilizable `BankExistenceChecker` (publicado por el contexto `Bank`), **no** con un pre-read en el controller. Devuelve el **IBAN íntegro canónico** (el enmascarado es del cliente). Registra un **evento de auditoría de acceso** (mensaje de auditoría, no de dominio; nunca el IBAN). Story 2.2 / PR2 del Epic 2.

## Boundaries & Constraints

**Always:**
- **Envelope final del Epic 1** (CE-1): `{items, pagination:{hasNext,hasPrev,count,links:{next,prev}}}`, params `after`/`before`/`limit`/`sort` vía `#[MapQueryString] SearchQuery`. Reusa `DoctrineSearchEngine`/`SearchResponder` — **un único contrato wire**; legacy `{cursor,hasMorePages}` prohibido.
- La búsqueda se acota a `WHERE ba.bankId = :id` en el QueryBuilder base antes de paginar.
- **Existencia vía puerto reutilizable** `BankExistenceChecker.ensureExists($bankId)` (publicado por `Bank`, consumido por `BankAccountSearcher`): `Uuid::ensure` primero (→ `400 invalid-uuid`, **0 queries**), luego existencia (→ `404 bank-not-found`). Sin acoplar a `BankFinder` ni duplicar pre-read en el controller. Todo por el pipeline RFC 9457.
- **IBAN — contrato canónico**: la API expone y (en búsquedas futuras) acepta el IBAN **uppercase, sin espacios ni separadores** (`ES9121000418450200051332`). Íntegro en el payload (FR5/invariante #3); el masking nunca ocurre en backend; el IBAN **nunca** se loggea.
- **Evento de auditoría de acceso** (invariante #3): el use case despacha `BankAccountsViewedAuditEvent` — **mensaje de auditoría/observabilidad, NO un `DomainEvent`** (fuera del lenguaje de dominio) — manejado por un `#[AsMessageHandler]` idempotente que registra un audit log estructurado (bankId + occurredOn; **sin** IBAN ni valores de cuenta). `userId` diferido hasta que exista auth.
- **`currency` es parte del contrato (modelo multicurrency)**: la entidad modela `Currency` (no es siempre EUR); se serializa como su `->value` (`"EUR"`). `status` se serializa como **label legible** (`"active"`/`"inactive"`/`"closed"`), no el int.
- Read context **solo puertos de lectura** (CE-4); construye sobre el repo `BankAccount` por composición del Epic 1 (CE-2). Doctrine ORM 3 / DBAL 4 (`fetchAllAssociative`/`toIterable`; nunca `flush($entity)`/`fetchAll()`).

**Ask First:**
- (resueltas) Endpoint **público** como el resto de `/backoffice` (no hay auth en el repo): documentar la exposición IBAN en `PRODUCTION_SECURITY_CHECKLIST.md` + abrir issue de auth unificada. Auditoría de acceso **incluida**.

**Never:**
- No `#[ORM\OneToMany]` Bank→BankAccount; no exponer `bankId` como contrato de navegación.
- El evento de acceso **no** entra en el event store de dominio (no es negocio); no se persiste como `DomainEvent`.
- No Mercure (v1 estático, invariante #4); no migraciones; no tocar infra Shared de paginación; no cablear escritura (`save()`) en el read context (CE-4).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Banco con cuentas | `GET /banks/{id}/accounts` | `200`, `data[]` con id, holderName, iban (canónico íntegro), bic?, alias, currency (`EUR`), status (`active`); envelope keyset | N/A |
| Banco sin cuentas | id válido, banco existe, 0 cuentas | `200`, `data: []`, `hasNext/hasPrev` false | N/A |
| Paginación keyset | `?limit=n&after=<cursor>` | página siguiente; `links.next/prev` relativos | N/A |
| `after` y `before` juntos | ambos params | — | `422 validation-failed` (engine) |
| id malformado | `/banks/not-a-uuid/accounts` | — | `400 invalid-uuid`, **0 queries** |
| banco inexistente | UUID válido sin banco | — | `404 bank-not-found` (1 query de existencia) |
| Acceso (auditoría) | cualquier `200` | se registra 1 audit log `bank_accounts.viewed` (bankId, occurredOn); **sin** IBAN | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php` -- PLANTILLA del controller keyset.
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` -- PLANTILLA de `search()` (QueryBuilder + field/sort maps + engine).
- `api/src/Backoffice/Bank/Application/BankFinder.php` -- REFERENCIA (no se usa): de aquí sale el `404`/`Uuid::ensure`; el nuevo `BankExistenceChecker` hace lo mismo sin hidratar la entidad.
- `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php` -- MODIFICAR: grupos `GROUP_LIST/GROUP_READ` + `#[Groups]`; `getStatus(): string` (label). IBAN ya se persiste canónico.
- `api/src/Backoffice/BankAccount/Domain/Enum/BankAccountStatus.php` -- REUSO: `HumanReadableIntEnumTrait` (labels).
- `api/src/Shared/Infrastructure/Messenger/PersistDomainEventMiddleware.php` -- VERIFICAR: solo actúa sobre `DomainEvent`; el audit event (mensaje plano) pasa a su handler sin persistirse ahí.
- `api/src/Backoffice/Bank/Application/BankCreator.php` -- PLANTILLA de dispatch al `MessageBusInterface`.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php` -- `GROUP_READ` (proyección única, KISS) + `#[Groups]` (holderName, iban, bic, alias, currency); `getStatusLabel()` con `#[SerializedName('status')]` → label (ver Spec Change Log #1/#2).
- [x] `api/src/Backoffice/Bank/Domain/Repository/BankExistenceChecker.php` (NUEVO, puerto en Domain/Repository por convención del repo) -- `ensureExists(string $bankId): void`.
- [x] `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankExistenceChecker.php` (NUEVO) -- `Uuid::ensure` + COUNT (sin hidratar); `#[AsAlias]`.
- [x] `api/src/Backoffice/BankAccount/Domain/Repository/BankAccountSearchRepository.php` (NUEVO) -- `search(string $bankId, SearchCriteria): Page`.
- [x] `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineBankAccountSearchRepository.php` (NUEVO) -- QB `WHERE ba.bankId = :bankId` + `DoctrineSearchEngine::paginate`; sortFieldMap (holderName/createdAt/updatedAt), searchFieldMap vacío.
- [x] `api/src/Backoffice/BankAccount/Application/Query/SearchBankAccountsQuery.php` (NUEVO).
- [x] `api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php` (NUEVO) -- `ensureExists` → repo → despacha el audit event (después de la búsqueda, siempre en éxito incl. vacío; nunca en 400/404).
- [x] `api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php` (NUEVO) -- mensaje plano (bankId + occurredOn); **no** `DomainEvent`.
- [x] `api/src/Backoffice/BankAccount/Infrastructure/Audit/BankAccountsViewedAuditHandler.php` (NUEVO) -- `#[AsMessageHandler]` (sync, no enrutado); audit log estructurado, **sin** IBAN.
- [x] `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountSearchController.php` (NUEVO) -- ruta `/banks/{id}/accounts`; grupos `[IDENTIFIABLE, GROUP_READ]`; pasa `['id' => $id]` como routeParams (ver Spec Change Log #3).
- [x] `api/src/Shared/Infrastructure/Http/Responder/SearchResponder.php` (MODIFICADO) -- param opcional `routeParams` retrocompatible para rutas keyset anidadas (Spec Change Log #3).
- [x] `api/tests/Unit/Backoffice/BankAccount/Application/BankAccountSearcherTest.php` (+ fakes + RecordingMessageBus) -- existencia delegada; 1 audit event sin IBAN; 400/404 sin buscar ni auditar.
- [x] `api/features/backoffice/bankaccount/search.feature` (NUEVO) -- 200 full projection (JPMorgan→Globex, iban canónico, status `inactive`), `active`+nulls (BofA→Initech), vacío (Wells Fargo→0), limit, 400, 404; envelope keyset + query-counts (200=2, 404=1, 400=0).
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` (§6, IBAN PII + ruta pública → issue #240) + `api/docs/adding-endpoints.md` (endpoints anidados + contrato IBAN canónico). `docs/architecture-api.md`: diferido (no tiene catálogo de endpoints; cubierto por adding-endpoints).

## Spec Change Log

**#1 — Una sola proyección `GROUP_READ` (no `GROUP_LIST`+`GROUP_READ`).** No hay segunda vista de cuentas (las filas no navegan en v1), así que un único grupo de lectura basta (KISS). Evita un grupo muerto.

**#2 — `getStatusLabel()` con `#[SerializedName('status')]`, no `getStatus(): string`.** El serializer de Symfony rechaza `#[Groups]` en métodos que no empiezan por get/is/has/can/set (MappingException). Además `getStatus()` ya devuelve el enum (dominio). Solución: un getter `getStatusLabel(): string` (label) agrupado y renombrado a `status` por `#[SerializedName]`; la propiedad enum queda sin grupo. Evita: serializar el int crudo y romper el dominio.

**#3 — `SearchResponder` extendido con `routeParams` opcional.** La spec decía "no tocar infra Shared de paginación", asumiendo rutas planas. Este es el primer endpoint keyset **anidado** (`/banks/{id}/accounts`): `urlGenerator->generate` necesita el `{id}` para materializar `links.next/prev`, o lanza. Amendment: `respond(..., array $routeParams = [])` retrocompatible (default vacío → comportamiento idéntico; Behat de Bank 126/126 intacto). Evita: links keyset rotos o un segundo responder que duplicaría el contrato wire (CE-1). KEEP: el responder sigue siendo el único compositor del envelope.

**#4 — `BankExistenceChecker` en `Bank/Domain/Repository`** (no `Application/Port` como proponía la spec) — alinea con la convención del repo (los puertos viven en `Domain/Repository`).

**#5 — Issue de auth = #240** (nuevo, sibling de #222). #222 es la *exención* pública de los health endpoints; las cuentas son lo contrario (deben **requerir** auth), así que merecen su propio tracker en vez de reusar #222.

**#6 — (review) Auditoría best-effort.** Hallazgo de la review adversarial: el mensaje de auditoría es sync (no enrutado), así que un fallo del handler/logger envolvía un `HandlerFailedException` que tumbaba una lectura `200` ya exitosa (500). La auditoría es observabilidad, no parte del contrato de lectura. Amendment: `BankAccountSearcher` envuelve el dispatch en try/catch (`MessengerExceptionInterface`), loggea un warning (sin IBAN) y devuelve la página. Test añadido (`testAReadSurvivesAnAuditDispatchFailure`). Evita: una lectura buena convertida en 5xx por un hipo de auditoría (y blinda el día que el mensaje pase a async).

**#7 — (review) Cobertura de traversal keyset.** Hallazgo: todas las fixtures tenían ≤1 cuenta/banco, así que `hasNext` nunca era true y el round-trip de cursor anidado (el código nuevo de `routeParams` + el tiebreaker del engine) no se ejercitaba end-to-end. Amendment: 3 cuentas en Citigroup (banco no aserto por 2.1) + escenario que pagina `limit=2` → sigue `links.next` → página 2. Cierra la cobertura y valida empíricamente el tiebreaker determinista del engine (M2 descartado). Resto de findings (validación-en-adapter, label `null` del outline) = rechazados por convención del repo.

**Acceptance Criteria:**
- Given un banco con cuentas, when `GET /banks/{id}/accounts`, then `200` con el envelope keyset final del Epic 1; cada cuenta lleva el IBAN canónico íntegro, `currency` (`EUR`) y `status` como label (`active`).
- Given un id malformado, then `400 invalid-uuid` con 0 queries; given un banco inexistente, then `404 bank-not-found`; ambos por RFC 9457 vía `BankExistenceChecker`.
- Given una lectura `200`, then se registra exactamente un audit log de acceso con bankId+occurredOn y **sin** el IBAN.
- Given el read context, then solo cablea puertos de lectura (CE-4), reusa el envelope/engine del Epic 1 (CE-1), y el evento de acceso **no** es un `DomainEvent`.

## Spec Change Log

## Design Notes

**Existencia reutilizable, no pre-read en el controller.** `BankExistenceChecker` (puerto publicado por `Bank`, consumido por `BankAccountSearcher`) centraliza `Uuid::ensure` + existencia (→ 400/404) para CUALQUIER endpoint hijo de un banco — evita que el patrón "pre-read + read" se duplique por el backoffice. La impl hace una consulta de existencia barata (sin hidratar `Bank`). El `400` ocurre antes de tocar la DB (0 queries).

**Auditoría ≠ dominio.** `BankAccountsViewedAuditEvent` es un mensaje de auditoría/observabilidad, **no** un hecho de negocio: no extiende `DomainEvent`, no entra en el event store de dominio. Un `#[AsMessageHandler]` dedicado lo registra (audit log estructurado, canal/contexto `audit`, sin IBAN). Mantener la auditoría separada del lenguaje de dominio evita contaminar un futuro event sourcing / integración externa.

**Momento de emisión:** el dispatch va **después** de `ensureExists` y de la búsqueda, justo antes de devolver. Así un `400`/`404` **no** emite evento (sin ruido de auditoría), mientras que un `200` **siempre** emite — incluido `items: []` (consultar las cuentas de un banco existente con 0 cuentas sigue siendo un acceso auditable).

**`status` como label:** `getStatus(): string` → `$this->status->label()` bajo el grupo de lectura; la propiedad enum queda sin grupo para que el serializer emita el label, no el int. Verificar en Behat (`"active"`).

## Verification

**Commands:**
- `make php.stan` -- 0 errores (level max) en lo tocado.
- `make php.unit c='--filter BankAccountSearcher'` -- verde (existencia + evento sin IBAN).
- `make php.behat c='features/backoffice/bankaccount'` -- 200/vacío/400/404/keyset verdes; `status` = `active`, `currency` = `EUR`, iban canónico.
- `make php.quality` -- limpio.

## Suggested Review Order

**Endpoint y flujo**

- Punto de entrada: controller keyset anidado; orquesta el searcher y pasa `['id' => $id]` como routeParams.
  [`BankAccountSearchController.php:39`](../../api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountSearchController.php#L39)

- Use case: existencia (400/404) → búsqueda → auditoría best-effort. Lee el orden y el `recordAccess`.
  [`BankAccountSearcher.php:41`](../../api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php#L41)
  [`BankAccountSearcher.php:57`](../../api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php#L57)

**Existencia reutilizable y scoping**

- `Uuid::ensure` antes de tocar la DB (0 queries en 400) + COUNT sin hidratar (404).
  [`DoctrineBankExistenceChecker.php:23`](../../api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankExistenceChecker.php#L23)

- Búsqueda acotada `WHERE ba.bankId = :bankId` sobre el engine keyset compartido.
  [`DoctrineBankAccountSearchRepository.php:43`](../../api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineBankAccountSearchRepository.php#L43)

**Serialización y envelope**

- IBAN íntegro + `status` como label vía getter renombrado (`#[SerializedName('status')]`).
  [`BankAccount.php:144`](../../api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php#L144)

- Extensión retrocompatible del responder para links keyset en rutas anidadas.
  [`SearchResponder.php:58`](../../api/src/Shared/Infrastructure/Http/Responder/SearchResponder.php#L58)

**Tests**

- Existencia delegada, auditoría (1 evento, empty también, best-effort), 400/404 sin buscar.
  [`BankAccountSearcherTest.php:1`](../../api/tests/Unit/Backoffice/BankAccount/Application/BankAccountSearcherTest.php#L1)

- Endpoint end-to-end: projection, status label, vacío, 400/404, y traversal keyset multi-página.
  [`search.feature:9`](../../api/features/backoffice/bankaccount/search.feature#L9)
