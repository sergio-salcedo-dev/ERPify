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
- **Política de retención como value object de dominio puro** — `Shared/Audit/Domain/AuditRetentionPolicy.php`:
  `final readonly`, `__construct(int $activityRetentionDays, int $securityRetentionDays)`. Invariante
  en el constructor: `securityRetentionDays > activityRetentionDays` y ambos `>= 1`, si no lanza
  `\InvalidArgumentException` (precondición de **config de confianza**, nunca entrada de cliente, nunca
  toca RFC 9457 — por eso SPL y **no** una nueva subclase de `DomainException`: ese árbol ya tiene 16
  hijos directos y `NumberOfChildren` de PHPMD dispara a partir de 15). Método
  `thresholdFor(AuditLevel $level, DateTimeImmutable $now): DateTimeImmutable` → `$now` menos la ventana
  del nivel (`match` exhaustivo sobre los 2 casos del enum). PHP puro, cero framework, unit-testeable
  sin DB ni reloj.
- **Puerto de poda por nivel** — `Shared/Audit/Application/AuditLogPruner.php` (interfaz):
  `pruneOlderThan(AuditLevel $level, DateTimeImmutable $threshold): int` (devuelve filas borradas).
- **Adaptador DBAL** — `Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php`,
  `#[AsAlias(AuditLogPruner::class)]`, inyecta `Connection`:
  `DELETE FROM audit_log WHERE level = :level AND occurred_on < :threshold` — **parametrizado**
  (`:level` = `AuditLevel->value`, `:threshold` con `Types::DATETIMETZ_IMMUTABLE`). No transaccción
  propia, no traga fallos (igual que `DbalHandledDomainEventPruner`).
- **Mensaje de tick** — `Shared/Audit/Infrastructure/Messenger/Maintenance/PruneAuditLogMessage.php`:
  `final readonly`, `__construct(public int $activityRetentionDays = 90, public int $securityRetentionDays = 365)`.
  Las ventanas son **parametrizables** vía estos defaults del constructor (convención del repo, igual que
  `PruneHandledDomainEventsMessage::$retentionDays` y `ReportDeadLetterBacklogMessage`). `security` (365)
  `>` `activity` (90).
- **Handler** — `Shared/Audit/Infrastructure/Messenger/Maintenance/PruneAuditLogHandler.php`,
  `#[AsMessageHandler]`, inyecta `AuditLogPruner` + `Clock` (`Shared/Clock/Domain`). Construye
  `new AuditRetentionPolicy($m->activityRetentionDays, $m->securityRetentionDays)` y para **cada**
  `AuditLevel::cases()` llama `pruneOlderThan($level, $policy->thresholdFor($level, $this->clock->now()))`.
  (Reloj inyectado, no `new DateTimeImmutable` — coherente con el resto del módulo Audit, que ya depende
  de `Clock` vía `SealedAuditEntryFactory`; tests deterministas.)
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

**Never:**
- No abrir ninguna otra ruta `UPDATE`/`DELETE` sobre `audit_log`: la poda es la **única** (FR9). El
  borrado GDPR (3.2) será `UPDATE` de pseudonimización, no `DELETE`.
- No interpolar `:level`/`:threshold` en el SQL (siempre bindings parametrizados).
- No introducir un `AuditRetentionEnforcer` de Application separado (el bucle de 2 niveles vive en el
  handler — regla de tres; el orquestador no compra testabilidad que el test de handler no dé ya).
- No nueva subclase de `DomainException` (cap de PHPMD `NumberOfChildren`).
- No tocar `DbalAuditLogWriter`, el `AuditLogSchemaListener`, ni el wire HTTP.
- No `CREATE INDEX` nuevo: `audit_log_level_idx (level, occurred_on)` ya sirve al `WHERE level = … AND occurred_on < …`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Poda mixta | filas `activity` y `security` con edades varias | borra `activity` con `occurred_on < now-90d` y `security` con `occurred_on < now-365d`; deja el resto | N/A |
| `security` sobrevive a `activity` | fila `security` de 200 d | **no** se borra (200 < 365), aunque una `activity` de 200 d **sí** | N/A |
| Idempotencia | correr la poda dos veces seguidas | la 2ª no borra nada nuevo (DELETE older-than es idempotente) | N/A |
| Política inválida | `new AuditRetentionPolicy(365, 90)` (security ≤ activity) | excepción en construcción → el tick falla ruidosamente | `\InvalidArgumentException` |
| Tabla vacía / nada fuera de ventana | `audit_log` sin filas viejas | 0 borradas | N/A |
| Conteo exacto (integración) | filas sembradas en Postgres real con edades/niveles concretos | se borran **exactamente** las fuera de ventana | N/A |

## Verification

- **Unit** `AuditRetentionPolicyTest`: invariante (security>activity, ≥1), `thresholdFor` por nivel con `now` fijo, umbral `security` más antiguo que `activity`.
- **Unit** `PruneAuditLogHandlerTest`: mock `AuditLogPruner` + reloj fijo → 2 llamadas `pruneOlderThan`, una por nivel, con el umbral correcto.
- **Unit** `AuditLogMaintenanceScheduleTest`: `getSchedule()->getRecurringMessages()` cuenta 1.
- **Functional/integración (Postgres real, `KernelTestCase` en transacción con rollback, patrón `HandledDomainEventDeduplicatorFunctionalTest`)** `AuditLogPrunerFunctionalTest`: sembrar filas `activity`/`security` de edades concretas vía `DbalAuditLogWriter`/INSERT, `pruneOlderThan`, assert recuento exacto y supervivencia de la traza dentro de ventana.
- **Gates:** `make php.stan`, `make php.quality` (incl. deptrac, bounded-context, phpmd), `make php.unit`.

</frozen-after-approval>
