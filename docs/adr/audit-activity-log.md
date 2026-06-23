# ADR — Auditoría operativa / de actor (`AuditLogger` → `audit_log`), eje separado del stream de dominio

> **Status:** accepted · design frozen, implementation pending · **Date:** 2026-06-14 · **Last reviewed:** 2026-06-23
> · **Scope:** cross-cutting `Shared` subsystem (capture + contract) + `Backoffice/Audit` module
> (read side). `BankAccountsViewed` is its first consumer; the **subsystem implementation** is
> its own epic and is not mixed with the code of the feature that surfaced it.
>
> Contexto temporal: la aplicación **no está en producción**; las tablas y políticas de retención
> de este ADR nacen sin compatibilidad hacia atrás.

## Contexto

Un ERP necesita responder preguntas que el stream de dominio **no** responde: *¿qué hizo un usuario
a lo largo de su jornada?*, *¿accedió o modificó algo que no debía?*, *reconstruir su sesión antes
de que algo se rompiera*. Esto es **observabilidad del actor**, no verdad de negocio.

ERPify ya tiene una auditoría, pero de **otro eje**: cada `DomainEvent` despachado se persiste en
`domain_event` como `StoredDomainEvent` (ver [`architecture-api.md`](../architecture-api.md) y la
tabla de [`bank-bankaccount-modeling.md`](./bank-bankaccount-modeling.md)). Eso audita *qué le pasó
al agregado* (estado), no *qué hizo la persona* (operación). Modelar `BankAccountsViewed` —una
lectura— hizo visible el hueco: una vista no es un cambio de estado de ningún agregado.

## Decisiones

### D1 — Eje separado: `AuditLogger` ≠ `DomainEvent`; **no** se renombra `StoredDomainEvent`

Se introduce un eje nuevo —auditoría operativa/de actor—, independiente del bus de dominio. La
superficie pública es **un único seam**, el puerto `AuditLogger->log(action, level, resource?,
metadata?)`; los módulos no conocen nada más. Tres ejes, sin solape:

| Pregunta                           | Mecanismo                    | Tabla                                |
|------------------------------------|------------------------------|--------------------------------------|
| ¿Qué le pasó al agregado? (estado) | `DomainEvent` → `EventBus`          | `domain_event` (`StoredDomainEvent`) |
| ¿Qué hizo el actor? (operación)    | `AuditLogger->log(...)`      | `audit_log` (`AuditLogEntry`)        |
| ¿Cómo funciona el sistema?         | logs / métricas              | —                                    |

**No existe un tipo público `AuditEvent`.** Detrás del seam viven dos piezas internas de
`Shared/Audit`, nunca expuestas a otros contextos:

- `RecordAuditEntry` — mensaje interno de Messenger (la vía async; ver D3). **No** es `DomainEvent`,
  **no** es evento de integración, **no** se publica fuera de `Shared/Audit`, **no** entra en el
  *event catalog* y **no** lo escucha ningún otro bounded context: existe solo como transporte
  interno hacia el worker de auditoría.
- `AuditLogEntry` — el modelo persistido append-only de la fila de `audit_log` (DBAL crudo, sin
  entidad ORM).

El nombre verbo-imperativo (`RecordAuditEntry`) y la ausencia de un tipo `AuditEvent` hacen las dos
fugas semánticas habituales —"parece evento → al event catalog", "va por un bus → lo escucho en otro
contexto"— estructuralmente imposibles, no solo prohibidas por convención.

`StoredDomainEvent` es la **foto en reposo de un `DomainEvent`**, no un "evento de auditoría
genérico": el nombre es correcto y se mantiene.

Descartado: que `BankAccountsViewedAuditEvent` extienda `DomainEvent` — lo despacharía al `EventBus`
y lo persistiría en `domain_event` vía `PersistDomainEventMiddleware`, contaminando outbox/replay y
lo que consumen otros contextos con telemetría de lectura; además `aggregateId` no aplica a una
vista de lista. Descartado: un tipo público `AuditEvent` o un sufijo `...Command`/`...Event` para el
mensaje interno — arrastra semántica de event bus / CommandBus aunque no se use y reintroduce justo
la fuga que este eje evita. Descartado: renombrar `StoredDomainEvent → AuditLogEntry` — pierde
precisión **y** colisiona con el concepto nuevo.

### D2 — Captura híbrida; la **política decide antes de persistir**, no el hook

Dos puntos de captura, una sola decisión de "qué se guarda":

- **Access-log genérico**: un `EventSubscriber` sobre **`kernel.terminate`** (se ejecuta *tras*
  enviar la respuesta → coste de latencia nulo), acotado a `/api/*`.
- **Acciones de fuerte semántica**: llamadas explícitas `AuditLogger->log(...)` en Application para
  exportaciones, vistas de datos sensibles, denegaciones de permiso, etc. (contexto que el hook no
  conoce).

El kernel **solo captura contexto**; un `AuditPolicy` decide si la interacción es auditable y a qué
nivel **antes** de invocar `AuditLogger->log(...)`. Las denegaciones de permiso se enganchan al pipeline
RFC 9457 existente (un listener sobre `AccessDeniedException`, ver
[`api-error-contract.md`](../api-error-contract.md)), no esparcidas por los handlers.

Descartado: *coarse-grained* (solo endpoints sensibles) → pierde acciones de UI relevantes para la
reconstrucción de jornada. Descartado: *fine-grained* (cada request) → "log explosion" (assets,
proxy al PWA, Mercure, healthchecks). Descartado: capturar todo y **decidir en la persistencia** →
IO desperdiciado y decisión tardía.

### D3 — Persistencia por nivel: `activity` async, `security` write-before-send; transporte `audit` dedicado

`AuditLogger->log(...)` **ramifica por `level`**, porque los dos niveles tienen contratos de
durabilidad distintos:

- **`activity`** (alto volumen, observabilidad) — `AuditLogger` despacha un `RecordAuditEntry` al
  transporte Messenger dedicado `audit` y un `RecordAuditEntryHandler` lo inserta en `audit_log`.
  Modelo **best-effort**: el *request path* queda libre de IO de auditoría y **acepta** perder un
  registro si el proceso muere antes de encolar.
- **`security`** (denegaciones, elevaciones, uso de API keys — raro y fuera del path caliente) —
  inserción **síncrona write-before-send** en el mismo ciclo de request: una denegación nunca se
  pierde en silencio si la request llegó a ejecutarse. Reutiliza el escritor DBAL idempotente
  (`INSERT … ON CONFLICT (id) DO NOTHING`), de modo que un eventual reintento async de respaldo es
  un no-op por PK.

**Transporte `audit` dedicado** (no comparte el `async` de los `DomainEvent`): son dos modelos de
cola distintos —el de dominio es *consistency-sensitive* (write-before-send/outbox), el de auditoría
es *loss-tolerant* y de mayor volumen—. Aislarlos fija tres invariantes operativas: auditoría no
puede bloquear la propagación de `DomainEvent` (head-of-line coupling), no puede saturar el
transporte `failed` de dominio, y su escalado no afecta a los consumidores de `event_store`. En dev
se pliega en `messenger_worker`; en prod puede tener su propio worker (mismo patrón que
`scheduler_maintenance` / `scheduler_worker`).

El sistema es, por diseño, **observabilidad operativa con pérdida parcial tolerada en `activity`**,
**no** logging forense uniforme: `security` es traza *compliance-grade* (durable), `activity` es
telemetría de jornada (best-effort).

Descartado: best-effort **uniforme** para ambos niveles — abre un *gap* silencioso justo en el nivel
`security`, que es el que existe para la investigación forense y el cumplimiento (D4/D6). Descartado:
`INSERT` síncrono **para todo** → latencia p95 y contención de DB en el alto volumen de `activity`.
Descartado: compartir el transporte `async` con los `DomainEvent` → mezcla semánticas de
retry/DLQ/throughput y reintroduce el acoplamiento de fallos que D1 evita en el tipo.

### D4 — Niveles, retención diferenciada, append-only y GDPR

Dos niveles (`activity`, `security`); el tercer eje (cambios de datos) **ya** lo cubre `DomainEvent`
y **no se duplica**. `audit_log` es **append-only**: sin `UPDATE`, sin `DELETE` desde la app salvo
el proceso de retención.

`audit_log` **es PII** (`actor_id`, `ip`, `user_agent`) → decisiones obligatorias:

- **Retención por nivel**: `security` se conserva más; `activity` rota agresivo. Prune por Symfony
  Scheduler, reutilizando el patrón `HandledDomainEventPruner` (ver
  [`domain-event-handler-idempotency.md`](./domain-event-handler-idempotency.md)).
- **Borrado GDPR**: el "olvídame" **pseudonimiza** `actor_id` (la traza de seguridad sobrevive),
  no borra filas. Coherente con "hard delete por defecto, salvo que el borrado rompa un requisito"
  de [`rules/database.md`](../rules/database.md); se registra en
  [`rules/security.md`](../rules/security.md) y `PRODUCTION_SECURITY_CHECKLIST.md`.
- **Sin payload sensible** en `metadata` (IDs y discriminantes, no cuerpos de entidad).

Descartado: misma retención para todo (incumple separación legal de seguridad vs actividad).
Descartado: borrado físico en *erasure* (destruye la auditoría de seguridad, que es justo lo que se
quiere preservar).

### D5 — Ubicación: backbone en `Shared/`, consulta en `Backoffice/Audit/`

- **`Shared/`** (transversal a todos los contextos): puerto `AuditLogger` (seam), mensaje interno
  `RecordAuditEntry`/modelo `AuditLogEntry`, `ActorContext`, `AuditPolicy` genérica, el subscriber de
  captura, el adaptador de storage y el transporte `audit`.
- **`Backoffice/Audit/`**: read model de investigación — timeline, filtros (actor / fecha /
  recurso), proyecciones, UI admin. **Backoffice no escribe auditoría, solo la consume.**

Respeta el gate de aislamiento de contextos (`make php.lint.bounded-context`): `Erpify\Shared\…` es
siempre importable; la captura no entra en el `Domain/` de ningún contexto.

Descartado: todo en `Backoffice` (la captura es transversal, la disparan todos los contextos).
Descartado: todo en `Shared` (la vista/consulta admin es una feature de negocio, no kernel).

### D6 — `ActorContext` + correlación obligatoria desde el día 1; `audit_session` diferido

Toda entrada de auditoría (`AuditLogEntry`) lleva, sin excepción, `correlation_id` (id de request estable) y `actor_id`
(*nullable* hoy —rutas públicas / acciones de sistema—, **no-null** en cuanto exista auth real).
Esto es lo que habilita la "reconstrucción de jornada" sin tabla de sesión: `actor_id` +
`correlation_id` + `occurred_on` reconstruyen el timeline.

Descartado **hoy**: tabla `audit_session` con ciclo de vida propio (¿cuándo cierra una sesión?) —
especulativo, sin flujo que lo pida (CLAUDE.md: "nada especulativo"). **Trigger de revisita**:
cuando exista auth con sesiones y un caso de investigación que exija correlación de sesión explícita
más allá de la agregación por `actor_id` + ventana temporal.

### D7 — `ActorContext` tipado: `actor_type` obligatorio, `actor_id` nullable según el tipo

D6 fija `actor_id` *nullable* hasta que exista auth, pero un `actor_id = null` es ambiguo: ¿ruta
anónima, proceso de sistema (cron/scheduler), API key o webhook externo? Esa fuga semántica degrada
justo la consulta forense que el subsistema existe para servir. Se cierra tipando el actor:

```text
ActorType  enum  anonymous | system | api_key | user
```

- `actor_type` es **obligatorio** en toda entrada de auditoría (nunca null).
- `actor_id` es nullable y su presencia depende del tipo.

| `actor_type` | `actor_id`       |
|--------------|------------------|
| `anonymous`  | `null`           |
| `system`     | `null`           |
| `api_key`    | `<api_key_uuid>` |
| `user`       | `<user_uuid>`    |

`ActorContext` (en `Shared/…/Audit/Domain`, value object de dominio, sin dependencias de framework):

```php
final readonly class ActorContext
{
    public function __construct(
        public ActorType $type,
        public ?string $actorId,
    ) {}
}
```

`actor_type` (quién) y `level` de D4 (`activity`/`security`, qué clase de auditoría) son ejes
**ortogonales**; no se colapsan. Esto **completa** D6, no lo revoca: la correlación obligatoria y el
`actor_id` nullable-hasta-auth siguen vigentes; solo se añade el discriminante de tipo.

Descartado: derivar el tipo de `actor_id IS NULL` + heurística de ruta — no es consultable, es
frágil y se rompe en cuanto entra `api_key`. Descartado: enum más rico (`cron`, `webhook`) hoy —
`system` los cubre; se expande cuando un caso real lo exija (YAGNI).

## Esbozo de esquema (`audit_log`)

```text
id              uuid v7  PK (Shared/Domain/Entity/Identifiable, id app-assigned)
level           enum     activity | security
action          string   p.ej. BANK_ACCOUNTS_VIEWED, UNAUTHORIZED_UPDATE_ATTEMPT
actor_type      enum     anonymous|system|api_key|user — obligatorio (D7)
actor_id        uuid     NULL salvo api_key/user (D7)
correlation_id  uuid     obligatorio (request id estable)
resource_type   string   NULL  (p.ej. BankAccount)
resource_id     uuid     NULL
metadata        jsonb    sin payload sensible
ip              inet     NULL
user_agent      string   NULL
occurred_on     timestamptz
```

Índices previstos: `(actor_id, occurred_on)` (jornada), `(correlation_id)` (request), `(level,
occurred_on)` (retención/prune), `(resource_type, resource_id)` (investigación por recurso).

## Alcance de captura — Fase 1

D2 fija el *mecanismo* (captura híbrida, la política decide); esto fija el *alcance* inicial para
que no derive en criterios por desarrollador. La `AuditPolicy` clasifica por **categoría**; los
`action` concretos aterrizan por módulo (hoy solo existe y está cableado `BANK_ACCOUNTS_VIEWED`; el
resto son ejemplos, algunos de módulos futuros, marcados con \*).

- **`activity`** — navegación a pantallas de backoffice, consultas de listado y de detalle,
  búsquedas, filtros, exportaciones.
  Ej.: `BANK_ACCOUNTS_VIEWED`, `BANK_ACCOUNT_VIEWED`\*, `CUSTOMERS_SEARCHED`\*, `INVOICES_EXPORTED`\*.
- **`security`** — `AccessDeniedException`, accesos a recursos fuera de alcance, login/logout (cuando
  exista auth), uso de API keys, elevaciones de permiso, operaciones admin sensibles.
  Ej.: `ACCESS_DENIED`, `UNAUTHORIZED_UPDATE_ATTEMPT`\*, `ROLE_ELEVATION_ATTEMPT`\*.
- **Nunca** — assets, health checks, Mercure, polling, requests técnicos y cualquier endpoint HTTP
  genérico. En particular **no** existe un `action` `HTTP_REQUEST` (sería el *log explosion* que D2
  rechaza: capturar todo y decidir tarde).

(\* `action` aún no definido; aterriza cuando exista el módulo.)

## Triggers de revisita

(a) Auth real / multirrol → `actor_id` pasa a `NOT NULL` y entra `audit_session` si hay caso. (b)
Volumen de `activity` que exija particionado temporal o un *sink* externo (deja de ser PostgreSQL).
(c) Necesidad de exponer la auditoría a un contexto cliente (hoy solo Backoffice la consume).

## Implementación

Subsistema transversal: su construcción es una épica propia y no se mezcla con el código de la
feature que la originó. Secuencia sugerida: (1) `Shared` — `AuditLogger` (seam) + `ActorContext` +
`AuditPolicy` + `RecordAuditEntry`/`RecordAuditEntryHandler` + escritor `AuditLogEntry` (DBAL) +
transporte `audit` + migración `audit_log`; (2) captura — subscriber `kernel.terminate` + listener
de `AccessDeniedException` (vía `security` síncrona) + `ActorContext`; (3) retención —
`AuditLogPruner` (Scheduler) + estrategia de pseudonimización GDPR; (4) `Backoffice/Audit` — read
model + UI de investigación. El estado de implementación vive en el issue/PR correspondiente.

**Secuencia frente a auth (cerrada):** el backbone de auditoría se implementa **antes** de User/RBAC.
`actor_id` permanece nullable (`actor_type` ∈ {`anonymous`, `system`, `api_key`}) hasta que exista
autenticación; el día que entre User solo cambia el proveedor de `ActorContext` (`ActorContextFactory`)
— schema, bus, storage, retención y read model no se tocan.
