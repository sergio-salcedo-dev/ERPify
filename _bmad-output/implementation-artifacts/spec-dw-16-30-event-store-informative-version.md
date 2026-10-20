---
title: 'DW-16 + DW-30(b) — aggregate_version informativo y metadata del event_store como objeto'
type: 'refactor'
created: '2026-09-29'
status: 'done'
baseline_revision: '0cff62bd01eba243c19d8b4ee757d44bf8a341aa'
review_loop_iteration: 0
followup_review_recommended: false
context: []
warnings: []
deferred:
  - summary: >-
      event_store.payload se escribe como array JSON `[]` para eventos cuyo toPrimitives() está vacío (p.ej. BankDeletedDomainEvent).
    evidence: |-
      DbalEventStore::encode() hace json_encode de un array PHP vacío; la columna se lee como objeto. Preexistente y fuera de DW-30(b), que sólo pedía metadata.
    location: >-
      api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php
    severity: low
  - summary: >-
      RepositoryUniqueViolationTest no tiene caso para el puerto `image` de ConcurrentUniqueWrite.
    evidence: |-
      api-error-contract.md dice que el `resource` es lo único que distingue los cuatro puertos y que se afirma por puerto; el proveedor del test sólo cubre bank, bank-account e identity-user. Preexistente.
    location: >-
      api/tests/Unit/Shared/Persistence/RepositoryUniqueViolationTest.php
    severity: low
---

<intent-contract>

## Intent

**Problem:** `DbalEventStore` promete control de concurrencia optimista sobre `aggregate_version` que no existe (el UNIQUE de stream incluye `tenant_id`, siempre `NULL`, y Postgres usa `NULLS DISTINCT`): el `catch (UniqueConstraintViolationException)` es inalcanzable y `EventStreamConcurrencyConflict` no se lanza nunca. Además `event_store.metadata` se escribe `[]` mientras el ADR describe `NOT NULL DEFAULT '{}'`, un default que la migración no declara.

**Approach:** Aplicar las decisiones del dueño (2026-09-28): `aggregate_version` es **informativo** — retirar la promesa del docblock y del comentario inline, borrar el catch y la excepción muerta con sus tests, y documentarlo en el ADR; `metadata` se escribe siempre como objeto JSON (`{}` si vacío) y el ADR se alinea con la migración real; las filas `[]` históricas de `audit_log` (DW-30a) se aceptan sin backfill y se deja escrito.

## Boundaries & Constraints

**Always:** el SQL del `INSERT` no cambia (mismo texto, mismas columnas, mismo `ON CONFLICT (event_id) DO NOTHING`); `payload` se sigue codificando igual; la coerción de `metadata` es sólo del nivel superior (`(object)`), como `DbalAuditLogWriter`; toda doc tocada deja de afirmar el 409 `event-stream-conflict`.

**Never:** no migrar ni recrear `event_store_stream_version_uniq` (ni `NULLS NOT DISTINCT` ni `DROP`); no añadir locks/reintentos a publicadores; no hacer backfill de `audit_log` ni de `event_store`; no editar `deferred-work.md`; no tocar migraciones.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| metadata vacío | envelope `metadata = []` | parámetro `metadata` = `{}`; en BD `jsonb_typeof(metadata) = 'object'` | — |
| metadata con claves | `['correlation_id' => 'x']` | `{"correlation_id":"x"}` | — |
| UNIQUE violada (hipotética, tenant no nulo) | `executeStatement` lanza `UniqueConstraintViolationException` | la excepción propaga sin traducir (sin 409 inventado) | no se captura |

</intent-contract>

## Code Map

- `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php` -- docblock L20-30 (promesa 409), comentario L47-49 (premisa del lock), try/catch L54-84, `encode()` L171; import `UniqueConstraintViolationException` y `EventStreamConcurrencyConflict` quedan muertos.
- `api/src/Shared/Event/Domain/Exception/EventStreamConcurrencyConflict.php` -- borrar (único uso: el catch).
- `api/tests/Unit/Shared/Event/Domain/Exception/EventStreamConcurrencyConflictTest.php` -- borrar.
- `api/tests/Unit/Shared/Event/Infrastructure/Persistence/DbalEventStoreConcurrencyTest.php` -- borrar; sustituir por un test unitario de forma de `metadata`.
- `api/tests/Functional/Shared/Persistence/DbalEventStoreStreamTest.php` -- añadir aserción `jsonb_typeof(metadata) = 'object'` sobre la fila real.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogWriter.php:60` -- patrón a reutilizar: `\json_encode((object) $x, JSON_THROW_ON_ERROR)`.
- `api/migrations/2026/Version20260616201857.php:37` -- evidencia: `metadata JSONB NOT NULL` sin default (read-only).
- `docs/adr/event-store-and-projections.md` -- L105 (`DEFAULT '{}'` falso), L120-122 (promesa de concurrencia optimista), L327 («hoy se escribe `[]`»), L362-364 (deuda «de la historia que active el versionado real»).
- `docs/adr/audit-activity-log.md` -- esquema L707 (`metadata`): registrar DW-30(a) aceptado sin backfill.
- `docs/api-error-contract.md:70-78` -- «four exceptions … The remaining two … Five ports, three spellings»: quitar `EventStreamConcurrencyConflict` y recontar.
- `docs/architecture-api.md:281` -- párrafo del `event_store` que describe el 409 inerte y el tracking en deferred-work.

## Tasks & Acceptance

**Execution:**
- `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php` -- quitar try/catch e imports muertos; `metadata` → `\json_encode((object) $envelope['metadata'], …)`; reescribir docblock y comentario: versión informativa (`MAX+1` sin serialización garantizada, UNIQUE inerte con `tenant_id NULL`) -- decisión DW-16/DW-30b.
- Borrar `EventStreamConcurrencyConflict.php`, `EventStreamConcurrencyConflictTest.php`, `DbalEventStoreConcurrencyTest.php` -- excepción muerta.
- `api/tests/Unit/Shared/Event/Infrastructure/Persistence/DbalEventStoreMetadataShapeTest.php` -- nuevo: captura parámetros de `executeStatement`; `[]` → `{}`, con claves → objeto; y una `UniqueConstraintViolationException` propaga sin traducir -- cubre la matriz.
- `api/tests/Functional/Shared/Persistence/DbalEventStoreStreamTest.php` -- aserción `jsonb_typeof` sobre la fila insertada -- superficie real (la BD).
- `docs/adr/event-store-and-projections.md` -- esquema sin `DEFAULT '{}'` en `metadata` y con nota de que el escritor garantiza objeto; bullet de `aggregate_version` como informativo con enmienda fechada (la UNIQUE no impone nada con `tenant_id NULL`; si `tenant_id` llega a no nulo el índice se vuelve activo y habrá que re-decidir); L327 y L362-364 alineadas.
- `docs/adr/audit-activity-log.md` -- nota en el esquema: filas `[]` históricas aceptadas sin backfill (cuarta mutación sancionada descartada), consultas como objeto acotan por `jsonb_typeof`/`::text`.
- `docs/api-error-contract.md`, `docs/architecture-api.md` -- retirar `event-stream-conflict` y recontar.

**Acceptance Criteria:**
- Given el árbol tras el cambio, when `git grep -n 'EventStreamConcurrencyConflict\|event-stream-conflict' -- api docs`, then no devuelve nada.
- Given un `append()` de un evento con metadata vacío contra la BD de test, when se lee la fila, then `jsonb_typeof(metadata) = 'object'`.
- Given el ADR del event store, when se lee su esquema y la sección de `aggregate_version`, then no declara default de `metadata` ni promete concurrencia optimista, y documenta la versión como informativa.

## Spec Change Log

## Review Triage Log

### 2026-09-29 — Review pass
- verdicts: 22 findings — high 0, medium 4, low 13, false 5, maybe-false 0
- findings:
  - `[false]` `[reject]` (BH) Entradas DW-16/DW-30 siguen `open` en el ledger — el cierre es del orquestador; la invocación prohíbe editar el ledger.
  - `[low]` `[patch]` (BH) La medición del censo se pierde («la mayoría») — añadido a la enmienda: 24 publicadores, 7 con lock, 2 mixtos, 15 sin él (2026-09-20).
  - `[medium]` `[patch]` (BH) Nada fija la premisa «el índice nunca salta» — nuevo `EventStoreStreamVersionInformativeTest` (funcional): dos filas `(NULL, x, 1)` entran; si enrojece, retomar la enmienda.
  - `[low]` `[patch]` (BH) Falta la alternativa descartada «serializar dentro de `append()`» — añadida (advisory lock por `aggregate_id`; nadie consume la versión hoy).
  - `[medium]` `[patch]` (BH) Las filas `[]` previas de `event_store` no se reconocen — ADR (DDL + bullet) y `event-catalog.md`: aceptadas sin backfill (D12), acotar por `jsonb_typeof`.
  - `[low]` `[defer]` (BH) `payload` también se escribe `[]` para eventos sin campos — preexistente, fuera de la intención (sólo `metadata`); mensaje del test cambiado para no avalarlo.
  - `[low]` `[patch]` (BH) La coerción es sólo de nivel superior y la doc promete más — docblock, ADR y catálogo dicen «nivel superior».
  - `[low]` `[reject]` (BH) Dos rutas de codificación en la clase (`encode()` + inline) — dos líneas, sin daño nombrado; `encode()` sigue sirviendo `payload`.
  - `[low]` `[patch]` (BH) El nombre `DbalEventStoreMetadataShapeTest` esconde la decisión de no traducir — renombrado a `DbalEventStoreAppendTest`.
  - `[false]` `[reject]` (BH) `stream()` no probado con filas `[]` y `{}` — `decode()` usa `json_decode(…, true)`, que colapsa ambas en `[]`; no hay divergencia posible.
  - `[medium]` `[patch]` (BH+ECH) Recuento de `api-error-contract.md` renumera la historia y parte de base rancia — corregido contra el árbol: seis puertos, tres grafías (`image` y `RecoverySecretAlreadyExists` incluidos), la frase de `identity_user.email` vuelve a ser histórica.
  - `[low]` `[patch]` (BH) No se dice qué ve el cliente ante una violación — docblock y error-contract: aborta la transacción, 500.
  - `[low]` `[patch]` (ECH) `DoctrineRecoverySecretRepository:49` «six other catches» — ahora son cinco; corregido.
  - `[low]` `[patch]` (ECH) `EventStoreSubjectAnonymiser` docblock apunta a `deferred-work.md` — apunta a la enmienda de D4.
  - `[medium]` `[patch]` (ECH) Recuento de puertos/grafías falso — mismo arreglo que la fila de error-contract de arriba.
  - `[low]` `[patch]` (ECH) `metadata` «siempre objeto» sin nota de filas viejas — mismo arreglo que la fila de filas `[]`.
  - `[low]` `[patch]` (VG, other) Mismo hallazgo de filas `[]` en el ADR del event_store — mismo arreglo.
  - `[false]` `[reject]` (IA) `encode()` vs sitio de llamada — el resultado exigido (columna `metadata` objeto) se cumple; cambiar `encode()` alteraría `payload`, que la intención no pide.
  - `[low]` `[patch]` (IA) Retirada de la promesa probada sólo con stub — cubierto por el test funcional nuevo.
  - `[low]` `[patch]` (IA) ADR ~L380 «la unicidad … se preserva» junto a la enmienda — reescrito: secuencia informativa, sin índice que la garantice.
  - `[false]` `[reject]` (IA) No consta `make php.quality` / spec sin trackear — se corrió (exit 0) y el spec se commitea en la finalización.
  - `[false]` `[reject]` (IA) Edición no pedida en el docblock de `DbalEventStoreStreamTest` («dev» → «test») — corrección de un hecho falso en fichero ya tocado (boy scout), no desviación.

### 2026-09-29 — Review pass (follow-up)
- verdicts: 26 findings — high 0, medium 0, low 13, false 5, maybe-false 0 (8 carried/rechazados por regla)
- findings:
  - `[low]` `[patch]` (BH) El AC de `git grep` falla: `api-error-contract.md:78` nombraba el tipo retirado — frase reescrita sin el token («the `event_store` is not a seventh port»); el grep vuelve vacío.
  - `[low]` `[patch]` (BH) «Triggers de revisita» (a) y (c) no remiten a la enmienda de D4 — (a) dice que un `tenant_id` no nulo activa el índice y obliga a retomarla antes; (c) que consumir la versión exige serializar el *append*.
  - `[low]` `[patch]` (BH) D4 decía «Preparado ya — lo consumirán…» tras declararla informativa — reescrito: podrán consumirla tras serializar el *append*; hoy dos *appends* pueden compartir versión.
  - `[low]` `[defer]` (BH) `payload` sigue escribiéndose `[]` — carried: mismo sitio y afirmación que la fila diferida del primer pase (ya en `deferred`).
  - `[low]` `[patch]` (BH) El bloque de esquema del ADR mantenía `recorded_on … DEFAULT now()`, que la migración no declara — alineado: sin default, el escritor pone `clock_timestamp()`.
  - `[false]` `[reject]` (BH) `actor` en la línea de `metadata` contradice D9 — el propio ADR (D12, enmienda 2026-08-04) reconcilia ese `actor` futuro con D9 y razona a partir de él; quitarlo rompería ese argumento.
  - `[low]` `[patch]` (BH) La coerción «sólo de nivel superior» no estaba probada para `event_store` — caso `['x' => []]` → `{"x":[]}` añadido a `DbalEventStoreAppendTest`.
  - `[low]` `[reject]` (BH) `EventStoreStreamVersionInformativeTest` no distingue «índice inerte» de «sin índice» — sin daño: un índice ausente tampoco obliga a revisar la decisión, que es lo único que el canario vigila; añadir la lectura de `pg_indexes` es complejidad sin consumidor.
  - `[low]` `[patch]` (BH) La mitad «escritor» de la premisa no avisa de D4 — `assertNull($found->tenantId)` de `DbalEventStoreStreamTest` lleva ahora un mensaje que manda a la enmienda de D4.
  - `[low]` `[reject]` (BH) El «500» documentado sólo está fijado en su primer eslabón — la verificación del eslabón restante (sin catch en `DoctrineTransactionManager`) confirma la cadena; un test extremo a extremo es más que una corrección directa para un camino hoy inalcanzable.
  - `[false]` `[reject]` (BH) El nombre del test difiere del spec — el arreglo es editar el spec de este build (regla de rechazo); el primer pase ya registró el renombrado.
  - `[low]` `[reject]` (BH) Cabecera del ADR sin `Amended:` — la convención del repo es la enmienda fechada en línea (p. ej. `administrative-recovery-channel.md`); la cabecera `Status · Date · Scope` es fija.
  - `[false]` `[reject]` (BH) Cifras del ADR (24/7/2/15) frente a las del ledger (20/4/16) — la del ADR es una medición fechada (2026-09-20); el arreglo editaría `deferred-work.md`, que es del orquestador.
  - `[low]` `[reject]` (BH) `encode()` sirve ya a una sola columna — carried: fila «dos rutas de codificación» del primer pase.
  - `[low]` `[patch]` (ECH) El AC de grep falla en `api-error-contract.md:78` — mismo arreglo que la primera fila.
  - `[low]` `[reject]` (ECH) `RepositoryUniqueViolationTest` sin caso `image` — preexistente y ya diferido (frontmatter `deferred`, ledger DW-65); no se duplica.
  - `[false]` `[reject]` (ECH) Nombre del test distinto del spec — arreglo = editar el spec.
  - `[low]` `[defer]` (VG, other) `payload` `[]` para eventos sin campos — carried: mismo diferido.
  - `[low]` `[patch]` (IA) R2(b): el token sigue en `api-error-contract.md` — mismo arreglo que la primera fila.
  - `[low]` `[reject]` (IA) R4(b): el 500 no se prueba en HTTP — mismo razonamiento que la fila del «500».
  - `[low]` `[reject]` (IA) `metadata` con claves sólo se prueba como parámetro — nada emite metadata con claves hoy; el caso vacío sí se prueba en BD.
  - `[low]` `[reject]` (IA) Nada fija que el SQL del INSERT no cambie — el diff lo deja byte a byte igual; un test de texto SQL no protege ninguna conducta nombrada.
  - `[low]` `[patch]` (IA) El canario mide el esquema, no el escritor — cubierto por el mensaje nuevo del `assertNull` de `tenantId`.
  - `[low]` `[reject]` (IA) El censo de la enmienda no lo comprueba nada — medición fechada (hoy `git grep -l` da 25 ficheros, uno más que el 2026-09-20); fechada, no falsa.
  - `[false]` `[reject]` (IA) Cambios fuera de la intención (recuento de error-contract, «five other catches», docblock del test) — boy scout sobre ficheros tocados, nombrados en el primer pase y verificados correctos contra el árbol.
  - `[false]` `[reject]` (IA) Cambios sin commitear en `deferred-work.md` y el spec — contabilidad del orquestador, fuera del diff.

## Verification

**Commands:**
- `make php.unit c='--filter "DbalEventStore"'` -- expected: verde, incluido el test funcional.
- `make php.stan` -- expected: sin errores.
- `make php.quality` -- expected: exit 0.

## Auto Run Result

Status: done

**Resumen.** Pase de revisión de seguimiento sobre DW-16/DW-30(b). El cambio sigue siendo el del primer pase: `aggregate_version` es informativo (sin catch, sin `EventStreamConcurrencyConflict`) y `event_store.metadata` se escribe como objeto JSON de nivel superior. Este pase añade seis parches de coherencia: el AC de `git grep` vuelve a estar vacío, el ADR deja de declarar un `DEFAULT now()` que la migración no tiene y sus triggers de revisita remiten a la enmienda de D4, y dos tests cubren la coerción anidada y el aviso del `tenant_id`.

**Ficheros de este pase.**
- `docs/api-error-contract.md` — el párrafo del `event_store` ya no nombra el tipo retirado.
- `docs/adr/event-store-and-projections.md` — `recorded_on` sin default; D4 sin «Preparado ya»; triggers (a) y (c) apuntan a la enmienda.
- `api/tests/Unit/Shared/Event/Infrastructure/Persistence/DbalEventStoreAppendTest.php` — caso de coerción sólo de nivel superior.
- `api/tests/Functional/Shared/Persistence/DbalEventStoreStreamTest.php` — `assertNull(tenantId)` con mensaje que manda a D4.

**Revisión.** 26 hallazgos (Blind Hunter 14, Edge Case Hunter 3, Verification Gap 1, Intent Alignment 8), ejecutadas en paralelo en esta sesión. 6 entradas parcheadas (todas `low`; 9 filas por la agrupación), 1 diferido que ya existía (carried: `payload` `[]`), y el resto rechazado: 5 falsos (`actor` en metadata, nombre del test ×2, cifras del ledger, cambios fuera de la intención, estado del orquestador) y los `low` sin daño nombrado o de arreglo desproporcionado (canario `pg_indexes`, 500 extremo a extremo, cabecera `Amended`, SQL sin fijar, metadata con claves en BD, censo fechado, `encode()` carried, caso `image` ya diferido como DW-65).

**Recomendación de follow-up:** false. Es un pase de seguimiento sin ningún `high` parcheado (0 high, 0 medium, 6 low parcheados): el trabajo ha convergido.

**Verificación.** `make php.unit c='--filter "DbalEventStore|EventStoreStreamVersionInformative"'` → OK (6 tests, 34 assertions); `make php.quality` → exit 0 (incluye PHPStan); `git grep -n 'EventStreamConcurrencyConflict\|event-stream-conflict' -- api docs` → vacío (exit 1).

**Riesgos residuales.** El canario de inercia del índice sigue sin haberse visto en rojo, porque provocarlo exige cambiar el esquema. `payload` `[]` y el caso `image` siguen diferidos. El «500» ante una violación del índice está razonado, no probado de punta a punta; hoy ese camino es inalcanzable.
