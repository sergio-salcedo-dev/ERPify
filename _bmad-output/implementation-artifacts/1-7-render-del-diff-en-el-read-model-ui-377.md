# Story 1.7: Render del diff en el read model + UI #377 (solo `Bank`)

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **administrador / investigador de cumplimiento**,
quiero **ver las altas/ediciones/borrados de `Bank` con su diff campo a campo (antes/después) en el timeline de auditoría**,
para **investigar qué cambió y de qué valor a qué valor — no solo navegación**.

> **Origen:** Epic 1 de `_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md`. La captura de escrituras (1.1–1.6, PRs #394/#398/#399) ya escribe filas `level=change` en `audit_log` con `action` semántica y diff en `metadata` (JSONB) para `Bank`; esta story las hace **visibles** end-to-end.

### Scope — solo `Bank` (decisión de arquitectura)

`Bank` es una **institución financiera** (BBVA, Santander): dato de referencia, **no-PII** → su diff se muestra **en claro**. La captura y el render de **`BankAccount` quedan FUERA** de esta story: su diff lleva PII (`holderName`/`iban`) y debe auditarse **crypto-shredded** (ADR D10–D17), trabajo que vive en **Epic 2** (stories 2.1–2.4), no aquí. No cablees `AuditedEntity` en `BankAccount` en esta story.

### Decisiones tomadas (ADR `regulatory-audit-trail.md` + sesión de arquitectura)

1. **Exposición del diff = recurso canónico `GET /api/v1/backoffice/audit/events/{id}`** (D-A). El **listado** keyset (`GET /audit/timeline`) queda **slim** (sin `metadata`). El detalle es un **recurso independiente del timeline** (un *audit event* existe por sí mismo, no es "el detalle del listado"); devuelve la fila completa con el diff (`metadata.changes`). Payload **diff-only**: `ip`/`user_agent` siguen dormidos hasta auth (la ruta es pública en E1 e `ip` es PII). Imita `BankGetController`/`BankAccountGetController`.
2. **Pulido UX del nivel `change` completo + UX del diff** (D-B): badge "Cambio" + acento lateral propio, segmento "Cambios" en el filtro, etiquetas curadas en español (`humanizeAuditAction`), y un componente de diff con **color+texto para añadido/eliminado/modificado** (no solo before/after), **colapso de diffs muy grandes**, e **indicador de tipo de campo**, todo escapado.

## Acceptance Criteria

**AC1 — Recurso de detalle expone el diff (FR16, D-A).**
Given el read model de `Backoffice/Audit` (timeline 4.1),
When se consulta `GET /api/v1/backoffice/audit/events/{id}` para una fila de escritura de `Bank`,
Then la respuesta incluye la `action` semántica (`BANK_CREATED`/`BANK_UPDATED`/`BANK_DELETED`) **y el diff campo a campo** (`metadata.changes`, forma `{field: {old, new}}`); el **listado** slim no cambia (las filas `change` ya salen ahí con su `action`).

**AC2 — La UI #377 muestra el diff escapado (FR16, `docs/rules/security.md`, D-B).**
Given la UI de investigación,
When se abre el drawer de una fila `level === 'change'`,
Then muestra el diff `antes`/`después` por campo con **canal de color + texto** para added/removed/changed, **colapso** de diffs grandes e **indicador de tipo de campo**, **escapando todo valor no confiable** (texto React, **sin** `dangerouslySetInnerHTML`/`innerHTML`); el diff de `Bank` se muestra en claro.

**AC3 — Nivel `change` distinguible y filtrable (D-B).**
Given el timeline,
When se listan filas `change`,
Then llevan el badge "Cambio" (con acento lateral propio, distinto de `security`), etiqueta de acción en español, y existe el segmento de filtro "Cambios".

**AC4 — Documentación ISO base actualizada (FR17).**
Given el cierre de esta rebanada de E1,
When se completa la story,
Then `docs/rules/security.md` y `PRODUCTION_SECURITY_CHECKLIST.md` reflejan el mapeo ISO 27001:2022 base (A.8.15 append-only + protección, A.8.17 clock sync) y `docs/architecture-api.md` describe el emisor de escritura **y** la superficie de lectura de detalle que expone el diff.

## Tasks / Subtasks

### A. API — Recurso de detalle `GET /audit/events/{id}` (AC1)

> Sin migración: `metadata JSONB NOT NULL` ya existe (`api/migrations/2026/Version20260623164321.php`).

- [ ] **A1.** Read model: `api/src/Backoffice/Audit/Domain/AuditEventDetail.php`
  - [ ] `final readonly` = los 10 campos slim de `AuditTimelineEntry` **+ `array $metadata`** (diff decodificado). `level`/`actorType` como **strings crudos** (fidelidad forense — nunca enums, nunca throw sobre token inesperado; mismo criterio que `AuditTimelineEntry`).
- [ ] **A2.** Puerto (ISP): `api/src/Backoffice/Audit/Domain/Repository/AuditEventDetailRepository.php`
  - [ ] Puerto hermano (no mezclar con el de listado): `findById(string $id): ?AuditEventDetail`. El contrato `search(SearchCriteria): Page` del puerto de listado queda intacto. El mismo adapter DBAL implementa ambos.
- [ ] **A3.** Adapter DBAL: implementar `findById` en `DbalAuditTimelineRepository`
  - [ ] `SELECT <cols slim>, a.metadata FROM audit_log a WHERE a.id = CAST(:id AS UUID)`; hidratar con `json_decode($row['metadata'], true)`. Reusar los guards `requiredString`/`optionalString`/`requiredBool`.
  - [ ] **NO tocar el SELECT/orden del listado keyset** (`(occurred_on, id)`) ni el fingerprint del cursor — método by-id nuevo, sin paginación.
- [ ] **A4.** Finder: `api/src/Backoffice/Audit/Application/AuditEventDetailFinder.php`
  - [ ] Handler lean `find(string $id): AuditEventDetail` (espejo de `BankDetailFinder`); lanza `AuditEventNotFound` si el puerto devuelve null.
- [ ] **A5.** DTO por vista: `api/src/Backoffice/Audit/Application/Resource/AuditEventDetailResource.php`
  - [ ] Campos slim + `array $metadata` (diff estructurado). Solo escalares + array, **nunca** la entidad. **Sin `ip`/`userAgent`** (diff-only).
- [ ] **A6.** Mapper: `api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php`
  - [ ] `AuditEventDetail` → `AuditEventDetailResource`. Reusar el formato de instante a microsegundos del mapper de listado (`Y-m-d\TH:i:s.uP`).
- [ ] **A7.** Controller: `api/src/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailController.php`
  - [ ] `#[Route('/audit/events/{id}', methods: ['GET'])]` (auto-discovery bajo `src/Backoffice/`, prefijo `/api/v1/backoffice` — **sin** editar config de rutas).
  - [ ] `Uuid::ensure($id)` (`Shared/Uuid/Domain/Uuid`) **antes** de cualquier lookup → 400 `invalid-uuid`; id válido-pero-ausente → 404. Delegar a finder + mapper + `ResourceResponder` (patrón `BankGetController`).
  - [ ] **Ruta pública consciente** (no hay voter/firewall — el gate RBAC pre-prod es D8/E3, no se resuelve aquí). Declararlo en el PR.
  - [ ] **No** añadir default `_audit_resource_type`: evita que el `AccessLogAuditListener` audite la lectura de la auditoría (ruido recursivo; la auto-auditoría real es D8/E3).
- [ ] **A8.** Marker 404: `api/src/Backoffice/Audit/Domain/Exception/AuditEventNotFound.php`
  - [ ] Espejo de `Bank/Domain/Exception/BankNotFoundException`; mapea a 404 vía el pipeline RFC 9457. **Actualizar `docs/api-error-contract.md`** (NFR26).
- [ ] **A9.** Tests (AC1)
  - [ ] Unit: `api/tests/Unit/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapperTest.php` — fija lista+orden de claves del DTO (estilo `AuditTimelineResourceMapperTest`); el diff pasa sin alterar.
  - [ ] Funcional (Postgres real, `inRolledBackTransaction`): `api/tests/Functional/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailFunctionalTest.php` — happy path devuelve el diff de un `BANK_UPDATED`; **400 invalid-uuid** (id basura) vs **404** (uuid válido ausente); forma `application/problem+json`.
  - [ ] Funcional: extender `DbalAuditTimelineRepositoryTest` para `findById` (hidratación del `metadata` decodificado; fila no-change → `metadata` `{}`/vacío).
  - [ ] Behat: `api/features/backoffice/audit/event_detail.feature` — GET by id devuelve el diff; id manipulado → 422/`invalid-uuid`; ausente → 404.
  - [ ] **Regresión:** `api/features/backoffice/audit/timeline.feature` `data[*] should have 10 children` **NO cambia** (detalle, no fila gorda).

### B. PWA — Render del diff + pulido del nivel `change` (AC2, AC3)

> Convenciones: BEM + `cn()` (`@/components/cn`), Tailwind 4, **sin default exports bajo `src/context/**`**, `data-testid` únicos, **sin `maxLength`**.

- [ ] **B1.** Dominio: `pwa/src/context/backoffice/audit/domain/AuditEntry.ts`
  - [ ] Añadir `'change'` al const `AuditLevel` + `isAuditLevel`.
  - [ ] Tipos del diff: `AuditFieldChange = { old: AuditScalar | null; new: AuditScalar | null }`, `AuditChanges = Record<string, AuditFieldChange>`, y `AuditEventDetail` (campos slim + `changes: AuditChanges`). (Pueden ir en `AuditChange.ts`.)
- [ ] **B2.** Detalle: puerto + adapter + hook + endpoint
  - [ ] Puerto: `domain/AuditEventDetailRepository.ts` (`findById(id): Promise<AuditEventDetail>`).
  - [ ] Adapter: `infrastructure/ApiAuditEventDetailRepository.ts` con guard de frontera `isAuditEventDetailResponse` (valida la forma del diff; descarta campos extraños — espejo de `isAuditEntry`).
  - [ ] Hook: `application/useAuditEventDetail.ts` — fetch del detalle por id al abrir un row (read-only). Fire-and-forget con block body (evita Sonar S3735/S6544).
  - [ ] Endpoint: `context/shared/http-client/infrastructure/ApiEndpoints.ts` — `BACKOFFICE.AUDIT.EVENT_DETAIL(id)` con `encodeURIComponent(id)`.
  - [ ] DI: binding del nuevo repo en el `Container.ts` del contexto audit.
- [ ] **B3.** Componente del diff: `pwa/src/context/backoffice/audit/infrastructure/ui/AuditChangeDiff.tsx` (NUEVO)
  - [ ] Render por campo `etiqueta → old → new` sobre `metadata.changes`. Estructura semántica: `<dl>` o `<table>` con `<th scope="row">` (no divs estilados).
  - [ ] **Tres estados con canal NO-color** (WCAG 1.4.1): **changed** (`old`→`new`), **added** (`old===null`, marcador "+"/"Añadido"), **removed** (`new===null`, marcador "−"/"Eliminado"). El color refuerza, nunca señaliza solo. *(D-B)*
  - [ ] **CREATE/DELETE = snapshot completo**: cabecera que nombre la forma ("Estado inicial" en CREATE, "Estado final antes del borrado" en DELETE).
  - [ ] **Colapso** de diffs muy grandes (umbral de campos/longitud → "ver N campos más") para no romper el layout. *(D-B)*
  - [ ] **Indicador de tipo de campo** junto a la etiqueta (p.ej. el campo crudo + su tipo). *(D-B)*
  - [ ] Valores: `null` → sentinel `— (vacío)`, **distinto** de `""`. Valores largos → `<TruncatedText>` (full string en el DOM) + `<CopyButton>`. Enums/fechas verbatim (el servidor ya escalarizó). Mantener la clave de campo cruda visible (fidelidad forense).
  - [ ] **SEGURIDAD (AC2, load-bearing):** todo valor es texto no confiable (un nombre de banco editable). Renderizar como hijo de texto React (auto-escapado). **PROHIBIDO** `dangerouslySetInnerHTML`/`innerHTML`/`eval`. Espejo de `MetadataBlock.tsx`. Ningún valor alimenta `href`/`src`.
- [ ] **B4.** Humanización de campos: `pwa/src/context/backoffice/audit/application/humanizeAuditField.ts` (NUEVO)
  - [ ] `Record<string,string>` curado + fallback title-case (espejo de `humanizeAuditAction`). `Bank`: `name→"Nombre"`, `shortName→"Código"`. Conservar la clave cruda visible.
- [ ] **B5.** Acciones en español: `pwa/src/context/backoffice/audit/application/humanizeAuditAction.ts`
  - [ ] Añadir a `CURATED_LABELS`: `BANK_CREATED→"Banco creado"`, `BANK_UPDATED→"Banco actualizado"`, `BANK_DELETED→"Banco eliminado"`. *(las variantes `BANK_ACCOUNT_*` llegan con E2.)*
- [ ] **B6.** Badge: `infrastructure/ui/AuditLevelBadge.tsx` — tono CVA `change` + etiqueta "Cambio" (label de texto, no solo color).
- [ ] **B7.** Tabla: `infrastructure/ui/AuditTimelineTable.tsx` — acento lateral para `change` (token distinto de `security`).
- [ ] **B8.** Filtro: `app/backoffice/audit/_lib/auditFilter.ts` — segmento "Cambios" en `AUDIT_LEVEL_SEGMENTS`.
- [ ] **B9.** Drawer: `infrastructure/ui/AuditEntryDrawer.tsx` — sección "Cambios" **encima** de "Metadata", renderizando `<AuditChangeDiff>` para entradas `change`; cablear la fuente del diff. `MetadataBlock` permanece para metadata no-diff; `ip`/`userAgent` siguen dormidos.
- [ ] **B10.** Pantalla: `app/backoffice/audit/_components/AuditInvestigationScreen.tsx` — suministrar el detalle al drawer vía `useAuditEventDetail` (la línea que hoy omite `detail`).
- [ ] **B11.** Tests PWA (AC2/AC3)
  - [ ] NUEVO `AuditChangeDiff.test.tsx`: render `campo / old → new`; **escapa texto** (valor con `<script>` → texto, no ejecuta); added/removed/changed; null↔value; snapshot CREATE/DELETE; colapso.
  - [ ] NUEVO `humanizeAuditField.test.ts`; NUEVO `ApiAuditEventDetailRepository.test.ts` (guard drift table); NUEVO `useAuditEventDetail.test.tsx`.
  - [ ] EXTENDER `AuditEntryDrawer.test.tsx` (diff para entrada `change`; conteo de secciones dormidas), `AuditLevelBadge.test.tsx` (tono `change`), `humanizeAuditAction.test.ts` (labels `BANK_*`).
  - [ ] e2e `pwa/tests/e2e/audit-diff.spec.ts`: abrir una fila `BANK_UPDATED`, ver el diff por campo. (Gotchas: stack HTTPS live, cert self-signed, localizar por testid del recurso, DB compartida → asertar presencia.)

### C. Docs (AC4 / FR17)

- [ ] **C1.** `docs/rules/security.md` — mapeo ISO base (A.8.15 append-only + protección, A.8.17 clock) y la postura XSS del render del diff (texto escapado, metadata tainted).
- [ ] **C2.** `PRODUCTION_SECURITY_CHECKLIST.md` — párrafo de filas `change`/diff (solo `Bank`, no-PII en esta rebanada); ruta de detalle pública (gate RBAC pre-prod).
- [ ] **C3.** `docs/architecture-api.md` — endpoint de lectura de detalle `/audit/events/{id}` que expone el diff; `Bank` como agregado auditado con render.
- [ ] **C4.** `docs/api-error-contract.md` — `AuditEventNotFound` → 404 (NFR26).
- [ ] **D.** **Barrido final:** eliminar de `src`/tests tocados cualquier comentario con ID de story/AC/FR/NFR; `make php.quality` + `make pwa.quality` + tests antes del commit final.

## Dev Notes

### Estado actual (verificado)

- **Productor (1.1–1.6, MERGED):** `AuditWriteCaptureListener` (onFlush) escribe filas `level=change` **síncronas en la transacción del flush**; el diff lo produce `AuditChangeDiff::of()` → `{changes: {field: {old, new}}}` en `audit_log.metadata` (JSONB). Solo `Bank` implementa `AuditedEntity` (`Bank.php:142-154`: `auditResource()`/`auditAction()` → `BANK_CREATED/UPDATED/DELETED`). El diff de `Bank` = cambios en `name`/`shortName` (no-PII → claro).
- **Read model de listado (4.1, #374) slim:** `AuditTimelineEntry`/`AuditTimelineResource`/el `SELECT` omiten `metadata` ("detail-only"). **No hay filtro de nivel** → las filas `change` ya salen en el listado con su `action`. Falta el **diff**, que viaja por el nuevo detalle.
- **No existe endpoint de detalle.** El drawer PWA (`AuditEntryDrawer`) ya declara un slot `detail?: { ip, userAgent, metadata }` y lo renderiza vía `MetadataBlock` **solo si se suministra** — `AuditInvestigationScreen` no lo pasa → cae a `<DormantDetail/>`. Esta story cablea esa costura dormida (vía detalle).
- **`MetadataBlock` NO es un renderizador de diff** (pretty-print JSON crudo escapado). Falta `AuditChangeDiff` (tabla `field/old→new`). Render del nivel `change` sin pulir (badge neutro, fallback inglés, sin segmento de filtro).

### Decisión de arquitectura (recurso canónico, D-A) — argumentada

- **Principio (SRP/CQRS):** el listado responde "qué pasó, en orden" (keyset, slim); el **audit event** es un recurso por sí mismo (`/audit/events/{id}`), no "el detalle del timeline". El codebase ya nombra el split (docblock del DTO, `AuditEntryDetail` dormido en PWA).
- **Objetivo:** listado keyset lean (sin JSONB por fila); diff bajo demanda; reusa el slot dormido del drawer; resuelve deep-links off-page.
- **Coste / descartada:** fila gorda (un JSONB en cada fila del camino paginado caliente; contradice el contrato slim-row). Patrón a imitar: `BankGetController`→`BankDetailFinder`→`BankNotFoundException`.
- **Payload diff-only:** ruta pública en E1 e `ip` es PII → solo el diff; `ip`/`userAgent` dormidos hasta auth.

### Source tree — archivos a tocar

**API — NEW:** `Backoffice/Audit/Domain/AuditEventDetail.php`, `Domain/Repository/AuditEventDetailRepository.php`, `Domain/Exception/AuditEventNotFound.php`, `Application/AuditEventDetailFinder.php`, `Application/Resource/AuditEventDetailResource.php`, `Infrastructure/Http/AuditEventDetailResourceMapper.php`, `Infrastructure/Controller/AuditEventDetailController.php`.
**API — UPDATE:** `Infrastructure/Persistence/Dbal/DbalAuditTimelineRepository.php` (`findById`).
**API — NO TOCAR:** `AuditTimelineKeysetPaginator`, `AuditTimelineFilterApplier`, `SearchAuditTimelineQuery`, `AuditTimelineSearcher`, `AuditTimelineResource` (slim), `AuditTimelineResourceMapper` (listado), `AuditTimelineSearchController`, el `SELECT`/orden del listado.

**PWA — NEW:** `domain/AuditEventDetailRepository.ts` (+ tipos diff en `AuditEntry.ts`/`AuditChange.ts`), `infrastructure/ApiAuditEventDetailRepository.ts`, `application/useAuditEventDetail.ts`, `application/humanizeAuditField.ts`, `infrastructure/ui/AuditChangeDiff.tsx`.
**PWA — UPDATE:** `domain/AuditEntry.ts`, `application/humanizeAuditAction.ts`, `infrastructure/ui/{AuditEntryDrawer,AuditLevelBadge,AuditTimelineTable}.tsx`, `app/backoffice/audit/_lib/auditFilter.ts`, `app/backoffice/audit/_components/AuditInvestigationScreen.tsx`, `context/shared/http-client/infrastructure/ApiEndpoints.ts`, `Container.ts` del contexto audit.

**deptrac:** el read de detalle se queda en `Backoffice/Audit` leyendo la tabla `Shared/Audit` por DBAL crudo (sin entidad, sin import cross-context) — misma postura que el listado; si una clase nueva no la resuelve, registrar en `api/tools/deptrac/deptrac.yaml`.

### Previous-story intelligence (patrones a seguir)

- **#374 (`7d4489f5`) — read model timeline:** vertical slice = puerto Domain + handler thin + query DTO + Resource DTO plano + mapper + adapter DBAL crudo (`SELECT` explícito, allowlists, guards `requiredString`/`optionalString`/`requiredBool`) + keyset paginator (orden `(occurred_on, id)`, cursor HMAC + fingerprint, LIGHT mode). **Imitar para el detalle.**
- **#377 (`0710f759`) — UI:** `AuditEntry` espeja la fila slim; guards `isAuditEntry`/`isAuditTimelineResponse`; `useAuditTimeline` lean read-only (NO `useResourceList`); `AuditEntryDrawer` con slot `detail` dormido; `MetadataBlock` escapado.
- **Detalle by-id:** `Backoffice/Bank` `BankGetController`→`BankDetailFinder`→`BankNotFoundException` (404) con `Uuid::ensure` (400). También existe `BankAccountGetController`/`BankAccountFinder`/`BankAccountNotFoundException`. **Imitar.**

### Testing standards

PHPUnit 13 (`#[CoversClass]`, real Postgres `inRolledBackTransaction`, mappers fijan lista+orden de claves) · Behat (siembra SQL, prueba diff por JSONB, `data[*] 10 children` NO cambia) · Vitest 4 (query por rol/texto, guard drift table, test XSS `<script>` = headline AC2) · Playwright (stack HTTPS live, gotchas cert/DB/Mercure).

### Quality gates + gotchas relevantes

`make php.stan` por archivo (worker segfault → `PHP_SERVICE=messenger_worker`); `make php.quality`; `make php.behat`; `make pwa.quality` + `make pwa.test`; `make php.lint.error-contract` (404). **PHP:** `#[CoversClass]` restringe cobertura + PHPMD `CouplingBetweenObjects` ≤13 cuenta `use` de test → trait; Rector impone `assertNotInstanceOf`/`assertSame`-objetos; tug-of-war Psalm↔PHPStan tras `assertCount` (usar `array_unique`); Sonar S3415/S1142/S1448. **PWA:** Sonar S3735/S6544 (fetch fire-and-forget → block body), jsx-a11y S6847 (filas focusables); `cn` desde `@/components/cn`; sin `maxLength`; sin `// NOSONAR` ni comentarios que narren reglas.

### Must-preserve / regresión

Listado slim + keyset cursor (orden `(occurred_on, id)`, fingerprint) **byte-idéntico**; envelope cursor-only; 3 vistas (Timeline/Jornada/Día), filtro debounced (URL params, sin PII en storage), paginación. RFC 9457 (400 `invalid-uuid` antes del lookup, 404 ausente) por el pipeline; nunca `JsonResponse` manual. `audit_log` append-only (la story **no** añade mutación). Sin entidad por el wire (DTO por vista). Fidelidad forense: `level`/`actorType` strings crudos; guard `requiredBool`. UX read existente: `RedactedValue`/`ActorChip`, patrón dormant `ip`/`userAgent`, teclado roving-tabindex, mobile-first, `MetadataBlock` para metadata no-diff.

### Project Structure Notes

Alineado con DDD/hexagonal: read-side en `Backoffice/Audit/{Domain,Application,Infrastructure}`; PWA en `context/backoffice/audit/{domain,application,infrastructure}` + `app/backoffice/audit/`. El detalle reutiliza la tabla `Shared/Audit` (entity-free raw DBAL) desde `Backoffice/Audit` — misma postura de aislamiento que el listado, sin nueva costura cross-context.

### References

- [Source: `_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#Story 1.7`] — AC base, FR16/FR17.
- [Source: `docs/adr/regulatory-audit-trail.md`] — D2 (acción semántica), D3 (reconciliación), D5 (mapeo ISO), D10 (BankAccount → E2), D-A/D-B (vía sesión de arquitectura).
- [Source: `api/src/Backoffice/Audit/`] — read model timeline (#374).
- [Source: `api/src/Shared/Audit/Application/AuditChangeDiff.php`] — forma del diff.
- [Source: `api/src/Backoffice/Bank/Domain/Entity/Bank.php#142-154`] — `AuditedEntity` de `Bank`.
- [Source: `pwa/src/context/backoffice/audit/`] — UI #377: `AuditEntry`, `ApiAuditTimelineRepository`, `useAuditTimeline`, `infrastructure/ui/{AuditEntryDrawer,MetadataBlock,AuditTimelineTable,AuditLevelBadge}.tsx`, `application/humanizeAuditAction.ts`.
- [Source: `pwa/src/app/backoffice/audit/_components/AuditInvestigationScreen.tsx`] — el consumidor que omite `detail`.
- Patrón detalle by-id: [Source: `api/src/Backoffice/Bank/Infrastructure/Controller/BankGetController.php` + `Application/BankDetailFinder.php` + `Domain/Exception/BankNotFoundException.php`].

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
