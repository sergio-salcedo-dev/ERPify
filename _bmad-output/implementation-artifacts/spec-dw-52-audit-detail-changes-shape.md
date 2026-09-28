---
title: 'DW-52 — un metadata.changes nulo o escalar degrada a «sin diff estructurado» en el detalle de auditoría'
type: 'bugfix'
created: '2026-09-28'
status: 'done'
baseline_revision: '329aba572cea413f1063af776e59fe4bd649987d'
review_loop_iteration: 1
followup_review_recommended: false
context: []
warnings: []
deferred:
  - summary: >-
      Una fila de nivel change SIN clave changes sigue pintando «No changes recorded», afirmación igual de desconocida que la del diff ilegible.
    evidence: |-
      AuditEntryDrawer pasa `detail.metadata.changes ?? {}` a AuditChangeDiff cuando no hay flag; comportamiento previo a este cambio, y el capturador siempre escribe `changes` en filas change, así que sólo aparece con otra vía de escritura.
    location: >-
      pwa/src/context/backoffice/audit/infrastructure/ui/AuditEntryDrawer.tsx
    severity: low
  - summary: >-
      Un changes escalar corrupto nunca pasó por el sellado por campo, así que podría llevar PII sin cifrar servida tal cual por la ruta de detalle.
    evidence: |-
      Preexistente: la API ya servía el escalar verbatim antes de este cambio (sólo cambia el cliente). Ningún escritor produce un escalar; sólo una fila corrupta. Lo zanjaría comprobar si el anonimizador de recurso o el crypto-shredding alcanzan un metadata.changes no-mapa.
    location: >-
      api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php
    severity: low
---

<intent-contract>

## Intent

**Problem:** `AuditEventDetailResourceMapper` sólo sella un `metadata.changes` con forma de array, así que un `changes` almacenado como `null` o escalar llega al cable tal cual; el guard PWA `isAuditEventMetadata` exige un mapa bien formado cuando la clave existe y rechaza el sobre entero (`MALFORMED_RESPONSE_ENVELOPE`), perdiendo el evento completo por un campo que la UI sólo usa para pintar el diff.

**Approach:** La API sigue sirviendo el valor almacenado tal cual (el mapper sella una forma, nunca la fabrica ni la borra: fidelidad forense, como ya hace con la lista) y lo fija con test; el cliente PWA trata un `changes` presente pero `null`/escalar como ausencia de diff estructurado — lo admite en el guard y lo retira del slot tipado al mapear — mientras que una lista o un mapa mal formado siguen siendo deriva y siguen rechazando.

## Boundaries & Constraints

**Always:** una lista (vacía o no) en `changes` y un mapa con un par `{old,new}` mal formado siguen rechazando el sobre entero; `metadata` no-objeto sigue rechazando; el slot tipado `changes` del dominio sólo contiene un `AuditChanges` válido o está ausente.

**Never:** no reescribir ni borrar el valor corrupto en la API; no inventar un `{}` en el cable; no cambiar el tratamiento de `operation` ni de listas; no tocar la ruta de escritura (`AuditWriteCaptureListener`); no editar `deferred-work.md`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| null | fila con `metadata = {"changes": null}` | API sirve `"changes":null`; PWA admite el sobre, `detail.metadata.changes` ausente, drawer pinta diff vacío | ninguno |
| escalar | `changes` = `"x"`, `7`, `1.5`, `true`/`false` | API lo sirve verbatim; PWA admite y retira el slot | ninguno |
| lista | `changes` = `[]` o `[{…}]` | sin cambios: PWA rechaza | `MALFORMED_RESPONSE_ENVELOPE` |
| mapa roto | `changes` = `{name: {old: "a"}}` | sin cambios: PWA rechaza | `MALFORMED_RESPONSE_ENVELOPE` |

</intent-contract>

## Code Map

- `api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php:52-63` -- `withChangesAsMap()`: sólo envuelve arrays vacíos/con claves; null/escalar pasan intactos (ya es el comportamiento deseado). Docblock de clase a ampliar con la decisión null/escalar.
- `api/tests/Unit/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapperTest.php:70-77` -- `testToResourceNeverInventsAChangesKey` ya fija `null`; ampliar a escalares con data provider.
- `api/tests/Functional/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailFunctionalTest.php:127,203` -- `seedChangeRow()` y el patrón de aserción sobre bytes (`testAnEmptyDiffSerializesChangesAsAnObject`) a reutilizar.
- `pwa/src/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.ts:77-82` -- `isAuditEventMetadata` (punto de fallo); `:147-155` `toMetadata` (ya retira un `changes` que no pasa `isAuditChanges`, por lo que sólo el guard necesita cambiar).
- `pwa/tests/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.test.ts:89-200` -- tests del guard (lista rechazada en `:151`) y del mapeo (`:45` como modelo del par guard+mapeo).
- `pwa/src/context/backoffice/audit/infrastructure/ui/AuditEntryDrawer.tsx:207-219` -- sección «Changes»: renderiza `changes ?? {}`; se añade la rama de diff ilegible.
- `docs/architecture-api.md:282` (última frase sobre `AuditEventDetailResourceMapper`) -- documentar el caso null/escalar.
- `docs/adr/audit-activity-log.md:508-513` -- dueño de la decisión: hoy dice que el guard PWA rechaza las tres formas (lista, null, escalar); tras este cambio sólo la lista rechaza.
- `api/src/Backoffice/Audit/Application/Resource/AuditEventDetailResource.php:34-36` -- docblock afirma que `changes`, si presente, es un mapa; ya no es todo el contrato del cable.
- `pwa/src/context/backoffice/audit/domain/AuditChange.ts:63-65` -- `AuditEventDetail`; aquí vive la señal nueva de diff ilegible.
- `pwa/src/context/backoffice/audit/infrastructure/ui/AuditChangeDiff.tsx:98-106` -- con `changes` vacío pinta «No changes recorded»: afirmación FALSA para un diff ilegible, por eso el drawer necesita otro estado.
- `pwa/tests/context/backoffice/audit/infrastructure/ui/AuditEntryDrawer.test.tsx:35-85` -- patrón de test del drawer con `detail` suministrado.

## Tasks & Acceptance

**Execution:**
- `pwa/src/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.ts` -- en `isAuditEventMetadata`, admitir `changes` ausente, `null` o escalar (string/number/boolean) además de un `AuditChanges` válido; documentar por qué null/escalar degradan y lista/mapa roto no; ajustar el tipo del predicado para que `toMetadata` siga estrechando con `isAuditChanges` -- es el punto donde se pierde el sobre.
- `pwa/src/context/backoffice/audit/domain/AuditChange.ts` -- añadir a `AuditEventDetail` un `changesUnreadable?: boolean` de nivel superior (NO dentro de `metadata`, que el bloque Metadata vuelca), documentado: presente y `true` sólo cuando el `changes` almacenado existía pero no era un diff -- la UI necesita distinguir «diff ilegible» de «diff vacío».
- `pwa/src/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.ts` (mapeo) -- `toMetadata`/`toAuditEventDetail`: sacar `changes` y reinsertarlo sólo si `isAuditChanges`; si estaba presente y no lo era, `changesUnreadable: true` en el detalle; corregir el docblock de `toMetadata` (ya no es «verbatim» para `changes`) -- el slot tipado sólo contiene un diff válido.
- `pwa/src/context/backoffice/audit/infrastructure/ui/AuditEntryDrawer.tsx` -- en la sección «Changes», si `detail.changesUnreadable`, pintar un aviso propio en inglés (p. ej. «Diff unavailable — the stored change record is not a field-by-field diff.») con `data-testid="audit-entry-drawer__diff--unreadable"` en lugar de `AuditChangeDiff` -- nunca «No changes recorded» para un diff desconocido.
- `pwa/tests/context/backoffice/audit/infrastructure/ui/AuditEntryDrawer.test.tsx` -- caso: detalle `change` con `changesUnreadable: true` muestra el aviso y NO «No changes recorded»; un diff `{}` legítimo sigue mostrando «No changes recorded».
- `pwa/tests/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.test.ts` -- casos guard (null, `"x"`, `7`, `true` admitidos; lista y mapa roto siguen rechazados) y caso `findById` que mapea `changes: null`/escalar a `metadata.changes` indefinido con `changesUnreadable === true`, conservando los hermanos; un diff válido o ausente NO marca `changesUnreadable`; añadir a los rechazos una lista no vacía de no-pares (`[1]`) -- cubre la matriz.
- `api/tests/Unit/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapperTest.php` -- data provider null/string/int/float/bool: el valor se sirve idéntico (`assertSame`) y no se envuelve.
- `api/tests/Functional/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailFunctionalTest.php` -- data provider null, `"corrupt"`, `7`, `1.5`, `true`, `false`: 200, y sobre el cuerpo DECODIFICADO `data.metadata` tiene la clave `changes` con valor idéntico (`assertArrayHasKey` + `assertSame`), no una búsqueda de subcadena -- superficie exterior de la API.
- `api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php` -- sólo docblock: null/escalar se sirven verbatim y el cliente los degrada.
- `docs/architecture-api.md` -- una frase sobre el caso null/escalar.
- `docs/adr/audit-activity-log.md` -- corregir la frase final del párrafo de `changes` en el cable: la lista sigue rechazando el sobre; `null`/escalar se sirven tal cual y el cliente los degrada a «diff ilegible», con el porqué de la asimetría (ver Design Notes). Es el único sitio que lleva el razonamiento completo; docblocks de tests y de código lo resumen en una línea y remiten aquí.
- `api/src/Backoffice/Audit/Application/Resource/AuditEventDetailResource.php` -- docblock: `changes` es un mapa cuando es un diff; un `null`/escalar almacenado se sirve tal cual.

**Acceptance Criteria:**
- Given una fila `change` cuyo `metadata.changes` es `null` o escalar, when se pide `GET /api/v1/backoffice/audit/events/{id}`, then responde 200 con el valor verbatim en `metadata.changes`.
- Given esa respuesta, when el repositorio PWA la valida y mapea, then no lanza `MALFORMED_RESPONSE_ENVELOPE` y el detalle resultante no tiene `metadata.changes`, conservando el resto de `metadata`.
- Given `changes` con forma de lista o mapa mal formado, when el guard PWA lo valida, then sigue rechazándolo.
- Given un detalle `change` cuyo `changes` almacenado era `null`/escalar, when se abre el drawer, then la sección «Changes» muestra el aviso de diff ilegible y no «No changes recorded»; un diff `{}` legítimo sigue mostrando «No changes recorded».

## Spec Change Log

### 2026-09-28 — loop 1 (bad_spec)
- **Hallazgo:** Blind Hunter, Edge Case Hunter y Verification Gap coinciden: al retirar `changes` el drawer pinta `AuditChangeDiff` sobre `{}` → «No changes recorded», afirmación falsa sobre una fila cuyo diff es desconocido; además el ADR (`docs/adr/audit-activity-log.md:508-513`) y el docblock de `AuditEventDetailResource` seguían diciendo lo contrario, y el test funcional sólo buscaba subcadenas con dos casos.
- **Enmienda:** Code Map (ADR, Resource DTO, dominio, drawer, AuditChangeDiff), tareas nuevas (`changesUnreadable` en dominio, aviso en drawer + test, ADR, docblock DTO, funcional sobre cuerpo decodificado con seis casos, rechazo `[1]`), AC del drawer, Design Notes (argumento real de la asimetría con la lista).
- **Estado malo evitado:** una fila corrupta leída por un operador como una escritura sin cambios.
- **KEEP:** del primer intento (`tmp/dw52-pass1-keep.patch`, ruta relativa a la raíz del repo) conservar: el cambio del guard `isAuditEventMetadata` (admite `isAuditScalarOrNull`), el tipo `AuditEventMetadataWire` hilado por `AuditEventDetailWire`, el `toMetadata` que desestructura `changes` y sólo lo reinserta si `isAuditChanges`, el test unitario PHP con data provider de seis casos y la reducción de `testToResourceNeverInventsAChangesKey`, y los tests PWA de guard/mapeo — ampliados según las tareas. Sustituir su frase «hides nothing that could be misread» y el argumento `{"0": …}` para la lista por el de Design Notes.

## Review Triage Log

### 2026-09-28 — Review pass
- verdicts: 18 findings — high 0, medium 5, low 10, false 3, maybe-false 0
- findings:
  - `[medium]` `[bad_spec]` BH: el ADR `docs/adr/audit-activity-log.md:508-513` sigue diciendo que el guard rechaza null/escalar — verificado en el árbol; enmienda: tarea ADR.
  - `[low]` `[bad_spec]` BH: docblock de `AuditEventDetailResource.php:34-36` sólo describe `changes` como mapa — verificado; enmienda: tarea docblock DTO.
  - `[medium]` `[bad_spec]` BH: una fila corrupta se ve igual que un diff vacío legítimo («No changes recorded», `AuditChangeDiff.tsx:106`) — verificado; enmienda: `changesUnreadable` + aviso en drawer + AC.
  - `[low]` `[reject]` BH: nada reporta la corrupción (log/métrica) — con el aviso propio en el drawer el operador la ve; añadir telemetría es una rama nueva para filas que el capturador nunca escribe.
  - `[medium]` `[bad_spec]` BH: el argumento `{"0": …}` para la lista es del mapper, no del cliente — verificado (el mapper no envuelve listas); enmienda: Design Notes con el argumento real (una lista puede llevar pares reales).
  - `[low]` `[bad_spec]` BH: docblock de `toMetadata` sigue diciendo «verbatim» y su argumento es de `operation` — verificado; enmienda: tarea de mapeo.
  - `[low]` `[bad_spec]` BH: funcional sólo null y string — verificado; enmienda: seis casos.
  - `[medium]` `[bad_spec]` BH: funcional por subcadena y `"changes":{}` nunca puede fallar para esas entradas — verificado; enmienda: aserción sobre cuerpo decodificado.
  - `[low]` `[bad_spec]` BH: ningún test del drawer para el caso degradado — verificado; enmienda: test en `AuditEntryDrawer.test.tsx`.
  - `[low]` `[bad_spec]` BH: razonamiento copiado en seis sitios, uno ya divergido — enmienda: el ADR es el dueño, el resto remite.
  - `[low]` `[bad_spec]` BH: `changes: []` es inalcanzable desde la API (el mapper lo sella a `{}`) y falta una lista realista — cierto; enmienda: añadir `[1]` (se conserva `[]` como defensa ante otra API).
  - `[medium]` `[bad_spec]` ECH: `toMetadata` retira la clave y el drawer afirma «No changes recorded» — mismo defecto que el tercero; misma enmienda.
  - `[low]` `[reject]` ECH: el valor crudo no llega a ninguna superficie de la UI — coste declarado; el valor sigue en el cable y el aviso nuevo hace visible la corrupción; mostrar el crudo añade superficie sin consumidor.
  - `[false]` `[reject]` ECH (claim): el docblock del mapper haría creer que la corrupción sigue visible — el docblock habla del CABLE, donde es cierto; la parte UI queda cubierta por el aviso nuevo.
  - `[false]` `[reject]` ECH (claim): «hides nothing that could be misread» — refutado en su propia premisa por el hallazgo del drawer; se reescribe la frase, así que la afirmación desaparece (contado como parte de la enmienda del drawer, no como defecto separado).
  - `[false]` `[reject]` VG: sin huecos de verificación en guard/mapeo — confirmado por falsificación propia (6 rojos por mitad); nada que hacer.
  - `[low]` `[bad_spec]` VG (other): tensión entre docblocks y «No changes recorded» — mismo defecto que el drawer; misma enmienda.
  - `[low]` `[bad_spec]` IA: los tests del lado API fijan el statu quo y la PWA no llega a la superficie de pantalla — la parte de pantalla se cubre con el test del drawer; la elección de degradar en el cliente queda argumentada en Design Notes.

### 2026-09-28 — Review pass
- verdicts: 18 findings — high 0, medium 0, low 13, false 5, maybe-false 0
- findings:
  - `[low]` `[patch]` BH: con diff ilegible el `operation` no aparece en ninguna parte (sin cabecera y `nonDiffMetadata` lo quita) y su docblock quedaba falso — aplicado: `nonDiffMetadata(detail)` sólo retira `operation` si el diff es legible; docblock corregido.
  - `[low]` `[defer]` BH: fila change sin clave `changes` sigue diciendo «No changes recorded» — preexistente, no causado por este cambio; diferido.
  - `[low]` `[reject]` BH: el flag se calcula para filas no-change y el drawer no lo muestra — ningún escritor pone `changes` en filas activity/security; mostrarlo exigiría una rama de layout nueva para un caso sin productor.
  - `[false]` `[reject]` BH: la entrada DW-52 del ledger sigue `open` — el ledger lo cierra el orquestador por instrucción expresa de la invocación.
  - `[low]` `[patch]` BH: `changesUnreadable?: boolean` admite `false` contra su docblock — aplicado: `?: true`.
  - `[low]` `[reject]` BH: clasificación calculada dos veces con dependencia de orden respecto al guard — ambas funciones son privadas del mismo módulo y sólo se llaman tras el guard; refactor sin defecto alcanzable.
  - `[low]` `[patch]` BH: faltan escalares falsy `""` y `0` — aplicado en PWA, unit PHP y funcional PHP.
  - `[low]` `[patch]` BH: el test del drawer no afirma el texto del aviso ni lo que muestra Metadata — aplicado: texto exacto y `operation` visible en Metadata.
  - `[low]` `[patch]` BH: docblock de `AuditEventDetail` no mencionaba el caso ilegible — aplicado; la parte sobre «Four states» de `AuditChangeDiff` es falsa: esos cuatro estados son clases de campo, no renderizados del drawer.
  - `[low]` `[defer]` BH: un escalar corrupto podría llevar PII sin sellar — preexistente (la API ya lo servía verbatim), sin escritor; diferido.
  - `[low]` `[reject]` BH: razonamiento repetido en seis sitios — tras la enmienda el ADR es el dueño y los demás remiten en una línea; residuo cosmético.
  - `[low]` `[reject]` ECH: flag en filas no-change ignorado — mismo que el tercero; misma razón.
  - `[false]` `[reject]` ECH: `entry.level` y `detail.level` podrían discrepar — ambos provienen del mismo evento por id; `level` es inmutable en `audit_log`.
  - `[false]` `[reject]` ECH: un float integral `1.0` volvería como int — no es uno de los casos de la matriz y ningún escritor lo produce; si fuese cierto sólo sería `low`, rechazado con la nota de que lo zanjaría sembrar `1.0` en el funcional.
  - `[false]` `[reject]` VG: sin huecos de verificación — confirmado; nada que hacer.
  - `[low]` `[reject]` IA: la API no cambia su comportamiento y el test API fija el statu quo — elección argumentada en Design Notes y ADR (D4); ya tratado en la pasada anterior.
  - `[low]` `[reject]` IA: `changesUnreadable` es superficie que la intención no pidió — es lo que hace cierta la frase «no structured diff» de la intención frente a «No changes recorded»; decidido en el loop 1.
  - `[false]` `[reject]` IA: ningún test recorre repositorio→drawer — el guard, el mapeo y el drawer están fijados cada uno en su frontera, y el drawer consume exactamente el tipo de dominio que el mapeo produce (tsc lo comprueba).

## Design Notes

Degradar en el cliente y no en la API: el mapper es forense y su regla documentada es «sella una forma, nunca la fabrica»; borrar la clave ocultaría la corrupción a todo consumidor del cable. Degradar NO significa fingir un diff vacío: «No changes recorded» afirma que la escritura no cambió nada, y para un `changes` ilegible eso es falso — por eso el detalle lleva `changesUnreadable` y el drawer pinta un aviso propio. Asimetría con la lista: una lista puede llevar pares `{old,new}` reales que perdieron el nombre de campo (un fallo del constructor del diff), así que degradarla tiraría en silencio datos de cambio reales y debe seguir gritando; un `null`/escalar no lleva ningún par que perder. Coste declarado: el valor crudo corrupto no se muestra en la UI (sigue en el cable para quien investigue).

## Verification

**Commands:**
- `make php.unit c='--filter "AuditEventDetailResourceMapperTest|AuditEventDetailFunctionalTest"'` -- expected: verde
- `make php.stan` y `make php.quality` -- expected: exit 0
- `make pwa.test.unit c='tests/context/backoffice/audit'` y `make pwa.quality` -- expected: exit 0

## Auto Run Result

Status: done

**Resumen:** un `metadata.changes` almacenado como `null` o escalar ya no hace que la PWA descarte el detalle entero. La API lo sigue sirviendo tal cual (fidelidad forense, fijado por test unitario y funcional); el cliente admite el sobre, retira `changes` del slot tipado y marca el detalle `changesUnreadable`, y el drawer pinta «Diff unavailable — …» en vez de «No changes recorded» (que afirmaría que la escritura no cambió nada). Una lista o un mapa mal formado siguen rechazando: pueden llevar pares reales que degradar tiraría en silencio.

**Ficheros:**
- `api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php` — docblock: null/escalar se sirven verbatim.
- `api/src/Backoffice/Audit/Application/Resource/AuditEventDetailResource.php` — docblock del contrato de `changes`.
- `api/tests/Unit/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapperTest.php` — provider null/`""`/`0`/string/int/float/bool: valor idéntico.
- `api/tests/Functional/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailFunctionalTest.php` — mismos casos por HTTP, sobre el cuerpo decodificado.
- `pwa/src/context/backoffice/audit/domain/AuditChange.ts` — `changesUnreadable?: true` en `AuditEventDetail`.
- `pwa/src/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.ts` — guard admite null/escalar; mapeo retira el valor y marca el flag.
- `pwa/src/context/backoffice/audit/infrastructure/ui/AuditEntryDrawer.tsx` — aviso de diff ilegible; `operation` sigue visible en Metadata en ese caso.
- `pwa/tests/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.test.ts`, `.../ui/AuditEntryDrawer.test.tsx` — guard, mapeo y drawer.
- `docs/adr/audit-activity-log.md` (D4, dueño del razonamiento), `docs/architecture-api.md` — una frase que remite al ADR.

**Revisión:** pasada 1 → `bad_spec` (el drawer habría afirmado «No changes recorded» sobre un diff desconocido; ADR y docblock del DTO decían lo contrario; test funcional por subcadena), spec enmendada y código re-derivado (loop 1). Pasada 2 → 5 parches `low` aplicados (operation visible, tipo `?: true`, casos `""`/`0`, aserciones del drawer, docblock del dominio), 2 diferidos (`low`, preexistentes: fila change sin `changes`; posible PII en un escalar corrupto), 11 rechazados con su razón en el Review Triage Log.

**Recomendación de revisión de seguimiento:** `false` — la pasada final parcheó sólo 5 entradas `low` (0 high, 0 medium).

**Verificación (ejecuciones frescas, exit impreso):** `make php.unit c='--filter "AuditEventDetailResourceMapperTest|AuditEventDetailFunctionalTest"'` = 0; `make php.quality` = 0; `make pwa.test.unit c='tests/context/backoffice/audit'` = 0 (141 tests); `make pwa.quality` = 0. Falsificación: quitar la admisión del guard o dejar `changes` en el slot enrojece 6 tests cada una (pasada 1); el implementador midió 7 rojos al romper el flag y la rama del drawer (loop 1).

**Riesgos residuales:** el valor crudo corrupto no se muestra en la UI (sigue en el cable); los dos diferidos del frontmatter.
