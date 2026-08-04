---
baseline_commit: 8bc9893a
---

# Story 1.7 (G-5): Ids de persona fuera del `event_store`

Status: done

> **LA DECISIÓN ESTÁ TOMADA Y REGISTRADA EN UN ADR** (Sergio, 2026-08-01):
> [`docs/adr/event-store-and-projections.md`](../../docs/adr/event-store-and-projections.md) **D12**. El log pasa
> a *append-only con un conjunto cerrado de mutaciones sancionadas* —hoy una, el borrado GDPR—, igual que su
> hermano `audit_log`. **Un único `UPDATE` parametrizado** reescribe el id del sujeto con un UUID aleatorio nuevo
> acuñado en el borrado, **en la columna y en el texto serializado de `payload` y `metadata`, por
> coincidencia de valor**, dentro de la transacción que `FulfilIdentityErasure` ya posee, y **solo para un
> sujeto cuya identidad estaba viva** — el predicado por valor no distingue clases de agregado, así que la
> cota vive en el caso de uso. **No la reabras.** Lo que faltaba era este artefacto, no la decisión.

> **Es la última historia de la épica.** Mientras G-5 no cierre, `epic-gdpr-hardening` no puede declararse
> completada. Todas las demás (G-1a, G-1b, G-1c, G-2, G-3a, G-3b, G-4a) están mergeadas.

## Story

Como **sujeto de datos borrado**,
quiero que mi identificador tampoco sobreviva en el log de eventos de negocio,
para que el borrado deje de ser cierto solo en las tablas que alguien se acordó de mirar.

**Eje que instala:** la última superficie del eje SI-21 — la tabla que ningún registro alcanza.
**Invariantes que consume:** SI-21/NFR1, D4/NFR4 (prohibición de crosswalk), SI-23 (el control no puede leerse
verde por construcción).
**Dependencias:** ninguna. G-4a cerró la fuga de Messenger; ésta es la permanente que aquélla no alcanza.

## Estado medido (`main` @ `8bc9893a`)

> *Procedencia:* pase read-only sobre el árbol del día. **Dos afirmaciones del corte cedieron al medirlas** y
> están corregidas abajo. Las coordenadas se dan para re-verificarlas, no para citarlas de memoria.

### La superficie son DIECISÉIS tipos de evento en DOS ejes, y el corte contaba OCHO

| Eje | Eventos | N |
|---|---|---|
| `aggregate_id` **es** el id de la persona | los 6 de `Iam.Identity` (`PasswordResetCompleted`, `PasswordResetRequested`, `UserDeactivated`, `UserLocked`, `UserRolesChanged`, `UserSuspended`) **+ `AllSessionsRevoked` + `OtherSessionsRevoked`** | **8** |
| `payload` lleva el id de la persona | los 6 `Invitation*` (`invitedUserId`, vía el trait `CarriesInvitationSnapshot`) + `SessionStarted` + `SessionRevoked` (`userId`) | **8** |

**CORRECCIÓN 1 — el inventario del corte decía «`aggregate_id` de 6 eventos». Son 8.** Se dejaba los dos
revokes masivos, y no es una inferencia:
[`RevokeAllSessions.php:35`](../../api/src/Iam/Session/Application/RevokeAllSessions.php) publica
`new AllSessionsRevoked($userId, …)` y
[`RevokeOtherSessions.php:39`](../../api/src/Iam/Session/Application/RevokeOtherSessions.php)
`new OtherSessionsRevoked($userId, …)`; el docblock de `AllSessionsRevoked` lo dice con todas las letras:
*«A single coarse fact whose subject is the user, so the aggregateId is the `userId`»*. Es la **segunda**
enmienda a esta lista (la primera añadió `SessionRevoked`), lo cual es en sí un argumento a favor de que el
borrado sea **por coincidencia de valor y no por enumeración de eventos**, que es lo que D12 decidió.

**`.persistent-transport-policy` clasifica `Iam.Invitation => non-person` y ACIERTA.** Ese registro clasifica
por lo que denota el **`aggregate_id`**, y el de una invitación es la invitación. El id de persona viaja en su
`payload`, que ese eje no mira. Ningún registro del repo alcanza hoy el eje de payload — es exactamente el
hueco que G-2 cerró para `audit_log.metadata` y que aquí sigue abierto.

### Quién lee `aggregate_id` de vuelta

**CORRECCIÓN 2 — el corte afirma que «NINGUNA consulta de `src/` lee `aggregate_id` de vuelta». Lo lee una.**
[`DbalEventStore::append()`](../../api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php) `:55-63`
calcula `COALESCE(MAX(aggregate_version), 0) + 1` con `WHERE aggregate_id = CAST(:aggregate_id AS UUID)`. Lo que
sí es cierto, y es la parte que importaba: **ningún consumidor ni proyección lo lee** — `stream()` (`:93-97`)
filtra por `sequence` y `event_name`, nunca por agregado.

**Consecuencia que el corte no enuncia: reescribir la columna MUEVE la fila de stream.** El `UNIQUE` es
`(tenant_id, aggregate_id, aggregate_version)` y **no incluye `aggregate_type`**, así que los eventos de
`Iam.Identity` y los dos revokes masivos del mismo sujeto **comparten secuencia de versión** (todos usan
`aggregate_id = userId`). Mover **todas** sus filas al **mismo** pseudónimo preserva la unicidad; moverlas a
pseudónimos distintos la rompería. El eje de payload no toca `aggregate_id`, así que no interactúa con el
`UNIQUE`. Y como el sujeto queda borrado, ningún append futuro apunta a ese id.

### La cadena de erasure no toca la tabla

`FulfilIdentityErasure` encadena identidad, ambos ejes de `audit_log`, sesiones, membresía e invitaciones —
**y ninguna sentencia sobre `event_store`**. Las únicas menciones en `Iam/Identity` son docblocks, incluidos
los dos que G-2 dejó diciendo que `event_store.aggregate_id` **queda fuera del control detective**. Cuando G-5
cierre, esos dos párrafos hay que revisitarlos.

### Trampas heredadas que muerden aquí

- **El payload es texto jsonb y Postgres NO normaliza el caso.** Medido en G-2 sobre `audit_log.metadata`: la
  misma fuga en mayúsculas pasaba verde bajo `LIKE` y roja bajo `ILIKE`. La ruta entrega la grafía del cliente
  sin canonicalizar (`Uuid::ensure()` valida sin normalizar).
- **`payload` puede ser `[]` y no `{}`.** `jsonb_object_keys` **aborta** sobre un array y `payload ? 'k'` lo
  silencia. Filtrar por `jsonb_typeof(...) = 'object'` o recorrer `::text`.
- **Reescribir por NOMBRE DE CLAVE es la declaración que se comprueba a sí misma.** Ya hay dos nombres
  (`invitedUserId`, `userId`) y el trait garantiza que aparecerán más. D12 lo zanja: por coincidencia de valor.
- **Un `@AfterScenario` rojo en Behat 4 rompe la build pero no marca escenario** — lee el exit code, no el
  recuento.

## Acceptance Criteria

1. **Given** un sujeto borrado, **When** se inspecciona `event_store`, **Then** su id real no sobrevive **ni en
   `aggregate_id` ni en `payload`**.
   → El testigo siembra su propio dato y **afirma el conteo de filas sembradas** antes del veredicto.
2. **Given** ese mismo borrado, **Then** el log sigue siendo reproducible como log: las filas siguen ahí, con su
   `sequence`, su `event_name` y su `aggregate_version` — la garantía no se compra destruyendo la traza.
3. **Given** una escritura futura que vuelva a meter un id de persona en cualquiera de los dos ejes, **When**
   corre la suite, **Then** un control **falla** — y **cada eje necesita su propio rojo**, porque un control que
   solo mire la columna se lee verde con el id vivo en el payload de al lado (D12 lo enuncia como el modo de
   fallo de esta historia).

## Tasks / Subtasks

- [x] **Tarea 1 — Re-medir contra el árbol del día** antes de escribir código: el inventario 8+8 y las dos
      correcciones de arriba.
- [x] **Tarea 2 (AC1, AC2)** — El `UPDATE` único, parametrizado, **por valor**, sobre columna, payload y
      metadata, dentro de la transacción que `FulfilIdentityErasure` ya posee y gateado en que la identidad
      estuviera viva. Un solo pseudónimo para todas las filas del sujeto. Idempotente.
- [x] **Tarea 3 (AC1)** — Testigo de aceptación que siembra y demuestra **en el mismo escenario**, con una fila
      por columna, `ILIKE` y sin suponer que `payload` es un objeto. Más el test funcional contra Postgres del
      adaptador, que cubre lo que el escenario no puede afirmar.
- [x] **Tarea 4 (AC3)** — Falsificación por mutación: **siete mutaciones, siete rojos** —la guarda, y las tres
      columnas por separado en el `SET` y en el `WHERE`—, provocados de verdad y restaurando por copia de
      bytes (nunca `git checkout --`), con md5 verificado.
- [x] **Tarea 5** — Revisitar los dos docblocks que G-2 dejó diciendo que `event_store.aggregate_id` queda fuera
      del control, y el bullet de `.person-reference-policy` que lo llama *standing leak*.
- [x] **Tarea 6** — Pase adversarial hecho por tres capas independientes sin contexto previo, registrado abajo
      en *Review Findings* y en el hilo de la PR.
- [x] **Tarea 7** — Puertas con ejecución fresca y exit code impreso, en el resumen de la PR.
- [x] **Tarea 8** — Sin trabajo: el marcador de G-2 ya estaba en `done` en la base de esta rama (`ff898534`,
      PR #639) tanto en `sprint-status.yaml` como en su artefacto. La tarea se escribió contra
      `baseline_commit: 8bc9893a`, donde aún era cierto.

### Review Findings

> Pase adversarial de la Tarea 6, ejecutado 2026-08-04 sobre PR #640 (`origin/main...HEAD`, merge-base
> `ff898534`) con tres capas independientes y sin contexto previo: adversarial general, edge-case hunter y
> auditor de aceptación. Cada hallazgo re-verificado contra el árbol antes de asignar severidad — la capa
> adversarial declaró «el daño colateral es estructuralmente imposible» y la medición lo desmiente, que es el
> primer punto de esta lista.

- [x] [Review][Decision] **El `UPDATE` no está acotado al sujeto: borrar un UUID que no es una persona reescribe irreversiblemente el stream de otro agregado** — El `WHERE` es `aggregate_id = :id OR payload::text ILIKE '%id%' OR metadata::text ILIKE '%id%'`, sin discriminador de agregado (`api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php:66-68`), y se invoca **incondicionalmente**, no tras `$identity->identityErased` (`api/src/Iam/Identity/Application/FulfilIdentityErasure.php:164-167`). `EraseIdentitySubject::execute()` no lanza cuando el id no resuelve a ninguna identidad, y `UserEraseController::__invoke()` corre el borrado **antes** de decidir el 404 y con la transacción ya commiteada (`api/src/Iam/Identity/Infrastructure/Controller/UserEraseController.php:44`, cuyo propio comentario dice «Do not gate the erase on this branch»). Consecuencia medida: un `DELETE /api/v1/backoffice/users/<uuid-de-un-banco>` — o `identity:gdpr:erase-subject <uuid-de-un-banco> --force` — reescribe el `aggregate_id` de **todas** las filas del stream de ese banco y el `bankId` del `payload` de todos los eventos de `BankAccount` (`api/src/Backoffice/BankAccount/Domain/Event/BankAccountSnapshot.php:21,34`), commitea, y **después** devuelve 404. Es irreversible por diseño (D4 veta tabla de mapeo y derivación) y no hay control detective. La asimetría la introduce esta PR: los dos anonimizadores hermanos tienen `WHERE` estrecho (`actor_id = :id`; `resource_type = 'User' AND resource_id = :id`), así que un id no-persona no casa nunca. Efecto colateral en la misma línea: `erasedAnything()` se vuelve cierto solo por filas de `event_store` (`api/src/Iam/Identity/Application/FulfilIdentityErasureResult.php:40`), así que la CLI deja de imprimir «Nothing to erase» y muestra `success('Erased subject …')` con los siete contadores a cero. **Decisión de Sergio:** el fix tiene fork (gatear en `identityErased` vs. exigir que el sujeto fuera una persona vs. acotar por `aggregate_type`, que D12 veta por enumeración).
- [x] [Review][Patch] **El eje `metadata` no tiene ningún rojo: elimínalo de la sentencia y toda la suite sigue verde** — las dos filas sembradas llevan `metadata = '[]'`, así que la aserción que incluye `OR metadata::text ILIKE …` da 0 con y sin la cláusula. Es el modo de fallo que AC3 fue escrito para prohibir, sobre una columna que la enmienda del ADR declara cubierta. [`api/features/backoffice/users/erase.feature:23`, `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php:65,68`]
- [x] [Review][Patch] **`DbalEventStoreSubjectAnonymiser` no tiene test funcional: 0 % de cobertura y el quality gate del PR en ERROR** — SonarCloud PR 640: `new_coverage` 25,9 % contra umbral 80, y las 20 líneas cubribles del fichero están **todas** sin cubrir; el resto del código nuevo está al 100 %. Sus tres hermanos raw-SQL sí lo tienen. Quedan sin medir: idempotencia, ausencia de daño colateral, los dos `Uuid::ensure()` que son el argumento de seguridad entero, `payload = '[]'`, el eje `metadata` y la pertenencia a la transacción (el test unitario usa `InlineTransactionManager`, que no distingue dentro/fuera). [`api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php:49-74`]
- [x] [Review][Patch] **La cadena de borrado creció y ninguna de las seis enumeraciones en prosa lo dice — incluido el prompt de consentimiento del operador y el informe de la CLI** — el operador confirma «removes the user and its reset tokens, anonymises its audit trail, and drops its sessions, its organization membership and every invitation addressed to it» y con eso autoriza además una reescritura irreversible del log de negocio; y el informe de éxito imprime siete contadores omitiendo `anonymizedEventRows`. Es literalmente la deriva que esta misma PR da de alta en `deferred-work.md:7`. [`api/src/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommand.php:100-102,133-145,20-28,50-56`, `api/src/Iam/Identity/Application/FulfilIdentityErasure.php:26-31,76-81`, `api/src/Iam/Identity/Application/FulfilIdentityErasureResult.php:8-18`, `api/src/Iam/Identity/Infrastructure/Controller/UserEraseController.php:18-19`]
- [x] [Review][Patch] **Tres textos afirman ahora falsedades sobre el `event_store`** — «the stored `payload` is never rewritten» (dos veces, y es la razón por la que existe el seam `Upcaster`) y «no erasure path touches that table», este último en la cabecera de puntos ciegos de un registro que el repo trata como contrato de lo que un verde prueba. [`api/src/Shared/Event/Application/Upcaster.php:9`, `api/src/Shared/Event/Infrastructure/Serialization/PrimitivesDomainEventDeserializer.php:18`, `api/.persistent-transport-policy:42-43`]
- [x] [Review][Patch] **D12 ordena reescribir los docblocks «append-only» y no se hizo** — el propio ADR (`:359-360`) dice que pasan a decir «append-only, con un conjunto cerrado de mutaciones sancionadas», como ya hace `audit_log`; los cuatro siguen a secas. La PR mergeada dejaría el ADR pidiendo un cambio que no ocurrió. [`api/src/Shared/Event/Application/EventStore.php:10`, `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php:21`, `api/src/Shared/Event/Infrastructure/Persistence/EventStoreSchemaListener.php:14`, `docs/architecture/event-catalog.md:22`]
- [x] [Review][Patch] **`docs/architecture-api.md` describe el estado anterior en tres sitios** — `:263` «permanent, append-only» sin la mutación sancionada; `:264` enumera la cadena de `FulfilIdentityErasure` sin el eslabón nuevo; `:290-291` afirma que `event_store` «keeps the real `aggregate_id` regardless of routing», que es exactamente lo que esta PR falsifica. `CLAUDE.md` → «Keeping docs up to date» lo exige. [`docs/architecture-api.md:263,264,290-291`]
- [x] [Review][Patch] **El árbol se contradice sobre si `metadata` reserva un `actor`** — la enmienda dice que D9 no lo reserva, y es cierto para el cuerpo de D9 (`:226`), pero el bloque de esquema del **mismo ADR** (`:105`) y un docblock de producción siguen diciendo «actor (futuro)». Sustantivo: si `actor` va a llevar un id de persona, refuerza cubrir `metadata` — pero el argumento escrito para justificarlo niega su propia premisa. [`docs/adr/event-store-and-projections.md:105,322-323`, `api/src/Shared/Event/Infrastructure/Serialization/PrimitivesDomainEventSerializer.php:14`]
- [x] [Review][Patch] **La enmienda degrada «regla» a «hoy ya cubierta por un gate» y el gate no cubre esa regla** — `php.lint.person-reference` obliga a clasificar, declarar y dotar de fuente a una columna `Types::GUID`; no impide clavear un read model de persona por el identificador real ni detecta el re-claveado silencioso tras un rebuild, que es el riesgo que D12 describe. Un read model con entidad, clasificado y con su fuente, pasa el gate y sigue expuesto. [`docs/adr/event-store-and-projections.md:352-358`]
- [x] [Review][Patch] **AC2 no mira el contenido: sustituir el `regexp_replace` por `CAST('{}' AS JSONB)` pasa el escenario entero** — la aserción de supervivencia sólo lee `event_id`, `aggregate_version` y `event_name`, así que «el log sigue siendo reproducible como log» no tiene control sobre el payload que dice preservar. [`api/features/backoffice/users/erase.feature:84-85`]
- [x] [Review][Patch] **IDs de historia y de ticket en comentarios de `api/src`** — `SI-23` y `#376`; `CLAUDE.md` → «Code comments» los prohíbe por nombre, y el segundo es la única referencia `#NNN` a una issue en todo `api/src`. [`api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php:24`, `api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php:37`]
- [x] [Review][Patch] **Markdown roto en el párrafo enmendado del ADR** — marcadores `**` impares: la edición abrió una negrita en «Regla, y hoy ya cubierta…**» y dejó el `**` de cierre de la frase original, que ahora abre una negrita que nunca cierra. El párrafo se renderiza mal desde la mitad. [`docs/adr/event-store-and-projections.md:352-359`]
- [x] [Review][Patch] **El docblock del reconciliador dice «both of its axes» y la sentencia cubre tres columnas** [`api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php:23-24`]
- [x] [Review][Patch] **AC2 nombra `sequence` y el testigo no lo afirma** — el comentario dice «with the sequence, the event name and the stream version» y el `SELECT` no incluye `sequence`. Inocuo hoy (`GENERATED ALWAYS AS IDENTITY`), pero es afirmar más de lo que se mide. [`api/features/backoffice/users/erase.feature:83-85`]
- [x] [Review][Patch] **El comentario del canario 17→18 le atribuye un valor probatorio que la propia historia retiró** — «ONE round trip for BOTH of its axes — which is the whole point» invita a leer el `+1` como evidencia de cobertura, y `+1` es también lo que cuesta cubrir un eje o ninguno. Lo que prueba los dos ejes son las dos aserciones `0 records`. [`api/features/backoffice/users/erase.feature:90-92`]
- [x] [Review][Patch] **`&` no es back-reference en Postgres; `\&` sí** — el argumento de seguridad (validar el pseudónimo como UUID) sigue en pie; la justificación escrita, no. [`api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php:31`]
- [x] [Review][Patch] **Artefacto de la historia incoherente consigo mismo** — `Dev Agent Record` dice «Implementación pendiente» con cinco tareas en `[x]` y `Status: review`; la Tarea 8 describe un trabajo ya resuelto en el merge-base (`sprint-status.yaml` y el artefacto de G-2 ya estaban en `done` en `ff898534`; el `in-progress` sólo era cierto en el `baseline_commit: 8bc9893a`, desfasado); y el banner de este artefacto (`:13`) y `sprint-status.yaml:318-319` siguen repitiendo «en las **claves de `payload`**», la frase que el ADR enmienda en esta misma PR por autocontradictoria. [`_bmad-output/implementation-artifacts/g-5-ids-de-persona-fuera-del-event-store.md:13,118-119,189`, `_bmad-output/implementation-artifacts/sprint-status.yaml:318-319`]
- [x] [Review][Patch] **Salto de línea partido en el bloque reescrito del registro** — `` `audit_log.metadata` `` queda colgando al final de línea contra el rewrap del resto del bloque. [`api/.person-reference-policy:66`]
- [x] [Review][Defer] **`anonymise(string $subjectId, string $pseudonym)`: dos `string` consecutivos en una mutación irreversible, y permutarlos es un no-op silencioso** [`api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php:61`] — diferido, mejora de diseño. El hermano del eje de recurso recibe un `AuditResource` tipado precisamente para que los argumentos no se puedan permutar. Con un solo llamador y el espía fijando el orden, tiparlo hoy es YAGNI; el argumento a favor crece en cuanto haya un segundo llamador.
- [x] [Review][Defer] **El «conjunto cerrado de mutaciones sancionadas» de D12 es prosa sin gate** [`docs/adr/event-store-and-projections.md:294`] — diferido, preexistente y simétrico con `audit_log`. `git grep "UPDATE event_store"` es el único control y no está automatizado; la palabra «cerrado» promete una garantía que nada cierra.

## Dev Notes

### Anti-patrones concretos

- **No enumeres eventos ni claves.** El inventario ya se equivocó dos veces; el `WHERE` por valor alcanza todo
  evento presente y futuro sin que ningún productor recuerde nada.
- **No uses pseudónimos distintos por fila**: rompe `(tenant_id, aggregate_id, aggregate_version)`.
- **No derives el pseudónimo del id real** ni crees tabla de mapeo — D4 veta ambas, con esas palabras.
- **No toques `metadata` de `event_store`** sin medir antes qué guarda: es otra columna, otro eje.

### References

- [`docs/adr/event-store-and-projections.md`](../../docs/adr/event-store-and-projections.md) — D12
- [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) — D4, y el precedente del conjunto cerrado de mutaciones
- [`g-2-ids-de-persona-fuera-de-audit-log-metadata.md`](g-2-ids-de-persona-fuera-de-audit-log-metadata.md) — el hermano: forma del testigo, trampas medidas y el pase adversarial

## Consulta a tres voces (ChatGPT → Winston + Amelia), 2026-08-04

Sergio pidió que el externo ayudara a decidir y que Winston y Amelia criticaran **su** respuesta — en ese orden,
que corrige el fallo de secuencia de G-2 (allí el prompt describía un árbol que la implementación cambió
mientras el externo respondía). Prompt en `tmp/bmad-md/consult-g5-event-store-controls-20260804-124634.md`.

**Dos hechos en disputa, medidos por mí antes de aceptar ningún veredicto — y Winston tenía razón en los dos:**

1. **D9 NO reserva `metadata` para «actor».** Reserva `correlation_id` y `causation_id`, que identifican un
   evento y una petición. ChatGPT y Amelia repitieron la premisa falsa; **mi propio docblock también**. Corregido.
2. **El `UNIQUE` de stream no impone nada.** `tenant_id` se escribe siempre `NULL` y Postgres usa
   `NULLS DISTINCT`; está medido contra `pg_indexes` en `deferred-work.md`. Mi docblock decía *«the stream UNIQUE
   is why»* — repetía una garantía que el repo ya había falsificado en su propio registro. Corregido: lo que
   sostiene el pseudónimo único es que una persona no se parta en varias identidades anónimas.

**D1 — reescribir `metadata`: 3 de 3, mantener.** Con el argumento de Winston, no el de ChatGPT: la garantía está
definida **sobre la fila**, no sobre una lista de columnas; cubrir la tercera *retira una excepción*. Enmendado
en D12, junto con su frase «en las **claves** de `payload`», que se contradecía con la decisión por valor doce
líneas después.

**D2 — 3 de 3 rechazan el registro declarativo.** Y **Winston y Amelia rechazan el hook suite-wide de ChatGPT**,
por una razón estructural que él no podía ver: el hook de G-2 funciona porque el contenido correcto de
`audit_log.metadata` es *cero ids de persona*; `event_store` **niega ese invariante por diseño**. Portado,
dispararía en casi todo escenario que haga login; y el anti-join tampoco vale, porque tras un borrado correcto el
id es un pseudónimo indistinguible por forma.

**El hook scoped (2′) de Winston se autorizó y NO se construyó, por medición posterior.** Los 11 borrados de la
suite están todos en `erase.feature`; cinco lo heredarían, y **ninguno siembra eventos**, así que pasaría en
vacío justo donde añadiría cobertura — la misma vacuidad que #639 acaba de corregir en el hook hermano.
*Trigger:* el primer escenario que borre a alguien **con eventos suyos**.

**D3 — no en G-5** (Winston y Amelia contra el sí de ChatGPT). El argumento decisivo es de Amelia: sin flag
`resource_erased`, el reconciliador reportaría **cada borrado correcto** como divergencia. Registrado en
`deferred-work.md` con su trigger.

**D4 — gana la medición de Amelia** sobre las propuestas de ChatGPT y Winston: hay **un solo** `Projector` y la
regla ya está **cubierta transitivamente** por `php.lint.person-reference` para todo read model con entidad. El
hueco real es estrecho y ya está declarado. Dos líneas en el ADR, cero código.

**Lo que Amelia encontró de mi trabajo, y era correcto:** el testigo no existía y el `UPDATE` corría **sobre cero
filas**, así que AC1/AC2 no estaban probados y AC3 era infalsificable. Yo había presentado el canario `17 → 18`
como confirmación de que una sentencia cubre los dos ejes — **+1 es también lo que cuesta un `UPDATE` que no casa
nada**. Cerrado con el testigo sembrado y un rojo por eje.

## Dev Agent Record

### Agent Model Used

claude-opus-5[1m]

### Completion Notes List

- Artefacto de contexto, implementación, testigo de aceptación y test funcional del adaptador.
- El pase adversarial encontró que el `UPDATE` no estaba acotado al sujeto: por coincidencia de valor alcanza
  cualquier agregado, así que un UUID de banco tecleado por error reescribía su stream entero de forma
  irreversible, y detrás de un 404. Resuelto con la guarda en `FulfilIdentityErasure` tras consultar a
  ChatGPT, Winston y Amelia; el hecho histórico que separaba las dos propuestas (¿existió alguna vez el
  residuo legacy que la guarda dejaría sin limpiar?) se midió: diez ADR declaran que no hay producción, y el
  `event_store` de la única BD viva no alcanza la ventana #494→#529.
