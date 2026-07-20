# ADR — Arquitectura dirigida por eventos: `EventBus`, outbox transaccional y los tres ejes

> **Status:** accepted · **Date:** 2026-06-14 · **Scope:** `api/src/Shared/{Domain,Infrastructure}/Bus/Event`
> + write use cases of `Backoffice/Bank/Application` + `php.lint.event-bus` gate
> + `config/packages/messenger.yaml` (bus naming, D6)
> + [`../rules/cqrs-naming.md`](../rules/cqrs-naming.md) (the "CQRS-shaped pre-bus" naming standard, D5).
>
> **Superseded in part by** [`event-store-and-projections.md`](./event-store-and-projections.md):
> conserva el modelo de tres ejes, el invariante «un `DomainEvent` se publica solo por `EventBus`, que
> solo escribe al outbox» y el gate `php.lint.event-bus`; **revisa** la ubicación de los puertos
> (`EventBus`/`DomainEvent` se reorganizan bajo `Shared/Event/`) y sustituye el *audit log* `domain_event`
> por el `event_store` reproducible con proyecciones.
>
> Contexto temporal: la aplicación **no está en producción**, así que cerrar la fuga de eventos de
> este ADR no arrastra recuperación de datos históricos. El cambio es **corrección del modelo de
> consistencia**, no preparación de CQRS — esa distinción es la decisión D5.

## Contexto

Los casos de uso de escritura de `Bank` commitean el agregado en `save()`/`remove()` (flush del repo)
y **después**, en un bucle, despachan los domain events en `MessageBusInterface` —
`BankCreator.php:57-61`, `BankUpdater.php:41-45`, `BankDeleter.php:48-63`. Son **dos commits
separados**: si el proceso muere entre el commit del agregado y el del despacho, el banco existe pero
su evento nunca se persistió en `domain_event` ni se encoló en `messenger_messages` → **evento
perdido** (dual-write). La tabla `domain_event` de hoy es un *audit log a posteriori*, no un outbox.

Hay dos defectos de distinta naturaleza, y conviene no fundirlos: el **dual-write es un bug de
correctness** (pérdida silenciosa); el acoplamiento de `Application/` a
`Symfony\Component\Messenger\MessageBusInterface` es un **smell de pureza hexagonal**. La misma pieza
los cierra, pero lo que obliga a actuar ya es el bug.

Coexisten además otros dos ejes que **no** son el stream de dominio y no deben mezclarse con él: la
auditoría de actor ([`audit-activity-log.md`](./audit-activity-log.md)) y la idempotencia de consumo
([`domain-event-handler-idempotency.md`](./domain-event-handler-idempotency.md), ya implementada).

## Decisiones

### D1 — Tres ejes, garantías deliberadamente distintas

"Unificado" no significa un solo pipeline: significa un modelo mental coherente donde cada eje tiene
su puerto y su garantía, elegida a conciencia.

| Eje | Pregunta | Puerto | Garantía | Sink |
|-----|----------|--------|----------|------|
| **Estado** | ¿qué le pasó al agregado? | `EventBus` (D2) | **atómico** (D3) | `domain_event` + `messenger_messages` |
| **Operación/actor** | ¿qué hizo el actor? | `AuditLogger` (épica aparte) | best-effort, async-post-respuesta | `audit_log` (hoy: log line) |
| **Lectura** | consulta | `QueryBus` (#263) | — | — |

Eje transversal de **consumo**: `DomainEventHandlerDeduplicator` (claim DBAL en `handled_domain_event`)
hace idempotentes los handlers bajo entrega at-least-once. Es ortogonal a la publicación y queda **tal
cual**.

**Invariante load-bearing:** un `DomainEvent` se publica **solo** por `EventBus`; el `EventBus`
publica **solo** al outbox (transporte Doctrine), **nunca** directo a un broker. Un broker externo
(RabbitMQ/SNS/SQS) va siempre **aguas abajo** del outbox vía un relay futuro — un `send` a un broker
es I/O de red que no se une a la transacción de la DB y reintroduciría el dual-write. El gate (D4)
hace ejecutable este invariante.

### D2 — Puerto `EventBus` en `Domain`, adaptador único

`Shared/Domain/Bus/Event/EventBus` (convención Codely): `publish(DomainEvent ...$events): void`. Vive
en `Domain/` porque su firma solo referencia `DomainEvent` (tipo de dominio) — no rompe la pureza, no
importa framework. El call-site queda variádico: `$this->eventBus->publish(...$bank->pullDomainEvents())`;
el bucle de `dispatch` vive en el adaptador, no en cada caso de uso.

Adaptador único `Shared/Event/Infrastructure/Bus/SymfonyMessengerEventBus` (`#[AsAlias(EventBus::class)]`),
inyecta `MessageBusInterface` y despacha cada evento. Coexiste con `DomainEventStore` (puerto de
auditoría/replay): el `EventBus` **publica/encola**; el store **persiste el log** (lo hace
`PersistDomainEventMiddleware` al pasar el evento por el bus, sin cambios).

Descartado: clases `SymfonyMessengerSyncEventBus`/`...AsyncEventBus`. Duplican la fuente de verdad
(código vs `messenger.yaml routing:`) y un bus sync **se salta el outbox** (corre handlers en-proceso,
I/O no transaccional). El split legítimo es por binding de entorno (`in-memory` en test vs Doctrine en
prod), no inyectar dos buses a la vez.

### D3 — Atomicidad vía `wrapInTransaction` inline — **detalle transitorio**, no arquitectura permanente

El caso de uso envuelve `save()`/`remove()` + `publish(...)` en `EntityManager::wrapInTransaction()`:
la fila del agregado, el `INSERT … ON CONFLICT` de `domain_event` y el `INSERT` del transporte Doctrine
corren en la conexión por defecto → commitean en **una sola transacción**. Si `publish` falla, rollback
total: no queda agregado sin su evento. `EntityManagerInterface` en `Application/` está sancionado por
`api/CLAUDE.md`.

> **Este `wrapInTransaction` inline es un detalle de implementación transitorio, pendiente de migrar al
> middleware `doctrine_transaction` + `dispatch_after_current_bus` del `CommandBus` (#263).** Cuando ese
> bus aterrice, el límite transaccional sale del caso de uso y entra en el bus — sustitución aditiva,
> sin retrabajo. Marcarlo así evita que se convierta en "arquitectura permanente accidental".

En DELETE los eventos se capturan **antes** de `remove()` (agregado intacto) y el recount de la FK
queda **fuera** del wrap: una violación de FK aborta toda la transacción en PostgreSQL y
`wrapInTransaction` cierra el `EntityManager`, así que el recount que produce el `409 bank-in-use` corre
con un manager fresco tras el rollback (opción 1, comentario de #249).

Descartado: repuntar el **productor** a un broker (rompe la atomicidad). Descartado: mantener el
dual-write actual "porque casi nunca falla" (pérdida silenciosa, inaceptable en un ERP).

### D4 — Gate prescriptivo + allowlist como frontera temporal explícita

`EventDispatchGateTest` (estilo `BoundedContextGateTest`, vía `make php.lint.event-bus`, sumado a
`php.quality`) **falla** si un fichero bajo `*/Application/` referencia `MessageBusInterface`, salvo
entrada en `api/.event-dispatch-allowlist`. Hace el invariante de D1 *load-bearing*: el acoplamiento no
puede reaparecer en un PR sin una excepción revisada.

El eje **audit** estrenó esa frontera temporal y la cerró: `BankAccountSearcher` ya **no** importa
`MessageBusInterface` — registra el acceso por el puerto `AuditLogger`
([`audit-activity-log.md`](./audit-activity-log.md)), que escribe una entrada de auditoría **síncrona**
(best-effort, **no** un `DomainEvent`), fuera del `EventBus` transaccional (ADR D3.1 — la vía async se
retiró). Construida esa épica, su entrada se retiró del `api/.event-dispatch-allowlist`, que queda sin
entradas de path.

Descartado: gate que prohíbe `MessageBusInterface` sin excepción (rompe el eje audit). Descartado
introducir ya un `AuditLogger` para evitar la allowlist (abre una épica congelada → scope creep).
Descartado gate semántico sobre el tipo despachado (`DomainEvent`) en vez del importado (frágil de
detectar estáticamente).

### D5 — CQRS desacoplado de este trabajo; modelo de nombrado «CQRS-shaped pre-bus»

Introducir `EventBus` + outbox **no es** adoptar CQRS: el `EventBus` es frontera hexagonal (DIP) para
eventos, no la indirección de ejecución de un `CommandBus`. El controller sigue invocando el caso de
uso directamente. `CommandBus`/`QueryBus` quedan diferidos a #263 con sus disparadores intactos: nº de
casos de uso más allá de Bank, divergencia real read/write o varios read-models, o necesidad de
middleware transversal uniforme en el borde de escritura.

Pero "desacoplado" no es "sin criterio de nombrado". El estándar operativo (5 categorías de mensaje,
plantilla de nueva entidad, lista de prohibidos) vive en [`../rules/cqrs-naming.md`](../rules/cqrs-naming.md);
aquí quedan la decisión y el porqué.

**Dos planos.** Semántica (intención write/read) vs ejecución (runtime: llamada directa vs dispatch por
bus). El sufijo `Handler` afirma "lo despacha un bus/transporte", así que el nombre sigue al plano de
ejecución y **nunca lo precede**: un `*CommandHandler` sin `CommandBus` es *atrezo* — nombra una capacidad
ausente. CQRS separa *ejecución*, no *nombres*. Por eso hoy se nombran las **intenciones** (carpetas
`Command/`/`Query/`) mientras los casos de uso siguen siendo `Creator`/`Finder` (ejecución directa) y los
suscriptores de evento son `<Efecto>OnEvento` (`#[AsMessageHandler]`, dispatch real), no `*Handler` genéricos.

**Invariantes por-path (un hogar a la vez; el nombre puede ir por detrás, nunca por delante):** **I1 ·
límite transaccional** — hoy `wrapInTransaction` inline en cada caso de uso (D3); migra al middleware
`doctrine_transaction` + `dispatch_after_current_bus` del `CommandBus` al aterrizar el bus. **I2 ·
enforcement transversal** (auth/validación) — hoy por-path; se uniforma en el borde del bus cuando exista.
**I3 · frontera de publicación de eventos** — **ya uniforme y enforced** (puerto `EventBus` D2 + gate
`php.lint.event-bus` D4): la única de las tres ya cerrada.

**Tres fases.** (1) *Pre-bus* (hoy): intención nombrada, ejecución directa, suscriptores `<Efecto>OnEvento`.
(2) *Aterrizaje del bus* (#263): strangler controlado, unidad = caso de uso (seguro porque el repo es
single-aggregate-por-operación; un process-manager multi-agregado migraría entero); I1 sale del
`wrapInTransaction` y entra en el middleware — nombre y runtime migran juntos, sustitución aditiva sin
retrabajo. (3) *Convergencia*: espacio de creación cerrado mono-dialecto + legacy cerrado y menguante.

**Ratchet de convergencia (ni barrido, ni coexistencia infinita).** Primario (obligatorio, gate estilo
`deptrac.baseline`): control de generación — post-bus no nace ningún `Creator`/`Finder` nuevo. Secundario
(oportunista, gratis): boy-scout — el legacy *tocado* convierte a handler; es lo único que **encoge** el
legacy (congelar la generación solo lo fosiliza). Nunca: barrido fechado de código frío. `Bank` es
**ejemplo de referencia, no plantilla obligatoria** — la estrategia de persistencia sigue siendo
por-agregado ([`bank-bankaccount-modeling.md`](./bank-bankaccount-modeling.md)).

Descartado: full-CQRS naming ya (`Creator → CommandHandler`) — atrezo, nombra una capacidad ausente.
Descartado: dejar la ejecución sin criterio de nombrado "hasta el bus" — intenciones y suscriptores ya
tienen forma estable y se nombran hoy.

### D6 — Nombrado del bus de Messenger: convención del default hoy, rol al dividir

El id de un bus bajo `framework.messenger.buses` es arbitrario: `messenger.bus.default` no es palabra
reservada, es la convención que trae la receta de Symfony. Hoy hay **un solo bus** y `MessageBusInterface`
se autocablea al **bus por defecto**, no a un id literal — con `default_bus: null` y un único bus, ese bus
*es* el default. Por eso `SymfonyMessengerEventBus` (D2) y `BankAccountSearcher` lo reciben sin nombrar el
id (el literal solo aparece en `messenger.yaml`).

**Decisión:** mientras exista un único bus se mantiene `messenger.bus.default`. Renombrarlo a
`erpify.bus.default` es cosmético — el significado de negocio ya vive en el puerto `EventBus` y su adaptador
(D2); el id del contenedor es detalle de infraestructura invisible a `Domain/Application`. El cambio solo
desviaría de lo idiomático sin ganancia.

El rename que **sí** paga llega al dividir en varios buses para CQRS (#263, D5): se nombran **por rol** —
`command.bus` / `query.bus` / `event.bus` — con autowiring por nombre de argumento
(`MessageBusInterface $commandBus`/`$queryBus`/`$eventBus`), no con un genérico `erpify.bus.default`. El id
revela *qué hace* el bus, lo cual solo importa cuando hay más de uno.

Descartado: `erpify.bus.default` ahora (prefijo de marca, no de rol; rompe la convención del default-bus a
cambio de nada). Descartado: fijar `default_bus` explícito teniendo un único bus (redundante — ese bus ya es
el default).

### D7 — No-handler: tolerancia por config, fail-fast deliberado, política por bus

**Cero-handlers se tolera por config, nunca por catch.** Que un evento no tenga suscriptores es semántica
legítima de un event bus, pero se expresa con `allow_no_handlers` del bus — **no** con un `try/catch` en
`SymfonyMessengerEventBus`. Ese catch correría **dentro del `wrapInTransaction` de D3** y tragaría también un
fallo real del transporte (DB caída → el `INSERT` del outbox falla), commiteando el agregado **sin** su evento
→ reintroduce el dual-write que D3 cerró. El adaptador traduce el puerto `EventBus`, no es un punto de política.

**Fail-fast deliberado.** El bus por defecto mantiene `allow_no_handlers: false`: un evento que se **olvidó
enrutar** y no tiene handler revienta síncrono dentro del wrap → rollback. Es fail-fast contra cableado
incompleto, ruidoso en dev/test antes de prod, no un defecto. La observabilidad de los fallos de handler en el
worker va por el transporte `failed` (visible y replayable), **nunca** por un `warning` tragado.

**Política por bus al dividir (D5/#263).** `allow_no_handlers: true` pertenecerá **solo** al `event.bus`
(relación evento↔handler **N:M**, 0..N suscriptores); el `command.bus` mantiene `false` — un comando debe tener
**exactamente un** handler, contrato **1:1**. Por eso el flag no se flipea hoy sobre el bus único.

Descartado: `try/catch` + `warning`/`info` en el adaptador (traga fallos reales del outbox, oculta cableado
roto, peor observabilidad que `failed`). Descartado: `allow_no_handlers: true` global hoy (rompería el contrato
1:1 del command bus cuando el bus único cargue también comandos).

> Mecánica y estado actual — enrutado `async` → outbox → worker → `failed`, y la tabla N:M con los ejemplos
> vivos de `Bank`: [`../architecture-api.md`](../architecture-api.md), sección *Async & messaging*.

## Verificación

- Behat de `Bank` (POST/PUT/DELETE) 100% verde, contrato RFC 9457 intacto; el delete con FK TOCTOU
  sigue devolviendo `409 bank-in-use`.
- `make php.lint.event-bus` falla al reintroducir `MessageBusInterface` en un `Application/` no
  exento; verde con la única excepción de `BankAccountSearcher`.
- `make php.quality` limpio (`level: max`).

## Triggers de revisita

(a) Adopción de `CommandBus` (#263) → el límite transaccional (I1) migra del `wrapInTransaction` al
middleware y D3 se reescribe; los buses pasan a nombrarse por rol (`command.bus`/`query.bus`/`event.bus`)
y D6 se actualiza; se activa el ratchet de generación-control de D5 y los `Creator`/`Finder` empiezan a
converger a handlers (`cqrs-naming.md`). (b) Construcción de la épica de auditoría → `BankAccountSearcher` pasa a
`AuditLogger` y **sale** de la allowlist. (c) Adopción de un broker externo → se añade el relay aguas
abajo del outbox (D1) sin tocar productor ni atomicidad.
