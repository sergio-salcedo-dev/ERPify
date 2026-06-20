# ADR — Event Store reproducible y proyecciones: el salto de *audit log* a *log replayable*

> **Estado:** aceptado · **Fecha:** 2026-06-16 · **Ámbito:** nuevo subsistema `api/src/Shared/Event/{Domain,Application,Infrastructure}`
> + esquema `event_store` (sustituye `domain_event`) + `projection_checkpoint` + read model `bank_count`
> + proyector de referencia `BankCountProjection` + endpoint y listado PWA de bancos.
>
> **Supersede parcialmente** [`event-driven-architecture.md`](./event-driven-architecture.md): conserva su
> modelo de tres ejes (D1), el invariante "un `DomainEvent` se publica solo por `EventBus`, que solo escribe
> al outbox" y el gate `php.lint.event-bus`; **revisa** la ubicación de los puertos (D2/D6 de aquel ADR) al
> reorganizar todo el backbone bajo `Shared/Event/`. La idempotencia de consumo
> ([`domain-event-handler-idempotency.md`](./domain-event-handler-idempotency.md)) y el eje de auditoría de
> actor ([`audit-activity-log.md`](./audit-activity-log.md)) quedan **intactos** (otros ejes).
>
> Contexto temporal: ERPify **no está en producción** (solo dev/test). Las migraciones se **regeneran desde
> cero** y los cambios incompatibles son gratis. Sin compatibilidad hacia atrás, sin coexistencia de esquemas.

## Contexto

La tabla `domain_event` de hoy es un **audit log a posteriori**: write-only, sin forma de reconstruir el
evento a partir de la fila (`DomainEvent` no tiene `fromPrimitives()`), con `eventId` acuñado dentro del
constructor (reconstruir acuñaría identidad nueva), `occurred_on` como `TIMESTAMP WITHOUT TIME ZONE`, sin
orden global, sin separar tiempo de dominio de tiempo de sistema, y sin costura de versión de esquema. No es
una fuente fiable para reconstrucciones.

El objetivo es un **`event_store` reproducible** (no Event Sourcing completo: el estado sigue siendo
state-oriented por agregado, [`bank-bankaccount-modeling.md`](./bank-bankaccount-modeling.md)) que habilite
replay, reproceso, reconstrucción de proyecciones / read models / índices de búsqueda, auditoría avanzada,
activity timelines, integraciones (CRM/BI/DW/API pública). El **contador de bancos** del listado —`+1` al
crear, `−1` al borrar, reconstruible desde el log— es el **proyector de referencia** que cierra el salto: un
read model derivado por proyección incremental, no por `COUNT(*)`. (Honestidad de arquitecto: para *bancos* un
`COUNT(*)` bastaría; el valor no es el contador, es establecer el patrón **proyector replayable** que usarán
read models reales — `SearchProjection`, `DashboardProjection`, `AccountingProjection`, `InvoiceProjection`.)

## Decisiones

### D1 — `event_store` ≠ `messenger_messages`: ciclos de vida opuestos, no duplicación

Los cuatro conceptos no se solapan; cada uno tiene su mecanismo:

| Concepto | Mecanismo | Ciclo de vida |
|----------|-----------|---------------|
| **Transporte / entrega** | `messenger_messages` (transporte Doctrine) | efímero — fila borrada en el ack |
| **Persistencia de eventos (estado)** | `event_store` | **permanente, append-only, jamás se borra** |
| **Auditoría de actor** | `audit_log` (eje aparte, congelado) | retención por nivel |
| **Replay / reproceso** | leer `event_store` por `sequence` → proyectores | operativo, on-demand |

La duplicación entre log permanente y cola transitoria **es el diseño** (outbox-con-log), no un defecto a
eliminar. Se escriben ambas atómicamente en la misma transacción (D8). Descartado: usar `messenger_messages`
como almacén (borra en el ack); descartado: no tener log permanente y reconstruir desde la cola (imposible).

### D2 — Estructura: `Shared/Event/{Domain,Application,Infrastructure}`

Todo el backbone de eventos se consolida como un **módulo transversal** con su split hexagonal propio, en vez
de esparcirse por `Shared/Domain/Bus/Event` + `Shared/Application/DomainEvent` + `Shared/Infrastructure/{Bus,Messenger,Persistence}`:

- `Shared/Event/Domain/` — `DomainEvent` (base), `EventBus` (puerto), `DomainEventMapper` (puerto).
- `Shared/Event/Application/` — `EventStore`, `DomainEventSerializer`/`Deserializer`, `Upcaster`, `Projector`,
  `ProjectionCheckpointStore`, `ProjectionRunner` (puertos + orquestación).
- `Shared/Event/Infrastructure/` — adaptadores Messenger/Doctrine/DBAL, serializadores, mapper por reflexión.

`Erpify\Shared\…` es siempre importable (gate de aislamiento de contextos). Requiere registrar el módulo en
`deptrac.yaml` (anclas `*domain`/`*application`/`*infrastructure`).

### D3 — Contrato `DomainEvent` endurecido: identidad reconstruible + versión

```php
abstract class DomainEvent
{
    public function __construct(
        private readonly string $aggregateId,
        ?string $eventId = null,                  // inyectable → preserva identidad histórica
        ?DateTimeImmutable $occurredOn = null,    // DateTimeImmutable interno (NO string)
    ) { /* eventId ?? Uuid::generate(); occurredOn ?? new DateTimeImmutable() */ }

    abstract public static function eventName(): string;       // 'erpify.<ctx>.<agg>.<hecho>' — clave estable
    abstract public static function aggregateType(): string;   // 'Backoffice.Bank' — declarado 1× por base de agregado
    public static function eventVersion(): int { return 1; }   // evolución de esquema

    abstract public function toPrimitives(): array;            // SOLO datos de dominio (sin aggregateId/eventId/occurredOn)

    abstract public static function fromPrimitives(
        string $aggregateId, array $body, string $eventId, string $occurredOn,
    ): static;                                                 // `: static`, más preciso que `: DomainEvent`
}
```

`eventId`/`occurredOn` inyectables cierran el **requisito de preservación de identidad** (replay/reintentos/tests
no acuñan identidad nueva). La clave canónica del store y del mapper es `(eventName, eventVersion)` — **nunca el
FQCN** (refactor-frágil). Jubila el `EventHydrator` de tests (el dominio ya reconstruye de verdad). Descartado
`occurredOn` como `string` (degrada el tipado); descartado `eventId` en el constructor sin inyección (rompe la
identidad en reconstrucción).

### D4 — Esquema `event_store`: secuencia como verdad de orden

```sql
CREATE TABLE event_store (
    sequence          BIGINT GENERATED ALWAYS AS IDENTITY,  -- orden global determinista
    event_id          UUID         NOT NULL,                -- UUID v7, identidad estable
    aggregate_id      UUID         NOT NULL,
    aggregate_type    VARCHAR(120) NOT NULL,
    aggregate_version INT          NOT NULL,                -- per-stream (1,2,3…) — preparado, no event-sourcing
    event_name        VARCHAR(190) NOT NULL,
    event_version     SMALLINT     NOT NULL DEFAULT 1,
    payload           JSONB        NOT NULL,                -- toPrimitives(): SOLO dominio
    metadata          JSONB        NOT NULL DEFAULT '{}',   -- correlation_id, causation_id (futuro), actor (futuro)
    tenant_id         UUID         NULL,                    -- multi-tenant SaaS: NULL hoy, NOT NULL al llegar auth
    occurred_on       TIMESTAMPTZ  NOT NULL,                -- tiempo de DOMINIO
    recorded_on       TIMESTAMPTZ  NOT NULL DEFAULT now(),  -- tiempo de SISTEMA
    PRIMARY KEY (sequence),
    CONSTRAINT event_store_event_id_uniq UNIQUE (event_id),
    CONSTRAINT event_store_stream_version_uniq UNIQUE (tenant_id, aggregate_id, aggregate_version)
);
CREATE INDEX event_store_aggregate_idx ON event_store (aggregate_type, aggregate_id, sequence);
CREATE INDEX event_store_name_idx      ON event_store (event_name, sequence);
CREATE INDEX event_store_recorded_idx  ON event_store (recorded_on);
```

- **`sequence BIGINT IDENTITY` como PK**, `event_id` UUID v7 `UNIQUE`. Para un log append-only escaneado por
  rango (checkpoint "todo después de N"), el BIGINT gap-aware gana a UUIDv7 en localidad y semántica de offset.
- **`aggregate_version`** se computa al *append* (`MAX(version)+1` por `(tenant_id, aggregate_id)`, serializado
  por el row-lock del agregado en la transacción de escritura); el `UNIQUE` lo hace control de concurrencia
  optimista. Preparado ya — lo consumirán per-stream replay y agregados event-sourced futuros.
- **`tenant_id`** entra hoy (nullable): retro-encajar una clave de aislamiento en un log inmutable es inviable;
  es candidato a partition key y RLS. Mismo patrón que `actor_id` en `audit_log` (nullable→not-null con auth).
- **`occurred_on`/`recorded_on` `TIMESTAMPTZ`** y **separados** (dominio vs sistema): catch-up y BI necesitan
  *cuándo se persistió*, no solo *cuándo ocurrió*. `JSONB` (no `JSON`) e indexable. `payload` ⊥ `metadata`.

**Persistencia raw DBAL + schema listener**, no entidad ORM: es un log de infraestructura sin invariantes de
dominio (misma categoría que `handled_domain_event`, [`domain-event-handler-idempotency.md`](./domain-event-handler-idempotency.md)),
y el `IDENTITY` + el subquery de `aggregate_version` chocan con la UoW del ORM. `EventStoreSchemaListener`
(`postGenerateSchema`) lo mantiene schema-aware para que `make db.diff` no lo borre. Particionado por
`recorded_on` **diferido** (trigger: ~50–100M filas o contención); el esquema lo habilita sin romper.

### D5 — Mapper / Serializer / Deserializer / Upcaster

| Pieza | Responsabilidad | Capa |
|-------|-----------------|------|
| `DomainEventMapper` | `(eventName, eventVersion) ⇄ FQCN`; `FQCN → aggregateType`. Clave estable. | puerto en `Domain`, impl `ReflectionDomainEventMapper` (compiler pass que descubre todas las subclases concretas de `DomainEvent`, **falla el build ante colisión de `eventName`**) en `Infrastructure` |
| `DomainEventSerializer` | `DomainEvent → fila`: `toPrimitives()` + metadata de envelope | puerto `Application`, impl `Infrastructure` |
| `DomainEventDeserializer` | `fila → DomainEvent`: mapper → **`Upcaster`** → `fromPrimitives()` | puerto `Application`, impl `Infrastructure` |
| `Upcaster` | `(eventName, fromVersion, payload) → payload'`. Costura de evolución. | puerto `Application`, `NullUpcaster` (cadena vacía) hoy |

La **costura `Upcaster` es obligatoria desde el día 1** aunque su cadena esté vacía: un store "reproducible"
que no puede leer un evento cuyo *shape* cambió no es reproducible. Las implementaciones se escriben cuando
cambie el primer evento; el `payload` viejo **nunca se reescribe**, se transforma al leer.

### D6 — Proyector ≠ Reactor; catch-up por `sequence` con checkpoint

Dos especies de consumidor, **separación dura**:

- **Reactor** (`<Efecto>OnEvento`, p.ej. `SendEmailOnBankChanged`, `RefreshRealtimeOnBankChanged`): efecto
  externo no determinista (email, Mercure, API). **Solo live**, idempotencia por claim (`handled_domain_event`).
  **El replay JAMÁS lo reejecuta** — un rebuild no debe reenviar 10.000 emails.
- **Proyector** (`Projector`): derivación determinista e idempotente de un read model. **Replayable desde
  `sequence` 0.** Se gobierna por **catch-up con checkpoint**, no por delta suelto:

```
ProjectionRunner.catchUp(projectionName):
  SELECT … FROM projection_checkpoint WHERE name = ? FOR UPDATE          -- serializa runs concurrentes
  SELECT … FROM event_store WHERE sequence > checkpoint
           AND event_name = ANY(subscribedTo) ORDER BY sequence LIMIT batch
  por cada fila:  deserialize → projector.project(event)
  UPDATE projection_checkpoint SET last_sequence = <última> WHERE name = ?
  COMMIT                                                                  -- read model + checkpoint, atómico
```

El checkpoint (`last_sequence`) hace el catch-up **idempotente y ordenado** en live y en rebuild — el mismo
mecanismo. **Live**: un reactor mínimo (`RunProjectionsOnDomainEvent`) dispara `catchUp` tras cada evento.
**Rebuild**: CLI `event:projection:rebuild <name>` → truncar read model + `last_sequence = 0` + `catchUp`. Un
contador `+1/−1` no es idempotente por naturaleza; el checkpoint es lo que lo hace seguro (nunca se aplica una
`sequence ≤ checkpoint`). Descartado: proyector como handler de delta con idempotencia por claim (frágil — un
evento perdido/duplicado corrompe el contador hasta el rebuild; no ordena).

### D7 — `bank_count`: el proyector de referencia

```sql
CREATE TABLE bank_count ( id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
                          total INT NOT NULL DEFAULT 0, updated_at TIMESTAMPTZ NOT NULL DEFAULT now() );
```

`BankCountProjector` (`subscribedTo = [bank.created, bank.deleted]`): `created → total+1`, `deleted → total−1`.
Read endpoint `GET /api/backoffice/banks/count` → `{ "total": N }` servido **desde el read model** (no
`COUNT(*)`). El listado PWA de bancos muestra el total desde ese endpoint. Rebuild verificable: truncar
`bank_count`, `rebuild`, el total cuadra con `COUNT(*)` de `bank` — prueba viva de reproducibilidad.

**Read model raw DBAL + schema listener, no `#[ORM\Entity]`** (mismo criterio que el log en D4, otra categoría):
una proyección es estado **derivado y desechable**, no un agregado — marcarla `#[ORM\Entity]` la disfrazaría de
concepto de dominio (en este repo `#[ORM\Entity]` ⇒ entidad de dominio, y el read model vive en
`Application`/`Infrastructure` `Projection`, no en `Domain/Entity`). Además el write del read model **debe
commitar en la misma transacción** que el `advance` del checkpoint (D6): `DbalBankCountReadModel` inyecta la
conexión DBAL **default** y un upsert `INSERT … ON CONFLICT (id) DO UPDATE SET total = total + :delta` que
resuelve *insert-or-increment* y el seed-clamp-a-0 en una sola sentencia atómica, uniéndose a esa transacción sin
pasar por la UoW del EM. Con una entidad ORM habría que orquestar el `flush()` en el punto exacto, mezclar
escrituras ORM-managed y DBAL-managed en una transacción, y un `total += 1` en PHP sería read-modify-write — todo
coste sin beneficio para una fila singleton (la hidratación/DQL/change-tracking del ORM no compran nada aquí).
`BankCountSchemaListener` (`postGenerateSchema`) la mantiene schema-aware para que `make db.diff` la genere y no
la borre — la gestión de esquema vía migración **sin** acoplar la proyección al ORM. Mismo patrón que el resto
del backbone (`event_store`, `projection_checkpoint`, `handled_domain_event`: todos raw DBAL + schema listener).
Descartado `#[ORM\Entity]`; se reevalúa en el trigger (h): si la proyección deja de ser un contador singleton y
se vuelve un read model **multi-fila consultable/ordenable/paginable/serializable** (p.ej. `SearchProjection`,
`DashboardProjection`), ahí sí pesa una entidad ORM de lectura vs. un repositorio de lectura dedicado — lo
dispara la *forma de consumo*, no la incomodidad de no tener entidad.

### D8 — Convivencia con Messenger: entrega viva intacta, relay externo aguas abajo

La escritura sigue siendo **atómica** (D3 del ADR anterior): el caso de uso envuelve `save()` + `EventBus.publish()`
en una transacción; commitan juntos la fila del agregado, el `INSERT` en `event_store` (vía `PersistDomainEventMiddleware`
→ `EventStore.append`) y el `INSERT` del transporte Doctrine (`messenger_messages`). Messenger queda **igual** como
entrega viva (worker, retries, `failed` transport). Un broker externo / BI / DW / CRM / API pública va en un
**relay futuro aguas abajo del `event_store`** (lee por `sequence`, checkpoint por sink) — nunca un publish
directo (un `send` de red no se une a la transacción de DB y reintroduce el dual-write). El `sequence` es la
primitiva que mantiene abierta esa evolución (y un eventual Pattern-1 log-as-queue) sin retrabajo.

**Contrato de fallo de `publish()`:** la excepción de `MessageBusInterface::dispatch`
(`Symfony\Component\Messenger\Exception\ExceptionInterface`) **propaga sin capturar** — al ir dentro del
`wrapInTransaction`, aborta también el `save` del agregado (sin dual-write) y llega al handler RFC 9457 global
→ 500. El tipo de framework **muere en el adaptador** (`SymfonyMessengerEventBus`, documentado con `@throws`);
el puerto `EventBus` no declara throws, así que la capa Application no se acopla a él (ni necesita propagar la
anotación a `BankCreator`/`Updater`/`Deleter`). Descartado tragarlo (reintroduce el dual-write); descartado
envolverlo hoy en una excepción de dominio (`EventPublishingFailedException`): con un único adaptador
productivo y cero callers que capturen el fallo, el envoltorio no se gana el mantenimiento (Regla de Tres). Se
gradúa con el trigger (g).

### D9 — Metadata de trazado preparada, no implementada

`metadata` reserva `correlation_id` y `causation_id` (qué evento/comando causó este — lineage para debugging y
BI a 10 años). **`causation_id` se documenta hoy y se puebla cuando existan process managers multi-agregado**;
`correlation_id` cuando exista un id de request estable (lo comparte con el eje de auditoría de actor). Pueden
promoverse de `metadata` JSONB a columnas indexadas cuando una consulta de trazado lo pida. No se inventa hoy
el cableado (CLAUDE.md: nada especulativo) — solo el hueco en el sobre.

## Flujo completo

```
ESCRITURA (una transacción atómica)
  Aggregate.record(DomainEvent) → pullDomainEvents() → EventBus.publish(...$events)
     └─ TX:  bank row
             event_store      ← append  (Serializer: toPrimitives + metadata; aggregate_version=MAX+1)   [permanente]
             messenger_messages ← enqueue (transporte Doctrine)                                          [efímero]

ENTREGA VIVA (Messenger worker)                 LOG PERMANENTE (event_store, por `sequence`)
  REACTORES <Efecto>OnEvento (solo live)        ProjectionRunner.catchUp (checkpoint FOR UPDATE)
    email · Mercure · integraciones               └─ Deserializer(+Upcaster) → Projector.project → read model
    idempotencia por claim · NUNCA replay         REBUILD: reset + checkpoint=0 + catchUp
  RunProjectionsOnDomainEvent → dispara catchUp   relay futuro → BI / DW / CRM / API pública (por `sequence`)
```

## Qué entra en esta PR (todo, sin fases)

Backbone `Shared/Event/{Domain,Application,Infrastructure}` (contrato `DomainEvent` endurecido + mapper +
serializer/deserializer + upcaster + `EventStore` raw-DBAL + schema listener) · **historial de migraciones
aplastado a un único baseline** generado desde cero (`event_store` sustituye `domain_event`; `projection_checkpoint`;
`bank_count`; el resto del esquema intacto) — permitido por estar pre-prod · proyección `bank_count`
(proyector + runner + checkpoint + CLI rebuild + endpoint + listado PWA) · reactores Bank reubicados sin cambio
de comportamiento · gate `php.lint.event-bus` y `deptrac.yaml` actualizados al nuevo árbol · Behat de
observabilidad de eventos repuntado a `event_store`.

## Triggers de revisita

(a) Auth/tenancy real → `tenant_id` pasa a `NOT NULL`, se evalúa partición/RLS por tenant. (b) Primer sink
externo (CRM/BI/DW/API pública) → relay aguas abajo del `event_store` por `sequence`. (c) Primer agregado
event-sourced o per-stream replay → se consume `aggregate_version`. (d) Primera evolución de `eventVersion` →
primer `Upcaster` real. (e) Volumen → particionado por `recorded_on`. (f) Adopción de `CommandBus` (#263) → el
límite transaccional migra del `wrapInTransaction` al middleware (hereda el trigger del ADR anterior). (g)
Segundo adaptador productivo de `EventBus` con excepciones nativas distintas, **o** un caller que capture el
fallo de publicación → envolver en el borde con `Shared\Event\Domain\EventPublishingFailedException` (con
`$previous`) para estabilizar el contrato de fallo frente al cambio de adaptador y mapearlo en el pipeline RFC
9457 — hasta entonces, propagación cruda (D8). (h) Un read model de proyección crece de contador singleton a
multi-fila **consultable/ordenable/paginable/serializable** → se reevalúa entidad ORM de lectura (con metadata
pasiva) vs. repositorio de lectura dedicado, frente al raw-DBAL + schema-listener actual (D7).
