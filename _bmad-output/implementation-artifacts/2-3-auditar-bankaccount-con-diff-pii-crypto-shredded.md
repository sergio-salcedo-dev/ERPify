---
baseline_commit: 6224f2a21de4aebc3e9680c4381f46ea3c233c24
---

# Story 2.3: Auditar `BankAccount` con el diff PII crypto-shredded

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **responsable de cumplimiento**,
quiero **que las escrituras de `BankAccount` se auditen con sus campos PII cifrados (crypto-shredded)**,
para **registrar quién cambió qué — sin dejar PII (`holderName`/`iban`) en claro en una tabla append-only**.

> **Origen:** Epic 2 (`_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#Story 2.3`), ADR [`regulatory-audit-trail.md`](../../docs/adr/regulatory-audit-trail.md) D6/D9/D10/D11/D12/D17. Cierra el motivo por el que `BankAccount` quedó **fuera** de la Story 1.7 (su diff lleva PII). **Requiere 2.1 (clasificación) y 2.2 (keystore/envelope).**

### Scope — lado de escritura (captura cifrada). El render descifrado es de E3

Esta story: (1) cablea `BankAccount` como `AuditedEntity` (igual que `Bank`), de modo que el `AuditWriteCaptureListener` ya vivo lo capture; (2) **cifra los campos `#[PersonalData]` del diff** bajo la DEK del `EncryptionScopeId` (`BankAccount:<id>`) en el mismo `onFlush`; (3) hace que la fila **referencie el scope** (`encryption_scope_id` en `audit_log`); (4) garantiza que **ningún metadato cripto** contamina la entidad. **NO** descifra ni renderiza PII en claro en la UI pública (la superficie de lectura es pre-auth → mostrar PII descifrada es privilegiado → **E3**). Sí añade las etiquetas `BANK_ACCOUNT_*` y un centinela "cifrado" en la UI para que el timeline siga coherente sin filtrar.

### Dependencias dentro de E2

`2.3` depende de **2.1** (qué campos cifrar) y **2.2** (con qué cifrar). Es prerequisito conceptual de la utilidad de 2.4 (que destruye la DEK que esta story usa).

### Decisiones tomadas (ADR + sesión de arquitectura)

1. **Captura por el listener genérico ya vivo** (D1/D10). `AuditWriteCaptureListener` (onFlush, síncrono **en la transacción del flush**) captura cualquier `AuditedEntity`. Cablear `BankAccount` con la interfaz es suficiente para que se capture — la diferencia con `Bank` es **solo** el cifrado del diff PII.
2. **El cifrado vive en el seam del diff, no en la entidad** (D6/D17). Se cifra el diff en `audit_log`, **no** la entidad viva: `BankAccount` sigue guardando `holderName`/`iban` en claro (la cuenta debe funcionar; `iban` es `unique`). El crypto-shredding es exclusivo del registro de auditoría. Descartado (sugerencia a evitar): cifrar la entidad en reposo vía Doctrine `prePersist`/`postLoad` — **no** es lo que el ADR pide y rompería unicidad/validación de `iban`.
3. **Cifrar, no redactar** (D6). Los campos PII del diff se guardan **cifrados** (valor forense preservado hasta el olvido), no `[REDACTED]`. La redacción perdería el "de qué valor a qué valor" que el regulador pide; el olvido se hace destruyendo la DEK (2.4), no redactando.
4. **La fila referencia el scope** (D6). `audit_log` gana una columna nullable `encryption_scope_id`: null en filas sin PII (p.ej. `Bank` — regresión-segura, la Story 1.7 sigue mostrando `Bank` en claro), `BankAccount:<id>` en filas de `BankAccount`. Descartado: derivar el scope de `(resource_type, resource_id)` en lectura — el scope-type reusa el `resource_type` verbatim (`BankAccount`), así sellador y erasure comparten una única fuente; una columna explícita evita un mapeo frágil y cumple "la fila referencia su dek".
5. **Compone con el erasure de actor sin reabrirlo** (D6/FR12). Loci de PII distintos: el actor se olvida por remint de `actor_id` (D4/D4.1, eje hermano); el PII del diff por destrucción de DEK (2.4). Nunca se mezclan.

## Acceptance Criteria

**AC1 — `BankAccount` es `AuditedEntity` (FR-captura, D10).**
Given `BankAccount` (hoy **no** auditado),
When se cablea,
Then implementa `AuditedEntity`: `auditResource()` → `AuditResource::of('BankAccount', $this->id())` y `auditAction()` → `BANK_ACCOUNT_CREATED`/`BANK_ACCOUNT_UPDATED`/`BANK_ACCOUNT_DELETED` (espejo exacto de `Bank.php:142-154`); el `AuditWriteCaptureListener` ya vivo emite una fila `level=change` por cada alta/edición/borrado, incluido el snapshot final en DELETE.

**AC2 — Campos PII del diff cifrados; no-PII en claro (FR9, FR11, NFR6, D6/D11/D12).**
Given un cambio sobre `BankAccount`,
When el listener `onFlush` construye la entrada,
Then los campos `#[PersonalData]` del diff (`holderName`, `iban`) se cifran bajo la DEK del `EncryptionScopeId` `BankAccount:<id>` (vía el `EnvelopeEncryptor` de 2.2, con la clasificación de 2.1), y `bic`/`currency`/`status`/`bankId` quedan **en claro**; **nunca** aparece `holderName`/`iban` en claro en `audit_log.metadata`.

**AC3 — La fila referencia el scope (D6).**
Given una fila de escritura de `BankAccount`,
When se persiste,
Then `audit_log.encryption_scope_id = 'BankAccount:<id>'`; las filas sin PII (p.ej. `Bank`) tienen `encryption_scope_id = NULL` y su diff sigue en claro (la Story 1.7 no se rompe).

**AC4 — Cero metadato cripto en el dominio (D17, deptrac).**
Given la frontera hexagonal,
When se revisa,
Then ningún metadato cripto (`encryption_scope_id`, `kek_version`, ciphertext) aparece en la entidad `BankAccount` ni en ningún `Domain/` de negocio — vive en el keystore (2.2) y en `audit_log` (raw-DBAL, entity-free); `make php.deptrac` verde.

**AC5 — Compone con el erasure de actor (FR12).**
Given el eje hermano,
When se revisa,
Then el crypto-shredding del diff **no** reabre el erasure de actor (D4 remint `actor_id` / D4.1 `actor_erased`): son loci distintos. Un test fija que ambos coexisten en una fila de `BankAccount` sin interferir.

**AC6 — La UI no filtra PII ni muestra basura (D-B render, seguridad).**
Given la superficie de lectura pública (pre-auth) y el detalle `GET /audit/events/{id}` de la Story 1.7,
When se abre una fila `change` de `BankAccount`,
Then los campos PII del diff se muestran con un **centinela "🔒 cifrado / no disponible"** (no ciphertext crudo, no plaintext); los no-PII (`bic`/`status`/…) se muestran en claro; las acciones `BANK_ACCOUNT_*` tienen etiqueta ES. **El descifrado/render del PII es privilegiado y queda para E3.**

## Tasks / Subtasks

> Convenciones: ver 2.1/2.2. La migración (columna `encryption_scope_id`) es editable en esta rama. Barrido final de comentarios con ID antes del commit.

### A. Dominio `BankAccount` — `AuditedEntity` (AC1)

- [ ] **A1.** `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php`
  - [ ] `implements AuditedEntity` (junto a `extends AggregateRoot`).
  - [ ] `auditResource(): AuditResource` → `AuditResource::of('BankAccount', $this->id())`.
  - [ ] `auditAction(AuditWriteOperation $operation): string` → `match`: `CREATED`→`BANK_ACCOUNT_CREATED`, `UPDATED`→`BANK_ACCOUNT_UPDATED`, `DELETED`→`BANK_ACCOUNT_DELETED`. **Espejo de `Bank.php:142-154`.** (Los `#[PersonalData]` de `holderName`/`iban` ya vienen de 2.1.)

### B. Seam de cifrado en el listener (AC2, AC3, AC4)

- [ ] **B1.** Sellador del diff PII: `api/src/Shared/Audit/Infrastructure/Persistence/PiiDiffSealer.php` (o `Application` con adapter — decidir)
  - [ ] Colaborador inyectable que recibe `(AuditedEntity $entity, array $diff)` y devuelve `SealedDiff{array $metadata, ?EncryptionScopeId $scope}`. Internamente: `PersonalDataClassifier::personalFieldsOf($entity)` (2.1) → si hay campos PII presentes en `$diff['changes']`, deriva `EncryptionScopeId` desde `$entity->auditResource()` (`BankAccount:<id>`) y cifra `old`/`new` de esos campos con `EnvelopeEncryptor::encrypt(scope, value)` (2.2); marca cada valor cifrado de forma distinguible (p.ej. `{ "__enc__": "<ciphertext-base64>" }`) para que la lectura sepa que es cifrado (no plaintext, no descifrable sin privilegio). Campos no-PII intactos.
  - [ ] Entidad sin campos PII (p.ej. `Bank`) → `scope = null`, `metadata` sin cambios (paridad con el comportamiento actual de la Story 1.7).
- [ ] **B2.** Cablear el sellador en `AuditWriteCaptureListener.php` (línea ~85, el seam identificado)
  - [ ] Inyectar `PiiDiffSealer`. Antes de `entryFactory->create(...)`: `$sealed = $piiDiffSealer->seal($entity, $changeDiff->of(...))`. Pasar `$sealed->metadata` como metadata y `$sealed->scope` al factory/entry (ver B3). **No** acoplar el listener a `BankAccount` (sigue genérico, opera sobre `AuditedEntity` + clasificación).
  - [ ] Mint de DEK ocurre dentro del flush (transacción ambiente) — el `DbalKeystore` usa la `Connection` por defecto → atómico con la escritura de negocio (NFR1 ya cumplido por la captura síncrona de E1).
- [ ] **B3.** Propagar `encryption_scope_id` por el pipeline de escritura (cambio acotado al `Shared/Audit` write-side)
  - [ ] `AuditLogEntry` (`Application`): añadir `?string $encryptionScopeId` (default null) a `create()`/constructor. `AuditEntryFactory::create(...)` + `SealedAuditEntryFactory`: aceptar/propagar el scope (string `EncryptionScopeId::toString()` o null). `DbalAuditLogWriter`: añadir la columna al INSERT (`encryption_scope_id`, `CAST(:scope AS ...)`, null-safe). Mantener idempotencia `ON CONFLICT (id) DO NOTHING`.
  - [ ] `AuditLogSchemaListener`: `addColumn('encryption_scope_id', Types::STRING, ['length' => 160, 'notnull' => false])`. **Sin** índice nuevo salvo que 2.4 lo requiera (la reconciliación de olvidos consulta el keystore, no `audit_log`). `make db.diff` → migración `ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS encryption_scope_id ...` (patrón `Version20260626215406.php`).

### C. PWA — coherencia de lectura sin fuga (AC6)

> Sin descifrar (privilegio → E3). Convenciones PWA de la Story 1.7 (BEM, `cn`, sin default exports bajo `context/**`, sin `maxLength`, texto React escapado, **sin `dangerouslySetInnerHTML`**).

- [ ] **C1.** `pwa/src/context/backoffice/audit/application/humanizeAuditAction.ts` — añadir a `CURATED_LABELS`: `BANK_ACCOUNT_CREATED→"Cuenta bancaria creada"`, `BANK_ACCOUNT_UPDATED→"Cuenta bancaria actualizada"`, `BANK_ACCOUNT_DELETED→"Cuenta bancaria eliminada"`.
- [ ] **C2.** `pwa/src/context/backoffice/audit/infrastructure/ui/AuditChangeDiff.tsx` — reconocer el marcador de campo cifrado (`{ "__enc__": ... }`) y renderizar un centinela **"🔒 cifrado (no disponible)"** (texto, accesible, canal no-color) en lugar del valor; nunca volcar el ciphertext crudo. Los campos no-PII se renderizan igual que hoy. (El descifrado autorizado llega en E3.)
- [ ] **C3.** `pwa/src/context/backoffice/audit/application/humanizeAuditField.ts` — etiquetas curadas de `BankAccount`: `holderName→"Titular"`, `iban→"IBAN"`, `bic→"BIC"`, `currency→"Moneda"`, `status→"Estado"`, `bankId→"Banco"` (+ fallback title-case existente).

### D. Tests

- [ ] **D1.** Unit (`PiiDiffSealerTest`, fakes de clasificador+encryptor): campos PII → marcados cifrados con scope `BankAccount:<id>`; no-PII intactos; entidad sin PII → scope null, metadata intacta.
- [ ] **D2.** Unit (`BankAccount` audit methods): `auditAction` mapea las 3 operaciones; `auditResource` = `('BankAccount', id)`.
- [ ] **D3.** Funcional (Postgres real, `inRolledBackTransaction`): crear/editar/borrar un `BankAccount` → fila `level=change` con `encryption_scope_id` set, `holderName`/`iban` **cifrados** en `metadata` (assert: el plaintext **no** aparece en la fila), `bic`/`status` en claro; DELETE captura snapshot. **Regresión:** un cambio de `Bank` → `encryption_scope_id` NULL y diff en claro (Story 1.7 intacta).
- [ ] **D4.** Funcional (AC5): una fila de `BankAccount` sobrevive a `audit:gdpr:erase <actor>` (remint `actor_id`, `actor_erased=true`) **conservando** su `encryption_scope_id` y su diff cifrado — loci independientes.
- [ ] **D5.** PWA Vitest: `AuditChangeDiff.test.tsx` extendido — campo con marcador `__enc__` → centinela "🔒 cifrado", **nunca** ciphertext ni plaintext; `humanizeAuditAction`/`humanizeAuditField` con `BANK_ACCOUNT_*`.
- [ ] **D6.** Behat (opcional, si aporta sobre el funcional): `bank_account` write → fila `change` cifrada; `data[*]` del timeline slim no cambia (sigue 10 children — el diff va por detalle).

### E. Docs

- [ ] **E1.** `docs/architecture-api.md` — `BankAccount` como agregado auditado con diff PII crypto-shredded; columna `encryption_scope_id`; el seam `PiiDiffSealer`.
- [ ] **E2.** `docs/rules/security.md` + `PRODUCTION_SECURITY_CHECKLIST.md` — nunca PII de `BankAccount` en claro en `audit_log`; la UI pública muestra centinela, no PII; descifrado = privilegio de E3.
- [ ] **E3.** **Barrido final:** sin comentarios con ID; `make php.stan` por archivo + `make php.quality` + `make php.psalm.taint` + `make pwa.quality` + `make pwa.test` antes del commit.

## Dev Notes

### Estado actual (verificado)

- **`AuditWriteCaptureListener`** (onFlush, `Shared/Audit/Infrastructure/Persistence`): gate `instanceof AuditedEntity` (líneas ~76-79); captura CREATED/UPDATED/DELETED desde `UnitOfWork`; DELETE usa `finalStateSnapshot()` (estado final antes de desaparecer); escribe **síncrono en la transacción del flush** (guard `isTransactionActive`). **El seam de cifrado es la línea ~85**: el retorno de `AuditChangeDiff::of(...)` antes de pasarlo como `$metadata` a `entryFactory->create(...)`.
- **`AuditChangeDiff::of($changeSet)`** → `['changes' => [field => ['old'=>scalar|null,'new'=>scalar|null]]]`; `scalarize` normaliza fechas/enums.
- **`AuditLogEntry`** (`Application`): `id, action, level(ACTIVITY|SECURITY|CHANGE), actor(ActorContext), correlationId, occurredOn, resource, metadata, ip, userAgent`. **Sin** campo de scope hoy. `DbalAuditLogWriter` INSERT raw-DBAL idempotente; `AuditLogSchemaListener` define columnas; `actor_erased` ya existe.
- **`Bank`** (`Bank.php:142-154`) = patrón `AuditedEntity` exacto a copiar (`BANK_CREATED/UPDATED/DELETED`).
- **`BankAccount`** (`BankAccount.php:28-66`) **no** implementa `AuditedEntity`; campos: `bankId`,`holderName`,`iban`,`bic`,`alias`,`currency`,`status` (ver 2.1 para tipos). Eventos de dominio (`BankAccount*DomainEvent`) viajan por el eje `event_store` (negocio) — **ortogonal** a esta auditoría (D3/FR4: no se duplica).
- **Lectura (Story 1.7):** `GET /api/v1/backoffice/audit/events/{id}` devuelve `metadata.changes`; la PWA `AuditChangeDiff.tsx` renderiza el diff escapado. Para `BankAccount`, los campos PII llegarán como marcador cifrado → centinela.

### Decisión de arquitectura (argumentada)

- **Principio (D6/D17 + frontera hexagonal):** el cifrado es del registro de auditoría (infra), no de la entidad; el listener sigue genérico (opera sobre `AuditedEntity` + clasificación + encryptor inyectados), evitando que `Shared/Audit` conozca `BankAccount`.
- **Objetivo:** registrar el cambio de PII (`holderName`/`iban`) con valor forense, cumpliendo "nunca PII en claro en reposo" (NFR6) y habilitando el olvido por destrucción de DEK (2.4) sin romper append-only.
- **Coste / descartada:** cifrar la entidad en reposo (rompe `iban` unique/validación, no es lo que el ADR pide); redactar el diff (pierde el forense). El coste es un sellador + propagar una columna nullable por el write-side — acotado y reversible.
- **Render diferido (consciente):** mostrar PII descifrada es privilegiado (D8/E3). 2.3 NO lo hace; la UI muestra centinela. Evita la fuga de exhibir PII en una ruta hoy pública.

### Source tree — archivos a tocar

**API NEW:** `Shared/Audit/Infrastructure/Persistence/PiiDiffSealer.php` (+ `SealedDiff` VO); tests Unit (`PiiDiffSealerTest`) + Functional (`BankAccount` audit e2e, regresión `Bank`, composición con erasure).
**API UPDATE:** `Backoffice/BankAccount/Domain/Entity/BankAccount.php` (`AuditedEntity`); `Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php` (cablear sealer); `Shared/Audit/Application/AuditLogEntry.php` + `AuditEntryFactory.php` + `Shared/Audit/Infrastructure/SealedAuditEntryFactory.php` + `Shared/Audit/Infrastructure/Persistence/DbalAuditLogWriter.php` + `AuditLogSchemaListener.php` (propagar `encryption_scope_id`); migración nueva.
**PWA UPDATE:** `context/backoffice/audit/application/{humanizeAuditAction,humanizeAuditField}.ts`, `infrastructure/ui/AuditChangeDiff.tsx` (+ tests).
**NO TOCAR:** el SELECT/orden/keyset del listado del timeline (slim); el render de `Bank` (debe seguir en claro); `event_store` / eventos de dominio de `BankAccount`; el keystore interno de 2.2 (solo se consume vía puerto).
**deptrac:** `PiiDiffSealer` en `Infrastructure`; consume puertos `PersonalDataClassifier`/`EnvelopeEncryptor`. `BankAccount.auditResource/auditAction` usan tipos `Shared/Audit/Domain` (importables). Si algo no resuelve → `api/tools/deptrac/deptrac.yaml`.

### Previous-story intelligence (patrones a seguir)

- **Story 1.7 (`6224f2a2`):** `AuditChangeDiff` UI escapada (sin `dangerouslySetInnerHTML`); `humanizeAuditAction`/`humanizeAuditField` curated + fallback; detalle by-id slim. **Extender, no duplicar.** Nota de 1.7: "las variantes `BANK_ACCOUNT_*` llegan con E2" → aterrizan aquí.
- **`Bank` AuditedEntity (`Bank.php:142-154`)** — copiar literalmente la forma para `BankAccount`.
- **Captura E1 (1.1-1.6):** listener onFlush síncrono en transacción; diff por `AuditChangeDiff::of()`; raw-DBAL. El cifrado se **inserta** en ese flujo, no lo reemplaza.

### Testing standards

PHPUnit 13 (`#[CoversClass]`, AAA, fakes de puertos para el sealer; funcional Postgres real `inRolledBackTransaction`, **assert que el plaintext PII NO está en la fila**). Behat (siembra SQL, JSONB). Vitest 4 (query por rol/texto; test de que el centinela aparece y **no** el ciphertext/plaintext). El test de composición con erasure (D4/D4.1) es load-bearing (AC5).

### Quality gates + gotchas relevantes

`make php.stan` por archivo (`PHP_SERVICE=messenger_worker`); `make php.quality`; `make php.psalm.taint` (el plaintext PII no debe fluir a un sink sin cifrar); `make php.deptrac`; `make php.lint.bounded-context`; `make php.lint.error-contract` (si se toca algún marker — no debería); `make pwa.quality` + `make pwa.test`. **PHP:** `#[CoversClass]` + PHPMD coupling ≤13 → fakes en trait; Rector `assertNotInstanceOf`/`assertSame`-objetos; Psalm↔PHPStan `assertCount`→`array_unique`; `Assert\Bic`/`Assert\Iban` ya en `BankAccount` (no se tocan). **PWA:** Sonar S3735/S6544; jsx-a11y S6847; `cn` desde `@/components/cn`; sin `maxLength`; texto React escapado. **Migración:** editable en rama; inmutable tras merge.

### Must-preserve / regresión

- **Story 1.7 intacta:** `Bank` sigue mostrando su diff **en claro** (`encryption_scope_id` NULL); listado slim + keyset byte-idéntico; `data[*]` 10 children.
- **`BankAccount` funciona igual:** CRUD + status endpoint + `iban` unique/canonicalizado + DTOs por vista; el cifrado es **solo** del diff de auditoría, no de la entidad.
- **Append-only de `audit_log`** intacto (la story solo añade una columna nullable + inserta; no muta filas existentes salvo el erasure, que no es de esta story).
- **Eje hermano (D4/D4.1):** `audit:gdpr:erase` sigue funcionando sin tocar `encryption_scope_id`.
- **Nunca** PII en claro en `audit_log`; la UI pública nunca muestra PII de `BankAccount`.

### Project Structure Notes

Write-side en `Shared/Audit` (sealer + propagación de scope) + `Backoffice/BankAccount/Domain` (AuditedEntity). Read-side reutiliza la superficie de la Story 1.7 (detalle by-id) sin descifrar. El cambio al pipeline compartido (`AuditLogEntry`/factory/writer/schema) es aditivo (columna nullable) y no rompe `Bank` ni los niveles `activity`/`security`.

### References

- [Source: `_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#Story 2.3`] — ACs base, FR9/FR11/FR12.
- [Source: `docs/adr/regulatory-audit-trail.md`] — D6 (crypto-shredding), D10 (el dato dispara E2), D11/D12 (PII por campo), D17 (cripto fuera del dominio), D9 (read gated en E3).
- [Source: `api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php` (~línea 85)] — seam de cifrado.
- [Source: `api/src/Shared/Audit/Application/{AuditChangeDiff,AuditLogEntry,AuditEntryFactory}.php`, `Infrastructure/{SealedAuditEntryFactory.php,Persistence/DbalAuditLogWriter.php,Persistence/AuditLogSchemaListener.php}`] — pipeline de escritura a extender.
- [Source: `api/src/Backoffice/Bank/Domain/Entity/Bank.php:142-154`] — patrón `AuditedEntity`.
- [Source: `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php`] — entidad a cablear.
- [Source: `_bmad-output/implementation-artifacts/2-1-clasificacion-pii-por-campo-personaldata.md`, `2-2-keystore-envelope-libsodium-dek-por-encryptionscopeid.md`] — clasificación y keystore que esta story consume.
- [Source: `pwa/src/context/backoffice/audit/infrastructure/ui/AuditChangeDiff.tsx`, `application/humanizeAuditAction.ts`] — UI a extender (Story 1.7).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story).

### Debug Log References

`make php.stan` 0 · `make php.quality` 0 violations · `make php.psalm.taint` clean · `make php.unit` full **1403→1406** green · `make pwa.quality` + `make pwa.test.unit` (854) green. Commits `4f6a3fde` (API) + `a7ecc7ea` (PWA).

### Completion Notes List

**API (`4f6a3fde`):** `BankAccount implements AuditedEntity` (`BANK_ACCOUNT_*`); new `PiiDiffSealer` at the `onFlush` seam encrypts `#[PersonalData]` diff columns under the aggregate's `EncryptionScopeId` (via 2.1 classifier + 2.2 encryptor), non-PII en claro; `audit_log` gains nullable `encryption_scope_id` (migración `Version20260701084808`), propagated through `AuditLogEntry`/`AuditEntryFactory`/`SealedAuditEntryFactory`/`DbalAuditLogWriter`/`AuditLogSchemaListener`. Cero cripto en la entidad (D17). Boy-scout nombrado: `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` en `BankAccount` (coupling inherente 13; **flagged para Sergio** — alternativa = split de responsabilidades, fuera de E2).
**PWA (`a7ecc7ea`):** `AuditChange` acepta el marcador `{__enc__}`; guard de detalle lo valida+normaliza; `AuditChangeDiff` lo renderiza como centinela "cifrado (no disponible)" (nunca ciphertext, nunca descifrado — E3); labels `BANK_ACCOUNT_*` + campos `BankAccount`.
**Render descifrado del PII = diferido a E3** (privilegiado). Behat de escritura no añadido (el funcional cubre las ACs).

### File List

**API NEW:** `Shared/Audit/Infrastructure/Persistence/{PiiDiffSealer,SealedDiff}.php`; migración `Version20260701084808.php`; tests `Unit/Shared/Audit/Infrastructure/Persistence/{PiiDiffSealerTest,AuditedSubjectFake,PlainAuditedFake}.php`, `Unit/Backoffice/BankAccount/Domain/Entity/BankAccountAuditTest.php`, `Functional/Shared/Audit/BankAccountAuditCryptoShreddingFunctionalTest.php`.
**API UPDATE:** `Backoffice/BankAccount/Domain/Entity/BankAccount.php`; `Shared/Crypto/Domain/EncryptionScopeId.php`; `Shared/Audit/{Application/{AuditLogEntry,AuditEntryFactory}.php, Infrastructure/{SealedAuditEntryFactory.php, Persistence/{AuditWriteCaptureListener,DbalAuditLogWriter,AuditLogSchemaListener}.php}}`; test double `FixedAuditEntryFactory.php` + `EncryptionScopeIdTest.php`.
**PWA:** `context/backoffice/audit/{domain/AuditChange.ts, infrastructure/ApiAuditEventDetailRepository.ts, infrastructure/ui/AuditChangeDiff.tsx, application/{humanizeAuditAction,humanizeAuditField}.ts}` + their tests.
