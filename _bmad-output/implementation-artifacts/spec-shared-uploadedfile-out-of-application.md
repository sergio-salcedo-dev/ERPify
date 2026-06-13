---
title: 'Shared · sacar `UploadedFile` (Symfony HTTP) de Application tras un VO `UploadedImage`'
type: 'refactor'
created: '2026-06-13'
status: 'ready-for-dev'
baseline_commit: 'c97d7dd'
tracking_issue: '#265'
context:
  - '{project-root}/docs/project-context.md'
  - '{project-root}/docs/rules/architecture.md'
  - '{project-root}/api/CLAUDE.md'
  - '{project-root}/docs/adr/bank-bankaccount-modeling.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `Symfony\Component\HttpFoundation\File\UploadedFile` (tipo de la capa **HTTP**) cruza la frontera de `Application/` y atraviesa **tres contextos** como tipo de parámetro: `MediaRegistrar::registerFromUploadedFile(UploadedFile)` (`Shared/Media`), `ImageNormalizer::normalize(UploadedFile)` (puerto, `Shared/Media`), `StoredImageObjectWriter::storeFromUploadedFile(UploadedFile,…)` (`Shared/Storage`) y `BankCreator::create(…, ?UploadedFile, ?UploadedFile)` (`Backoffice/Bank`). `UploadedFile` es un detalle del **adaptador de entrada** (el controller); por la regla hexagonal (igual que `ManagerRegistry`/Doctrine) `Application/` no debe conocer tipos del framework HTTP. Preexistente (PR #252). La superficie real consumida aguas abajo del controller es **mínima**: `getMimeType()` y los bytes (`getPathname()`+`file_get_contents`); `filename`/`size`/`error` no se leen, y la validación size+MIME ya vive en `BankPostController::assertValidUpload` (`#[Assert\File]`). El único lector real del fichero es `InterventionImageNormalizer::normalize()`.

**Approach:** Introducir un **value object inmutable** propio de Media — `Shared/Media/Application/Dto/UploadedImage { public string $mimeType, public string $bytes }` — sin interfaz, sin adaptador, sin stream, sin Symfony. El controller (donde `UploadedFile` **sí** es legítimo) lo construye leyendo mime + bytes; `Application/` y el puerto pasan a hablar de `UploadedImage`. Tras el cambio el **único** `UploadedFile` en producción queda en los controllers. **No** se introduce un puerto stream-capable: su único consumidor (`InterventionImageNormalizer`) necesita el blob completo (`decodeBinary`) y haría `stream_get_contents()` acto seguido → complejidad sin ganancia. El streaming pertenece a un **contexto `Documents` futuro** (ficheros grandes no-imagen), no al pipeline de imágenes — frontera registrada en un ADR.

## Boundaries & Constraints

**Always:**
- **VO** `api/src/Shared/Media/Application/Dto/UploadedImage.php`: `final readonly`, `public string $mimeType` + `public string $bytes`. PHP puro; cero imports de framework. Campo `bytes` (no `contents`/`binary`) por coherencia con el DTO hermano `NormalizedImage.bytes` del mismo módulo.
- **Firmas Application/puerto sin Symfony**: `ImageNormalizer::normalize(UploadedImage): NormalizedImage`; `MediaRegistrar::register(UploadedImage): Media` (rename de `registerFromUploadedFile`); `StoredImageObjectWriter::store(UploadedImage, string $invalidImageFormField): StoredObjectWriteResult` (rename de `storeFromUploadedFile`); `BankCreator::create(CreateBankCommand, ?UploadedImage $logo = null, ?UploadedImage $storedObject = null)`.
- **`InterventionImageNormalizer` lee del VO**: `$mime = $uploadedImage->mimeType`, `$binary = $uploadedImage->bytes`. Conserva intactos el allowlist de MIME (`ALLOWED_MIMES`), el check de blob vacío (`'' === $binary` → `InvalidImageException('Empty upload.')`), el `scaleDown`, el re-encode por MIME y el hash. Deja de importar y usar `UploadedFile`/`getPathname`/`file_get_contents`.
- **Construcción del VO en el controller** (Infrastructure): tras `assertValidUpload(...)`, mapear cada `?UploadedFile` → `?UploadedImage` con `new UploadedImage($file->getMimeType() ?? '', (string) file_get_contents($file->getPathname()))`. `UploadedFile` y `#[MapUploadedFile]` permanecen en el controller (adaptador de entrada legítimo).
- **Contrato HTTP intacto**: RFC 9457 sin cambios; los multipart funcionales de Bank (logo + storedObject) verdes sin tocar el wire.
- **ADR** `docs/adr/media-vs-documents-upload-boundary.md` + entrada en `docs/index.md`.

**Ask First (decididas):**
- **Nombre del campo** → `bytes` (coherencia con `NormalizedImage`); reversible a `contents` si el humano lo pide.
- **Dónde se mapea `UploadedFile`→`UploadedImage`** → helper privado inline en el controller **ahora** (1 consumidor: `BankPostController`). Extraer a un mapper compartido en `Shared/Media/Infrastructure/Http/` cuando llegue el **2º** controller (logos empresa, avatares cliente/usuario) — regla de tres, no especular hoy.
- **Streaming / puerto** → **rechazado** para Media; pertenece al contexto `Documents` (ver ADR y #265 backlog).

**Never:**
- No introducir interfaz/adaptador/stream para la entrada de imagen (`UploadedImage` es un VO por valor, no un puerto).
- No mover la validación size+MIME fuera del controller (sigue en el boundary, antes de construir el VO).
- No tocar el pipeline de imágenes (decode→normalize→re-encode→hash→dedup→store) ni `StoragePort`.
- No construir aquí el contexto `Documents`, `StoragePort::writeStream()`, backends Dropbox/S3, antivirus, OCR (backlog: #268 / #266 / #267).
- No exponer `pathname`/`originalName`/`size` en el VO (nadie los consume en el pipeline de imágenes).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Logo OK | `POST /banks` multipart con `image` JPEG/PNG/WebP | controller valida → mapea a `UploadedImage` → `register` → `Media` dedup por `contentHash`; `201` | N/A |
| storedObject OK | `POST /banks` con `storedObject` | `store` → `StoredObjectWriteResult` → `StoredObject`; `201` | N/A |
| Sin ficheros | `POST /banks` solo JSON | `$logo`/`$storedObject` = `null`; banco sin logo/objeto | N/A |
| MIME no permitido | `image/gif` | rechazado en el **boundary** por `assertValidUpload` (`#[Assert\File]`) antes de construir el VO | `422 validation-failed` |
| Fichero vacío | upload 0 bytes | `bytes=''` → `InterventionImageNormalizer` lanza `InvalidImageException('Empty upload.')` (o ya rechazado por mime en boundary) | contrato `invalid-image` intacto |
| MIME no detectable | `getMimeType()===null` | VO recibe `''`; normalizer rechaza por allowlist (`Unsupported image MIME type: `) | `InvalidImageException` |
| Dedup hit | `contentHash` ya registrado | `register` devuelve la `Media` existente sin re-escribir; `store` no re-escribe si la key existe | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Media/Application/Dto/UploadedImage.php` -- NUEVO: VO `final readonly { public string $mimeType, public string $bytes }`.
- `api/src/Shared/Media/Application/Port/ImageNormalizer.php` -- MODIFICAR: `normalize(UploadedImage): NormalizedImage`; quita import `UploadedFile`.
- `api/src/Shared/Media/Infrastructure/Image/InterventionImageNormalizer.php` -- MODIFICAR: lee `$image->mimeType`/`$image->bytes`; conserva allowlist + check vacío + re-encode + hash; quita `UploadedFile`/`getPathname`/`file_get_contents`.
- `api/src/Shared/Media/Application/MediaRegistrar.php` -- MODIFICAR: `register(UploadedImage): Media`; quita import `UploadedFile`.
- `api/src/Shared/Storage/Application/StoredImageObjectWriter.php` -- MODIFICAR: `store(UploadedImage, string): StoredObjectWriteResult`; quita import `UploadedFile`.
- `api/src/Backoffice/Bank/Application/BankCreator.php` -- MODIFICAR: params `?UploadedImage $logo, ?UploadedImage $storedObject`; llama `store()`/`register()`; quita import `UploadedFile`.
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php` -- MODIFICAR: helper privado `toUploadedImage(?UploadedFile): ?UploadedImage` tras `assertValidUpload`; pasa los VOs a `create()`. `UploadedFile`/`#[MapUploadedFile]` permanecen aquí.
- `api/tests/Unit/Shared/Media/Application/MediaRegistrarTest.php` -- MODIFICAR: `register(new UploadedImage('image/png', 'PNG.'))`; quita import + `createStub(UploadedFile::class)`.
- `api/tests/Functional/Backoffice/Bank/Infrastructure/Controller/BankLogoMultipartFunctionalTest.php` -- REFERENCIA: contrato HTTP intacto, verde sin cambios.
- `api/tests/Functional/Backoffice/Bank/Infrastructure/Controller/BankStoredObjectMultipartFunctionalTest.php` -- REFERENCIA: idem.
- `api/src/Shared/Media/Application/Dto/NormalizedImage.php` -- REFERENCIA: campo `bytes` (origen del nombre).
- `api/config/packages/media.yaml` -- REFERENCIA: cap `max_upload_bytes: 2M`; sin cambios.
- `docs/adr/media-vs-documents-upload-boundary.md` -- NUEVO: ADR de la frontera.
- `docs/index.md` -- MODIFICAR: entrada del ADR.

## Tasks & Acceptance

**Execution:**
- [ ] `api/src/Shared/Media/Application/Dto/UploadedImage.php` (NUEVO) -- VO `{ mimeType, bytes }`.
- [ ] `api/src/Shared/Media/Application/Port/ImageNormalizer.php` (MODIFICAR) -- firma `normalize(UploadedImage)`.
- [ ] `api/src/Shared/Media/Infrastructure/Image/InterventionImageNormalizer.php` (MODIFICAR) -- lee del VO; sin `UploadedFile`.
- [ ] `api/src/Shared/Media/Application/MediaRegistrar.php` (MODIFICAR) -- `register(UploadedImage)`.
- [ ] `api/src/Shared/Storage/Application/StoredImageObjectWriter.php` (MODIFICAR) -- `store(UploadedImage, …)`.
- [ ] `api/src/Backoffice/Bank/Application/BankCreator.php` (MODIFICAR) -- params `?UploadedImage`; llamadas `store()`/`register()`.
- [ ] `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php` (MODIFICAR) -- mapeo `UploadedFile`→`UploadedImage`.
- [ ] `api/tests/Unit/Shared/Media/Application/MediaRegistrarTest.php` (MODIFICAR) -- input `UploadedImage`; sin stub `UploadedFile`.
- [ ] `docs/adr/media-vs-documents-upload-boundary.md` (NUEVO) + `docs/index.md` (entrada).
- [ ] `make php.stan` sobre cada fichero tocado y `make php.quality` al final, limpios.
- [ ] Behat/funcionales de Bank (POST logo + storedObject) verdes sin tocar el wire.

**Acceptance Criteria:**
- Given la capa `Application/` (Media/Storage/Bank), then ningún símbolo importa `Symfony\Component\HttpFoundation\File\UploadedFile`; el único `UploadedFile` en `api/src` (prod) queda en los controllers.
- Given `grep -rn "UploadedFile" api/src`, then solo aparece en `Backoffice/Bank/Infrastructure/Controller/BankPostController.php`.
- Given un POST de banco con logo y/o storedObject válidos, then el comportamiento (dedup por hash, `201`, payload) es idéntico al actual.
- Given un MIME no permitido, then se rechaza en el boundary (`422`); given un blob vacío, then `InvalidImageException` con el contrato `invalid-image` intacto.
- Given el ADR, then registra la frontera Media↔Documents y el *por qué* del VO simple (alternativa stream rechazada).
- Given `make php.quality`, then limpio (stan level max, cs-fixer, rector, phpmd, psalm-taint).

## Design Notes

**Por qué un VO por valor y no un puerto con `stream()`.** El único lector del fichero, `InterventionImageNormalizer`, necesita el blob completo (`decodeBinary($binary)`) y consumiría un `stream()` con `stream_get_contents()` en la línea siguiente → la pereza no se aprovecha. Un puerto stream-capable metería interfaz + adaptador para servir a un solo consumidor que descarta la laziness, y **contaminaría Media con un requisito que pertenece a Documents**. El VO `{ mimeType, bytes }` es exactamente la superficie consumida, sin más.

**Frontera Media (imágenes) ↔ Documents (ficheros grandes).** Media es un módulo de **imágenes** (logos banco/empresa, avatares cliente/usuario, imágenes de producto, miniaturas): todas se validan → decodifican → normalizan → re-encodan → hashean → almacenan, y todas necesitan el contenido completo. Los **ficheros grandes no-imagen** (proyectos, memorias técnicas, planos DWG, PDFs, Excel, ZIP, vídeo, RAW, partes de trabajo) serán un contexto `Documents` independiente con su propio `UploadedDocument { mimeType(); stream(); originalFilename(); size() }`, `StoragePort::writeStream()` y pipeline sin Intervention (upload → virus scan → metadata → store stream). El ADR fija esta separación y el *por qué* (no un puerto genérico que finja servir a ambos). Backlog: épica #268 (contexto `Documents`), enablers #266 (`StoragePort::writeStream`) y #267 (backends Dropbox/S3).

**El seam de imagen es compartido.** Hoy `BankCreator` es el único consumidor; el VO limpio paga en cada nuevo llamante (Company/Bank/Client/User logos y avatares) que herede la entrada sin reabrir la fuga de Symfony.

**MIME y bytes se leen una vez, en el controller.** La detección de MIME (`getMimeType()`, finfo sobre el temp file) y la lectura de bytes se hacen al construir el VO; valor idéntico al actual (mismo `getMimeType()` sobre el mismo temp file). Neto de I/O sin cambios: la lectura que hacía el normalizer se mueve al boundary.

**Dónde vive el mapeo.** Helper privado inline en `BankPostController` ahora (1 consumidor). Cuando entre el 2º controller con subida de imagen, extraer un mapper compartido en `Shared/Media/Infrastructure/Http/` (regla de tres) — no se crea hoy por minimalismo.

**ADR — decisiones a registrar** (estilo `docs/adr/bank-bankaccount-modeling.md`, ≤150 líneas): (1) `UploadedFile` no cruza `Application/` — es detalle del adaptador HTTP; (2) entrada de imagen = VO `UploadedImage` por valor (alternativa: puerto stream-capable — descartada, contamina Media, sin ganancia con Intervention); (3) ficheros grandes/no-imagen = contexto `Documents` futuro con streaming desde el día uno + `StoragePort::writeStream`.

## Verification

**Commands:**
- `make php.stan` -- 0 errores (level max) en lo tocado.
- `make php.unit c='--filter MediaRegistrar'` -- verde con input `UploadedImage`.
- `grep -rn "UploadedFile" api/src` -- solo `BankPostController`.
- `make php.behat c='features/backoffice/bank'` (o los funcionales multipart) -- POST logo + storedObject verdes; contrato HTTP intacto.
- `make php.quality` -- limpio.

## Suggested Review Order

**VO y puerto**
- VO por valor (PHP puro): `api/src/Shared/Media/Application/Dto/UploadedImage.php`.
- Firma del puerto: `api/src/Shared/Media/Application/Port/ImageNormalizer.php`.

**Adaptador y casos de uso (Symfony fuera de Application)**
- Lectura del VO: `api/src/Shared/Media/Infrastructure/Image/InterventionImageNormalizer.php`.
- `register`/`store`: `MediaRegistrar.php`, `StoredImageObjectWriter.php`.
- Orquestación: `api/src/Backoffice/Bank/Application/BankCreator.php`.

**Boundary (único `UploadedFile` que queda)**
- Mapeo `UploadedFile`→`UploadedImage`: `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php`.

**Frontera y tests**
- ADR Media↔Documents: `docs/adr/media-vs-documents-upload-boundary.md`.
- Test del registrar con VO: `api/tests/Unit/Shared/Media/Application/MediaRegistrarTest.php`.
