---
title: 'Shared · `AuditLogPruner` — retención diferenciada por nivel de `audit_log` (Symfony Scheduler)'
type: 'feature'
created: '2026-06-25'
status: 'ready-for-dev'
baseline_commit: '19a1f5ae'
epic: 'epic-3'
story: '3.1'
context:
  - '{project-root}/api/CLAUDE.md'
  - '{project-root}/docs/rules/database.md'
  - '{project-root}/docs/architecture-api.md'
  - '{project-root}/docs/adr/audit-activity-log.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `audit_log` (raw-DBAL, append-only, escrito por `DbalAuditLogWriter`) crece sin cota. La
obligación legal/PII exige (a) mantener la tabla acotada y (b) conservar la traza `security` más tiempo
que la `activity` (separación legal entre seguridad y actividad). Hoy `DbalAuditLogWriter` documenta
explícitamente que "no hay ruta `UPDATE`/`DELETE` aquí: retención y borrado GDPR son un concern aparte"
— este story aporta ese concern de retención (el borrado GDPR es el story 3.2).

**Approach:** Una poda programada con **retención por nivel**, siguiendo el patrón ya existente en
`Shared/Event` (`HandledDomainEventMaintenanceSchedule` + `PruneHandledDomainEventsMessage` +
`PruneHandledDomainEventsHandler` + puerto `HandledDomainEventPruner` + `DbalHandledDomainEventPruner`),
pero **propiedad de la capability `Shared/Audit`** (vertical slice — Audit posee su propia retención,
no se cuelga del schedule de event-dedup). La poda es la **única** ruta de `DELETE` sobre `audit_log`
(FR9); el resto sigue siendo append-only. La ventana se aplica sobre `occurred_on`, apoyada en el índice
existente `audit_log_level_idx (level, occurred_on)`.

## Boundaries & Constraints

**Always:**
- **Excepción de dominio explícita** — `Shared/Audit/Domain/Exception/InvalidAuditRetentionPolicy.php`
  extends `DomainException`, marker-less (como `InvalidAuditLogEntry`): precondición de **config de
  confianza**, fuera del mapeo RFC 9457. (`NumberOfChildren` de PHPMD está **suprimido en la base
  `DomainException`** por diseño — "one flat root, not a hierarchy to rebalance" — así que un hijo más es
  coherente con esa decisión, no deuda.)
- **Política de retención que emite el plan (estrategia como policy object)** —
  `Shared/Audit/Domain/AuditRetentionPolicy.php` (`final readonly`,
  `__construct(int $activityRetentionDays, int $securityRetentionDays)`, invariante
  `security > activity` y ambos `>= 1` → lanza `InvalidAuditRetentionPolicy`) expone
  `thresholdsAt(DateTimeImmutable $now): list<AuditRetentionThreshold>`, iterando `AuditLevel::cases()`
  con `match` exhaustivo. El VO `Shared/Audit/Domain/AuditRetentionThreshold.php`
  (`{AuditLevel $level, DateTimeImmutable $deleteBefore}`) es una línea del plan. Así la **decisión por
  nivel vive en la policy como dato**, no como control-flow en el handler. PHP puro, unit-testeable sin
  DB ni reloj.
- **Puerto que ejecuta el plan** — `Shared/Audit/Application/AuditLogPruner.php` (interfaz):
  `prune(AuditRetentionThreshold ...$plan): int` (devuelve filas borradas).
- **Advisory lock reutilizable** — `Shared/Persistence/Infrastructure/PostgresAdvisoryLock.php`,
  `withTryLock(string $name, callable $work): bool`, **session-level** (`pg_try_advisory_lock` +
  `pg_advisory_unlock`, no `xact`, para abarcar los varios statements autocommit del borrado por lotes).
  Primitivo reutilizable (otros pruners convergen aquí en el futuro), no inline en Audit.
- **Adaptador DBAL — borrado por lotes bajo lock** —
  `Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php`, `#[AsAlias(AuditLogPruner::class)]`,
  inyecta `Connection` + `PostgresAdvisoryLock` (+ `batchSize`, default 5000). Bajo un único advisory
  lock drena cada nivel del plan en lotes `DELETE ... WHERE id IN (SELECT id ... LIMIT :batch)` hasta
  vaciar — **parametrizado** (`:level`, `:threshold` `Types::DATETIMETZ_IMMUTABLE`, `:batch`
  `Types::INTEGER`). Acota duración de lock + presión de vacuum (exposición real: backfill/cold-start) y
  serializa barridos concurrentes (defense-in-depth, no corrección: el DELETE older-than es idempotente y
  prod corre `scheduler_worker` réplica única). No transacción propia, no traga fallos.
- **Mensaje de tick** — `Shared/Audit/Infrastructure/Messenger/Maintenance/PruneAuditLogMessage.php`:
  `final readonly`, `__construct(public int $activityRetentionDays = 90, public int $securityRetentionDays = 365)`.
  Las ventanas son **parametrizables** vía estos defaults del constructor (convención del repo, igual que
  `PruneHandledDomainEventsMessage::$retentionDays` y `ReportDeadLetterBacklogMessage`). `security` (365)
  `>` `activity` (90).
- **Handler delgado (sin loop)** — `Shared/Audit/Infrastructure/Messenger/Maintenance/PruneAuditLogHandler.php`,
  `#[AsMessageHandler]`, inyecta `AuditLogPruner` + `Clock` (`Shared/Clock/Domain`). Construye
  `new AuditRetentionPolicy(...)` y hace `pruner->prune(...$policy->thresholdsAt($this->clock->now()))`.
  La decisión por nivel vive en la policy y la estrategia de borrado en el pruner; ninguna se expresa
  aquí como control-flow. (Reloj inyectado — Audit ya depende de `Clock` vía `SealedAuditEntryFactory`;
  tests deterministas.)
- **Schedule propio de Audit** — `Shared/Audit/Infrastructure/Messenger/Maintenance/AuditLogMaintenanceSchedule.php`,
  `#[AsSchedule('audit_maintenance')]` implements `ScheduleProviderInterface`: una `RecurringMessage::every('1 day', new PruneAuditLogMessage())`.
- **Transporte consumido** — el schedule `audit_maintenance` autogenera el transporte
  `scheduler_audit_maintenance`; **añadirlo al `messenger:consume`** del `messenger_worker` en
  `compose.yaml` (dev, plegado) y del `scheduler_worker` single-replica en `compose.prod.yaml` (prod,
  aislado para tick único). Sin esta arista la poda no corre.
- **Docs** — `docs/rules/database.md` documenta `audit_log` como **excepción de retención justificada**
  (append-only + purga programada con ventana acotada por nivel); una línea en `docs/architecture-api.md`
  (sección audit/messenger) menciona el `AuditLogPruner` + `scheduler_audit_maintenance`.

**Ask First (decididas):**
- **Schedule propio (`audit_maintenance`) vs. reusar `scheduler_maintenance`** → **propio**. Symfony
  prohíbe dos providers con el mismo nombre de schedule (`AddScheduleMessengerPass` lanza). Reusar el
  transporte exigiría colgar el tick de `HandledDomainEventMaintenanceSchedule` (acoplaría
  `Shared/Event` → `Shared/Audit` y sobrecargaría un schedule nombrado por event-dedup). Capability-ownership
  gana; el coste (un arg en dos `messenger:consume`) se paga en el mismo commit + test de schedule.
- **Ventanas por defecto** → activity 90 d / security 365 d. Reversibles (defaults del mensaje).
- **`occurred_on` como eje de la ventana** (no un `created_at` físico) → es el instante de negocio sellado
  por `SealedAuditEntryFactory`; es la única columna temporal de la tabla.
- **Reloj inyectado vs. `new DateTimeImmutable('-N days')`** → `Clock` inyectado (Audit ya lo usa).
- **Shared `MaintenanceSchedule` centralizado (1 transporte, tagged messages)** → **diferido** (review).
  Resolvería el wiring per-feature en `compose`, pero toca el Event subsystem ya mergeado en el mismo PR
  (coordination debt) e introduce un registry de runtime implícito. El patrón estándar "Maintenance Job"
  de ERPify es un diseño aparte, no este PR. Audit mantiene su schedule propio por ahora.
- **Chunking + advisory lock** → adoptados como **hardening estructural** (no hotspot medido — pre-prod);
  el lock es un primitivo **reutilizable** (`Shared/Persistence`) que otros pruners adoptarán al converger.
- No abrir ninguna otra ruta `UPDATE`/`DELETE` sobre `audit_log`: la poda es la **única** (FR9). El
  borrado GDPR (3.2) será `UPDATE` de pseudonimización, no `DELETE`.
- No interpolar `:level`/`:threshold`/`:batch` en el SQL (siempre bindings parametrizados).
- No expresar la decisión por nivel como control-flow en el handler/Application: vive en la policy
  (el plan) y la ejecuta el pruner. Sin `AuditRetentionEnforcer` de Application intermedio.
- No `pg_advisory_xact_lock` (transaccional): el lock debe abarcar los varios statements autocommit del
  borrado por lotes → session-level.
- No tocar `DbalAuditLogWriter`, el `AuditLogSchemaListener`, ni el wire HTTP.
- No `CREATE INDEX` nuevo: `audit_log_level_idx (level, occurred_on)` ya sirve al `WHERE level = … AND occurred_on < …`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Poda mixta | filas `activity` y `security` con edades varias | borra `activity` con `occurred_on < now-90d` y `security` con `occurred_on < now-365d`; deja el resto | N/A |
| `security` sobrevive a `activity` | fila `security` de 200 d | **no** se borra (200 < 365), aunque una `activity` de 200 d **sí** | N/A |
| Idempotencia | correr la poda dos veces seguidas | la 2ª no borra nada nuevo (DELETE older-than es idempotente) | N/A |
| Política inválida | `new AuditRetentionPolicy(365, 90)` (security ≤ activity) | excepción en construcción → el tick falla ruidosamente | `InvalidAuditRetentionPolicy` |
| Tabla vacía / nada fuera de ventana | `audit_log` sin filas viejas | 0 borradas | N/A |
| Borrado por lotes | stale > `batchSize` para un nivel | drena en varios `DELETE … LIMIT :batch` hasta vaciar | N/A |
| Barrido concurrente | un 2.º prune mientras otro corre | el advisory lock lo salta (no encola, no race) | `withTryLock` → `false` |
| Conteo exacto (integración) | filas sembradas en Postgres real con edades/niveles concretos | se borran **exactamente** las fuera de ventana | N/A |

## Verification

- **Unit** `AuditRetentionPolicyTest`: invariante (security>activity, ≥1) lanza `InvalidAuditRetentionPolicy`; `thresholdsAt` planea un cutoff por nivel con `now` fijo; cutoff `security` más antiguo que `activity`.
- **Unit** `PruneAuditLogHandlerTest`: doble `RecordingAuditLogPruner` + reloj fijo → **un** `prune()` con el plan (2 thresholds) correcto.
- **Functional/integración (Postgres real, `KernelTestCase` en transacción con rollback)** `AuditLogPrunerFunctionalTest`: siembra `activity`/`security` de edades concretas; `batchSize` bajo prueba el bucle de lotes; assert recuento exacto + supervivencia de la traza en ventana.
- **Functional** `PostgresAdvisoryLockFunctionalTest`: dos sesiones (`DriverManager`) → lock tomado bloquea al 2.º; liberado tras el work, el 2.º adquiere.
- Test de wiring del schedule (`getRecurringMessages` count) **eliminado** (review: testear comportamiento, no wiring).
- **Gates:** `make php.stan`, `make php.quality` (incl. deptrac, bounded-context, phpmd), `make php.unit`.

</frozen-after-approval>
