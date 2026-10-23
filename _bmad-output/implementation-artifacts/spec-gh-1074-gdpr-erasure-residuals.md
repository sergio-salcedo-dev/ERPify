---
title: 'gh-1074 — residuales GDPR/borrado del ledger (DW-13, DW-14, DW-15, DW-60)'
type: 'bugfix'
created: '2026-10-23'
status: 'in-review'
baseline_commit: '2a84ac7df747869ab3146e3b0f12d37563403a42'
route: 'dispatch'
review_loop_iteration: 1
context:
  - '{project-root}/docs/adr/event-store-and-projections.md'
  - '{project-root}/docs/adr/audit-activity-log.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Cuatro entradas abiertas de `deferred-work.md` del bloque GDPR/borrado (#1074). DW-13: la prosa que describe la cadena de borrado (docblocks del comando y del controlador, `setHelp()`, el aviso «Nothing to erase» y la línea de éxito del CLI) enumera a mano lo que `FulfilIdentityErasureResult::ERASED_CATEGORIES` ya enumera, y puede volver a derivar. DW-15: el `UPDATE` de `DbalEventStoreSubjectAnonymiser` es un scan secuencial (medido: ~3 s a 1M de filas). DW-14 y DW-60 hay que re-medirlas y cerrarlas o mantenerlas abiertas con razón fechada.

**Approach:** DW-13 se cierra **derivando, no con un gate de prosa**: toda superficie que enumere categorías se renderiza desde el mapa, y los docblocks remiten al mapa en lugar de enumerar. DW-15 se resuelve con un **índice GIN sobre el array de UUIDs extraídos** de `payload`/`metadata` (decisión del usuario, 2026-10-23, opción (c) frente a pg_trgm y a la aceptación documentada). La función `event_store_uuids(jsonb) RETURNS uuid[]` recorre el JSON serializado y recoge **todas** las ventanas de 36 caracteres con forma de UUID, **solapadas incluidas**. Para ello saca los tramos maximales de `[0-9a-fA-F-]`, los parte por `-` y toma como UUID cada secuencia formada por los 8 últimos caracteres de un grupo, tres grupos de 4 y los 12 primeros del siguiente. El coste es lineal; el `pos+1` con `regexp_instr` que se aprobó primero era cuadrático (9 s por insert con 147 KB). El cambio lo aprobó el usuario el 2026-10-23 tras la revisión de tres capas. Sin flag `'i'` y sin duplicados. Una versión con `regexp_matches(…,'g')` perdía un UUID solapado con otro y se descartó por eso (revisión externa del spec, 2026-10-23). Contrato: `col::text ILIKE '%id%'` ⇔ `event_store_uuids(col) @> ARRAY[CAST(id AS uuid)]` para todo id UUID válido, en claves y valores, a cualquier profundidad y con cualquier caja. Medido a 1M de filas en la misma sesión: misma selección que `ILIKE` (`same_rows = t`); `UPDATE` 3,0 s → 5–20 ms; índice de 138 MB (frente a 425 MB con trigram); inserts ×1,7–2,1 (frente a ×7,8 con trigram). DW-14 se queda abierta por diseño (decisión del usuario, 2026-10-23), con la re-medición fechada. DW-60 se cierra como riesgo aceptado (decisión del usuario, 2026-10-23), sin mutación nueva sobre `audit_log`.

## Boundaries & Constraints

**Always:** antes de cambiar el `WHERE` de producción, test de equivalencia: el predicado viejo y el nuevo seleccionan el mismo conjunto de filas sobre un corpus adversarial. El índice, la función y el plan se prueban como tres contratos distintos. El consentimiento, la ayuda, el aviso y el informe de éxito nombran exactamente las categorías del mapa (en ambas direcciones, con test). Cada entrada del ledger termina `status: done 2026-10-23` con `resolution:` que nombra la PR, o abierta con una línea re-medida y fechada. `make php.stan` sobre cada PHP tocado y `make php.quality` al final. Toda mutación nueva sobre `event_store` lleva línea en `SanctionedLogMutationGateTest` y decisión en D12.

**Never:** gate sobre prosa libre; flag `resource_erased` en `event_store` (reabre D12; DW-14 sigue abierta); backfill sobre `audit_log` (DW-60); borrar a mano entradas o stubs del ledger; DW-32/DW-33 (van en su PR propia); DW-7 (su trigger no ha saltado).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Ayuda | `identity:gdpr:erase-subject --help` | el texto contiene cada etiqueta del mapa | N/A |
| Nada que borrar | id válido sin sujeto vivo ni residuo | el aviso nombra cada etiqueta del mapa; exit 0 | N/A |
| Éxito | borrado con contadores | la línea de éxito lleva una cifra por propiedad del mapa, en el orden del mapa | N/A |
| Equivalencia | corpus: id en valor anidado, en una **clave**, en MAYÚSCULAS, con prefijo/sufijo pegado, **solapado** con otra ventana con forma de UUID, en `metadata`, en `aggregate_id`; casi-UUID (35 hex, guion mal puesto); JSON escalar/array/objeto | las filas de `ILIKE` = las filas de `@>`, comparadas como conjuntos | N/A |
| Borrado indexado | sujeto en `aggregate_id`, en `payload` bajo una clave cualquiera y en MAYÚSCULAS | se reescriben las mismas filas que antes; el plan es un Bitmap Index Scan sobre el índice nuevo, no un Seq Scan | N/A |
| Propiedad nueva | décima propiedad sin etiqueta | `FulfilIdentityErasureResultTest` en rojo (ya existe) | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Iam/Identity/Application/FulfilIdentityErasureResult.php` -- `ERASED_CATEGORIES`: la única enumeración. Su docblock dice que «no alcanza la prosa»: se reescribe.
- `api/src/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommand.php` -- docblock de clase, `setHelp()` (heredoc), aviso «Nothing to erase» (l.153) y línea de éxito (l.161): todo se deriva del mapa. `confirmationQuestion()` ya lo hace y es el patrón a reutilizar (`CATEGORY_SEPARATOR`).
- `api/src/Iam/Identity/Infrastructure/Controller/UserEraseController.php` -- el paréntesis del docblock que enumera la cadena pasa a remitir al mapa.
- `api/tests/Unit/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommandConfirmationTest.php` -- patrón de aserción por etiqueta (colapsando el whitespace).
- `api/tests/Unit/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommandTest.php` -- asserts sobre `Nothing to erase` y `Erased subject <id>`, que deben seguir en verde.
- `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php` -- el `UPDATE` de DW-15: el `WHERE` pasa de `ILIKE` a `event_store_uuids(col) @> ARRAY[lower(:subject_id)]`; el `SET` (`regexp_replace` con `'gi'`) no cambia.
- `api/src/Shared/Event/Infrastructure/Persistence/EventStoreSchemaListener.php` -- es la fuente de verdad de la tabla, pero DBAL no puede expresar índices de expresión. **Medido el 2026-10-23:** el introspector de DBAL hace `JOIN pg_attribute ON attnum` y en un índice de expresión `attnum = 0`, así que la función y el índice son invisibles (`schema:update --dump-sql` y `schema:validate` siguen en sync). La migración es su dueña y el docblock lo dice.
- `api/migrations/` -- migración nueva: `CREATE FUNCTION event_store_uuids(jsonb)` (`IMMUTABLE STRICT PARALLEL SAFE`) y dos índices GIN (`payload`, `metadata`). `down()` reversible.
- `api/tests/Functional/Shared/Event/EventStoreSubjectAnonymiserFunctionalTest.php` -- semántica actual (caja, claves históricas): debe seguir verde sin tocar las aserciones.
- `api/tests/Functional/Shared/Audit/AuditPrunePlanFunctionalTest.php` / `.../Event/FailedMessagePrunePlanFunctionalTest.php` -- patrón de test de plan sobre una población sembrada y con `ANALYZE`.
- `docs/adr/event-store-and-projections.md` D12 -- enmienda: el predicado y su índice, con las cifras medidas y las alternativas descartadas.
- Scratch de medición: `tmp/dw15/` (BD desechable `dw15_measure`; borrarla al final).
- `_bmad-output/implementation-artifacts/deferred-work.md` -- DW-13, DW-15, DW-60 se cierran; DW-14 recibe la re-medición. DW-14: `EventStore` solo expone `append`/`stream`; no hay rehidratación ni flag de borrado (trigger sin saltar, 2026-10-23).

## Tasks & Acceptance

**Execution:**
- [x] `EraseIdentitySubjectCommand.php` -- dos métodos privados: uno que une las etiquetas (para `setHelp()` y el aviso) y otro que itera **el mapa** y toma cada valor del resultado con `get_object_vars($result)[$property]` (para la línea de éxito). El orden del mapa es solo de presentación, no de ejecución; el docblock de clase remite al mapa -- deriva imposible por construcción.
- [x] `UserEraseController.php`, `FulfilIdentityErasureResult.php` -- docblocks: remitir al mapa en lugar de enumerar; corregir «no alcanza la prosa».
- [x] `EraseIdentitySubjectCommandConfirmationTest.php` (o un hermano) -- pinear, para cada superficie (ayuda, aviso y éxito), que aparece cada etiqueta; en el éxito, además, su contador y el orden del mapa; falsificar cada uno (provocar el rojo y restaurar copiando bytes).
- [x] `api/tests/Functional/Shared/Event/EventStoreSubjectPredicateEquivalenceFunctionalTest.php` -- **primero**: el corpus de la matriz y la igualdad de conjuntos entre el predicado viejo y el nuevo; falsificarlo con la variante `regexp_matches 'g'` (debe fallar en el caso solapado).
- [x] `api/migrations/Version<ts>.php` -- función + 2 índices GIN con nombres estables; `down()` borra los índices y después la función. `CREATE INDEX` normal dentro de la transacción de la migración (ver Design Notes).
- [x] `api/tests/Functional/Shared/Event/EventStoreUuidIndexSchemaFunctionalTest.php` -- la función existe con `IMMUTABLE`/`STRICT`/`PARALLEL SAFE` y devuelve `uuid[]`; los dos índices existen, son GIN y su expresión es `event_store_uuids(<col>)`. Este test cubre lo que Doctrine no ve.
- [x] `DbalEventStoreSubjectAnonymiser.php` -- predicado `event_store_uuids(col) @> ARRAY[CAST(:subject_id AS UUID)]`, la misma expresión que el índice; el `CAST` normaliza la caja; docblock actualizado (por qué un array de UUIDs y no trigram, y que la función es la misma en el índice y en la consulta).
- [x] `EventStoreSchemaListener.php` -- docblock: el índice de expresión vive en la migración porque DBAL no lo ve (medido).
- [x] `api/tests/Functional/Shared/Event/EventStoreSubjectAnonymiserPlanFunctionalTest.php` -- sobre una población sembrada (transacción revertida) y con `ANALYZE`, `EXPLAIN (FORMAT JSON)` del `UPDATE` real, sin ANALYZE (no ejecuta), contiene un nodo con `Index Name` = el índice de `payload`. Sin `enable_seqscan=off` ni comparación textual del plan; falsificarlo quitando el índice.
- [x] `docs/adr/event-store-and-projections.md` D12 -- enmienda.
- [ ] `deferred-work.md` -- solo después de abrir la PR y con los gates en verde: DW-13 y DW-15 a `done 2026-10-23` con `resolution:` y el número **real** de la PR. DW-60 cerrada como aceptada: residuo (filas anteriores con el literal `[REDACTED]` del cliente), por qué no hay backfill (mutación sancionada nueva para un literal que la atribución ya maneja), control verificado (el predicado de flags atribuye bien), quién acepta (el usuario, 2026-10-23) y reapertura (datos reales dentro de la ventana de retención de `audit_log`). DW-14 sigue abierta: evidencia (`EventStore` solo tiene `append`/`stream`; ningún agregado-persona se rehidrata; no hay flag de borrado), riesgo residual (un id de persona que se escape al borrado preventivo en `event_store` no lo detecta nadie; lo leen los proyectores al hacer replay), condición de cierre (el trigger de D12) y por qué no ahora (reabre D12). Que el trigger no haya saltado no prueba que no haya riesgo, y la entrada lo dice.

**Acceptance Criteria:**
- Given una etiqueta borrada del texto de la ayuda, del aviso o de la línea de éxito, when corre la suite, then un test falla nombrando la propiedad.
- Given el ledger tras la PR, when se lee el bloque, then DW-13/15/60 están `done 2026-10-23` con `resolution:` y DW-14 está abierta con la medición del 2026-10-23.

## Design Notes

**Migración sin `CONCURRENTLY`.** `CREATE INDEX` normal bloquea las escrituras de `event_store` mientras se construye. Medido a 1M de filas: unos 30 s. Sobre la tabla vacía de hoy es instantáneo, y el usuario confirmó el 2026-10-23 que no hay ningún despliegue con datos. Cuando lo haya, la migración sería `isTransactional(): false` con `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, y la receta para un índice que quede `INVALID` (`DROP INDEX CONCURRENTLY` y repetir) iría en D12.

**`uuid[]` y no `text[]`:** 16 bytes por entrada frente a 36, y el `CAST` del parámetro normaliza la caja sin `lower()`.

## Implementation Notes

- **Re-medición con el extractor final (2026-10-23, 1M de filas, PG 18.6, `tmp/dw15/remeasure.sh` → `remeasure.out`):** la selección coincide con `ILIKE` (`same_rows = t`, 9 filas). `UPDATE` sin índice: 3,40–3,42 s (Seq Scan). Indexado: 9–144 ms, por BitmapOr de los tres índices. Índice de `payload`: 138 MB. Construcción: 32 s + 5,5 s. Inserts de 50k: 1,7–5,7 s sin índice y 8,9–12,0 s con índice. **La variación entre rondas es alta, así que el factor de inserción hay que darlo como rango (unos ×2–×7) y no como ×1,7–2,1.** Estas cifras van al ADR y a la PR en lugar de los `<re-measure>`.

- Raíz de trabajo: `/home/dev/Projects/ERPify/.claude/worktrees/shared-gdpr-erasure-residuals-nza3` (worktree de la rama `fix/shared-gdpr-erasure-residuals-nza3`, con su propio stack levantado). Todos los paths y `make` se resuelven ahí, nunca en `/home/dev/Projects/ERPify`. Sin `git commit`/`push`/`stash`. Los SQL de medición están en `/home/dev/Projects/ERPify/tmp/dw15/` (`psql.sh [db]`).

## Spec Change Log

## Review Triage Log

Capas (2026-10-23, subagentes en paralelo y en solo lectura sobre la worktree): Blind Hunter (BH), Edge Case Hunter (EC), Verification Gap (VG) y Acceptance Auditor (AA).

| # | Hallazgo (capas) | Veredicto | Evidencia | Ruta |
|---|---|---|---|---|
| 1 | Extractor cuadrático: `regexp_instr`/`substr` desde el inicio en cada vuelta (EC, BH) | high | Medido: 14,5 KB 95 ms, 147 KB 8,9 s; tramo hex/guion hostil de 90k, 8,9 s. Versión lineal: 4,5 ms, 19 ms y 2–4 ms | patch (frozen enmendado con aprobación del usuario) |
| 2 | `CREATE FUNCTION` no idempotente: `db.reset` rompe el `migrate` (EC, AA) | medium | `make/db.mk:30` usa `schema:drop --full-database`; `DropSchemaObjectsSQLBuilder` solo emite tablas y secuencias, así que la función sobrevive | patch: `CREATE OR REPLACE`, `IF NOT EXISTS` |
| 3 | La receta `CONCURRENTLY` es incompatible con la migración (BH) | medium | El índice concurrente necesita la función antes, y la migración usaba `CREATE INDEX` sin `IF NOT EXISTS` | patch: con el #2 la receta funciona (función e índices concurrentes fuera de banda y la migración no hace nada); reescribir la receta |
| 4 | El flag `'i'` hace depender `IMMUTABLE` de la colación (BH) | low | Clases `[0-9a-f]` con `'i'` | patch (lo absorbe el #1) |
| 5 | Array con duplicados (BH) | low | `found \|\| …` sin dedupe | patch (lo absorbe el #1) |
| 6 | `report()` lanza tras el commit → exit 1 sobre un borrado hecho (EC, BH) | medium | `EraseIdentitySubjectCommand.php:219` fuera del `try`; inalcanzable mientras el mapa sea igual a las propiedades, pero mentiría a un llamador desatendido | patch: no lanzar |
| 7 | Memberships e invitations a 0: no se detecta que se intercambien (VG, EC, BH, AA) | low | `CategoriesTest:1002-1003`; `InMemoryInvitationRepository` admite precarga | patch |
| 8 | Docblock de `TransientTransactionFailure` y ADR `audit-activity-log.md` siguen diciendo «`payload::text ILIKE` scan secuencial» (VG, BH, AA) | medium | `TransientTransactionFailure.php:31-33`, `audit-activity-log.md:258-261`: premisa falsa del 503 | patch |
| 9 | «Ninguna segunda enumeración» exagera: el docblock de `FulfilIdentityErasure` y la `description` de `#[AsCommand]` (EC, AA) | low | El primero argumenta el ORDEN, que el mapa no recoge; la `description` es un resumen | patch: acotar la afirmación |
| 10 | El test de plan solo afirma el índice de `payload` (BH) | low | Un `OR` necesita todos los brazos indexados para el BitmapOr (VG, AA), pero un «no Seq Scan» explícito es más directo | patch |
| 11 | El brazo `aggregate_id` depende del skip scan de PG18 (BH) | false | El brazo es anterior al cambio y ya se midió a 1M de filas con 5 `aggregate_type` y `tenant_id` aleatorio (`Index Searches: 11`) | rechazado |
| 12 | Ninguna guarda ante un despliegue poblado (BH) | false | El usuario confirmó el 2026-10-23 que no hay despliegue con datos; la receta va en D12 | rechazado |
| 13 | Corpus solo ASCII, sin el sujeto en ambas columnas ni repetido (BH) | medium | Afecta al `SET`/`WHERE` y al extractor | patch: añadir filas |
| 14 | La ayuda pierde «columna de agregado y JSON» (BH) | low | Era un dato operativo para quien atiende una solicitud | patch: frase estática |
| 15 | Redacción relativa al cambio en los tests (`OLD_PREDICATE`, «replaced», «no longer») (AA) | low | Reglas de comentarios de `CLAUDE.md` | patch |
| 16 | Encabezado «matched as text» a medias (AA) | low | El `WHERE` ya no compara texto | patch |
| 17 | Cifras de la enmienda sin trazar y no medidas con la función final (AA) | medium | La función cambió después de medir | patch: re-medir a 1M con la final y registrarlo en la PR |
| 18 | BD residual `dw15_q` (AA) | low | Higiene | patch: borrarla |

## Verification

**Commands:**
- `make php.unit c='--filter EraseIdentitySubjectCommand'` -- expected: verde
- `make php.unit c='--filter EventStoreSubjectAnonymiser'` -- expected: verde (semántica + plan)
- `make db.migrate`, después `doctrine:migrations:migrate prev` y de nuevo `make db.migrate` -- expected: up, down y up sin error
- `make sf c='doctrine:schema:update --dump-sql'` -- expected: «Nothing to update» (solo prueba que Doctrine no generará un DROP; la existencia del índice la prueba el test de esquema)
- `make php.stan` y `make php.quality` -- expected: exit 0
