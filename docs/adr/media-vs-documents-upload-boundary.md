# ADR — Frontera de subida: Media (imágenes) vs Documents (ficheros grandes)

> **Status:** superseded · **Date:** 2026-06-14 · **Scope:** the upload seam consumed by `Backoffice/Bank` and future callers (company logos, client/user avatars, product images).
>
> **Superseded:** the API carries no upload surface. Nothing consumed it, so it was removed rather than
> carried forward; images will be modelled afresh in their own epic. The reasoning below — where the
> `UploadedFile`/`UploadedImage` boundary belongs, and why images and large documents are different
> problems — is the record this ADR preserves for that work.
>
> Contexto temporal: la aplicación **no está en producción**; ninguna decisión arrastra compatibilidad hacia atrás. Este ADR no requiere migración de esquema.

## Contexto

`Symfony\Component\HttpFoundation\File\UploadedFile` —un tipo de la capa **HTTP**— cruzaba la
frontera de `Application/` y atravesaba **tres contextos** como tipo de parámetro:
`MediaRegistrar::registerFromUploadedFile(UploadedFile)` (`Shared/Media`),
`ImageNormalizer::normalize(UploadedFile)` (puerto, `Shared/Media`),
`StoredImageObjectWriter::storeFromUploadedFile(UploadedFile, …)` (`Shared/Storage`) y
`BankCreator::create(…, ?UploadedFile, ?UploadedFile)` (`Backoffice/Bank`).

`UploadedFile` es un detalle del **adaptador de entrada** (el controller). Por la misma regla
hexagonal que mantiene Doctrine/`ManagerRegistry` fuera de `Application/`
([`rules/architecture.md`](../rules/architecture.md)), `Application/` no debe conocer tipos del
framework HTTP. La fuga era preexistente (llegó con el PR #252).

La superficie que el pipeline consume realmente de `UploadedFile` aguas abajo del controller es
**mínima**: el MIME (`getMimeType()`) y los bytes (`getPathname()` + `file_get_contents`).
`filename` / `size` / `error` no se leen, y la validación size+MIME ya vive en el boundary
(`BankPostController::assertValidUpload` con `#[Assert\File]`). El único lector real del fichero es
`InterventionImageNormalizer::normalize()`.

## Decisiones

### D1 — `UploadedFile` no cruza `Application/`

`UploadedFile` queda confinado al controller (adaptador de entrada, donde el tipo HTTP **sí** es
legítimo). Tras el refactor, el **único** símbolo de producción que importa `UploadedFile` es
`Backoffice/Bank/Infrastructure/Controller/BankPostController.php`; `Application/` y el puerto dejan
de conocerlo. Es la misma frontera que ya respetan el resto de adaptadores: el detalle del
transporte se traduce a un tipo propio del dominio/aplicación en el borde.

### D2 — Entrada de imagen = value object `UploadedImage` por valor

La entrada del pipeline de imágenes es un VO inmutable propio de Media —
`Shared/Media/Application/Dto/UploadedImage { public string $mimeType; public string $bytes }`— sin
interfaz, sin adaptador, sin stream, sin Symfony. El controller lo construye leyendo MIME + bytes
tras validar; `Application/` y el puerto hablan de `UploadedImage`. El campo se llama `bytes` por
coherencia con el DTO hermano `NormalizedImage.bytes` del mismo módulo.

El MIME y los bytes se leen **una vez, en el controller**, al construir el VO: mismo `getMimeType()`
sobre el mismo temp file que antes, misma lectura de bytes. El I/O neto no cambia — la lectura que
hacía el normalizer simplemente se mueve al boundary.

**Descartado: un puerto `UploadedImage { mimeType(); stream(): resource }` con adaptador.** Su único
consumidor, `InterventionImageNormalizer`, necesita el blob completo (`decodeBinary($binary)`) y
haría `stream_get_contents()` en la línea siguiente → la pereza del stream no se aprovecha. Un
puerto stream-capable metería interfaz + adaptador para servir a un consumidor que descarta la
laziness, y **contaminaría Media con un requisito que pertenece a Documents** (D3). El VO
`{ mimeType, bytes }` es exactamente la superficie consumida, sin más.

El seam de imagen es **compartido**: hoy `BankCreator` es el único llamante; mañana lo serán logos
de empresa, logos de banco, avatares de cliente/usuario e imágenes de producto. Cuanto más limpio
el puerto (cero Symfony), más rinde en cada nuevo consumidor que hereda la entrada sin reabrir la
fuga.

Dónde vive el mapeo `UploadedFile`→`UploadedImage`: helper privado inline en `BankPostController`
mientras haya **un** consumidor. Cuando entre el segundo controller con subida de imagen, extraer un
mapper compartido a `Shared/Media/Infrastructure/Http/` (regla de tres) — no se especula hoy.

### D3 — Ficheros grandes / no-imagen = contexto `Documents` futuro con streaming desde el día uno

Media es un módulo de **imágenes**: logos, avatares, miniaturas, imágenes de producto. Todas siguen
el mismo pipeline (validar → decodificar → normalizar → re-encodar → hashear → almacenar) y todas
necesitan el **contenido completo** en memoria — por eso el VO por valor es el modelo correcto.

Los **ficheros grandes no-imagen** (proyectos, memorias técnicas, planos DWG, PDFs, Excel, ZIP,
vídeo de obra, fotografías RAW, partes de trabajo) serán un contexto **`Documents`** independiente,
con su propia abstracción de entrada `UploadedDocument { mimeType(); stream(); originalFilename();
size() }` y un pipeline **sin** Intervention (upload → virus scan → metadata → store stream). Eso
exige `StoragePort::writeStream()` —hoy `StoragePort` solo ofrece `write(string $key, string
$contents)`, que bufferiza el fichero entero en RAM (bloqueante para ficheros grandes)— y cablear
backends Flysystem (Dropbox a corto plazo, S3 a futuro; el puerto ya abstrae el destino).

La frontera se fija **ahora** para no construir un puerto genérico que finja servir a ambos mundos:
imágenes quieren el blob completo, documentos quieren el stream. Forzar una única abstracción
penalizaría a los dos. Backlog: épica #268 (contexto `Documents`), enablers #266
(`StoragePort::writeStream`) y #267 (backends Dropbox/S3). Antivirus, versionado documental,
metadatos y **OCR** de PDFs escaneados cuelgan de esa épica, no de Media.

## Consecuencias

- `Shared/Media/Application` y `Shared/Storage/Application` quedan libres de tipos HTTP; el pipeline
  de imágenes (decode → normalize → re-encode → hash → dedup → store) y `StoragePort` no se tocan.
- El contrato HTTP de `POST /banks` (multipart logo + storedObject, RFC 9457) es idéntico: el wire
  no cambia, solo el tipo interno que el controller pasa a `Application/`.
- Cualquier futuro requisito de streaming entra por `Documents` / `StoragePort::writeStream`, no
  ensanchando el VO de Media con `pathname` / `originalName` / `size` que nadie consume aquí.
