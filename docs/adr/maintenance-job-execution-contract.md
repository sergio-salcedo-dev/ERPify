# ADR — Maintenance Job Execution Contract (jobs de mantenimiento programados)

> **Status:** accepted · contrato normativo, **sin código todavía** (interfaces diferidas a un 3.er caso) · **Date:** 2026-06-25
> · **Scope:** cross-cutting `Shared` — todo job de mantenimiento programado: poda de retención de `audit_log`,
> poda de dedup de `handled_domain_event`, chequeo de backlog de dead-letter, y los futuros.

## Contexto

El ERP tiene ya varios **jobs de mantenimiento** que corren fuera del request, por Symfony Scheduler:
la poda de retención de `audit_log` ([`audit-activity-log.md`](./audit-activity-log.md) D4), la poda del
claim store `handled_domain_event` ([`domain-event-handler-idempotency.md`](./domain-event-handler-idempotency.md))
y el alarmado de backlog de dead-letter ([`dead-letter-observability.md`](./dead-letter-observability.md)).
Cada uno eligió **por separado** su locking, su batching y su idempotencia. Funciona, pero el patrón vive
**distribuido por implementación, no por contrato** — y eso, al crecer, deriva en divergencia de estrategia
entre bounded contexts.

Esto **no** es un job system ni un scheduler genérico: es un **mini-runtime de mantenimiento declarativo**.
Estamos en fase de **formación de patrón** (2 variaciones estructurales reales), no de estabilización. La
regla en esta fase es dura: con 1–2 implementaciones **no se cristaliza el contrato en código** — el riesgo
no es la inconsistencia, es la **convergencia prematura** (lock-in del shape antes de ver su variabilidad
natural). Por eso este ADR fija el **contrato normativo** (invariantes + API mental) y **difiere las
interfaces reales** hasta que un 3.er caso confirme la estabilidad estructural.

## Invariantes del contrato

Un job de mantenimiento **conforme** satisface, sin excepción:

- **I1 — Ejecutor único: la garantía, no el mecanismo (estándar, opt-in).** Un job que muta estado corre
  **a lo sumo una vez en concurrencia**: la plataforma ofrece **un** mecanismo *single-executor* estándar y un
  intento concurrente se **salta** (skip), no se encola. El invariante es la **garantía de ejecutor único**,
  no su implementación — hoy se realiza con un *advisory lock* de Postgres **session-level**, nombrado por
  intención (`PostgresAdvisoryLock`); sustituirlo por otro primitivo (lock distribuido, *leader election*) no
  reabre este contrato. Nunca un locking ad-hoc por job. Un job de **solo lectura** puede declararse exento.
- **I2 — Idempotencia: convergencia observable por chunk.** Cada unidad de trabajo es segura de repetir:
  **re-ejecutar un chunk completo tras un éxito parcial o un redelivery debe converger al mismo estado final
  observable** que una ejecución única. Es el criterio verificable en review — `DELETE older-than` lo cumple
  por construcción; un `INSERT`/`UPSERT` sólo si lleva clave natural + `ON CONFLICT`. Esto es lo que hace
  tolerable el *at-least-once* del transporte (I6).
- **I3 — Acotación: toda mutación demuestra su cota (batching o volumen documentado).** Ninguna mutación de
  barrido total sin cota. Cada job declara una **estrategia de acotación explícita**: lo normal es **batching**
  (lotes de tamaño limitado, para acotar duración de lock y presión de vacuum); la excepción sancionada es un
  **volumen máximo demostrado y documentado** (p. ej. la dedup de claims, ínfima por diseño). Lo que el
  contrato prohíbe no es *no batchear*, sino una mutación cuya cota nadie ha demostrado.
- **I4 — Frontera de policy: la policy emite un `ExecutionPlan`, nada más.** La decisión de **qué** mantener
  vive en una policy **pura** de dominio que produce un plan como **dato** — sin conocer infraestructura, sin
  leer estado de runtime externo. En el momento en que una policy alcanza una `Connection`, un reloj de
  sistema o estado mutable global, **viola el contrato** (no es opinión de estilo). La infra vive en el lado
  de ejecución (`ExecutionStep`), nunca en el de planificación.
- **I5 — Propiedad de scheduling: tres ejes separados.** El **schedule** define *cuándo*; el **job** define
  *qué*; la **plataforma** define *cómo* se garantiza la ejecución (locking, batching, retry). Cada job es
  **propiedad de su capability** (su message + handler + policy viven en ella). La topología del transporte
  (schedule propio por capability vs. dispatch centralizado) es un eje aparte, deliberadamente **no fijado
  aquí** — ver "Alternativas".
- **I6 — Retry & observabilidad.** Los jobs viajan sobre Messenger *at-least-once*; el handler tolera
  redelivery vía I2; un fallo agota reintentos y aterriza en el transporte `failed`, **observable** (no un
  warning tragado). Sin compensación bespoke. Cada ejecución reporta su `Result` (filas afectadas / *saltada
  por lock*).
- **I7 — Materializabilidad del plan: estrategia, no dataset.** Construir un `ExecutionPlan` es **acotado** y
  **no depende del cardinal de los datos mantenidos**: el plan describe *cómo* barrer (umbrales, niveles,
  tamaño de lote), nunca **materializa el conjunto de filas afectadas**. Un `plan()` que devuelve
  `findAllExpiredRows()` viola el contrato aunque siga siendo puro (I4) — traslada una bomba de memoria al
  lado de planificación. Las filas se descubren y se drenan dentro de `ExecutionStep::execute()`, lote a lote (I3).

## API mental (pseudo-interfaces — **NO** son archivos PHP todavía)

El contrato se expresa, normativamente, como tres formas. Son la **API mental**, no código en `src/`:

```text
MaintenanceJob
    plan(now): ExecutionPlan          // puro (I4): decide QUÉ; sin infra

ExecutionPlan
    steps(): iterable<ExecutionStep>  // el plan como DATO (I4); construcción acotada (I7), unidades acotadas (I3)

ExecutionStep
    execute(Connection): Result       // el borde de infra: idempotente (I2), bajo el lock del job (I1)
```

La asimetría es el contrato: **planificar es puro y acotado (I4, I7), ejecutar es infra.** `plan()` jamás ve
una `Connection` ni materializa el dataset; sólo `ExecutionStep::execute()` toca filas, lote a lote.

## Mapeo a los casos reales (evidencia, no abstracción)

| Caso | `plan(now)` | `ExecutionStep` | I1 ejecutor único | I2 idemp. | I3 cota |
|------|-------------|-----------------|-------------------|-----------|---------|
| Poda retención `audit_log` | `AuditRetentionPolicy::thresholdsAt(now)` | un `AuditRetentionThreshold` (drena un nivel) | sí (`PostgresAdvisoryLock`) | sí (`DELETE` older-than) | sí — batching (lotes por `id`) |
| Poda dedup `handled_domain_event` | umbral único `claimedBefore` | 1 step (`pruneClaimedBefore`) | **no — retrofit pendiente** | sí | sí — volumen ínfimo demostrado |
| Poda retención transporte `failed` | umbral único `pruneFailedBefore` | 1 step (drena una cola) | sí (`PostgresAdvisoryLock`) | sí (`DELETE` older-than) | sí — batching por `id` **y** presupuesto de reloj |
| Backlog dead-letter | — (solo lectura) | report | exento (I1) | N/A | N/A |

La poda del transporte `failed` es el **tercer caso mutante**, y el segundo conforme: copia la forma del de
`audit_log` (mismo lock, mismo `ORDER BY id … FOR UPDATE`, mismo `DELETE` older-than idempotente) y añade
una cota que aquél no necesita — un presupuesto de reloj —, porque es el único que llega a una tabla que
**nunca** se podó, así que su primera ejecución se enfrenta al historial entero mientras sostiene el lock.
Que sea una copia es justo lo que el *timing* de abajo esperaba para juzgar la estabilidad estructural; que
conviva en el MISMO fichero de schedule con el caso no conforme hace la divergencia de I1 más visible, no
menos urgente.

La poda de `audit_log` es el **primer caso conforme de facto**. La de `handled_domain_event` diverge **sólo en
I1** (aún sin el lock estándar): su acotación ya es conforme por la excepción de I3 (volumen ínfimo demostrado,
sin batching), y converge del todo al adoptar el advisory lock al retrofitearse. El reporte de dead-letter es
conforme por vacuidad (no muta).

## Decisión de timing — contrato sin código ahora

Cristalizar las interfaces en `src/` a **N=2** arriesga fijar el *shape del runtime* antes de observar su
variabilidad: lock-in prematuro. El contrato normativo da consistencia (revisable contra estos invariantes)
sin esa deuda.

> **Las interfaces y abstracciones compartidas se introducirán cuando un *tercer* caso de mantenimiento
> confirme la estabilidad estructural del patrón** — entonces se extraen `MaintenanceJob`/`ExecutionPlan`/
> `ExecutionStep` a un módulo `Shared/Maintenance/`, se retrofitan los pruners de Audit y Event, y se decide
> el eje de dispatch (I5).

## Alternativas descartadas

- **Interfaces reales en `src/` ya** — convergencia prematura a N=2; interfaces sin implementor (deuda
  especulativa) o adopción forzada antes de ver el 3.er caso.
- **Retrofit del pruner de Audit como reference impl ya** — mezcla contrato + feature + refactor en el PR de
  la feature (#370); congela el *abstraction boundary* demasiado pronto.
- **Scheduler central (god-scheduler) ahora** — *coordination debt* sobre el subsistema Event ya mergeado y
  un registry de runtime implícito; el eje de dispatch (I5) queda explícitamente abierto.
- **Tercera exención de I1 (mutación idempotente + volumen acotado → exenta)** — descartada **por ahora**, no
  prohibida. Con un único caso conocido (`handled_domain_event`) la categoría no ha probado estabilidad
  estructural; mantener la divergencia **visible** preserva la presión de convergencia hacia el estándar de
  plataforma y evita ablandar la condición de exención de *single-executor por defecto* a *demuestra que
  puedes saltártelo*. Reabrible si aparecen varios casos con la misma forma (mutación + idempotencia fuerte +
  cota estricta + beneficio nulo del lock) — entonces sería un patrón, no una excepción aislada.
- **Status quo (sin contrato)** — divergencia por implementación: justo el riesgo que este ADR cierra.
