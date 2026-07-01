---
title: 'audit(crypto): ligar field + old/new + row en el AAD del AEAD para detectar swaps intra-scope'
type: 'feature'
created: '2026-07-02'
status: 'done'
baseline_commit: '4f5615d1dafa0bdc84543f67f2c80998ba6237a9'
context:
  - '{project-root}/docs/adr/regulatory-audit-trail.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `SodiumEnvelopeEncryptor` liga como AEAD-AAD **solo** el `EncryptionScopeId` (`BankAccount:<uuid>`), así que todos los valores PII sellados de un sujeto comparten scope+DEK+AAD. Un actor con escritura sobre `audit_log` puede reubicar un ciphertext intra-scope —mover `holderName`→`iban`, intercambiar `old`↔`new`, o copiar un valor entre filas de cambio del mismo sujeto— y todo descifra limpio: el trail no detecta el reordenamiento (issue #404).

**Approach:** Ligar la identidad de ranura en el AAD → `"<scope>|<field>|<old|new>|<audit_log.id>"`, para que un ciphertext reubicado falle la autenticación al desellar. El encryptor sigue siendo cripto genérica (liga el scope y **añade** un AAD opaco del llamador); el módulo Audit posee la cola `field|posición|id` en un VO reutilizable que E3 reconstruirá byte-a-byte. El `audit_log.id` (UUID v7 de la app) se acuña una vez en el seam y viaja al sellador y a la fila persistida.

## Boundaries & Constraints

**Always:**
- El encryptor liga el scope y le añade el AAD del llamador; `encrypt`/`decrypt` exigen el AAD (sin default). La cola de Audit lleva **solo** `field|posición|id` — un único dueño por hecho.
- El `id` del AAD **debe** igualar el PK persistido: acuñar una vez, pasarlo al sellador **y** al factory (`create(..., ?string $id = null)`, mint-if-null).
- El VO del AAD es la fuente única del formato byte-exacto; `posición` es un enum; el VO rechaza `|` en `field`/`id` (espejo del invariante `:` de `EncryptionScopeId`).
- `null` sigue en claro (ausencia no es PII); campos no-PII y catálogos (`Bank`) intactos, sin scope.

**Ask First:**
- Ampliar el binding más allá de `field|posición|id`, o versionar el token de ciphertext.
- Tocar el esquema de `audit_log` o `dek_keystore`.

**Never:**
- Migración / compatibilidad de ciphertexts previos — **ruptura limpia** autorizada (no hay lector; E3 en backlog; datos solo de dev).
- Enseñar a `Shared/Crypto` qué es "field/old/new" (rompería D13). Meter bookkeeping cripto en `Domain/` (D17). Añadir un `decrypt()` que descifre en producción (eso es E3): aquí solo la firma simétrica + round-trip en tests.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error |
|----------|--------------|-------------------|-------|
| Round-trip | `encrypt(scope, v, aad)` → `decrypt(scope, ct, aad)`, mismo aad | Devuelve `v` | N/A |
| Swap de campo | ct sellado `f=holderName`, desellar con `f=iban` | Falla autenticación | `DecryptionFailed` |
| Swap old↔new | ct sellado `pos=old`, desellar con `pos=new` | Falla autenticación | `DecryptionFailed` |
| Copia entre filas | mismo `field`+`pos`, distinto `auditLogId` | Falla autenticación | `DecryptionFailed` |
| Cross-scope (regresión) | ct de scopeA desellado bajo scopeB | Falla (DEK distinto) | `DecryptionFailed` |
| DELETE snapshot | `old=valor, new=null` | Sella solo `old` (`field\|old\|id`); `new` sigue `null` visible | N/A |
| Valor null | `old=null` en campo PII | Permanece `null`, no se sella | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Crypto/Application/EnvelopeEncryptor.php` -- interfaz: añadir `string $aad` (obligatorio) a `encrypt`/`decrypt`.
- `api/src/Shared/Crypto/Infrastructure/SodiumEnvelopeEncryptor.php` -- `fullAad = scope->toString() . SEP . aad` (SEP privada `|`) en encrypt y decrypt; docblock.
- `api/src/Shared/Audit/Application/PiiFieldPosition.php` -- **NUEVO** enum backed `Old='old'`, `New='new'`.
- `api/src/Shared/Audit/Application/AuditPiiAad.php` -- **NUEVO** VO fuente-única: `for(field, PiiFieldPosition, auditLogId): self` + `toString()`; guarda `|`.
- `api/src/Shared/Audit/Application/AuditLogEntry.php` -- `create(..., ?string $id = null)` → `$id ?? Uuid::generate()`.
- `api/src/Shared/Audit/Application/AuditEntryFactory.php` -- interfaz `create(..., ?string $id = null)` + `mintId(): string` (el factory posee el minteo del id; evita acoplar `Uuid` al listener).
- `api/src/Shared/Audit/Infrastructure/SealedAuditEntryFactory.php` -- hilvana `$id` a `AuditLogEntry::create`.
- `api/src/Shared/Audit/Infrastructure/Persistence/PiiDiffSealer.php` -- `seal(entity, diff, string $auditLogId)`; `sealValue` compone el AAD vía `AuditPiiAad`.
- `api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php` -- acuña el id por entidad vía `entryFactory->mintId()`; a `seal(...)` y a `create(..., id: $id)`.
- `api/tests/Unit/Shared/Audit/Infrastructure/Double/FixedAuditEntryFactory.php` -- añadir `?string $id = null` (compat interfaz).
- `api/tests/.../SodiumEnvelopeEncryptorTest.php`, `.../PiiDiffSealerTest.php`, `.../BankAccountAuditCryptoShreddingFunctionalTest.php` -- ver Tasks.

## Tasks & Acceptance

**Execution:**
- [x] Encryptor: AAD obligatorio + composición `scope|aad` (interfaz + impl + docblock).
- [x] `PiiFieldPosition` (enum) y `AuditPiiAad` (VO + guarda `|`).
- [x] Id opcional mint-if-null hilvanado por `AuditLogEntry`/`AuditEntryFactory`/`SealedAuditEntryFactory` (+ firma del double `FixedAuditEntryFactory`).
- [x] `PiiDiffSealer` recibe `$auditLogId` y sella cada valor bajo su `AuditPiiAad`.
- [x] `AuditWriteCaptureListener` acuña el id una vez y lo pasa al sellador y al factory.
- [x] Tests: `AuditPiiAadTest` (bytes exactos + guarda `|`); `SodiumEnvelopeEncryptorTest` (6 `decrypt`/~11 `encrypt` con AAD constante + caso "AAD distinto → `DecryptionFailed`"); `PiiDiffSealerTest` (3 `seal` con id + round-trip sella→descifra con AAD reconstruido); funcional: reconstruir AAD desde `(field, posición, audit_log.id)` de la fila y descifrar OK, y reubicación falla.

**Acceptance Criteria:**
- Given una escritura de `BankAccount`, when el `onFlush` sella la PII, then el `audit_log.id` persistido iguala el id ligado en el AAD de cada valor (round-trip: reconstruir AAD desde la fila y descifrar con éxito).
- Given un valor sellado reubicado a otro `field`, `old/new` o fila, when se deselle con el AAD de su propia ranura, then falla la autenticación (`DecryptionFailed`).
- Given la suite existente, when se ejecuta, then verde sin regresión; `make php.quality` (deptrac + psalm.taint incl.) verde.

## Design Notes

AAD completo (encrypt y decrypt lo construyen idéntico):
```
BankAccount:<uuid> | holderName | old | <audit_log.id v7>
└── scope: encryptor ──┘ └──── cola: AuditPiiAad ────┘
```
- Inyectivo por construcción: `field` (propiedad), `posición` (`old`/`new`), `id` (UUID) y `scope` (`Type:id`, sin `:`) son `|`-libres; `AuditPiiAad` guarda `|` para preservarlo.
- Cross-scope sigue cubierto por el **DEK-por-scope**, no por el scope-en-AAD; se mantiene el scope en el AAD como defensa en profundidad (coste ~0).
- DELETE: `finalStateSnapshot` da `old=valor,new=null`; el enum liga `field|old|id` sin caso especial. El id se acuña **una** vez y viaja al PK (coherente con `ON CONFLICT (id) DO NOTHING`).

## Verification

**Commands:**
- `make php.unit c='--filter "SodiumEnvelopeEncryptor|PiiDiffSealer|AuditPiiAad|AuditLogEntry|BankAccountAuditCryptoShredding"'` -- expected: verde.
- `make php.stan` sobre cada fichero tocado -- expected: sin errores nivel max.
- `make php.quality` -- expected: verde (cs-fixer, rector, phpmd, deptrac, psalm taint).

## Suggested Review Order

**Design intent (start here)**

- El AAD opaco obligatorio en el puerto de cripto es de donde cuelga todo el cambio.
  [`EnvelopeEncryptor.php:25`](../../api/src/Shared/Crypto/Application/EnvelopeEncryptor.php#L25)

**AAD composition — crypto stays generic**

- El encryptor liga scope y le añade el contexto opaco del llamador; idéntico en encrypt/decrypt.
  [`SodiumEnvelopeEncryptor.php:122`](../../api/src/Shared/Crypto/Infrastructure/SodiumEnvelopeEncryptor.php#L122)

**The AAD contract — audit-owned single source of truth (highest-risk)**

- Fuente única del AAD byte-exacto `field|posición|id`, con guarda de inyectividad.
  [`AuditPiiAad.php:31`](../../api/src/Shared/Audit/Application/AuditPiiAad.php#L31)

- El lado `old`/`new` como enum, no string suelto.
  [`PiiFieldPosition.php:13`](../../api/src/Shared/Audit/Application/PiiFieldPosition.php#L13)

- Guarda marker-less (fallo de wiring, no 4xx), espejo de `InvalidEncryptionScopeId`.
  [`InvalidAuditPiiAad.php:16`](../../api/src/Shared/Audit/Domain/Exception/InvalidAuditPiiAad.php#L16)

**Sealing each value under its slot**

- El sellador recibe el `auditLogId` y sella cada valor bajo su `AuditPiiAad`.
  [`PiiDiffSealer.php:75`](../../api/src/Shared/Audit/Infrastructure/Persistence/PiiDiffSealer.php#L75)

**The seam — the id minted once, bound and persisted (boundary-crossing)**

- El listener acuña el id vía el factory y lo pasa al sellador y a `create()`.
  [`AuditWriteCaptureListener.php:85`](../../api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php#L85)

- El factory posee el minteo del id (evita acoplar `Uuid` al listener).
  [`AuditEntryFactory.php:36`](../../api/src/Shared/Audit/Application/AuditEntryFactory.php#L36)

- `create()` adopta el id pre-acuñado (mint-if-null), que se vuelve el PK persistido.
  [`AuditLogEntry.php:84`](../../api/src/Shared/Audit/Application/AuditLogEntry.php#L84)

**Tests (peripherals)**

- Round-trip real contra Postgres: el AAD reconstruido desde la fila descifra; reubicar falla.
  [`BankAccountAuditCryptoShreddingFunctionalTest.php:75`](../../api/tests/Functional/Shared/Audit/BankAccountAuditCryptoShreddingFunctionalTest.php#L75)

- Bytes exactos del AAD + guardas de la inyectividad.
  [`AuditPiiAadTest.php:18`](../../api/tests/Unit/Shared/Audit/Application/AuditPiiAadTest.php#L18)
