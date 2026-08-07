# ADR — Event Store reproducible y proyecciones: el salto de *audit log* a *log replayable*

> **Status:** accepted · **Date:** 2026-06-16 · **Scope:** new subsystem `api/src/Shared/Event/{Domain,Application,Infrastructure}`
> + `event_store` schema (replaces `domain_event`) + `projection_checkpoint` + `bank_count` read model
> + reference projector `BankCountProjection` + bank endpoint and PWA listing.
>
> **Supersedes in part** [`event-driven-architecture.md`](./event-driven-architecture.md): conserva su
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

### D10 — Payload content: state event by default, delta only when a consumer needs the diff

A domain event carries the data of the change it represents, and different actions carry different fields
(`BankCreatedDomainEvent` ≠ `BankDeletedDomainEvent`). Two shapes answer "what data": a **state event** (full
snapshot of what the action affected, self-contained) vs a **delta/intent event** (only what changed, or the
bare fact). The default here is the **state event**; the discriminator for leaving it is *a real consumer that
asks "what changed?"*.

- **State by default is what reproducibility (D6/D7) needs.** A `Projector` replaying from `sequence` 0 must
  rebuild the read model from the `payload` alone — a state event lets it do so without reading prior aggregate
  state (which, mid-rebuild, may not exist). It is also self-contained for any future sink (D8) and byte-stable
  in the store. A delta event would force every projector to hold the prior state, coupling the log's readers to
  live aggregate state and defeating replay.
- **Envelope ⊥ payload — already the contract (D3/D4).** `aggregateId`/`eventId`/`occurredOn` live in the
  `event_store` row, **never** in `toPrimitives()`; the payload carries only domain state.
- **Lived shape.** `BankCreatedDomainEvent`/`BankUpdatedDomainEvent` carry the full `BankSnapshot`;
  `BankDeletedDomainEvent` carries an **empty payload** (the id is in the envelope row) — the canonical "the
  action only needs the id" case. `BankUpdatedDomainEvent` is a full snapshot *by design*, not a delta: its only
  consumer, `RefreshRealtimeOnBankChanged` (Mercure), pushes the whole new state to the client — a delta would
  force it to re-read the aggregate to recompose the view. Fat is justified **by the consumer that exists**, not
  by inertia.
- **Shared shape → shared value object, not a supertype.** When two events share a payload shape (`BankSnapshot`
  across created/updated), share the VO; a supertype *over events* would couple their schemas and threaten the
  byte-stability the store depends on.
- **Security (repo checklist).** No secrets, no client-editable audit fields in the payload; the snapshot must be
  state already public to its consumers.

Discarded: **fat by inertia** — a snapshot whose fields no consumer's shape needs is byte-bloat; name the
consumer or trim it. Discarded: **thin/delta by default** — breaks replay self-containment (D6) and the rebuild
guarantee (D7). Discarded: a speculative global `changedFields` on every event — YAGNI; it is added to *the one
event* whose consumer needs the diff, when that consumer exists (trigger (i)).

### D11 — Catch-up live "tonto": `catchUpAll()` por evento, no filtrado por suscripción

`RunProjectionsOnDomainEvent` dispara `ProjectionRunner::catchUpAll()` tras **cada** evento entregado, y
`catchUpAll()` recorre **todos** los proyectores registrados abriendo, por proyector, su propia transacción con
`lockAndRead()` sobre `projection_checkpoint` (`INSERT … ON CONFLICT DO NOTHING` + `SELECT … FOR UPDATE`) —
aunque el proyector no esté suscrito al evento entregado. El `stream()` interno **ya va filtrado por
`subscribedTo()`** (barato, casi siempre vacío): el coste del fan-out **no es el barrido**, sino las *N*
transacciones + *N* locks de checkpoint, serializados en el worker único.

**Se mantiene el diseño tonto.** El firing es deliberadamente ignorante del evento entregado: la corrección
(orden, exactly-once) vive en el checkpoint del runner (D6), no en el disparo; y al releer el `event_store`
**permanente** —no el mensaje— una entrega perdida o duplicada se reconcilia en el siguiente run (auto-sanación).
Filtrar por suscripción cambiaría el firing de "reconcilia todo" a "reconcilia solo lo entregado".

**Medición que lo respalda (argumento de coste, CLAUDE.md acepta el análisis de complejidad como medición).** Con
*N* proyectores el coste por evento es *N* transacciones + *N* locks de checkpoint. Hoy **N = 1**
(`BankCountProjector`, único `Projector` registrado): el fan-out es exactamente **1 transacción + 1 lock**, el
**suelo irreducible** —avanzar el único proyector con garantía exactly-once exige abrir una transacción y bloquear
su checkpoint—, luego el sobrecoste sobre el trabajo necesario es `(N−1) = 0`. No se corre un benchmark de runtime
porque a `N = 1` no hay fan-out que medir, y sintetizar *N* proyectores para medirlo sería infra especulativa
(YAGNI). El coste escala con el **número de proyectores**, no con el trabajo real del evento — por eso el trigger
es *cuántos proyectores*, no *cuántos eventos*.

**Alternativa descartada hoy (se gradúa en el trigger (k)):** pasar el `eventName` entregado al runner y filtrar
los proyectores por `subscribedTo()` **antes** de abrir su transacción de catch-up. Evita la transacción + lock en
los proyectores no afectados (el coste dominante). Coste: pierde la auto-sanación de un proyector recién añadido o
con backlog (dejaría de ponerse al día con eventos ajenos), así que exigiría **conservar un catch-up completo
periódico fuera del hot path** y medir contención real sobre `projection_checkpoint` antes de decidir. Con un solo
proyector esa maquinaria es puro coste sin beneficio.

### D12 — `event_store` es append-only **con un conjunto cerrado de mutaciones sancionadas**: el borrado GDPR

`PersistDomainEventMiddleware` escribe **todo** evento despachado **antes** de que Messenger decida transporte,
así que ocurre con `async`, con `sync` y sin enrutar. Para los eventos cuyo agregado **es una persona**, el
`aggregate_id` **es** el id real del sujeto; y `SessionStarted`, `SessionRevoked` y los seis `Invitation*` lo
llevan además en el `payload`. Nada en la cadena de erasure toca esta tabla, de modo que el identificador de una
persona **sobrevive a su propio borrado, para siempre**. Eso es incompatible con SI-21.

**Decisión: el log deja de ser estrictamente inmutable y pasa a ser _append-only con un conjunto cerrado de
mutaciones de primera clase_ — hoy exactamente una.** Es la misma forma que
[`audit-activity-log.md`](./audit-activity-log.md) ya adoptó para el log hermano de PII, y **no introduce un
principio nuevo**: lo extiende al log de negocio.

**La política.** Al ejercerse el derecho de supresión, un **único `UPDATE` parametrizado** reescribe el
identificador del sujeto con **un UUID aleatorio nuevo acuñado en el borrado** —sin valor original, sin tabla de
mapeo, sin derivación determinista—, **en la columna y en el TEXTO SERIALIZADO de `payload` y `metadata`,
por coincidencia de valor y sin distinguir mayúsculas**, dentro de la transacción que
`FulfilIdentityErasure` ya posee. Es idempotente por construcción: una segunda pasada no encuentra nada.

**Por qué el borrado es por coincidencia de valor y no por enumeración de eventos.** Un `WHERE` sobre el id del
sujeto alcanza **todo evento** que lo contenga, presente y futuro, sin que ningún productor tenga que acordarse
de nada. Cualquier mecanismo preventivo —que el id nunca llegue a escribirse— depende de la memoria de quien
añada el próximo evento, y para ser fiable necesitaría **su propio gate**: más maquinaria para una garantía más
débil.

*Enmienda (2026-08-04, al implementarlo).* Esta frase decía «en las **claves** de `payload`», y se
contradecía con la decisión de borrar **por valor** que el propio párrafo siguiente fija: enumerar claves es
una declaración que solo se comprueba a sí misma —quedaría verde justo sobre los eventos que nadie recordó
listar—, y ya hay dos nombres distintos (`invitedUserId`, `userId`) garantizados por un trait compartido. Se
amplía además a `metadata`, que hoy se escribe `[]`, porque la garantía de este anonimizador está definida
sobre la FILA y no sobre una lista de columnas recordada: cubrir la tercera **retira una excepción** en vez de
añadir responsabilidad, y como el predicado es por valor, mientras la columna no guarde un id de persona la
sentencia no reescribe nada. Coste de ejecución: cero (misma sentencia, mismo viaje). La razón **no** es que
D9 reserve la columna para un actor: el cuerpo de D9 reserva `correlation_id` y `causation_id`, que
identifican un evento y una petición, no a una persona. Dicho eso, el bloque de esquema de este mismo ADR
apunta un `actor` futuro, y ese sí sería un id de persona — de modo que cubrir `metadata` no es solo higiene,
es anticiparse a un campo ya previsto.

*Enmienda (2026-08-04, tras la lectura hostil).* La garantía de arriba se estrecha, y hay que decirlo en vez
de dejarla escrita más ancha de lo que el código entrega: la sentencia alcanza todo evento que contenga el
identificador **de un sujeto cuya condición de persona estableció el caso de uso de borrado** —en concreto,
cuya fila de identidad estaba viva—, no de cualquier UUID que se le pase. El motivo es que el predicado por
valor no distingue clases de agregado: es lo que le permite alcanzar cualquier evento y, con la misma
indiferencia, reescribir el stream entero de un banco si alguien teclea su id, de forma irreversible (D4 veta
la tabla de mapeo que lo desharía) y silenciosa (la rama del id ausente se decide después del commit). La cota
vive en `FulfilIdentityErasure`, no en el SQL ni en el módulo compartido: clasificar personas es conocimiento
del contexto que las posee, la misma asignación que
[`audit-activity-log.md`](./audit-activity-log.md) D4 hace para el eje de recurso. La prohibición de enumerar
queda intacta — la sentencia no cambia. Lo que se acota es *cuándo* corre, nunca *qué* casa.

**Los dos ejes se cierran juntos, y no es un detalle de alcance.** Una implementación que solo reescriba
`aggregate_id` deja `SELECT aggregate_id FROM event_store` en verde mientras el id sigue vivo en el `payload` de
la fila contigua — una declaración cuya única evidencia es la mitad que eligió mirar.

*Enmienda (2026-08-07, al cerrar el residuo de invitación).* El inventario de arriba deja de describir a los
seis `Invitation*`: su `aggregateId` pasa a ser el `invitedUserId` y su `payload` queda vacío, de modo que
migran del eje de payload al eje de columna. El motivo **no** es de borrado —esta política los alcanzaba por
las dos vías— sino que su `aggregate_id` era el **selector** del enlace de aceptación, y este log no caduca:
lo prohíbe el corolario de I-1 de [`administrative-recovery-channel.md`](./administrative-recovery-channel.md).
Consecuencias que esta decisión hereda y conviene tener escritas:

- Los seis suben a `eventVersion` **2**. Una fila v1 ya no resuelve clase en `ReflectionDomainEventMapper` y
  **falla ruidosamente al leerse**, que es lo correcto: su `aggregate_id` significa algo que la clase ya no
  significa, y ningún upcaster puede repararlo — un upcaster transforma el `payload` y nunca ve la columna.
  **No se migran filas históricas**; siguen conteniendo selectores y esta política las alcanza igual.
- El sujeto de esos eventos pasa a compartir el eje de `aggregate_id` con sus eventos de `Iam.Identity` y con
  los dos revokes masivos de sesión, así que un borrado los mueve **todos al mismo seudónimo** y la unicidad
  de `event_store_stream_version_uniq` se preserva por la misma razón que ya se enuncia arriba.
- `AcceptInvitation` publica ahora eventos claveados por el usuario mientras sostiene el lock de la fila de
  **invitación**, no el de la identidad. Hoy es inocuo porque ese UNIQUE es inerte (`tenant_id` siempre
  `NULL`); queda registrado en `deferred-work.md` como deuda de la historia que active el versionado real.

**Alternativas descartadas:**

- **Crypto-shredding del `aggregate_id`.** No aplica: una clave indexada no se cifra. Y es **peor que
  inaplicable** — el keystore indexa por la cadena de scope y `destroy()` **conserva la fila**, así que un scope
  por sujeto dejaría el id real ahí para siempre: un no-op con pasos extra.
- **Tabla de correspondencia `id real → seudónimo`.** Vetada por D4 de [`audit-activity-log.md`](./audit-activity-log.md).
- **Derivación determinista del seudónimo.** Vetada por el mismo D4, explícitamente.
- **Que el `aggregate_id` nazca como sustituto** (un `event_stream_id` propio en la fila del sujeto, que muere
  con ella). Es la alternativa seria, y pierde por alcance: **no toca el eje de `payload`**, luego necesitaría
  esta misma política igualmente; exige migración, todo constructor de evento y un gate propio; y obligaría a
  reinterpretar la prohibición de crosswalk para el caso de una columna. *Se reevalúa si se activa el trigger de
  abajo.*
- **No persistir esos eventos, o partir la tabla.** No persistirlos destruye la **única** traza de suspensión,
  desactivación, cambio de rol y bloqueo — de la que depende hoy la regla que exige degradar a un administrador
  antes de borrarlo. Una segunda tabla borrable exige un `stream()` que una dos orígenes preservando una
  `sequence` monótona única para un solo checkpoint: estrictamente más caro para el mismo resultado.

**Qué empeora, y la regla que lo acota.** El replay deja de ser **históricamente reproducible**: tras un borrado,
reproyectar produce un identificador distinto del emitido. Hoy es inocuo —ningún proyector lee `aggregate_id`—,
pero el día que un proyector claveara un read model de persona por esa columna, un rebuild post-borrado lo
re-clavearía **en silencio**. **Regla, no código: un proyector nunca clavea un read model de persona por el
identificador real.** Sigue siendo una regla y no un gate, porque ninguno la comprueba: lo medido el
2026-08-04 es que hoy no puede violarse por accidente —existe **un** `Projector` en el árbol y ninguno lee
`aggregate_id`—, y que `make php.lint.person-reference` obligaría a un read model de persona **con entidad
Doctrine** a clasificar su columna `Types::GUID`, a declararla con `#[PersonSubjectReference]` y a dotarla de
un `PersonReferenceSource`. Eso es un control **adyacente**, no el de esta regla: fuerza a que la columna
tenga dueño de borrado, nunca a que el proyector no la clavee por el id real, ni detecta el re-claveado tras
un rebuild. Un read model que pase ese gate sigue expuesto al fallo que este párrafo describe. Los docblocks
que decían «append-only» a secas dicen «append-only, con un conjunto cerrado de mutaciones sancionadas»,
como ya hace `audit_log`.

**Qué cierra el conjunto, dicho con precisión: la revisión, no un gate — y la palabra «cerrado» se lee así.**
No existe control automatizado que impida una segunda mutación: `git grep "UPDATE event_store"` es todo lo que
hay, y no lo ejecuta nadie más que quien se acuerde. El conjunto es cerrado **por decisión**, y una propuesta
de ampliarlo se detecta leyendo el diff, no rompiendo el build. Se declara aquí porque la frase anterior
—«conjunto cerrado»— se lee con naturalidad como una garantía mecánica, y prometer un control que no existe es
el defecto que esta épica entera se dedicó a eliminar. El hueco es **simétrico** con
[`audit-activity-log.md`](./audit-activity-log.md), que tiene el mismo conjunto declarado y el mismo control
ausente, así que ampliarlo a un gate es un trabajo para las dos tablas o para ninguna.

**Trigger para construir ese gate:** la primera propuesta de una **segunda** mutación sobre cualquiera de las
dos tablas. Mientras el conjunto tenga un solo miembro por tabla, un gate cuesta registro, fixtures y su propia
cabecera de puntos ciegos para vigilar una lista que no ha cambiado nunca — y la Regla de Tres no se cumple.

**Trigger de revisita:** (a) que un agregado-persona pase a ser **event-sourced de verdad** —una clave de stream
reescrita cambiaría la identidad de un agregado vivo—, o (b) que aterrice el **relay externo** de D8: un `UPDATE`
aguas arriba **no** se propaga a un sumidero ya replicado. Nótese que (b) no exime a la alternativa del
sustituto: el eje de `payload` se fuga igual, así que un relay necesita su propio dueño de borrado en ambos
mundos.

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
pasiva) vs. repositorio de lectura dedicado, frente al raw-DBAL + schema-listener actual (D7). (i) First
consumer whose interest is *what changed* (selective notification, a field-level change log, a partial-resync
integration) → that **one** event gains a delta shape or a `changedFields` entry in its `payload`, never a global
field; until then, state snapshots (D10). (j) Event catalog scale/drift → the hand-maintained
`docs/architecture/event-catalog.md` is generated from the `RegisterDomainEventsPass` registry (plus a drift
gate) once the events outgrow a hand-kept list, or a first external integrator consumes the contract; until
then it stays hand-written, pointing at the pass as its source of truth. (k) El número de proyectores crece
(orientativo ≥ 5) **o** se observa contención sobre `projection_checkpoint` en el worker → pasar el `eventName`
entregado al runner y filtrar los proyectores por `subscribedTo()` antes de abrir la transacción de catch-up,
preservando la garantía exactly-once del checkpoint y añadiendo un catch-up completo periódico fuera del hot path
como vía de reconciliación (D11).
