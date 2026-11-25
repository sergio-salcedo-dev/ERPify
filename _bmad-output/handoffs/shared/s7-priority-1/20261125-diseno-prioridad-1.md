# Diseño: PR "C-1" — riesgos de §7 de Prioridad 1

Estado: **solo diseño, sin código**. Escrito sin `vendor/`, sin Postgres y sin PHP 8.5, a partir de lectura
del árbol por tres investigaciones de solo lectura. Todo lo que dice de Symfony o de comportamiento en
ejecución es una hipótesis hasta verificarlo con `make php.quality` y los tests reales.
Las líneas `file:line` son del checkout en `main` a fecha 2026-11-25 y se mueven; localiza por símbolo.

Ejecución prevista: worktree `fix/shared-s7-priority-1`, una sola PR, las tres capas de revisión de
`CLAUDE.md` antes de darla por hecha. Pasa `make php.quality` y `make pwa.quality`.

## Decisiones del propietario — TOMADAS el 2026-11-25 (Sergio, desde el móvil: "de acuerdo con todo")

Aceptó las recomendaciones tal como se le propusieron. No quedan decisiones abiertas para empezar a implementar.

| # | Decisión | Resolución |
|---|---|---|
| D1 | L1867: borrar usuario pasa de `DELETE` a `POST /backoffice/users/{id}/erase` con `currentPassword` | **Sí.** Rompe la API pública del backoffice; aceptado porque no hay despliegue ni clientes externos |
| D2 | L191: exit code 3 de `audit:gdpr:erase` | **Retirarlo** (deja de ser alcanzable con la transacción atómica) |
| D3 | L1397/L58: diff de campos desconocidos | **`WITHHELD`, fail-closed** |
| D4 | L1397: registro `resource_type -> clase` | **A mano**, con un gate que exija que cada implementador de `AuditedEntity` figure en él |
| D5 | L58: `orphan_role_values` | **Allowlist por patrón** (`^[A-Z][A-Z0-9_]*$` más centinelas `<null>` y `<non-role-shaped>`); sin HMAC |
| D6 | L373: lock durante el SMTP | **No.** Solo la comprobación de vigencia justo antes de enviar; en §7 se escribe como "ventana reducida, no cerrada" |
| D7 | L298: ignorar `api/storage-test/` | **Hecho** (PR #1299) |
| L24 | Carrera GDPR vs. recovery-secret | **No cerrar hasta** revisar `git log -S IdentityRowLock` y ampliar el test funcional (acciones restantes, eje actor, caso inverso) |

## 1. L1867 — `backoffice_user_erase` no re-prueba la contraseña

Hoy: `UserEraseController` (`DELETE /backoffice/users/{id}`, `#[IsGranted('users.erase')]`) solo llama a
`FulfilIdentityErasure::execute()`. `api/.credential-proof-policy` lo clasifica `ordinary` y
`CredentialProofGateTest` lo fija como hueco conocido.

Cambio:
1. Ruta a `POST …/erase` con `#[StrictRequestPayload] EraseIdentityRequest` (`currentPassword`, mismas
   constraints y `#[SensitiveParameter]` que `EraseDocumentRequest`).
2. Controlador inyecta `ProveActorPassword`, `PasswordHasher`, `CurrentPasswordProofThrottle`. Orden
   obligatorio, copia de `UserPatchRolesController`: `ensureWithinBudget($actorId)` → `proveActorPassword->ensure()`
   → `execute($id)`. Así el 400/404/409 llegan después de la prueba y no hay oráculo de existencia.
3. La CLI `identity:gdpr:erase-subject` **no** pide contraseña (no hay sesión; ADR
   `administrative-recovery-channel.md` D11). La prueba vive en el controlador, no en `FulfilIdentityErasure`.
4. `api/.credential-proof-policy`: `backoffice_user_erase :: credential-affecting`; reescribir la cabecera
   ("ocho" → "nueve").
5. `CredentialProofGateTest`: añadir la ruta al conjunto fijado y borrar la aserción `ORDINARY`.
   Revisar `CredentialProofActionRulesGateTest` por conteos "eight".
6. `make sf.routes.manifest` (cambia el verbo en `api/.route-manifest.json`).

Tests: unit `UserEraseControllerTest` (contraseña errónea → no se invoca `execute`; presupuesto agotado →
no se llama a `verify`; orden); Behat `features/backoffice/users/erase.feature` (11 escenarios llevan ahora
`currentPassword`; nuevos: contraseña errónea sin borrar nada y 0 filas `GDPR_*`, falta de campo → 422, id
desconocido + contraseña errónea → 403 `invalid-current-password` y nunca 404, auto-borrado correcto → 409);
funcional con 429 al agotar el presupuesto; `make php.lint.credential-proof` y comprobar que quitar el
`ensure()` lo pone en rojo.

PWA: `UserEraseControl.tsx` reutiliza `CurrentPasswordDialog` (como `UserStatusControl`); puerto
`EraseIdentityRepository.erase(id, currentPassword)`; `FulfilIdentityErasure.run(id, currentPassword)`;
`ApiEraseIdentityRepository` pasa a `post`; `ApiEndpoints.ts`. Tests a tocar: `userEraseControl.test.tsx`,
`ApiEraseIdentityRepository.test.ts`, `FulfilIdentityErasure.test.ts`, `users-erase-real-api.spec.ts`,
`userDetailPage.test.tsx`, `touchTargets.test.tsx`.

Docs: §7 (cerrar), `CLAUDE.md` (regla "credential-proof": "only eight … `backoffice_user_erase` is the same
gap" → nueve), `administrative-recovery-channel.md`, `authorization-model-boundaries.md`,
`docs/api-error-contract.md`.

Riesgo: la prueba gasta el presupuesto compartido (5/min); un admin que borre varios usuarios seguidos lo notará.

## 2. L1539 — el throttle de login usa el email "como se escribió"

Hoy: `security.yaml` `login_throttling: { max_attempts: 5 }`, sin `limiter`. Symfony 8.1.11 admite
`login_throttling.limiter` (confirmado en `config/reference.php`). La clave por defecto minúscula
`username` + IP (**de memoria; verificar** `DefaultLoginRateLimiter` tras `composer install`).
`Email::canonicalise` recorta, quita zero-width, NFC y minúsculas; el throttle no.

Cambio:
1. `CanonicalLoginRateLimiter` en `Iam/Identity/Infrastructure/Security/`, implementando
   `PeekableRequestRateLimiterInterface` (el listener hace `peek` antes de autenticar, y
   `LoginThrottleSpendFunctionalTest` lo exige). Mantener **dos buckets**: global por IP (25/min) y
   local `identificador|IP` (5/min).
2. Identificador: reutilizar `RecoveryBudgetKey::forEmail()` (`Email::tryFrom(...)?->toString() ?? mb_strtolower(trim(...))`,
   el fallback evita oráculo con direcciones inválidas) y **hashear después** (sha256 o HMAC con `kernel.secret`,
   sabiendo que rotar el secreto reinicia los buckets): la clave no debe llevar el email en claro.
3. Declarar los limiters en `rate_limiter.yaml` con `lock_factory: null` y comentario, como los demás
   (no hay `symfony/lock`). Con `limiter` explícito Symfony ignora `max_attempts`/`interval`/`cache_pool`:
   hay que moverlos a los limiters declarados.
4. No tocar `config/reference.php` a mano (`make sf.config.reference`).

Tests: unit con `DataProvider` de grafías que deben compartir clave (`Nora@Erpify.TEST`, espacio al borde,
`​`, `﻿`, ` `, NFD vs NFC), email inválido que sigue gastando, la clave no contiene el email,
IPs distintas → buckets distintos; funcional: 5 fallos con grafías distintas → el sexto da 429, y 5 emails
distintos desde una IP no lo dan. Ojo: `.env.test` baja el limiter anónimo a 5/min y puede tapar el escenario
Behat; cubrirlo en funcional.

Verificar contra `vendor/`: `SecurityRequestAttributes::LAST_USERNAME`, firma de
`AbstractRequestRateLimiter::createLimiters`, y que el listener rellena el atributo antes de leerlo.

## 3. L24 — carrera GDPR vs. canje de recovery-secret (probablemente ya cerrado)

Hallazgo: el código **ya serializa** la escritura. `RecordRecoverySecretAuditBestEffort::record()` envuelve
las cinco acciones en `IdentityRowLock::whileHeld($userId, …)` (`FOR KEY SHARE`), y `FulfilIdentityErasure`
toma `FOR UPDATE` sobre la fila antes de la pasada de auditoría. §7 se contradice a sí mismo (L1000 vs L1047).
Esto es una lectura de código, no una ejecución: antes de cerrar, revisar con
`git log -S "IdentityRowLock" -- api/src/Iam/Identity/Application/RecordRecoverySecretAuditBestEffort.php`
que no falta un writer con actor = sujeto.

Cambio: no mover nada a la transacción (`whileHeld` no se anida). Extender
`LateAuditWriterErasureSerialisationFunctionalTest`: hoy solo cubre `RECOVERY_SECRET_MINTED`; añadir
`REDEEMED`, `REDEMPTION_COMPENSATED`, `REDEMPTION_INTERRUPTED`, una aserción de "ninguna fila con
`actor_id = sujeto`" y el caso inverso (el writer toma el lock primero → actor pseudonimizado,
`actor_erased = true`). Cerrar o fusionar el ítem de §7. Opcional: ampliar `SCOPE` de
`SubjectAuditWriteSerialisationGateTest` a `Iam/Session/Application/` e `Iam/Invitation/`.
Decisión pendiente: confirmar que no existe un caso que la investigación no vio.

## 4. L1397 y L58 — campos personales y texto no elegido en `audit_log.metadata` (misma raíz)

L1397: los campos personales de una fila `change` ya los sella `PiiDiffSealer`; lo que queda en claro son
los no clasificados, y una fila antigua o insertada a mano no pasó por el sellado. El borrado no reescribe
`metadata` (ADR `audit-activity-log.md` D4).
Cambio recomendado, solo en lectura y fail-closed (sin migración): `AuditEventDetailResourceMapper` resuelve
`resource_type → clase` (registro a mano, D4), y por campo: personal (`PersonalDataClassifier::personalFieldsOf`)
solo se sirve si ambos lados son `null` o `{__enc__}`; no personal conocido, en claro; desconocido o tipo
desconocido, `WITHHELD`. Más un gate que recorra los implementadores de `AuditedEntity` y exija que cada
columna ORM sea `#[PersonalData]` o figure en una lista de campos claros.
Tests: `AuditEventDetailResourceMapperTest` (sellado / personal en claro / no personal / desconocido / tipo
desconocido) y una fila `change` insertada por SQL con `{"iban": …}` que debe salir `WITHHELD`.

L58 (`orphan_role_values`): `AuditMetadataShape::StoredValues` admite cualquier string. Restringir cada
elemento a `ROLE_NAME_PATTERN` (`/^[A-Z][A-Z0-9_]*$/D`) o a centinela del servidor (`<null>`,
`<non-role-shaped>`); `StoredIdentityDrift::toAuditMetadata()` sustituye lo demás y conserva el recuento.
Residuo aceptado: una palabra suelta en mayúsculas pasa. Tests en `AuditLogEntryMetadataVocabularyTest`
(email y texto con espacios rechazados; `SUPERVISOR` y `<null>` aceptados) y `StoredIdentityDriftTest`;
actualizar la lista de exclusión de `AuditMetadataVocabularyLivenessGateTest` si se añade clave.
Sin reescritura de filas históricas (no hay despliegue con clientes; si lo hubiera, `UPDATE` puntual).

Docs: §7 (cerrar L1397; reducir L58), ADR `audit-activity-log.md` D4 (lado de lectura), docblocks de
`AuditMetadataShape` y del mapper.

## 5. L191 — `audit:gdpr:erase` no es atómico

Hoy la orquestación vive en `EraseActorAuditTrailCommand`: `anonymise()` (UPDATE en autocommit) y después
`GDPR_ERASURE_EXECUTED` fuera de transacción; un fallo en medio deja el borrado hecho sin evidencia y el
reintento dice "nothing to erase".
Cambio: caso de uso `Shared/Audit/Application/EraseActorAuditTrail` con `TransactionManager`
(patrón de `FulfilIdentityErasure`, no una transacción DBAL anidada). Dentro de `transactional()`:
`anonymise()`; si `affectedRows === 0`, devolver sin loguear; si no, `log(ACTOR_TRAIL_ERASED, SECURITY, …)`
con solo el pseudónimo. Si el log lanza, rollback y las filas conservan el id original. El guard de cero
filas pasa del comando al caso de uso. El comando conserva preflight, confirmación, dry-run; el id original
solo entra como argv (lo redacta `ConsoleCommandRedactionProcessor`), nunca en mensajes ni metadata.
`ERASED_UNRECORDED = 3` deja de ser alcanzable (D2). Ambigüedad residual: commit sin ack, se verifica con `--dry-run`.
Tests: unit con transacción simulada (orden anonymise → log, 0 filas no loguea, logger que lanza), funcional
que falla entre pasos y comprueba `actor_id` original, `actor_erased = false` y ninguna fila
`GDPR_ERASURE_EXECUTED`; actualizar `EraseActorAuditTrailCommandTest` (el exit 3 desaparece o pasa a 1).
Docs: §7, ADR D4 (donde dice que no comparten transacción), `docs/rules/security.md`, docblock de
`DbalAuditActorAnonymiser`. `.audit-evidence-actions` no cambia.

## 6. L373 — correos de invitación tras el commit

Carrera: dos reenvíos concurrentes; el correo con el enlace ya invalidado puede llegar el último.
Messenger queda descartado: `Iam.Invitation => person` en `api/.persistent-transport-policy` prohíbe encolar
y `docs/rules/security.md` ("Token hygiene") prohíbe tokens en transporte.
Cambio: comprobar vigencia justo antes del SMTP en `SendInvitationEmailBestEffort`, con
`Invitation::verify(secret, now)` (tiempo constante, TTL incluido); si el token ya no es el vigente, no
enviar y devolver `mailerAccepted = false` con log distinto. Hay que pasar el selector y el secreto por
separado y mantener `#[SensitiveParameter]` (`SensitiveRecipientAddressGateTest`,
`PersonAddressParameterGateTest`; revisar `api/.person-address-parameter-policy`).
Es una reducción, no un cierre: sigue habiendo TOCTOU de milisegundos. No pasar el token a logs.
Tests: `ResendInvitationTest` (vigente envía; rotado no; revocada/aceptada/expirada no; varias invitaciones),
`SendInvitationEmailBestEffortTest` (lectura que falla se traga), funcional con dos reenvíos secuenciales.
Docs: reescribir §7 como "ventana reducida, no cerrada".

## 7. L298 — `api/storage-test/`

El directorio **no existe** y nada lo referencia (ni tests, ni compose, ni config); no hay residuo versionado.
Añadir `api/storage-test/` al `.gitignore` raíz junto al bloque `api/storage/`. Comprobar con
`git check-ignore -v api/storage-test/x`. Cerrar el ítem en §7 con la nota "directorio inexistente en este checkout".
Confirmar con el propietario si alguna configuración local lo fija.

## Orden de commits sugerido (una PR)
1. L298 (+ verificar L199/L218, posiblemente ya cumplidos).
2. L1539, con su test funcional.
3. L1867 (API + PWA + docs + manifest).
4. L191.
5. L1397 + L58.
6. L24 (tests + cierre de §7).
7. L373.
Al final: tres capas de revisión en paralelo, aplicar cada `patch`, cuerpo de PR con qué es historia y qué
lo forzó la revisión, y una nota de qué quedó sin verificar.
