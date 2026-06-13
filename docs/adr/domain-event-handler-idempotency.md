# ADR — Idempotencia de handlers de eventos de dominio: claim por DBAL + schema listener

> **Estado:** aceptado · **Fecha:** 2026-06-13 · **Ámbito:** `api/src/Shared` (DomainEvent + Messenger + Persistence).

## Contexto

Symfony Messenger entrega **at-least-once**: el worker puede reejecutar un handler tras un fallo
parcial o un redelivery del transporte. Los handlers naturalmente idempotentes (publicación Mercure,
upserts) lo toleran; los que tienen un **efecto externo no idempotente** (enviar email, llamar a una
API de terceros) no pueden reejecutarse sin duplicar el efecto.

Lo evita el puerto `Shared/Application/DomainEvent/DomainEventHandlerDeduplicator`: el handler
**reclama** su par `(eventId, handler)` antes de actuar y **libera** la reclamación si falla, para que
el retry del transporte siga abierto. La reclamación persiste en la tabla
`handled_domain_event (event_id, handler, claimed_at)`, PK compuesta `(event_id, handler)` (migración
`Version20260612221100`). Consumidor de ejemplo: `BankChangedNotifyEmailHandler`.

## Decisiones

### D1 — Claim atómico vía DBAL crudo, sin entidad ORM

`Shared/Infrastructure/Messenger/DbalDomainEventHandlerDeduplicator` reclama con un único
`INSERT … ON CONFLICT (event_id, handler) DO NOTHING` (devuelve `true` solo si insertó 1 fila) y
libera con un `DELETE`. Razones:

- **Atomicidad sin ventana check-then-act.** El claim es un solo `INSERT` atómico: ante dos workers
  concurrentes sobre el mismo evento, exactamente uno gana. Un `SELECT`-then-`INSERT` abriría esa
  carrera.
- **No cierra el EntityManager.** Una colisión de unicidad vía ORM sería
  `UniqueConstraintViolationException` en `flush()`, que **cierra el EntityManager** a mitad del
  handler. Con DBAL la colisión es un resultado normal (`0` filas afectadas), no una excepción.

Descartado: **mapear la tabla como entidad ORM.** No es un agregado de dominio sino una primitiva de
concurrencia de infraestructura (sin invariantes ni significado de negocio); arrastraría el problema
del EM cerrado y el coste de hidratación/UoW para algo que solo hace claim/release.

### D2 — Tabla schema-aware vía `postGenerateSchema` listener

Al no haber entidad ORM, Doctrine no conoce la tabla: `make db.diff` la vería huérfana y generaría un
`DROP TABLE handled_domain_event`. Para evitarlo, `Shared/Infrastructure/Persistence/HandledDomainEventSchemaListener`
la inyecta en el schema en memoria en `postGenerateSchema` — Doctrine queda *ORM-unaware* (dominio
limpio) pero *schema-aware* (diffs limpios, sin migración manual recurrente). Es el **mismo patrón**
que la FK física Bank/BankAccount: ver D2 de [`bank-bankaccount-modeling.md`](./bank-bankaccount-modeling.md).

La PK compuesta se declara con
`PrimaryKeyConstraint::editor()->setUnquotedColumnNames('event_id', 'handler')` (DBAL 4.4 deprecó
`Table::setPrimaryKey()` en favor de `addPrimaryKeyConstraint()`).

Descartado: **`doctrine.dbal.schema_filter`** (regex que excluye la tabla del diff en vez de añadirla
al schema). Más simple, pero (a) `doctrine:schema:validate` dejaría de detectar drift de columnas en
esa tabla, (b) es configuración global que crece con cada tabla excluida, y (c) el repo no usa
`schema_filter` mientras que el patrón listener ya está establecido (dos listeners). Un único
mecanismo schema-aware es más barato de razonar.

### D3 — Replay operativo: lo suprime el `eventId` estable; la poda lo acota

El claim se indexa por `(eventId, handler)` y el `eventId` es **estable** para un evento dado, así que
un `messenger:failed:retry` de un evento ya reclamado **no reenvía**: el `INSERT … ON CONFLICT` choca
con la fila existente y `claim()` devuelve `false`. Es el comportamiento correcto bajo entrega
at-least-once — dos entregas del *mismo* evento no deben duplicar el email — y dos actualizaciones de
negocio *distintas* obtienen `eventId` distintos (verificado), por lo que cada una se notifica.

La única consecuencia es que un **replay operativo intencionado** (reejecutar a mano un mensaje ya
manejado para forzar otro envío) queda suprimido mientras exista su fila de claim. Escape:
`PruneHandledDomainEventsHandler` (planificado a diario vía `HandledDomainEventMaintenanceSchedule`)
caduca las reclamaciones pasada la ventana de retención (`PruneHandledDomainEventsMessage::retentionDays`,
30 días por defecto), tras lo cual el evento vuelve a ser reclamable; un replay **inmediato** requiere
borrar a mano la fila `handled_domain_event` de ese `(event_id, handler)`. Se acepta conscientemente:
para un email de notificación, no duplicar pesa más que poder reenviar a voluntad.

## Verificación

`doctrine:schema:validate` sobre una BD migrada desde cero queda **in sync** (la tabla aparece con su
PK compuesta `(event_id, handler)`), confirmando que el listener reproduce el esquema de la migración
sin proponer cambios.
