---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics', 'step-03-create-stories', 'step-04-final-validation']
inputDocuments:
  - docs/adr/images-vs-documents-conservation-contract.md
  - docs/adr/media-vs-documents-upload-boundary.md
  - _bmad-output/implementation-artifacts/deferred-work.md
  - docs/adr/regulatory-audit-trail.md
  - docs/adr/audit-activity-log.md
  - api/.audit-resource-types
  - api/.person-reference-policy
  - docs/rules/security.md
  - docs/rules/database.md
  - PRODUCTION_SECURITY_CHECKLIST.md
  - docs/project-context.md
scope: 'Primera rebanada del módulo Shared/Images (D6 del ADR de contrato de conservación) en un único épica: sin cablear ningún consumidor real (Bank.logoImageId / User.avatarImageId no existen hoy) y sin auditar la ruta de lectura — ambas decisiones confirmadas con Sergio, quedan para una épica de consumidor aparte. PRs grandes (~30 ficheros/story), pocas stories — preferencia explícita de Sergio, se agrupa trabajo relacionado en vez de fragmentar por guarantee-axis (patrón usado en gdpr-hardening). Requisitos revisados adversarialmente (2026-08-23): 30 hallazgos triados, uno rechazado con cita del ADR (Image sí se persiste), el resto incorporado — ver Additional Requirements.'
---

# ERPify — Módulo Shared/Images (subida de imágenes) — Desglose de épica

## Overview

Este documento desglosa en épica(s) e historias implementables la **primera rebanada del módulo
`Shared/Images`** definida en
[`docs/adr/images-vs-documents-conservation-contract.md`](../../docs/adr/images-vs-documents-conservation-contract.md)
(ADR *accepted, design, no code yet*): el seam compartido de subida y servido de **representaciones
fungibles** (logos, avatares, imágenes de producto, miniaturas) — distinto del contexto `Documents`
(evidencia; issue #268), que queda fuera de esta épica.

Estado del baseline (brownfield): la API **no tiene superficie de subida**. La implementación anterior
(`Shared/Media`, `Shared/Storage`) se retiró entera en `08f8199` porque nadie la consumía — no por
defectuosa. El ADR actual sustituye por completo el criterio de frontera de aquella implementación
(contrato de conservación, no formato/tamaño) y borra por construcción varias piezas que aquella daba
por necesarias (dedup, blob compartido, refcount, GC).

**Alcance confirmado con Sergio (esta sesión, incluida una revisión adversarial del propio
breakdown):**

1. **Solo el módulo**, fiel al D6 del ADR — sin cablear `Bank.logoImageId` ni `User.avatarImageId`
   (ninguno de los dos existe en el árbol hoy). Un primer consumidor real es una épica separada. Esto
   **no** significa que `Image` quede sin persistir: el ADR fija explícitamente en su frontmatter *"The
   first slice does add schema — the `Image` table"* y en D6 lista *"the `Image` aggregate it
   produces"* como entregable de esta rebanada. `Shared/Images` posee identidad, estado y ciclo de vida
   **mecánico** de su propio agregado desde esta épica; lo que no existe todavía es un consumidor
   externo que referencie ese `ImageId`. Ver la tabla de responsabilidades en Additional Requirements.
2. **Sin auditoría en esta épica** — la ruta de lectura escribe cero filas en `audit_log`. La
   clasificación person/non-person de `api/.audit-resource-types` se decide **por consumidor** (p. ej.
   `BankLogo` vs. `UserAvatar`, tipos distintos aunque compartan tabla) el día que exista un primer
   consumidor real — nunca de forma genérica sobre `Image`, porque un único `resource_type` no puede
   ser a la vez persona-denotante (avatar) y no-persona (logo). El bloqueo original del ADR (issue
   #555, crosswalk de `resource_id`) **ya cerró e implementó** — no es lo que fuerza esta decisión, la
   fuerza la ambigüedad de tipo.
3. **Autorización de lectura (decidida en la revisión adversarial)**: la ruta canonical-only exige
   sesión autenticada (`IS_AUTHENTICATED_FULLY`) como frontera **provisional y explícitamente acotada a
   esta slice** — no hay voter de ownership porque no existe todavía relación de consumidor sobre la
   que votar, y `ImageId` opaco **no** se trata como mecanismo de autorización (conocerlo nunca basta
   para leer sin autenticar). El primer consumidor real introduce su propia política de autorización
   específica si la necesita. Detalle completo en Additional Requirements.
4. **Storage técnico**: adaptador Flysystem local (filesystem) tras el puerto `ImageStorage` — decisión
   de bajo riesgo y reversible por diseño (el propio ADR la enmarca como *"infrastructure adapter...
   belongs to deployment, not to this contract"*). Precedente medido en la superficie retirada
   (`FlysystemStorage`, delete() idempotente reutilizable). Swap a S3-compatible es un adaptador nuevo
   detrás del mismo puerto cuando haga falta — no bloquea esta épica.
5. **PRs grandes, pocas stories** — objetivo ~30 ficheros modificados por story/PR, agrupando trabajo
   relacionado en vez de fragmentar por guarantee-axis. Estructura candidata para step-02: (1) dominio +
   pipeline de procesamiento, (2) storage + frontera de persistencia/outbox + seguridad de transporte,
   (3) ruta de lectura canonical-only + caching HTTP.

## Adversarial pass

Dos lecturas hostiles sobre el breakdown completo, aportadas por Sergio desde fuera de esta sesión —
**no autocertificación**: la primera (2026-08-23) sobre el requirements inventory de step-01; la
segunda (2026-08-24) sobre las tres historias ya redactadas de step-03. Además, BMAD Advanced
Elicitation (autoadministrada por esta sesión: Boundary & Edge Case Sweep, Security Audit Personas,
Cascading Failure Simulation, Failure Mode Analysis) sobre el mismo contenido, con al menos un hallazgo
corregido por Sergio antes de aceptarlo (ver GRAVE-2). Un tercer control, no adversarial pero real:
step-04 (validación final) encontró dos FRs sin cobertura de AC que ninguna de las dos lecturas había
señalado.

**GRAVE-1 — contradicción interna entre NFR4 y NFR7.** El requirements inventory decía en NFR4 que el
borrado de bytes "no es un efecto lateral síncrono... se publica tras commit" (outbox, ya decidido) y
simultáneamente en NFR7 que "síncrono vs. outbox" era una decisión abierta para la historia del puerto —
dos afirmaciones incompatibles sobre el mismo mecanismo. Una story escrita contra NFR7 literal podría
haber reintroducido un borrado síncrono que NFR4 ya prohibía. Corregido: NFR7 solo deja abierta la
semántica de `delete()` como operación (idempotencia, comportamiento ante fallo); el mecanismo outbox
queda fijado en NFR4, sin margen de reinterpretación.

**GRAVE-2 — orden de borrado entre bytes de storage y fila `Image` sin especificar.** Ninguna AC decía
en qué orden desaparecen la fila `Image` y los bytes cuando se procesa la señal de lifecycle. Sin
fijarlo, una implementación podía borrar la fila primero (dejando bytes huérfanos sin ninguna referencia
que los reintente, exactamente el bookkeeping que NFR3 prohíbe construir) o los bytes primero (dejando
una fila viva y retryable durante la ventana). La propuesta inicial de esta sesión no distinguía las dos
direcciones; **Sergio la corrigió** con el argumento de retryability (storage→fila, nunca al revés,
porque un fallo a medio camino en esa dirección deja siempre algo con lo que reintentar). Incorporado
literalmente en la Story 1.2.

**GRAVE-3 (encontrado en step-04, no en las lecturas externas) — FR3 y FR7 sin cobertura de AC.** El
inventario de requisitos declaraba "no promoción entre contratos" (FR3) y "`ImageProcessor` como seam
reutilizable" (FR7), y el mapa de cobertura los daba por cubiertos por la Épica 1 — pero ninguna
historia tenía una AC que los verificara. Un mapa de cobertura que apunta a una épica sin que ninguna
story lo pruebe es cobertura de nombre, no de hecho. Cerrado con dos ACs nuevas en la Story 1.1.

**MEDIA-1 — el contrato de `ImageStorage` se dejaba más abierto de lo que el ADR permite.** La
formulación original delegaba "síncrono vs. outbox, idempotencia, comportamiento ante fallo" enteros a
la historia del puerto, cuando D6 del ADR ya fija la mitad (escritura aceptada⇒recuperable, borrado
completado⇒no recuperable). Tratar como abierto lo que ya está cerrado invita a que una story lo
renegocie. Corregido en NFR7.

**MEDIA-2 — ambigüedad sobre si `Image` se persiste.** Una redacción confusa ("la primera historia que
persiste un ImageId... solo internamente al módulo") sonaba a que `Image` podía no tener persistencia
propia. El ADR es inequívoco (frontmatter: *"the first slice does add schema — the `Image` table"*; D6:
*"the `Image` aggregate it produces"* como entregable). Corregido con la tabla de responsabilidades
(Shared/Images posee identidad/estado/storage; el consumidor posee significado/ownership/erasure).

**MEDIA-3 — defensa anti-polyglot implícita, no verificada.** El pipeline siempre re-encoda (D1), lo que
de hecho neutraliza un polyglot (cabecera de imagen válida + payload malicioso anexado), pero ninguna AC
lo afirmaba como propiedad — dependía de que la implementación lo hiciera "por casualidad". Añadida AC
explícita: solo los bytes canónicos re-encodados alcanzan `ImageStorage` o la respuesta HTTP.

**MEDIA-4 — orden auth-vs-existencia sin fijar.** Sin especificar, un no autenticado podía recibir
códigos distintos según si el `ImageId` solicitado existía o no, filtrando esa información sin
necesidad de autenticarse. Fijado: auth → validación de formato → lookup → 404, siempre en ese orden.

**MEDIA-5 — respuesta truncada si el storage falla a mitad de stream.** Ninguna AC impedía comprometer
`200` + `Content-Length` antes de confirmar que la lectura completa era correcta — un fallo de storage
a mitad de la respuesta habría dejado al cliente con un fichero truncado sin ningún error visible.
Añadida AC: lectura verificada antes de comprometer cabeceras.

**RECHAZADO-1 — "`Image` no debería persistirse como agregado propio; el consumidor persiste
`ImageId`".** Propuesto en la segunda lectura como P0. Contradice el ADR literalmente en dos sitios (ver
MEDIA-2); rechazado citando el texto exacto del ADR en vez de deferir a la autoridad del revisor.

**RECHAZADO-2 — rescatar `ContentHashUrlGenerator`.** Se descartó por completo (no solo renombrado como
`ContentAddressedHttpCache`) — el riesgo de que el hash reabra semántica de identidad (`/Image/{hash}`)
supera el ahorro de líneas frente al invariante 2 del ADR.

**RESIDUAL-1 — `ImageId` (UUIDv7, convención del repo) + lectura autenticada-cualquiera habilita una
enumeración de rango temporal más barata que sobre un UUIDv4 puro.** No cambia la decisión de
autenticación de esta épica; documentado como riesgo aceptado, candidato a rate-limiting en la épica del
consumidor. `ImageId` no se considera nunca un mecanismo de autorización ni un secreto.

**RESIDUAL-2 — un mensaje `ImageDeleted` perdido deja bytes y fila vivos indefinidamente**, sin
monitorización en esta rebanada — coherente con "sin GC" (NFR3), pero desde el ángulo de pérdida de
mensaje, no de bookkeeping de huérfanos.

**RESIDUAL-3 — sin refcounting (NFR3 lo prohíbe), un futuro consumidor que duplique una referencia a
`ImageId` (bug del lado consumidor, viola NFR4) no tiene ningún safety net en esta capa** — un
`delete()` legítimo de un poseedor borra bytes que el otro todavía cree tener. No se arregla aquí;
documentado para que la épica del consumidor no lo redescubra por accidente.

## Requirements Inventory

### Functional Requirements

FR1 (D1): El pipeline decide qué transformar por el **contrato de conservación** sobre el byte
(fungible vs. evidencia), nunca por MIME o tamaño — toda imagen que entra por `Images` puede
decodificarse, normalizarse, redimensionarse y reencodarse libremente.

FR2 (D2): `UploadImage` es el **único** entry point de subida en esta rebanada, y **no acepta un
parámetro de contrato de conservación**: por construcción siempre produce una representación fungible —
no existe forma de que un caller de `Images` pida `Evidence`. **Regla interina**: mientras no exista
`Documents` (#268), cualquier necesidad de conservar evidencia se queda sin servir. No es un caso que el
pipeline "detecte y rechace" — D1 ya establece que MIME/tamaño no distinguen contrato — es simplemente
un caso que esta API no expone.

FR3 (D3): **No hay promoción entre contratos.** Una imagen que entró como fungible no puede convertirse
después en evidencia — ni por reconstrucción de historia, ni por conservar un "original best-effort".
Volver a aportar el original como evidencia es la vía soportada.

FR4 (D4): El módulo gestiona **representaciones canónicas**, no preservación — regla técnica y estable,
deliberadamente no legal. El nombre se mantiene `Images` mientras siga siendo honesto (el disparador de
renombrar a "renditions" es el primer consumo real desde otro contexto, no algo previsto).

FR5 (D5): El ciclo de vida de la imagen pertenece **siempre** al agregado consumidor a través de un
`ImageId` opaco, nunca a storage ni al derivado. El módulo expone un `delete(ImageId)` **fiable** — su
contrato exacto en NFR7.

FR6 (D6 — primera rebanada): `UploadImage` (caso de uso), agregado `Image` — **persistido con tabla y
repositorio propios en `Shared/Images`** (state-oriented, sin event sourcing: el histórico relevante
vive en el agregado propietario y en `audit_log`, no en `Image`) —, procesador de imagen (`ImageProcessor`,
ver FR7), pipeline determinista (decode → validate → normalize → re-encode → digest), storage
direccionado por `ImageId` opaco, puerto `ImageStorage`, y una ruta de lectura canonical-only que exige
sesión autenticada como frontera de acceso provisional (ver Additional Requirements). Que ningún
consumidor externo (`Bank`, `User`) referencie todavía `ImageId` no significa que `Image` no se
persista: el módulo posee identidad, estado y ciclo de vida mecánico de su propio agregado; el futuro
consumidor poseerá el significado, el ownership y la decisión de erasure sobre la referencia.

FR7 (D6 — seam reutilizable): `ImageProcessor` (decode → validate → normalize → re-encode → digest) es
el seam reutilizable por futuros productores que no son `UploadImage` (p. ej. una derivada de
`Documents` renderizando una portada). En esta épica `UploadImage` es el **único** entry point de
`Application`; `ImageProcessor` queda preparado para un segundo productor sin modificar ningún
invariante, pero no se crea ninguna abstracción adicional (`ImagePipelineProducer`, `ImageUploadSource`,
`ImageCreationStrategy`) solo para demostrar extensibilidad futura.

### NonFunctional Requirements

NFR1 (Invariante 1): Storage guarda bytes; la semántica pertenece siempre al agregado. Nada en
`Shared/` decide qué significa un byte, quién lo posee o cuándo muere.

NFR2 (Invariante 2): El digest canónico es un **atributo**, nunca una identidad ni clave de unicidad —
dos subidas idénticas producen dos `Image` distintos que comparten hash por coincidencia, no por
diseño. No se reintroduce ningún índice único sobre el hash. Test de regresión obligatorio: mismos bytes
de entrada → `ImageId` distintos → mismo digest → dos objetos de storage independientes. El digest se
calcula **después** del re-encoding, sobre los bytes canónicos — `digest = SHA-256(bytes canónicos)`,
nunca `SHA-256(bytes subidos)` ni una combinación con MIME/dimensiones/`ImageId`.

NFR3 (Invariante 3): La primera iteración **no** introduce deduplicación ni bookkeeping global — sin
blob compartido, sin refcount, sin GC. Explícitamente no se revive: `MediaConcurrentInsertResolver` +
su clúster de concurrencia (~250 l), `MediaRegistrar`, `StoredObjectOrphanCleaner` + inspectores +
`CompositeStoredObjectAccess`, ni el índice único `media_content_hash_uniq`.

NFR4 (Invariante 4): Un `ImageId` es propiedad de **exactamente una** instancia de **agregado
consumidor** (no de `Image` mismo, que posee su `ImageId` como su propia identidad de forma trivial) y
nunca se copia a otra — duplicar una entidad consumidora materializa su propia imagen, no comparte el
id. Esta rebanada no tiene consumidor, así que no puede demostrar ni violar esta garantía todavía; queda
como contrato para la épica que cablee el primero. `ImageId` lo genera
**siempre el módulo**, nunca el caller: `UploadImage` lo genera internamente antes de procesar; ninguna
firma de la API acepta un `ImageId` de entrada para una subida nueva — evita que un caller sobrescriba
una imagen existente, elija ids, infiera ownership o reutilice ids. El borrado de bytes **no** es un
efecto lateral síncrono dentro de la transacción del propietario — se publica tras commit y se consume
después (mismo patrón outbox que el resto del repo: `wrapInTransaction(save+publish)`, gate
`make php.lint.event-bus`). El evento de lifecycle (`ImageDeleted` o equivalente) transporta **solo**
`ImageId` — nunca `storageKey`, `path`, `filename`, `digest` ni `absolutePath`; el consumidor reconstruye
la dirección de storage desde `ImageId`. **Distinción que la story 2 debe mantener explícita**: el
módulo puede emitir o participar en una señal de lifecycle basada en `ImageId` (mecánica), pero **no
decide cuándo un consumidor ha dejado de poseer una imagen** (semántica de negocio) — esa decisión la
toma siempre el agregado propietario. `ImageRepository::delete()` → `ImageStorage::delete()` no
representa nunca el borrado de la entidad consumidora; representa únicamente la reacción mecánica del
módulo a una decisión que el consumidor ya tomó y confirmó (commit). Un `ImageId` que referencia la imagen de una persona física es
en sí mismo dato personal: el campo se marca `#[PersonalData]` para que `PiiDiffSealer` lo selle, con
ámbito del **sujeto**, no de la imagen — y `Shared/Images` **nunca** determina por sí mismo si un
`ImageId` es dato personal; es el agregado consumidor quien declara la referencia como tal y posee su
erasure.

NFR5 (Invariante 5): El contrato de conservación — nunca formato, nunca tamaño — es la frontera entre
`Images` y el futuro `Documents`.

NFR6 (Invariante 6): Ni un tipo de transporte HTTP ni una localización suministrada por el caller
cruzan la capa `Application/` del módulo — dos ejes, verificados con tests **negativos** distintos, no
solo análisis estático: **tipo** (`Application` no puede recibir `UploadedFile`, `File`, `SplFileInfo`,
`SplFileObject` ni sucesores) y **valor** (no puede recibir un path/filename/URL/storage-key elegido por
el caller — una firma como `upload(ImageId $id, string $path)` cumpliría el eje tipo y violaría
completamente el eje valor). `deptrac` no basta para expresarlo (folds los `Shared/` anidados en tres
capas); la historia del pipeline lleva un scan propio del árbol del módulo **más** un test de regresión
que falle en rojo si el scan deja de matchear tras un rename, más un test a nivel de tipo/compilación
donde sea práctico.

NFR7 (Contrato del puerto `ImageStorage` — ya fijado en D6, no queda abierto): una escritura aceptada
(`store()` retorna éxito) es **subsecuentemente recuperable** por el mismo `ImageId`; un borrado
completado (`delete()` retorna éxito) **no** lo es. El puerto es infraestructura pura y no conoce
transacciones ni Messenger — la orquestación outbox (publicar tras commit, consumir después) vive en el
propietario, no en `ImageStorage`, y **ya está decidida** por NFR4 (nunca síncrono dentro de la
transacción del propietario); no es una opción abierta para la historia del puerto. Lo que sí decide esa
historia es la **semántica del propio `delete()` como operación**: idempotencia y comportamiento ante
fallo de infraestructura. Semántica recomendada: éxito si el objeto está ausente (idempotente respecto a
"ya no existe"), fallo si la existencia no puede establecerse o el borrado no puede completarse — nunca
convertir un fallo de infraestructura en éxito silencioso, porque el consumidor outbox necesita saber si
puede reintentar.

NFR8 (Límites de recursos del decoder — D1 no los sustituye): `size limit ≠ conservation
classification` — que D1 rechace clasificar por tamaño no implica ausencia de límites de seguridad. La
historia del pipeline establece explícitamente qué controla el decoder y qué controla la aplicación
frente a: tamaño máximo de request, píxeles decodificados máximos, dimensiones máximas, número de
frames, timeouts — sin que ningún límite se confunda con la clasificación fungible/evidencia. No se
exige implementar cada control en esta rebanada, pero sí declarar cuáles quedan cubiertos y cuáles no.

### Additional Requirements

- **Invariante central de la épica** — marco de responsabilidades que resuelve casi todas las
  ambigüedades de alcance de este documento:

  | Responsabilidad                    | Shared/Images            | Consumidor                |
  |-------------------------------------|:-------------------------:|:--------------------------:|
  | Decode/normalize/encode             | ✅                         |                            |
  | Digest canónico                     | ✅                         |                            |
  | Generar `ImageId`                   | ✅                         |                            |
  | Guardar bytes                       | ✅                         |                            |
  | Borrar bytes                        | ✅ (vía señal de lifecycle) |                            |
  | Significado de la imagen            |                            | ✅                          |
  | Ownership                           |                            | ✅                          |
  | Semántica de autorización fina      |                            | ✅                          |
  | Clasificación persona/no-persona    |                            | ✅                          |
  | Propiedad de `#[PersonalData]`      |                            | ✅                          |
  | Decisión de erasure                 |                            | ✅                          |
  | Semántica de auditoría              |                            | ✅                          |
  | Deduplicación                       | ❌                         | ❌                          |
  | Refcount / GC                       | ❌                         | ❌ (decisión futura explícita) |

- **Contrato de lectura y autorización** (decisión de la revisión adversarial de esta sesión, sustituye
  el "voter/ruta consciente" genérico del ADR por una política concreta): la ruta canonical-only exige
  `IS_AUTHENTICATED_FULLY`. No hay voter de ownership porque no existe relación de consumidor sobre la
  que votar. Es una frontera **provisional y explícitamente acotada a esta slice**, no una regla de
  autorización permanente — la semántica pretendida NO es "cualquier usuario de backoffice puede leer
  cualquier imagen para siempre", sino "mientras no exista un consumidor con política propia, la
  autenticación es la frontera completa". `ImageId` opaco no se trata como mecanismo de autorización.
  El primer consumidor real introduce su propia política específica si la necesita; el agregado genérico
  `Image` nunca infiere person/non-person ni ownership desde `ImageId`. Acceptance criteria obligatorios:
    1. Petición **no** autenticada sobre un `ImageId` existente → denegada por la frontera de
       autenticación de la API.
    2. Petición autenticada → puede devolver la representación canónica.
    3. Test de regresión: un `ImageId` por sí solo nunca concede acceso no autenticado a los bytes.

  La ruta demuestra que los bytes canónicos son recuperables a través de la frontera de `Images` sin
  exponer semántica de storage — es una **prueba de infraestructura**, no una feature de producto lista
  para exponerse como API pública de imágenes; no establece ownership ni autorización semántica de
  ningún consumidor.
- **Restricción dura, desde el día uno**: diseñar contra el resolver de argumentos de Symfony 8.1
  (`mergeParamsAndFiles`) — fue ese cambio el que destapó una lectura arbitraria de ficheros en la
  implementación retirada. El guard ya existe:
  `Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizer` (ancla en `SplFileInfo`, no en
  `UploadedFile`, porque el vector es la *constructibilidad* desde un path arbitrario). **Acceptance
  criterion verificable, no nota**: dado un upload HTTP que use `#[MapUploadedFile]`, cuando Symfony
  resuelve el argumento, un path de filesystem arbitrario no debe poder materializarse como origen de la
  subida. Se exigen **dos tests distintos**, ninguno sustituye al otro: (a) test del guard en aislado
  (reconoce y rechaza objetos construibles desde path), y (b) test de integración de la **vía real de
  entrada** — la resolución efectiva de `#[MapUploadedFile]` en Symfony 8.1 no permite esquivarlo. "El
  guard existe" no es evidencia de seguridad sin (b) — la superficie retirada tomaba sus subidas
  exactamente por ese atributo, que es un resolver distinto al que el guard fue pensado para cubrir
  originalmente.
- **MIME allowlist es frontera de seguridad del decoder, no frontera de conservación** (aclaración de D1
  que debe quedar explícita para que una futura implementación no la reintroduzca mezclada): decide qué
  puede interpretar el decoder, nunca si algo es fungible/evidencia, si se transforma, o el ownership.
- **Decoder threat model**: la historia de pipeline declara explícitamente qué controla el decoder y qué
  controla la aplicación frente a decompression bombs, dimensiones/memoria/número de frames
  desproporcionados, parsers malformados y timeouts — de forma que una imagen controlada por un
  atacante no derive en DoS trivial por memoria/CPU no acotada. Ver NFR8.
- **Frontera transaccional dentro del propio módulo** (sin consumidor externo, pero `Image` sí se
  persiste — ver invariante central): orden y semántica de fallo entre storage y persistencia:
  (1) generar `ImageId` → (2) procesar bytes → (3) escribir en storage → (4) persistir la fila `Image`
  en su propia transacción → (5) si (4) falla tras un (3) exitoso, el objeto de storage queda sin fila
  `Image` que lo referencie. **Se acepta explícitamente este huérfano en la primera rebanada**,
  consistente con NFR3 (sin GC global), y se documenta como deuda consciente, no como omisión — no se
  introduce ningún mecanismo de compensación en esta épica. **Acceptance criterion explícito**: una
  escritura de storage exitosa NO se trata como acoplada transaccionalmente a la persistencia de
  `Image` en esta rebanada — ningún intento de rollback de storage, compensación ni GC "arregla" el
  huérfano; lo contrario sería reintroducir exactamente el bookkeeping global que NFR3 prohíbe.
- **Gotcha heredado de la superficie retirada**: un BLOB de Doctrine puede devolverse con el puntero ya
  en EOF; `stream_get_contents` desde EOF da `''` (no `false`) → corrupción servible como cuerpo de
  respuesta. Leer siempre desde offset 0.
- **Modelado mutable/inmutable de `Image`** y la URL de variantes `/{imageId}/{hash}/{variant}` quedan
  deliberadamente **fuera de esta épica**. Decisión de la revisión adversarial: **la ruta de lectura de
  esta épica es canonical-only, sin variantes** — `GET /images/{imageId}` devuelve exclusivamente la
  representación canónica. El cacheo inmutable de variantes, y con él la pregunta mutable/inmutable, se
  decide cuando exista un consumidor real que necesite thumbnails/resize/cache inmutable por variante.
- **Inventario de rescate** (`08f8199`, medido fichero a fichero contra las invariantes del ADR —
  recuperación vía `git show 08f8199^:<ruta>`). Regla añadida por la revisión adversarial: **rescatar
  comportamiento, no nombres ni modelos mentales** — un fichero rescatado cuyo naming expresa la
  arquitectura antigua se renombra al integrarlo, para no arrastrar una semántica que esta épica
  descarta:
    - *Se copia tal cual (renombrando)*: `Shared/Http/Infrastructure/ContentAddressedHttpCache.php`
      (43 l) + test (71 l) — política ETag / `If-None-Match` (fuerte, débil, sin comillas, `*`),
      agnóstica del hash; pieza exacta para el 304 del endpoint canonical-only. Se renombra al
      integrarla (p. ej. `HttpCacheValidator`) porque esta épica no adopta content-addressing — el ETag
      se deriva del **digest** (atributo), nunca del `ImageId` ni usado como storage key.
      `ContentHashUrlGenerator` **no se rescata en esta épica** — el riesgo de que el hash reabra
      semántica de identidad (`/Image/{hash}`) supera el ahorro de líneas; si hiciera falta una utilidad
      de URL más adelante, se escribe de cero contra el contrato ya decidido (`ImageId` = identidad,
      digest = atributo).
    - *Sirve de plantilla, no de copia*: `Shared/Media/Infrastructure/Image/InterventionImageNormalizer.php`
      (77 l) — pipeline D6 literal (allowlist MIME → `decodeBinary` → `scaleDown` → `encode` → sha256).
      `intervention/image` ya no está en el árbol, y D1 obliga a **argumentar** su endurecimiento (el
      decoder es él mismo superficie de ataque — ver decoder threat model arriba), no a asumirlo.
    - *Aprovechable a medias*: `FlysystemStorage.php` (el `delete()` idempotente sí; la clave derivada
      del hash no — invariante 2); `StoredObjectGetController.php` (la puerta doble del 304 sí; su
      ausencia de voter y de auditoría de lectura no — esta épica la sustituye por `IS_AUTHENTICATED_FULLY`
      y explícitamente sin auditoría).
    - *No revivir* — el ADR los borra por construcción: `StoredObject` (embeddable),
      `ContentAddressableObjectKey`, `StoredImageObjectWriter` (el agregado solo lleva `ImageId`), y
      `BankStoredObjectRemoveListener` (contraejemplo exacto del invariante 4: borraba el byte dentro
      del flush del propietario, en un `#[AsEntityListener(postRemove)]` — acceptance criterion
      explícito: el módulo no contiene ningún listener de ciclo de vida de Doctrine que borre storage
      como efecto lateral de un flush/remove del propietario).
- **`#[PersonSubjectReference]` y `api/.person-reference-policy`**: aunque esta épica no cablea ningún
  consumidor, el campo `ImageId` que un futuro agregado use para referenciar una imagen de persona
  física deberá declararse ahí — dejar la nota en la propia historia de storage/puerto para que la
  épica de consumidor no lo redescubra desde cero.
- **La storage key deriva exclusivamente de `ImageId`** — nunca de filename, MIME, dimensiones o
  cualquier dato suministrado por el caller. El filename original (si la librería de decodificación lo
  necesita transitoriamente) no se persiste en `Image`, no se usa como storage key y no se devuelve como
  identidad autoritativa. La ruta de lectura resuelve **`ImageId` → repositorio de `Image` →
  `ImageStorage`**, nunca un path de filesystem directo — es la defensa estructural equivalente al
  precedente de lectura arbitraria de fichero que motivó el guard de Symfony 8.1. El puerto
  `ImageStorage` **nunca retorna una URL**: la entrega (URL, CDN, presigned link) es responsabilidad de
  la capa de serving, no de storage — mezclar ambas dificulta cambiar el adaptador local por S3/CDN más
  adelante.
- **Estado mínimo del agregado `Image`**: `ImageId`, `digest`, `mediaType`, `width`, `height`,
  `byteSize`, `createdAt`. **`Image` no persiste los bytes canónicos** — solo metadata e identidad; los
  bytes viven exclusivamente en `ImageStorage`. No lleva `ownerId`, `filename`, `storagePath`, `url` ni
  `variant` — meterlos convertiría `Image` en una tabla de metadata universal, exactamente lo que el ADR
  evita.
- **Modelo de fallo del pipeline**: los errores de `UploadImage` (input inválido, formato no soportado,
  fallo del decoder, fallo de normalización/encoding, límite de recursos excedido, fallo de storage,
  fallo de persistencia) son errores de dominio/aplicación **distinguibles**, no una única excepción
  genérica. Las excepciones de librerías de infraestructura (Intervention, Flysystem) no cruzan a
  `Application/` sin traducir — mismo principio hexagonal que el resto del repo.
- **Terminología fijada** para evitar ambigüedad en las stories: `Image` = agregado de dominio;
  *representación canónica* = la representación decodificada/normalizada/reencodada que produce
  `ImageProcessor`; *bytes canónicos* = los bytes serializados de esa representación (lo que se
  persiste en storage); `digest` = `SHA-256(bytes canónicos)`. Ningún término es sinónimo intercambiable
  de otro — cada story usa el preciso.
- **Explícitamente fuera de alcance de esta épica** (para que "PRs grandes" no derive en scope creep
  durante la implementación): `Bank.logoImageId`, `User.avatarImageId` y cualquier integración de
  lifecycle de consumidor; auditoría de lectura; contexto `Documents`; ingestión de evidencia; variantes
  de imagen y su esquema de URL; deduplicación; blob compartido; refcount; garbage collection;
  content-addressed storage; inferencia de ownership/autorización fina; event sourcing sobre `Image`;
  adaptador S3/objeto remoto.

- **Decision firewall para step-02/step-03** (decisiones ya cerradas — ninguna story de esta épica
  puede reabrirlas por accidente; es la lista definitiva, sustituye cualquier enumeración parcial
  anterior en este documento): `ImageId ≠ digest` · `ImageId ≠ storage key suministrada por el caller` ·
  `Image` no contiene bytes · `Image` no contiene `owner` · `Image` no contiene `filename` · `Image` no
  contiene `url` · `Image` no contiene `variant` · sin deduplicación · sin refcount · sin GC · sin
  content-addressed storage · sin event sourcing sobre `Image` · sin contexto `Documents` · sin
  ingestión de evidencia · sin consumidor `Bank` · sin consumidor `User` · sin voter de ownership
  genérico · sin auditoría genérica de lectura · borrado de bytes ≠ transacción síncrona del
  propietario · `ImageStorage` nunca devuelve una URL · ruta de lectura ≠ path de filesystem ·
  sin abstracción `ImagePipelineProducer`/`ImageUploadSource` anticipatoria (el seam de D6 se limita a
  `ImageProcessor` hasta que exista un segundo productor real) · sin URL de variante
  `/{imageId}/{hash}/{variant}`. Con esta lista cerrada, step-02/step-03 son un ejercicio de asignación
  de responsabilidades y acceptance criteria, no una nueva ronda de diseño arquitectónico.

### UX Design Requirements

No aplica. No hay contrato de diseño UX (`ux-designs/ux-*`) que cubra esta rebanada — es un módulo de
backend puro sin superficie PWA en el alcance confirmado (sin consumidor cableado, no hay pantalla que
suba ni muestre una imagen todavía).

### FR Coverage Map

FR1: Epic 1 - contrato de conservación como frontera de decisión del pipeline (D1)
FR2: Epic 1 - `UploadImage` único entry point, sin parámetro de contrato, evidencia rechazada en la
frontera (D2)
FR3: Epic 1 - no hay promoción entre contratos (D3)
FR4: Epic 1 - `Images` gestiona representaciones canónicas, no preservación (D4)
FR5: Epic 1 - ciclo de vida en el agregado consumidor vía `ImageId` + `delete()` fiable (D5)
FR6: Epic 1 - primera rebanada completa: `UploadImage`, `Image`, pipeline, storage, puerto, ruta de
lectura (D6)
FR7: Epic 1 - `ImageProcessor` como seam reutilizable por un futuro segundo productor (D6)

NFR1-NFR8: Epic 1 - transversales a las tres stories (invariantes 1-6 del ADR + contrato del puerto +
límites de recursos)

## Epic List

### Epic 1: Shared/Images — subida y lectura de representaciones fungibles (primera rebanada)

Los futuros consumidores del backoffice (empezando por el que cablee logos de banco o avatares de
usuario en su propia épica) disponen de un seam de plataforma estable y ya endurecido para subir una
imagen, obtener su `ImageId` y su representación canónica, y leerla de vuelta autenticados — sin tener
que resolver ellos mismos decodificación, límites de seguridad del decoder, direccionamiento de
storage, ni el borrado fiable de bytes. Esta épica no expone ninguna pantalla ni endpoint de negocio
todavía: entrega la capacidad de plataforma, completa y verificable por sí misma (unit + integration +
Behat contra el propio seam), que la épica del primer consumidor real consumirá directamente sin tener
que rediseñar nada de lo aquí decidido (ver el guardrail al final de Additional Requirements).

**FRs covered:** FR1, FR2, FR3, FR4, FR5, FR6, FR7 (NFR1-NFR8 transversales)

**Historias candidatas** (confirmadas informalmente en el cierre de step-01, a formalizar en step-03):

1. **Dominio + pipeline de canonicalización** — `ImageId`, agregado `Image`, `UploadImage`,
   `ImageProcessor`, decoder threat model, límites de recursos, determinismo, digest post-encoding.
2. **Storage + persistencia + frontera transaccional** — puerto `ImageStorage`, adaptador Flysystem
   local, protocolo de creación (storage→persist), semántica de `delete()`, seam outbox, documentación
   `#[PersonalData]`/`#[PersonSubjectReference]`.
3. **Lectura HTTP + seguridad de transporte** — ruta canonical-only con `IS_AUTHENTICATED_FULLY`, ETag
   sobre digest, offset-0 en BLOB, scan+test de regresión de NFR6 (ejes tipo y valor), los dos tests de
   `#[MapUploadedFile]`.

~25-30 ficheros por story, consistente con la preferencia de PRs grandes / pocas stories.

## Epic 1: Shared/Images — subida y lectura de representaciones fungibles (primera rebanada)

Los futuros consumidores del backoffice disponen de un seam de plataforma estable para subir una imagen
fungible, obtener su `ImageId` y representación canónica, y leerla de vuelta autenticados — sin
resolver ellos mismos decodificación, límites de seguridad del decoder, direccionamiento de storage ni
el borrado fiable de bytes. **FRs covered:** FR1-FR7. **NFRs:** NFR1-NFR8, transversales a las tres
historias. El decision firewall de Additional Requirements aplica a las tres sin excepción.

### Story 1.1: Subir una imagen y obtener su representación canónica

As a bounded context consumidor (p. ej. la futura épica que cablee `Bank.logoImageId` o
`User.avatarImageId`),
I want enviar los bytes de una imagen fungible a `UploadImage` y recibir un `ImageId` opaco generado
por el módulo junto con su representación canónica (dimensiones, mediaType, digest),
So that puedo delegar la decodificación, la normalización, los límites de seguridad del decoder y el
cálculo del digest sin implementar nada de eso yo mismo.

**Frontera con la Story 1.2 (validación step-04, sin dependencia hacia adelante)**: esta historia
entrega `ImageProcessor` completo y probable de forma aislada (puro, sin storage ni persistencia) y la
orquestación de `UploadImage` hasta invocar `ImageProcessor` inclusive. Las ACs que dicen "se invoca
`UploadImage`" se verifican contra la salida del processor (bytes canónicos + digest + `ImageId`
generado) — **no** contra una respuesta HTTP de extremo a extremo, que requiere la Story 1.2
(`ImageStorage`/persistencia de `Image`) para completarse. Story 1.1 es completable y testable sin que
exista la 1.2; la 1.2 completa `UploadImage`, no la reabre.

**Acceptance Criteria:**

**Given** bytes de imagen válidos y soportados
**When** se invoca `UploadImage`
**Then** el pipeline ejecuta decode → validate → normalize → re-encode → digest, en ese orden
**And** el `digest` es `SHA-256` de los bytes canónicos post-encoding — nunca de los bytes subidos
originales, ni combinado con MIME/dimensiones/`ImageId` (NFR2)

**Given** una subida nueva
**When** `UploadImage` procesa la solicitud
**Then** el módulo genera el `ImageId` internamente
**And** ninguna firma pública de `UploadImage` acepta un `ImageId` de entrada (NFR4)

**Given** los mismos bytes de entrada subidos dos veces por separado
**When** ambas subidas se procesan con la misma implementación/configuración del processor
**Then** ambas producen bytes canónicos y digest idénticos
**And** cada subida produce un `ImageId` distinto — la determinación de "distintos objetos de storage"
se completa en la Story 1.2; aquí se afirma sobre la salida del `ImageProcessor` (NFR2/NFR3)

**Given** una `Image` ya existente producida por una subida previa
**When** se inspecciona la API pública del módulo
**Then** no existe ningún método, comando ni endpoint que reclasifique esa `Image` de fungible a
evidencia — la única vía soportada para tratar el mismo contenido como evidencia es volver a subirlo
como un recurso nuevo del futuro contexto `Documents`, nunca una transición sobre la `Image` existente
(FR3, D3)

**Given** `ImageProcessor` como componente
**When** se inspecciona su contrato público
**Then** no depende de ningún tipo específico de `UploadImage` (transporte HTTP, DTO de subida) — su
firma es `bytes/contrato → representación canónica`, invocable de forma aislada e independiente de
`UploadImage`, lo que le permite ser reutilizado por un futuro segundo productor (p. ej. una derivada de
`Documents`) sin modificarse (FR7, D6) — sin introducir ninguna abstracción adicional
(`ImagePipelineProducer`, `ImageUploadSource`) todavía, per el decision firewall

**Given** que `UploadImage` no expone ningún parámetro de contrato de conservación
**When** se busca invocarlo con un contrato "Evidence"
**Then** no existe firma ni camino que lo permita — el rechazo es de frontera, no de clasificación
heurística sobre el contenido (FR2)

**Given** una imagen que excede los límites declarados (tamaño de request, píxeles decodificados,
dimensiones, número de frames, timeout)
**When** se procesa
**Then** `UploadImage` la rechaza con un error de dominio distinguible antes de que el decoder consuma
memoria/CPU no acotada
**And** ese límite no se confunde en código ni en naming con la clasificación fungible/evidencia (NFR8)

**Given** un MIME fuera del allowlist de formatos soportados por el decoder
**When** se procesa
**Then** se rechaza como límite de seguridad del decoder, nunca como decisión sobre el contrato de
conservación

**Given** un fallo del decoder, de normalización o de encoding
**When** se propaga el error
**Then** `Application` expone una excepción de dominio/aplicación propia
**And** ninguna excepción de la librería de decodificación (p. ej. Intervention) cruza a `Application/`
sin traducir

**Given** que la librería de decodificación necesite el filename original de forma transitoria
**When** el pipeline lo procesa
**Then** el filename no se persiste en ningún modelo del módulo, no se usa como storage key y no se
devuelve como identidad autoritativa

**Given** un input de 0 bytes o un cuerpo vacío
**When** se invoca `UploadImage`
**Then** se rechaza explícitamente como input inválido antes de intentar `decode` (elicitación:
Boundary & Edge Case Sweep)

**Given** metadata de dimensiones/tamaño declarada en la cabecera de la imagen (**no confiable por
construcción**)
**When** el pipeline valida los límites de recursos (AC de límites ya existente)
**Then** el valor declarado se contrasta contra los límites **antes** de reservar cualquier buffer,
memoria o recurso dimensionado por ese valor — nunca se confía en la cabecera para dimensionar la
asignación de recursos

**Given** un MIME declarado que no coincide con los magic bytes reales del contenido
**When** se procesa
**Then** se rechaza como defensa de confusión de decoder — distinta y adicional a la AC de MIME fuera
de allowlist

**Given** el resultado del pipeline (bytes canónicos re-encodados)
**When** se decide qué llega a `ImageStorage` o a cualquier respuesta HTTP
**Then** únicamente los bytes canónicos producidos por el re-encoding pueden alcanzar `ImageStorage` o
la respuesta de lectura — **los bytes originales subidos nunca se persisten ni se sirven**. Esta AC
documenta explícitamente la propiedad anti-polyglot del pipeline (un payload malicioso anexado a una
cabecera de imagen válida no sobrevive al re-encode) en vez de dejarla como efecto colateral accidental
de la implementación (elicitación: Security Audit Personas)

### Story 1.2: Persistir la imagen y garantizar el borrado fiable de sus bytes

As a bounded context consumidor,
I want que la imagen procesada quede almacenada de forma recuperable bajo su `ImageId`, con un
mecanismo fiable para pedir su borrado más adelante,
So that puedo referenciar `ImageId` desde mi propio agregado sin conocer dónde ni cómo se guardan los
bytes, ni cómo se liberan cuando decida que ya no los necesito.

**Acceptance Criteria:**

**Given** bytes canónicos producidos por la Story 1.1
**When** `ImageStorage::store(ImageId, bytes)` retorna éxito
**Then** leer mediante el mismo `ImageId` devuelve **exactamente** los bytes canónicos cuyo `SHA-256`
coincide con `Image.digest` — la promesa es más fuerte que "algo es recuperable": detecta una escritura
parcial o corrupta en disco que el filesystem considera terminada correctamente (elicitación: Boundary
& Edge Case Sweep) (NFR7)

**Given** un `ImageId` cuyo objeto ya fue borrado o nunca existió
**When** se invoca `ImageStorage::delete(ImageId)`
**Then** la operación retorna éxito — idempotente respecto a "ya ausente"
**And Given** un fallo real de infraestructura al borrar, **When** se invoca `delete()`, **Then** la
operación retorna fallo — nunca se convierte en éxito silencioso (NFR7)

**Nota de diseño documentada (elicitación: Cascading Failure Simulation, no es una AC nueva sino una
advertencia explícita)**: `ImageStorage::delete()` no lleva refcounting (NFR3) — si un futuro consumidor
llega a violar NFR4 y duplica una referencia al mismo `ImageId` (bug del lado consumidor), un `delete()`
legítimo de un poseedor borra bytes que el otro poseedor todavía cree tener, sin ningún safety net en
esta capa. No se arregla en esta épica; queda documentado para que la épica del primer consumidor real
no lo redescubra por accidente.

**Given** cualquier implementación del adaptador (Flysystem local en esta rebanada)
**When** deriva la key de almacenamiento
**Then** la key es función exclusiva de `ImageId` — nunca de filename, MIME, dimensiones o cualquier
valor suministrado por el caller

**Given** el agregado `Image`
**When** se inspecciona su esquema de persistencia
**Then** contiene únicamente `ImageId`, `digest`, `mediaType`, `width`, `height`, `byteSize`,
`createdAt`
**And** no contiene `ownerId`, `filename`, `storagePath`, `url`, `variant` ni los bytes canónicos

**Given** el orden generar `ImageId` → procesar → escribir en storage → persistir `Image`
**When** la escritura en storage tiene éxito pero la persistencia de `Image` falla
**Then** el objeto de storage queda huérfano, y ningún mecanismo de esta historia intenta compensar,
revertir el storage o recolectarlo (NFR3)
**And** una escritura de storage exitosa no se trata como acoplada transaccionalmente a la persistencia
de `Image` en esta rebanada
**And** `UploadImage` devuelve el `ImageId` al caller **solo después** de que la persistencia de
`Image` haga commit — nadie puede conocer un `ImageId` antes de que exista su fila, lo que cierra por
construcción cualquier ventana de carrera entre una lectura y una subida todavía en vuelo (elicitación:
Cascading Failure Simulation)

**Nota de alcance documentada (elicitación: Cascading Failure Simulation, no bloqueante)**: si el
mensaje `ImageDeleted` (o equivalente) nunca llega a entregarse — fallo del transporte Messenger —
bytes y fila `Image` sobreviven indefinidamente. Esta épica no introduce monitorización de mensajes
perdidos; es coherente con "sin GC" (NFR3) pero desde el ángulo de pérdida de mensaje, no de
bookkeeping de huérfanos, y merece quedar dicho en vez de asumido en silencio.

**Given** que el módulo procesa la señal de lifecycle para borrar un `ImageId` (elicitación: Boundary &
Edge Case Sweep, refinado con Sergio)
**When** ejecuta el borrado
**Then** el orden es **primero `ImageStorage::delete(ImageId)`, después borrar la fila `Image`** —
nunca al revés
**And** si el segundo paso falla tras el primero, la fila `Image` sigue existiendo y sigue siendo
**retryable** (puede reintentarse el borrado completo desde el estado en que quedó)
**And** el orden inverso queda explícitamente descartado: si el borrado de storage fallara después de
haber borrado ya la fila, el objeto de storage quedaría huérfano sin ninguna fila que lo referencie —
exactamente el bookkeeping/GC que NFR3 prohíbe construir
**And** durante la ventana intermedia (bytes ya borrados, fila `Image` todavía presente), una lectura
concurrente encuentra la fila pero el storage lookup falla — la ruta de lectura (Story 1.3) ya trata esa
ausencia como "no recuperable", nunca como un crash (misma AC que gobierna el `304` optimista)
**And** no se promete atomicidad entre el borrado de la fila y el de los bytes (no hay transacción
cruzando Postgres y filesystem) — se promete un **orden** que garantiza que cualquier fallo a medio
camino deja un estado retryable, nunca un huérfano permanente sin referencia

**Given** que en esta épica no existe todavía un agregado consumidor real
**When** se diseña el seam de borrado
**Then** el módulo no contiene ningún listener de ciclo de vida de Doctrine que borre bytes de storage
como efecto lateral de un flush/remove del propietario
**And** el seam queda preparado para que un futuro propietario publique tras commit y el borrado físico
se consuma después (outbox), sin que el módulo decida él mismo cuándo un consumidor deja de poseer una
imagen

**Given** que el módulo emite o participa en una señal de lifecycle de borrado
**When** se define su payload
**Then** transporta únicamente `ImageId` — nunca `storageKey`, `path`, `filename`, `digest` ni
`absolutePath`

**Given** el puerto `ImageStorage`
**When** se define su interfaz
**Then** ningún método retorna una URL — la entrega es responsabilidad de la Story 1.3, no de storage

**Given** que ningún consumidor existe todavía
**When** se documenta el contrato del puerto/persistencia
**Then** queda anotado que un futuro campo consumidor que referencie una imagen de persona física debe
declararse `#[PersonalData]`/`#[PersonSubjectReference]` en `api/.person-reference-policy` — `Image`
nunca determina por sí mismo si un `ImageId` es dato personal

### Story 1.3: Leer la representación canónica de una imagen de forma segura

As a bounded context consumidor (o su interfaz de usuario, cuando exista),
I want recuperar los bytes canónicos de una imagen por su `ImageId` a través de una ruta autenticada,
con soporte de cache HTTP condicional,
So that puedo servir la imagen sin conocer dónde ni cómo se almacena, y sin pagar el coste de una
respuesta completa cuando el cliente ya tiene la versión vigente.

**Acceptance Criteria:**

**Given** una petición no autenticada sobre un `ImageId` existente
**When** se invoca `GET /images/{imageId}`
**Then** la API deniega el acceso en la frontera de autenticación (`IS_AUTHENTICATED_FULLY`) antes de
resolver ningún dato
**And** el orden de comprobación es siempre **auth → validación de formato del `ImageId` → lookup en
repositorio → 404 si no existe** — la autenticación precede incluso a la validación sintáctica del id,
de modo que un no autenticado recibe el mismo resultado tanto si el `ImageId` es sintácticamente
inválido como si no existe: nunca se filtra esa distinción a quien no está autenticado (elicitación:
Boundary & Edge Case Sweep, refinado con Sergio)

**Given** un `ImageId` con formato inválido (no es un UUID)
**When** una petición autenticada lo usa
**Then** se rechaza con 400 vía `Uuid::ensure()` **antes** de cualquier lookup en repositorio — mismo
patrón que el resto de la API (`Shared/Uuid/Domain/Uuid`)

**Given** un `ImageId` sintácticamente válido pero inexistente
**When** una petición autenticada lo solicita
**Then** responde `404` — nunca `500` ni un código que revele información adicional sobre el estado
interno del módulo

**Given** una petición autenticada sobre un `ImageId` existente
**When** se invoca `GET /images/{imageId}`
**Then** la respuesta devuelve la representación canónica (bytes + `Content-Type` + `Content-Length`)
**And** incluye `X-Content-Type-Options: nosniff`
**And** el `Content-Type` es siempre el `mediaType` canónico registrado en `Image` — nunca inferido de
los bytes en el momento de servir (elicitación: Security Audit Personas)

**Given** un fallo de storage al leer los bytes de un `ImageId` cuya fila `Image` sí existe
**When** se distingue el motivo del fallo (elicitación: Failure Mode Analysis)
**Then** una ausencia **confirmada** (objeto ya no recuperable) responde `404`
**And** un fallo **transitorio** de storage (p. ej. I/O temporal) responde `5xx` — nunca se conflacionan
ambos bajo el mismo código, porque entrenaría al cliente a tratar un error reintentable como permanente

**Given** una lectura de storage que falla **a mitad de stream**, después de que la respuesta ya
hubiera comprometido `200` + `Content-Length`
**When** se sirve la respuesta (elicitación: Failure Mode Analysis)
**Then** la implementación completa una lectura **verificada** (bytes íntegros, coincidentes con
`Image.digest`) **antes** de comprometer cabeceras o status — nunca empieza a responder `200` sobre una
lectura todavía no confirmada, para no dejar al cliente con un fichero truncado sin ningún error visible

**Given** la implementación del controlador
**When** resuelve la respuesta
**Then** la cadena de resolución es `ImageId → ImageRepository → ImageStorage` — en ningún punto se
construye o acepta un path de filesystem desde la petición

**Given** cualquier variante de la petición, con o sin parámetros adicionales
**When** no hay sesión autenticada
**Then** el conocimiento del `ImageId` por sí solo nunca concede acceso a los bytes (test de regresión)

**Given** una respuesta exitosa
**When** se construye
**Then** incluye un `ETag` derivado del `digest` — nunca del `ImageId`
**And** una petición posterior con `If-None-Match` que coincide responde `304` únicamente si el objeto
sigue siendo recuperable en storage — nunca un `304` optimista sobre un objeto ya ausente

**Given** que el almacenamiento subyacente devuelva un stream ya posicionado en EOF (gotcha heredado de
Doctrine BLOB)
**When** se sirven los bytes
**Then** la implementación lee explícitamente desde el offset 0, nunca confía en la posición del stream
recibido

**Given** un upload HTTP que use `#[MapUploadedFile]`
**When** Symfony resuelve el argumento
**Then** existe un test de guard aislado que reconoce y rechaza objetos construibles desde path
**And** existe un test de integración independiente que reproduce la resolución real del argumento y
demuestra que un path de filesystem arbitrario no puede materializarse como origen de la subida — el
guard en aislado no es evidencia suficiente sin este segundo test

**Given** el árbol de `Application/` del módulo
**When** se ejecuta el scan de arquitectura de NFR6
**Then** falla si aparece un tipo de transporte (`UploadedFile`, `File`, `SplFileInfo`, `SplFileObject`
o sucesor) en cualquier firma pública
**And** falla si aparece un parámetro de valor elegido por el caller (path/filename/URL/storage key) en
cualquier firma pública
**And** un test de regresión falla si el scan deja de matchear tras un rename dentro del árbol

**Given** la ausencia de un consumidor real
**When** se documenta la ruta
**Then** queda explícito que es una prueba de infraestructura — bytes recuperables a través de la
frontera de `Images` — y no una API de producto lista para exponerse; no establece ownership ni
autorización semántica de ningún consumidor

**Riesgo residual documentado (elicitación: Security Audit Personas, no bloqueante para esta épica)**:
`ImageId` es UUIDv7 por convención del repo — time-ordered, lo que reduce el espacio de enumeración
dentro de una ventana temporal estrecha frente a un UUIDv4 puramente aleatorio. Combinado con la
decisión ya tomada de que cualquier sesión autenticada puede leer cualquier imagen (sin ownership), un
atacante autenticado de bajo privilegio podría intentar enumerar imágenes subidas en una ventana de
tiempo conocida. No cambia la decisión de autenticación de esta épica — se documenta como riesgo
residual aceptado, y **`ImageId` no se considera nunca un mecanismo de autorización ni un secreto**;
rate-limiting sobre esta ruta queda como candidato explícito para la épica del primer consumidor real,
no para esta.
