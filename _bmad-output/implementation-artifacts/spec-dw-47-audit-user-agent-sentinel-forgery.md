---
title: 'DW-47 — el centinela de redacción del audit trail no es falsificable desde la petición'
type: 'bugfix'
created: '2026-09-28'
status: 'done'
baseline_revision: '634579b670ff8a0ab4212c14abe190268405781b'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/docs/adr/audit-activity-log.md'
warnings: []
deferred:
  - summary: >-
      Las filas de audit_log persistidas antes de este cambio con un User-Agent igual a [REDACTED] no se reescriben.
    evidence: |-
      La neutralización es sólo en captura (E1); no hay backfill. Un backfill sería un UPDATE nuevo sobre audit_log,
      que exige línea en SANCTIONED de SanctionedLogMutationGateTest y decisión en el ADR D4. Sólo importa si existe
      un despliegue con datos reales dentro de la ventana de retención; la documentación ya acota la garantía a
      filas capturadas tras el despliegue.
    location: >-
      api/src/Shared/Audit/Domain/AuditRedaction.php
    severity: medium (unverified)
  - summary: >-
      docs/rules/security.md y docs/rules/database.md describen el centinela [REDACTED] y sus dos escritores sin mencionar su reserva ni la reescritura [client-supplied].
    evidence: |-
      security.md:298 y database.md:75-79 no se tocaron en este cambio; el ADR y PRODUCTION_SECURITY_CHECKLIST.md sí.
      No afirman nada falso (no dicen que user_agent sea forjable), sólo omiten el patrón nuevo. Diferido porque el
      arreglo edita ficheros de reglas para agentes (docs/rules).
    location: >-
      docs/rules/security.md:298
    severity: low
---

<intent-contract>

## Intent

**Problem:** `audit_log.user_agent` se guarda tal cual llega en la cabecera `User-Agent`, así que un cliente puede escribir el literal `AuditRedaction::SENTINEL` (`[REDACTED]`) y producir una fila indistinguible de una redactada. Con la redacción por eje de recurso (sentinela con `actor_erased = FALSE`), la derivación documentada en el ADR (`… AND (ip = '[REDACTED]' OR user_agent = '[REDACTED]')`) cuenta la fila falsificada como evidencia de una supresión que nunca ocurrió.

**Approach:** En la construcción de `AuditLogEntry` (la única puerta de toda fila de `audit_log`), un `ip`/`userAgent` capturado que equivalga al centinela se reescribe con un prefijo que declara su origen (`[client-supplied] …`), de modo que el centinela exacto sólo lo pueden escribir los dos `UPDATE` de supresión. La regla vive junto al literal, en `AuditRedaction`.

## Boundaries & Constraints

**Always:** la neutralización se aplica en `AuditLogEntry::create()` a `ip` y `userAgent` por igual; «equivale» = el valor, sin espacios en los extremos, coincide con el centinela sin distinguir mayúsculas; el valor neutralizado no es NULL (NULL significa «nunca capturado», y la cabecera sí llegó) y cabe en `VARCHAR(512)`; cualquier otro valor pasa intacto.

**Never:** tocar las dos sentencias de supresión (`DbalAuditActorAnonymiser`, `DbalAuditResourceAnonymiser`) ni el valor del centinela; lanzar excepción (en la ruta síncrona `security` convertiría una denegación en 5xx); añadir un segundo sitio de captura con su propia regla.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| UA normal | `Mozilla/5.0` | `Mozilla/5.0` | No error |
| UA = centinela | `[REDACTED]` | `[client-supplied] [REDACTED]` | No error |
| Variante | `  [redacted] ` (padding hasta 512) | `[client-supplied] [redacted]` (≤ 512) | No error |
| Contiene el centinela | `foo [REDACTED]` | intacto | No error |
| IP = centinela | `ip: '[REDACTED]'` | `[client-supplied] [REDACTED]` | No error |
| Ausente | `null` | `null` | No error |

</intent-contract>

## Code Map

- `api/src/Shared/Audit/Domain/AuditRedaction.php` -- dueño del literal `SENTINEL`; aquí va `neutraliseCaptured(?string): ?string` y la constante del prefijo.
- `api/src/Shared/Audit/Application/AuditLogEntry.php:57-95` -- `create()`, única factoría de entradas; aplica la neutralización a `$ip` y `$userAgent`. Su docblock ya declara ambos campos como contaminados.
- `api/src/Shared/Audit/Infrastructure/SealedAuditEntryFactory.php:83-96` -- lee la cabecera y la trunca a 512 con `mb_substr`; NO se toca (la regla vive en la entrada para cubrir todo llamador).
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogWriter.php:61-62` -- persiste `$entry->ip`/`$entry->userAgent` sin transformar (solo lectura).
- `api/tests/Unit/Shared/Audit/Application/AuditLogEntryTest.php` -- añadir los casos de la matriz.
- `docs/adr/audit-activity-log.md:341-352` -- política GDPR; una frase sobre la reserva del centinela.

## Tasks & Acceptance

**Execution:**
- `api/src/Shared/Audit/Domain/AuditRedaction.php` -- añadir `CLIENT_SUPPLIED_PREFIX = '[client-supplied] '` y `neutraliseCaptured()` -- el literal y su reserva viven juntos.
- `api/src/Shared/Audit/Application/AuditLogEntry.php` -- pasar `$ip` y `$userAgent` por `AuditRedaction::neutraliseCaptured()` en `create()` -- cubre todo escritor.
- `api/tests/Unit/Shared/Audit/Application/AuditLogEntryTest.php` -- data provider con la matriz -- fija la regla.
- `docs/adr/audit-activity-log.md` -- una frase en la política GDPR -- el ADR afirma la derivación sobre ese literal.

**Acceptance Criteria:**
- Given una petición con `User-Agent: [REDACTED]`, when se construye su `AuditLogEntry`, then `userAgent !== AuditRedaction::SENTINEL` y empieza por `[client-supplied] `.
- Given cualquier valor capturado, when se construye la entrada, then el resultado nunca es igual (exacto) al centinela.

## Spec Change Log

## Review Triage Log

### 2026-09-28 — Review pass
- verdicts: 19 findings — high 0, medium 7, low 8, false 4, maybe-false 0
- findings:
  - `[medium]` `[patch]` (blind) Filas previas al despliegue no cubiertas y la documentación afirma lo contrario — acotadas las afirmaciones en checklist, ADR (×2), docblocks de `AuditLogEntry`/`DbalAuditResourceAnonymiser`/`AuditRedaction`; el backfill queda en `deferred`.
  - `[low]` `[reject]` (blind) Ninguna gate impide un INSERT en audit_log que salte `AuditLogEntry::create()` — hoy el único INSERT es `DbalAuditLogWriter` y el constructor es privado; cerrarlo exige una gate nueva (complejidad) para un caso improbable.
  - `[medium]` `[patch]` (blind) Ningún test cubre la superficie real (cabecera → factoría) — añadido `SealedAuditEntryFactoryTest::testItNeutralisesAUserAgentHeaderSpellingTheRedactionSentinel`.
  - `[low]` `[patch]` (blind) `COLUMN_WIDTH` dice reflejar `ip`, que es VARCHAR(45) (`AuditLogSchemaListener.php:61`) — docblock corregido a `user_agent`.
  - `[low]` `[patch]` (blind) «como lo leería un humano» promete más que `trim`/`strcasecmp` ASCII — docblock acota la garantía y nombra lo no defendido.
  - `[low]` `[patch]` (blind) El docblock dice que `ip` se guarda tal cual — corregido: `getClientIp()` filtra; neutralizar `ip` es defensa en profundidad.
  - `[low]` `[patch]` (blind) El párrafo de la derivación del ADR (~454) no justifica el brazo `user_agent` — añadida la cláusula de dependencia y ventana temporal.
  - `[false]` `[reject]` (blind) La implementación no sigue la spec (tests en `AuditRedactionTest`) y su verificación no corre el test nuevo — la verificación ejecutada filtró `AuditRedactionTest` explícitamente (verde); arreglarlo sería editar la spec.
  - `[low]` `[patch]` (edge) Padding Unicode (NBSP, U+200B) pasa intacto — agrupado con la acotación del docblock; normalizar Unicode añadiría complejidad sin consumidor.
  - `[low]` `[patch]` (edge) Homóglifos/anchura completa — mismo grupo que la anterior.
  - `[medium]` `[patch]` (edge) Afirmación del checklist sin acotar a filas nuevas — grupo «filas previas».
  - `[medium]` `[patch]` (edge) Afirmación del docblock de `AuditLogEntry` sin acotar — grupo «filas previas».
  - `[medium]` `[patch]` (verification-gap, other) Docs sin acotar a filas posteriores al despliegue — grupo «filas previas».
  - `[medium]` `[patch]` (intent) Los tests no tocan la superficie cabecera→fila — grupo «superficie real».
  - `[false]` `[reject]` (intent) La regla vive en `AuditLogEntry` y no en la ruta de captura — no hay resultado malo: la factoría llama a `create()` y ahora la cubre un test; más cobertura, no menos.
  - `[false]` `[reject]` (intent) Igualdad ampliada (trim + mayúsculas) más allá del predicado SQL — sirve al lector humano que nombra el ledger; ningún valor legítimo se pierde.
  - `[false]` `[reject]` (intent) Se añadió `ip` — defensa en profundidad sin efecto sobre valores reales (`getClientIp()` nunca produce el literal).
  - `[medium]` `[patch]` (intent) Docs afirman E2 con implementación E1 — grupo «filas previas».
  - `[low]` `[patch]` (intent) El docblock describe un consumidor que sólo existe en el ADR — ahora dice «la derivación que documenta el ADR».

### 2026-09-28 — Review pass
- verdicts: 15 findings — high 0, medium 3, low 5, false 7, maybe-false 0 (1 carried: low)
- findings:
  - `[medium]` `[patch]` (blind) La justificación dice que la derivación del ADR se engaña con un `User-Agent` falsificado, y no es así — verificado: sobre filas anónimas el pase de recurso sobrescribe todo `user_agent` no vacío (`DbalAuditResourceAnonymiser.php:107-108`) y el de actor lo hace sin condición (`DbalAuditActorAnonymiser.php:75`). Reescrito el porqué en el docblock de `AuditRedaction`: los flags atribuyen el centinela en toda fila; la regla sirve al lector que no los mira.
  - `[medium]` `[patch]` (blind) El ADR se contradice (~457: «solo vale para filas posteriores al despliegue» junto a «retroactivo») — mismo grupo; la cláusula dice ahora que el brazo de `user_agent` no depende de la neutralización y vale también para filas anteriores.
  - `[medium]` `[patch]` (blind) La regla forense de checklist, ADR y docblock del anonimizador omite `actor_type = 'anonymous'` — mismo grupo; añadido el término en los tres sitios y explicado que con él la atribución vale en filas previas (el backfill diferido deja de ser necesario para atribuir; la entrada `deferred` y DW-60 se conservan, son del orquestador).
  - carried `[low]` `[reject]` (blind) Nada impide en la tabla un INSERT que salte `AuditLogEntry::create()` (propone un `CHECK`) — misma afirmación que la fila «ninguna gate impide un INSERT» del pase anterior; el constructor sigue privado y `DbalAuditLogWriter` es el único INSERT.
  - `[false]` `[reject]` (blind) La neutralización es más ancha que la colisión (trim + mayúsculas) — la intención lo exige explícitamente en *Always*; sirve al lector humano, que es el daño que nombra DW-47. La advertencia sobre Unicode es correcta para ese propósito.
  - `[low]` `[patch]` (blind) El prefijo `[client-supplied]` también es forjable y el docblock dice que «nombra su origen» — corregido en `AuditRedaction`, checklist y ADR: sólo garantiza desigualdad con el centinela.
  - `[low]` `[defer]` (blind) `docs/rules/security.md` y `docs/rules/database.md` no mencionan la reserva del literal — el arreglo edita ficheros de reglas; añadido a `deferred`.
  - `[false]` `[reject]` (blind) El docblock de `RedactedValue.tsx` no matiza el literal — preexistente y sin importadores; ningún usuario lo ve.
  - `[false]` `[reject]` (blind) El test del ancho de columna es redundante — nombra la restricción *Always* (cabe en `VARCHAR(512)`) que el caso del provider no declara; no hay resultado malo.
  - `[low]` `[reject]` (blind) Falta un test de `X-Forwarded-For: [REDACTED]` por la factoría tras proxy de confianza — Symfony descarta ese valor antes (`FILTER_VALIDATE_IP`); camino inalcanzable y añadir el test es complejidad nueva.
  - `[false]` `[reject]` (intent) R2: el invariante de tabla sólo vale para escrituras nuevas — con el predicado completo de flags la atribución es exacta también en filas previas (ver grupo de arriba); el literal crudo en filas no tocadas es el residual ya diferido.
  - `[low]` `[reject]` (intent) R3: ningún test pasa por `DbalAuditLogWriter`/Postgres — el escritor persiste `$entry->userAgent` sin transformar; un test funcional nuevo es complejidad para un defecto improbable.
  - `[false]` `[reject]` (intent) R4: ASCII frente a Unicode — el docblock ya declara el límite y la consulta usa `=` exacto; sin resultado malo.
  - `[false]` `[reject]` (intent) Cadena vacía no cubierta por la matriz — se conserva `''` y está fijada en el provider.
  - `[false]` `[reject]` (intent) Cambios de prosa fuera del *Approach* — descriptivo; la prosa corrige una afirmación previa («`user_agent` es forjable») que el cambio dejó falsa.

## Auto Run Result

Status: done (pasada de seguimiento)

**Resumen:** `AuditLogEntry::create()` pasa `ip` y `userAgent` por `AuditRedaction::neutraliseCaptured()`: un valor capturado igual al centinela `[REDACTED]` (ASCII, sin espacios en los extremos, sin distinguir mayúsculas) se guarda como `[client-supplied] <valor>`. Esta pasada corrige la justificación documentada: la atribución de un centinela a una supresión la dan los flags (`actor_erased`, o `resource_erased` con `actor_type = 'anonymous'`) en toda fila, también en las anteriores al despliegue; la neutralización protege al lector humano que no mira los flags.

**Ficheros (esta pasada):**
- `api/src/Shared/Audit/Domain/AuditRedaction.php` — porqué reescrito; el prefijo declarado forjable.
- `api/src/Shared/Audit/Application/AuditLogEntry.php` — docblock remite a los flags para atribuir.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php` — predicado de atribución completo (`actor_type = 'anonymous'`).
- `PRODUCTION_SECURITY_CHECKLIST.md` — mismo predicado; prefijo forjable.
- `docs/adr/audit-activity-log.md` — tres pasajes: predicado completo, contradicción de la derivación eliminada, prefijo forjable.

**Revisión:** 15 hallazgos (1 arrastrado). Parches: medium 1 entrada (3 hallazgos agrupados: justificación/derivación/predicado), low 1 (prefijo forjable). Diferido: low 1 (`docs/rules` sin la reserva del literal). Rechazados: 2 low (test por XFF inalcanzable; test funcional por el escritor) y 7 false, más 1 low arrastrado (gate/`CHECK` de INSERT); motivos en el triage log.

**Recomendación de revisión de seguimiento:** `false` — pasada de seguimiento sin parches `high` (patched: high 0, medium 1, low 1); sólo se tocó prosa y docblocks.

**Verificación:** `make php.unit c="--filter 'AuditLogEntryTest|AuditRedactionTest|SealedAuditEntryFactoryTest|AuditRedactionSentinelParityTest'"` → OK (25 tests, 65 assertions); `make php.quality` → exit 0 (incluye PHPStan y deptrac: 0 violaciones).

**Riesgos residuales:** filas previas con el literal crudo en filas que ningún pase tocó (el lector humano que no mire los flags puede tomarlas por redactadas); look-alikes Unicode no defendidos (declarado); un INSERT futuro que salte `AuditLogEntry` no está gateado; `docs/rules` sin actualizar (diferido).

## Design Notes

Prefijar en lugar de anular: el docblock de `AuditRedaction` razona que colapsar en NULL destruye la evidencia de que algo estaba ahí; aquí también llegó una cabecera, y conservar su texto (recortado) deja al lector humano ver qué se envió. Se recorta antes de prefijar para que un valor con relleno hasta 512 no desborde la columna.

## Verification

**Commands:**
- `make php.unit c='--filter AuditLogEntryTest'` -- expected: verde
- `make php.stan` -- expected: sin errores
- `make php.quality` -- expected: exit 0

