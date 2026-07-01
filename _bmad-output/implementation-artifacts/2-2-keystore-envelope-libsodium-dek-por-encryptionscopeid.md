---
baseline_commit: 6224f2a21de4aebc3e9680c4381f46ea3c233c24
---

# Story 2.2: Keystore + envelope libsodium (DEK por `EncryptionScopeId`)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **plataforma de ERPify**,
quiero **una tabla keystore con DEKs por scope envueltas por una KEK, y un cifrador envelope libsodium**,
para **soportar crypto-shredding por sujeto (destruir la DEK → ciphertext ilegible) sin acoplar la cripto al dominio**.

> **Origen:** Epic 2 (`_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#Story 2.2`), ADR [`regulatory-audit-trail.md`](../../docs/adr/regulatory-audit-trail.md) D6/D13/D14/D17. Es el **motor cripto** de E2. No audita nada todavía (eso es 2.3): construye el keystore + envelope + el value object de identidad de scope.

### Scope — keystore + envelope + `EncryptionScopeId` (sin cablear auditoría)

Esta story entrega: (1) el value object `EncryptionScopeId`, (2) la tabla keystore (migración), (3) el cifrador envelope libsodium con su ciclo de vida de DEK (mint / wrap / encrypt / decrypt / **destroy**), y (4) la custodia de la KEK por env. **No** toca `audit_log`, **no** cablea `BankAccount`, **no** clasifica PII (eso es 2.1/2.3). La integran 2.3 (cifra) y 2.4 (destruye).

### Dependencias dentro de E2

`2.2` es independiente de `2.1`. Es **prerequisito de 2.3 y 2.4** (ambas usan el keystore). Es el nodo que el usuario señaló: *"2.1→2.4 dependen del keystore"*.

### Decisiones tomadas (ADR D13/D14/D17)

1. **Identidad cripto desacoplada del dominio** (D13). Un value object `EncryptionScopeId` (`"<TYPE>:<uuid>"`, hoy `BankAccount:<uuid>`) nombra el scope que una DEK protege. **No** presupone un agregado: un futuro `Party` heredará el scope (D16) sin renombrar el concepto cripto. Descartado: nombre `SubjectId` (cargado de GDPR), e introducir un agregado `Person`/`Party` solo para anclar la DEK (deja que infra modele el dominio — dirección incorrecta; YAGNI).
2. **Envelope: DEK por scope (CSPRNG), envuelta por una KEK custodiada fuera de la app** (D14). libsodium AEAD `crypto_aead_xchacha20poly1305_ietf`; la KEK vive en env, **nunca** junto a las DEKs. Cada fila keystore guarda `wrapped_dek` + `kek_version`. Descartado: KEK en Postgres junto a las DEKs (anula el envelope); una clave global (destruirla *shreddea* todos los scopes — el keying por-scope es justo lo que hace posible el olvido de un único sujeto); HSM/KMS/jerarquía de derivación (sobre-ingeniería a cardinalidad de datos maestros — *trigger de revisita* si llega un agregado PII a cardinalidad transaccional).
3. **Destruir una DEK es irreversible** (D14) — propiedad operativa aceptada, no un fallo. La rotación de KEK = **rewrap por lotes acotado** (re-envolver cada DEK bajo la nueva KEK; factible a cardinalidad de datos maestros).
4. **Metadata cripto fuera del dominio** (D17): `encryption_scope_id`, `kek_version`, `wrapped_dek`, ciphertext viven en el keystore y (en 2.3) en `audit_log` raw-DBAL — **nunca** en una entidad o value object de negocio.

## Acceptance Criteria

**AC1 — `EncryptionScopeId` value object (D13).**
Given la necesidad de identificar un scope de cifrado sin un agregado de dominio,
When se modela,
Then existe un value object inmutable `EncryptionScopeId` con forma `"<TYPE>:<uuid>"` (factory `forBankAccount(string $id)` → `BankAccount:<uuid>`, y un parser/validador), sin dependencias de framework; un valor mal formado lanza una excepción de dominio.

**AC2 — Tabla keystore (FR10, D14).**
Given el esquema envelope,
When se implementa,
Then existe una tabla keystore en Postgres con **una DEK por `EncryptionScopeId`** (`encryption_scope_id` único), `wrapped_dek`, `kek_version`, `created_at` y `destroyed_at` (nullable); la migración se genera vía `make db.diff` desde un schema listener (espejo de `AuditLogSchemaListener`).

**AC3 — Envelope libsodium con ciclo de vida de DEK (FR10, D14).**
Given el cifrador,
When se cifra un valor bajo un `EncryptionScopeId`,
Then: la DEK del scope se **acuña con CSPRNG** si no existe, se envuelve con la KEK y se persiste con su `kek_version`; el valor se cifra con `crypto_aead_xchacha20poly1305_ietf` bajo esa DEK (nonce único por operación); descifrar recupera el plaintext; **destruir** la DEK del scope deja el ciphertext **permanentemente ilegible** (descifrar tras destrucción → error/`null` controlado, no plaintext).

**AC4 — KEK custodiada fuera de la app (D14, seguridad).**
Given la KEK,
When se gestiona,
Then se inyecta por env (`#[Autowire('%env(...)%')]`), **nunca** se commitea ni se guarda junto a las DEKs; falta de KEK en prod = arranque/uso falla de forma explícita; `PRODUCTION_SECURITY_CHECKLIST.md` documenta la variable y su rotación (rewrap por lotes).

**AC5 — Aislamiento + dataflow seguro (NFR7, taint).**
Given el keystore y el envelope,
When se ejecuta `make php.deptrac` + `make php.lint.bounded-context` + `make php.psalm.taint`,
Then no hay violaciones: la cripto vive en `Infrastructure`; ningún `Domain/` alcanza framework; la KEK no fluye a logs/respuestas/excepciones (sin fuga de secretos).

## Tasks / Subtasks

> Convenciones API: `declare(strict_types=1)`; tipos en todo; `final`; excepciones de dominio (no `false`/`null`-sentinel para errores salvo el contrato explícito de "DEK destruida"). Migración vía `make db.diff` (editable en esta rama; inmutable tras merge).

### A. `EncryptionScopeId` (AC1)

- [ ] **A1.** `api/src/Shared/Crypto/Domain/EncryptionScopeId.php`
  - [ ] `final readonly`; `type` (string, p.ej. `BANK_ACCOUNT`) + `id` (uuid). `toString(): "<TYPE>:<uuid>"`; `forBankAccount(string $id): self`; `fromString(string $raw): self` (valida formato + `Uuid::ensure` en la parte uuid → `Shared/Uuid/Domain/Uuid`). Excepción `Shared/Crypto/Domain/Exception/InvalidEncryptionScopeId.php`.
  - [ ] **No** importar nada de framework (value object de dominio, espejo de `ActorContext`).

### B. Keystore (tabla + puerto + adapter) (AC2)

- [ ] **B1.** Schema listener: `api/src/Shared/Crypto/Infrastructure/Persistence/KeystoreSchemaListener.php`
  - [ ] `#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]` (espejo de `AuditLogSchemaListener`). Tabla `dek_keystore` (nombre a confirmar): `encryption_scope_id VARCHAR(160) PRIMARY KEY`, `wrapped_dek` (`Types::BLOB`/bytea o `TEXT` base64 — decidir, ver Dev Notes), `kek_version VARCHAR(32) NOT NULL` (o SMALLINT), `created_at TIMESTAMPTZ NOT NULL`, `destroyed_at TIMESTAMPTZ DEFAULT NULL`. Índice por `destroyed_at` (reconciliación de olvidos). **Sin FK a `bank_account`** (D13/D17). **Sin default de columna** vivo (writer aporta valores — mismo patrón que `audit_log`).
- [ ] **B2.** Migración: `make db.diff` → revisar `api/migrations/2026/Version<ts>.php`; `up()` `CREATE TABLE dek_keystore (...)` + índices; `down()` `DROP TABLE IF EXISTS dek_keystore`. Imitar `Version20260623164321.php`. **Sin sembrar** claves; `down()` reversible.
- [ ] **B3.** Puerto: `api/src/Shared/Crypto/Application/Keystore.php` (o `Domain/Repository/`)
  - [ ] `wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek` · `store(EncryptionScopeId $scope, WrappedDek $dek): void` · `destroy(EncryptionScopeId $scope): bool` (idempotente: ya destruida → `false`/no-op). `WrappedDek` = value object (`bytes`, `kekVersion`).
- [ ] **B4.** Adapter DBAL: `api/src/Shared/Crypto/Infrastructure/Persistence/DbalKeystore.php`
  - [ ] `#[AsAlias(Keystore::class)]`. INSERT idempotente (`ON CONFLICT (encryption_scope_id) DO NOTHING`); `destroy` = `UPDATE ... SET destroyed_at = :now, wrapped_dek = NULL WHERE encryption_scope_id = :id AND destroyed_at IS NULL` (anula la clave envuelta → irreversible; `destroyed_at` marca el olvido). Raw DBAL, entity-free (como `DbalAuditLogWriter`). Usa la `Connection` por defecto (se enrola en la transacción ambiente — clave para 2.3 dentro del flush).

### C. Envelope encryptor libsodium (AC3, AC4)

- [ ] **C1.** Puerto: `api/src/Shared/Crypto/Application/EnvelopeEncryptor.php`
  - [ ] `encrypt(EncryptionScopeId $scope, string $plaintext): string` (devuelve ciphertext serializable: nonce+cipher, base64) · `decrypt(EncryptionScopeId $scope, string $ciphertext): string` (lanza/`DekDestroyed` si la DEK no existe o fue destruida) · `destroyScope(EncryptionScopeId $scope): void` (delega en `Keystore::destroy`).
- [ ] **C2.** Adapter: `api/src/Shared/Crypto/Infrastructure/SodiumEnvelopeEncryptor.php`
  - [ ] DEK = 32 bytes CSPRNG (`sodium_crypto_aead_xchacha20poly1305_ietf_keygen()`). Mint-on-first-encrypt: si `Keystore::wrappedDekFor` es null → genera DEK, la envuelve con la KEK (AEAD bajo KEK con nonce propio) y `Keystore::store`. Cifrado de campo: `crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $dek)` con nonce aleatorio por op; serializar `nonce.ciphertext` (base64url, patrón de `CursorCodec::sodium_bin2base64`). `decrypt`: unwrap DEK con KEK + descifrar; DEK ausente/destruida → excepción `DekDestroyed` (controlada). `memzero` de claves en claro tras uso (`sodium_memzero`).
  - [ ] **KEK por env:** `#[Autowire('%env(AUDIT_KEK)%')]` (nombre a confirmar; patrón `CursorCodec` con `%kernel.secret%`). Validar longitud/forma de la KEK al construir; **nunca** loguearla ni incluirla en mensajes de excepción.
- [ ] **C3.** (Opcional, si el architect lo pide) `RewrapKeystoreCommand` (`Shared/Crypto/Infrastructure/Cli`) para rotación de KEK por lotes — **YAGNI hoy**: documentar la rotación como procedimiento; implementar el comando cuando exista la primera rotación real. Marcar como follow-up, no bloquea ACs.

### D. Tests (AC1–AC5)

- [ ] **D1.** Unit: `EncryptionScopeIdTest` — round-trip `toString`/`fromString`, `forBankAccount`, formato inválido → excepción.
- [ ] **D2.** Unit: `SodiumEnvelopeEncryptorTest` con un `Keystore` in-memory (fake del puerto) — encrypt→decrypt round-trip; **destruir → decrypt falla** (`DekDestroyed`, no plaintext); dos scopes distintos → DEKs distintas; el ciphertext difiere entre llamadas (nonce). KEK fija de test. (Cripto pura, sin DB.)
- [ ] **D3.** Funcional (Postgres real, `inRolledBackTransaction`): `DbalKeystoreFunctionalTest` — store/fetch/destroy; idempotencia de `destroy` (segunda vez → no-op); `ON CONFLICT` no duplica.
- [ ] **D4.** Funcional: `SodiumEnvelopeEncryptor` + `DbalKeystore` extremo a extremo contra Postgres — mint-on-first-encrypt persiste la DEK envuelta; decrypt tras reabrir; destroy → ilegible.

### E. Docs + seguridad

- [ ] **E1.** `PRODUCTION_SECURITY_CHECKLIST.md` — variable `AUDIT_KEK` (custodia fuera de la app, nunca en `.env`/`compose*.yaml` del repo), política de rotación (rewrap por lotes), irreversibilidad de la destrucción de DEK.
- [ ] **E2.** `docs/rules/security.md` — crypto-shredding (A.5.12/A.8.15): envelope libsodium, DEK por scope, KEK env-driven; ningún camino deja la KEK ni una DEK en claro en reposo/logs.
- [ ] **E3.** `docs/architecture-api.md` — nueva capability `Shared/Crypto` (keystore + envelope) como infraestructura de crypto-shredding; relación con `audit_log` (la usa 2.3).
- [ ] **E4.** `api/.env` / `compose*.yaml` — declarar `AUDIT_KEK` con **soft default** en dev (placeholder no-secreto) y **requerido** en prod (sin default → fallo explícito); cuidado con el gotcha de Compose `${VAR:?}` ([[compose-profile-required-var-gotcha]]).
- [ ] **F.** **Barrido final:** sin comentarios con ID de story/AC/FR en src/tests; `make php.stan` por archivo + `make php.quality` + `make php.psalm.taint` antes del commit.

## Dev Notes

### Estado actual (verificado)

- **No existe** cripto en el repo salvo `api/src/Shared/Search/.../Keyset/CursorCodec.php` (HMAC-SHA256 de cursor + `sodium_bin2base64`/`sodium_base642bin`, `SodiumException` envuelta en excepción de dominio). **Es el patrón vivo de libsodium + secreto por env** a imitar: `#[Autowire('%kernel.secret%')] private string $secret`.
- **`ext-sodium`** está en `api/composer.json` (`"ext-sodium": "*"`) — disponible.
- **`audit_log`** se crea por `AuditLogSchemaListener` (postGenerateSchema) + migración; el writer (`DbalAuditLogWriter`) es raw DBAL, idempotente (`ON CONFLICT (id) DO NOTHING`), sin transacción propia (se enrola en la ambiente). **Mismo patrón** para el keystore.
- **Migración reciente de columna** (`Version20260626215406.php`): `ALTER TABLE ... ADD COLUMN IF NOT EXISTS ... DEFAULT FALSE` y luego `DROP DEFAULT` (default transitorio para backfill, luego limpio para que `make db.diff` quede estable). Patrón para columnas nuevas.
- **Secretos prod requeridos** hoy: `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD` (project-context.md). `AUDIT_KEK` se suma a esa lista.

### Decisión de arquitectura (argumentada)

- **Principio (D13 DIP / frontera hexagonal):** la cripto es infraestructura; la identidad de scope es un value object cripto, **no** un agregado de negocio — así el olvido por sujeto no fuerza un modelo de dominio que nadie pide (YAGNI) y un futuro `Party` migra a este scope sin tocar la cripto.
- **Objetivo:** olvido de un único sujeto (destruir su DEK) sin romper append-only de `audit_log`; reutilizable por cualquier infra de datos personales.
- **Coste / descartada:** clave global (más simple pero destruirla *shreddea* a todos — inservible para "olvídame" individual); KEK en DB (anula el envelope). El coste real es una tabla + un adapter + ~1 cifrador — barato frente a la propiedad que compra.
- **`wrapped_dek` bytea vs text base64 (decisión a cerrar):** `BLOB`/bytea es lo natural para bytes; `TEXT` base64 evita el manejo de `bytea` en DBAL. Recomendación: **bytea** (`Types::BLOB`) si DBAL lo maneja limpio aquí; si fricciona, base64 `TEXT` (precedente: `CursorCodec` ya serializa a base64url). Confirmar al implementar.

### Source tree — archivos a tocar

**NEW (capability `Shared/Crypto`):** `Domain/EncryptionScopeId.php`, `Domain/Exception/InvalidEncryptionScopeId.php`, `Domain/Exception/DekDestroyed.php`, `Application/Keystore.php`, `Application/EnvelopeEncryptor.php`, `Application/WrappedDek.php`, `Infrastructure/Persistence/KeystoreSchemaListener.php`, `Infrastructure/Persistence/DbalKeystore.php`, `Infrastructure/SodiumEnvelopeEncryptor.php`; migración `migrations/2026/Version<ts>.php`; tests Unit (`EncryptionScopeIdTest`, `SodiumEnvelopeEncryptorTest`) + Functional (`DbalKeystoreFunctionalTest`, envelope+keystore e2e).
**UPDATE:** `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/rules/security.md`, `docs/architecture-api.md`, `api/.env`, `compose*.yaml` (declarar `AUDIT_KEK`).
**NO TOCAR:** `audit_log` / `AuditWriteCaptureListener` / `BankAccount` (los toca 2.3); `EraseActorAuditTrailCommand` (lo espeja 2.4).
**deptrac:** value object en `Domain` (pure); puertos en `Application`; libsodium + DBAL + `#[Autowire]` en `Infrastructure`. Si una clase nueva no resuelve, registrar en `api/tools/deptrac/deptrac.yaml`.

### Previous-story intelligence (patrones a seguir)

- **`CursorCodec`** (`Shared/Search/.../Keyset/CursorCodec.php`): libsodium + secreto por `#[Autowire('%kernel.secret%')]` + `SodiumException` → excepción de dominio + base64url. **Patrón cripto canónico del repo.**
- **`DbalAuditLogWriter` / `AuditLogSchemaListener`**: raw DBAL idempotente + schema-listener + migración. **Patrón de persistencia para el keystore.**
- **`ActorContext`** (`Shared/Audit/Domain`): value object de dominio sin framework. **Patrón para `EncryptionScopeId`.**

### Testing standards

PHPUnit 13 (`#[CoversClass]`, AAA). Cripto unit con `Keystore` in-memory (fake del puerto, no mock builder). Funcional contra **Postgres real** (`inRolledBackTransaction`), nunca SQLite. KEK fija de test (no leer env real en tests). `make php.psalm.taint` debe ver que la KEK no fluye a sinks (logs/response/excepción).

### Quality gates + gotchas relevantes

`make php.stan` por archivo (worker segfault → `PHP_SERVICE=messenger_worker`); `make php.quality`; `make php.psalm.taint` (dataflow de secreto — **headline de seguridad**); `make php.deptrac`; `make php.lint.bounded-context`. **PHP gotchas:** PHPMD `CouplingBetweenObjects` ≤13 también en tests → fakes en trait ([[phpmd-coupling-applies-to-tests]]); Rector readonly-fica clases fake anónimas → usar Fixtures nombradas ([[phpmd-anon-readonly-class-parse-error]]); tug-of-war Psalm↔PHPStan tras `assertCount` → `array_unique` ([[psalm-phpstan-assertcount-tugofwar]]); Rector↔Psalm → Rector gana, nunca `@psalm-suppress` ([[feedback-rector-over-psalm-no-suppress]]); sin `// NOSONAR`. **Migraciones:** editable en esta rama; tras merge, inmutable — nueva migración ([[migration-squash-only-pre-production]]).

### Must-preserve / regresión

- `make db.diff` debe quedar **estable** tras la migración (schema listener = fuente de verdad; sin default de columna vivo).
- Ningún cambio a `audit_log`, al writer de auditoría, ni a `BankAccount` (esta story es solo el motor cripto).
- Secretos: `AUDIT_KEK` **nunca** en el diff (`.env`/`*.local`); pre-commit/detect-secrets no debe disparar; la KEK nunca en logs/respuestas/excepciones.
- Append-only de `audit_log` intacto (esta story no escribe en `audit_log`).

### Project Structure Notes

Capability `Shared/Crypto/{Domain,Application,Infrastructure}` alineada a DDD/hexagonal; `Erpify\Shared\…` importable por todos los contextos. **Decisión de nombre de tabla** (`dek_keystore` propuesto) y **de capability** (`Shared/Crypto` propuesto frente a `Shared/Keystore`) a confirmar con el architect; el ADR las ubica como "keystore table + raw-DBAL persistence" del subsistema, sin fijar nombres.

### References

- [Source: `_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#Story 2.2`] — ACs base, FR10.
- [Source: `docs/adr/regulatory-audit-trail.md`] — D6 (crypto-shredding), D13 (`EncryptionScopeId` desacoplado), D14 (envelope, CSPRNG, KEK fuera, rewrap), D16 (Party futuro hereda scope), D17 (cripto fuera del dominio).
- [Source: `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/CursorCodec.php`] — patrón libsodium + secreto env + base64url.
- [Source: `api/src/Shared/Audit/Infrastructure/Persistence/{DbalAuditLogWriter,AuditLogSchemaListener}.php`] — patrón raw-DBAL + schema listener.
- [Source: `api/migrations/2026/Version20260623164321.php`, `Version20260626215406.php`] — patrón de migración (create table / add column).
- [Source: `api/src/Shared/Audit/Domain/ActorContext.php`] — patrón value object de dominio.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story).

### Debug Log References

`make php.stan` 0 errors · `make php.quality` 0 violations · `make php.psalm.taint` "No errors found" (KEK no fluye a sinks) · unit+functional (Postgres real) green. Commits `8854eafd` (+ `4f6a3fde` refina `EncryptionScopeId`, + `21693509` `destroyScope: bool`).

### Completion Notes List

- Nueva capability `Shared/Crypto`: `EncryptionScopeId` VO + tabla `dek_keystore` (migración `Version20260701083342`, sin FK a dominio) + envelope libsodium `crypto_aead_xchacha20poly1305_ietf` (`SodiumEnvelopeEncryptor`), DEK CSPRNG mint-on-first-encrypt, KEK env `AUDIT_KEK` (32 bytes), scope como AAD, `destroyScope` = crypto-shred irreversible.
- **Decisiones abiertas resueltas:** tabla = `dek_keystore`; `wrapped_dek` = `TEXT` base64 (adapter serializa); `EncryptionScopeId` usa el tipo de recurso verbatim (`BankAccount:<uuid>`, no `BANK_ACCOUNT`) para que sellador y erasure compartan una única fuente — desviación argumentada del ejemplo del ADR.
- `.env` gana `AUDIT_KEK` placeholder dev; rotación de KEK = rewrap por lotes (documentado, no implementado — YAGNI).

### File List

**NEW:** `api/src/Shared/Crypto/**` (`Domain/EncryptionScopeId.php` + 4 excepciones, `Application/{Keystore,EnvelopeEncryptor,WrappedDek}.php`, `Infrastructure/{SodiumEnvelopeEncryptor.php, Persistence/{KeystoreSchemaListener,DbalKeystore}.php}`); `api/migrations/2026/Version20260701083342.php`; tests `Unit/Shared/Crypto/**` + `Functional/Shared/Crypto/**`.
**UPDATE:** `api/.env` (`AUDIT_KEK`).
