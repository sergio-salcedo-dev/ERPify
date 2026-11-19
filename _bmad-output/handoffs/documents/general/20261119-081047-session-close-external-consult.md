# Handoff — Documents epic (#268): decisions recorded, external consultation pending

```
/bmad-agent-architect continue the Documents epic (#268) decision round: critique the owner's ChatGPT answer to the consultation prompt in the appendix of this handoff, then record the final decisions in the doc-8 plan (as section 11) and apply them to the specs.

BRANCH: ccr-38e5efdc-b2nhi8 (PR #1268, open, docs only). PR #1267 (sections 1–8 of the plan) is merged on main.

DONE:
- Plan for doc-8 written, adversarially reviewed (2 GRAVE, 3 MEDIUM, 6 MINOR, all applied) and approved by the owner:
  `_bmad-output/handoffs/documents/doc-8/20261119-072425-doc-8-plan.md`.
  Sections: 2 (doc-8 decisions D-a..D-l), 6 (owner-approved recommendations), 7 (new story doc-11),
  8 (memlog lines + stories.yaml re-derivations for doc-8/doc-9), 9 (rest of the epic: order, doc-10 deferred
  with its shape, doc-12 drafted), 10 (#718 policy and doc-12 decisions 1–7, after two emulated architect rounds).
- Issue #268 body updated with status, order and the new stories.
- Nothing implemented. No PHP/PWA gate was run (cloud container: no Docker, PHP 8.3).

IN PROGRESS:
- External second opinion: the owner is consulting ChatGPT with the prompt in the APPENDIX below (it lived in
  gitignored tmp/ and is reproduced here so it survives). Its answer has not come back yet.
- Third architect round, NOT yet recorded in the plan and awaiting the owner's approval (see DECISIONS).

NEXT:
1. Paste ChatGPT's answer using the PART 2 wrapper of the appendix; critique it against the rubric and the tree
   (verify claims in code); propose the final decision per item A1..H.
2. Record the outcome as section 11 of the doc-8 plan; push to PR #1268 (do not merge without the owner's OK).
3. Locally (needs `_bmad/scripts/memlog.py`, absent from cloud containers): apply plan sections 8–11 to
   `_bmad-output/specs/spec-documents-context/` (memlog lines; stories.yaml: re-derive doc-8 and doc-9, remove
   doc-10, add doc-11 and doc-12 with decisions 2–7 of section 10; SPEC.md: CAP-8 to deferred) and, if ic-5 is
   retired, to `_bmad-output/specs/spec-images-consumers/`. Check stories.yaml: 11 entries, unique quoted ids, no
   status field. Mark `stories/doc-7-erasure-by-key-destruction.md` status done (#1264 merged).
4. If ic-5 is retired: remove its code residue in the same PR, with gates (php.quality, pwa.quality).
5. Then: doc-8 via `bmad-loop run --spec _bmad-output/specs/spec-documents-context` from a worktree; #718 as its
   own small PR in parallel (owes the three review layers; remove its §7 accepted-risk row before "closes #718").

ARTIFACTS (re-read):
- `_bmad-output/handoffs/documents/doc-8/20261119-072425-doc-8-plan.md` (the whole decision record).
- `_bmad-output/specs/spec-documents-context/SPEC.md`, `evidence-lifecycle.md`, `stories.yaml`.
- `_bmad-output/specs/spec-images-consumers/SPEC.md` (open question l.84), `stories.yaml` (ic-5).
- `docs/adr/images-vs-documents-conservation-contract.md` (D3, D4, D5), `docs/adr/documents-custody-log.md` (D6, D8, D11),
  `docs/adr/image-deletion-signal-transport.md`, `docs/runbooks/documents-restore-proof.md`.

KEY PATHS:
- `api/src/Shared/Images/` (Image, UploadImage, ImageAttachment, CanonicalImageFinder, ImageGetController).
- `api/src/Documents/Document/Application/Erasure/` (EraseDocument, ApplyErasure, ReplayErasure, DeleteErasedObjects),
  `api/src/Documents/Document/Infrastructure/Persistence/Doctrine/DbalDocumentErasures.php`,
  `api/src/Documents/Document/Domain/Erasure/ErasureLimits.php`,
  `api/src/Documents/Document/Infrastructure/Messenger/Maintenance/DocumentsMaintenanceSchedule.php`.
- `api/src/Backoffice/BankAccount/Application/EraseBankAccountSubject.php` (l.75 remove($account)).
- `api/src/Shared/Audit/Domain/AuditErasureEvidence.php`, `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php`.
- ic-5 residue: `api/frankenphp/Caddyfile` (@imageWrite l.186, l.208), `api/tests/Unit/Gate/UploadRoutePathAgreementGateTest.php`
  (l.24, l.184), `api/config/packages/rate_limiter.yaml` (l.203), `docs/api-error-contract.md` (l.287),
  `docs/architecture-pwa.md` (l.63), `docs/deployment-guide.md` (l.35), `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts`
  (l.120), `pwa/src/app/backoffice/roadmap/_lib/roadmap.ts` (l.557).

DECISIONS/CONSTRAINTS:
- Owner-approved and recorded: everything in plan sections 2, 6, 9, 10 (doc-8 design; doc-9 series row, RBAC,
  uploader_id hidden in the projection; doc-10 out of the epic; doc-11; doc-12 with a separate
  BankAccountEvidence link table, retention fixed per kind and verified server-side, 409 on deleting an account
  with live evidence, ADMIN only, a published Documents read port; #718 closed by nulling ip/user_agent past the
  row's own level threshold inside the pruner).
- Third round, recommended by the assistant + emulated architect, NOT yet approved or recorded:
  (1) retire ic-5 from spec-images-consumers, with its code residue cleaned in the same PR; doc-12 drops the
  image-control warning but records the reopening condition (first screen offering both image and evidence) in
  ADR D3 and SPEC l.65; (2) order: one spec PR (sections 8–11 + ic-5 retirement + doc-12 entry + doc-7 done) →
  doc-8 with #718 in parallel → doc-9 → the counting restore drill → open the switches → doc-12 → doc-11; second
  ledger host and an unrecorded runbook rehearsal now; (3) the counting drill goes AFTER doc-9 (doc-8 and doc-9
  change the replay; the proof record is bound to no code version) and the record gains a `code_ref` (commit)
  field — changes if a go-live date precedes doc-9.
- The architect consultations in this session were EMULATED (official bmad-agent-architect definition from
  bmad-method 6.12.4, no `_bmad/custom/` overrides); the real one can be run locally.
- Hard constraints: `.memlog.md` is written only through memlog.py, never by hand; bmad-build-auto reads only the
  stories.yaml entry, so decisions must be re-derived there; never merge into main without per-merge permission.
```

## Appendix — external consultation prompt (copied from the gitignored scratch file)

# Consulta externa — decisiones de la épica Documents (#268) de ERPify

> Fichero temporal (`tmp/` está en `.gitignore`). Borrar cuando la decisión esté tomada.
> Fuente completa en el repo: `_bmad-output/handoffs/documents/doc-8/20261119-072425-doc-8-plan.md` (secciones 2, 6–10).

---

## PARTE 1 — copiar esto en ChatGPT

Eres un arquitecto de software sénior. Da una recomendación razonada sobre cada decisión de abajo. Responde en
español.

### Contexto (no tienes acceso al repositorio; todo lo necesario está aquí)

**Producto.** ERPify, un ERP para el sector de la construcción (pymes españolas). Monorepo con una API Symfony 8 /
PHP 8.5 sobre FrankenPHP, PostgreSQL 18, Doctrine ORM, Symfony Messenger (transporte Doctrine), y una PWA Next.js 16.
Despliegue en **un único VPS con Docker Compose**. **No hay producción todavía** ni clientes.

**Reglas de arquitectura del repo (no negociables salvo argumento):**
- DDD + hexagonal: `Domain/` sin frameworks; adaptadores en `Infrastructure/`; orquestación en `Application/`.
- **Ningún grafo de objetos cruza un módulo**: un agregado referencia a otro por id (UUID v7), nunca con
  `ManyToOne`. Las FK físicas se inyectan aparte con un listener de esquema.
- `Shared/` nunca importa un contexto de negocio; los contextos sí importan `Shared/`. Un seam publicado entre
  contextos va en un allowlist explícito.
- Persistencia **por estado** por defecto; event sourcing solo por agregado y con argumento.
- Mínimo código que resuelva el problema; Rule of Three; "boring technology"; criterio de largo plazo, no tamaño
  del diff.
- Fuerte cultura de "gates": tests que leen el código y fallan si se rompe una regla (registros de quién escribe
  cada tabla, quién publica cada evento, qué columnas guardan ids de personas, etc.).

**El contexto `Documents` (ya construido, doc-1…doc-7).** Guarda binarios cuya **evidencia es el byte original**
(planos, certificados, contratos). Distinto del módulo `Shared/Images`, que recodifica y quita metadatos a propósito
(imágenes "fungibles": logos, avatares).
- Cada documento se cifra con su propia clave (DEK) envuelta por una KEK de entorno (`AUDIT_KEK`). **Borrar un
  documento = destruir su clave** (crypto-shredding); quedan digest, tamaño y custodia como lápida.
- Estados: `Received → Scanning → Ready | Quarantined | ScanFailed`, y `Erased` desde cualquiera (terminal). Las
  transiciones son compare-and-set SQL. Solo `Ready` es legible.
- Escaneo antivirus con un sidecar `clamd` sin secretos: PHP descifra y envía en streaming; nada en claro en disco
  (tmpfs, sin swap, sin core dumps).
- Log de custodia **append-only** (`document_custody`).
- **Ledger de borrados** en un segundo host (restic append-only): el borrado escribe el id en el ledger ANTES de
  destruir la clave, para que una restauración de un backup antiguo (pg_dump offsite, ~3 meses) vuelva a destruir
  las claves al reaplicar el ledger.
- La transacción de borrado tiene un presupuesto medido: 10 sentencias, 3 bloqueos → un mínimo de 185 s para el
  barrido de completado (un test exige < 300 s). Orden global de bloqueos documentado (intent → document → keystore).
- Interruptores `documents.ingest_enabled` y `documents.read_enabled` **cerrados en producción** hasta que exista un
  registro de prueba de restauración y un simulacro con reaplicación del ledger.
- Lectura y metadatos: **solo ADMIN** (autorización RBAC por permisos; el voter nunca mira el objeto concreto).
- **Hoy Documents no tiene consumidor**: ningún contexto de negocio lo usa y no hay pantallas en la PWA.

**Mensajería.** Reintentos `async`: 10 intentos, delay 1 s × 3^n con tope 1 h → horizonte ≈ 2,9 h (≈ 3,2 h con
jitter). Luego cola `failed` 30 días. Un "outbox" exige transacción abierta para publicar.

### Decisiones a evaluar

Para cada una se indica la opción elegida (★) y las descartadas.

**A. doc-8 — derivados dependientes (previews) a través de `Shared/Images`**

- **A1. Productor real.** ★ doc-8 entrega solo la costura y el ciclo de vida (origen en `Image`, liberación,
  barridos); el primer productor real (preview de PDF) va en una historia posterior (doc-11). Coste aceptado: hasta
  entonces no protege nada en producción. ✗ Construir ya un productor (rasterizador PDF).
- **A2. Origen.** ★ VO `ImageOrigin` = enum cerrado de contexto (hoy solo `Documents`) + UUID; columnas nullable con
  CHECK "ambas o ninguna" e índice parcial. ✗ columna `document_id` (pondría un nombre de negocio en `Shared`).
- **A3. Quién libera.** ★ Documents (dueño del ciclo de vida) con ticks en su schedule de mantenimiento ya existente
  (sin tocar compose). ✗ schedule propio de Images (necesitaría un puerto Images→Documents y montar storage).
- **A4. Enganche al borrado.** ★ Fuera de la transacción de borrado: marcador `derivatives_released_at` en
  `document`, como el que ya existe para los ficheros (`object_deleted_at`). ✗ publicar dentro de la transacción
  (rompe el presupuesto 10/3/185 s y el orden de bloqueos); ✗ post-commit en el use case (no cubre la reaplicación
  tras restore).
- **A5. Materialización serializada con el borrado.** ★ Puerto `DerivativeOriginLock` declarado en `Shared/Images`
  e implementado por Documents (DIP). Flujo: pre-comprobación sin bloqueo de `Ready` → procesar y guardar bytes fuera
  de transacción → transacción con `SELECT … FOR SHARE` sobre el documento, exige `Ready`, inserta la fila `image` →
  si no, borra los bytes y rechaza. ✗ commit de la imagen y comprobación en otra transacción (una revisión
  adversarial encontró una carrera: el barrido no ve la fila sin confirmar bajo READ COMMITTED, marca liberado, y un
  crash entre pasos deja un derivado eterno sin alarma).
- **A6. Escritor/publicador.** ★ Servicio `DependentImages` (materialise, idsOf, origins, release) como segundo
  escritor de `Image` y segundo publicador de `ImageDeletionRequested`, registrados en sus gates. ✗ reutilizar
  `ImageAttachment` (diseñado para un holder y una imagen).
- **A7. Lectura.** ★ La ruta canónica `GET /api/v1/images/{id}` (sin voter; cualquier sesión autenticada) responde
  404 a toda imagen con origen; las previews serán legibles solo por una ruta de Documents con voter + `Ready` +
  auditoría, que llega con doc-11.
- **A8. Liberación y cotas.** ★ Primera liberación paginada de todos los ids; re-liberación cada 4 h (> horizonte de
  reintentos) con compare-and-set en la misma transacción que la publicación; alarma a las 8 h; ambas cotas derivadas
  por un test de la configuración de reintentos (jitter incluido).
- **A9. Barrido de huérfanos.** ★ Segundo tick que recorre los orígenes presentes en `image` y libera los de
  documentos `Erased`, ausentes o no `Ready`.
- **A10. Alarma.** ★ Una línea `error` en el canal de observabilidad, cuenta + hasta 20 ids **de documento** (nunca
  de imagen: una imagen puede ser una cara).
- **A11. Restore.** ★ La sentencia que ya reabre `object_deleted_at` en la reaplicación reabre también los
  marcadores de derivados (sin sentencia nueva).
- **A12. Nombre del módulo.** El ADR preveía renombrar `Images` → `Renditions` cuando otro contexto materializara a
  través de él. ★ Mantener `Images` mientras toda rendición sea imagen ráster, con condición de reapertura explícita
  (primera rendición no-imagen). ✗ renombrar ya (refactor mecánico amplio sin cambio de comportamiento).

**B. doc-9 — versionado de documentos (una versión = nuevo original inmutable enlazado a su predecesor; cadena
lineal; los nodos borrados quedan como lápidas legibles)**

- **B1. Exclusión de dos sucesores vivos.** ★ Fila de serie con puntero a la cabeza, movido por un `UPDATE` bajo el
  bloqueo de esa fila. Medido sobre las 5 transiciones que ocupan o liberan el hueco: +1 bloqueo constante (solo en
  sucesores) → el presupuesto de borrado sigue siendo calculable (~210 s); `series_id` inmutable, sin re-chequeos ni
  reintentos. La cabeza = el ancestro no borrado más cercano, recalculado con una lectura recursiva sin bloqueos bajo
  el lock de serie. ✗ Flag `holds_slot` + índice único parcial + recorrido bloqueado de antecesores borrados en cada
  transición (bloqueos no acotados en el borrado, deadlock real si no se ordenan por id, escribe filas `Erased` desde
  transacciones ajenas).
- **B2. Autorización.** La spec pedía "un voter sobre el predecesor". ★ Mantener RBAC (`document.write`); un
  predecesor ausente responde igual que uno no `Ready`. ✗ autorización por fila (otro modelo, otro ADR).
- **B3. Lápida y persona.** La spec dice que una lápida no guarda "nada que nombre a una persona", pero conserva
  `uploader_id`. ★ No reescribirlo al borrar el documento (borrar un documento no es borrar a una persona, y esa
  columna ya tiene dueño: la purga de la persona); la proyección de la serie omite el uploader de un nodo `Erased`.
  ✗ reescribir `uploader_id` a un marcador en el compare-and-set de borrado.

**C. doc-10 — OCR de PDFs escaneados con búsqueda (CAP-8)**

- **C1.** ★ **Sacarlo de la épica** como aplazamiento argumentado: no hay consumidor. Queda registrada su forma:
  - búsqueda = descifrar y recorrer en consulta sobre candidatos acotados por un filtro de metadatos obligatorio,
    tope duro ~2.000, latencia como contrato medido; consulta en el cuerpo de un POST; respuesta solo ids, sin
    fragmentos; una fila de auditoría por búsqueda; solo ADMIN;
  - ✗ índice ciego HMAC por documento (puerto criptográfico nuevo, solo tokens exactos, recall pobre con ruido OCR);
    ✗ índice en claro UNLOGGED excluido del dump (`pg_dump` vuelca datos UNLOGGED por defecto; además contradice la
    regla decidida "sin índice en claro"); ✗ Tika (JVM de 1–2 GB);
  - runtime: OCR en un sidecar sin secretos (poppler + Tesseract), nunca en el worker PHP que tiene la KEK;
  - estado en una tabla `document_text` aparte, sin leases; se retira con un barrido sobre `Erased`;
  - reabre: un caso de uso con nombre y un tamaño de corpus.

**D. doc-11 (nueva) — productor de previews.** ★ Construye su propio sidecar de renderizado (poppler, sin secretos,
sin salida a red, tmpfs, imagen fijada por digest), que doc-10 reutilizaría; añade la ruta de lectura de previews de
Documents. Depende de doc-8 y doc-12.

**E. doc-12 (nueva) — primer consumidor: evidencia de cuenta bancaria + PWA**

- **E1. Persistencia.** ★ Por estado, tabla de vínculo aparte `BankAccountEvidence(id, bank_account_id,
  document_id, kind, attached_at)`, `UNIQUE(bank_account_id, document_id)`, FKs sin cascada. Sin `attached_by`.
  ✗ colección de ids dentro del agregado `BankAccount` (sin FK posible; ensucia su auditoría de cambios; no hay
  invariante de conjunto). ✗ event sourcing.
- **E2. Retención.** ★ La fija el consumidor por `kind` (certificado de titularidad: `contract-execution` +
  `legitimate-interests` como base de prevención del fraude, pendiente del asesor legal); el servidor verifica que el
  documento la lleva y responde 422 si no. ✗ que la elija la UI.
- **E3. Control contra archivar en el contrato equivocado.** ★ doc-12 lo decide al ser la primera UI de evidencia:
  etiquetas y ayudas distintas; la cuestión se reabre en la primera pantalla que ofrezca a la vez imagen y evidencia.
- **E4. Borrar una cuenta con evidencia viva.** ★ 409 mientras quede un documento vinculado no `Erased`; con todos
  borrados, se quitan vínculos y cuenta en la misma transacción. (Sin esto, el borrado GDPR del titular daría 500 por
  la FK, o con cascada el certificado con nombre e IBAN sobreviviría al borrado.)
- **E5. Permisos.** ★ Solo ADMIN; el primer usuario no ADMIN reabre la decisión con su ADR.
- **E6. Mostrar nombre y estado.** ★ Puerto de lectura publicado por Documents (los nombres están cifrados; un JOIN
  no sirve), declarado en el allowlist de seams.

**F. #718 — retención de IP/user-agent en filas de evidencia GDPR**

Las filas de auditoría que prueban un borrado GDPR están exentas de la poda de 365 días para siempre (un
reconciliador las cruza sin límite de fecha con lápidas de claves eternas). Por eso la IP y el user-agent del
administrador que borró se guardan indefinidamente. Se descartaron antes: (a) un plazo propio solo para una de las
dos acciones (parte una regla en dos); (b) no guardar IP/UA al escribir (quita atribución).
- ★ Conservar la fila y `actor_id` para siempre; **vaciar `ip`/`user_agent` al superar el umbral del nivel de la
  fila** (hoy 365 días), con una segunda sentencia `UPDATE` dentro del podador existente, bajo su mismo advisory
  lock, bloqueando en orden ascendente por id; escribe `NULL`. ✗ dejarlo como "vigilancia" indefinida (su disparador
  exige SQL manual en producción y nadie lo mira).

**G. ic-5 — "imagen de la cuenta bancaria" (otra spec, `spec-images-consumers`)**

Pregunta abierta: ¿qué representa? (tarjeta, cheque, logo o titular). ★ **Retirarla**: tarjeta/cheque son medios de
pago que la ruta compartida serviría a cualquier sesión; el logo es del banco (otra historia); el titular es una
persona sin necesidad de negocio; la prueba de titularidad es evidencia (doc-12). Hay que limpiar código ya entregado
que la anticipaba: un matcher del proxy (Caddy) que admite 20 MiB en una ruta inexistente, un test-gate que la fija
como "prevista" y prosa en seis ficheros.

**H. Orden y proceso**

- ★ Orden: (1) un solo PR que aplica a la spec todas las decisiones anteriores + retira ic-5 con su limpieza →
  (2) doc-8, con #718 en paralelo (no comparten ficheros) → (3) doc-9 → (4) el simulacro de restauración que cuenta
  → (5) abrir interruptores → (6) doc-12 → (7) doc-11. El segundo host del ledger y un ensayo del runbook sin
  registro, desde ya.
- ★ El simulacro "que cuenta" **después** de doc-9, porque doc-8 y doc-9 cambian la reaplicación tras restore y el
  registro de prueba no está ligado a ninguna versión del código; añadir un campo `code_ref` (commit) al registro.
  ✗ hacer el simulacro primero.

### Rúbrica

Justifica contra cada principio **que aplique** y di cuáles no aplican y por qué: **Clean Architecture, Clean Code,
SOLID (sobre todo SRP y DIP), Tell-Don't-Ask, Law of Demeter, DRY, KISS, YAGNI.** Ten en cuenta que es un ERP sin
producción todavía y un equipo pequeño: señala dónde el diseño está sobredimensionado y dónde se queda corto.

### Formato de respuesta (obligatorio)

Para **cada** decisión (A1…H):
1. Veredicto en una línea: *de acuerdo* / *de acuerdo con matiz* / *en desacuerdo* (y tu alternativa).
2. Razonamiento por principio relevante (breve).
3. Trade-offs y riesgos.

Después, una vez para todo el conjunto:
4. **Las 3 decisiones que más cambiarías**, ordenadas por impacto.
5. **Riesgos que nadie ha mencionado.**
6. **Dónde podrías estar equivocado / qué necesitarías verificar** (sección explícita de autocrítica: no tienes el
   código, así que di qué supuestos haces).

Sé conciso y concreto; sin relleno.

---

## PARTE 2 — pegar aquí la respuesta

```
I consulted an external AI about: todas las decisiones de la épica Documents (#268), #718, ic-5 y el orden (plan doc-8, secciones 2 y 6–10). Its answer:
<<<
[pega aquí la respuesta de ChatGPT]
>>>
Critique and debate this against Clean Architecture, SOLID, Tell-Don't-Ask, Law of Demeter,
DRY/KISS/YAGNI and this repo's real constraints. Say where it's right, where it's wrong, and
propose the final decision with your reasoning.
```
