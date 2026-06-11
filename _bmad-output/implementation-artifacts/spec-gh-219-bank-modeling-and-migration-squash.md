---
title: 'BankAccount→Bank por referencia de ID + FK schema-aware, y squash de migraciones bank (#219, #207)'
type: 'refactor'
created: '2026-06-11'
status: 'in-review'
baseline_commit: '1a6fa74dcc349b10ba74a693aeb0867e65f14e19'
context:
  - '{project-root}/docs/adr-bank-bankaccount-modeling.md'
  - '{project-root}/docs/rules/architecture.md'
  - '{project-root}/docs/rules/database.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `BankAccount` modela su relación con `Bank` como asociación Doctrine navegable (`#[ORM\ManyToOne] private Bank $bank`), lo que hace cruzar un grafo de objetos entre módulos del mismo bounded context — viola el invariante del ADR "ningún grafo de objetos cruza fronteras de módulo". Además el histórico de `bank` tiene 3 migraciones mergeadas (create + índices temporales + índices keyset/collation), y la del medio (`Version20260608165844`) tiene un `down()` sin `DROP INDEX IF EXISTS` (issue #207).

**Approach:** (1) Sustituir la asociación por una referencia por identidad `private string $bankId` (UUID v7 plano, `Uuid::ensure()` en el borde, sin VO nuevo), conservando la FK física vía un listener `postGenerateSchema` que la reinyecta en el schema en memoria (Doctrine ORM-unaware pero schema-aware → `db.diff` vacío, sin migración). (2) App **no en producción** (confirmado): consolidar las 3 migraciones de `bank` en 1 limpia, lo que cierra #207 de paso. `bank_account` ya es 1 migración y no se toca.

## Boundaries & Constraints

**Always:**
- Dominio puro: `Domain/` solo lleva metadata pasiva (`#[ORM]`, `#[Assert]`); el listener de schema vive en `Infrastructure/Persistence/Doctrine`.
- La FK física `bank_account.bank_id → bank.id` (`NOT DEFERRABLE`) se conserva idéntica en Postgres — el cambio es solo de mapping ORM.
- `make db.diff` debe dar **diff vacío** tras el refactor (gate del listener) y tras `db.reset` + consolidación.
- La migración consolidada porta **a mano** lo que `db.diff` no round-trippea: `ALTER TABLE bank ALTER COLUMN name_normalized/short_name TYPE … COLLATE "C"` (no está en metadata de entidad). `down()` idempotente con `DROP INDEX IF EXISTS`.
- `BankDeleter` y su doble-check (count 409 + catch FK TOCTOU) quedan funcionalmente intactos; sus tests siguen verdes.
- Squash documentado en el PR como **excepción consciente** a la regla de inmutabilidad de migraciones (autorizada solo por estar sin producción).

**Ask First:**
- Si apareciera un consumidor real de creación/lectura de `BankAccount` dentro de este scope (hoy no existe), antes de construir el puerto `bank-existe?` o la proyección JOIN.
- Si `make db.diff` no diera vacío tras el listener y exigiera tocar el esquema físico.

**Never:**
- No crear un VO `BankId` (ADR: UUID plano).
- No construir especulativamente `BankAccountCreator`/controller ni la proyección de lectura JOIN DQL: **no hay flujo de creación ni endpoint de lectura** → fuera de scope (YAGNI), diferido.
- No tocar `Version20260602120000` (bank_account) ni migraciones ajenas a `bank`.
- No introducir migración nueva para #219 (el esquema no cambia).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Crear agregado con bankId válido | `BankAccount::create(id, bankId, holderName, iban, …)` | Entidad con `bankId` guardado; IBAN/BIC canonicalizados | N/A |
| bankId con UUID malformado | `bankId = "not-a-uuid"` | `Uuid::ensure()` lanza antes de construir | `InvalidUuidException` (400 invalid-uuid) |
| `countByBankId` tras refactor | DQL sobre `ba.bankId = :bankId` | Mismo entero que antes (sin `IDENTITY()`) | N/A |
| `db.diff` con asociación retirada | Schema ORM ya no conoce la FK | Listener reinyecta FK `bank_account.bank_id→bank.id` → diff vacío | N/A |
| `db.reset` + migración consolidada | DB limpia | `doctrine:schema:validate` limpio, `db.diff` vacío | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php` -- entidad a refactorizar: asociación `$bank` (L24-27), `create()`/ctor, `getBank()` (L86).
- `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineBankAccountRepository.php` -- `countByBankId` usa `IDENTITY(ba.bank)` (L24) → pasar a `ba.bankId`.
- `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/` -- **nuevo** listener `postGenerateSchema` (FK injection).
- `api/src/Backoffice/Bank/Application/BankDeleter.php` -- consumidor de `countByBankId` (L36, L56); no cambia, solo verificar.
- `api/tests/Unit/Backoffice/Bank/Application/FakeBankAccountRepository.php` -- fake del puerto; sigue válido (interfaz intacta).
- `api/tests/DataFixtures/Fixtures/BankAccount.yaml` -- usa `@bank_*` (objeto) → pasar el **id** del banco.
- `api/migrations/2026/Version20260527115017.php` -- create bank → **reescribir** como migración consolidada (mantiene timestamp, queda antes de bank_account).
- `api/migrations/2026/Version20260608165844.php` -- índices temporales (#207) → **borrar** (absorbida).
- `api/migrations/2026/Version20260610195734.php` -- índices keyset + collation → **borrar** (absorbida).
- `api/src/Backoffice/Bank/Domain/Entity/Bank.php` -- declara los 4 `#[ORM\Index]` (db.diff los regenera); collation NO está aquí.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php` -- reemplazar `#[ORM\ManyToOne]/#[ORM\JoinColumn] private Bank $bank` por `#[ORM\Column(name: 'bank_id', type: Types::GUID)] private string $bankId`; `create()`/ctor reciben `string $bankId` con `Uuid::ensure($bankId)`; quitar `getBank()`, añadir `getBankId(): string`; quitar el `use` de `Bank`.
- [x] `api/.../Doctrine/DoctrineBankAccountRepository.php` -- DQL `WHERE IDENTITY(ba.bank) = :bankId` → `WHERE ba.bankId = :bankId`.
- [x] `api/.../BankAccount/Infrastructure/Persistence/Doctrine/BankAccountForeignKeySchemaListener.php` -- **nuevo**, `#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]`: en el schema en memoria, si la tabla `bank_account` no tiene la FK a `bank(id)`, añadirla (`bank_id → bank.id`, NOT DEFERRABLE). Idempotente.
- [x] `api/tests/Unit/Backoffice/BankAccount/Domain/Entity/BankAccountTest.php` -- **nuevo**, unit del agregado sin tocar `Bank`: create con `bankId`, `getBankId()`, canonicalización IBAN/BIC, rechazo de UUID malformado.
- [x] `api/tests/DataFixtures/Fixtures/BankAccount.yaml` -- pasar el id del banco (p.ej. `bankId: '@bank_jpmorgan_chase->getId()'`) en vez del objeto `@bank_*`.
- [x] `api/migrations/2026/Version20260527115017.php` -- reescribir como migración **consolidada** de `bank`: `up()` = CREATE TABLE bank + 3 índices únicos/FK media + 2 índices temporales + 2 índices keyset + 2 ALTER COLUMN … COLLATE "C"; `getDescription()` actualizado; docblock notando el squash pre-producción. `down()` idempotente (`DROP INDEX IF EXISTS`, revertir collation, drop FK+table).
- [x] Borrar `api/migrations/2026/Version20260608165844.php` y `Version20260610195734.php`.

**Acceptance Criteria:**
- Given el refactor aplicado, when `make db.diff`, then diff vacío (el listener conserva la FK).
- Given `make db.reset` con la migración consolidada, when `make db.validate`, then `doctrine:schema:validate` limpio y `db.diff` vacío.
- Given `make php.unit`, when corre la suite de Bank/BankAccount, then `BankDeleterTest` y el nuevo `BankAccountTest` pasan al 100%.
- Given el árbol final, when `grep -r getBank()` en `api/src`, then 0 referencias; `bank` ya no se importa en `BankAccount`.
- Given el bank schema final, when se compara con `main`, then es idéntico columna-a-columna, índice-a-índice y collation-a-collation (solo cambia el nº de archivos de migración).

## Design Notes

Listener (patrón a espejar de `api/tests/Behat/Doctrine/FixturesWriteListener.php`, que usa `#[AsDoctrineListener]`):

```php
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class BankAccountForeignKeySchemaListener
{
    public function __invoke(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        $table = $schema->getTable('bank_account');
        if ($table->hasForeignKey('FK_53A23E0A11C8FB41')) {
            return; // idempotente
        }
        $table->addForeignKeyConstraint('bank', ['bank_id'], ['id'], [], 'FK_53A23E0A11C8FB41');
    }
}
```

Verificar el nombre exacto de la FK (`FK_53A23E0A11C8FB41`, de `Version20260602120000`) para que `db.diff` no detecte rename. Si Doctrine no incluye `bank` en el schema en ese momento, capturar con guarda `$schema->hasTable('bank')`.

Consolidación: el esquema final de `bank` no cambia entre las 3 migraciones (solo se añaden índices/collation), así que la consolidada = unión de sus `up()`. Los 4 índices (`idx_bank_*`) están en `#[ORM\Index]` de la entidad → `make db.diff` los regeneraría, pero como reescribimos a mano partiendo del SQL verbatim no dependemos de eso; el único riesgo real es el `COLLATE "C"`, que se porta explícito.

## Verification

**Commands:**
- `make php.stan` -- (por cada fichero PHP cambiado) expected: 0 errores.
- `make db.reset` -- expected: drop→migrate(consolidada)→fixtures sin errores; ledger limpio.
- `make db.validate` -- expected: mapping y database in sync (`doctrine:schema:validate` limpio).
- `make db.diff` -- expected: "No changes detected" (vacío) — gate del listener + consolidación.
- `make php.unit c='--filter BankAccount'` y `--filter BankDeleter` -- expected: verde.
- `make php.quality` -- expected: limpio (al final de la tarea).
