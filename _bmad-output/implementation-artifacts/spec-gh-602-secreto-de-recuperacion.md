---
title: '#602 — secreto de recuperación con puerta de credencial (caso administrador único)'
type: 'feature'
created: '2026-08-28'
status: 'in-progress'
review_loop_iteration: 0
baseline_commit: '57b93dc2'
context:
  - '{project-root}/docs/adr/administrative-recovery-channel.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** un administrador único bloqueado no tiene ninguna arista de recuperación. Las tres
entregadas no le sirven: `users.unlock` exige un segundo ADMIN, y las dos de observabilidad son
detectivas. El cerrojo se indexa por email, que no es un secreto, así que quien lo conozca resella la
cuenta cada 15 minutos por ~10 intentos fallidos.

**Approach:** un secreto de recuperación `<selector>.<secreto>` sobre `SingleUseToken`, en agregado
propio. Se **acuña desde sesión viva demostrando la contraseña actual** (nunca en el terminal del
vendor, nunca por email) y se muestra en claro **una sola vez**. Se **canjea anónimamente** gastando
sólo el presupuesto por selector: el canje establece sesión autenticada y limpia el cerrojo. Esa sesión
sobrevive a todo resellado posterior, porque el gate de admisión lee la fila de sesión y nunca
`locked_until`.

## Boundaries & Constraints

**Always:**
- **I-1** — ningún limitador del camino de canje se indexa por email ni por identidad. Sólo
  `token_action_per_selector`. El agotamiento se pliega en el mismo muro opaco que un enlace muerto.
- **Corolario de I-1** — el selector es una **capacidad de denegación**: quien lo conozca cierra el
  canje en silencio. No puede salir a evento, `audit_log`, log, DTO, vista de sesión ni URL.
- **I-2** — el texto plano se emite una vez, en el cuerpo de la respuesta de acuñación, y en ningún
  otro sitio de su vida. Sólo se persiste el digest.
- El re-login ocurre **antes** de consumir la fila. Un fallo deja el secreto intacto y responde 503.
- Los eventos nombran al **usuario**, nunca al selector (convención del reset; #648 es el contraejemplo).
- El muro de estado es `ensureActive()` explícito, no `UserChecker` — `checkPostAuth` no corre en el
  camino programático. El cerrojo **no** se reaplica ahí, que es lo que este canal necesita.
- **I-A — serialización sobre la fila del secreto.** El selector se resuelve **sin bloqueo y sin
  autorizar nada**; la decisión de consumir o revocar se toma sobre una **relectura `FOR UPDATE`** de esa
  fila, y toda la validación (existencia, `expiresAt`, `verify()`, `ensureActive()`) se hace sobre la fila
  ya bloqueada. `findBySelector()` **no** bloquea: un método que bloquea implícitamente se reutiliza en el
  orden equivocado. Sin esto, dos canjes concurrentes verifican antes de que ninguno retire la fila y el
  «un solo uso» es una afirmación del comentario, no del sistema.
- **I-B — serialización sobre la fila del usuario, y el orden es el invariante.** Ambos caminos que
  mutan el cerrojo adquieren `User` **primero**: el canje toma `User FOR UPDATE` (por el `userId` que le
  dio la resolución del selector) y **después** `RecoverySecret FOR UPDATE`; `recordFailure()` toma
  `User FOR UPDATE` por email y no toca el secreto. Así ningún camino adquiere el secreto antes que el
  usuario, que es lo que hace demostrable la ausencia de ABBA. Sin esto, un fallo de login concurrente
  reescribe `locked_until` después del desbloqueo del canje.
- **TTL explícito** — `RECOVERY_SECRET_TTL = P10Y`, constante nombrada. `SingleUseToken` exige un TTL,
  así que «no caduca» no es representable; diez años es la forma honesta de decirlo y queda visible en el
  esquema. Un TTL corto reintroduciría por la puerta de atrás la destrucción silenciosa que rechazamos al
  decidir no invalidar con el cambio de contraseña.
- **El selector es un UUID v7 de `symfony/uid`, y su impredecibilidad se afirma acotada.** No es un
  sorteo CSPRNG por id: siembra con `random_bytes(16)` y, dentro del mismo milisegundo, **incrementa** la
  parte aleatoria con 24 bits de una cadena SHA-512 de la semilla (monotonicidad del estándar). Lo que
  cierra el hueco no es la entropía sino el modelo de amenaza: adivinar un selector **sólo compra
  denegación, nunca autenticación** —el secreto es el autenticador—, y esa denegación está dominada por el
  ataque que #602 ya documenta, que es más barato y no exige adivinar nada. Jamás `userId`, email ni un
  contador; la prueba lo comprueba **por construcción**, no estadísticamente.

**Ask First:**
- Cualquier presupuesto sobre el canje que no sea por selector.
- Hacer la acuñación alcanzable sin `currentPassword`.
- Cambiar la política de invalidación fijada abajo.

**Never:** entrega por email o por CLI · rotación automática del secreto en la respuesta del canje ·
`ReauthenticateDeviceBestEffort` en el canje · reutilizar `identity_password_reset_token` · puerta de
rol (cualquier identidad ACTIVE) · forzar ≥2 administradores · el secreto en query string.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Acuñación | sesión viva + `currentPassword` correcta | 201 con el texto plano una vez | — |
| Acuñación, contraseña mala | sesión viva + `currentPassword` incorrecta | 403 `invalid-current-password` | presupuesto por identidad consumido |
| Acuñación, ya existe uno vivo | sesión viva, fila presente | 409; no se reemite ni se re-muestra | — |
| Canje válido | `<selector>.<secreto>` correcto, cuenta ACTIVE y bloqueada | 204 + sesión autenticada; cerrojo limpio; fila retirada | — |
| Canje inválido / caducado / presupuesto agotado | cualquiera de los tres | mismo 400 opaco | indistinguibles entre sí |
| Canje sobre identidad no ACTIVE | SUSPENDED/DEACTIVATED/INVITED/REVOKED | 403; fila **no** consumida | `ensureActive()` antes de consumir |
| Fallo al establecer sesión | login lanza tras verificar | 503; secreto **intacto** | nada se consume |
| Revocación | dueño revoca desde perfil | 204; fila borrada | — |
| Dos canjes concurrentes | mismo secreto, dos peticiones | exactamente **uno** gana; el otro ve el 400 opaco | serializado por `FOR UPDATE` |
| Canje vs revocación | ambos en vuelo | gana quien tome antes el bloqueo; el perdedor es no-op u opaco | nunca un verify sobre fila ya revocada |
| Canje vs login fallido | resellado concurrente | el `locked_until` del atacante nunca queda como estado final del canje ganador | bloqueo compartido de `User` |
| Login OK, mutación DB falla | sesión ya establecida | 5xx; secreto **sigue vivo** y recanjeable | sesión no se revierte; operación idempotente |

</frozen-after-approval>

## Code Map

- `api/src/Iam/Identity/Domain/Entity/PasswordResetToken.php` -- patrón exacto a espejar (selector = PK,
  digest, `verify()` opaco, `#[PersonSubjectReference]`).
- `api/src/Shared/Token/Domain/SingleUseToken.php` -- primitivo; **TTL obligatorio** → expiración lejana explícita.
- `api/src/Iam/Identity/Infrastructure/Http/CompletePasswordResetController.php` -- forma del canje, y el
  antipatrón a evitar (`:76` re-autentica post-commit, best-effort).
- `api/src/Iam/Identity/Infrastructure/Security/ReauthenticateDevice.php` -- seam de `Security::login()`.
- `api/src/Iam/Identity/Infrastructure/Security/PasswordRecoveryThrottle.php` -- consumo por selector.
- `api/src/Iam/Identity/Infrastructure/Http/PasswordResetOriginListener.php` -- guarda same-origin a replicar.
- `api/src/Iam/Identity/Application/ChangeMyPassword.php` -- verificación de `currentPassword`.
- `api/src/Iam/Identity/Application/LoginAttemptRegistrar.php:51` -- la carrera diferida (`findByEmail` sin bloqueo).
- `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DbalPasswordResetTokenPersonReferences.php` -- modelo de detective.
- `api/.person-reference-policy:196,213` · `api/.public-access-exemptions:76-77` · `api/.audit-evidence-actions:67` -- formatos.
- `pwa/src/app/backoffice/profile/_components/ChangePasswordForm.tsx` · `pwa/src/app/(auth)/reset-password/page.tsx` -- superficies PWA.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Iam/Identity/Domain/Entity/RecoverySecret.php` -- agregado nuevo (selector = PK, `userId`
      con `#[PersonSubjectReference]`, digest, expiración lejana, `verify()` opaco). Evento nombra al usuario.
- [x] `api/migrations/` -- `make db.diff` para `identity_recovery_secret`.
- [x] `api/src/Iam/Identity/Domain/Repository/RecoverySecretRepository.php` + implementación Doctrine --
      `findBySelector`, `save`, `remove`, `deleteAllForUser` (borrado GDPR).
- [x] `api/src/Iam/Identity/Application/MintRecoverySecret.php` -- verifica `currentPassword`, refusa si ya
      hay uno vivo, devuelve el texto plano una sola vez.
- [x] `api/src/Iam/Identity/Application/RedeemRecoverySecret.php` -- resuelve opaco → `ensureActive()` →
      `Security::login()` → **luego** transacción: limpia cerrojo + retira fila.
- [x] `api/src/Iam/Identity/Application/RevokeRecoverySecret.php` -- borrado explícito por el dueño.
- [x] Controladores en `api/src/Iam/Identity/Infrastructure/Http/` -- `POST /me/recovery-secret`,
      `DELETE /me/recovery-secret`, `GET /me/recovery-secret` (metadatos: existe y desde cuándo, **nunca**
      el selector), `POST /recovery/redeem` (anónimo, guarda same-origin, presupuesto por selector).
- [x] `api/config/packages/security.yaml` + `api/.public-access-exemptions` -- exención `exact` del canje.
- [x] `api/.person-reference-policy` -- `$id => non-person`, `$userId => person :: …EraseIdentitySubject.php`.
- [x] `api/src/Iam/Identity/Application/EraseIdentitySubject.php` + `Dbal…RecoverySecretPersonReferences.php`
      -- borrado GDPR y detective tagueado.
- [x] `api/.audit-evidence-actions` -- `RECOVERY_SECRET_MINTED|REDEEMED|REVOKED => ordinary`.
- [x] `api/src/Iam/Identity/Application/LoginAttemptRegistrar.php` -- cerrar la carrera: lectura bajo
      bloqueo de fila (`findByEmailForUpdate`), en la misma transacción que la mutación.
- [x] `pwa/src/app/backoffice/profile/` -- pantalla de acuñar/ver/revocar (`metadata.title`, copy en
      inglés). Muestra **creado y caduca**, no sólo que existe: su caducidad es la única forma en que el
      canal muere sin que nadie actúe.
- [x] `pwa/src/app/(auth)/recovery/page.tsx` -- canje; el secreto se **teclea o pega**, jamás en la URL.
- [x] `api/tests/` + `api/features/` -- PHPUnit por fila de la matriz; Behat del canje con
      `SELECT … AND locked_until > NOW()` intermedio antes de afirmar. **Concurrencia obligatoria**: dos
      canjes simultáneos (gana uno), canje vs revocación, canje vs login fallido, login OK + mutación DB
      fallida (secreto recanjeable). Más: el selector nunca aparece en evento/auditoría/log/DTO, el
      selector no deriva de `userId`, y el cambio de contraseña no invalida el secreto.
- [x] `_bmad-output/implementation-artifacts/sprint-status.yaml` -- clave `br-4e-602-secreto-de-recuperacion`.
- [x] `docs/adr/administrative-recovery-channel.md` + `docs/deployment-guide.md` +
      `PRODUCTION_SECURITY_CHECKLIST.md` -- registrar el mecanismo elegido y sus residuales.
- [x] **Riesgo aceptado — issue #870 ya abierta.** Etiquetar el párrafo del agregado con
      `@accepted-risk #870`, en la forma de `NotifyLockedIdentities.php:88-91`. Un riesgo aceptado exige
      artefacto abierto y rastreable, no un docblock: los diez años son una decisión, no una consecuencia
      accidental de que `SingleUseToken` exija TTL. Gates: `AcceptedRiskTagGateTest` y el workflow
      `accepted-risk-live-state.yml`, que exige que #870 siga **abierta**.
- [x] **Puerta de credencial en la revocación** (decisión 2026-08-29).
      `api/src/Iam/Identity/Infrastructure/Http/RevokeRecoverySecretRequest.php` nuevo, y la constante
      `EXISTING_CREDENTIAL_CEILING` extraída a un sitio compartido en vez de una tercera copia.
      `RevokeMyRecoverySecretController` pasa a `POST /me/recovery-secret/revoke` con
      `#[StrictRequestPayload]` y `CurrentPasswordProofThrottle`; el `DELETE` desaparece.
      `RevokeRecoverySecret::revoke()` toma el cierre de verificación y, dentro de su transacción,
      `findByIdForUpdate` → `proveCurrentPassword` → `findByUserIdForUpdate`, en el orden canónico de I-B.
      Sin `ensureActive()`. Sin alimentar `LoginAttemptRegistrar`.
- [x] **PWA**: `revoke()` deja de usar `delete` y pasa a `post` con cuerpo — **sin ensanchar el puerto
      `HttpClient`**, que es la razón de que la ruta sea POST. El diálogo de revocación gana campo de
      contraseña, con la copia en inglés y los `data-testid` existentes intactos.
- [x] **Concurrencia**: `RecoverySecretLockOrderFunctionalTest` cubre el nuevo camino de dos cerrojos
      (revoke vs acuñación, revoke vs canje) con las sondas NOWAIT que ya usa. El análisis ABBA se re-corre
      con revoke dentro, en vez de heredarse.
- [x] **Docs**: ADR D7, `PRODUCTION_SECURITY_CHECKLIST.md`, guía de despliegue y `api/docs/` dicen la
      superficie real; ningún documento puede seguir describiendo un `DELETE` que ya no existe.


**Acceptance Criteria:**
- Dado un secreto acuñado y una cuenta con `locked_until` en el futuro (verificado por `SELECT`
  intermedio), cuando se canja, entonces la respuesta es 2xx **con sesión establecida** — y la prueba se
  pone en rojo si se elimina la transición.
- Dado que el dueño cambia su contraseña, cuando existe un secreto vivo, entonces **sigue siendo válido** y
  la pantalla de perfil lo muestra con su fecha de acuñación y un botón de revocar.
- Dado un canje cuyo establecimiento de sesión falla, cuando se responde, entonces es 503 y el secreto
  sigue siendo canjeable.
- Dado un canje que falla por secreto inválido, caducado o presupuesto agotado, entonces el cuerpo y el
  estado son **idénticos** y no existe rama observable por identidad. Un secreto válido sobre identidad no
  ACTIVE es el caso **identificado** (403) y no pertenece a ese conjunto — la matriz manda sobre esta línea.
- Dado un canje ganado, `RECOVERY_SECRET_REDEEMED` se emite **sólo tras confirmar el consumo persistido**,
  nunca tras la mera verificación.
- Ningún evento, fila de auditoría, log ni DTO contiene el selector — con prueba que lo afirme.
- Dado un secreto vivo y una sesión autenticada, cuando se revoca **sin** la contraseña correcta, entonces
  la fila **sigue existiendo** y la respuesta es la refusal del contrato, no un 204. Con el presupuesto
  compartido agotado, la respuesta es 429 y tampoco se borra nada.
- Dada una identidad **sin** secreto y la contraseña correcta, la revocación sigue siendo un éxito vacío:
  la existencia de la fila no cambia ni el código ni la forma de la respuesta.

## Spec Change Log

- **2026-08-28 · review externa de la spec (11 hallazgos: 3 P0, 5 P1, 3 P2).** Amendado: (a) dos
  invariantes de serialización explícitas — I-A sobre la fila del secreto, I-B sobre la del usuario, con
  orden de adquisición fijado usuario→secreto para no reintroducir un ABBA; (b) contrato de **fallo
  parcial** entre `Security::login()` y la mutación, porque `RecoverySecret`/`User.lockout`/`Session` son
  tres máquinas de estado que ninguna transacción ACID abarca y la spec hablaba de ellas como si el flujo
  fuese atómico; (c) cuatro filas de matriz de concurrencia y las pruebas que las cubren; (d)
  `RECOVERY_SECRET_TTL = P10Y` en lugar de «expiración lejana», que no era una especificación; (e) el
  criterio de opacidad ya no se contradice con el 403 de `ensureActive()`, y deja de prometer «latencia
  indistinguible», infalsable sin métrica; (f) `RECOVERY_SECRET_REDEEMED` se emite sólo tras el consumo
  confirmado; (g) la afirmación sobre el vendor se acota — impide la acuñación administrativa y de
  terminal, **no** es una frontera criptográfica frente a root.

  Estado malo que esto evita: un «un solo uso» que descansaba en que `remove()` ocurriese después de
  `verify()`, con dos peticiones concurrentes verificando la misma fila viva.

  **KEEP:** el borrado duro sin columna de ciclo de vida (retener filas consumidas dejaría viva una
  referencia a persona; el un-solo-uso lo da el `FOR UPDATE`), y PK-como-selector con los eventos
  nombrando al usuario (UUID v7 = 74 bits aleatorios; una columna aparte añade identificador sin cerrar la
  clase de fuga). Ambas discrepancias con el review, sostenidas y confirmadas por Sergio.

- **2026-08-28 · segunda review de la spec — «approve with minor amendments», sin rediseño.** Amendado:
  (a) el protocolo de bloqueo se hace ejecutable — el selector se resuelve sin bloqueo, la decisión se
  toma sobre una relectura `FOR UPDATE`, y `findBySelector()` no bloquea porque un método que bloquea
  implícitamente acaba reutilizado en el orden equivocado; (b) «operación idempotente» pasa a **estado
  parcial reintentable**, que es lo que está probado; (c) «un solo uso» se define como *como mucho un
  consumo persistido*, no *como mucho una autenticación*; (d) «auditable por ausencia» baja a **detectable
  por el propietario**, y se dice que la desaparición no identifica a quién consumió; (e) el TTL de diez
  años se registra como riesgo aceptado con issue abierta y `@accepted-risk`, no como nota en el ADR.

  **Corrección medida, que ninguna de las dos reviews tenía.** La enmienda pedía sustituir «UUID v7 = 74
  bits impredecibles» por «CSPRNG del runtime». Medido en `vendor/symfony/uid/UuidV7.php`, **las dos son
  falsas**: siembra con `random_bytes(16)` pero dentro del mismo milisegundo **incrementa** la parte
  aleatoria con 24 bits de una cadena SHA-512 de la semilla, en vez de sortear de nuevo. La spec afirma
  ahora lo que corre, y cierra el hueco por modelo de amenaza —adivinar un selector sólo compra
  denegación, dominada por el ataque que #602 ya documenta— en vez de por una propiedad de entropía que el
  código no tiene.

- **2026-08-29 · la única decisión que el pase adversarial dejó abierta, resuelta por Sergio.** Revocar
  pasa a exigir la contraseña actual sobre el **mismo** bucket que acuñar y cambiar contraseña, y la ruta
  pasa de `DELETE /me/recovery-secret` a `POST /me/recovery-secret/revoke`, sin alias. Los detalles, las
  tres mediciones que fijaron la forma, la consecuencia de cerrojos y las cuatro alternativas rechazadas
  están en `## Adversarial pass`, que es donde vive el hallazgo. Se consultó a una IA externa; coincidió en
  el fondo, y se le corrigieron dos cosas contra el árbol: el nombre `revocation` (los cuatro precedentes
  del repo son verbos) y un objeto `CurrentPasswordProof` en Application, que sería un retroceso sobre el
  cierre que `ProveCurrentPassword` ya usa para no dejar salir el texto plano de Infrastructure.

  Queda **fuera** de esta rama, con issue de seguimiento ([#874](https://github.com/sergio-salcedo-dev/ERPify/issues/874)): convertir el invariante en gate — un registro
  clasificando qué actos son credential-affecting, en la forma de los otros registros de `api/.*`. Es una
  pieza propia y la rama está terminada.

- **2026-08-29 · la rama llevaba ROJA en CI desde `52894e09`, y la lista de verificación del traspaso decía
  lo contrario.** `php.cs.dry-run` salía 2 sobre `RedeemRecoverySecret.php:127` — fichero sin modificar,
  introducido por esta misma rama. Es la trampa que este repo ya tiene registrada: `make php.quality` corre
  `php.cs` en modo *apply* y lo tapa, mientras CI corre `php.quality.dry-run`. Un gate que se declara verde
  desde la ejecución equivocada es peor que uno rojo, porque nadie vuelve a mirarlo.

  La causa es un empate real entre las dos herramientas de estilo, medido en ambas direcciones: con
  `catch (A|B $e)` phpcs=2 / cs-fixer=0; con `catch (A | B $e)` phpcs=0 / cs-fixer=2. Las dos están dentro de
  `php.quality.dry-run`. Descartados subir versión (phpcs ya es 4.0.4, la última mayor) y reestructurar
  (`AccountDeactivated` y `AccountSuspended` son `final … implements Forbidden` sin padre propio, así que
  capturar el padre ensancharía el `catch`).

  **Resuelto excluyendo el sniff en `api/tools/phpcs/phpcs.xml`, y la exclusión se apoya en dos mediciones,
  no en preferencia.** phpcs se equivoca: acepta todas las uniones compactas en firmas del árbol
  (`array|string|null`) y refusa sólo la grafía del multi-catch, siendo el mismo `|` con el mismo
  significado. Y además sobra: php-cs-fixer configura `binary_operator_spaces => single_space`, **más
  estricto** que el `at_least_single_space` de @PSR12 — verificado plantando `$probe = 1+2;`, que pone
  `php.cs-fixer.dry-run` en 2. La exclusión es estrecha: con un `if($x){…}` plantado, phpcs sigue dando cinco
  errores PSR12.

- **2026-08-29 · consulta a Amelia y Murat sobre a qué nivel fijar el contrato de revoke, y las dos me
  corrigieron la misma premisa.** Yo había puesto como coste de Behat «vocabulario nuevo y su clasificación
  en `api/.behat-step-vocabulary`». **Falso, medido**: `git diff origin/main...HEAD -- api/.behat-step-vocabulary`
  está vacío — las 134 líneas del feature del canje se escribieron íntegras con pasos existentes, y todos los
  que revoke necesitaría ya están `used`, incluido `the password-change budget is exhausted for identity`
  (`RateLimitContext.php:82`), que ceba **el mismo** `limiter.password_change_per_identity` que revoke gasta.
  La decisión no podía apoyarse en el coste, que era el eje que yo había planteado.

  **Amelia: sí a Behat** (cinco escenarios, cero vocabulario). **Murat: no a Behat, sí a un `WebTestCase`.**
  Gana Murat, por dos razones que Amelia no tiene: Behat **no acredita cobertura de producción** (lo dice el
  docblock de `ChangeMyPasswordFunctionalTest:30`, y el hermano `/me/password` tiene las dos cosas), y cuatro
  de los cinco escenarios re-afirmarían el 403, el 422, la forma Problem Details y el 401, todos fijados **una
  vez y globalmente** por el pipeline de error y `access_control.feature:11`. Lo único exclusivo de Behat sería
  ver el cubo compartido salir por la ruta de revoke, y esa compartición es **estructural** — una sola clase
  `final readonly` con un `#[Autowire]` inyectada en los tres controladores, que ninguna mutación de una ruta
  puede romper.

  **Lo que ninguno de los dos discute, y es el hallazgo:** `grep -rn "me/recovery-secret" api/tests api/features`
  daba **cero**. La ligadura ruta↔método es un literal que ningún test ejecutaba, y es justo el delta de esta
  rama — la ruta se acababa de mover. Cerrado con `RevokeMyRecoverySecretFunctionalTest`, que fija tres cosas
  y no más: la ruta y el método por el cable, el 422 de payload estricto llegando **por esta ruta** (el gate
  estructural prueba que el atributo está, no que el 422 llegue), y el 204 con **aserción de la siembra antes
  de afirmar la ausencia** — sin ese `SELECT` intermedio el test pasa con una siembra de cero filas, que es un
  defecto que este repo ya se ha comido.

- **2026-08-29 · la costura PWA↔API deja de ser prosa y pasa a ser gate.** Sergio pidió decidir por mérito a
  largo plazo y no por si cabía en la historia, y eso invierte la respuesta: una issue habría sido la séptima
  promesa de un repo que documenta que los controles estructurales aplazados son justo los que se pudren.

  **El fichero pedía el gate por escrito.** El docblock de `ApiEndpoints.ts` dice que sus rutas «MUST match
  the backend exactly» y que «typos become 404s only at runtime». ~50 constantes de cliente frente a 43 rutas
  de API, y ningún instrumento las reconcilia: `ApiRecoverySecretRepository.test.ts` afirma contra **su propia
  constante**, que respecto al API es una tautología.

  **La fuente de verdad tiene que ser el router, y esto es lo que lo decide:** `routes.yaml` aplica el prefijo
  **por directorio**, y dos hermanos del mismo contexto difieren — `Iam/Identity/Infrastructure/Http/` monta
  bajo `/api/v1/backoffice` y `.../Controller/` bajo `/api/v1`. Mover un controlador entre ellos cambia su URL
  pública en silencio. Un regex sobre los `#[Route]` reimplementaría Symfony justo en la parte que falla.

  Tres piezas: `make sf.routes.manifest` (genera `api/.route-manifest.json` desde `debug:router`), un gate de
  frescura que exige que el manifiesto commiteado sea igual al router vivo —sin él el manifiesto es una mentira
  que envejece, el mismo defecto una capa arriba—, y un gate de contrato en la PWA que lee el AST y casa cada
  constante **y el verbo de cada llamada** contra el manifiesto.

  **Por qué el verbo, que es lo que justifica el tamaño:** un gate sólo-de-rutas habría salido **verde sobre
  este mismo cambio**. `/me/recovery-secret` sigue existiendo con GET y POST; lo que se movió fue el verbo.

  Una sola dirección, cliente → API. La inversa —toda ruta consumida— necesitaría allowlist para sondas de
  salud y rutas `dev`, y la posición declarada de este repo es que una regla que necesita allowlist está mal
  escrita.

## Adversarial pass

Dos pases hostiles, read-only, en contexto fresco y con encargos **disjuntos** sobre `f86b2662..HEAD`
(109 ficheros, +5571/−206): uno sobre seguridad y concurrencia del API, otro sobre GDPR, los registros, los
ejes de auditoría/eventos y la PWA. A ambos se les dijo explícitamente que **no se fiaran de los mensajes de
commit ni de los docblocks**, y que un comentario falso contase como hallazgo. Todo lo de abajo se verificó
contra el árbol o contra el stack antes de aceptarse o rechazarse.

### GRAVE

Ninguno. Ninguno de los dos encontró dato personal sobreviviendo a su propio borrado, ni un hueco explotable
sin credencial.

### Aceptados y arreglados

- **La sesión se acuñaba antes del muro de estado, y mi docblock afirmaba lo contrario.** `Security::login()`
  commitea la fila `iam_session` a través de sus propios listeners, así que la relectura bloqueada del paso 4
  sólo podía convertir una sesión viva en un cuerpo 403 — y el gate de admisión lee la fila de sesión, nunca
  el estado de la identidad. Una suspensión concurrente (la única contención contra un secreto filtrado)
  quedaba derrotada por una carrera reintentable. **Arreglado** con una revocación compensatoria en el muro,
  el docblock corregido para decir que refusa el *consumo* y no la sesión, y un caso nuevo que escenifica la
  suspensión **bajo el bloqueo** — falsificado quitando la compensación: un solo rojo, el caso nuevo.
- **El presupuesto «por selector» no era por selector.** `Uuid::isValid()` acepta cualquier caja y la columna
  es `uuid` nativo de Postgres, que compara sin distinguirla — medido: `'0190…4a5b'::uuid =
  '0190…4A5B'::uuid` responde `t`. La clave del limitador iba **verbatim**, así que una fila respondía a
  ~2000 buckets y un límite de 10/15min se volvía decenas de miles. Es la misma clase de defecto que
  `RecoveryBudgetKey` ya documenta para el eje de la dirección, un método más abajo. **Arreglado** en
  `Shared\Token\Domain\SelectorBudgetKey`, que es donde vive el primitivo — lo que lo corrige en las
  **tres** superficies a la vez (reset, invitación, canje), no sólo en la mía.
- **Un sitio nuevo donde la dirección de una persona es parámetro nombrado**, en un closure del controlador,
  fuera de los dos gates que vigilan ese eje. **Eliminado** pasando el seam como callable de primera clase:
  el método destino ya está clasificado `sensitive`.
- **El gate de CSRF que escribí reproducía el fallo que existe para rechazar**: leía sólo la constante
  `CSRF_TOKEN_ID`, así que un controlador con el id inline no aportaba nada al conjunto y, ausente también del
  YAML, dejaba los dos lados iguales y el gate verde sobre un endpoint que 401 en cada petición legítima.
  **Arreglado**: lee las dos grafías y **falla** si un fichero recogido no cede ninguna. Falsificado con esa
  forma exacta.
- **`sprint-status.yaml` afirmaba que no existía «ni una línea de código de producción»** con 3076 escritas.
  Es el primer fichero que lee una sesión nueva. Corregido, y con la razón por la que mintió.
- **La guía de despliegue mandaba a «Account ▸ profile»**, ruta que no existe (`User Profile ▸ My profile`) —
  y es el único paso del que depende toda la función en el traspaso.
- **Comentarios y documentos falsos o rancios**, todos corregidos: «aparece en ningún DTO» refutado por un
  fichero de este mismo cambio; `RecoverySecretMinted` contradiciendo a sus dos hermanos sobre
  wire-on-consumer; el docblock del detective afirmando que reporta huérfanos cuando no ejerce el anti-join;
  el comentario del canje diciendo que una presentación malformada «no puede casar nada» cuando el namespace
  del limitador es compartido; `routes.yaml` y `InvitationAcceptThrottle` describiendo dos consumidores donde
  ya hay tres.

### Rechazado, con su razón

- **«La PWA no ofrece la ruta de recuperación» — aceptado en parte, rechazado en su encuadre.** El pase lo
  presentó como que faltaba un enlace. Verificado: `Routes.RECOVERY` no tenía **ningún** consumidor, y el muro
  de bloqueo ofrecía exactamente las dos aristas que el atacante ya tiene cerradas. Eso no es un enlace que
  falta, es la función siendo inalcanzable para su único usuario. **Arreglado** en el muro, que es donde el
  usuario está en el momento en que la necesita — no en la pantalla de login, que sí sería anunciar la arista
  a un anónimo.

### Aceptado y arreglado — la decisión de Sergio, 2026-08-29

- **`DELETE /me/recovery-secret` no exigía la contraseña actual ni gastaba presupuesto.** El docblock
  argumentaba que destruir el secreto «no concede nada»; el pase lo refutó con una cadena concreta: quien
  roba una sesión no puede cambiar la contraseña ni acuñar (las dos re-prueban), pero **sí podía destruir
  el secreto** con una petición, leer la dirección en `GET /me` —confirmado, `MeResource` la lleva— y
  mantener la cuenta cerrada por el cerrojo indexado por email. El desvío forgot→reset corre sobre un
  presupuesto que el mismo atacante drena, y su agotamiento es silencioso por contrato.

  **Resuelto exigiendo la contraseña actual sobre el bucket compartido.** El encuadre que decide no es la
  asimetría de costes sino la propiedad: **destruir una capacidad es tan sensible como concederla**, y aquí
  la recuperabilidad forma parte de la frontera de seguridad. Queda un invariante enunciable — *todo acto
  que crea, reemplaza o destruye una credencial y es alcanzable desde sesión viva re-prueba la credencial y
  comparte presupuesto* — en lugar de tres docblocks justificándose por separado.

  **La forma la decidieron tres mediciones, no el gusto REST.** (a) La contraseña no puede viajar en
  cabecera: en este despliegue toda cabecera de petición excepto `Referer` se escribe en claro en el log de
  acceso del contenedor, que tiene rotación por volumen pero ni TTL ni ruta de borrado. (b) El puerto
  `HttpClient` de la PWA declara `delete(url, options)` sin cuerpo, y `sendWithBody` está tipado a
  `"POST" | "PUT" | "PATCH"`; ensanchar un puerto compartido para un solo llamante es lo que la Regla de
  Tres de este repo rechaza. (c) Los cuatro sub-caminos de acción del árbol son **verbos**
  (`/banks/realtime/authorize`, `/invitations/accept`, `/backoffice/users/{id}/unlock`, `/recovery/redeem`),
  cero nominalizaciones. De ahí `POST /me/recovery-secret/revoke`, y el `DELETE` **eliminado**, no
  mantenido como alias: un alias sin la prueba reinstala el defecto entero. Cuesta cero en `security.yaml`
  —el catch-all `^/api` va el último y una ruta protegida no necesita regla.

  **Consecuencia de cerrojos, que es lo que este hallazgo tenía de caro y nadie había nombrado.** El caso de
  uso documentaba no tomar el cerrojo del usuario, y llamaba deliberada esa asimetría con acuñación y canje.
  Leer la credencial obliga a la fila del usuario, así que revoke pasa a ser el **tercer** camino que toma
  los dos cerrojos, en el orden canónico `User` → `RecoverySecret` que I-A/I-B fijan. Sin ciclo ABBA, con la
  misma forma que los otros dos, y `RecoverySecretLockOrderFunctionalTest` extendido para cubrirlo en vez de
  afirmarlo. **No** se le añade `ensureActive()`: wallear aquí sólo preservaría el secreto de una identidad
  suspendida, que de todos modos no puede canjearlo.

  **Lo que se verificó antes de aceptar el coste.** La objeción natural —«un atacante drena el bucket
  compartido para impedir que el dueño revoque, y gana tiempo para canjear un texto plano robado»— **no
  funciona**: las dos rutas que consumen el bucket exigen sesión viva, así que quien no la tiene no puede
  drenarlo, y quien la tiene no gana nada impidiendo una revocación que él mismo querría. El residual sigue
  siendo sólo tecleo propio del dueño, exactamente el que el docblock del throttle ya había aceptado.

  **Rechazadas, con su razón.** Un presupuesto propio para revoke: lo refuta el docblock del throttle —un
  segundo bucket duplica los intentos por ventana contra la misma contraseña. Re-probar sólo si existe la
  fila: rompe el 204 idempotente y hace que la forma de la respuesta revele la existencia. Un objeto
  `CurrentPasswordProof` en Application: `ProveCurrentPassword` ya recibe `Closure(HashedPassword): bool`,
  de modo que el texto plano no sale de Infrastructure y el cierre se invoca **después** de las
  comprobaciones de hash ausente/corrupto, que responden las tres igual a propósito; una prueba calculada
  con antelación no puede expresar «no había credencial» sin una segunda bandera, así que sería un
  retroceso sobre una propiedad documentada.

### Hallazgo del propio pase de falsificación, no de los agentes

El escenario de aceptación que el ADR exige —«sella `locked_until` en el futuro … y ponte rojo al quitar la
transición»— **no se ponía rojo**. Medido: quitar `clearLockout()` del caso de uso deja los ocho escenarios
verdes, porque `ClearLockoutOnLoginSuccess` limpia el contador como efecto del propio login. El escenario
prueba que **la arista existe** de punta a punta, que es lo que #602 necesita, pero no puede atribuir quién
limpió. Queda escrito en el propio fichero de feature, y se añadió la aserción que sí es exclusiva de este
caso de uso (la fila `RECOVERY_SECRET_REDEEMED`, condicionada al consumo persistido). Quitar la retirada de la
fila sí pone dos escenarios en rojo.

### Ángulos que volvieron limpios

Contención del selector en todos los sumideros alcanzables (evento, envelope, `audit_log`, log, DTO, URL);
recuperabilidad del texto plano en cliente y servidor, incluido el scrubbing de Sentry; el eje de borrado
GDPR completo, con sus contadores y su entrada de cumplimiento; la fuente detective leyendo tabla y columna
correctas y su `DISTINCT` ausente descansando sobre el índice único; las clasificaciones de los siete
registros; ausencia de ciclo ABBA enumerando **todos** los caminos que tocan las dos filas, incluida la cadena
de borrado; `findBySelector()` no bloqueando; el muro 403 inalcanzable sin poseer el secreto; CSRF, same-origin
y access-control del endpoint anónimo; la migración; inyección y mass assignment; el bucket compartido de
prueba de credencial; y los gates de la PWA.

### Code review de tres capas, 2026-08-30 — sobre `c408df9c..HEAD` (134 ficheros, +8310/−230)

Tres capas en paralelo y con encargos ortogonales, read-only y en contexto fresco: **Blind Hunter**
(adversarial, sin spec), **Edge Case Hunter** (enumeración exhaustiva de ramas y fronteras, reportando sólo
las no cubiertas) y **Acceptance Auditor** (ACs, matriz AC→test, los siete registros y el récord, contra el
árbol). A las tres se les pasaron los dos pases hostiles anteriores para que no re-reportaran lo cerrado.

**GRAVE: ninguno.** Ocho SERIOUS y una veintena de MINOR.

**El convergente, y el único bloqueante.** Blind Hunter y Edge Case Hunter, por separado, encontraron el
mismo defecto: `RedeemRecoverySecret` compensaba la sesión sólo para `AccountDeactivated|AccountSuspended`.
Si `consume()` lanzaba `InvalidRecoverySecret` porque el dueño revocó en la ventana, el atacante recibía 400
y **conservaba la sesión viva**, sin fila de auditoría (es post-commit y no se alcanza) y sin evento (muere
con la transacción). Es decir: un 204 de revocación no garantizaba lo que el diálogo de la UI promete —
*«The secret you saved stops working immediately»*. El código ya había razonado esa clase exacta de peligro
para el muro de estado y compensaba allí; la rama gemela quedó abierta.

Y es más agudo que «falta un catch»: las Design Notes de abajo **aceptan explícitamente** que una sesión
sobreviva a un canje no consumado («*como mucho un consumo persistido, no como mucho una autenticación*»).
Ese contrato se escribió para el fallo parcial —una caída, un 503—, donde quien canjea se presume el dueño.
Este PR añadió la segunda promesa sin reconciliarla con la primera: **dos contratos documentados que se
contradicen**. Por eso «documentar la asimetría» no era salida — habría obligado a contradecir la UI.

**Resuelto ampliando la compensación** a las tres refusals que la pasada bloqueada puede levantar, con una
fila de auditoría nueva (`RECOVERY_SECRET_REDEMPTION_COMPENSATED`) que es la única traza durable que deja esa
interleaving. Se descartó revocar *sólo* la sesión de esta petición: `reauthenticate()` devuelve `void`, la
fila la inserta un listener y la firma la comparten cuatro llamantes, así que exigiría ensanchar un puerto
compartido para un caso — lo que la Regla de Tres de este repo rechaza. El coste de la forma gruesa se paga a
sabiendas: quien pierde una carrera canje-contra-canje también pierde su sesión, pero esa parte tiene camino
de contraseña (el ganador ya ejecutó `clearLockout()`) y quien sólo tiene el secreto no lo tiene. Falsificado:
revertida la ampliación, **un solo rojo**, el caso nuevo.

**La cobertura era ausencia real, no artefacto de atribución.** Se midió con `vitest --coverage` local y el
lcov reproduce el número de SonarCloud (`new_coverage` 44,2 % contra umbral 80). `RecoveryRedeemForm.tsx`
estaba al **0 %** con dos guardas borrables dejando la suite verde: quitar `!hydrated` hace que un envío
pre-hidratación dispare un **GET nativo** que mete `<selector>.<secret>` en la URL, el historial y el log de
acceso del contenedor —el sumidero sin TTL ni ruta de borrado que este mismo cambio dedica tres párrafos a
evitar—, y quitar el latch en vuelo gasta dos veces el presupuesto por selector. El falsificador que el ADR
declara para D7 tampoco existía: `SecretInstants` se podía borrar en verde. Y la única puerta a `/recovery`
—el muro de bloqueo— no estaba afirmada, con el título de su test diciendo todavía «*both recovery actions …
two-action stack*» sobre tres acciones.

**El patrón MINOR es uno solo, siete veces:** este PR añade un tercer/cuarto/séptimo miembro a conjuntos
enumerados en prosa y no actualizó ninguno — consumidores de `ProveCurrentPassword` (dos→tres), llamantes de
`reauthenticate()` (tres→cuatro), caminos con dos cerrojos (seis→siete), rutas públicas de recuperación
(«las dos» sobre una enumeración de tres), rutas que drenan el bucket («ambas»→tres), superficies de
`token_action_per_selector` (dos→tres) y «*the four spellings*» sobre tres filas. Los dos que más pesan
—`RecoverySecretRepository` y `ErasureLockOrderTest`— son justo los ficheros de los que un autor futuro
deduce el orden de adquisición, y el primero afirmaba que la revocación no toma el cerrojo del usuario, que
es la premisa desde la que alguien reordena y cierra el ABBA. Todos corregidos con el número **medido**.

**Consultada externamente y debatida** (`tmp/bmad-md/consult-pr877-review-decisions-*.md`): seis decisiones,
de las que dos cambiaron al confrontarlas con el árbol. La recomendación inicial en la carrera era la
granularidad fina, retirada al comprobar que el id de sesión no existe donde haría falta; y la de Postman,
al medir que la colección no tiene **ningún** endpoint de autenticación, de modo que añadir cuatro rutas con
sesión produciría documentación no ejecutable.

**Residual no cerrado, y explícito:** el diferencial temporal existencia/ausencia en `recordFailure()`. Este
PR movió el fast path adentro, así que una dirección existente paga `BEGIN` + cerrojo de fila y una
desconocida no. Puede quedar por debajo del ruido del KDF equalizado — pero eso es hipótesis, no medición, y
la regla de este repo es que una afirmación de seguridad se mide. La afirmación de `security.yaml` se rebajó
a lo demostrado y la medición es la issue **#881**.

## Design Notes

**Por qué el re-login va antes de consumir.** El flujo de reset re-autentica post-commit con un helper
que se traga cualquier `Throwable` y responde 204 igual. Para cambiar contraseña es correcto: la mutación
ocurrió. Aquí sería el peor caso — secreto gastado, fila borrada, 204 alegre y el administrador único
fuera. Invertir el orden hace que el fallo sea gratis.

**Por qué no se rota el secreto en la respuesta del canje.** Una respuesta perdida gastaría el único
secreto de un cliente sin shell; y convertiría un robo puntual en acceso permanente e indetectable,
matando la propiedad que hace el consumo **detectable por el propietario** (su secreto desaparece). No es
más que eso: la desaparición no identifica **quién** lo consumió, y `RECOVERY_SECRET_REDEEMED` no lleva el
selector, así que el dueño no puede correlacionar el evento con un secreto concreto. El
punto fijo es: recuperas → tienes sesión y contraseña → acuñas de nuevo explícitamente.

**Por qué el cambio de contraseña no invalida un secreto vivo.** Una rotación rutinaria destruiría en
silencio el canal de quien no tiene shell para notarlo. La expulsión se consigue con revocación explícita
y visible, no con un efecto colateral.

**Fallo parcial: no hay transacción que abarque las tres máquinas de estado.** `RecoverySecret`,
`User.lockout` y `Session` son tres, y ninguna transacción ACID las cubre. El contrato es explícito:
si `Security::login()` falla antes de producir sesión, **no se consume** nada y la respuesta es 503; si
tiene éxito y falla la mutación posterior, la sesión **no se revierte** —el dueño ha recuperado el acceso,
que es el objetivo— pero el endpoint **no promete 204** y el secreto queda vivo y recanjeable. El estado
parcial es **reintentable**, no idempotente: un segundo canje completa la limpieza persistida sin exigir
una nueva acuñación, y el comportamiento de `Security::login()` sobre una sesión ya autenticada queda
cubierto por prueba de integración en vez de asumido.

**Qué significa exactamente «un solo uso».** Es *como mucho un consumo persistido*, no *como mucho una
autenticación*: durante el estado parcial de arriba el endpoint puede producir más de una sesión antes de
que el consumo quede confirmado. Precisarlo evita que alguien escriba una prueba que afirme lo segundo.

**El secreto es una segunda credencial, no una extensión de la contraseña.** Sobrevive a la rotación de
la contraseña, no se rota al canjearse, y sólo muere por canje, revocación, caducidad o borrado GDPR. Por
tanto poseerlo equivale a poseer una credencial de recuperación hasta uno de esos cuatro eventos. Esa es
la consecuencia aceptada de la decisión de no invalidar, y la pantalla de perfil es lo que la hace
gobernable.

**Qué NO se afirma.** Lo que I-2 compra aquí es que la emisión no pueda ocurrir por vía puramente
administrativa ni en el terminal del vendor: obliga a pasar por una sesión autenticada y por la superficie
de aplicación, lo que deja fila en `session` y aparece en *Active sessions* del propio cliente. Eso es
mejor que el `UPDATE` indetectable que concede D2. **No es una frontera criptográfica frente a un operador
con control del runtime o de la base de datos** — puede leer la DB, la memoria y el tráfico local, y puede
borrar esa fila; no hay tamper-evidence (#555). No leer esto como confidencialidad ni integridad frente a
root.

## Verification

**Commands:** — cada uno en ejecución fresca, con su código de salida impreso.

Se registran **códigos de salida y alcance, nunca recuentos**. Un número de tests es cierto durante una
tarde: este bloque afirmaba «3313 tests / 15340 aserciones … sobre el árbol final» cuando ya habían entrado
220 ficheros después de la última edición del spec, así que la frase que lo databa era exactamente la parte
falsa. Un recuento que envejece es una afirmación falsa con retardo; el código de salida no envejece.

- `make php.stan` · `php.md` · `php.cs.dry-run` · `php.cs-fixer.dry-run` · `php.rector.dry-run` -- 0
- `make php.deptrac` -- 0
- `make php.unit` -- 0
- `make php.behat` -- 0
- `make php.lint.route-manifest` -- 0 · `make sf.routes.manifest` -- 0, y regenerar no cambia un byte
- `make php.lint.{bounded-context,event-bus,error-contract,step-vocabulary,audit-evidence,public-access}` -- 0
- `make php.lint.person-reference` · `php.lint.gate-placement` -- 0
- `make pwa.quality` · `pwa.lint.graph` -- 0
- `make pwa.test.unit` -- 0

**`make php.quality` NO se corre como barrida única**: muere de forma intermitente con 137 (flake conocido de
PHPMD/OOM). Se corren los miembros en grupos pequeños, y el que CI ejecuta es `php.quality.dry-run`.
