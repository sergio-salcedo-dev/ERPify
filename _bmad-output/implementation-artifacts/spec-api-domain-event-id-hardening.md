---
title: 'domain_event hardening: índice único event_id + append idempotente + minteo UUID unificado'
type: 'chore'
created: '2026-06-06'
status: 'done'
baseline_commit: '7b3413571e7c19ef56a6d37688990bb5f9ed204a'
context:
  - '{project-root}/docs/rules/database.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `domain_event` no tiene UNIQUE sobre `event_id`: si el mismo evento llega dos veces a `DoctrineDomainEventStore::append()` (persist síncrono + retry del bus), se duplican filas de auditoría y `event_id` pierde su valor de dedupe. Además conviven dos minteos UUID v7 solapados: `Shared/Domain/Uuid/Uuid::generate()` (eventos) y `SymfonyUuidGenerator::generate()` (PKs).

**Approach:** Migración que deduplica y añade índice único sobre `event_id`; el guardado del store pasa a `INSERT … ON CONFLICT (event_id) DO NOTHING`. Después, los PKs se mintean con `Uuid::generate()` y se retiran `SymfonyUuidGenerator` + puerto `UuidGenerator`. Un PR en rama fresca de `main`, dos commits: `fix(api)` y `refactor(api)`. Cierra los dos ítems diferidos del review de spec-api-domain-event-id-generation (2026-06-04).

## Boundaries & Constraints

**Always:**
- Migración vía `make db.diff`; editarla solo en esta rama; `down()` reversible (drop del índice).
- La migración deduplica antes del índice: conserva la fila más antigua por `event_id` (menor `id`, v7 ≈ orden temporal).
- `Validator::ensure()` sigue corriendo sobre `StoredDomainEvent` antes del insert.
- El store sigue asignando el row id en aplicación — ahora vía `Uuid::generate()`.
- Firmas de `DomainEventStore::append()` y `StoredDomainEventRepository::save()` intactas.

**Ask First:**
- Cualquier cambio de esquema de `domain_event` más allá del índice único.
- Dedupe insegura (duplicados con cuerpos distintos detectados) — parar y preguntar.

**Never:**
- No tocar `DomainEvent` ni el minteo de `eventId` (cerrado en PR #148).
- No introducir DI para el minteo UUID — sigue estático; consolidación, no rediseño.
- No usar try/catch de `UniqueConstraintViolationException` en `flush()` como idempotencia (cierra el EntityManager en mitad de la request).
- No tocar transportes ni config de Messenger.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Append nuevo | `event_id` inédito | 1 fila insertada | N/A |
| Doble append | mismo evento dos veces | 2ª llamada no-op; 1 fila; sin excepción | `ON CONFLICT DO NOTHING` |
| Eventos distintos, mismo aggregate | 2 `event_id` distintos | 2 filas (unicidad por `event_id`) | N/A |
| Migración con duplicados preexistentes | filas compartiendo `event_id` | dedupe conserva la más antigua; índice se crea | índice falla visible si quedan duplicados |
| Rollback de migración | `down()` | índice fuera, datos intactos | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Infrastructure/Persistence/DoctrineDomainEventStore.php` -- `append()`: minta row id (L33) + valida + delega
- `api/src/Shared/Infrastructure/Persistence/DoctrineStoredDomainEventRepository.php` -- `save()` = persist+flush (L27-28) → insert DBAL idempotente
- `api/src/Shared/Infrastructure/Persistence/Entity/StoredDomainEvent.php` -- entidad ORM; getters ya existen; añadir UniqueConstraint
- `api/src/Shared/Domain/Uuid/Uuid.php` -- minteo v7 (queda); `UuidGenerator.php` (puerto) y `api/src/Shared/Infrastructure/Uuid/SymfonyUuidGenerator.php` -- retirar
- `api/src/Backoffice/Bank/Application/BankCreator.php` L48 y `api/src/Shared/Media/Application/MediaRegistrar.php` L34 -- call sites de PK
- `api/migrations/2026/Version20260405190808.php` -- creó `domain_event` (referencia; inmutable)
- Tests: `api/tests/Unit/Shared/Infrastructure/Uuid/SymfonyUuidGeneratorTest.php` (migrar a `UuidTest`), `…/Persistence/DoctrineDomainEventStoreTest.php`, `api/tests/Functional/Backoffice/Bank/BankCreateEventIdMatchesPersistedPkTest.php` L41
- Docs: `docs/architecture-api.md` L74, `docs/rules/database.md` L47-48, `docs/deep-dive-api-shared-foundation.md` (dice "v4", stale), `docs-info/domain-events-and-messenger.md`

## Tasks & Acceptance

**Commit 1 — `fix(api)`: índice único + append idempotente:** (`6003a13`)
- [x] `StoredDomainEvent.php` -- añadir `#[ORM\UniqueConstraint(name: 'domain_event_event_id_uniq', fields: ['eventId'])]`
- [x] `make db.diff` → editar migración: `DELETE` dedupe (conservar menor `id` por `event_id`) antes del índice; `down()` reversible (`Version20260606113458.php`)
- [x] `DoctrineStoredDomainEventRepository.php` -- `save()` vía `Connection::executeStatement('INSERT … ON CONFLICT (event_id) DO NOTHING')` con tipos Doctrine (`body` JSON, `occurredOn` datetime_immutable)
- [x] `api/tests/Functional/Shared/Persistence/DomainEventStoreIdempotencyTest.php` -- nuevo: doble `append()` → 1 fila; patrón begin/rollback existente
- [x] `make db.migrate` + `make db.validate` en el stack del worktree

**Commit 2 — `refactor(api)`: minteo UUID unificado:** (`3b0ba6f`)
- [x] `DoctrineDomainEventStore.php` L33, `BankCreator.php` L48, `MediaRegistrar.php` L34 -- `SymfonyUuidGenerator::generate()` → `Uuid::generate()`
- [x] Borrar `SymfonyUuidGenerator.php` y `UuidGenerator.php`; revisar docstring de `Identifiable` por si nombra al generador
- [x] Mover tests → `api/tests/Unit/Shared/Domain/Uuid/UuidTest.php` (mismas 3 aserciones); `BankCreateEventIdMatchesPersistedPkTest` usa `Uuid::generate()`
- [x] Docs -- actualizar menciones del generador (3 ficheros del Code Map; corregir "v4" stale) y documentar dedupe por `event_id` en `docs-info/domain-events-and-messenger.md`
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` -- eliminar la sección "Deferred from: spec-api-domain-event-id-generation review (2026-06-04)" (hecho en la copia del worktree; va en el PR)

**Acceptance Criteria:**
- Given la migración aplicada, when se inspecciona el esquema, then existe `domain_event_event_id_uniq` y `make db.validate` pasa.
- Given el mismo evento apendizado dos veces, when se consulta por su `event_id`, then hay exactamente 1 fila y no escapó ninguna excepción.
- Given el commit 2, when se busca `SymfonyUuidGenerator|UuidGenerator` en `api/src` y `api/tests`, then 0 referencias; en `docs/` solo pueden quedar la clase third-party `Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator` (ajena al puerto retirado, preexistente) y la nota histórica de retirada en `deep-dive-api-shared-foundation.md`.
- Given `make php.unit`, `make php.stan`, `make php.quality`, then verdes.

## Spec Change Log

- **2026-06-06 — review pass 1 (Acceptance Auditor):** el AC/comando de verificación "0 referencias a `SymfonyUuidGenerator|UuidGenerator` en `api/`, `docs/`, `docs-info/`" era literalmente insatisfacible: 2 hits preexistentes nombran la clase third-party `Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator` y 1 hit es la propia nota de retirada. Se acotó el AC y el grep a `api/src`+`api/tests` con caveat de docs. Estado malo evitado: un AC imposible de cumplir que forzaría tocar líneas de docs ajenas al scope. KEEP: el código no cambió — la implementación era correcta; solo se corrigió el contrato de verificación. Los patches del review (guard `abortIf` de cuerpos divergentes en la migración, binding `Types::GUID` del `id`, test reforzado con aserción tras cada append, docblock de contrato en `save()`) van en el commit `6aa2517`.

- **2026-06-06 — simplificación de la migración dirigida por Sergio:** producción aún no tiene eventos y en dev/test la tabla es desechable, así que el dedupe lossless (guard `abortIf` de cuerpos divergentes + `DELETE` conservando la fila más antigua por `event_id`) se sustituye por un `DELETE FROM domain_event` antes del índice — migración explícitamente destructiva, autorizada por el owner. Supersede el boundary "la migración deduplica antes del índice" y la fila "Migración con duplicados preexistentes" de la matriz I/O; el gate Ask-First de dedupe insegura queda sin objeto. `down()` sigue siendo el drop del índice.

- **2026-06-06 — ampliación de scope dirigida por Sergio:** `Uuid` pasa a `abstract class` ya en este PR (era "kept non-final" para el futuro). Decisión de diseño acordada: `generate()` se mantiene como mint estático que devuelve `string` (sin late static binding ni `generate(): static`); los UUID value objects futuros serán clases hijas explícitas que extienden la base. Sin impacto en call sites: las llamadas estáticas sobre una clase abstracta son legales y la clase nunca se instancia (verificado — los `new Uuid()` de `ValidatorTest` son la constraint de Symfony Validator).

## Design Notes

- `ON CONFLICT` atómico en DBAL y **no** try/catch del `flush()`: una violación en flush cierra el EntityManager y rompería el resto de la request (`PersistDomainEventMiddleware` corre síncrono pre-respuesta).
- `ON CONFLICT DO NOTHING` no aborta la transacción envolvente → el test funcional puede usar begin/rollback.
- Worktree aislado: `make worktree.create BRANCH=chore/api-domain-event-id-hardening START=true` — stack propio para `db.diff`/`db.migrate`/funcionales.

## Verification

**Commands:**
- `make db.migrate && make db.validate` -- expected: migración aplica; esquema sincronizado
- `make php.unit c='--filter "UuidTest|DoctrineDomainEventStoreTest|DomainEventStoreIdempotencyTest|BankCreateEventIdMatchesPersistedPk"'` -- expected: verde
- `make php.stan` y `make php.quality` -- expected: 0 errores / sweep verde
- `grep -rn 'SymfonyUuidGenerator\|UuidGenerator' api/src api/tests` -- expected: sin resultados (en `docs/` solo la clase third-party `Symfony\Bridge\…\UuidGenerator` y la nota histórica de retirada)

## Suggested Review Order

**Idempotencia del append (núcleo del cambio)**

- Punto de entrada: el insert DBAL atómico con `ON CONFLICT (event_id) DO NOTHING` que sustituye a `persist`+`flush`
  [`DoctrineStoredDomainEventRepository.php:40`](../../api/src/Shared/Infrastructure/Persistence/DoctrineStoredDomainEventRepository.php#L40)

- El contrato no-obvio: la entidad nunca entra al EntityManager; por qué no try/catch del flush
  [`DoctrineStoredDomainEventRepository.php:26`](../../api/src/Shared/Infrastructure/Persistence/DoctrineStoredDomainEventRepository.php#L26)

**Migración: guard + dedupe + índice único**

- Puerta Ask-First operacionalizada: aborta si hay duplicados con cuerpos divergentes (destructivo solo sobre copias idénticas)
  [`Version20260606113458.php:27`](../../api/migrations/2026/Version20260606113458.php#L27)

- Dedupe (conserva menor `id`; lossless garantizado por el guard) y el índice único
  [`Version20260606113458.php:34`](../../api/migrations/2026/Version20260606113458.php#L34)

- La fuente del diff de esquema: atributo `UniqueConstraint` en la entidad
  [`StoredDomainEvent.php:16`](../../api/src/Shared/Infrastructure/Persistence/Entity/StoredDomainEvent.php#L16)

**Minteo UUID unificado (commit 2)**

- El único punto de minteo v7 que queda en el código
  [`Uuid.php:18`](../../api/src/Shared/Domain/Uuid/Uuid.php#L18)

- Los tres call sites migrados (el puerto `UuidGenerator` y `SymfonyUuidGenerator` se borran)
  [`DoctrineDomainEventStore.php:33`](../../api/src/Shared/Infrastructure/Persistence/DoctrineDomainEventStore.php#L33)
  [`BankCreator.php:48`](../../api/src/Backoffice/Bank/Application/BankCreator.php#L48)
  [`MediaRegistrar.php:34`](../../api/src/Shared/Media/Application/MediaRegistrar.php#L34)

**Periféricos: tests y docs**

- Test funcional: 1 fila tras el primer append Y tras el segundo (distingue no-op de doble fallo)
  [`DomainEventStoreIdempotencyTest.php:50`](../../api/tests/Functional/Shared/Persistence/DomainEventStoreIdempotencyTest.php#L50)

- Tests del generador movidos a dominio (mismas 3 aserciones)
  [`UuidTest.php:1`](../../api/tests/Unit/Shared/Domain/Uuid/UuidTest.php#L1)

- Semántica de dedupe documentada junto al modelo at-least-once
  [`domain-events-and-messenger.md:14`](../../docs-info/domain-events-and-messenger.md#L14)
