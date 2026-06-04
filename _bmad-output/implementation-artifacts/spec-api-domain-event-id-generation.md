---
title: 'Generación interna de eventId en DomainEvent (UUID v7 vía symfony/uid en Domain)'
type: 'refactor'
created: '2026-06-04'
status: 'done'
baseline_commit: '693e63adad61aa1482591267176554ff0534d022'
worktree: '.claude/worktrees/api-domain-event-id-generation-vjpc'
branch: 'chore/api-domain-event-id-generation-vjpc'
context:
  - '{project-root}/api/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Los métodos de agregado (`Bank::create/rename/delete`) reciben `$createEventId`/`$updateEventId`/`$deleteEventId` por parámetro y los servicios de Application los acuñan con llamadas estáticas a `SymfonyUuidGenerator` (Infrastructure) — detalle técnico contaminando las firmas del dominio y acoplando Application a una clase concreta de Infrastructure.

**Approach:** Generar el `eventId` dentro del constructor de `DomainEvent` con una clase `Uuid` nueva en `Shared/Domain/Uuid/` que envuelve `symfony/uid` — **excepción de capas consciente y documentada**: symfony/uid es un componente hoja sin acoplamiento al framework, mejor para crear/validar uuids de distintas versiones, y la clase está pensada como futura base de value objects de uuid. Se elimina `$eventId` de toda la jerarquía de eventos y de las firmas de agregado. Seguro frente a Messenger: el `PhpSerializer` por defecto no re-invoca constructores al deserializar — el id se acuña una vez y viaja intacto al worker.

## Boundaries & Constraints

**Always:**
- La clase nueva vive en `api/src/Shared/Domain/Uuid/Uuid.php`, envuelve `Symfony\Component\Uid\Uuid` (v7), **NO implementa** la interfaz `UuidGenerator` y queda abierta (no `final`) como futura base abstracta de value objects de uuid.
- La excepción de capas (symfony/uid permitido en `Domain/`) se documenta en `docs/rules/architecture.md` como subsección hermana de la de metadatos pasivos, y se refleja en las líneas de excepción de `CLAUDE.md` y `api/CLAUDE.md`.
- Salida RFC 4122 v7 en minúsculas (`toRfc4122()`), 36 chars — compatible con la columna `domain_event.event_id` (VARCHAR 36) y `Assert\Uuid`.
- Rama nueva desde `main` vía `make worktree.create`; commit tipo `refactor(api)`.
- Tests dentro del worktree con su stack propio (`make app.dev` desde el worktree).

**Ask First:**
- Cualquier cambio que afecte a la generación de ids de ENTIDAD o requiera migración de BD.
- Si durante la implementación aparece una vía real de reconstrucción de eventos con eventId preexistente (no se encontró ninguna en la investigación).

**Never:**
- No tocar la generación de PKs de entidades (`Identifiable`, `SymfonyUuidGenerator` en `BankCreator` para `$id`, `DoctrineDomainEventStore` para el id de la fila `StoredDomainEvent`).
- No importar clases de `Erpify\Shared\Infrastructure\…` en `Domain/` — la excepción cubre solo el componente `symfony/uid`.
- No cambiar el serializer de Messenger ni el esquema de `domain_event`.
- No tocar `BankDeleter` más allá de eliminar el argumento (el PR #144 abierto lo modifica; minimizar superficie de conflicto).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Construcción de evento | `new BankDeletedDomainEvent($bankId)` | `eventId()` devuelve UUID v7 válido, único por instancia | N/A |
| Persistencia en store | `DoctrineDomainEventStore::append($event)` | Fila `domain_event` con `event_id` = `$event->eventId()` (sin cambios de contrato) | N/A |
| Tránsito Messenger | Evento despachado → worker | El worker recibe el MISMO eventId acuñado en construcción (PhpSerializer no re-construye) | N/A |
| Formato del generador | 1000 generaciones consecutivas | Todas únicas y regex v7 (`^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`) | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Domain/Uuid/` — destino de la clase nueva `Uuid`; el puerto `UuidGenerator` existente en ese dir queda intacto (PKs de entidad).
- `docs/rules/architecture.md:29-37` — patrón "Documented exception" (metadatos pasivos) a replicar para symfony/uid.
- `api/src/Shared/Domain/Event/DomainEvent.php` — base de la jerarquía; `$eventId` en posición 2 del constructor.
- Subclases: `AbstractBankChangedDomainEvent` (intermedia, reenvía al padre), `BankDeletedDomainEvent` (constructor propio), `BankCreated/BankUpdated` (heredan; sin cambios propios — verificar).
- `api/src/Backoffice/Bank/Domain/Entity/Bank.php` — `create/rename/delete` con parámetros `*EventId`.
- Application: `BankCreator.php:48-49`, `BankUpdater.php:36`, `BankDeleter.php:28` — acuñan con `SymfonyUuidGenerator::generate()`; `BankCreator` conserva uno para el `$id` de entidad.
- `DoctrineDomainEventStore.php` — único consumidor de `eventId()`; sin cambios.
- Tests afectados: `BankRealtimePublisherHandlerTest` (4 sitios `'event-id'`), `BankDeleteEventTest`, `DoctrineDomainEventStoreTest:39,49` (asierta eventId persistido), `BankCreateEventIdMatchesPersistedPkTest` (funcional).

## Tasks & Acceptance

**Execution:**
- [x] Crear worktree: `make worktree.create BRANCH=chore/api-domain-event-id-generation` y levantar su stack.
- [x] `api/src/Shared/Domain/Uuid/Uuid.php` — nuevo: clase NO final con `public static function generate(): string` que devuelve `SymfonyUuid::v7()->toRfc4122()`; docblock que recoja la excepción de capas y la intención de futura base de value objects; no implementa `UuidGenerator`.
- [x] `api/tests/Unit/Shared/Domain/Uuid/UuidTest.php` — nuevo: casos de la fila «Formato del generador» de la matriz.
- [x] `api/src/Shared/Domain/Event/DomainEvent.php` — quitar `$eventId` del constructor; `$this->eventId = Uuid::generate()` en el cuerpo; propiedad readonly no promovida.
- [x] `api/src/Backoffice/Bank/Domain/Event/AbstractBankChangedDomainEvent.php` + `BankDeletedDomainEvent.php` — quitar `$eventId` y su reenvío al padre.
- [x] `api/src/Backoffice/Bank/Domain/Entity/Bank.php` — quitar los tres parámetros `*EventId`.
- [x] `api/src/Backoffice/Bank/Application/BankCreator.php`, `BankUpdater.php`, `BankDeleter.php` — eliminar acuñación/paso de eventIds (y el import de `SymfonyUuidGenerator` donde quede sin uso).
- [x] Actualizar los 8 sitios de construcción en tests; `DoctrineDomainEventStoreTest` pasa a asertar `event_id` persistido `=== $event->eventId()`; revisar `BankCreateEventIdMatchesPersistedPkTest` (leerlo antes — su nombre sugiere acople PK↔eventId).
- [x] `docs/rules/architecture.md` — nueva subsección "Documented exception — symfony/uid in Domain" junto a la de metadatos pasivos (rationale: componente hoja, mejor creación/validación multi-versión, futura base de VOs).
- [x] `CLAUDE.md` + `api/CLAUDE.md` — ampliar la línea de excepción documentada existente para cubrir también symfony/uid en `Shared/Domain/Uuid/`.
- [x] `docs/deep-dive-api-shared-foundation.md` — actualizar la línea del constructor de `DomainEvent`.

**Acceptance Criteria:**
- Given un agregado `Bank`, when se llama a `create/rename/delete` sin argumentos de eventId, then cada evento registrado expone un `eventId()` UUID v7 válido y distinto.
- Given el grep `SymfonyUuidGenerator` en `api/src/Backoffice/Bank/Application/`, when el refactor termina, then solo queda el uso de `BankCreator` para el `$id` de entidad.
- Given `grep -r 'use Erpify\\Shared\\Infrastructure' api/src/Shared/Domain api/src/Backoffice/Bank/Domain`, then cero resultados (symfony/uid permitido por la excepción; Infrastructure propia no).
- Given `docs/rules/architecture.md`, when el refactor termina, then la excepción symfony/uid está documentada con rationale.
- Given la suite completa, when corre en el worktree, then verde sin tests borrados ni debilitados (el assert del store se adapta, no se elimina).

## Spec Change Log

## Verification

**Commands:**
- `make php.stan` — expected: 0 errores en ficheros tocados.
- `make php.unit` — expected: suite verde (unit + funcionales que apliquen).
- `make php.quality` — expected: sweep completo limpio (PHPMD sin baseline: cuidado con fakes anónimos readonly).
- `git merge-tree --write-tree HEAD origin/feat/api-bank-in-use-409-534c` — informativo: confirmar el conflicto previsible con PR #144 en `BankDeleter` y avisar al usuario.

## Suggested Review Order

**Decisión arquitectónica — excepción de capas symfony/uid**

- La excepción documentada que sanciona symfony/uid en Domain; léela antes que el código.
  [`architecture.md:39`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/docs/rules/architecture.md#L39)

- La clase que la ejerce: wrapper v7 no-final, futura base de VOs de uuid.
  [`Uuid.php:16`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/src/Shared/Domain/Uuid/Uuid.php#L16)

**Acuñación interna del eventId**

- El corazón del refactor: el constructor base acuña su propio id.
  [`DomainEvent.php:21`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/src/Shared/Domain/Event/DomainEvent.php#L21)

- Subclase intermedia: parámetro y reenvío eliminados.
  [`AbstractBankChangedDomainEvent.php:17`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/src/Backoffice/Bank/Domain/Event/AbstractBankChangedDomainEvent.php#L17)

- Evento de borrado: mismo recorte.
  [`BankDeletedDomainEvent.php:13`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/src/Backoffice/Bank/Domain/Event/BankDeletedDomainEvent.php#L13)

**Firmas de agregado y Application**

- `create/rename/delete` sin parámetros `*EventId` — el objetivo del refactor.
  [`Bank.php:76`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/src/Backoffice/Bank/Domain/Entity/Bank.php#L76)

- Conserva un único mint: el `$id` de ENTIDAD (PKs fuera de scope).
  [`BankCreator.php:48`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/src/Backoffice/Bank/Application/BankCreator.php#L48)

- Toque mínimo para minimizar conflicto con PR #144.
  [`BankDeleter.php:42`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/src/Backoffice/Bank/Application/BankDeleter.php#L42)

**Contrato de persistencia y transporte (tests)**

- Pin del supuesto clave: PhpSerializer no re-invoca el constructor; el id viaja intacto.
  [`DomainEventTest.php:18`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/tests/Unit/Shared/Domain/Event/DomainEventTest.php#L18)

- El `event_id` persistido es el acuñado por el evento — assert reforzado, no eliminado.
  [`DoctrineDomainEventStoreTest.php:52`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/tests/Unit/Shared/Infrastructure/Persistence/DoctrineDomainEventStoreTest.php#L52)

- Nuevo contrato funcional: eventId v7 válido y distinto del aggregate id.
  [`BankCreateEventIdMatchesPersistedPkTest.php:56`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/tests/Functional/Backoffice/Bank/BankCreateEventIdMatchesPersistedPkTest.php#L56)

- Formato del generador: 1000 v7 únicos en minúsculas.
  [`UuidTest.php:20`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/tests/Unit/Shared/Domain/Uuid/UuidTest.php#L20)

**Periféricos**

- Tests renombrados para desambiguar aggregateId vs eventId (hallazgo de revisión).
  [`BankTest.php:21`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/tests/Unit/Backoffice/Bank/Domain/Entity/BankTest.php#L21)

- 31 bloques de fixture sin el event-id posicional.
  [`Bank.yaml:1`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/tests/DataFixtures/Fixtures/Bank.yaml#L1)

- Entrada única y acotada (`ClassMustBeFinal` del `Uuid` no-final intencional).
  [`psalm-baseline.xml:171`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/api/tools/psalm/psalm-baseline.xml#L171)

- Excepción reflejada en las guías operativas.
  [`CLAUDE.md:74`](../../.claude/worktrees/api-domain-event-id-generation-vjpc/CLAUDE.md#L74)
