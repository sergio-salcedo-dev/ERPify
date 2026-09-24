---
title: 'Forma de la metadata de auditoría de nivel change (DW-6, DW-31)'
type: 'bugfix'
created: '2026-09-24'
status: 'done'
baseline_revision: 'eda292ca68d7433e34cd40c7f8c23fe3394a044e'
review_loop_iteration: 0
followup_review_recommended: false
context: []
warnings: [oversized]
deferred:
  - summary: >-
      Un metadata.changes almacenado como null o escalar se sirve tal cual y el guard PWA rechaza todo el sobre del detalle.
    evidence: |-
      Preexistente: AuditEventDetailResourceMapper sólo sella arrays; isAuditEventMetadata exige isAuditChanges cuando la clave existe. El listener nunca escribe null/escalar, así que sólo lo produciría otra vía de escritura o una fila corrupta.
    location: >-
      api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php
    severity: low
---

<intent-contract>

## Intent

**Problem:** (DW-31) `AuditChangeDiff::of()` puede devolver `['changes' => []]` (changeset vacío o íntegramente descartado por sus tres `continue`) y la ruta de lectura lo sirve como `"changes":[]`: `json_decode(…, true)` colapsa `{}` y `[]` en el mismo `[]` PHP, y el `ArrayObject` del mapper sólo protege la raíz. El guard PWA `isAuditChanges([])` lo rechaza, `isAuditEventMetadata` falla y el detalle entero cae en `MALFORMED_RESPONSE_ENVELOPE`. (DW-6) `entry.action` y `metadata.operation` codifican el mismo hecho y nada impide que un `auditAction()` futuro diverja del `AuditWriteOperation` que recibe.

**Approach:** Sellar `metadata.changes` como objeto JSON en el único punto que conoce la forma del cable (`AuditEventDetailResourceMapper`), cubriendo filas futuras e históricas; y un gate de artefacto que descubre desde `api/src` cada implementador de `AuditedEntity` y exige que `auditAction($op)` termine en `_<op->name>` con una raíz común no vacía a las tres operaciones, con su motor de reglas probado en rojo por fixtures.

## Boundaries & Constraints

**Always:** la fila de auditoría se conserva (una escritura que ocurrió es evidencia; no se salta); `changes` no vacío llega al cable byte a byte igual que hoy; el universo del gate se deriva del árbol, nunca de una lista a mano; el gate falla (no se salta) si el universo sale vacío; nuevo gate clasificado en `api/.artifact-gate-placement`.

**Never:** no tocar el guard PWA (el contrato es del API); no `JSON_FORCE_OBJECT` ni cast global en `DbalAuditLogWriter` (convertiría listas legítimas anidadas en `{"0":…}`); no cambiar los `auditAction()` existentes; no editar el ledger `deferred-work.md`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Diff vacío anidado | fila `change` con metadata `{"changes":[],"operation":"UPDATED"}` | `GET /audit/events/{id}` responde bytes `"changes":{}`, nunca `"changes":[]` | — |
| Diff con campos | `{"changes":{"name":{…}}}` | Idéntico a hoy | — |
| Sin `changes` | metadata `{}` (activity) | `"metadata":{}` y ninguna clave `changes` inventada | — |
| Acción divergente | `auditAction(UPDATED)` devuelve `BANK_MODIFIED` | El motor de reglas reporta la violación nombrando clase y operación | Gate en rojo |
| Raíces distintas | `CREATED→BANK_CREATED`, `UPDATED→ACCOUNT_UPDATED` | Violación reportada | Gate en rojo |

</intent-contract>

## Code Map

- `api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php` -- `toResource()` envuelve `metadata` en `ArrayObject`; aquí se sella también `changes` cuando es array.
- `api/src/Backoffice/Audit/Application/Resource/AuditEventDetailResource.php:29-49` -- docblock de `metadata` (`ArrayObject<string, mixed>`); explica la raíz, ampliar a `changes`.
- `api/src/Backoffice/Audit/Infrastructure/Persistence/Dbal/DbalAuditTimelineRepository.php:193-210` -- `decodedMetadata()` hace `json_decode(…, true)`: por eso sellar en escritura NO basta (lectura sólo, no se toca).
- `api/src/Shared/Audit/Application/AuditChangeDiff.php` -- productor de `['changes' => …]`; sólo lectura.
- `api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php:95-104` -- une `auditAction($operation)` y `$diff['operation'] = $operation->name`; sólo lectura.
- `api/src/Shared/Audit/Domain/AuditedEntity.php` -- contrato; su docblock debe nombrar el gate que fija el sufijo.
- `api/src/Backoffice/Bank/Domain/Entity/Bank.php:118`, `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php:255` -- únicos implementadores reales hoy.
- `pwa/src/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.ts:50-76` -- `isAuditChanges`/`isAuditEventMetadata`: el consumidor que rechaza `[]` (sólo lectura).
- `api/tests/Support/ApiSourceFiles.php` -- recorrido de `api/src` a reutilizar.
- `api/tests/Unit/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapperTest.php` -- test unitario a ampliar.
- `api/tests/Functional/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailFunctionalTest.php:127,183` -- `seedChangeRow()` y el precedente de aserción sobre BYTES (`"metadata":{}`).
- `api/.artifact-gate-placement` -- registro donde clasificar los gates nuevos como `home`.

## Tasks & Acceptance

**Execution:**
- `api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php` -- si `metadata['changes']` es array, sustituirlo por `new ArrayObject(...)` antes de envolver la raíz; actualizar docblock -- cierra DW-31 en el cable para filas nuevas e históricas.
- `api/src/Backoffice/Audit/Application/Resource/AuditEventDetailResource.php` -- docblock: `changes` también es mapa en el cable.
- `api/tests/Unit/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapperTest.php` -- casos: `changes` vacío sale como `ArrayObject` vacío; `changes` poblado conserva su contenido.
- `api/tests/Functional/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailFunctionalTest.php` -- sembrar `['changes' => [], 'operation' => 'UPDATED']` y afirmar bytes `"changes":{}` / no `"changes":[]`.
- `api/tests/Support/AuditActionOperationAgreement.php` -- motor puro: dado un `AuditedEntity`, lista de violaciones (sufijo `_<op>` por operación y raíz común no vacía).
- `api/tests/Unit/Gate/AuditActionOperationAgreementRulesGateTest.php` -- fixtures (clases anónimas) que deben ponerse en rojo y una conforme en verde.
- `api/tests/Unit/Gate/AuditActionOperationAgreementGateTest.php` -- descubre implementadores concretos desde `api/src` (prefiltro de texto + reflexión), exige universo no vacío y cero violaciones.
- `api/.artifact-gate-placement` -- dos líneas `home`.
- `api/src/Shared/Audit/Domain/AuditedEntity.php` -- docblock: nombrar el gate que mantiene de acuerdo `action` y `operation`.

**Acceptance Criteria:**
- Given una fila `change` almacenada con `changes` vacío, when se pide `GET /api/v1/backoffice/audit/events/{id}`, then la respuesta contiene `"changes":{}` y el guard PWA la acepta.
- Given un implementador de `AuditedEntity` en `api/src` cuyo `auditAction()` no termina en el nombre de la operación, when corre `make php.unit c='--filter AuditActionOperationAgreement'`, then el gate falla nombrando clase y operación.

## Design Notes

Se descarta "saltar la fila": borra evidencia de una escritura real (p.ej. sólo una colección to-many cambió) en un trail regulatorio. Se descarta sellar sólo en escritura: `decodedMetadata()` usa `json_decode(…, true)`, así que un `{}` almacenado vuelve a salir `[]`; el mapper es el único sitio que controla el cable y además cubre filas históricas.

## Verification

**Commands:**
- `make php.unit c='--filter "AuditActionOperationAgreement|AuditEventDetailResourceMapperTest|AuditEventDetailFunctionalTest"'` -- expected: verde, exit 0.
- `make php.lint.gate-placement` -- expected: exit 0.
- `make php.stan` y `make php.quality` -- expected: exit 0.

## Spec Change Log

## Review Triage Log

### 2026-09-24 — Review pass
- verdicts: 21 findings — high 0, medium 3, low 16, false 2, maybe-false 0
- findings:
  - `[low]` `[reject]` (blind) El gate no tiene target `make php.lint.*` — corre en `php.unit`/CI igual que `AuditWriteOperationParityTest`, que tampoco lo tiene; target+docs es complejidad para un renombrado improbable.
  - `[low]` `[patch]` (blind) Suelo del universo débil (sólo no-vacío con 2 miembros) — añadido `KNOWN_AGGREGATE_FLOOR = 2` con mensaje.
  - `[low]` `[reject]` (blind) La raíz no se valida (`bank_CREATED`, `BANK__CREATED`) — no rompe la concordancia action/operation de DW-6; improbable y añade una regla nueva.
  - `[low]` `[patch]` (blind) El docblock del test funcional decía que se almacena `{}` cuando se almacena `[]` — corregido.
  - `[low]` `[reject]` (blind) La columna sigue guardando `"changes":[]` — decisión de la spec (Design Notes); ningún lector SQL/jsonb recorre `changes` (grep) y el timeline excluye metadata.
  - `[low]` `[reject]` (blind) Falta test de `changes` escalar/stdClass en el mapper — `json_decode(…, true)` no produce stdClass; el escalar es preexistente e inalcanzable desde el listener.
  - `[low]` `[reject]` (blind) Fixture con nombre en vez de ampliar el skip de Rector — cosmético; precedente `ExceptionResponderTest`.
  - `[low]` `[patch]` (blind) `RulesGateTest` etiquetado `@internal test support` — cambiado a `@internal`.
  - `[false]` `[reject]` (blind) DW-6/DW-31 siguen en `deferred-work.md` — la invocación prohíbe editar el ledger; lo cierra el orquestador.
  - `[medium]` `[patch]` (blind) Sin test PWA del cable `changes:{}` — agrupado con verification-gap; casos añadidos.
  - `[low]` `[defer]` (edge) `changes` null/escalar pasa tal cual y el guard rechaza el sobre — preexistente, no causado por este cambio; el listener nunca lo escribe.
  - `[false]` `[reject]` (edge) Un enum que implemente `AuditedEntity` rompería `newInstanceWithoutConstructor` — el gate falla ruidosamente, comportamiento correcto.
  - `[low]` `[patch]` (edge) Subclase de un agregado concreto en un fichero que no nombra `AuditedEntity` — añadida a los puntos ciegos declarados.
  - `[low]` `[reject]` (edge) `BANK__CREATED` pasa — mismo hallazgo y motivo que la raíz no validada.
  - `[medium]` `[patch]` (verification-gap) El guard PWA nunca verifica que admite `changes:{}` — accept `{changes:{},operation:"UPDATED"}` y reject `{changes:[]}` en `ApiAuditEventDetailRepository.test.ts`; vitest 12/12.
  - `[low]` `[patch]` (verification-gap) `withChangesAsMap` envolvía una lista y servía `{"0":…}` — ahora sólo sella vacío o no-lista; test `testToResourceLeavesAListShapedChangesUnwrapped`.
  - `[low]` `[reject]` (intent) DW-31 actúa en lectura, no en escritura — la intención admite «sellar como objeto» con el fin de que el guard nunca vea `[]`; sellar en escritura no llega al cable por `json_decode(…, true)`.
  - `[low]` `[reject]` (intent) Ningún test lleva al listener a producir un diff vacío — el ledger ya lo declara no medido; el sellado en el cable lo hace irrelevante para el guard.
  - `[medium]` `[patch]` (intent) El guard PWA no se ejercitaba — mismo grupo que el test PWA; parcheado.
  - `[low]` `[reject]` (intent) El gate no comprueba que el listener estampe el NOMBRE del caso — el listener escribe `$operation->name` literal y `AuditWriteOperationParityTest` ya fija ese nombre como cable.
  - `[low]` `[reject]` (intent) La raíz común va más allá de la intención — endurecimiento coherente con «action y operation no divergen»; ningún implementador real lo incumple.

## Auto Run Result

Status: done

**Resumen.** DW-31: `AuditEventDetailResourceMapper` sella `metadata.changes` como `ArrayObject` cuando está vacío o es un mapa, así un diff vacío sale `"changes":{}` (filas nuevas e históricas) y una lista malformada no se disfraza de mapa; la fila se conserva como evidencia. DW-6: motor `AuditActionOperationAgreement` + gate sobre el árbol (descubre implementadores de `AuditedEntity` en `api/src`, suelo 2) + gate de reglas con fixtures en rojo.

**Ficheros.**
- `api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php` — sellado de `changes` + docblock.
- `api/src/Backoffice/Audit/Application/Resource/AuditEventDetailResource.php` — docblock: `changes` también es mapa en el cable.
- `api/src/Shared/Audit/Domain/AuditedEntity.php` — docblock nombra el gate.
- `api/tests/Support/AuditActionOperationAgreement.php` — motor de la regla.
- `api/tests/Unit/Gate/AuditActionOperationAgreementGateTest.php` — gate sobre el árbol.
- `api/tests/Unit/Gate/AuditActionOperationAgreementRulesGateTest.php` — falsificación de la regla.
- `api/.artifact-gate-placement` — dos líneas `home`.
- `api/tools/rector/rector.php` — skip de `ReadOnlyAnonymousClassRector` (PDepend no parsea `new readonly class`).
- `api/tests/Unit/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapperTest.php` — casos vacío/lista/sin clave.
- `api/tests/Functional/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailFunctionalTest.php` — bytes `"changes":{}`.
- `pwa/tests/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.test.ts` — guard acepta `{}` y rechaza `[]`.

**Revisión.** 21 hallazgos (4 capas). Parches: 7 filas en 6 entradas (1 entrada medium agrupada de 3 filas, 5 low). Diferido: 1 (null/escalar en `changes`, preexistente). Rechazados: 13 con motivo en el triage log. Tras los parches se corrigió además un docblock `@return` desplazado que dejaba un docblock apilado (PHPStan `missingType.generics` + `php.lint.stacked-docblock`).

**Recomendación de follow-up:** false — patched: high 0, medium 1, low 5.

**Verificación (ejecuciones frescas).** `make php.unit c='--filter "AuditActionOperationAgreement|AuditEventDetailResourceMapperTest|AuditEventDetailFunctionalTest"'` exit 0 (19 tests); `make php.lint.gate-placement` exit 0; `make php.quality` exit 0; `make pwa.quality` exit 0; vitest `ApiAuditEventDetailRepository.test.ts` exit 0 (12). Falsificación medida por el implementador: sin el sellado, el funcional y dos unitarios fallan; con `BANK_ACCOUNT_MODIFIED`/`BANKS_DELETED` el gate falla nombrando clase y operación.

**Riesgos residuales.** `audit_log.metadata` sigue almacenando `"changes":[]` para un diff vacío (sólo el cable está sellado; ningún lector actual lo consulta). El gate no ve una subclase en un fichero que no nombre `AuditedEntity` ni lee filas ya almacenadas. Capas de revisión de este run: Blind Hunter, Edge Case Hunter, Verification Gap e Intent Alignment (sesión bmad-build-auto, 2026-09-24); no corrió un Acceptance Auditor con ese nombre.
