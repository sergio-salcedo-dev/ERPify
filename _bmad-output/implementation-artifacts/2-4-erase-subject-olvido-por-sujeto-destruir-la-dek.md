---
baseline_commit: 6224f2a21de4aebc3e9680c4381f46ea3c233c24
---

# Story 2.4: `erase-subject` — olvido por sujeto (destruir la DEK)

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **responsable de cumplimiento**,
quiero **un "olvídame" por sujeto que destruya su DEK (y borre/anonimice el dato vivo)**,
para **volver ilegible su PII conservando la fila de auditoría, su orden y su integridad (append-only)**.

> **Origen:** Epic 2 (`_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#Story 2.4`), ADR [`regulatory-audit-trail.md`](../../docs/adr/regulatory-audit-trail.md) D6/D13/D15, NFR3. **Requiere 2.2 (keystore/DEK)** y aplica sobre lo que cifra 2.3. Es el cierre GDPR de E2.

### Scope — el comando GDPR de sujeto (distinto del de actor)

Esta story entrega el comando operador-driven `erase-subject`: (1) borra/anonimiza el **dato vivo** del sujeto, (2) **destruye la DEK** de su `EncryptionScopeId` → el ciphertext del diff queda permanentemente ilegible; fila/orden/integridad de `audit_log` intactos. Idempotente. Se auto-audita y es reconciliable. **Nunca** se mezcla con `erase-actor` (D15).

### Dependencias dentro de E2

`2.4` depende de **2.2** (la DEK/keystore que destruye) y opera sobre lo cifrado por **2.3**. Es independiente de 2.1 salvo por compartir el scope.

### Decisiones tomadas (ADR D15 + sesión de arquitectura)

1. **`erase-subject` ≠ `erase-actor`** (D15). `erase-actor` (`audit:gdpr:erase <actor-id>`, ya vivo) anonimiza *quién actuó* (remint de `actor_id` + `actor_erased`, eje hermano D4/D4.1). `erase-subject` anonimiza *de quién es el dato del diff* (destruye la DEK). Disparadores GDPR distintos; **nunca** se fusionan. Descartado: extender `erase-actor` para también *shreddear* DEKs — conflagra dos triggers y acopla sus ciclos de vida.
2. **Dos efectos, atómicos entre sí** (D15): (a) borrado/anonimización del dato vivo, (b) destrucción de la DEK. El append-only de `audit_log` se preserva: la fila **no** se borra ni se edita; solo su clave desaparece (NFR3).
3. **Idempotente** (DEK ya destruida → no-op) — espejo de la idempotencia del `anonymise` del eje hermano (`WHERE ... AND destroyed_at IS NULL`).
4. **Auto-auditado + reconciliable** (D15): emite una fila `security` con acción **distinta** (`GDPR_SUBJECT_ERASED`, no `GDPR_ERASURE_EXECUTED`) registrando el scope; todo scope con DEK destruida debe casar con esa evidencia (cross-check), y una divergencia es violación de integridad detectable.

## Acceptance Criteria

**AC1 — Comando distinto de `erase-actor` (D15).**
Given una solicitud de olvido para un sujeto,
When se ejecuta `erase-subject` (comando de consola operador-driven, nombre/argumento distintos de `audit:gdpr:erase`),
Then el comando existe con la misma ergonomía que `EraseActorAuditTrailCommand` (`--dry-run`, `--force`, confirmación, salida `SymfonyStyle`), pero su dominio es el sujeto/scope, no el actor.

**AC2 — Borra el dato vivo + destruye la DEK (FR9, NFR3, D15).**
Given un sujeto con datos (`BankAccount`),
When se ejecuta,
Then (1) el dato vivo del sujeto se borra o anonimiza (política del módulo dueño — ver Dev Notes) y (2) se destruye la DEK de su `EncryptionScopeId` (`BANK_ACCOUNT:<id>`); el ciphertext del diff en `audit_log` queda **permanentemente ilegible** (descifrar → error/`null` controlado, jamás plaintext); la fila, su orden y su prueba de integridad permanecen (append-only intacto).

**AC3 — Idempotente.**
Given la operación,
When se repite sobre un sujeto ya olvidado,
Then es no-op (DEK ya destruida → `destroyed_at` ya set → no reescribe; dato vivo ya borrado/anonimizado → no falla), reportándolo claramente.

**AC4 — Auto-auditoría con acción propia + reconciliación (D15).**
Given el olvido,
When se completa,
Then emite una fila `security` `GDPR_SUBJECT_ERASED` (durable, write-before-send del eje hermano) con el `encryption_scope_id` en `metadata` (nunca PII); y existe un cross-check (test y/o job) que verifica: **todo scope con DEK destruida ⟺ una fila `GDPR_SUBJECT_ERASED` con ese scope**; una divergencia es violación de integridad detectable.

**AC5 — Separación de conceptos (D15).**
Given `erase-subject` y `erase-actor`,
When se revisan juntos,
Then no comparten servicio, comando ni columna: `erase-actor` toca `actor_id`/`actor_erased`/`ip`/`user_agent`; `erase-subject` toca el keystore (`destroyed_at`) y el dato vivo. Un test fija que ejecutar uno **no** altera el locus del otro.

## Tasks / Subtasks

> Convenciones API: ver 2.1/2.2/2.3. Sin migración nueva (la columna `destroyed_at` del keystore viene de 2.2; `encryption_scope_id` de `audit_log` viene de 2.3). Barrido final de comentarios con ID antes del commit.

### A. Caso de uso de olvido por sujeto (AC2, AC3)

- [ ] **A1.** `api/src/Backoffice/BankAccount/Application/EraseBankAccountSubject.php`
  - [ ] `execute(string $bankAccountId): SubjectErasureResult` (VO con `scope`, `dekDestroyed: bool`, `liveRecordErased: bool`). Pasos, **envueltos en una transacción** (`wrapInTransaction`, patrón del eje hermano — atomicidad dato↔DEK): (1) borrar/anonimizar el `BankAccount` vivo; (2) `EnvelopeEncryptor::destroyScope(EncryptionScopeId::forBankAccount($id))` (2.2). `Uuid::ensure($id)` al borde. Idempotente (ambos pasos toleran "ya hecho").
  - [ ] **Decisión: borrar vs anonimizar el dato vivo (confirmar con architect/usuario).** `BankAccount.iban` es `unique` + `#[Assert\Iban]` + NOT NULL → anonimizar a un centinela rompería validación/unicidad. **Recomendado: hard-delete del `BankAccount` vivo** (la evidencia GDPR retenida es la fila de `audit_log` con DEK destruida; el dato operativo desaparece). Alternativa: estado "erased" con PII nullable (requiere relajar invariantes — más invasivo). Nota: el `BankAccountDeleter` actual hard-borra solo `CLOSED` (→409); el olvido GDPR **prevalece** sobre ese guard (o exige `CLOSED` antes) — cerrar la política en esta story.
- [ ] **A2.** Si se hard-borra: reutilizar/asegurar que el borrado del `BankAccount` dispara su captura `BANK_ACCOUNT_DELETED` (2.3) **antes** de destruir la DEK — el snapshot del DELETE se cifra y luego su clave se destruye (coherente: el olvido alcanza también la última fila). Verificar el orden en la transacción.

### B. Self-audit + acción propia (AC4)

- [ ] **B1.** Emitir `GDPR_SUBJECT_ERASED` vía `AuditLogger->log('GDPR_SUBJECT_ERASED', AuditLevel::SECURITY, resource?, ['encryption_scope_id' => $scope->toString()])` tras la transacción (espejo del self-audit de `EraseActorAuditTrailCommand`; misma limitación de atomicidad conocida D4 — aceptable para un comando síncrono operador-driven). **Nunca** PII en `metadata` (solo el scope id).
  - [ ] Confirmar que la inserción `security` es durable (write-before-send; el comando CLI no tiene transacción de negocio ambiente que revierta → cumple el invariante D3 del eje hermano).

### C. Adapter CLI (AC1)

- [ ] **C1.** `api/src/Backoffice/BankAccount/Infrastructure/Cli/EraseBankAccountSubjectCommand.php`
  - [ ] `#[AsCommand(name: 'bank-account:gdpr:erase-subject', ...)]` (nombre **distinto** de `audit:gdpr:erase`). Argumento `bank-account-id` (UUID; el comando deriva el scope `BANK_ACCOUNT:<id>`). Opciones `--dry-run` (cuenta/preview sin mutar) y `--force` (salta confirmación). Flujo `SymfonyStyle` espejo de `EraseActorAuditTrailCommand` (líneas 30-140): validar UUID → preview → confirmación → `EraseBankAccountSubject::execute` → reportar. Manejo de error: si el self-audit falla tras destruir la DEK, reportar la irreversibilidad (igual asimetría que el eje hermano).
  - [ ] **Ruta consciente:** comando operador-driven (no endpoint) mientras no haya auth (D8/E3). Declararlo.

### D. Reconciliación (AC4)

- [ ] **D1.** Cross-check `scope con DEK destruida ⟺ fila GDPR_SUBJECT_ERASED`: implementar como **test de integridad** (funcional) y, si el architect lo pide, un check ligero en el job de mantenimiento existente (`AuditLogMaintenanceSchedule`) — **YAGNI**: por defecto solo el test; el job como follow-up. (Espejo del cross-check `actor_erased ⟺ GDPR_ERASURE_EXECUTED` de D4.1.)

### E. Tests

- [ ] **E1.** Unit (`EraseBankAccountSubjectTest`, fakes de `EnvelopeEncryptor`/repo): orquesta borrado vivo + destroy DEK; idempotente (segunda vez → no-op, sin excepción).
- [ ] **E2.** Funcional (Postgres real, `inRolledBackTransaction`): sembrar `BankAccount` + filas `change` cifradas (2.3) → `erase-subject` → la DEK queda `destroyed_at` set, descifrar el diff **falla** (`DekDestroyed`), la(s) fila(s) `audit_log` **siguen existiendo** (mismo count, mismo orden), el dato vivo desaparece/anonimizado; emite `GDPR_SUBJECT_ERASED`. Re-run → no-op.
- [ ] **E3.** Funcional (AC5): `erase-subject` **no** altera `actor_id`/`actor_erased` de las filas; y `audit:gdpr:erase` (actor) **no** toca `destroyed_at` del keystore — loci independientes.
- [ ] **E4.** Funcional/CLI (`EraseBankAccountSubjectCommandTest` unit con spies + functional con DB real): `--dry-run` no muta; confirmación declinada aborta; UUID inválido → `Command::INVALID`; happy path → `Command::SUCCESS`. Espejo de `EraseActorAuditTrailCommandTest`/`...FunctionalTest`.
- [ ] **E5.** Reconciliación: test que crea divergencia (DEK destruida sin self-audit) y prueba que el cross-check la detecta.

### F. Docs

- [ ] **F1.** `docs/rules/security.md` + `PRODUCTION_SECURITY_CHECKLIST.md` — `erase-subject` (A.5.34 / derecho de supresión): destruye DEK, conserva append-only; distinto de `erase-actor`; irreversibilidad; acción `GDPR_SUBJECT_ERASED`; reconciliación.
- [ ] **F2.** `docs/architecture-api.md` — comando `bank-account:gdpr:erase-subject`, su caso de uso, y la composición con el erasure de actor (loci distintos).
- [ ] **F3.** Si esta story **resuelve o roza** el gap de async-resurrection (issue #376, citado por el ADR), actualizar/enlazar; si no, dejar nota explícita de que sigue abierto.
- [ ] **G.** **Barrido final:** sin comentarios con ID; `make php.stan` por archivo + `make php.quality` + `make php.psalm.taint` + `make php.behat` (si hay feature) antes del commit.

## Dev Notes

### Estado actual (verificado)

- **`EraseActorAuditTrailCommand`** (`Shared/Audit/Infrastructure/Cli`, `audit:gdpr:erase`): argumento `actor-id`, `--dry-run`/`--force`, confirmación, delega en `AuditActorAnonymiser::anonymise` (un solo UPDATE: remint `actor_id` + `ip/user_agent='[REDACTED]'` + `actor_erased=TRUE`), self-audit `GDPR_ERASURE_EXECUTED` con pseudónimo en `metadata`, `actor_type=system`. **Patrón exacto a espejar** (estructura, no servicio). Tests: `EraseActorAuditTrailCommandTest` (unit, spies `RecordingAuditActorAnonymiser`/`RecordingAuditLogger`), `EraseActorAuditTrailCommandFunctionalTest`, `AuditActorAnonymiserFunctionalTest`.
- **Idempotencia del eje hermano:** `anonymise` re-ejecutado no encuentra filas (`WHERE actor_id = original`); `erase-subject` consigue lo mismo con `destroyed_at IS NULL` en el keystore (2.2).
- **`AuditLogger`** (puerto `Shared/Audit`): `log(action, level, resource?, metadata?)`, ramifica por nivel; `security` = síncrono durable. Es el seam para el self-audit.
- **`BankAccountDeleter`** (`Backoffice/BankAccount/Application`): hard-delete; hoy solo `CLOSED` (→409). La política GDPR de borrado vivo se decide en esta story.
- **`EnvelopeEncryptor::destroyScope` / `Keystore::destroy`** vienen de 2.2 (idempotente: `UPDATE ... SET destroyed_at, wrapped_dek=NULL WHERE destroyed_at IS NULL`).
- **Retención (Story 1.6, MERGED):** el suelo de 5 años para `level=change` ya está (`AuditRetentionPolicy` `P5Y`); el olvido **dentro** del suelo se satisface por crypto-shredding (esta story), **no** borrando filas (D7) — confirma que `erase-subject` no debe borrar filas de `audit_log`.

### Decisión de arquitectura (argumentada)

- **Principio (D15 separación de triggers GDPR):** "de quién es el dato" y "quién actuó" son derechos/operaciones distintos; mezclarlos acopla ciclos de vida y rompe la trazabilidad. Dos comandos, dos servicios, dos loci.
- **Objetivo:** olvido efectivo (PII ilegible) **sin** romper append-only ni la integridad que el regulador audita (NFR3) — la fila sobrevive como evidencia, la clave no.
- **Coste / descartada:** borrar la fila de `audit_log` (destruye evidencia ISO, viola D7/append-only); redactar el diff en sitio (rompe append-only). Destruir la DEK cuesta un UPDATE en el keystore y deja todo lo demás intacto — exactamente la propiedad que el envelope de 2.2 compra.
- **Borrado vivo (decisión abierta, recomendada hard-delete):** ver Task A1. La evidencia retenida es la fila de auditoría con DEK destruida, no el `BankAccount` operativo.

### Source tree — archivos a tocar

**API NEW:** `Backoffice/BankAccount/Application/EraseBankAccountSubject.php` (+ `SubjectErasureResult` VO), `Backoffice/BankAccount/Infrastructure/Cli/EraseBankAccountSubjectCommand.php`; tests Unit + Functional (caso de uso, comando, composición con erasure de actor, reconciliación).
**API UPDATE (si aplica):** `AuditLogMaintenanceSchedule` (solo si se añade el check de reconciliación como job — por defecto NO); posiblemente el `BankAccountDeleter`/política de borrado según la decisión A1.
**NO TOCAR:** `EraseActorAuditTrailCommand`/`AuditActorAnonymiser` (no fusionar — D15); el writer/append-only de `audit_log`; el keystore interno (solo vía puerto `EnvelopeEncryptor`/`Keystore`).
**deptrac:** caso de uso en `Application`, comando en `Infrastructure/Cli`; importa `Shared/Crypto` (`EnvelopeEncryptor`, `EncryptionScopeId`) y `Shared/Audit` (`AuditLogger`) — `Shared` siempre importable. Si no resuelve → `api/tools/deptrac/deptrac.yaml`.

### Previous-story intelligence (patrones a seguir)

- **`EraseActorAuditTrailCommand` + `AuditActorAnonymiser` + `ActorAnonymisationResult`** — estructura CLI, idempotencia, self-audit, manejo de fallo post-mutación. **Espejo directo.**
- **D4.1 cross-check** (`actor_erased ⟺ GDPR_ERASURE_EXECUTED`) — patrón de reconciliación a replicar para `scope destruido ⟺ GDPR_SUBJECT_ERASED`.
- **2.2 keystore** — `destroy` idempotente; `EnvelopeEncryptor::destroyScope`.
- **2.3** — la fila lleva `encryption_scope_id`; el snapshot del DELETE se cifra (orden de operaciones en A2).

### Testing standards

PHPUnit 13 (`#[CoversClass]`, AAA; unit con fakes de puertos; **funcional Postgres real** `inRolledBackTransaction` — assert que tras `erase-subject` el diff es indescifrable y la fila persiste). Behat opcional (escenario "olvídame" end-to-end). Tests de composición con erasure de actor y de reconciliación son load-bearing (AC4/AC5). Patrón de spies del eje hermano (`Recording*`).

### Quality gates + gotchas relevantes

`make php.stan` por archivo (`PHP_SERVICE=messenger_worker`); `make php.quality`; `make php.psalm.taint`; `make php.deptrac`; `make php.lint.bounded-context`; `make php.behat` (si hay feature). **PHP gotchas:** `#[AsCommand]` en `Infrastructure/Cli`; Rector `CatchExceptionNameMatchingType` renombra `catch ($x)` al tipo → nombres largos disparan PHPMD `LongVariable` → no capturar si no se usa ([[rector-catch-var-vs-phpmd-longvariable]]); `#[CoversClass]` + PHPMD coupling ≤13 en tests → fakes en trait; Behat: +2 queries por write envuelto (BEGIN/COMMIT), +4 por delete → ajustar budgets si hay feature ([[behat-query-budget-transaction-overhead]]); `messenger:consume` en test resetea el transporte in-memory → usar `Worker` real ([[behat-consume-inmemory-worker-not-command]]). Sin `// NOSONAR`.

### Must-preserve / regresión

- **Append-only de `audit_log` intacto:** `erase-subject` **no** borra ni edita filas de `audit_log` (solo el keystore + el dato vivo). Mismo count/orden tras el olvido.
- **`erase-actor` intacto** y separado (D15): ejecutar `erase-subject` no toca `actor_id`/`actor_erased`; ejecutar `erase-actor` no toca `destroyed_at`.
- **Suelo de retención (Story 1.6):** el olvido dentro del suelo es por DEK, no por borrado de fila.
- **Secretos:** la KEK nunca aparece; el self-audit nunca lleva PII (solo el scope id).

### Project Structure Notes

Caso de uso + CLI en `Backoffice/BankAccount` (el módulo dueño del dato vivo), orquestando el `Shared/Crypto` (destruir DEK) y `Shared/Audit` (self-audit). Cuando llegue un agregado `Party` (D16), `erase-subject` se generaliza al nuevo scope sin renombrar el concepto cripto — hoy, BankAccount-específico (YAGNI). El nombre del comando (`bank-account:gdpr:erase-subject`) y la política de borrado vivo se confirman con el architect.

### References

- [Source: `_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#Story 2.4`] — ACs base, FR9/NFR3.
- [Source: `docs/adr/regulatory-audit-trail.md`] — D6 (crypto-shredding), D13 (`EncryptionScopeId`), D15 (subject ≠ actor erasure), D7 (olvido dentro del suelo = shredding), D16 (Party futuro).
- [Source: `docs/adr/audit-activity-log.md`] — D4 (erase-actor, atomicidad self-audit), D4.1 (`actor_erased`, cross-check) — patrón a espejar sin reabrir.
- [Source: `api/src/Shared/Audit/Infrastructure/Cli/EraseActorAuditTrailCommand.php` + `Application/AuditActorAnonymiser.php` + `Application/ActorAnonymisationResult.php`] — patrón CLI + idempotencia + self-audit.
- [Source: `api/src/Shared/Audit/Application/AuditLogger.php`] — seam del self-audit `security`.
- [Source: `_bmad-output/implementation-artifacts/2-2-keystore-envelope-libsodium-dek-por-encryptionscopeid.md`] — `EnvelopeEncryptor::destroyScope`/`Keystore::destroy` (idempotente).
- [Source: `api/src/Backoffice/BankAccount/Application/BankAccountDeleter.php`] — política de borrado vivo actual (CLOSED→409).
- Relacionado: issue #376 (async-resurrection gap en el erasure de actor).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
