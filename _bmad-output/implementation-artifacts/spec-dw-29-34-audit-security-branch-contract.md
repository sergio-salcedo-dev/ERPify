---
title: 'DW-29 + DW-34 — la denegación `security` se escribe fuera de toda transacción, y eso se hace cumplir'
type: 'bugfix'
created: '2026-09-28'
status: 'done'
baseline_revision: '640dae1f5beee20c6339bddc2433b689f3b88061'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/docs/adr/audit-activity-log.md'
warnings: [oversized]
deferred: []
---

<intent-contract>

## Intent

**Problem:** `SymfonyAuditLogger::writeSecurity` escribe por la `Connection` DBAL compartida sin transacción propia; la durabilidad de una denegación (D3: «una denegación nunca se pierde») descansa en una afirmación del ADR («en `kernel.exception` cualquier transacción de negocio ya hizo rollback») que nada comprueba, y el contrato de D1/D3 (propagar si la persistencia falla) nunca se ha contrastado contra su productor vivo `AccessDeniedAuditListener:64`.

**Approach:** Hacer cumplir la lectura «probar que se escribe fuera de toda transacción» —no la de «commitear aparte»— con un seam único para los productores `security` de frontera de request: antes de escribir comprueba `isTransactionActive()` sobre la MISMA conexión que usa `DbalAuditLogWriter`; si hay una abierta, rehúsa con `LogicException` (la denegación no puede probarse durable, y D3 prefiere un 5xx a una pérdida silenciosa); si no, delega en `AuditLogger` a nivel `SECURITY`, en autocommit, y un fallo de persistencia se propaga intacto. Los tres listeners HTTP que comparten esa asunción pasan por el seam.

## Boundaries & Constraints

**Always:** el seam vive en `Erpify\Shared\Audit\Infrastructure\Http`, es `final readonly`, recibe `AuditLogger` y `Doctrine\DBAL\Connection` (el servicio por defecto, el mismo que autowirea `DbalAuditLogWriter`); la comprobación ocurre ANTES de llamar al logger; la excepción de rehúso no lleva en el mensaje ni metadata ni ids (sólo la `action`); un fallo del logger se propaga sin envolver; el ADR registra el resultado de la revisión en castellano.

**Never:** no tocar `SymfonyAuditLogger` ni `DbalAuditLogWriter` (los productores `security` de caso de uso —`ChangeUserRoles`, `InviteUser`, `UnlockUserAccount`, `FulfilIdentityErasure`…— escriben DENTRO de su transacción a propósito: su fila debe revertirse con el cambio que describe); no abrir una segunda conexión DBAL ni `REQUIRES_NEW`; no hacer rollback de una transacción ajena; no convertir la rama `security` en best-effort; no editar `deferred-work.md`; no tocar `RecoveryThrottleAuditListener` (pasa por un `*BestEffort` de Application, fuera de este contrato).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Denegación sin transacción | conexión sin transacción activa | una llamada `log(action, SECURITY, null, metadata)`; en BD la fila queda commiteada y la ve una segunda conexión aunque después se abra y revierta otra transacción | — |
| Transacción abierta (rollback alrededor) | `beginTransaction()` en la conexión del contenedor | el logger no se llama; tras el rollback no existe fila | `LogicException` que se propaga |
| Fallo de persistencia | el logger lanza | el listener propaga la misma instancia | sin swallow |
| No-denegación / fuera de `/api` / sub-request | como hoy | ninguna escritura, ni consulta a la conexión relevante | — |

</intent-contract>

## Code Map

- `api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php:91,101-109` -- `writeSecurity`: síncrono, propaga; sin transacción propia. SOLO LECTURA.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogWriter.php` -- inyecta `Connection` por defecto; «owns no transaction». SOLO LECTURA.
- `api/src/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php:36-65` -- productor vivo (`kernel.exception`, prio 32): pasa a depender del seam.
- `api/src/Iam/Identity/Infrastructure/Http/InvalidCurrentPasswordAuditListener.php:45-77` -- misma forma (`kernel.exception`): pasa por el seam.
- `api/src/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListener.php:33-70` -- `kernel.response`, misma asunción: pasa por el seam.
- `api/src/Shared/Persistence/Infrastructure/DoctrineTransactionManager.php` -- `wrapInTransaction` revierte antes de relanzar: por eso en la frontera no debería quedar transacción; una abierta es fuga.
- `api/src/Shared/Persistence/Infrastructure/DoctrineConnectionResetListener.php` -- dev/test cierra la conexión en `kernel.request`, así que un test no puede arrastrar una transacción a una petición HTTP.
- `api/tests/Unit/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListenerTest.php`, `api/tests/Unit/Iam/Identity/Infrastructure/Http/InvalidCurrentPasswordAuditListenerTest.php`, `api/tests/Unit/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListenerTest.php` -- construyen el listener con un mock de `AuditLogger`; pasan a envolverlo en el seam con un stub de `Connection` (precedente: `ObservesRowLocksOnASecondConnection::statementTheLockEmits`).
- `api/tests/Unit/Shared/Audit/Infrastructure/Double/FailingAuditLogger.php` -- doble para el fallo de persistencia.
- `api/tests/Functional/Shared/Audit/ObservesRowLocksOnASecondConnection.php` -- precedente de segunda conexión (`DriverManager::getConnection($params)`) y de limpieza de filas commiteadas.
- `docs/adr/audit-activity-log.md:112-124,726-733` -- invariante de D3 y la afirmación no comprobada en «Implementación».

## Tasks & Acceptance

**Execution:**
- `api/src/Shared/Audit/Infrastructure/Http/RequestBoundarySecurityAudit.php` -- crear el seam `record(string $action, array $metadata): void` descrito en Approach, con docblock del porqué (quién puede y quién no puede usarlo) -- una sola definición de la asunción.
- `api/src/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php` -- depender del seam; ajustar docblock -- DW-34.
- `api/src/Iam/Identity/Infrastructure/Http/InvalidCurrentPasswordAuditListener.php` y `api/src/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListener.php` -- idem -- misma asunción, misma raíz.
- Los tres unit tests de listeners -- adaptar la construcción; en `AccessDeniedAuditListenerTest` añadir que un fallo de persistencia se propaga.
- `api/tests/Unit/Shared/Audit/Infrastructure/Http/RequestBoundarySecurityAuditTest.php` -- filas 1-3 de la matriz a nivel unitario.
- `api/tests/Functional/Shared/Audit/RequestBoundarySecurityAuditFunctionalTest.php` -- contra la BD real: rollback alrededor ⇒ rehúso y cero filas; sin transacción ⇒ fila visible desde una segunda conexión tras un rollback posterior; limpieza de la fila commiteada.
- `docs/adr/audit-activity-log.md` -- registrar la revisión: el invariante de D3 sólo aplica a filas que registran un rehúso en la frontera; las de caso de uso comparten transacción a propósito; el mecanismo elegido y el descartado.

**Acceptance Criteria:**
- Given una petición `/api` denegada sin transacción abierta, when `AccessDeniedAuditListener` actúa, then la fila `ACCESS_DENIED` está commiteada (visible desde otra conexión) antes de que el responder fije el 403.
- Given una transacción abierta en la conexión compartida al llegar a la frontera, when cualquiera de los tres listeners actúa, then no se escribe fila y se lanza `LogicException`.
- Given el escritor falla, when el listener actúa, then la excepción se propaga (contrato D1/D3 conservado).

## Verification

**Commands:**
- `make php.unit c='--filter "RequestBoundarySecurityAudit|AccessDeniedAuditListener|InvalidCurrentPasswordAuditListener|AuditTrailReadAuditListener|SymfonyAuditLogger"'` -- expected: verde.
- `make php.behat c='features/shared/audit/security_denial.feature features/backoffice/audit/self_audit.feature'` -- expected: verde.
- `make php.stan` y `make php.quality` -- expected: exit 0.

## Auto Run Result

Status: blocked
Blocking condition: límite de uso alcanzado durante el triaje de la revisión (paso 4); las cuatro capas corrieron, pero sus hallazgos no se han clasificado ni parcheado.

- Implementación completa y sin commit: seam `RequestBoundarySecurityAudit` + tres listeners de frontera + tests unitarios/funcional + ADR D3 + ampliación de forwarders en `AuditEvidenceActions`.
- Verificación: `php.unit` (filtro del spec + gates de evidencia) 45 OK; Behat `security_denial` + `self_audit` OK; `php.lint.audit-evidence` OK; `php.quality` exit 0.
- Hallazgos pendientes de triaje (las cuatro capas): (1) el `LogicException` lanzado en `kernel.exception` escapa de `handleThrowable` sin Problem Details y sin encadenar la excepción original; (2) en prod (worker FrankenPHP) una transacción filtrada persiste entre requests → 5xx en cada frontera hasta reiniciar; el ADR no lo dice; (3) el test "durable" hace rollback de una transacción vacía DESPUÉS de escribir, sin probar el camino del listener; (4) falta el test de propagación de fallo en `InvalidCurrentPasswordAuditListenerTest` y `AuditTrailReadAuditListenerTest`; (5) la regla de forwarder promueve cualquier consumidor sin tokens (p.ej. `AccessLogAuditListener`) y no tiene tests de fixture (profundidad ≥2); (6) el ADR no clasifica los productores `Record*AuditBestEffort` ni los comandos CLI; (7) una línea del ADR demasiado larga; (8) ningún gate obliga a que los listeners de frontera pasen por el seam.

## Review Triage Log

The dev session hit the usage limit during review triage (step 4), with the four layers run and their eight findings not yet triaged. They were verified against the tree and triaged by hand on the #1026 branch; the patches marked **Fixed** below reached `main` in #1027, because #1026 merged before they were pushed. #1027 also routed a fourth boundary recorder, `SelfTargetedActRefusalAuditListener`, through the seam; a three-layer review of #1027 then made `recordOnException()` wrap a failed write and report what it hands over to Sentry, in the pull request that follows it. The **Auto Run Result** above records the automated session as it stopped and is superseded by this log.

| # | Finding | Outcome |
|---|---|---|
| 1 | A refusal or failed write thrown from a `kernel.exception` listener escapes `HttpKernel::handleThrowable()` with no Problem Details and loses the original exception. | **Fixed.** The seam's `recordOnException()` hands the failure to the event, and the refusal chains the original throwable. Falsified. |
| 2 | In the FrankenPHP worker, a leaked transaction outlives its request, so every audited boundary on that worker answers 5xx until it recycles. | **Measured and recorded.** DoctrineBundle resets the entity managers, not the connection. Recorded in ADR D3 as a conscious cost. Whether to roll back at request end is the **owner's decision**, still open. |
| 3 | The "durable" test rolls back an empty transaction after writing. | **Fixed.** Autocommit is proven by visibility from a second session. |
| 4 | No failure-propagation tests for the password and trail-read listeners. | **Fixed.** All three listeners have them. |
| 5 | The forwarder rule admits any token-free `AuditLogger` consumer, and it has no fixture tests. | **Rejected.** Depth 1 is pinned by the registry gate's staleness check, and depth 2 has no instance. Over-inclusion errs toward demanding a classification, which fails loud. |
| 6 | The ADR does not classify the `Record*AuditBestEffort` producers or the CLI commands. | **Fixed** in ADR D3. |
| 7 | An ADR line is too long. | **Fixed.** |
| 8 | No gate forces the boundary listeners through the seam. | **Fixed.** `BoundarySecurityAuditSeamGateTest`: under `Infrastructure/Http/` only the seam names `AuditLevel::SECURITY` in code, and no `kernel.exception` listener calls `record()`. Both rules falsified. |

