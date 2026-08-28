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

### Aceptado y NO arreglado — decisión abierta para Sergio

- **`DELETE /me/recovery-secret` no exige la contraseña actual ni gasta presupuesto.** Mi docblock argumenta
  que destruir el secreto «no concede nada»; el pase lo refuta con una cadena concreta: quien roba una sesión
  no puede cambiar la contraseña (hay re-prueba), pero **sí puede destruir el secreto** con una petición, leer
  la dirección en `GET /me` y mantener la cuenta cerrada por el cerrojo indexado por email. El desvío
  forgot→reset corre sobre un presupuesto que el mismo atacante drena, y su agotamiento es silencioso por
  contrato. Coste de arreglarlo: quien agotó el bucket compartido espera 15 minutos para revocar un secreto
  que cree comprometido, y la PWA gana un campo de contraseña en el diálogo. Coste de no arreglarlo: pérdida
  permanente del canal, irreversible. **No lo decido yo**: revierte una decisión documentada y cambia una
  superficie de producto.

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

**Commands:**
- `make php.stan` -- 0, sin errores
- `make php.quality` -- 0 (incluye `php.lint.person-reference`, `public-access`, `audit-evidence`, deptrac)
- `make php.unit` -- 0, sin regresión
- `make php.behat c='features/backoffice/identity/'` -- 0
- `make pwa.quality` -- 0
