---
title: 'Story 3.2: Auto-auditoría del acceso (auditar-al-auditor)'
type: 'feature'
created: '2026-07-05'
status: 'done'
baseline_commit: '1ec6be6336448e3f92356c030f70d76eb4f3cb7b'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/docs/adr/auth-rbac-subsystem.md'
  - '{project-root}/docs/adr/audit-activity-log.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Leer el trail de auditoría (`GET /api/v1/backoffice/audit/timeline` y `GET .../audit/events/{id}`, ya restringidas a `ROLE_AUDIT_READER` en 3.1) no deja rastro propio; ISO 27001 A.5.18/A.8.15 exige que el acceso a los registros de auditoría sea él mismo auditable. Peor: ambas rutas emiten hoy en silencio una fila `activity ROUTE_*` genérica (que nadie consume ni asevera), no la fila `security` que el «quién leyó qué» requiere.

**Approach:** Un listener dedicado en `Backoffice/Audit` sobre la respuesta **exitosa (2xx)** de esas dos rutas emite una fila `security` con acción cardinalidad-1 `AUDIT_TRAIL_READ` (quién = actor sellado; qué = ruta, y el id en la ruta de detalle) por la vía **durable write-before-send**, reutilizando el puerto `AuditLogger` — el espejo de `AccessDeniedAuditListener` en el camino de éxito. Las dos rutas declaran `_audit_canonical: true` para que el emisor genérico ceda → una sola fila, no dos.

## Boundaries & Constraints

**Always:**
- La fila es `AuditLevel::SECURITY` → inserción **síncrona write-before-send**; el fallo del write **propaga por diseño** (igual que `AccessDeniedAuditListener`).
- El listener vive en `Backoffice/Audit/Infrastructure` (dueño de sus dos rutas) e importa el puerto `Erpify\Shared\Audit\Application\AuditLogger` (Shared siempre importable). **No** en `Shared/Audit`: el aislamiento de contexto prohíbe que Shared catalogue rutas concretas (contrato explícito de `AuditPolicy`/`HttpInteraction`).
- `action` = constante `AUDIT_TRAIL_READ`; la ruta (y el id leído en detalle) viajan en `metadata`, como `ACCESS_DENIED` mete la ruta en `metadata`.
- El actor se resuelve solo por `SecurityActorContextFactory` (3.1) al sellar la entrada — **no** tocar resolución de actor, puerto, writer ni read-model.
- **Cero migración**: `audit_log` ya tiene todas las columnas (`level`, `action`, `actor_*`, `metadata jsonb`, `correlation_id`).

**Ask First:**
- Si el write `security` pudiera montar sobre una transacción de negocio que revierta (invariante de orden D3): no aplica a un GET de lectura, pero si aparece una transacción en vuelo, PARA y consulta antes de escribir.

**Never:**
- No cerrar el mapeo ISO en `PRODUCTION_SECURITY_CHECKLIST.md` / `docs/rules/security.md` ni levantar el gate de producción — eso es **Story 3.3**.
- No Voter propio, no marker de error-contract, no tocar `access_control`/`security.yaml`, no `JsonResponse` manual.
- No enlazar la fila a un `resource` que apunte a otra fila `audit_log` (ruido recursivo — la ruta de detalle ya omite `_audit_resource_type`); el id leído va en `metadata`, nunca como `resource`.
- No ramificar por rol en `Domain`/`Application`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| Lectura autorizada del timeline | `GET .../audit/timeline`, sesión con `ROLE_AUDIT_READER` → 200 | 1 fila `level=security`, `action=AUDIT_TRAIL_READ`, `actor_type=user`, `actor_id`=UUID real, `metadata.route=backoffice_audit_timeline_search`; **0** filas `activity ROUTE_*` | write síncrono; fallo del write propaga (posible 5xx) por diseño |
| Lectura autorizada de un detalle | `GET .../audit/events/{id}` existente → 200 | 1 fila `security AUDIT_TRAIL_READ`, `metadata.route=backoffice_audit_event_detail`, `metadata.auditEventId={id}` | idem |
| Acceso denegado | `GET .../audit/timeline` sin el rol → 403 | **0** filas `AUDIT_TRAIL_READ` (la denegación la audita `ACCESS_DENIED`, intacto) | N/A |
| Evento inexistente | `GET .../audit/events/{id}` → 404 | **0** filas (no se leyó nada) | N/A |
| UUID inválido | `GET .../audit/events/not-a-uuid` → 400 `invalid-uuid` | **0** filas | N/A |
| Ruta ajena | `GET .../banks` → 200 | **0** filas `AUDIT_TRAIL_READ` (fuera del allowlist de 2 rutas) | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListener.php` — **NUEVO**. El emisor `security` en `kernel.response`.
- `api/src/Backoffice/Audit/Infrastructure/Controller/AuditTimelineSearchController.php` — +`_audit_canonical`; `ROUTE_NAME` ya existe (`backoffice_audit_timeline_search`).
- `api/src/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailController.php` — +`_audit_canonical`; extraer `ROUTE_NAME` (hoy literal `backoffice_audit_event_detail`).
- `api/src/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php` — **plantilla** a calcar (security + `metadata.route`, cardinalidad-1).
- `api/src/Shared/Audit/Application/AuditLogger.php` — puerto `log(string $action, AuditLevel $level, ?AuditResource $resource=null, array $metadata=[]): void` (referencia, no se toca).
- `api/src/Shared/Audit/Domain/AuditPolicy.php` / `HttpInteraction.php` — por qué `_audit_canonical` hace ceder al genérico (referencia).

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListener.php` -- crear listener `#[AsEventListener(event: KernelEvents::RESPONSE)]`; guardas: `isMainRequest()`, `_route` ∈ {`AuditTimelineSearchController::ROUTE_NAME`, `AuditEventDetailController::ROUTE_NAME`}, `$event->getResponse()->isSuccessful()`; emite `AuditLogger->log(self::ACTION, AuditLevel::SECURITY, metadata: [...])` con `route` (+ `auditEventId` desde `attributes->get('id')` en la ruta de detalle). Inyecta `AuditLogger`. `private const string ACTION = 'AUDIT_TRAIL_READ'` -- reutiliza la costura durable en vez de dispersar llamadas de auditoría por los controllers.
- [x] `api/src/Backoffice/Audit/Infrastructure/Controller/AuditTimelineSearchController.php` -- añadir `defaults: ['_audit_canonical' => true]` al `#[Route]` + docblock (emisor canónico = el nuevo listener); barrer cualquier comentario stale -- evita la doble fila (activity+security).
- [x] `api/src/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailController.php` -- añadir `defaults: ['_audit_canonical' => true]`; introducir `public const string ROUTE_NAME = 'backoffice_audit_event_detail'` y referenciarla en el `#[Route]`; extender el docblock existente -- da al listener una constante que citar en vez de un literal.
- [x] `api/tests/Unit/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListenerTest.php` -- unit con `#[CoversClass(...)]` cubriendo la matriz (2 rutas → emite con metadata correcta incl. `auditEventId` en detalle; ruta ajena → no emite; 4xx/5xx → no emite). Doble: `RecordingAuditLogger` (spy) o `createMock(AuditLogger)`; construir `ApiRequestMatcher`/`RequestStack` reales; coupling ≤13 (PHPMD).
- [x] `api/features/backoffice/audit/self_audit.feature` -- Behat: Alice (con `ROLE_AUDIT_READER`) lee timeline y un detalle sembrado → asevera 1 fila `security AUDIT_TRAIL_READ` por `correlation_id` (SQL directo, sin consumir transporte, es síncrona) con `actor_id` de Alice; asevera 0 filas `activity ROUTE_BACKOFFICE_AUDIT_*` (no doble fila). Correlation-id propio para no colisionar con otras features de audit.
- [x] `docs/architecture-api.md` -- documentar la acción `security AUDIT_TRAIL_READ` (auto-auditoría de lectura) + `_audit_canonical` en las dos rutas de audit; nota: el cierre del checklist ISO se difiere a Story 3.3.

**Acceptance Criteria:**
- Given las suites existentes (unit/functional/behat), when cada lectura autorizada del trail añade su fila `security` (comportamiento **deseado**: la lectura queda en el propio trail), then el suite completo sigue verde -- las aserciones que cuentan u ordenan `audit_log` a través de varias lecturas se hacen robustas a las filas de auto-auditoría (son las más nuevas, `occurred_on = now()`; ver Design Notes).
- Given `make db.diff`, when se ejecuta tras el cambio, then reporta «No changes detected» (esquema intacto).
- Given `make php.quality`, when corre, then EXIT 0 -- deptrac verde sin skips nuevos (listener en `Backoffice/Audit` → puerto `Shared/Audit` es legal), error-contract y bounded-context sin churn.

## Design Notes

- **`kernel.response`, no `kernel.terminate`.** `AccessLogAuditListener` usa `terminate` porque su fila `activity` es async/latencia-cero; una fila `security` debe escribirse **antes de enviar** dentro del ciclo (D3 write-before-send). `isSuccessful()` excluye limpiamente el 403 (lo audita `ACCESS_DENIED`), el 404 y el 400. Sin prioridad especial (a diferencia de `AccessDeniedAuditListener`, que la necesita por `setResponse()` en `kernel.exception`).
- **`_audit_canonical`, no extender `lacksBusinessSemantics`.** Leer el trail *sí* es acción de negocio significativa; `_audit_canonical` es el flag exacto «esta ruta ya tiene auditoría canónica → el genérico cede» (docblock de `AuditPolicy`/`HttpInteraction`). Precedente idéntico: `BankAccountSearchController`/`BankAccountSearchCollectionController`.
- **Interacción de tests (la trampa).** Las filas `security` nuevas tienen `occurred_on = now()` → son las más recientes; en el timeline (`occurred_on DESC`) la navegación `after` las excluye y `prev` toma la ventana más cercana, así que el functional de cursor *debería* sobrevivir — **verificarlo, no asumirlo**; ajustar quirúrgicamente (filtrar por acción/correlation, o asertar ids sembrados) cualquier aserción de conteo/orden que rompa.
- **Golden (calcar `AccessDeniedAuditListener::onException`):**
  ```php
  $route = $this->routeOf($request);
  $metadata = 'backoffice_audit_event_detail' === $route
      ? ['route' => $route, 'auditEventId' => (string) $request->attributes->get('id')]
      : ['route' => $route];
  $this->auditLogger->log(self::ACTION, AuditLevel::SECURITY, metadata: $metadata);
  ```

## Verification

**Commands:**
- `make php.stan PHP_SERVICE=messenger_worker` -- expected: No errors (override por el segfault del web worker).
- `make php.unit c='--filter AuditTrailReadAuditListenerTest'`, luego `make php.unit` -- expected: verde, incl. los functional de audit.
- `make php.behat` -- expected: verde, con los escenarios de `self_audit.feature`; sin colisión de `correlation_id` con otras features de audit.
- `make db.diff` -- expected: «No changes detected».
- `make php.quality` -- expected: EXIT 0 (deptrac/bounded-context/error-contract/cs-fixer/rector/phpmd/phpcs/gherkinlint).
- Live `curl -k` (HTTPS del worktree): timeline con rol → 200 + fila `security AUDIT_TRAIL_READ`; sin rol → 403 sin fila.

## Review Findings

Review adversarial (Blind Hunter · Edge Case Hunter · Acceptance Auditor), 2026-07-05. Veredicto del Acceptance Auditor: **faithful & complete** — las 6 filas de la matriz realizadas, fronteras honradas, sin scope-creep, suites verdes. Sin intent_gap / bad_spec → sin loopback. Patches aplicados y verificados (`php.stan` · `php.unit` · `php.behat` 208 escenarios · `php.quality` EXIT 0):

- [x] [Patch] El escenario Behat de detalle asevera ahora `the "audit" transport should hold 0 messages` — prueba el yield `_audit_canonical` también en la ruta de detalle (antes solo en timeline).
- [x] [Patch] Nuevo escenario Behat: una lectura de detalle 404 (id inexistente) registra **0** filas `AUDIT_TRAIL_READ` — cierra la fila 404 de la matriz end-to-end.
- [x] [Patch] `EVENT_ID` del unit test cambiado a un UUID distinto del de Alice en fixtures (antes el mismo literal → colisión confusa).

Descartados / aceptados por diseño (razón): el flag `_audit_canonical` «sin handler» (Blind — sin acceso al repo; `AuditPolicy`/`AccessLogAuditListener` lo honran, verificado por la aserción transport-0 + el live check); un write `security` que lanza → 5xx (fail-closed deliberado, calca `AccessDeniedAuditListener`, frontera «Always»); 304/HEAD (las lecturas de audit son `no-store` → 304 inalcanzable; auditar un acceso HEAD es aceptable); el guard nulo de `readEventIdOf` (solo de tipo, inalcanzable en la ruta `{id}` — precedente del repo de no forzar tests de guards muertos); prioridad por defecto del listener (segura en `kernel.response`, a diferencia del hermano en fase de excepción); correlation-id fijado por el cliente (propiedad preexistente del pipeline de auditoría; el actor se sella desde la sesión, no lo controla el cliente). Tradeoff aceptado: las filas de auto-auditoría se acumulan en la retención `security` — inherente a A.8.15 «auditar al auditor», acotado por el prune existente `AuditRetentionPolicy`.

## Suggested Review Order

**El emisor de auto-auditoría (intención de diseño)**

- Entry point: guardas de decisión — main request + 2xx + ruta ∈ allowlist de las 2 rutas de audit
  [`AuditTrailReadAuditListener.php:49`](../../api/src/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListener.php#L49)

- El emit: `security` `AUDIT_TRAIL_READ` por la vía durable write-before-send (fallo propaga por diseño)
  [`AuditTrailReadAuditListener.php:61`](../../api/src/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListener.php#L61)

- El «qué»: ruta en metadata, y el id leído en la ruta de detalle — nunca como `resource` (evita recursión)
  [`AuditTrailReadAuditListener.php:71`](../../api/src/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListener.php#L71)

**Supresión de la fila `activity` genérica (metadata de ruta)**

- La ruta declara `_audit_canonical` → el hook genérico cede (una sola fila, no dos)
  [`AuditTimelineSearchController.php:23`](../../api/src/Backoffice/Audit/Infrastructure/Controller/AuditTimelineSearchController.php#L23)

- Ídem detalle + `ROUTE_NAME` extraída para que el listener la cite en vez de un literal
  [`AuditEventDetailController.php:22`](../../api/src/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailController.php#L22)

**Doc**

- La sección de auditoría documenta la acción `AUDIT_TRAIL_READ` + `_audit_canonical` (cierre ISO diferido a 3.3)
  [`architecture-api.md:262`](../../docs/architecture-api.md#L262)

**Tests (soporte)**

- Behat end-to-end: fila síncrona + transport-0 (no doble fila); +escenario 404-sin-fila
  [`self_audit.feature:13`](../../api/features/backoffice/audit/self_audit.feature#L13)

- Unit del listener: emite / no-emite por ruta, estado y tipo de request
  [`AuditTrailReadAuditListenerTest.php:23`](../../api/tests/Unit/Backoffice/Audit/Infrastructure/Http/EventListener/AuditTrailReadAuditListenerTest.php#L23)
