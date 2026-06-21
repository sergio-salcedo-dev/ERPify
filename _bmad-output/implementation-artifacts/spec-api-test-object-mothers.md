---
title: 'ObjectMother para tests Bank/BankAccount (api)'
type: 'refactor'
created: '2026-06-21'
status: 'in-progress'
baseline_commit: '2123ae71'
context:
  - '{project-root}/docs/project-context.md'
  - '{project-root}/api/tests/Unit/Shared/Search/Domain/Mother/FilterMother.php'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** La construcción de datos de prueba en los tests unitarios de `Bank`/`BankAccount` está duplicada y dispersa: `Bank::create(...)` en 26 call-sites / 14 ficheros, el UUID canónico de Bank en dos familias incoherentes (`0190a1b2-…a5b` ×6 vs `0190e9c2-…1a2c` ×2), y `BankSnapshot`/eventos/IBAN/holder repetidos como literales. El ruido de *arrange* oculta el comportamiento bajo prueba.

**Approach:** Extender el patrón ObjectMother que YA existe en el repo (`Shared/Search/.../Mother/`) a `Bank`/`BankAccount` como **data-builders deterministas** (sin faker): una factory estática por tipo de dominio, defaults canónicos + overrides por parámetro, en un subpaquete `Mother/` que espeja `src/`. Adopción **selectiva**: solo donde reduce ruido sin tapar el comportamiento que el test verifica.

## Boundaries & Constraints

**Always:**
- Mothers `final`, `declare(strict_types=1)`, `public static`, retornan el tipo de producción real, defaults + overrides (estilo `FilterMother`), tipado PHPStan `level: max`, sin atributos PHPUnit, namespace = directorio bajo `Erpify\Tests\Unit\…\Mother`.
- Enrutan SIEMPRE por el factory real (`Bank::create`, `BankAccount::create`). `BankAccountMother` es **passthrough crudo** (no normaliza IBAN/BIC ni valida id) para no tapar la canonicalización que `BankAccountTest` verifica.
- AAA y una-conducta-por-test intactos: cambia el *arrange*, no los asserts.
- Estandarizar el id default de Bank a `0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b` (actualizar los 2 ficheros `e9c2` entra en scope; el valor es arbitrario).

**Ask First:**
- Ampliar a `api/tests/Functional/**` (8 ficheros más usan `Bank::create` para *seeding*) — diferido a fast-follow salvo petición explícita.
- Crear un Mother no listado aquí, o introducir faker.

**Never:**
- No tocar ni reemplazar los fakes existentes (`InMemoryBankRepository`, `RecordingEventBus`, `InMemoryBankAccountRepository`, `InlineTransactionStubs`, `*MessageBus`) — son otro patrón, fuera de scope.
- No cambiar producción (`api/src/**`) — solo `api/tests/**`.
- No crear `StoredObjectMother` (1 consumidor → YAGNI); no introducir un `*ModuleUnitTestCase` con `shouldSave()/shouldPublishDomainEvent()` (choca con "fakes sobre mocks").
- No "molerizar" los tests de serialización (`fromPrimitives`/`toPrimitives` round-trips, `StoredObjectTest`, casos primitivos de `BankSnapshotTest`): mantienen construcción inline.

</frozen-after-approval>

## Code Map

- `tests/Unit/Shared/Search/Domain/Mother/FilterMother.php` -- convención de referencia.
- `src/Backoffice/Bank/Domain/Entity/Bank.php` -- `create`/`rename`/`delete`; emite eventos.
- `src/Backoffice/Bank/Domain/Event/{BankCreated,BankUpdated,BankDeleted}DomainEvent.php`, `BankSnapshot.php` -- firmas a envolver.
- `src/Backoffice/BankAccount/Domain/Entity/BankAccount.php` -- `create(id,bankId,holderName,iban,?bic,?alias,Currency,BankAccountStatus)`; canonicaliza/valida.
- Consumidores Bank (9): `tests/Unit/Backoffice/Bank/` → `Domain/Entity/{BankTest,BankDeleteEventTest}`, `Domain/Event/{BankCreated,BankUpdated,BankDeleted}DomainEventTest`, `Application/{BankDetailFinder,BankSearcher,BankAccountCountEnricher,Projection/BankCountProjector}Test`, `Infrastructure/Messenger/{RefreshRealtimeOnBankChanged,SendEmailOnBankChanged}Test`.
- Consumidores BankAccount (2): `tests/Unit/Backoffice/BankAccount/{Domain/Entity/BankAccountTest,Application/BankAccountSearcherTest}`.

## Tasks & Acceptance

**Execution:**
- [ ] `tests/Unit/Backoffice/Bank/Domain/Entity/Mother/BankMother.php` -- crear: `const DEFAULT_ID`; `create(id,name,shortName,?StoredObject)`, `drained(...)` (create + `pullDomainEvents`).
- [ ] `tests/Unit/Backoffice/Bank/Domain/Event/Mother/BankSnapshotMother.php` -- crear: `create(...8 params defaulted...)` cubre 4-arg y 8-arg.
- [ ] `tests/Unit/Backoffice/Bank/Domain/Event/Mother/{BankCreated,BankUpdated,BankDeleted}DomainEventMother.php` -- crear: `create(aggregateId,[snapshot],eventId,occurredOn)` con defaults; Deleted sin snapshot.
- [ ] `tests/Unit/Backoffice/BankAccount/Domain/Entity/Mother/BankAccountMother.php` -- crear: `const DEFAULT_ID,DEFAULT_BANK_ID`; `create(...)` passthrough crudo.
- [ ] Consumidores Bank (Code Map) -- reemplazar construcciones inline por Mothers; borrar consts UUID locales y helpers privados ad-hoc (`bank()`, `snapshot()`, `event()`, `createdEvent()`, `bankCreatedEvent()`…); dejar inline los casos de serialización.
- [ ] Consumidores BankAccount -- `BankAccountTest` usa `BankAccountMother::create(...)` con overrides crudos (IBAN/BIC/bankId) preservando los asserts de canonicalización; `BankAccountSearcherTest` usa Mother + consts compartidas.

**Acceptance Criteria:**
- Given la suite unitaria, when `make php.unit`, then 100% verde sin cambios de aserción ni de comportamiento.
- Given `api/tests/**` modificados, when `make php.quality`, then PHPStan/PHPMD/PHPCS/CS-Fixer/Rector verdes (Mothers incluidos).
- Given los ficheros unitarios del Code Map, when se busca `Bank::create(`/`BankAccount::create(`, then no hay construcción inline salvo los casos de serialización excluidos, y `0190e9c2-…1a2c` ya no se usa como id de Bank.
- Given un test de canonicalización IBAN/BIC o de `InvalidUuidException` por bankId malformado, when usa `BankAccountMother`, then el aserto sigue pasando por el factory real (el Mother no normaliza).

## Spec Change Log

## Design Notes

Colocación: el Mother espeja la ruta de `src/` del tipo que construye (`Bank` → `Domain/Entity/Mother/`, eventos → `Domain/Event/Mother/`), igual que `FilterMother` (Domain) vive en `Domain/Mother/`. Consumidores de otras capas importan vía `use`. Una sola factory con params defaulted cubre todas las variaciones (2/4/8-arg, generated-id) por override — sin proliferación de métodos (Regla de Tres).

```php
final class BankMother
{
    public const string DEFAULT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public static function create(
        string $id = self::DEFAULT_ID,
        string $name = 'Acme Savings',
        string $shortName = 'ACME',
        ?StoredObject $storedObject = null,
    ): Bank {
        return Bank::create($id, $name, $shortName, null, $storedObject);
    }

    public static function drained(string $id = self::DEFAULT_ID): Bank
    {
        $bank = self::create($id);
        $bank->pullDomainEvents();

        return $bank;
    }
}
```

## Verification

**Commands:**
- `make php.unit` -- expected: suite unitaria 100% verde.
- `make php.stan` -- expected: 0 errores en ficheros nuevos/tocados.
- `make php.quality` -- expected: lint sweep completo verde.
