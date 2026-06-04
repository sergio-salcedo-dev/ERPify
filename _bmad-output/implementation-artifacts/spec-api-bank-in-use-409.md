---
title: 'API: rechazar el borrado de un banco con cuentas asociadas (409 bank-in-use)'
type: 'feature'
created: '2026-06-04'
status: 'done'
baseline_commit: '154735b'
context:
  - '{project-root}/docs/api-error-contract.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `BankDeleter` borra bancos referenciados por `bank_account` sin comprobarlo (la FK existe desde `Version20260602120000`; el check no). El contrato UX de listas exige que la PWA distinga obsolescencia (404) de integridad (409) en el confirm de borrado, y hoy la API no emite ese 409.

**Approach:** Puerto nuevo `BankAccountRepository` (conteo por banco) + check en `BankDeleter` que lanza `BankInUseException` — marker `Conflict` existente, sin marker nuevo — **antes** de mutar nada ni despachar eventos. Pipeline RFC 9457 intacto.

## Boundaries & Constraints

**Always:**
- Dominio puro: el puerto vive en `BankAccount/Domain` sin imports de framework/ORM.
- `type: 'bank-in-use'`, `title` legible con recuento y `context: ['bankId', 'accountCount']` — **el string del type es contrato**: lo consumirá el spec PWA (`deferred-work.md`); el title/detail se renderizará verbatim a usuario final.
- El fallo es total: ni `delete()` en la entidad, ni `remove()`, ni dispatch de eventos de dominio.
- `make php.stan` en cada PHP tocado; `make php.quality` al cierre.

**Ask First:**
- Si el escenario Behat exigiera steps nuevos de contexto (preferir fixtures Alice existentes).

**Never:**
- Superficie HTTP/Application de BankAccount (CRUD de cuentas) — solo el puerto de conteo.
- Cascada o soft-delete de cuentas; marker nuevo; editar migraciones mergeadas (`bank_id` ya está indexada — `IDX_53A23E0A11C8FB41`).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Banco en uso | DELETE banco con ≥1 cuenta | 409 `application/problem+json`, `type: bank-in-use`, title con recuento, `bankId`+`accountCount` en extensiones; banco y cuenta intactos; cero eventos despachados | N/A |
| Banco libre | DELETE banco sin cuentas | 204 — conducta actual byte a byte | N/A |
| Banco inexistente | DELETE id desconocido | 404 `bank-not-found` — sin cambios (el check de uso va tras `find`) | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Backoffice/Bank/Application/BankDeleter.php:24-38` — find → delete → remove → dispatch; el check se inserta tras `find`.
- `api/src/Backoffice/Bank/Domain/Exception/BankNotFoundException.php` — patrón a copiar (type explícito, title sprintf, context).
- `api/src/Shared/Domain/Exception/Conflict.php` — marker → 409 vía `ProblemDetailsFactory::MARKER_STATUS_MAP` (`api/src/Shared/Application/Problem/ProblemDetailsFactory.php:111-129`).
- `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php:24-25` — ManyToOne a Bank (`bank_id`); el módulo no tiene Application/Infrastructure aún.
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` — patrón `#[AsAlias]` a replicar.
- `api/features/backoffice/bank/delete.feature` — escenarios 204/404 a extender; fixtures: `api/tests/DataFixtures/Fixtures/BankAccount.yaml`.
- `api/tests/Unit/Shared/Application/Problem/ErrorContractGateTest.php` — no aplica (sin marker nuevo); no tocar.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Backoffice/BankAccount/Domain/Repository/BankAccountRepository.php` — NUEVO puerto: `countByBankId(string $bankId): int`.
- [x] `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineBankAccountRepository.php` — NUEVO: COUNT por `bank_id`, `#[AsAlias]`, espejo de `DoctrineBankRepository`.
- [x] `api/src/Backoffice/Bank/Domain/Exception/BankInUseException.php` — NUEVO: `extends DomainException implements Conflict`, factoría estática con id y recuento.
- [x] `api/src/Backoffice/Bank/Application/BankDeleter.php` — inyectar el puerto; `count > 0` → lanzar antes de mutar.
- [x] `api/tests/Unit/Backoffice/Bank/Application/BankDeleterTest.php` — NUEVO unit con fakes in-memory: borra sin cuentas; lanza con cuentas; cero eventos al fallar.
- [x] `api/features/backoffice/bank/delete.feature` — escenario 409 espejo del 404: sembrar banco+cuenta, assert `type`, `status`, extensiones, header problem+json.
- [x] `docs/architecture-api.md` — anotar la invariante (DELETE de banco con cuentas → 409 `bank-in-use`); `docs/api-error-contract.md` NO cambia.

**Acceptance Criteria:**
- Given Behat, when DELETE de un banco con cuenta asociada, then 409 `bank-in-use` con headers problem+json y ni banco ni cuenta mutan.
- Given los escenarios existentes de `delete.feature`, when corren contra estos cambios, then pasan sin tocar asserts.
- Given el fallo por cuentas, when se inspecciona el bus, then ningún `BankDeletedDomainEvent` fue despachado.

## Spec Change Log

## Verification

**Commands:**
- `make php.unit c='--filter BankDeleterTest'` — expected: verde (3 casos).
- `make php.behat` — expected: `delete.feature` verde incluido el 409.
- `make php.stan` + `make php.quality` — expected: limpio.

## Suggested Review Order

**La invariante en el caso de uso (núcleo del cambio)**

- El check de uso va tras `find`, antes de toda mutación — el fallo es total por construcción
  [`BankDeleter.php:37`](../../.claude/worktrees/api-bank-in-use-409-534c/api/src/Backoffice/Bank/Application/BankDeleter.php#L37)
- Cierre TOCTOU: la FK rechaza el DELETE en el flush y el catch lo traduce al 409 prometido
  [`BankDeleter.php:50`](../../.claude/worktrees/api-bank-in-use-409-534c/api/src/Backoffice/Bank/Application/BankDeleter.php#L50)
- `max(1, recount)`: la violación de FK prueba ≥1 cuenta aunque la doble carrera inversa devuelva 0
  [`BankDeleter.php:59`](../../.claude/worktrees/api-bank-in-use-409-534c/api/src/Backoffice/Bank/Application/BankDeleter.php#L59)

**Excepción y contrato 409**

- `type: 'bank-in-use'` + recuento en title verbatim — el string es contrato para el spec PWA
  [`BankInUseException.php:12`](../../.claude/worktrees/api-bank-in-use-409-534c/api/src/Backoffice/Bank/Domain/Exception/BankInUseException.php#L12)
- La invariante documentada donde vive el resto del contrato de dominio
  [`architecture-api.md:97`](../../.claude/worktrees/api-bank-in-use-409-534c/docs/architecture-api.md#L97)

**Puerto y adaptador (primer Infrastructure de BankAccount)**

- Puerto mínimo de conteo — dominio puro, sin superficie CRUD especulativa
  [`BankAccountRepository.php:13`](../../.claude/worktrees/api-bank-in-use-409-534c/api/src/Backoffice/BankAccount/Domain/Repository/BankAccountRepository.php#L13)
- COUNT parametrizado por `IDENTITY(ba.bank)`, cableado con `#[AsAlias]` como su espejo de Bank
  [`DoctrineBankAccountRepository.php:16`](../../.claude/worktrees/api-bank-in-use-409-534c/api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineBankAccountRepository.php#L16)

**Tests (periferia)**

- Escenario Behat 409 espejo del 404: contrato HTTP completo contra fixtures deterministas
  [`delete.feature:30`](../../.claude/worktrees/api-bank-in-use-409-534c/api/features/backoffice/bank/delete.feature#L30)
- El mapeo FK→409 pinneado con una violación DBAL real (SQLSTATE 23503, stub nombrado)
  [`BankDeleterTest.php:79`](../../.claude/worktrees/api-bank-in-use-409-534c/api/tests/Unit/Backoffice/Bank/Application/BankDeleterTest.php#L79)
- No-mutación y cero eventos al rechazar — el contrato "fallo total" con spies nombrados
  [`BankDeleterTest.php:63`](../../.claude/worktrees/api-bank-in-use-409-534c/api/tests/Unit/Backoffice/Bank/Application/BankDeleterTest.php#L63)
