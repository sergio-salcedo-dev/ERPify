---
title: 'Shared · EventBus (puerto/adaptador) + outbox transaccional para domain events'
type: 'feature'
created: '2026-06-13'
status: 'ready-for-dev'
baseline_commit: '290c9f8'
context:
  - '{project-root}/docs/project-context.md'
  - '{project-root}/docs/architecture-api.md'
  - '{project-root}/docs/adr/bank-bankaccount-modeling.md'
  - '{project-root}/api/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Hay una **fuga dual-write**. En los casos de uso de Bank el agregado se commitea en `save()`/`remove()` (flush implícito del repo, `DoctrineBankRepository.php:64-65`) y **después**, en un bucle, se despachan los domain events (`BankCreator.php:59-61`, `BankUpdater.php:44`, `BankDeleter.php:61-63`), que escriben en `domain_event` (`DoctrineStoredDomainEventRepository.php:37`) y en `messenger_messages` (transporte Doctrine) en **commits separados**. Si el proceso muere entre el commit del agregado y el despacho, el banco existe pero su evento nunca se persistió ni encoló → **evento perdido**. La tabla `domain_event` de hoy es un *audit log a posteriori*, no un outbox. Además la capa `Application/` se acopla a `Symfony\Component\Messenger\MessageBusInterface` directamente.

**Approach:** Introducir un puerto **`EventBus`** (convención Codely) en `Shared/Domain/Bus/Event/` con un adaptador único **`SymfonyMessengerEventBus`**, y **envolver `save`/`remove` + `publish(...)` en una sola transacción** (`EntityManager::wrapInTransaction()`) en cada caso de uso, de modo que la fila del agregado, `domain_event` y `messenger_messages` commiteen **atómicamente**. El **transporte Doctrine sigue siendo el outbox** (su relay ya hace `SELECT … FOR UPDATE SKIP LOCKED`). Un broker externo (RabbitMQ/SNS/SQS) quedaría **aguas abajo** del outbox, vía un relay futuro — fuera de alcance. CommandBus/QueryBus diferidos a #263.

## Boundaries & Constraints

**Always:**
- **Puerto `EventBus`** en `api/src/Shared/Domain/Bus/Event/EventBus.php`: `public function publish(DomainEvent ...$events): void;`. PHP puro — solo importa `DomainEvent` (no rompe la pureza del dominio; no importa framework).
- **Call-site variádico**: los 3 casos de uso llaman `$this->eventBus->publish(...$bank->pullDomainEvents())` (sin `foreach`). El bucle de dispatch vive en el adaptador, no en cada caso de uso.
- **Adaptador único** `api/src/Shared/Infrastructure/Bus/Event/SymfonyMessengerEventBus.php`: inyecta `MessageBusInterface`, hace `$this->bus->dispatch($event)` por cada evento; `#[AsAlias(EventBus::class)]`. **Sin** `SymfonyMessengerSyncEventBus`/`SymfonyMessengerAsyncEventBus` — sync-vs-async es un asunto de `messenger.yaml routing:` (los 3 eventos de Bank ya enrutan a `async`).
- **Atomicidad** vía `EntityManager::wrapInTransaction()` inline en cada caso de uso (EM en `Application/` está sancionado por `api/CLAUDE.md`). Dentro del wrap: `save()`/`remove()` conservan su flush (dentro de una tx abierta el flush **no** commitea), y el `INSERT` del transporte Doctrine + el `INSERT … ON CONFLICT` de `domain_event` corren en la **conexión por defecto** → participan en la misma tx.
- **Orden dentro del wrap**: capturar los eventos (`pullDomainEvents()`) → `save()`/`remove()` → `publish(...)`. En DELETE los eventos se capturan **antes** de `remove()` (el agregado debe estar intacto, patrón actual `BankDeleter.php:45`).
- `PersistDomainEventMiddleware` sigue corriendo **antes** de `SendMessageMiddleware` (su append a `domain_event` queda dentro de la tx).
- Contrato HTTP **sin cambios**: RFC 9457 intacto, Behat de Bank 100% verde (POST/PUT/DELETE).

**Ask First (decididas):**
- **FK del delete + transacción única** → tratado en el **comentario de #249** (rama del delete-guard, Story 2.5). Al envolver `remove + publish`, una violación de FK **aborta toda la tx** en PostgreSQL: el recount de `BankDeleter.php:56` reventaría con `current transaction is aborted`, y `wrapInTransaction` hace `EntityManager::close()` al capturar. Decisión a aplicar al implementar: **(1)** catch + recount **fuera** del wrap (con `resetManager()`/manager fresco) — preserva el 409; **(2)** aceptar el downgrade (la rarísima carrera TOCTOU → 500 en vez de 409). Recomendado (1).
- **Bus dedicado `event.bus`** y **CommandBus/QueryBus (CQRS)** → diferidos a **#263**. Por ahora el adaptador publica sobre el bus por defecto.

**Never:**
- No construir relay/forwarder a broker externo ahora (YAGNI hasta adoptarlo).
- No repuntar el transporte del **productor** a un broker (RabbitMQ/SNS/SQS): un `send` a un broker es una llamada de red que **no** se une a la tx de la DB → reintroduce el dual-write. El broker va siempre detrás del outbox.
- No `SymfonyMessengerSyncEventBus`/`SymfonyMessengerAsyncEventBus`.
- No migraciones: `domain_event` y `messenger_messages` ya existen.
- No cambiar el orden del middleware ni el contrato de error.
- No introducir CommandBus/QueryBus aquí (#263).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Create OK | `POST /backoffice/banks` | `bank` + `domain_event` + `messenger_messages` commitean en **1 tx**; worker entrega `BankCreated` async | N/A |
| Update OK | `PUT …/banks/{id}` | idem con `BankUpdated` en 1 tx | N/A |
| Delete OK (sin cuentas) | `DELETE …/banks/{id}` | `remove` + `BankDeleted` en 1 tx | N/A |
| Fallo al publicar dentro de la tx | `dispatch` lanza | **rollback total**: ni `bank` ni evento → sin estado a medias | excepción propaga (5xx RFC 9457), pero **sin agregado huérfano** |
| Crash entre commit y dispatch | (el bug actual) | ya **no** existe la ventana: o todo commitea o nada | N/A |
| Delete con FK TOCTOU | cuenta insertada en la ventana del count-check | tx abortada; recount **fuera** del wrap | `409 bank-in-use` (opción 1) / `500` (opción 2) — ver #249 |
| Entorno test (`in-memory://?serialize=true`) | publish en tests | sin fila en DB; el wrap funciona; el serializer valida el payload | N/A |
| `publish()` con 0 eventos | agregado sin eventos | no-op; solo el `save`/`remove` commitea | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Backoffice/Bank/Application/BankCreator.php` -- MODIFICAR: `MessageBusInterface` → `EventBus`; inyectar `EntityManagerInterface`; `wrapInTransaction(save + publish(...))`.
- `api/src/Backoffice/Bank/Application/BankUpdater.php` -- MODIFICAR: idem.
- `api/src/Backoffice/Bank/Application/BankDeleter.php` -- MODIFICAR: idem + decisión FK (ver #249); capturar eventos antes de `remove`.
- `api/src/Shared/Domain/Bus/Event/EventBus.php` -- NUEVO: puerto `publish(DomainEvent ...$events): void`.
- `api/src/Shared/Infrastructure/Bus/Event/SymfonyMessengerEventBus.php` -- NUEVO: adaptador `#[AsAlias(EventBus::class)]`, dispatch por evento.
- `api/src/Shared/Infrastructure/Messenger/PersistDomainEventMiddleware.php` -- VERIFICAR: corre antes de `SendMessageMiddleware`; el append entra en la tx.
- `api/src/Shared/Infrastructure/Persistence/DoctrineStoredDomainEventRepository.php` -- REFERENCIA: `INSERT … ON CONFLICT` por la conexión por defecto → participa en la tx.
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` -- REFERENCIA: `save()`/`remove()` conservan flush (`:64-65`, `:71-72`); el comentario `:61-63` (transaction-boundary "out of scope") queda resuelto en el caso de uso.
- `api/config/packages/messenger.yaml` -- REFERENCIA: routing `async` sin cambios; bus dedicado `event.bus` diferido a #263.
- `api/src/Shared/Domain/Aggregate/AggregateRoot.php` -- REFERENCIA: `pullDomainEvents()`.
- `api/tests/Unit/Backoffice/Bank/Application/RecordingMessageBus.php` -- REEMPLAZAR por un `RecordingEventBus` (fake del puerto `EventBus`).

## Tasks & Acceptance

**Execution:**
- [ ] `api/src/Shared/Domain/Bus/Event/EventBus.php` (NUEVO) -- puerto `publish(DomainEvent ...$events): void`.
- [ ] `api/src/Shared/Infrastructure/Bus/Event/SymfonyMessengerEventBus.php` (NUEVO) -- adaptador `#[AsAlias(EventBus::class)]`; `foreach ($events as $e) $this->bus->dispatch($e);`.
- [ ] `api/src/Backoffice/Bank/Application/BankCreator.php` (MODIFICAR) -- `EventBus` + `EntityManagerInterface`; `wrapInTransaction(fn) { save; publish(...pullDomainEvents) }`.
- [ ] `api/src/Backoffice/Bank/Application/BankUpdater.php` (MODIFICAR) -- idem.
- [ ] `api/src/Backoffice/Bank/Application/BankDeleter.php` (MODIFICAR) -- idem; recount FK **fuera** del wrap (opción 1, #249) o downgrade documentado (opción 2).
- [ ] `api/tests/Unit/Backoffice/Bank/Application/RecordingEventBus.php` (NUEVO) -- fake del puerto; sustituye a `RecordingMessageBus` en los tests de Bank.
- [ ] Tests unit de `BankCreator`/`BankUpdater`/`BankDeleter` -- `RecordingEventBus` + stub de `EntityManagerInterface::wrapInTransaction` (`willReturnCallback` que invoca el callback); asertan los eventos publicados.
- [ ] `api/features/backoffice/bank/*.feature` -- POST/PUT/DELETE siguen verdes (contrato HTTP intacto); cobertura de atomicidad donde aplique.
- [ ] `docs/architecture-api.md` -- flujo de domain events ahora atómico (EventBus + `wrapInTransaction`); outbox = transporte Doctrine; broker aguas abajo vía relay futuro.
- [ ] Quitar imports de `MessageBusInterface` ya no usados en los casos de uso.

**Acceptance Criteria:**
- Given create/update/delete OK, when se ejecuta el caso de uso, then el agregado y sus domain events commitean en **una única transacción** (sin ventana de pérdida); si el publish falla, rollback total (no queda agregado sin su evento).
- Given la capa `Application/`, then depende del puerto `EventBus` (no de `Symfony\…\MessageBusInterface`) y publica con `publish(...$aggregate->pullDomainEvents())`.
- Given un único adaptador `SymfonyMessengerEventBus`, then sync-vs-async se decide por `messenger.yaml routing:` (no por clases separadas).
- Given el delete con FK TOCTOU, then sigue devolviendo `409 bank-in-use` con el recount fuera del wrap (opción 1) — o el downgrade queda documentado (opción 2, #249).
- Given el Behat de Bank, then 100% verde (sin cambios en el contrato HTTP) y `make php.quality` limpio.

## Design Notes

**El outbox es la costura permanente; el broker, aguas abajo.** La garantía transaccional nace de **dónde aterriza el mensaje**: el transporte Doctrine es un `INSERT` en la misma conexión → se une a la tx del agregado. Un broker (RabbitMQ/SNS/SQS) es una llamada de red → **no** puede unirse a una tx de DB. Por eso el puerto publica al **outbox**, y el día que se adopte un broker se añade un *relay* (consume el transporte Doctrine → reenvía a `amqp://`/SNS/SQS) sin tocar al productor ni la atomicidad. Symfony Messenger ya es la capa puerto/adaptador de transportes (`MESSENGER_TRANSPORT_DSN`).

**`EventBus` en `Domain`, no en `Application`.** El interfaz solo referencia `DomainEvent` (tipo de dominio), así que vive en `Shared/Domain/Bus/Event/` (convención Codely) sin violar la pureza. Coexiste con el `DomainEventStore` (puerto de auditoría/replay en `Shared/Application/DomainEvent/`): el `EventBus` **publica/encola**; el store **persiste el log** (lo hace el middleware al pasar el evento por el bus).

**EM en `Application/` y testabilidad.** `wrapInTransaction` inline hace que el caso de uso dependa de `EntityManagerInterface` (sancionado por `api/CLAUDE.md`). En unit tests se stubea `wrapInTransaction` para que invoque el callback; la **atomicidad real** se cubre en Behat/funcional, no en unit.

**Por qué no dos buses sync/async.** Duplicarían la fuente de verdad (código vs routing) y un bus sync **se salta el outbox** (corre handlers en-proceso, I/O no transaccional). El split legítimo es por *binding de entorno* (`InMemory` en tests vs Doctrine en prod), no inyectar dos a la vez.

**Evolución a CQRS (#263).** Cuando llegue `command.bus` con middleware `doctrine_transaction` + `dispatch_after_current_bus`, el límite transaccional migra del `wrapInTransaction` inline al bus — aditivo, sin retrabajo.

## Verification

**Commands:**
- `make php.stan` -- 0 errores (level max) en lo tocado.
- `make php.unit c='--filter BankCreator'` / `--filter BankUpdater` / `--filter BankDeleter` -- verde (eventos publicados; FK del delete).
- `make php.behat c='features/backoffice/bank'` -- POST/PUT/DELETE verdes; contrato HTTP intacto.
- `make php.quality` -- limpio.

## Suggested Review Order

**Puerto y adaptador**
- Puerto `EventBus` (`publish` variádico, PHP puro): `api/src/Shared/Domain/Bus/Event/EventBus.php`.
- Adaptador único sobre Messenger: `api/src/Shared/Infrastructure/Bus/Event/SymfonyMessengerEventBus.php`.

**Atomicidad en los casos de uso**
- `wrapInTransaction(save + publish)`: `api/src/Backoffice/Bank/Application/BankCreator.php`.
- Variante delete + FK (recount fuera del wrap, #249): `api/src/Backoffice/Bank/Application/BankDeleter.php`.

**Tests**
- Fake del puerto + stub de la transacción: `api/tests/Unit/Backoffice/Bank/Application/RecordingEventBus.php` y los tests de los 3 casos de uso.
