# ERPify — Media & Document System (core transversal)

> **Objetivo:** centralizar la gestión de archivos (imágenes, PDFs, Excel…)
> **desacoplada del dominio de negocio**. No es un módulo más: es **shared
> kernel** transversal del que cuelgan CRM (logos), Projects (fotos de obra),
> Procurement (PDFs de proveedor) y Accounting (facturas).
>
> Artefacto de **roadmap / diseño** — backlog vivo por capas, no roadmap cerrado.
> El módulo `0.8` del [`product-roadmap.md`](product-roadmap.md) (visible en la
> página in-app `/backoffice/roadmap`) resume el estado por fases; aquí está el
> detalle de historias, DoD y gates.

## Estado real de partida

No se parte de cero: el código ya tiene `api/src/Shared/Media`
(Application/Domain/Infrastructure) y `api/src/Shared/Storage` (adaptadores
**Flysystem**), y la vertical **Bank** ya sube y recupera ficheros end-to-end
(StoredObject con hash de contenido para deduplicar). Por eso **F0 está hecho** y
**F1 en curso**; lo que falta es generalizarlo en una librería de medios reusable
y subir por las capas F2–F5.

## Backlog vivo por capas (evolutivo)

Cada fase tiene **estado independiente** — se avanza por capas, no de golpe.

```text
[Media & Document System]
 ├── F0 · Diseño del modelo .......... DONE
 ├── F1 · Storage local (MVP) ........ IN PROGRESS
 ├── F2 · Media Library genérica ..... NEXT
 ├── F3 · Document System ............ FUTURE
 ├── F4 · S3 / MinIO abstraction ..... FUTURE
 └── F5 · Optimización SaaS .......... IDEA
```

### F0 — Diseño del modelo (base contract) · DONE

Lo primero y lo que casi nunca cambia.

- **Historias:** definir la entidad `MediaFile`; definir
  `StorageProviderInterface`; definir tipos MIME y clasificación básica; definir
  la relación **genérica** con módulos (Customer, Project, Product) por id.
- **DoD:** existe tabla `media_file`; existe la interfaz de storage; **ningún
  módulo guarda archivos directamente**.

### F1 — Storage local (MVP funcional) · IN PROGRESS

ERPify ya puede subir y descargar archivos.

- **Historias:** `LocalStorageProvider`; subida desde la API; descarga;
  generación de path **por tenant**; control básico de permisos.
- **DoD:** upload funciona end-to-end; el archivo se guarda en `/storage`;
  metadatos en PostgreSQL.

### F2 — Media Library genérica · NEXT

Dejas de pensar "archivos sueltos" y pasas a sistema.

- **Historias:** endpoint `/media/upload`; endpoint `/media/{id}`; listado de
  archivos por contexto (proyecto, cliente…); soft delete / borrado lógico (bajo
  las excepciones de [`rules/database.md`](rules/database.md)); tags o categorías
  básicas.
- **DoD:** un archivo puede reutilizarse en múltiples entidades; **no hay
  duplicación** de ficheros (dedup por hash).

### F3 — Document System (dominio construcción) · FUTURE

Aquí empieza el valor ERP real. `Document` es un **agregado de negocio**, no se
confunde con `MediaFile` (el fichero técnico). Es la capa que en el mapa de
contextos figura como **DMS** (ver [`bounded-contexts.md`](bounded-contexts.md)).

- **Historias:** agregado `Document`; versionado de documentos; tipos de
  documento (plano, contrato, certificación, foto de obra); relación con
  Project / Customer / Supplier (por id + eventos).
- **DoD:** documentos versionados; historial de cambios; estados (borrador,
  aprobado, archivado).

### F4 — Storage abstraction (cambio de proveedor) · FUTURE

Clave para escalar.

- **Historias:** `S3StorageProvider`; `MinioStorageProvider`; configuración por
  entorno; migración local → S3.
- **DoD:** cambiar de storage **sin tocar el dominio**; provider swap en config.

### F5 — Optimización SaaS · IDEA

Nivel producto serio.

- **Historias:** URLs firmadas temporales; CDN (Cloudflare); cache headers
  correctos; thumbnails automáticos; OCR opcional (PDFs).
- **DoD:** descarga directa sin pasar por la API; rendimiento optimizado.

## Gates técnicos (load-bearing)

Reglas que evitan deuda técnica invisible — un módulo nuevo que adjunte ficheros
debe respetarlas:

- **Ningún módulo guarda archivos directamente.** Todo pasa por el Media System.
- **Todo archivo es un `MediaFile`.** Las entidades de negocio guardan su **id**,
  no bytes ni paths.
- **Ningún `file_get_contents` (ni acceso a disco/SDK de storage) en `Domain/`.**
  El acceso físico vive tras `StorageProviderInterface` en `Infrastructure/`
  (encaja con la pureza de `Domain/` de [`architecture-api.md`](architecture-api.md)).
- **`MediaFile` (técnico) ≠ `Document` (agregado de negocio).** No mezclar la
  capa de fichero con la de documento versionado.

## Principios de backlog vivo

1. **Cada fase, estado independiente** (F0 done, F1 in-progress, F2 next…).
2. **Cada historia es un vertical slice.** ❌ "Implementar storage" → ✅ "Subir la
   imagen de un usuario y recuperarla en su perfil".
3. **El dominio manda, no la infraestructura.** Nunca "añadir S3"; siempre
   "permitir descargas rápidas de documentos de obra". S3/MinIO es
   implementación (F4), no objetivo.

## Encaje global

Es shared kernel transversal porque casi todos los contextos adjuntan ficheros:

| Contexto | Uso |
|---|---|
| CRM | logos de clientes |
| Projects | fotos de obra, planos |
| Procurement | PDFs de proveedor |
| Accounting / Finance | facturas |

Estructurarlo bien desde el inicio reduce ~70% la complejidad futura de
integración de documentos, evita lógica duplicada en cada bounded context y
habilita multi-storage (F4) sin refactor masivo.

## Mantenimiento

El estado por fases se refleja en `roadmap.ts` (módulo `0.8`); el detalle de
historias/DoD/gates vive aquí. Al cerrar una fase, actualiza su `status` en
`roadmap.ts` y marca su DoD en este doc.
