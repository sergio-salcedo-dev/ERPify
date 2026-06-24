---
baseline_commit: d0e6092eb98eebc5b8e80221d3ddb7cd02e224e8
---

# Story 1.5: Migrar `BANK_ACCOUNTS_VIEWED` al subsistema real (primer actor auditado)

Status: review

<!-- Epic 1 — Registro de auditoría end-to-end (backbone + primer actor auditado).
     Quinta y ÚLTIMA historia del Epic 1: la validación end-to-end del backbone (1.1→1.4) con un actor real.
     Migra el placeholder BankAccountsViewedAuditEvent al puerto AuditLogger de 1.4.
     Ver ADR docs/adr/audit-activity-log.md (D1, D2 — la vía explícita AuditLogger->log se estrena aquí, FR18). -->

## Story

Como investigador de seguridad,
quiero que "ver las cuentas de un banco" quede registrado de forma duradera en `audit_log` (sustituyendo el placeholder de log-line), con actor + correlación,
para tener la **primera traza forense real end-to-end** y validar todo el backbone (`ActorContext` + `correlationId` + persistencia async + tabla append-only + idempotencia) sobre una acción real, no sobre fixtures de subsistema.

Esta historia **cierra el Epic 1**: no añade superficie nueva del backbone — la **consume**. `BankAccountSearcher` deja de despachar el mensaje-placeholder `BankAccountsViewedAuditEvent` por `MessageBusInterface` directo y pasa a llamar al **único seam público** de 1.4, `AuditLogger->log(...)`, tras una lectura con éxito. La migración **retira** el archetype provisional (`BankAccountsViewedAuditEvent` + `RecordAuditLogOnBankAccountsViewed`), **elimina** la entrada del `BankAccountSearcher.php` en `api/.event-dispatch-allowlist` (con su bloque de comentario) y **actualiza** `docs/architecture/event-catalog.md` (sección *Non-domain signals*) para reflejar que `BANK_ACCOUNTS_VIEWED` ya no es una log-line síncrona sino una fila durable en `audit_log` (async `activity` sobre el transporte `audit`). Es **Application puro** en `Backoffice/BankAccount` + dos borrados + dos ediciones de doc/allowlist + un escenario Behat de extremo a extremo. NO toca captura genérica (Epic 2), retención/GDPR (Epic 3) ni read model (Epic 4).

> **Dependencia dura — 1.4 debe estar en disco.** Esta historia consume el puerto `AuditLogger`, el `RecordAuditEntryHandler`, el transporte `audit` y `ActorContextFactory`, todos creados por la **Story 1.4**. En la entrega de PR único los commits siguen 1.1 → 1.2 → 1.3 → 1.4 → 1.5; **si 1.4 no está en disco, 1.5 está bloqueada** (T0). La firma exacta de `AuditLogger->log(...)` la fija 1.4: `log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = [])` — el recurso viaja como el VO opcional `AuditResource` (1.4 D-1.4.a), **no** como dos `?string` sueltos. Esta spec usa esa firma; T0 la re-verifica contra el `1-4-...md` real antes de cablear (Pregunta 1).

## Acceptance Criteria

**AC1 — `BankAccountSearcher` registra vía `AuditLogger->log(...)`, no despachando un mensaje.**
**Given** `BankAccountSearcher` tras una lectura con éxito (banco existente, página de cuentas resuelta —incluso vacía),
**When** se ejecuta `search($bankId, $query)`,
**Then** invoca `auditLogger->log('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY, AuditResource::of('Bank', $bankId))` (la acción `'BANK_ACCOUNTS_VIEWED'`, nivel `activity`, recurso `Bank:$bankId` vía el VO `AuditResource` de 1.4) **una sola vez**, tras `bankAccountSearchRepository->search(...)` y **antes** de `return $page`;
**And** **no** despacha ya `BankAccountsViewedAuditEvent` ni inyecta `MessageBusInterface`; consultar las cuentas de un banco existente es un acceso auditable independientemente del número de cuentas devueltas (FR18, FR2).

**AC2 — La frontera best-effort vive en 1.4: el llamador NO envuelve la llamada en try/catch.**
**Given** que la Story 1.4 garantiza que un fallo del despacho de auditoría **nunca** impide completar el caso de uso (frontera best-effort + observabilidad técnica, AC de 1.4 / D3 / NFR2),
**When** `BankAccountSearcher` llama a `auditLogger->log(...)`,
**Then** la llamada es **directa**, sin `try/catch` ni log de fallo en el searcher (esa responsabilidad se traslada íntegra a `AuditLogger`); el `LoggerInterface` que el searcher inyectaba **solo** para el warning de auditoría se elimina si no tiene otro uso (verificar: hoy `logger` solo se usa en `recordAccess()`), y el método privado `recordAccess()` con su `try/catch` desaparece (FR2, NFR2).

**AC3 — Migración completa: placeholder retirado y allowlist limpio.**
**Given** la migración,
**When** se completa,
**Then** se **eliminan** los ficheros `api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php` y `api/src/Backoffice/BankAccount/Infrastructure/Audit/RecordAuditLogOnBankAccountsViewed.php` (y los directorios `Audit/` que queden vacíos en `Application/` y `Infrastructure/`);
**And** desaparece de `api/.event-dispatch-allowlist` la línea `src/Backoffice/BankAccount/Application/BankAccountSearcher.php` **junto con** su bloque de comentario explicativo (las líneas 12–16 del fichero), porque tras 1.5 el searcher ya no importa `MessageBusInterface` y la exención deja de tener objeto (FR18).

**AC4 — Una request real que lista cuentas produce exactamente una fila forense correcta.**
**Given** una request real `GET /backoffice/banks/{bankId}/accounts` (misma ruta sin prefijo `/api/v1` que `search.feature` y el cliente Behat) que lista las cuentas de un banco existente, procesada de extremo a extremo (incluido el consumo del transporte `audit` por un worker),
**When** se procesa,
**Then** aparece **exactamente una** fila en `audit_log` con `action = 'BANK_ACCOUNTS_VIEWED'`, `level = 'activity'`, `actor_type = 'anonymous'` (no hay auth aún → `ActorContextFactory` resuelve `anonymous` para `/api/*`; `actor_id` null), `correlation_id` = el `correlation-id` de **esa** request, y `resource_type = 'Bank'` / `resource_id = {bankId}` identificando el banco consultado;
**And** la columna `metadata` **no** contiene IBAN ni ningún valor de cuenta ni PII de negocio (FR12, FR14) — Behat.

**AC5 — Idempotencia: una única ejecución observable genera una única fila.**
**Given** una única ejecución observable de la operación (la request real que lista cuentas, consumida una vez por el transporte `audit`),
**When** se procesa de extremo a extremo,
**Then** aparece **exactamente una** fila en `audit_log` para ese acceso — Behat asserta solo lo observable: una ejecución → una fila;
**And** la garantía técnica de idempotencia por PK ante una reentrega at-least-once (`INSERT … ON CONFLICT (id) DO NOTHING`, idempotente por el `id` UUIDv7 acuñado en `AuditLogEntry::create()`, 1.2/1.4) queda **delegada al test de integración de 1.3** (`AuditLogWriterIdempotencyTest`), que la prueba por-PK; Behat **no** la re-demuestra (el transporte `in-memory` drena la cola al consumir y no existe step de redelivery → no es ejecutable end-to-end, y no se introduce un mecanismo artificial de reentrega) (FR4, NFR7).

**AC6 — `event-catalog.md` *Non-domain signals* actualizado; nota de allowlist retirada.**
**Given** `docs/architecture/event-catalog.md`,
**When** se actualiza,
**Then** la fila de resumen "Audit log" (§ *Reading this catalog*, ~línea 24) y la sección *Non-domain signals* (~línea 101) reflejan que `BANK_ACCOUNTS_VIEWED` **persiste de forma durable en `audit_log`** vía `AuditLogger` (Messenger async `activity` sobre el transporte `audit`), **no** una log-line `bank_accounts.viewed`, **no** un mensaje síncrono del bus por defecto; la fila del placeholder `BankAccountsViewedAuditEvent` se reescribe o retira coherentemente, y se **elimina** el párrafo que la describía como la excepción documentada del `php.lint.event-bus` allowlist (`api/.event-dispatch-allowlist`), porque esa excepción ya no existe (FR18).

**AC7 — Aislamiento intacto y net-mejora de isolation; sin cambios en gates de arquitectura.**
**Given** que `Backoffice/BankAccount` importa el puerto `Erpify\Shared\Audit\Application\AuditLogger` (siempre importable: `Erpify\Shared\…`),
**When** se corren `make php.deptrac` y `make php.lint.bounded-context`,
**Then** quedan verdes **sin** allowlist ni bloque deptrac nuevos; al contrario, 1.5 **retira** una entrada del `event-dispatch-allowlist` (el searcher deja de importar `MessageBusInterface`) → es una **mejora neta** de aislamiento, no un coste;
**And** el gate `make php.lint.event-bus` / `tests/Unit/Shared/Architecture/EventDispatchGateTest.php` queda verde con la entrada retirada (su `testAllowlistEntriesPointToExistingFiles` no se rompe — solo verifica que las entradas restantes apunten a ficheros existentes; y `testNoMessageBusInterfaceImportInApplicationLayer` no flaggea `BankAccountSearcher` porque ya no importa el FQCN).

**AC8 — Gates verdes (validación end-to-end del backbone completo).**
**Given** todas las piezas,
**When** se corren los gates,
**Then** `make php.stan`, `make php.quality` (deptrac + bounded-context + event-bus + phpmd + cs-fixer + rector) y `make php.behat` quedan verdes — esta historia es la prueba de extremo a extremo de 1.1→1.4 sobre una acción real (Epic 1: "una capacidad verificable, no infraestructura pura").

## Tasks / Subtasks

- [x] **T0 — Verificar prerequisitos en disco (1.1 → 1.4)** — bloqueante.
  - [x] Confirmar el seam de **1.4**: existe `api/src/Shared/Audit/Application/AuditLogger.php` (puerto público con `log(...)`), su adaptador, el `RecordAuditEntryHandler`, el transporte `audit` cableado en `config/packages/messenger.yaml` (+ routing de `RecordAuditEntry` → `audit`, y override `when@test` a `in-memory://?serialize=true`), y `ActorContextFactory` (resuelve `anonymous` en `/api/*` sin auth). **Si 1.4 no está en disco, 1.5 está bloqueada.**
  - [x] **Leer la firma real de `AuditLogger::log(...)`** en el fichero de 1.4 y reconciliarla con la llamada de T1. El AC del epic la describe como `log(action, level, resource?, metadata?)`. Verificar el orden de parámetros, los nombres (`resourceType`/`resourceId` vs un objeto/tupla de recurso), y si `metadata` es opcional con default `[]`. **Cualquier desajuste se eleva como Pregunta 1** y se ajusta T1 a la firma canónica de 1.4 — **no** se inventa una firma.
  - [x] Confirmar 1.1/1.2/1.3 en disco: `ActorContext`/`ActorType` (1.1), `AuditLevel`/`AuditLogEntry`/`RecordAuditEntry` (1.2), `audit_log` + `DbalAuditLogWriter` + migración aplicada (1.3, `make db.migrate` corrido). El escenario Behat de T5 necesita la tabla `audit_log` ya migrada en la BD de test.

- [x] **T1 — Migrar `BankAccountSearcher` al puerto `AuditLogger`** (AC1, AC2, AC7) → `api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php`
  - [x] **Reemplazar** la dependencia `private MessageBusInterface $messageBus` por `private AuditLogger $auditLogger` en el constructor. **Eliminar** `private LoggerInterface $logger` (hoy solo lo usa el warning de `recordAccess()`; tras la migración no tiene consumidor — verificar con un grep del cuerpo antes de borrar).
  - [x] **Eliminar** el método privado `recordAccess(string $bankId): void` íntegro (con su `try/catch` y el `logger->warning(...)`) y su docblock.
  - [x] En `search()`, sustituir `$this->recordAccess($bankId);` por la llamada directa al seam (ajustar a la firma confirmada en T0):
    ```php
    $this->auditLogger->log(
        self::AUDIT_ACTION,
        AuditLevel::ACTIVITY,
        AuditResource::of(self::RESOURCE_TYPE, $bankId),
    );
    ```
    Mantener el orden actual del cuerpo de `search()`: guard de existencia → `search()` del repositorio → `log(...)` → `return $page` (la auditoría se registra **solo en el camino de éxito**, tras la lectura, también para una página vacía).
  - [x] Declarar las constantes de acción/recurso en el propio searcher (es su dueño semántico — la acción es propia de este caso de uso, no del backbone genérico): `private const string AUDIT_ACTION = 'BANK_ACCOUNTS_VIEWED';` y `private const string RESOURCE_TYPE = 'Bank';`. **No** crear un enum/registro central de acciones (D-1.2.e: las `action` son constantes open-ended por módulo; aquí solo hay una — Regla de Tres).
  - [x] Ajustar los `use`: **quitar** `Symfony\Component\Messenger\MessageBusInterface`, `Symfony\Component\Messenger\Exception\ExceptionInterface`, `Psr\Log\LoggerInterface` (si quedan sin uso) y `Erpify\Backoffice\BankAccount\Application\Audit\BankAccountsViewedAuditEvent`; **añadir** `Erpify\Shared\Audit\Application\AuditLogger`, `Erpify\Shared\Audit\Domain\AuditLevel` y `Erpify\Shared\Audit\Domain\AuditResource`. Verificar con `make php.stan` que no queda ningún `use` huérfano (también lo pillaría cs-fixer en `php.quality`).
  - [x] Actualizar el **docblock de clase** para que describa el comportamiento **actual** sin referencia al cambio: "…paginates the accounts, and records the access through the `AuditLogger` audit seam — only on success, even for an empty page (consulting an existing bank's accounts is an auditable access regardless of how many accounts it has)." Sin "previously"/"replaces" ni IDs de story/FR/AC (regla de comentarios de `CLAUDE.md`). **Metadata PII-free**: no pasar IBAN ni ningún campo de cuenta a `metadata` (no se pasa `metadata` en absoluto aquí; default `[]`) — FR12.
  - [x] **Seguridad / mass-assignment:** la acción y el `resourceType` son **constantes acuñadas por el código**, nunca input del cliente; `resourceId` es el `bankId` de la ruta, ya guardado por `Uuid::ensure()` aguas arriba (el `BankExistenceChecker::ensureExists()` corre primero). No hay nueva superficie de input.

- [x] **T2 — Borrar el archetype placeholder** (AC3) →
  - [x] `rm api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php`
  - [x] `rm api/src/Backoffice/BankAccount/Infrastructure/Audit/RecordAuditLogOnBankAccountsViewed.php`
  - [x] Eliminar los directorios `Application/Audit/` e `Infrastructure/Audit/` de `Backoffice/BankAccount` **si quedan vacíos** tras los borrados (no dejar carpetas huérfanas). Confirmar con un listado que no haya otros ficheros dentro.
  - [x] **Grep de referencias colgantes** antes de declarar T2 hecho: `git grep -nF 'BankAccountsViewedAuditEvent'` y `git grep -nF 'RecordAuditLogOnBankAccountsViewed'` no deben devolver nada en `api/src`, `api/tests`, `api/features`, `api/config` ni en `docs/` salvo lo que T3/T4 reescriban. Comprobar en particular `config/packages/messenger.yaml` (que el placeholder **no** tuviera routing — hoy se despacha **sync**, sin entrada de routing) y que ningún test unitario/funcional referenciaba el placeholder.

- [x] **T3 — Retirar la entrada del event-dispatch allowlist** (AC3, AC7) → `api/.event-dispatch-allowlist`
  - [x] Borrar la línea `src/Backoffice/BankAccount/Application/BankAccountSearcher.php` (la única entrada de path hoy, línea 16) **y** su bloque de comentario explicativo inmediatamente anterior (líneas 12–16: el párrafo "# Audit axis — NOT the domain-event stream…"). Dejar la cabecera genérica del fichero (líneas 1–10) intacta.
  - [x] Verificar que el fichero queda **sin ninguna entrada de path activa** (solo cabecera + comentarios) — es lo correcto: ya no hay ninguna Application que importe `MessageBusInterface` directamente tras 1.5. El gate sigue verde (`EventDispatchGateTest`: `testNoMessageBusInterfaceImportInApplicationLayer` verde sin exenciones; `testAllowlistEntriesPointToExistingFiles` verde con cero entradas).

- [x] **T4 — Actualizar `docs/architecture/event-catalog.md`** (AC6) → `docs/architecture/event-catalog.md`
  - [x] **Fila de resumen "Audit log"** (tabla de § *Reading this catalog*, ~línea 24): cambiar "Best-effort observability log lines for access auditing." por una descripción de la fila durable — p. ej. "Durable access-audit rows in `audit_log` (the operational/actor audit axis), written through the `AuditLogger` seam." y, si procede, actualizar el "Contract owner" a la sección *Non-domain signals* / el ADR de auditoría.
  - [x] **Sección *Non-domain signals*** (~línea 101) + la **fila** de `BankAccountsViewedAuditEvent` (~línea 108): reescribir la fila para `BANK_ACCOUNTS_VIEWED` de modo que refleje: *Dispatched by* `BankAccountSearcher` (llamada directa a `AuditLogger->log`, best-effort aislado en `AuditLogger`); *Transport* el transporte **`audit`** dedicado (async, no `sync`/default bus); *Consumer* `RecordAuditEntryHandler` → `audit_log` (no la log-line `bank_accounts.viewed`); *Payload* la fila `AuditLogEntry` (`action`, `level=activity`, `actor_type`, `correlation_id`, `resource_type=Bank`/`resource_id`, `metadata` **PII-free**, sin IBAN). Considerar si la sección sigue describiendo un "non-`DomainEvent` signal" coherente (lo es: `RecordAuditEntry` no es `DomainEvent`) o si conviene un puntero a `audit-activity-log.md`.
  - [x] **Párrafo del allowlist** (~línea 111): **eliminar** "It is the documented exception on the `php.lint.event-bus` allowlist (`api/.event-dispatch-allowlist`) — it must dispatch `MessageBusInterface` directly…" (ya no aplica). Mantener/ajustar la frase de cierre que apunta al ADR `audit-activity-log.md` como el eje de auditoría (ahora **implementado**, no "frozen epic").
  - [x] **Source link** (~línea 110): el enlace a `BankAccountsViewedAuditEvent.php` queda roto al borrar el fichero (T2) → re-apuntarlo a una fuente viva (`BankAccountSearcher.php` o el puerto `AuditLogger`), nunca a un fichero inexistente (regla de Markdown links de `CLAUDE.md`: enlazar solo a ficheros concretos).
  - [x] **Boy-scout:** barrer en la sección tocada cualquier comentario change-relative o ID de story que encuentres. Mantener la densidad del doc (sin narrativa de proceso).

- [x] **T5 — Escenario Behat end-to-end** (AC4, AC5) → `api/features/backoffice/bank_account/audit.feature` (NEW)
  - [x] **Aislamiento de la tabla por feature:** añadir `audit_log` a la sentencia `TRUNCATE` de `api/tests/Behat/Context/FixturesContext.php` (línea 131: `'TRUNCATE event_store, projection_checkpoint, bank_count, handled_domain_event RESTART IDENTITY'` → añadir `, audit_log`), para que la fixture de cada feature empiece con `audit_log` vacío y los conteos reflejen solo la operación bajo prueba. (Mismo patrón que las demás tablas raw-DBAL del backbone.)
  - [x] **Escenario 1 — una fila forense correcta (AC4):**
    - Fijar la correlación de la request para poder asertarla: `Given I add "X-Correlation-Id" header equal to "<uuid-v7-canónico>"` (step `HttpRequestContext::I add :name header equal to :value`; usar un UUIDv7 canónico en minúscula que el `CorrelationIdListener` acepte tal cual — si no es canónico, el listener acuña uno nuevo y la aserción de igualdad fallaría).
    - `When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001/accounts?limit=100"` (mirror del escenario verde de `search.feature`; banco con 1 cuenta).
    - `Then the response status code should be 200` (la lectura no se ve afectada por la auditoría).
    - **Consumir el transporte `audit`** (la persistencia de `activity` es ASYNC tras 1.4 — sin consumir, la fila aún no existe): `When I consume 1 message from the "audit" transport` (step `MessengerConsumerContext::I consume :count message(s) from the :transportName transport`, que construye un `Worker` real contra `messenger.transport.audit` — el patrón "consume in-memory via Worker, no messenger:consume"). En `when@test` el transporte `audit` es `in-memory://?serialize=true`.
    - Asertar la fila vía `SqlQueryContext`. Una sola consulta por columnas explícitas (sin `SELECT *`, NFR9), con `correlation_id` filtrado al de la request:
      ```gherkin
      When I execute the SQL query "SELECT action, level, actor_type, actor_id, resource_type, resource_id, correlation_id, metadata FROM audit_log WHERE action = 'BANK_ACCOUNTS_VIEWED'"
      Then there should have 1 records in SQL result
      And the SQL result as JSON should be:
        """
        [
          {
            "action": "BANK_ACCOUNTS_VIEWED",
            "level": "activity",
            "actor_type": "anonymous",
            "actor_id": null,
            "resource_type": "Bank",
            "resource_id": "11111111-1111-7000-8000-000000000001",
            "correlation_id": "<el mismo uuid del X-Correlation-Id>",
            "metadata": "[]"
          }
        ]
        """
      ```
      El literal de `metadata` es `'[]'`: el `DbalAuditLogWriter` de 1.3 escribe `json_encode([])` = `'[]'` para el `metadata` vacío (no `'{}'`), y así lo devuelve `fetchAllAssociative`. **Aserción anti-PII explícita** (FR12): `And the SQL result as JSON should be:` no contiene IBAN ni campos de cuenta — el `metadata` vacío lo garantiza; opcionalmente añadir un assert `the SQL result ... should not contain "DE89370400440532013000"` si existe un step de no-contención, o dejarlo cubierto por el match exacto del JSON.
  - [x] **Escenario 2 — una única ejecución, una única fila (AC5):**
    - Behat asserta **solo lo observable**: una única ejecución observable de la operación (la request + consumo del escenario 1) genera **una única** fila en `audit_log`. El escenario 1 ya lo cubre con su `Then there should have 1 records in SQL result`; un escenario 2 dedicado es opcional (mismo aserto, sin valor añadido) — si se añade, es un mirror explícito de "una ejecución → una fila" sin intentar forzar un redelivery.
    - **NO** escribir un escenario de reentrega: el transporte `in-memory` **drena la cola al consumir** y no existe un step de redelivery → un escenario end-to-end de redelivery **no es ejecutable**, y **no** se introduce un mecanismo artificial de reentrega (no bajar el transporte a `sync`, no reinyectar a mano un envelope con el mismo `id`).
    - La garantía técnica de idempotencia **por PK** (`INSERT … ON CONFLICT (id) DO NOTHING`, mismo `id` → no-op) queda **delegada al test de integración de 1.3** (`AuditLogWriterIdempotencyTest`), que la prueba directamente contra el escritor. Citarlo en *Completion Notes* como la cobertura de la idempotencia por-PK (decisión cerrada, ver nota de decisión bajo D-1.5.e).
  - [x] **Presupuesto de queries:** el escenario 1 NO debe añadir aserciones `N requests got executed only for doctrine connection "default"` sobre la request HTTP — la inserción de `activity` es **async** (la hace el worker, fuera del request path, NFR2), así que el conteo del request sigue siendo **2** (guard + página), idéntico a `search.feature`. La inserción `audit_log` ocurre en el consumo del transporte, no en la request. Si se añade un assert de presupuesto al request, mantenerlo en `2` (la auditoría no toca el request path).
  - [x] El `SqlQueryContext` (`I execute the SQL query …` en una **conexión nombrada/side**) **no** lo cuenta el `TestDebugDataHolder`; la inserción del worker se ve consultando la tabla, no por presupuesto. No mezclar el assert de filas con el de queries.

- [x] **T6 — Gates** (AC7, AC8): orden importa →
  1. `make php.stan` sobre `BankAccountSearcher.php` (único src tocado) — verde, sin `use` huérfanos.
  2. Stack arriba + `audit_log` migrada (1.3) + transporte `audit` cableado (1.4). `make php.behat` (corre el nuevo `audit.feature` + el `search.feature` existente, que **no** debe regresar — sigue dando 2 queries y 200).
  3. `make php.quality` — deptrac + bounded-context + **event-bus** (verde con la entrada del allowlist retirada) + phpmd + cs-fixer + rector. `api/config/reference.php` se regenera: **commitea** el diff, no `git checkout`.
  4. **Re-correr `make php.stan`** sobre los ficheros asentados (Rector puede reescribir en `php.quality`).
  - [x] **Barrer del diff** cualquier comentario con IDs de story/AC/FR/NFR/`D-1.5.x` antes del commit final (regla de comentarios de `CLAUDE.md`). Aplicar boy-scout a `BankAccountSearcher.php` y a la sección tocada de `event-catalog.md`.

## Dev Notes

### Contexto del subsistema (leer antes de tocar código)

- **Esta historia es el cierre del Epic 1 y la única que CONSUME el backbone sin crear superficie nueva.** 1.1 dio el actor (`ActorContext`/`ActorType`), 1.2 el contrato de datos (`AuditLevel`/`AuditLogEntry`/`RecordAuditEntry`), 1.3 el almacenamiento (`audit_log` append-only + `DbalAuditLogWriter` idempotente), 1.4 el seam (`AuditLogger->log` con branching por `level`, transporte `audit`, `RecordAuditEntryHandler`, `ActorContextFactory`). 1.5 **cablea el primer actor real** (`BANK_ACCOUNTS_VIEWED`) al seam y **prueba end-to-end** que una request real deja una traza forense correcta e idempotente. Fuente: [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) (D1, D2 — la vía explícita `AuditLogger->log` se estrena aquí, FR18; D3 — `activity` async sobre el transporte `audit`).
- **Cambio de comportamiento: de sync log-line a async fila durable.** HOY el placeholder corre **síncrono** (`BankAccountsViewedAuditEvent` se despacha por `MessageBusInterface` **sin routing** → bus por defecto → `RecordAuditLogOnBankAccountsViewed` escribe `logger->info('bank_accounts.viewed', …)` en el mismo ciclo). TRAS 1.5 corre **async**: `AuditLogger->log` (nivel `activity`) **encola** un `RecordAuditEntry` en el transporte `audit`, y el `RecordAuditEntryHandler` lo inserta en `audit_log` cuando el worker lo consume. **Implicación directa para el test:** el escenario Behat **debe consumir el transporte `audit`** para ver la fila — sin consumir, la cola tiene el mensaje pero `audit_log` está vacío. Es exactamente la diferencia que separa un test verde de uno que "no encuentra la fila".
- **La frontera best-effort es de 1.4, no del searcher.** El placeholder envolvía el `dispatch` en un `try/catch` que tragaba `Messenger\Exception\ExceptionInterface` y logueaba un warning — porque el despacho directo podía fallar y la lectura no debía caer. Tras 1.4, esa garantía ("un fallo de auditoría nunca tumba el caso de uso, y el fallo queda registrado por observabilidad técnica", AC de 1.4 / D3 / NFR2) vive **dentro** de `AuditLogger`. Por eso 1.5 llama **directo**, sin `try/catch`: duplicar la frontera en el searcher sería responsabilidad mal ubicada (SRP) y código muerto (el seam ya no lanza al llamador). Ver D-1.5.b.
- **El placeholder ya era deliberadamente NO-`DomainEvent`.** `BankAccountsViewedAuditEvent` nunca extendió `DomainEvent` (era una señal de observabilidad, no un hecho de negocio) — por eso vivía en el `event-dispatch-allowlist` como la **única** excepción sancionada al gate del `EventBus`. 1.5 lo generaliza al `RecordAuditEntry` de 1.2 (también no-`DomainEvent`) y **retira la excepción**: el searcher deja de importar `MessageBusInterface` por completo, así que el allowlist ya no necesita exonerarlo. Es una mejora neta de aislamiento (D-1.5.c).

### Decisiones técnicas

**D-1.5.a — La acción `BANK_ACCOUNTS_VIEWED` y `resourceType='Bank'` son constantes del searcher (su dueño semántico), no del backbone. [decisión de diseño, mirror D-1.2.e]**
*Principio:* SRP + dueño-de-la-semántica — la acción describe *este* caso de uso, no es vocabulario genérico del subsistema. *Objetivo:* el backbone (`Shared/Audit`) no conoce acciones concretas (no es su responsabilidad); cada módulo acuña las suyas como constantes locales (D-1.2.e: "los `action` son constantes open-ended por módulo"). *Coste / alternativa descartada:* un enum/registro central `AuditAction` con `BANK_ACCOUNTS_VIEWED` — abstracción prematura para **una** acción (Regla de Tres); acoplaría `Shared/Audit` a las acciones de `Backoffice` (fuga de dependencia hacia afuera) y obligaría a tocar `Shared` cada vez que un módulo audita algo nuevo. Constantes `private const` en `BankAccountSearcher` (boring-over-clever). *Trigger de revisita:* si las `action` ganan reglas de formato (`CONTEXT_ACTION`) o un registro central con ≥3 consumidores que las necesiten enumerar → entonces VO/enum (lo decidirá esa historia).

**D-1.5.b — Llamada directa a `AuditLogger->log`, SIN `try/catch` en el llamador. [mejora argumentada sobre el placeholder]**
*Principio en juego:* SRP — la política de "best-effort / un fallo de auditoría no tumba la lectura" es **una** responsabilidad, y 1.4 la asigna a `AuditLogger`. Duplicarla en `BankAccountSearcher` mezcla orquestación de lectura con política de resiliencia de auditoría. *Objetivo:* el searcher queda mínimo y honesto (un cuerpo de tres pasos: guard → search → audit); la frontera best-effort se prueba **una vez** en los tests de 1.4, no en cada llamador. *Coste / alternativa descartada:* mantener el `try/catch` "por si acaso" — sería código muerto (el seam de 1.4 no propaga al llamador por contrato) y mentiría sobre dónde vive la garantía; un futuro lector creería que `AuditLogger->log` puede lanzar y copiaría el patrón. Se descarta. **Verificación a hacer en T0:** que el contrato de 1.4 efectivamente **no** propaga (si 1.4 deja escapar alguna excepción al llamador, esto se reabre como Pregunta — pero el AC de 1.4 es explícito: "nunca impide completar el caso de uso").

**D-1.5.c — Retirar la entrada del `event-dispatch-allowlist` (no solo "dejar de usarla"). [higiene de gate]**
*Principio:* el allowlist es una lista de **exenciones activas y revisables**; una entrada cuyo motivo desapareció es ruido que invita a copiarla. *Objetivo:* tras 1.5 ninguna Application importa `MessageBusInterface` directamente, así que el fichero debe quedar sin entradas de path — refleja la verdad. El propio comentario de la entrada (líneas 12–16) y el `revisit trigger (b)` de `docs/adr/event-driven-architecture.md` previeron exactamente este momento ("…this entry is removed when that subsystem is built"). *Coste / alternativa descartada:* dejar la entrada "por si vuelve" — el gate `EventDispatchGateTest::testAllowlistEntriesPointToExistingFiles` la mantendría verde (el fichero existe), pero sería una exención fantasma; peor, si el searcher vuelve a importar `MessageBusInterface` por error, la exención lo enmascararía. Se retira con su comentario.

**D-1.5.d — `metadata` vacío (default `[]`), PII-free por construcción. [seguridad / FR12]**
*Principio:* minimización de datos — `metadata` admite discriminantes (filtros, formato de export), pero esta acción no tiene ninguno que aporte valor forense que no esté ya en `action`/`resource`. *Objetivo:* cero superficie de fuga de PII (FR12: nunca IBAN ni cuerpos de entidad); el banco se identifica por `resource_id`, no replicando datos de cuenta. *Coste / alternativa descartada:* meter el conteo de cuentas o los filtros de la query en `metadata` — el conteo no es forense (y un test lo cablearía al tamaño de fixtures, frágil) y los filtros de paginación tampoco; YAGNI. Se pasa `metadata` por defecto (`[]`). *Trigger de revisita:* si Epic 2 (captura genérica) o un caso forense piden discriminantes (p. ej. los filtros aplicados) → se añaden entonces, siempre IDs/discriminantes, nunca PII.

**D-1.5.e — Behat consume el transporte `audit`; presupuesto de queries del request intacto (2). [validación del modelo async]**
*Principio:* el test debe ejercitar el camino real (async), no un atajo. *Objetivo:* probar que (1) el request path sigue libre de IO de auditoría (sigue en **2** queries: guard + página, NFR2) y (2) la fila aparece **tras** consumir el transporte — exactamente el contrato de D3 (`activity` async). *Coste / alternativa descartada:* forzar `audit` a `sync://` en test para ver la fila sin consumir — falsearía el modelo (en prod es async) y ocultaría regresiones del routing/handler; se descarta. En `when@test` el transporte `audit` es `in-memory://?serialize=true` (como `async`/`failed`), y el `MessengerConsumerContext` construye un `Worker` real contra él (patrón "no `messenger:consume`", que reseteería el `in-memory`).

**D-1.5.f — La idempotencia por-PK (AC5) la prueba el test de integración de 1.3; Behat solo asserta "una ejecución → una fila". [decisión cerrada]**
*Principio:* el test ejercita lo que su nivel puede observar — Behat es end-to-end sobre el modelo async real, no un banco de pruebas de reentrega. *Objetivo:* AC5 ejecutable y honesto: Behat afirma la invariante observable (una única ejecución observable de la operación genera una única fila `audit_log`), y la garantía técnica de no-duplicación ante un redelivery at-least-once (`INSERT … ON CONFLICT (id) DO NOTHING`, mismo `id` → no-op) queda **delegada al `AuditLogWriterIdempotencyTest` de 1.3**, que la prueba por-PK contra el escritor. *Coste / alternativa descartada:* montar un escenario Behat de redelivery — **no es ejecutable**: el transporte `in-memory` drena la cola al consumir y no existe un step de reentrega del mismo envelope; fabricar uno (bajar el transporte a `sync`, o reinyectar a mano un envelope con el mismo `id`) falsearía el modelo o probaría plumbing de test, no el contrato. Se descarta; **no** se introduce ningún mecanismo artificial de redelivery. *Nota:* el `id` que ancla el `ON CONFLICT` no es observable desde Behat, lo que confirma que el nivel correcto para la prueba por-PK es la integración de 1.3.

### YAGNI / alcance — qué NO hacer aquí

- **No** crear ni modificar `AuditLogger`, `RecordAuditEntryHandler`, el transporte `audit`, `ActorContextFactory` ni el routing en `messenger.yaml` — todo es de **Story 1.4** (1.5 lo consume). Si falta algo, 1.4 no está completa → bloquea (T0).
- **No** crear un enum/registro central de acciones de auditoría (D-1.5.a) — una `private const` en el searcher.
- **No** envolver la llamada a `AuditLogger->log` en `try/catch` ni reintroducir un `LoggerInterface` de fallback (D-1.5.b).
- **No** poner conteos, filtros, IBAN ni ningún dato de cuenta en `metadata` (D-1.5.d, FR12).
- **No** capturar la navegación genérica vía `kernel.terminate` ni `AuditPolicy` (**Epic 2**), retención/poda/GDPR (**Epic 3**), ni read model/UI (**Epic 4**).
- **No** tocar la migración de `audit_log` (1.3) ni el esquema; 1.5 solo escribe filas vía el seam.
- **No** editar `tools/deptrac/deptrac.yaml`, `deptrac.baseline.yaml` ni `api/.bounded-context-allowlist` (AC7 — `Shared/Audit` autoenrolla; importar el puerto `Shared` es legítimo). La **única** edición de allowlist es **retirar** la entrada del `event-dispatch-allowlist` (T3).
- **No** cambiar el comportamiento de lectura de `BankAccountSearcher` (guard de existencia, paginación, presupuesto de 2 queries) — solo cambia *cómo* se registra el acceso.

### Architecture compliance (guardrails que muerden)

- **Bounded-context isolation (`make php.lint.bounded-context` + `make php.deptrac`):** `Backoffice/BankAccount/Application` importa `Erpify\Shared\Audit\Application\AuditLogger` y `Erpify\Shared\Audit\Domain\AuditLevel` — ambos `Erpify\Shared\…`, **siempre importables**; ninguna pieza entra en el `Domain/` de otro contexto de negocio. **Sin** allowlist ni bloque deptrac nuevos. Al retirar `MessageBusInterface` del searcher, 1.5 **reduce** acoplamiento (el adaptador del bus vive en `Infrastructure`, fuera del alcance del gate de Application).
- **Event-dispatch gate (`make php.lint.event-bus`, `EventDispatchGateTest`):** tras 1.5 ninguna Application importa `MessageBusInterface` directamente → `testNoMessageBusInterfaceImportInApplicationLayer` verde **sin** exenciones; `testAllowlistEntriesPointToExistingFiles` verde con cero entradas de path (solo verifica que las entradas restantes —ninguna— apunten a ficheros existentes). Leído el test ([`api/tests/Unit/Shared/Architecture/EventDispatchGateTest.php`](../../api/tests/Unit/Shared/Architecture/EventDispatchGateTest.php)): retirar la entrada **no** rompe ningún assert.
- **Hexagonal:** `BankAccountSearcher` sigue siendo Application orquestando puertos (`BankExistenceChecker`, `BankAccountSearchRepository`, ahora `AuditLogger`) — todos interfaces/puertos, cero framework. El adaptador concreto de `AuditLogger` (1.4) vive en `Shared/Audit/Infrastructure`, fuera de este diff.
- **Error contract:** 1.5 no añade marcadores ni respuestas HTTP; un fallo de auditoría lo absorbe la frontera best-effort de 1.4 (no aflora al pipeline RFC 9457). **No** se edita [`docs/api-error-contract.md`](../../docs/api-error-contract.md) (NFR26 no aplica).
- **Seguridad (`audit_log` es PII; metadata PII-free):** la fila lleva `actor_id`/`ip`/`user_agent` (PII) **solo** cuando 1.4 los estampe; aquí el actor es `anonymous` (`actor_id` null) y `metadata` va vacío (FR12 — sin IBAN). La **retención + pseudonimización GDPR** y la actualización de `PRODUCTION_SECURITY_CHECKLIST.md`/`docs/rules/security.md` son **Epic 3**, no 1.5. La acción/`resourceType` son constantes del código (no input de cliente → sin mass-assignment); `resourceId` es el `bankId` ya guardado por `Uuid::ensure()` aguas arriba.
- **Docs:** se edita `docs/architecture/event-catalog.md` (T4, obligatorio por FR18). La línea de `audit_log` en `docs/architecture-api.md` (*Async & messaging* / nuevo eje) la debe haber añadido **1.4** (cuando cableó el transporte y el flujo); si 1.4 la difirió, **proponerlo como mejora en scope** y confirmarlo (no asumir). 1.5 no documenta infraestructura que no toca.

### Librerías / framework

- PHP **8.5** (floor `^8.5`). `final readonly class`, `private const string`, propiedades promovidas, el VO de recurso `AuditResource::of('Bank', $bankId)` (1.4 D-1.4.a). `declare(strict_types=1);`. Sin sintaxis 8.5 inventada de memoria.
- Imports nuevos en el searcher: `Erpify\Shared\Audit\Application\AuditLogger`, `Erpify\Shared\Audit\Domain\AuditLevel`, `Erpify\Shared\Audit\Domain\AuditResource`. Imports a **eliminar**: `Symfony\Component\Messenger\MessageBusInterface`, `Symfony\Component\Messenger\Exception\ExceptionInterface`, `Psr\Log\LoggerInterface` (si queda sin uso), `Erpify\Backoffice\BankAccount\Application\Audit\BankAccountsViewedAuditEvent`.
- Behat: **Behat aislado** en `api/tools/behat/` (instala con `make php.behat.install` si hace falta; `app.dev` no lo hace). Config en `api/tools/behat/behat.yml.dist` (suite `default`). Contextos ya registrados y reutilizables: `HttpRequestContext` (`I send a … request`, `I add :name header equal to :value`), `SqlQueryContext` (`I execute the SQL query … [on connection :name]`, `there should have N records in SQL result`, `the SQL result as JSON should be:`), `MessengerConsumerContext` (`I consume N message(s) from the :transportName transport`), `FixturesContext` (TRUNCATE de tablas raw). **No** hace falta registrar un contexto nuevo: `SqlQueryContext` cubre `audit_log` (tabla raw, sin entidad ORM) y `MessengerConsumerContext` resuelve `messenger.transport.audit` sin cambios.
- Fixtures: bancos/cuentas pre-cargados por Alice (`api/tests/DataFixtures/Fixtures/Bank.yaml`/`BankAccount.yaml`); el banco `11111111-1111-7000-8000-000000000001` tiene 1 cuenta (mirror del escenario verde de `search.feature`).

### File structure

```
api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php                         (MODIFY — MessageBus→AuditLogger; drop try/catch + logger; const action/resource)
api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php          (DELETE — placeholder retirado; dir Audit/ si queda vacío)
api/src/Backoffice/BankAccount/Infrastructure/Audit/RecordAuditLogOnBankAccountsViewed.php (DELETE — placeholder retirado; dir Audit/ si queda vacío)
api/.event-dispatch-allowlist                                                              (MODIFY — borrar la entrada del searcher + su comentario, líneas 12–16)
docs/architecture/event-catalog.md                                                        (MODIFY — Non-domain signals: log-line→audit_log; quitar nota allowlist; arreglar source link)
api/features/backoffice/bank_account/audit.feature                                         (NEW — escenario end-to-end: GET accounts → consume audit → 1 fila correcta + PII-free)
api/tests/Behat/Context/FixturesContext.php                                                (MODIFY — añadir audit_log al TRUNCATE, línea 131)
```

Sin ficheros src nuevos (1.5 consume, no crea). El único src tocado es `BankAccountSearcher.php`. El resto son borrados, un feature Behat nuevo, un toque de aislamiento en `FixturesContext` y dos ediciones de doc/allowlist.

### Testing requirements

- **Behat es la prueba de la historia (FR18 lo pide explícitamente: "— Behat").** El feature `audit.feature` valida el flujo real `request → AuditLogger->log (activity) → transporte audit → RecordAuditEntryHandler → audit_log`, que es justo lo que 1.1→1.4 montaron por piezas. Mirror del estilo de `api/features/backoffice/bank_account/search.feature` (mismo banco/ruta) + asserts SQL al estilo de cómo `delete.feature` cuenta filas en `event_store`.
- **Consumir el transporte `audit` es obligatorio** antes de asertar la fila (la persistencia `activity` es async — sin consumo la cola tiene el mensaje pero `audit_log` está vacío). Usar `MessengerConsumerContext::I consume :count message(s) from the :transportName transport` (Worker real contra `messenger.transport.audit`), nunca `messenger:consume` (resetearía el `in-memory`). Verificado: el contexto resuelve `messenger.transport.<name>` dinámicamente → `"audit"` funciona sin tocar el contexto, **siempre que 1.4 haya registrado el transporte** (T0).
- **Aislamiento de `audit_log` por feature:** añadir `audit_log` al `TRUNCATE` de `FixturesContext` (línea 131) — sin esto, filas de otros features (o de runs anteriores) ensuciarían el conteo. Mismo patrón que `event_store`/`bank_count`/`handled_domain_event`.
- **Correlación asertable:** fijar `X-Correlation-Id` en la request con un UUIDv7 **canónico minúscula** (el `CorrelationIdListener` reusa el inbound solo si es canónico; si no, acuña uno y la igualdad fallaría — leído su docblock: "inbound `X-Correlation-Id` (when canonical lowercase UUIDv7) → `_correlation_id`"). Generar el literal una vez (p. ej. `0197...`-style v7) y reutilizarlo en el header y en el assert SQL.
- **No-PII (FR12):** el assert SQL exacto del JSON (con `metadata` vacío) ya prueba que no hay IBAN; el banco se identifica por `resource_id`, no por datos de cuenta. No introducir el IBAN en ninguna columna/aserción.
- **Idempotencia (AC5):** la garantía por-PK (`ON CONFLICT (id) DO NOTHING`) está **probada en integración en 1.3** (`AuditLogWriterIdempotencyTest`) y AC5 la **delega** íntegramente ahí. En Behat se asserta solo lo observable: una única ejecución observable de la operación genera una única fila `audit_log` (ya cubierto por el `there should have 1 records` del escenario 1). **No** se escribe un escenario Behat de redelivery — el transporte `in-memory` drena la cola al consumir y no hay step de reentrega, así que no es ejecutable end-to-end; **no** se introduce un mecanismo artificial de redelivery. Citar el test de 1.3 en *Completion Notes*.
- **Presupuesto de queries del request:** sigue en **2** (guard + página). La auditoría no toca el request path (async). No añadir aserciones de presupuesto que asuman una 3.ª query en el request.
- **Gotchas de tooling (de 1.1–1.4 y sesiones previas):**
  - `php.quality` regenera `api/config/reference.php`: **commitea** el diff, no `git checkout`.
  - Tras `php.quality` (Rector/cs-fixer pueden mover cosas), **re-correr `make php.stan`** sobre `BankAccountSearcher.php` asentado.
  - `cs-fixer` quita `use` huérfanos en `php.quality`; aun así, dejar el diff de `use` limpio a mano (no confiar en el fixer para la corrección, sí para el formato).
  - El feature Behat **falla con "relation audit_log does not exist"** si se corre antes de `make db.migrate` (1.3). Respeta el orden de T6.

### Git intelligence (rama `feat/shared-audit-actor-context-5sz9`)

- Estado de la rama (base `df391a26`): commits de planning/docs + los commits de implementación 1.1→1.4 (en la entrega de PR único). **1.5 es el quinto y último commit de implementación del Epic 1**, encadenado sobre 1.4. Verifica con `git log --oneline` que 1.4 ya está antes de empezar (T0).
- Commit sugerido (Conventional Commits, scope `backoffice` — el cambio de comportamiento vive en `Backoffice/BankAccount`; alternativamente `shared` si se quiere alinear con el resto del epic): `feat(backoffice): record BANK_ACCOUNTS_VIEWED through the AuditLogger seam`. El cuerpo menciona el borrado del placeholder y la retirada de la entrada del event-dispatch allowlist (boy-scout nombrado).
- **Barrer del diff** comentarios con IDs de story/AC/FR/NFR/`D-1.5.x` antes del commit final (regla de `CLAUDE.md`); son andamiaje, no van a `main`. Boy-scout: limpiar comentarios change-relative en `BankAccountSearcher.php` y en la sección tocada de `event-catalog.md`.

### Project Structure Notes

- Sin estructura nueva: 1.5 modifica un fichero de `Backoffice/BankAccount/Application`, borra dos del archetype `Audit/` y añade un `.feature`. Los directorios `Audit/` de `Backoffice/BankAccount` desaparecen si quedan vacíos (no dejar carpetas huérfanas).
- El subsistema `Shared/Audit` (1.1–1.4) no se toca aquí; 1.5 solo lo consume desde `Backoffice`.
- Confirmar que `BankAccountSearcher` no tiene **otros** consumidores que asuman su firma de constructor (la inyección la resuelve el contenedor por autowiring; un test unitario/funcional que lo construya a mano debe actualizar los args — grep `new BankAccountSearcher(` por si acaso).

### References

- [`_bmad-output/planning-artifacts/epics.md`](../planning-artifacts/epics.md) — Epic 1 / Story 1.5 (ACs originales) + FR18/FR12/FR14/FR2/FR4, NFR2/NFR7; *Additional Requirements* (retirar la entrada del allowlist + actualizar `event-catalog.md`).
- [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) — D1 (eje separado, sin `AuditEvent` público), D2 (la vía explícita `AuditLogger->log` se estrena con FR18), D3 (`activity` async sobre el transporte `audit`), esquema `audit_log`, *Alcance de captura — Fase 1* (`BANK_ACCOUNTS_VIEWED` es la única acción cableada).
- [`1-1-actorcontext-con-actortype-tipado.md`](./1-1-actorcontext-con-actortype-tipado.md) — `ActorContext`/`ActorType`; `anonymous` es lo que produce esta request (sin auth).
- [`1-2-auditlevel-mensaje-interno-recordauditentry-modelo-auditlogentry-separados-del-domainevent.md`](./1-2-auditlevel-mensaje-interno-recordauditentry-modelo-auditlogentry-separados-del-domainevent.md) — `AuditLevel::ACTIVITY`, `AuditLogEntry`, `RecordAuditEntry`; D-1.2.d (`id` ancla de idempotencia), D-1.2.e (`action` como constante por módulo).
- [`1-3-tabla-audit-log-append-only-escritor-idempotente-raw-dbal-schema-listener.md`](./1-3-tabla-audit-log-append-only-escritor-idempotente-raw-dbal-schema-listener.md) — `audit_log` + `DbalAuditLogWriter` (`ON CONFLICT (id) DO NOTHING`); `AuditLogWriterIdempotencyTest` prueba AC5 por-PK en integración.
- `1-4-...md` (sibling story, **leer en T0**) — el seam `AuditLogger->log(...)`, su firma exacta, el `RecordAuditEntryHandler`, el transporte `audit`, `ActorContextFactory` (resuelve `anonymous` en `/api/*`). La frontera best-effort es suya (1.5 no la duplica).
- [`api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php`](../../api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php) — el fichero a migrar (hoy: `MessageBusInterface` + `LoggerInterface` + `recordAccess()` con try/catch).
- [`api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php`](../../api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php) — placeholder a **borrar**.
- [`api/src/Backoffice/BankAccount/Infrastructure/Audit/RecordAuditLogOnBankAccountsViewed.php`](../../api/src/Backoffice/BankAccount/Infrastructure/Audit/RecordAuditLogOnBankAccountsViewed.php) — handler placeholder a **borrar**.
- [`api/.event-dispatch-allowlist`](../../api/.event-dispatch-allowlist) — retirar la entrada del searcher + su comentario (líneas 12–16).
- [`api/tests/Unit/Shared/Architecture/EventDispatchGateTest.php`](../../api/tests/Unit/Shared/Architecture/EventDispatchGateTest.php) — el gate; confirmado que retirar la entrada no rompe ningún assert.
- [`docs/architecture/event-catalog.md`](../../docs/architecture/event-catalog.md) — *Reading this catalog* (fila "Audit log", ~24) + *Non-domain signals* (~101–113) a actualizar.
- [`api/features/backoffice/bank_account/search.feature`](../../api/features/backoffice/bank_account/search.feature) — mirror del request/banco para `audit.feature` (banco `…0001`, 1 cuenta, 200, 2 queries).
- [`api/tests/Behat/Context/SqlQueryContext.php`](../../api/tests/Behat/Context/SqlQueryContext.php) — asertar filas de `audit_log` (`I execute the SQL query …`, `there should have N records …`, `the SQL result as JSON should be:`).
- [`api/tests/Behat/Context/MessengerConsumerContext.php`](../../api/tests/Behat/Context/MessengerConsumerContext.php) — consumir el transporte `audit` con un Worker real (`I consume N message(s) from the :transportName transport`).
- [`api/tests/Behat/Context/FixturesContext.php`](../../api/tests/Behat/Context/FixturesContext.php) — TRUNCATE de tablas raw (línea 131; añadir `audit_log`).
- [`api/tools/behat/behat.yml.dist`](../../api/tools/behat/behat.yml.dist) — contextos registrados + suite `default`.
- [`api/config/packages/messenger.yaml`](../../api/config/packages/messenger.yaml) — transportes + `when@test` (`in-memory://?serialize=true`); el transporte `audit` lo añade 1.4.
- [`api/src/Shared/Http/Infrastructure/CorrelationIdListener.php`](../../api/src/Shared/Http/Infrastructure/CorrelationIdListener.php) — `X-Correlation-Id` (canónico lowercase UUIDv7 reusado, si no acuña uno).
- [`docs/architecture-api.md`](../../docs/architecture-api.md) — *Async & messaging* (la línea del eje `audit_log`/transporte la debe traer 1.4; confirmar).

## Preguntas para Sergio (resolver antes o durante la implementación)

1. **Firma de `AuditLogger::log(...)` (ya reconciliada con 1.4 — confirmar el VO).** 1.4 fijó `log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = [])`: el recurso viaja como el VO `AuditResource::of('Bank', $bankId)` (1.4 D-1.4.a), no como dos `?string` sueltos. Esta spec ya usa esa firma en AC1/T1. T0 solo debe **re-verificar** que el `AuditLogger.php` en disco coincide (mismo orden, `metadata` opcional con default `[]`) antes de cablear. **Recomendación:** mantener el VO `AuditResource` (atomicidad del par tipo+id; es además lo que 1.4 dejará en disco). 1.4 lo eleva como su propia Pregunta 3 (VO vs dos `?string`); si Sergio revierte a `?string` allí, ajustar la llamada de T1 en consecuencia.
2. **Scope del commit y de `docs/architecture-api.md`.** ¿Confirmas scope `backoffice` para el commit (el cambio de comportamiento vive ahí), o prefieres `shared` para alinear con los commits 1.1–1.4 del epic? Y: ¿la línea del eje `audit_log`/transporte `audit` en `docs/architecture-api.md` la trajo 1.4, o la añado en 1.5? **Recomendación:** scope `backoffice`; y que `architecture-api.md` lo documente 1.4 (cuando cableó el flujo) — si 1.4 lo difirió, lo añado en 1.5 como mejora-en-scope nombrada.

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (1M context) — `claude-opus-4-8[1m]`.

### Debug Log References

- `make php.stan` → 565 files, **No errors** (antes y después de los fixers de `php.quality`).
- `make php.behat` → **136 escenarios / 1405 pasos** verdes (incluye el nuevo `audit.feature` + `search.feature` sin regresión: sigue en 2 queries).
- `make php.unit` → **1179 tests / 5319 asserts** OK (3 skips preexistentes); incluye `EventDispatchGateTest` verde con cero entradas de path en la allowlist (AC7).
- `make php.quality` → **exit 0** (deptrac · bounded-context · event-bus · phpmd · cs-fixer 0 fixes · rector · gherkin).
- Dos incidencias encontradas y resueltas durante T6 (ver Completion Notes): doble-fetch del `SqlQueryContext` y baseline de deptrac obsoleto.

### Completion Notes List

**ACs 1–8 satisfechos y verificados end-to-end.** `BankAccountSearcher` registra `BANK_ACCOUNTS_VIEWED` (activity, `AuditResource::of('Bank', $bankId)`) por `AuditLogger->log(...)` sólo en éxito; placeholder y entrada de allowlist retirados; `audit.feature` prueba la fila forense correcta (anonymous, `actor_id` null, correlation-id de la request, `Bank`/id, `metadata` `[]` sin IBAN).

- **Pregunta 1 (firma/VO) — RESUELTA por evidencia en disco:** `log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = [])` con `AuditResource::of(...)` coincide con la spec; se mantiene el VO. Sin desviación.
- **Pregunta 2 — ABIERTA para Sergio (no asumida):** (a) **scope del commit** — recomiendo `backoffice` (el cambio de comportamiento vive ahí); aún **sin commit** (pendiente de tu OK). (b) **`docs/architecture-api.md`** NO documenta el eje `audit_log`/transporte `audit` (1.4 lo difirió) → lo **propongo como mejora en scope**; no lo añadí unilateralmente.

**Trabajo más allá del File structure de la spec (nombrado por higiene de scope; forzado por el cambio):**

- **Caída de tests (la spec lo anticipó en T2, pero no lo listó):** `BankAccountSearcherTest` reescrito contra un nuevo fake `RecordingAuditLogger`; **borrados** los dos tests de clases-placeholder (`BankAccountsViewedAuditEventTest`, `RecordAuditLogOnBankAccountsViewedTest`) y los dos fakes ahora huérfanos (`RecordingMessageBus`, `ThrowingMessageBus`). **Eliminado** el test de resiliencia del searcher ante fallo de auditoría: esa frontera best-effort vive ahora en `AuditLogger` (1.4, AC2/D-1.5.b), así que probarla en el searcher sería código muerto/mentira.
- **Verdad documental más allá de `event-catalog.md`:** `event-driven-architecture.md` (el párrafo de la exención de allowlist — el propio ADR predijo esta migración → actualizado a hecho) y `audit-activity-log.md` (la alternativa descartada nombraba la clase borrada → reescrita al `RecordAuditEntry` vivo). **`cqrs-naming.md`: arreglo mínimo de verdad + FLAG (decisión tuya).**
- **Baseline de deptrac regenerado** (`make php.deptrac.baseline`): cayeron las entradas obsoletas `BankAccountSearcher → MessageBusInterface/ExceptionInterface` (deuda saldada → mejora neta de aislamiento, AC7). El `skip_violations` de `deptrac.yaml` (seam cross-context a `Bank`) se mantiene: sigue siendo válido.
- **Asserts SQL del Behat consolidados:** el `Result` de `SqlQueryContext` es de un solo fetch, así que `there should have 1 records` + `the SQL result as JSON should be:` sobre una misma query hace doble-fetch (el segundo da `[]`). Uso sólo el match exacto de JSON, que prueba a la vez AC4 (campos, PII-free) y AC5 (exactamente una fila). Escenario reestructurado a un único flujo `Given→When→Then` + docstring a 4 espacios para pasar el gherkin linter.

**Idempotencia por-PK (AC5):** delegada a `AuditLogWriterIdempotencyTest` (1.3, `ON CONFLICT (id) DO NOTHING`); Behat sólo asserta lo observable (una ejecución → una fila). No se introdujo redelivery artificial (el `in-memory` drena la cola al consumir).

**FLAG para Sergio — `docs/rules/cqrs-naming.md` Categoría 4:** su ejemplo (`RecordAuditLogOnBankAccountsViewed`) nombra una clase ya borrada y la categoría queda **sin instancia viva** (el eje audit pasó al seam genérico `Shared/Audit` `RecordAuditEntryHandler`, que es `<Verbo><Noun>Handler`, no `<Effect>On<X>`). Hice sólo el arreglo de verdad mínimo en el bullet; **reencuadrar/retirar la Categoría 4 es decisión tuya** (taxonomía que curas, con la PR #325 de naming de subscribers en vuelo). Es la única referencia al placeholder que queda dentro del alcance del grep de docs/ — deliberada, no un olvido.

### File List

**Modificados**

- `api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php` — `MessageBusInterface`+`LoggerInterface` → `AuditLogger`; `recordAccess()`/try-catch fuera; `const` acción/recurso.
- `api/tests/Unit/Backoffice/BankAccount/Application/BankAccountSearcherTest.php` — reescrito contra `RecordingAuditLogger`.
- `api/tests/Behat/Context/FixturesContext.php` — `audit_log` añadido al `TRUNCATE`.
- `api/.event-dispatch-allowlist` — entrada del searcher + comentario retirados (queda sólo cabecera).
- `api/tools/deptrac/deptrac.baseline.yaml` — regenerado (entradas framework de `BankAccountSearcher` retiradas).
- `docs/architecture/event-catalog.md` — fila resumen + *Non-domain signals* + source link al día.
- `docs/adr/event-driven-architecture.md` — párrafo de exención al día (migración hecha).
- `docs/adr/audit-activity-log.md` — alternativa descartada reescrita a `RecordAuditEntry`.
- `docs/rules/cqrs-naming.md` — bullet Categoría 4 al día (+ FLAG pendiente de tu decisión).

**Nuevos**

- `api/features/backoffice/bank_account/audit.feature` — escenario end-to-end (GET → consume `audit` → 1 fila forense PII-free).
- `api/tests/Unit/Backoffice/BankAccount/Application/RecordingAuditLogger.php` — fake/spy del puerto `AuditLogger`.

**Borrados**

- `api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php` (+ dir `Audit/` vacío).
- `api/src/Backoffice/BankAccount/Infrastructure/Audit/RecordAuditLogOnBankAccountsViewed.php` (+ dir `Audit/` vacío).
- `api/tests/Unit/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEventTest.php` (+ dir vacío).
- `api/tests/Unit/Backoffice/BankAccount/Infrastructure/Audit/RecordAuditLogOnBankAccountsViewedTest.php` (+ dir vacío).
- `api/tests/Unit/Backoffice/BankAccount/Application/RecordingMessageBus.php` — fake huérfano.
- `api/tests/Unit/Backoffice/BankAccount/Application/ThrowingMessageBus.php` — fake huérfano.

### Change Log

- 2026-06-24 — Story 1.5 implementada: `BANK_ACCOUNTS_VIEWED` migrado al seam `AuditLogger` (activity async sobre el transporte `audit`), placeholder + entrada de allowlist retirados, `audit.feature` end-to-end, baseline de deptrac saldado. Gates verdes (stan/quality/behat 136/136/unit 1179). Status → review.
