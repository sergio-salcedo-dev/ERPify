# Story 1.1: Subir una imagen y obtener su representación canónica

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a bounded context consumidor (p. ej. la futura épica que cablee `Bank.logoImageId` o `User.avatarImageId`),
I want enviar los bytes de una imagen fungible a `UploadImage` y recibir un `ImageId` opaco generado por el módulo junto con su representación canónica (dimensiones, mediaType, digest),
so that puedo delegar la decodificación, la normalización, los límites de seguridad del decoder y el cálculo del digest sin implementar nada de eso yo mismo.

## Frontera de esta historia — leer antes de tocar código

Esta es la **Story 1 de 3** de la épica `epic-images` (ver
[`sprint-status-images.yaml`](sprint-status-images.yaml)). Entrega **solo** dominio + pipeline de
canonicalización, de forma aislada y completa por sí misma:

- **Sí entrega**: `ImageId` (VO), agregado de dominio `Image` (**sin mapeo ORM todavía** — la tabla y
  el repositorio son la Story 1.2), el puerto `ImageProcessor` + su adaptador de infraestructura, y la
  orquestación de `UploadImage` **hasta invocar `ImageProcessor` inclusive**.
- **No entrega** (y no debe tocarse en esta PR): `ImageStorage` (puerto ni adaptador Flysystem),
  persistencia de `Image` (sin migración, sin repositorio Doctrine), borrado de bytes / outbox, ningún
  controlador HTTP (ni de subida ni de lectura), Behat, auditoría. Todo eso son las Stories 1.2 y 1.3.
- Las ACs que dicen "se invoca `UploadImage`" se verifican contra la **salida del `ImageProcessor`**
  (bytes canónicos + digest + dimensiones + `ImageId` generado) — nunca contra una respuesta HTTP de
  extremo a extremo. La Story 1.2 **extiende la implementación** de `UploadImage` (añade el paso de
  storage+persistencia) manteniendo estable el contrato público que esta historia fija (bytes +
  MIME declarado opcional de entrada, `Image` de salida — ver resolución de AC 13 más abajo) — no crea
  un segundo caso de uso ni le cambia la forma a la firma existente.
- `ImageProcessor` no conoce `ImageId`: ni lo recibe ni lo genera. Su contrato es únicamente
  `bytes → representación canónica`. `UploadImage` genera el `ImageId` y ensambla el agregado `Image`
  combinando ese id con la salida del `ImageProcessor` — invertir esto (que el processor genere o
  reciba el id) rompe FR7 (reutilizable por un futuro segundo productor que no conoce `UploadImage`).
- **Decision firewall de la épica (no reabrir en esta historia, listado textual)**: `ImageId ≠ digest` ·
  `Image` no contiene bytes/`owner`/`filename`/`url`/`variant` · sin deduplicación · sin refcount ·
  sin GC · sin content-addressed storage · sin event sourcing sobre `Image` · sin abstracción
  `ImagePipelineProducer`/`ImageUploadSource` anticipatoria (el seam de D6 se limita a `ImageProcessor`
  hasta que exista un segundo productor real).
- **Nota de diseño propia, no del listado anterior — a validar/discutir, no a tomar como mandato**:
  FR7 describe la firma de `ImageProcessor` como "bytes/contrato → representación canónica", lo que
  podría leerse como que recibe un parámetro de contrato de conservación. Mi lectura (argumentada, no
  el epic hablando): D1 ya fija que el pipeline mecánico (decode→validate→normalize→re-encode→digest)
  no varía por contrato — lo que cambia entre un productor y otro es quién llama y qué hace con el
  resultado (p. ej. D5's "dependent image" añade un campo de origen sobre el `Image` resultante, no una
  rama distinta dentro del processor). Por eso Task 3 propone una firma sin parámetro de contrato. Si al
  implementar se concluye lo contrario, es una decisión legítima a razonar en el PR (principio +
  objetivo + coste), no un error por desviarse de esta nota.
- **Resolución de una tensión textual — orden `decode → validate` (AC 1) frente a "límites antes de
  decodificar" (AC 7/12).** Una revisión externa de este documento señaló, correctamente, que ambas
  cosas no pueden ser literalmente ciertas a la vez si "decode" significa "materializar el raster
  completo en memoria". El epic usa "decode → validate" como el resumen macro del pipeline (así
  aparece también en FR6) — la lectura que lo hace consistente es que "decode" cubre tanto una
  inspección estructural barata (lectura de cabecera) como la decodificación completa, y que las
  guardas de recursos (AC 7, AC 12) y de MIME (AC 8, AC 13) se ejecutan en la fase barata, **antes**
  de que el decoder complete la materialización que consumiría memoria/CPU proporcional al contenido.
  Task 5 fija las fases concretas: `preflight (vacío → tamaño de payload → cabecera/estructura → MIME
  vs allowlist vs magic bytes → presupuesto de píxeles/dimensiones) → decode completo → validate
  semántico → normalize → re-encode → digest`. Esto no contradice AC 1: el orden macro
  `decode → validate → normalize → re-encode → digest` se preserva como el resumen del pipeline
  completo; lo que se fija aquí es que "validate" tiene un tramo previo al decode completo y otro
  posterior, ambos exigidos por ACs distintas del mismo documento.
- **Resolución de una contradicción real — AC 13 exige un "MIME declarado" que la firma
  `process(string $bytes)` no puede recibir.** Detectado por la misma revisión externa: tal como
  estaba planteado el contrato (solo bytes), no hay ningún valor contra el que comparar los magic
  bytes en AC 13. Dos salidas posibles — **se elige la B**, razonada:
  - *(A, descartada)* Eliminar el concepto de "MIME declarado" de esta historia y reformular AC 13
    como "el content type inferido de los magic bytes debe estar en el allowlist" (duplicaría AC 8) —
    dejando la comparación declarado-vs-real para cuando exista un caller HTTP real (Story 1.3 o la
    que introduzca el endpoint de subida).
  - *(B, elegida)* Extender la firma con un parámetro **opcional** de tipo `string`:
    `process(string $bytes, ?string $declaredMediaType = null)` (y lo mismo en `UploadImage`, que lo
    reenvía). Un `string` no es un tipo de transporte ni una localización elegida por el caller, así
    que **no viola NFR6** (sus dos ejes son "tipo de transporte" y "path/filename/URL/storage-key") —
    y AC 13 aparece textualmente dentro del bloque de Story 1.1 en `epics-images.md`, no en el de la
    Story 1.3, así que esta historia es quien debe poder demostrarlo. Cuando `$declaredMediaType` es
    `null` (el único caso que un test de esta historia puede ejercitar sin un caller real todavía),
    solo aplica la comprobación de AC 8 (magic bytes reales contra el allowlist); cuando se provee un
    valor no nulo, se compara además contra los magic bytes reales (AC 13). Task 3/5/7 reflejan esto.

## Adversarial pass

Una lectura hostil externa sobre el borrador de esta historia (2026-08-27, aportada por Sergio desde
fuera de la sesión que la redactó — no autocertificación) encontró tres bloqueantes y varios hallazgos
menores, todos corregidos antes de esta versión:

**P0-1 — contradicción de orden entre AC 1 (`decode → validate`) y AC 7/12 (límites antes de
decodificar).** Resuelto con una fase explícita de preflight (vacío → tamaño → estructura/MIME →
presupuesto de píxeles) que precede al decode completo — ver "Frontera de esta historia" y Task 5.

**P0-2 — AC 13 exigía un "MIME declarado" que la firma original (`process(string $bytes)`, sin
transporte por NFR6) no podía recibir.** Resuelto extendiendo la firma con
`?string $declaredMediaType = null` — un escalar no viola NFR6 (que prohíbe tipos de transporte y
localizaciones, no metadata) — con una segunda vuelta de Sergio cerrando la semántica exacta: el
declarado nunca selecciona el decoder, y un mismatch se rechaza aunque ambos formatos estén en el
allowlist.

**P0-3 — "límite de bytes" no distinguía el tamaño del `string` que recibe el processor del tamaño de
un futuro cuerpo HTTP.** Resuelto declarando `max_input_bytes` como el límite que esta historia
controla (`strlen($bytes)`), y el límite de transporte como fuera de alcance.

**Hallazgos menores incorporados**: `catch (\Throwable)` demasiado amplio (ahora exige tipos
específicos de la librería); el test de AC 4 se apoyaba en reflexión para probar una ausencia (ahora
`Image` es `final readonly` y la propiedad es verdadera por construcción); `failure_category` y
`operation` no tenían un vocabulario cerrado (ahora sí); ownership ambiguo de quién emite la señal de
NFR9 (fijado en `InterventionImageProcessor`); AC 14 pretendía probar una garantía de extremo a extremo
que esta historia no puede probar sola (acotado a la propiedad local que sí prueba). Una segunda ronda
(misma fecha) cerró explícitamente la política de canonicalización (formato de salida, animación,
orientación EXIF, metadata no semántica) que la primera corrección había dejado abierta — ver Dev Notes
→ "Canonicalización — contrato cerrado".

Nada se rechazó de la lectura externa; las únicas discrepancias fueron de matiz (p. ej. qué opción entre
dos alternativas propuestas se adoptaba, y no fabricar valores numéricos de límite sin benchmark),
razonadas en el propio documento en cada punto.

## Acceptance Criteria

1. **Orden y digest determinista (NFR2).** Dados bytes de imagen válidos y soportados, al invocar
   `UploadImage` el pipeline ejecuta `decode → validate → normalize → re-encode → digest`, en ese
   orden. El `digest` es `SHA-256` de los **bytes canónicos post-encoding** — nunca de los bytes
   subidos originales, ni combinado con MIME/dimensiones/`ImageId`.
2. **Generación interna del id (NFR4).** Ante una subida nueva, el módulo genera el `ImageId`
   internamente; ninguna firma pública de `UploadImage` (ni de `ImageProcessor`) acepta un `ImageId`
   de entrada.
3. **Determinismo del processor, identidad distinta por subida (NFR2/NFR3).** Los mismos bytes de
   entrada subidos dos veces por separado, procesados con la misma implementación/configuración,
   producen bytes canónicos y digest idénticos; cada subida produce un `ImageId` distinto (la
   comprobación de "dos objetos de storage independientes" se completa en la Story 1.2 — aquí se
   afirma sobre la salida del `ImageProcessor`).
4. **Sin promoción entre contratos (FR3, D3).** Dada una `Image` ya existente, no existe método,
   comando ni endpoint que la reclasifique de fungible a evidencia — la única vía soportada para tratar
   el mismo contenido como evidencia es volver a subirlo como recurso nuevo del futuro contexto
   `Documents`, nunca una transición sobre la `Image` existente.
5. **`ImageProcessor` reutilizable (FR7, D6).** Su contrato público no depende de ningún tipo
   específico de `UploadImage` (transporte HTTP, DTO de subida): la firma es `bytes/contrato →
   representación canónica`, invocable de forma aislada e independiente de `UploadImage`. No se
   introduce ninguna abstracción adicional (`ImagePipelineProducer`, `ImageUploadSource`) todavía.
6. **Sin parámetro de contrato de conservación (FR2).** `UploadImage` no expone ningún parámetro de
   contrato de conservación; no existe firma ni camino para invocarlo con "Evidence" — el rechazo es
   de frontera, no de clasificación heurística sobre el contenido.
7. **Límites de recursos antes de decodificar (NFR8).** Una imagen que excede los límites declarados
   (tamaño del payload de bytes, píxeles decodificados, dimensiones, número de frames, timeout) se
   rechaza con un error de dominio distinguible **antes** de que el decoder consuma memoria/CPU no
   acotada. Ese límite no se confunde en código ni en naming con la clasificación fungible/evidencia.
8. **MIME fuera de allowlist.** Se rechaza como límite de seguridad del decoder, nunca como decisión
   sobre el contrato de conservación.
9. **Traducción de errores.** Un fallo del decoder, de normalización o de encoding se propaga como una
   excepción de dominio/aplicación **propia**; ninguna excepción de la librería de decodificación (p.
   ej. Intervention) cruza a `Application/` sin traducir.
10. **Filename transitorio.** Si la librería de decodificación necesita el filename original de forma
    transitoria, ese filename no se persiste en ningún modelo del módulo, no se usa como storage key y
    no se devuelve como identidad autoritativa.
11. **Input vacío (Boundary & Edge Case Sweep).** Un input de 0 bytes o un cuerpo vacío se rechaza
    explícitamente como input inválido **antes** de intentar `decode`.
12. **No confiar en metadata declarada.** La metadata de dimensiones/tamaño declarada en la cabecera de
    la imagen (no confiable por construcción) se contrasta contra los límites de recursos **antes** de
    reservar cualquier buffer, memoria o recurso dimensionado por ese valor declarado.
13. **Confusión de decoder.** Un MIME declarado que no coincide con los magic bytes reales del
    contenido se rechaza como defensa de confusión de decoder — distinta y adicional a la AC de MIME
    fuera de allowlist (#8).
14. **Anti-polyglot (Security Audit Personas).** Únicamente los bytes canónicos producidos por el
    re-encoding pueden alcanzar `ImageStorage` o una respuesta de lectura — los bytes originales
    subidos nunca se persisten ni se sirven. Esta AC documenta explícitamente la propiedad anti-polyglot
    del pipeline (un payload malicioso anexado a una cabecera de imagen válida no sobrevive al
    re-encode) como propiedad afirmada, no como efecto colateral accidental de la implementación.
15. **Observabilidad privacy-safe (NFR9).** Ante un fallo de decoder, normalización, encoding, o un
    rechazo por límite de recursos o MIME fuera de allowlist, la señal de observabilidad emitida
    incluye `format` y `failure_category` pero **nunca** los bytes de la imagen, el filename original,
    ni ningún dato capaz de identificar a la persona que subió el contenido.
16. **Versionado del contrato de canonicalización (MEDIA-8).** Queda declarado (código + comentario o
    doc, no un campo persistido) que la canonicalización de esta rebanada es **v1 implícito** — no se
    persiste ningún campo de versión — y que el disparador para introducir un versionado explícito es
    la primera vez que el algoritmo de canonicalización cambia en código mergeado.

## Tasks / Subtasks

- [ ] **Task 0 — Vocabulario de nombrado** (referencial, ADR D2 / `docs/rules/cqrs-naming.md`)
  - [ ] `docs/rules/cqrs-naming.md` no tiene categoría "Upload" (lo dice el propio ADR — ver
    References). `UploadImage` no encaja en `<Noun>{Creator|Updater|Deleter}` (no es create/update/
    delete, y "upload" no es un sinónimo razonable de ninguno de los tres) ni es un handler de bus.
    Propuesta concreta de partida (a razonar/ajustar en el PR, no un mandato): una sexta categoría
    "Upload (ingesta)" con forma `<Verb><Noun>`, igual que Command/Query pero para un caso de uso de
    ingesta invocado por llamada directa — ejemplo de referencia `UploadImage`. Documentar la decisión
    final con su argumento (principio + objetivo + coste), igual que cualquier otra propuesta de
    nombrado nueva. Es un entregable de esta historia, no limpieza opcional.
- [ ] **Task 1 — `ImageId`** (AC 2) — `api/src/Shared/Images/Domain/ImageId.php`
  - [ ] `final readonly class ImageId` extendiendo el patrón de `Erpify\Shared\Uuid\Domain\Uuid` — ver
    `SessionId` como plantilla exacta (ctor privado, `generate()` vía `Uuid::generate()`,
    `fromString()` vía `Uuid::ensure()`, `toString()`, `equals()`). Ningún método público acepta un
    valor de `ImageId` para "crear una nueva" — solo `generate()` mina.
- [ ] **Task 2 — Agregado `Image`** (AC 4, 16) — `api/src/Shared/Images/Domain/Image.php`
  - [ ] Estado mínimo: `ImageId`, `digest` (string, hex SHA-256), `mediaType` (string), `width` (int),
    `height` (int), `byteSize` (int), `createdAt` (`DateTimeImmutable`, vía
    `Erpify\Shared\Clock\Domain\SystemClock::now()` en el constructor — mismo patrón que `Bank`). Sin
    ORM todavía (Story 1.2). Sin `ownerId`, `filename`, `storagePath`, `url`, `variant`.
  - [ ] `final readonly class` con **solo** constructor + accesores — ningún setter, ningún método de
    transición de estado. Esto es lo que hace **AC 4 verdadera por construcción**: no hay superficie
    que reclasificar porque la clase no tiene ninguna operación de mutación, ni siquiera interna. No
    escribir un test de reflexión que busque "un método `reclassify` no existe" (frágil y de bajo
    valor); el test de AC 4 verifica el modelo observable — el constructor no acepta ni expone ningún
    campo de contrato de conservación/clasificación, y la clase es `final readonly`.
  - [ ] Invariantes estructurales que el constructor SÍ debe guardar (guarda en construcción, lanza
    excepción si no se cumplen): `digest` tiene exactamente 64 caracteres hexadecimales (longitud de
    SHA-256 en hex), `width > 0`, `height > 0`, `byteSize > 0`, `mediaType` no vacío. **No** re-verificar
    que `digest` corresponde a ningún byte — `Image` no lleva bytes, esa garantía es responsabilidad de
    `ImageProcessor` (Task 3/5), no algo que el agregado pueda comprobar por sí mismo.
- [ ] **Task 3 — Puerto `ImageProcessor`** (AC 1, 5, 6, 13) —
  `api/src/Shared/Images/Domain/ImageProcessor.php`
  - [ ] Interfaz de capacidad (convención `docs/rules/testing.md` — nombrar por capacidad, no
    `ImageProcessorInterface`). Firma:
    `process(string $bytes, ?string $declaredMediaType = null): CanonicalImage` — el parámetro
    opcional es lo que hace verificable AC 13 (ver "Resolución de una contradicción real" en Frontera);
    no es un tipo de transporte ni un path, así que no viola NFR6. Sin parámetro de contrato de
    conservación (ver la nota de diseño en "Frontera de esta historia" — decisión a razonar, no cerrada
    por este documento). Sin dependencia de ningún tipo de transporte/DTO de `UploadImage`.
  - [ ] `CanonicalImage` (DTO/VO de salida, `final readonly`) lleva exactamente lo que `ImageProcessor`
    produce: bytes canónicos, `digest`, `mediaType`, `width`, `height`, `byteSize` — sin `ImageId` (lo
    genera `UploadImage`). `byteSize` se deriva de `strlen($bytes canónicos)` **dentro** del propio
    VO/constructor (nunca un valor pasado por separado que pueda divergir de los bytes reales).
    `mediaType` es el MIME de los **bytes canónicos de salida** (el que decide la fase `re-encode`),
    no el MIME de entrada — ver "Terminología" en Dev Notes.
- [ ] **Task 4 — Excepciones de dominio distinguibles** (AC 7, 8, 9, 11, 13) —
  `api/src/Shared/Images/Domain/Exception/`
  - [ ] Al menos: input inválido / vacío (AC 11), formato no soportado o MIME fuera de allowlist o
    confusión MIME-vs-magic-bytes (AC 8, 13 — pueden compartir clase con una razón distinguible o ser
    clases separadas; deben poder distinguirse en un test), fallo del decoder, fallo de
    normalización/encoding (AC 9), límite de recursos excedido (AC 7, 12). Nombres sugeridos —
    ajustables con argumento: `EmptyImageInput`, `UnsupportedImageFormat`, `ImageDecodingFailed`,
    `ImageProcessingFailed`, `ImageResourceLimitExceeded`.
  - [ ] Ninguna excepción de Intervention (o de la librería decodificadora que se elija) cruza a
    `Application/` sin traducir (AC 9) — **capturar los tipos de excepción específicos** que la
    librería documenta para fallos de decodificación/encoding (p. ej. su jerarquía propia de
    excepciones, no `\Throwable` en bloque). Un `catch (\Throwable)` amplio traduciría también un
    `\TypeError`, un `\ArgumentCountError` o un agotamiento de memoria de PHP como si fueran un fallo
    de negocio esperado ("imagen inválida") en vez de dejarlos propagarse como el error de programación
    o de entorno que realmente son — precisamente el escenario que NFR8 quiere que no ocurra en
    silencio. Si la librería elegida no ofrece una jerarquía de excepciones suficientemente fiable,
    documentarlo explícitamente y decidir el catch más estrecho posible con esa limitación.
  - [ ] Categorías estables de `failure_category` para NFR9 (Task 6) — fijar un conjunto cerrado, no
    strings inventados en el momento: `empty_input`, `input_too_large`, `unsupported_format`,
    `mime_mismatch`, `resource_limit_exceeded`, `decode_failure`, `processing_failure` (normalización/
    encoding). Cada excepción de este Task se mapea a exactamente una categoría.
  - [ ] No es obligatorio ya cablear el marcador RFC 9457 (`docs/api-error-contract.md`) — esta
    historia no tiene controlador HTTP que las traduzca a una respuesta. Dejarlas como excepciones de
    dominio/aplicación puras; el mapeo a marcador llega con la historia que introduzca el endpoint de
    subida.
- [ ] **Task 5 — Adaptador `InterventionImageProcessor`** (AC 1, 3, 7, 8, 9, 10, 12, 13, 14, 16) —
  `api/src/Shared/Images/Infrastructure/InterventionImageProcessor.php`
  - [ ] `composer require "intervention/image:^4.3"` (ver Latest Tech Information — v4.x, no v3; API
    distinta del código rescatado de referencia). **No** instalar `intervention/image-laravel` (paquete
    de integración con Laravel, irrelevante en Symfony — composer podría sugerirlo, no es necesario).
    Construir `ImageManager` explícitamente con el driver **GD** (único disponible — ver más abajo) en
    el propio adaptador de Infrastructure, no en un bundle/servicio nuevo.
  - [ ] **Fase 0 — input vacío y tamaño del payload** (AC 7, 11): rechazar `'' === $bytes` antes de
    cualquier otra cosa. Después, rechazar si `\strlen($bytes) > erpify.images.max_input_bytes` —
    **esto es el límite de bytes que esta historia controla** (sobre el `string` que ya recibe
    `ImageProcessor`); el límite de tamaño del **cuerpo HTTP** de una futura subida es un control de
    perímetro/transporte distinto, fuera de esta historia (no hay HTTP todavía), y no debe confundirse
    con este.
  - [ ] **Fase 1 — inspección estructural antes de decodificar completo** (AC 7, 12, 13): usar
    `getimagesizefromstring()` para leer dimensiones declaradas y `finfo` (`ext-fileinfo`, ya requerido)
    sobre los bytes crudos para el MIME real detectado — ninguna de las dos decodifica el raster
    completo, pero tampoco son una garantía general de seguridad por sí mismas (siguen siendo parsers
    sobre input no confiable; ver ADR D1: "the decoder is itself an attack surface" — el objetivo es
    acotar el trabajo, no eliminar el riesgo del parseo). Con esos dos valores:
    - `format(declaredWidth × declaredHeight)` se contrasta contra `erpify.images.max_decoded_pixels`
      **antes** de invocar el decode completo — este es el límite de "píxeles decodificados" de NFR8.
    - `declaredWidth`/`declaredHeight` se contrastan por separado contra
      `erpify.images.max_input_dimension` (dimensión de **entrada**, distinta de la dimensión de
      **salida** que fija la fase de `normalize` — no son el mismo parámetro).
    - **Secuencia exacta para AC 8 + AC 13 (cerrada, no ambigua)**: (1) detectar el MIME real desde los
      magic bytes vía `finfo` — el detectado es siempre la única fuente de verdad, `$declaredMediaType`
      **nunca** selecciona el decoder ni influye en cómo se decodifica, solo se usa como comparación
      posterior; (2) si el detectado no está en el allowlist del decoder → rechazo `unsupported_format`
      (AC 8), sin mirar siquiera `$declaredMediaType`; (3) si `$declaredMediaType !== null` y
      `$declaredMediaType !== <detectado>` → rechazo `mime_mismatch` (AC 13) **incluso si el declarado
      también estuviera en el allowlist** — la comparación es declarado-vs-detectado, no
      declarado-vs-allowlist, así que un declarado `image/png` sobre bytes reales `image/jpeg` se
      rechaza aunque ambos formatos sean soportados. Con `$declaredMediaType === null` (único caso
      ejercitable en esta historia sin un caller HTTP real) el paso (3) no aplica.
  - [ ] **Límite de frames / animación** (AC 7; contrato cerrado en Dev Notes → "Canonicalización",
    punto 4): la salida canónica contiene **exactamente un frame**, sea cual sea el número de frames
    del origen — decisión ya tomada, no un eje abierto. El requisito observable es ese; el nombre
    concreto de la opción de Intervention/GD que lo consigue se verifica contra la versión instalada
    (no asumir el nombre de este documento).
  - [ ] **Timeout** (AC 7): PHP no tiene un timeout de decodificación nativo sin `pcntl` (no está entre
    las extensiones requeridas del proyecto). Esta historia **no** implementa un timeout a nivel de
    aplicación — la protección de perímetro (límite de request del servidor/worker) es lo que cubre
    este eje. Ver la matriz de controles en Dev Notes.
  - [ ] **Decode completo + validate semántico + normalize + re-encode + digest** (AC 1, 3): tras la
    Fase 0/1, invocar el decode completo; validar lo que solo es observable tras decodificar (si algo
    lo es); `normalize` implementa las 8 propiedades cerradas en Dev Notes → "Canonicalización" (mismo
    formato de familia que la entrada, aplicar orientación EXIF a los píxeles, descartar animación a 1
    frame, descartar metadata no semántica, redimensionar preservando aspect ratio si excede
    `erpify.images.max_output_dimension`); re-encodear; `digest = SHA-256(bytes canónicos
    post-encoding)`.
  - [ ] **Anti-polyglot** (AC 14): el método solo devuelve los bytes re-encodados; nunca los bytes de
    entrada originales. Esta historia solo puede probar esta propiedad **localmente** (el processor
    nunca expone/retorna el input) — que "nunca alcanzan storage ni una respuesta HTTP" es una garantía
    que se completa cuando existan la Story 1.2 (storage) y 1.3 (lectura HTTP); no reformular el test
    de esta historia como si probara el camino completo.
  - [ ] Filename transitorio (AC 10): si la firma de entrada en algún punto lleva un nombre de archivo
    (no debería si `ImageProcessor` recibe solo `string $bytes` + `?string $declaredMediaType`), no
    persistirlo ni usarlo como key.
  - [ ] Comentario/doc explícito de versionado v1 implícito (AC 16) junto a la implementación del
    digest — sin campo persistido.
  - [ ] **Implementar el contrato de canonicalización ya cerrado** — ver Dev Notes, "Canonicalización
    — contrato cerrado" (8 propiedades: fuente del formato de entrada, MIME mismatch, formato de
    salida = misma familia, un solo frame, orientación EXIF aplicada a píxeles, metadata no semántica
    descartada, aspect ratio preservado, caveat de determinismo). Reflejarlo con un comentario junto al
    pipeline — ya no es una decisión abierta, así que documentarlo es dejar constancia, no decidir.
  - [ ] Nuevos parámetros de configuración bajo `erpify.images.*` en `api/config/services.yaml`
    (`parameters:` — mismo patrón que el `erpify.media.*` retirado, con namespace nuevo, no revivido):
    `max_input_bytes`, `max_decoded_pixels`, `max_input_dimension`, `max_output_dimension`, calidad de
    encoding. **Ninguno de estos valores numéricos es un requisito de dominio** — son **defaults de
    configuración del despliegue**, documentados explícitamente (comentario junto al parámetro), no
    inventados en silencio ni tratados como límites de seguridad ya calibrados. Punto de partida de
    ingeniería, no un valor validado: `max_input_bytes` ≈ 20 MB, `max_decoded_pixels` ≈ 40 megapíxeles
    (`8000 × 5000`), `max_output_dimension` ≈ 4096 px. **`40 megapíxeles` es más permisivo de lo que
    parece**: una imagen RGBA materializada en memoria ronda 4 bytes/píxel más los buffers intermedios
    que el decoder y el resize puedan reservar — el coste real de memoria/CPU no lo fija el recuento de
    píxeles solo, sino `píxeles × bytes-por-píxel × número de buffers intermedios`, que depende del
    decoder/runtime concreto. Estos tres valores deben **validarse con una prueba de memoria/CPU contra
    el worker real** antes de considerarse límites de seguridad calibrados — hasta entonces son un
    punto de partida razonable, no una cifra defendible por sí misma.
    Inyectarlos con `#[Autowire('%erpify.images.max_…%')]` en el constructor del adaptador.
- [ ] **Task 6 — Observabilidad privacy-safe** (AC 15) —
  **reutilizar** el canal Monolog `observability` ya existente (`api/config/packages/monolog.yaml`,
  siempre activo, no sujeto a buffering `fingers_crossed`) — **no crear un mecanismo de métricas
  nuevo**. Inyectar `Psr\Log\LoggerInterface` con
  `#[Autowire(service: 'monolog.logger.observability')]` (mismo patrón que
  `SearchObservabilityListener`).
  - [ ] **Ownership — quien emite, para no duplicar la línea**: `InterventionImageProcessor`
    (Infrastructure) emite la señal para cualquier fallo que él mismo detecta o traduce (Fase 0/1,
    decode, normalize, encode). `UploadImage` (Application) **no** vuelve a loguear el mismo fallo — si
    en el futuro `UploadImage` llega a detectar algo por sí mismo antes de invocar al processor, eso sí
    lo logueará él, pero no hay ningún caso así en esta historia (`ImageProcessor` es quien recibe los
    bytes primero).
  - [ ] Línea estructurada por fallo con discriminador `event` estable (p. ej.
    `images.processing.rejected`, `images.processing.failure`) y contexto limitado a `format` +
    `operation` + `failure_category` — nunca `imageId`, `digest`, bytes, filename ni dato de persona.
    `format` es el MIME **detectado** cuando se pudo determinar; si el rechazo ocurre antes de poder
    determinarlo (p. ej. input vacío, o bytes demasiado cortos para `finfo`), el valor es el literal
    `"unknown"` — nunca se omite la clave. `operation` toma uno de: `preflight`, `decode`, `normalize`,
    `encode`. `failure_category` es una de las constantes fijadas en Task 4.
- [ ] **Task 7 — Caso de uso `UploadImage`** (AC 2, 6, 13) —
  `api/src/Shared/Images/Application/UploadImage.php` (o el nombre que resulte de Task 0)
  - [ ] Firma pública: acepta `string $bytes, ?string $declaredMediaType = null` (nunca
    `UploadedFile`/`File`/`SplFileInfo`/`SplFileObject` ni un path/filename/URL de caller — el
    invariante NFR6 ya aplica aquí aunque su scan+test de regresión formal se escriba en la Story 1.3;
    un `?string` no es un tipo de transporte ni una localización, así que no lo viola). En esta historia
    ningún caller real pasa un valor no nulo todavía (no hay HTTP) — el parámetro existe para que AC 13
    sea demostrable con un test directo.
  - [ ] Orquestación: genera `ImageId::generate()` → invoca
    `ImageProcessor::process($bytes, $declaredMediaType)` → ensambla `Image` con el id generado + la
    salida del processor → devuelve el agregado (sin storage ni persistencia todavía — eso lo añade la
    Story 1.2 sobre esta misma clase, sin cambiar esta firma).
  - [ ] No expone ningún parámetro de contrato de conservación (AC 6).
- [ ] **Task 8 — Tests** (ver matriz AC→test en Dev Notes)
  - [ ] Unit, `ImageProcessor` en aislado (sin contenedor, sin DB): AC 1, 3, 7, 8, 9, 10, 12, 13, 14, 16
    — incluye el test de regresión de determinismo explícito en NFR2 (mismos bytes → mismo digest,
    bytes canónicos idénticos) y un fixture de imagen válida mínima bajo `api/tests/Fixtures/Images/`
    (no `DataFixtures/Fixtures/`, que es para Alice/YAML) — más fixtures deliberadamente rotos:
    cabecera con dimensiones que exceden el límite, payload con datos anexados tras una imagen válida
    (para probar el anti-polyglot). AC 13 (`declaredMediaType` no nulo y no coincidente con los magic
    bytes reales) se prueba invocando `ImageProcessor::process()` directamente con el segundo argumento
    — no requiere HTTP ni fixture nueva, basta con declarar un MIME distinto al real de una fixture ya
    existente; incluir el caso del contrato cerrado con Sergio: declarado y detectado son formatos
    **ambos soportados** pero distintos entre sí (p. ej. declarado `image/png` sobre bytes reales
    `image/jpeg`) — debe rechazarse igual, no solo el caso de un declarado no soportado.
  - [ ] Tests del contrato de canonicalización cerrado (Dev Notes → "Canonicalización"): (a) una
    fixture JPEG con orientación EXIF no-normal produce los mismos píxeles/canonical bytes que su
    equivalente ya rotado sin EXIF de orientación; (b) una fixture GIF/WebP animada produce una salida
    canónica de un solo frame; (c) dos fixtures con contenido visual idéntico pero metadata no semántica
    distinta (EXIF/comentarios/ICC distintos) producen el mismo digest; (d) el `mediaType` de salida es
    siempre de la misma familia que el detectado en la entrada, nunca el `declaredMediaType`.
  - [ ] Unit, `Image`: AC 4 se prueba sobre el **modelo observable** — el constructor no acepta ni
    expone ningún campo de contrato de conservación/clasificación y la clase es `final readonly` (no
    escribir un test de reflexión buscando la ausencia de un método concreto). Además, los invariantes
    estructurales del constructor (digest hex-64, dimensiones/tamaño > 0, mediaType no vacío).
  - [ ] Unit, `UploadImage` con un doble de `ImageProcessor` (nombrarlo según
    `docs/rules/testing.md` — `InMemoryImageProcessor` si es una implementación alternativa utilizable,
    `StubImageProcessor` si solo responde con un valor fijo): AC 2, 6, y verificación de que el
    `ImageId` generado no se pasa como argumento a `ImageProcessor`, y de que `$declaredMediaType` se
    reenvía tal cual (incluido el caso `null`).
  - [ ] Unit/log-capturing, NFR9 (AC 15): capturar el logger `observability` inyectado y aserta las
    claves permitidas/prohibidas explícitamente, incluido el caso `format = "unknown"` cuando el
    rechazo ocurre antes de poder detectar un formato — no basta con "no lanzó excepción". Aplica
    "Assert the seed before asserting the absence" (`docs/rules/testing.md`): primero afirmar que **sí
    se emitió** una línea de log, luego afirmar que esa línea no contiene las claves prohibidas — si el
    logger nunca llegó a invocarse, "no contiene `digest`" es una verdad vacía que no prueba nada.
  - [ ] Behat **no aplica** a esta historia — no existe todavía ningún endpoint HTTP que ejercitar
    (llega con la Story 1.3).
  - [ ] `make php.stan` sobre cada fichero nuevo/tocado; `make php.quality` al terminar.

## Dev Notes

### Arquitectura y capas

- Módulo nuevo `Shared/Images` (vertical-slice, como `Shared/Crypto`/`Shared/Uuid` — ver
  [`../../../api/CLAUDE.md`](../../../api/CLAUDE.md) → "Folder structure"). **No hace falta registrarlo
  en `api/tools/deptrac/deptrac.yaml`**: los `Shared/` anidados se pliegan automáticamente en las capas
  `Shared.*` (`src/Shared/(.*/)?Domain` etc. — ver `api/CLAUDE.md` → "Deptrac architecture gate"), igual
  que `Shared/Event`. Tampoco requiere entrada en `api/.bounded-context-allowlist` para esta historia (no
  hay ninguna llamada cross-context — el módulo no tiene consumidor todavía).
- `Domain/` (`ImageId`, `Image`, `ImageProcessor` interfaz, excepciones): cero imports de Symfony/
  Doctrine/HTTP. Única librería externa permitida: `symfony/uid` a través de la clase base `Uuid`
  (excepción ya documentada, no una nueva). Ver
  [`docs/rules/architecture.md`](../../../docs/rules/architecture.md) → "Layer Structure".
- `Application/` (`UploadImage`): orquesta, no decodifica. Depende de `ImageProcessor` (interfaz) y de
  `ImageId`/`Image` (Domain). Puede inyectar `Psr\Log\LoggerInterface` directamente — es un contrato
  PSR interop admitido sin envoltorio (`docs/rules/architecture.md` → "Documented exception — PSR
  interface-only interop contracts": no crear un puerto 1:1 que solo envuelva `psr/log`).
- `Infrastructure/` (`InterventionImageProcessor`): la única capa que puede importar Intervention/GD.

### Librería de imagen — investigación de versión (2026-08-26)

- `intervention/image` último estable es **4.3.1** (Packagist, fuente primaria consultada
  directamente), requiere `php: ^8.3` — compatible con el floor `^8.5` del proyecto. Esto es de
  confianza alta.
- **El código retirado (ver abajo) usaba una API vieja** (`new ImageManager(new Driver())`,
  `decodeBinary()`, `encode(new JpegEncoder(...))`) que **no** es la instalable hoy — de eso sí hay
  certeza, porque `intervention/image` cambió de mayor varias veces desde entonces. **Lo que NO está
  verificado con certeza** es el mapeo exacto método-a-método de la API actual: dos fuentes de
  investigación (búsqueda web y una página de la propia documentación) dieron nombres parcialmente
  distintos entre sí para las mismas operaciones (`decode()` vs `usingDriver()` aparecieron en páginas
  etiquetadas de forma inconsistente). **No copiar ningún nombre de método de este documento como si
  fuera la firma real** — leer el código fuente instalado en `vendor/intervention/image` (o su
  changelog/upgrade guide) tras el `composer require` es el único paso fiable antes de escribir el
  adaptador.
- Solo el driver **GD** está disponible: `ext-imagick` no está entre las extensiones requeridas del
  proyecto (`ext-gd` sí lo está — ver `api/composer.json`). No añadir `ext-imagick` sin justificarlo.
- La investigación no encontró ninguna opción documentada de protección de decompression-bomb ni de
  límite de píxeles/frames en Intervention (ausencia, no una confirmación fuerte — ver el caveat de
  fiabilidad de esa fuente arriba). **Asumir que no existe** y que los controles de NFR8 son
  responsabilidad de este adaptador es la postura segura independientemente de si la librería
  termina teniendo alguna opción parcial: verificarlo contra el código fuente instalado no exime de
  implementar la guarda propia en Fase 0/1 (Task 5).

### Código retirado como plantilla, no como copia

`git show 08f8199^:api/src/Shared/Media/Infrastructure/Image/InterventionImageNormalizer.php` (77
líneas) es la referencia de forma del pipeline (allowlist MIME → decode → scaleDown → encode → sha256)
citada por la propia épica, **pero le faltan exactamente los controles que esta historia añade nuevos**:
no comprobaba dimensiones declaradas antes de decodificar (AC 12), no comparaba MIME declarado contra
magic bytes reales (AC 13), no limitaba frames, confiaba en el MIME que el caller declaraba
(`$uploadedImage->mimeType`) sin verificarlo, y su DTO de salida (`NormalizedImage`) no llevaba
dimensiones. Rescatar la forma del pipeline, no esas ausencias — y no rescatar el nombre `Normalizer`
tal cual sin argumentar si `Processor` (el término que usa la ADR/épica en FR6/FR7) es más preciso.

### Naming

- `ImageProcessor` como nombre de interfaz sigue la convención de puertos por capacidad
  (`docs/rules/testing.md` → "Test double naming convention" — la misma tabla rige nombres de puerto en
  `src/`, no solo en tests): `<Capability>`, adaptador `<Technology><Port>` →
  `InterventionImageProcessor`.
- `UploadImage` no encaja en la plantilla actual de `docs/rules/cqrs-naming.md` (`Creator`/`Updater`/
  `Deleter`/`Finder`/`Searcher`, o `*Handler` de bus) — es intencional, lo dice el propio ADR
  ([`docs/adr/images-vs-documents-conservation-contract.md`](../../../docs/adr/images-vs-documents-conservation-contract.md)
  D2: *"the concrete classes take their shape from rules/cqrs-naming.md, whose template has no upload
  category yet and gains one with the first slice"*). Ver Task 0.

### Terminología — tres nociones de MIME distintas, no intercambiables

Añadido a la terminología ya fijada por el epic (`Image`, representación canónica, bytes canónicos,
digest — ver `epics-images.md` → "Terminología fijada"):

- **MIME declarado** (`declaredMediaType`): lo que un caller afirma sobre el contenido, opcional y no
  confiable por construcción — en esta historia, solo lo que un test le pasa directamente a
  `ImageProcessor::process()`; en una futura historia con HTTP, el `Content-Type` u otro campo que la
  petición declare.
- **MIME detectado**: el que `finfo` infiere de los magic bytes reales del contenido. Es la única base
  fiable para decidir soporte (AC 8) y para contrastar contra lo declarado (AC 13).
- **MIME canónico** (`CanonicalImage::$mediaType`): el de los bytes de **salida**, tras `re-encode` —
  siempre de la **misma familia de formato** que el detectado (v1 no transcodifica entre formatos; ver
  siguiente sección, punto 3), nunca el `declaredMediaType` del caller.

### Canonicalización — contrato cerrado (decisión de Sergio, 2026-08-27)

Ninguno de estos ejes lo fija el epic ni el ADR literalmente — pero `CanonicalImage`/`digest`/NFR2
necesitan una definición operacional mínima para significar algo, así que se cierran aquí en vez de
dejarse implícitos en la implementación. Las siguientes ocho propiedades son el contrato de v1;
documentarlas junto al pipeline (comentario) es obligatorio, no opcional:

1. **Formato de entrada**: se determina siempre desde los bytes (magic bytes vía `finfo`);
   `declaredMediaType` es metadata de entrada no confiable, **nunca** selecciona el decoder ni influye
   en cómo se decodifica — ver la secuencia cerrada de Task 5, Fase 1.
2. **MIME mismatch**: `declaredMediaType !== null` y no coincide con el detectado ⇒ rechazo, **aunque
   ambos estén en el allowlist** (declarado `image/png` sobre bytes reales `image/jpeg` se rechaza
   igual que si `image/png` no fuera soportado — ver Task 5).
3. **Formato de salida = misma familia que el de entrada detectado** (`jpeg→jpeg`, `png→png`,
   `webp→webp` — el precedente del código retirado). Se descarta deliberadamente para v1 converger a un
   único formato universal (p. ej. siempre WebP): eso convertiría la canonicalización en una política
   de transcodificación con preguntas adicionales sobre alpha, pérdida de calidad, fidelidad visual,
   color profiles y compatibilidad que esta rebanada no necesita abrir. `CanonicalImage::$mediaType`
   es siempre el MIME de esta familia de salida, nunca el declarado por el caller.
4. **Animación**: la representación canónica contiene **exactamente un frame**, sea cual sea el número
   de frames del origen — un formato animado se acepta como entrada, pero la animación no forma parte
   de la representación canónica de v1 (evita que un futuro lector interprete que `Image` puede
   representar una animación).
5. **Orientación EXIF**: se aplica durante `normalize` — los bytes canónicos usan la orientación física
   de píxel resultante; la metadata de orientación deja de ser autoritativa después de canonicalizar.
   Esto es necesario para el propio NFR2: dos imágenes visualmente equivalentes (una ya rotada en
   píxeles, otra con los píxeles sin rotar más una etiqueta EXIF de rotación) deben producir el mismo
   resultado canónico — sin fijar esto, producirían digests distintos por una diferencia que no es
   visual.
6. **Metadata no semántica** (EXIF más allá de orientación, comentarios, perfiles ICC, etc.): no forma
   parte de la representación canónica ni puede influir en el digest. Se fija la **propiedad**, no un
   listado exhaustivo de tags — cualquier encoder determinista que la cumpla vale; no hace falta
   decidir tag por tag en esta historia.
7. **Dimensiones de salida**: se preserva el aspect ratio; el resultado queda acotado por
   `erpify.images.max_output_dimension` (redimensiona solo si excede, nunca amplía).
8. **Caveat de determinismo (NFR2)**: todo lo anterior se sostiene bajo **la misma implementación/
   configuración/runtime** (misma versión de Intervention, de GD/libjpeg/libwebp) — no es una promesa
   de identidad de bytes entre actualizaciones de esas librerías nativas. No convertir NFR2 en una
   garantía que ninguna librería de imagen puede sostener a través de upgrades.

### Matriz de controles de recursos (NFR8)

NFR8 permite explícitamente no implementar cada control con tal de declarar cuál queda cubierto:

| Control                        | Cubierto en Story 1.1 | Mecanismo                                             |
|---------------------------------|:----------------------:|--------------------------------------------------------|
| Tamaño del input (bytes)        | Sí                     | `strlen($bytes)` vs `max_input_bytes`, Fase 0           |
| Dimensiones declaradas          | Sí                     | `getimagesizefromstring()` vs `max_input_dimension`     |
| Píxeles decodificados           | Sí                     | `width × height` declarados vs `max_decoded_pixels`     |
| Dimensión de salida             | Sí                     | `normalize` vs `max_output_dimension`                   |
| Número de frames                | Sí (acotado a 1)       | Decode sin animación — ver Task 5                       |
| Timeout de decodificación       | **No**                 | Perímetro (servidor/worker) — no hay `pcntl` disponible |

### Reutilización — no reinventar

- **Observabilidad**: `Erpify\Shared\Search\Infrastructure\Http\EventListener\SearchObservabilityListener`
  ya establece el patrón exacto para NFR9 — canal Monolog `observability` dedicado y siempre activo
  (declarado en `api/config/packages/monolog.yaml`, no sujeto al buffering `fingers_crossed` de `app`
  en producción), logger inyectado vía `#[Autowire(service: 'monolog.logger.observability')]`, líneas
  estructuradas con un discriminador `event` estable. No crear un `MetricsRecorder`/`StatsD`/similar —
  no existe ninguno en el árbol y añadir uno sería infraestructura nueva para un problema ya resuelto.
- **Identidad**: `ImageId` sigue el patrón exacto de `Erpify\Iam\Session\Domain\SessionId` (VO sobre
  `Erpify\Shared\Uuid\Domain\Uuid`) — no reinventar la validación/generación de UUID.
- **Timestamp del agregado**: `Image::createdAt` se estampa con `SystemClock::now()` en el constructor
  del dominio, igual que `Erpify\Backoffice\Bank\Domain\Entity\Bank` — no inyectar un `Clock` por DI en
  una clase que el propio `UploadImage` construye con `new`.
- **Configuración de límites**: parámetros nuevos bajo `erpify.images.*` en el bloque `parameters:` de
  `api/config/services.yaml`, inyectados con `#[Autowire('%param%')]` — mismo mecanismo que el
  `erpify.media.max_dimension` ya retirado (no revivir ese parámetro con su nombre viejo; el namespace
  cambia a `images`).

### Testing

- Domain y Application puros: unit, sin contenedor, sin DB (`api/tests/Unit/Shared/Images/...`,
  espejando `api/src/Shared/Images/...`).
- El adaptador de Infraestructura (`InterventionImageProcessor`) también es testeable como unit test
  puro (no toca red ni DB) — decodifica bytes de fixtures reales. Fixtures binarias en
  `api/tests/Fixtures/Images/` (no en `api/tests/DataFixtures/Fixtures/`, reservado a fixtures Alice/
  YAML de base de datos).
- **No Behat en esta historia** — no hay endpoint HTTP que ejercitar todavía.
- Ver [`docs/rules/testing.md`](../../../docs/rules/testing.md) → "Test double naming convention". AC 4
  se prueba sobre el modelo observable de `Image` (constructor + accesores, `final readonly`, sin
  campo de clasificación) — no con un test de reflexión que busque la ausencia de un método concreto
  (ver Task 2).

### Matriz AC → test

| AC | Qué prueba | Nivel |
|----|------------|-------|
| 1  | orden del pipeline completo + digest sobre bytes canónicos | Infrastructure |
| 2  | id generado internamente, ninguna firma pública lo acepta | Application |
| 3  | mismos bytes → mismo digest/bytes canónicos; `ImageId` distinto por llamada | Infrastructure + Application |
| 4  | `Image` sin superficie de clasificación (por construcción) | Domain |
| 5  | `ImageProcessor` invocable sin `UploadImage` | Domain/Infrastructure |
| 6  | `UploadImage` sin parámetro de contrato de conservación | Application |
| 7  | límites de tamaño/píxeles/dimensión/frames rechazan antes del decode completo | Infrastructure |
| 8  | MIME detectado fuera de allowlist | Infrastructure |
| 9  | excepción de librería nunca cruza sin traducir | Infrastructure |
| 10 | filename transitorio no persiste/no es storage key | Infrastructure/Application (estático si no hay firma con filename) |
| 11 | input vacío rechazado antes de decode | Infrastructure |
| 12 | metadata declarada contrastada antes de reservar recursos | Infrastructure |
| 13 | `declaredMediaType` no nulo y no coincidente con magic bytes reales | Infrastructure (vía `ImageProcessor::process()` directo) |
| 14 | solo bytes re-encodados salen del processor (garantía local; la de extremo a extremo se completa en 1.2/1.3) | Infrastructure |
| 15 | señal `observability` con claves permitidas/prohibidas, incluido `format="unknown"` | Infrastructure (log-capturing) |
| 16 | declaración v1 implícito | revisión de código/doc, no un test ejecutable |

### Project Structure Notes

```
api/src/Shared/Images/
  Domain/
    ImageId.php
    Image.php
    CanonicalImage.php            (DTO/VO de salida de ImageProcessor, final readonly)
    ImageProcessor.php            (interfaz)
    Exception/
      EmptyImageInput.php
      UnsupportedImageFormat.php
      ImageDecodingFailed.php
      ImageProcessingFailed.php
      ImageResourceLimitExceeded.php
  Application/
    UploadImage.php
  Infrastructure/
    InterventionImageProcessor.php

api/tests/Unit/Shared/Images/
  Domain/{ImageTest.php, ImageIdTest.php}
  Application/UploadImageTest.php
  Infrastructure/InterventionImageProcessorTest.php

api/tests/Fixtures/Images/                (nuevo — bytes de imagen mínimos válidos/rotos)
```

No hay variancia ni conflicto detectado con la estructura unificada del proyecto — el módulo sigue el
mismo patrón de `Shared/Crypto` (Domain + Application + Infrastructure, sin ORM en Domain).

### Fuera de alcance — no lo construyas aquí

Storage (`ImageStorage`), persistencia de `Image` (migración/repositorio Doctrine), borrado fiable de
bytes, outbox, `#[PersonalData]`/`#[PersonSubjectReference]` (no hay consumidor todavía), cualquier
endpoint HTTP, `#[MapUploadedFile]`, Behat, auditoría, deduplicación, refcount, GC, content-addressed
storage, variantes de imagen. Todo está explícitamente fuera de esta épica entera o diferido a la
Story 1.2/1.3 — ver `epics-images.md` → "Explícitamente fuera de alcance" y "Decision firewall".

### References

- [`_bmad-output/planning-artifacts/epics-images.md`](../planning-artifacts/epics-images.md) —
  Requirements Inventory (FR1-FR7, NFR1-NFR9), Additional Requirements (tabla de responsabilidades,
  terminología fijada, modelo de fallo del pipeline), Story 1.1 completa (frontera con 1.2 incluida).
- [`docs/adr/images-vs-documents-conservation-contract.md`](../../docs/adr/images-vs-documents-conservation-contract.md) —
  D1 (contrato de conservación), D2 (UploadImage único entry point, categoría de nombrado pendiente),
  D3 (no promoción), D6 (primera rebanada, `ImageProcessor` como seam, digest como atributo hasta que
  entra en una URL), invariantes 1-6.
- [`docs/rules/architecture.md`](../../docs/rules/architecture.md) → "Layer Structure", excepciones de
  `symfony/uid` y de contratos PSR interop.
- [`docs/rules/cqrs-naming.md`](../../docs/rules/cqrs-naming.md) — plantilla de nombrado actual, sin
  categoría "Upload" (Task 0).
- [`docs/rules/testing.md`](../../docs/rules/testing.md) → "Test double naming convention", "Assert the
  seed before asserting the absence".
- `api/src/Iam/Session/Domain/SessionId.php` — plantilla de VO de identidad sobre `Uuid`.
- `api/src/Backoffice/Bank/Domain/Entity/Bank.php` — plantilla de estampado de `createdAt` vía
  `SystemClock::now()`.
- `api/src/Shared/Search/Infrastructure/Http/EventListener/SearchObservabilityListener.php` — patrón
  exacto a reutilizar para NFR9 (canal `observability`).
- `api/config/packages/monolog.yaml` — declaración del canal `observability`.
- `git show 08f8199^:api/src/Shared/Media/Infrastructure/Image/InterventionImageNormalizer.php` —
  plantilla de forma del pipeline retirado (rescatar la forma, no las ausencias de seguridad).
- [intervention/image — Packagist](https://packagist.org/packages/intervention/image) — fuente
  fiable para versión (4.3.1) y floor de PHP (`^8.3`), consultada 2026-08-26.
- La URL de documentación de configuración de drivers consultada en la misma sesión
  (`image.intervention.io/v3/...`) dio nombres de API inconsistentes con lo reportado por la búsqueda
  web para "v4" — **no citar ningún nombre de método de esa página como definitivo**; ver "Librería de
  imagen" arriba.

## Dev Agent Record

### Agent Model Used

{{agent_model_name_version}}

### Debug Log References

### Completion Notes List

### File List
