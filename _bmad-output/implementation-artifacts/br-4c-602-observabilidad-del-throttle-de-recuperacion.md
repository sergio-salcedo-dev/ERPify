---
title: 'BR-4c · #602 — observabilidad del agotamiento del throttle de recuperación'
type: 'feature'
created: '2026-08-11'
status: 'ready-for-dev'
review_loop_iteration: 1
baseline_commit: 0fc48fff
context:
  - '{project-root}/docs/adr/administrative-recovery-channel.md'
  - '{project-root}/docs/adr/audit-activity-log.md'
  - '{project-root}/api/.audit-resource-types'
  - '{project-root}/api/.person-reference-policy'
---

> Épica: `epics-backlog-resolution.md` · BR-4 (`:127-167`), «sus dos aristas»
> Hermana: `br-4b-602-observabilidad-del-lockout.md` (`done`, PR #683, squash `0fc48fff`)
> Rama: **NO AUTORIZADA** — propuesta al final · Base: `origin/main` @ `0fc48fff`

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problema:** de las dos aristas del grafo de recuperación que #602 describe, BR-4b cubrió la del **lockout**.
Queda la que el propio issue prioriza por encima de ella: *«If only one observability line is added here, it
should be throttle exhaustion — not the lock»*. Hoy el agotamiento de `password_recovery_per_email` es
**estructuralmente invisible**: `RequestPasswordResetController:38` cae al `202` uniforme sin auditar nada
(`git grep -n "AuditLogger" -- .../RequestPasswordReset*.php` no devuelve nada), y los ganchos genéricos no
pueden verlo — `AuditPolicy::isGenericActivityRead` audita **solo `GET`** (`AuditPolicy.php:36,47-51`) y un
forgot ahogado es un `POST` que responde `202`, así que tampoco hay `AccessDeniedException` para
`AccessDeniedAuditListener`. El silencio de cara al cliente es correcto y no se toca; lo que falta es la
contrapartida **interna**: nadie puede saber que a un administrador le están negando su única arista de
recuperación.

**Enfoque:** un control **detectivesco** de una sola mitad — **como máximo una fila `security` en `audit_log`
por dirección canonizada y ventana del presupuesto de auditoría**, escrita *best-effort* desde el borde del
controlador, detrás de un presupuesto propio que acota el amplificador de escritura. La respuesta no cambia en
forma, estado, cuerpo ni latencia.

**La unidad NO es «un episodio».** El mecanismo no detecta un flanco de subida semántico y no hay que
enunciarlo como si lo hiciera: detecta **una observación acotada por dirección y por ventana**. Un asedio
continuo de seis horas produce varias filas, no una; y un agotamiento accidental produce una. La frase
contractual, literal, para el cuerpo del PR:

> *At most one `security` row per canonicalised address per audit-budget window, recording the observation of
> a recovery-throttle exhaustion — not the rising edge of a semantic siege.*

## Boundaries & Constraints

**Always:**
- **El `202` uniforme y su indistinguibilidad por latencia son invariantes.** La fila es interna (legible solo
  tras `auditTrail.read`) y no puede alterar la respuesta ni en forma, ni en estado, ni en cuerpo, ni en
  tiempo. De ahí se deriva, y no se elige: la escritura es **best-effort** con `catch (Throwable)` —nunca
  `catch (Exception)`— + `warning`, porque `SymfonyAuditLogger::writeSecurity()` **propaga** por diseño
  (`SymfonyAuditLogger.php:21-23,65-72`) y un fallo propagado convertiría el `202` en `500`. El nivel
  `security` es el eje de taxonomía y retención; el envoltorio es la frontera de fallo. Mismo reparto que
  `RecordLockoutAuditBestEffort` en BR-4b.
- **El presupuesto de auditoría es propio, y lo gasta LA ESCRITURA, nunca la observación suprimida.** El
  throttle **no puede ser su propia guarda**: es exactamente lo que se está reportando, y nada en esta ruta se
  auto-limita como sí lo hacía `User::recordFailedAttempt()` en BR-4b. Sin guarda propia esto es *«a
  synchronous, per-attempt row on an unbudgeted endpoint … a write amplifier handed to the attacker it is
  meant to record»* (`InvalidCurrentPasswordAuditListener.php:40-42`). **Que el gasto sea la escritura y no la
  observación es load-bearing, no un detalle**: si el `consume(1)` cae también en la rama suprimida, el
  contador del propio presupuesto se infla al ritmo del atacante y el acarreo lo mantiene agotado
  indefinidamente — bajo ataque sostenido se escribe UNA fila en total y nunca más (medido; ver *Hechos
  medidos*). El techo se cumpliría, la lectura operativa no.
- **Las dos claves se canonizan idénticamente** — `mb_strtolower(trim($email))`, la misma de
  `PasswordRecoveryThrottle:38`. Si divergen, los dos cubos dejan de corresponderse, se multiplican las filas
  y **nada lo detecta**; de ahí que tenga AC propio.
- **La fila nombra al sujeto cuando la dirección resuelve a una identidad**, y el tipo se alcanza por
  `FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE`, jamás por el literal `'User'`; el fichero que lo derive vive
  bajo `src/Iam/Identity/` (regla de contención de `.audit-resource-types`) y pasa por `AuditResource::of()`.
- El orden en la rama denegada es **`equalise()` primero, todo lo demás después**, replicando el de la rama
  permitida (`RequestPasswordReset.php:52` paga el suelo antes de trabajar). El suelo es el primer trabajo tras
  conocer la denegación; la resolución de identidad y la escritura son trabajo adicional **dentro** del sobre.

**Never:**
- **Nada que cambie la respuesta.** Ni `429` por cuenta, ni cabecera `RateLimit-*`, ni cuerpo, ni una rama que
  se pueda cronometrar. `rate_limiter.yaml:47-52` fija el porqué: un `429` por objetivo sería oráculo de
  existencia y permitiría además superponer indefinidamente el token vivo de la víctima.
- **La dirección de correo, ni un hash, ni un digest, ni codificación alguna de ella, en `metadata` o en
  cualquier columna.** No es «desaconsejado», es un muro **medido**: `.person-reference-policy:68-100` declara
  que `audit_log.metadata` hoy sostiene **cero** ids de persona, enumera los cuatro controles que lo sostienen,
  y fija como disparador de revisión *cualquier* ruta de escritura que vuelva a meter uno. Y los cuatro casan
  por **id** contra `identity_user`: una dirección no la ve **ninguno**. Ni `DbalAuditActorAnonymiser` ni
  `DbalAuditResourceAnonymiser` tocan `metadata`, así que sobreviviría al borrado del propio sujeto, para
  siempre y sin detección.
- **No auditar desde `PasswordRecoveryThrottle`.** Su responsabilidad es «¿puede continuar esta petición?», no
  «¿debo producir evidencia de que no pudo?». Además sirve también `allowCompletion()`, cuyo silencio es de
  otra naturaleza; mezclarlas junta dos semánticas de seguridad distintas en un adaptador.
- **No tocar `LoginAttemptRegistrar:51`** (lectura sin bloqueo). #602 lo prohíbe: cerrarlo abarata el DoS sin
  dar palanca de recuperación.
- **No poner limitador a `POST /sessions/revoke-others`.** Trampa anotada en `epics-backlog-resolution.md:158-161`
  y en `PRODUCTION_SECURITY_CHECKLIST.md` §7: es la única arista que el adversario no puede gastar.
- **No endurecer la ruta de recuperación** en ninguna dirección: bajar el presupuesto, acortar el TTL del
  token o añadir fricción destruye el remedio del dueño y hace #602 irreparable
  (`epics-backlog-resolution.md:160`).
- **No persistir el presupuesto de auditoría en PostgreSQL.** Una tabla keyeada por la dirección convertiría
  una decisión de observabilidad en superficie nueva de datos personales — exactamente lo que el muro de
  `metadata` prohíbe, por la puerta de al lado. El residuo del pool de caché se acepta a cambio.
- No reproponer lo que el issue rechaza: dimensión de origen/IP, MFA, filtrar `locked_until` del directorio,
  `users.unlock`.
- Fuera de alcance, sin «mejorarlo» de paso: `SessionAdmissionGate`, `revoke-others`, el limitador
  `token_action_per_selector`, y la mitad de lockout que BR-4b ya cerró.

## I/O & Edge-Case Matrix

| Escenario | Entrada / Estado | Comportamiento esperado | Manejo de error |
|---|---|---|---|
| Petición 1..5 sobre una dirección | presupuesto de recuperación disponible | `202` · el caso de uso corre · **ninguna fila nueva** | N/A |
| **Primera denegación de la ventana de auditoría** | 6.ª petición | `202` idéntico · el caso de uso **no** corre · **1 fila** `PASSWORD_RECOVERY_THROTTLED` / `security` / `actor_type = anonymous` · se gasta el presupuesto de auditoría | best-effort: `warning` y sigue |
| **N-ésima denegación en la misma ventana** | 7.ª…k-ésima | `202` idéntico · **0 filas** · el presupuesto **no** se consume (el *peek* no muta) | N/A |
| **Ventana de auditoría rodada, ataque en curso** | ~1 h después, sigue denegando | **1 fila nueva** — latido, no repetición espuria | N/A |
| Dirección que resuelve a identidad | agotada | fila **con** `resource_id` = UUID del sujeto, `resource_type` por la constante | N/A |
| Dirección que no casa con ninguna identidad | barrido de un atacante | fila **sin recurso** (`resource_type`/`resource_id` NULL). **Se escribe igual**: se conserva la señal del barrido | N/A |
| Dirección de un usuario legítimo, agotada por un atacante que la apunta | la víctima pide recuperación y es ahogada | `202` idéntico · **ninguna fila adicional** dentro de la ventana; su petición es N-ésima | N/A |
| Dos denegaciones **concurrentes** cruzando la transición | dos workers, `lock_factory: null`, y *peek*→*consume* no atómico | `202` en ambas · **dos filas** posibles | Residuo documentado, no defecto |
| El almacén del limitador no responde | pool de caché caído | Lo que ya ocurre hoy sin este cambio: la excepción del limitador sale del controlador. **Este cambio no la introduce ni la captura** | Preexistente; se declara, no se altera |
| `audit_log` inescribible | tabla renombrada | `202` **idéntico** · `warning` con la excepción · **la respuesta sobrevive al fallo de su propia auditoría** | `catch (Throwable)` |
| Redespliegue, `cache:clear` o segundo worker FrankenPHP | pool `cache.rate_limiter` reiniciado o partido | Filas **extra** para el mismo asedio | Residuo documentado |

</frozen-after-approval>

## Code Map

- `api/src/Iam/Identity/Infrastructure/Http/RequestPasswordResetController.php:38-42` — la rama denegada; el
  **único** punto que conoce el `false`. No hay excepción y la respuesta es idéntica byte a byte a la feliz,
  así que **el mecanismo del precedente no está disponible aquí** (ver *Design Notes*).
- `api/src/Iam/Identity/Infrastructure/Http/InvalidCurrentPasswordAuditListener.php:16-42` — el precedente
  doctrinal: por qué un recorder propio, por qué la fila es sin recurso, y la frase del amplificador (`:40-42`).
- `api/src/Iam/Identity/Infrastructure/Security/PasswordRecoveryThrottle.php:30-42` — `allowRequest()` y la
  canonización que hay que replicar; el contrato de **neutralidad** está en su docblock (`:10-18`).
- `api/src/Iam/Identity/Application/RecordLockoutAuditBestEffort.php` — la plantilla exacta del envoltorio
  (`catch (Throwable)` + `warning`, la línea de log **no nombra ningún id**).
- `api/src/Iam/Identity/Application/PreIdentityTimingFloor.php:13-17` — la doctrina de latencia que autoriza
  una escritura en banda: *«equalise the store work inside this same envelope, never defer the write»*.
- `api/src/Iam/Identity/Application/RequestPasswordReset.php:52,54,60` — la rama permitida ya paga el suelo y
  **ya resuelve `email → identity`** (`Email::tryFrom()` + `findByEmail()`); es el término de comparación.
- `api/src/Shared/Audit/Application/AuditLogger.php:24` — el puerto: `log($action, AuditLevel::SECURITY, $resource, $metadata)`.
  `writeSecurity()` es privado del adaptador y no se llama desde fuera.
- `api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php:21-23,65-72` — `security` **propaga**; de ahí se
  deriva el envoltorio, no de una preferencia.
- `api/src/Shared/Audit/Domain/AuditResource.php:26-37` — `of()` rechaza un id no-UUID.
- `api/src/Shared/Audit/Domain/AuditPolicy.php:36,47-51` — `GET` únicamente; por qué el hueco existe.
- `api/config/packages/rate_limiter.yaml:47-57` — el limitador que se observa y el porqué del silencio;
  `lock_factory: null`.
- `api/.audit-resource-types:103-105` — `User => person :: FulfilIdentityErasure.php :: erase.feature`, ya
  registrado: nombrar al sujeto **no** añade línea a este registro.
- `api/.person-reference-policy:68-100,168-173` — el muro de `metadata` y la plaza única de `audit_log.resource_id`.
- `docs/architecture-api.md:265` — la taxonomía de acciones y el argumento de amplificación de las tres
  hermanas; esta cuarta se coloca en la misma frase.

## Tasks & Acceptance

**Execution:**

- [ ] `api/config/packages/rate_limiter.yaml` + `api/.env` — limitador `recovery_throttle_audit_per_email`,
      `sliding_window`, `limit: 1`, `interval: '1 hour'`, por env
      (`RATE_LIMIT_RECOVERY_THROTTLE_AUDIT_LIMIT` / `_INTERVAL`). El comentario debe decir dos cosas que el
      YAML no muestra: que **su gasto ES la supresión**, no una pérdida de filas, y que **solo lo gasta la
      escritura** — un mantenedor que «simplifique» el *peek* a un `consume()` directo rompe el latido sin
      poner nada rojo salvo el AC que lo cubre.
- [ ] `api/src/Iam/Identity/Infrastructure/Security/PasswordRecoveryThrottle.php` — método nuevo sobre el
      factory nuevo, con la **misma** canonización que `allowRequest()`, que **decide con `consume(0)` y solo
      consume 1 cuando la respuesta es afirmativa**. Devuelve `bool`. Sigue sin auditar nada: solo responde
      «¿corresponde escribir?».
- [ ] `api/src/Iam/Identity/Application/RecordRecoveryThrottleAuditBestEffort.php` — `catch (Throwable)` +
      `logger->warning`; la línea de log **no nombra la dirección ni ningún id**. Resuelve `email → identity`
      y construye el `AuditResource` solo cuando hay identidad.
- [ ] `api/src/Iam/Identity/Infrastructure/Http/RequestPasswordResetController.php` — rama denegada:
      `equalise()` → intento de auditoría → `202`. Una llamada a un colaborador de `Application`; el
      controlador sigue delgado.
- [ ] `api/tests/Unit/Iam/Identity/Application/RecordRecoveryThrottleAuditBestEffortTest.php` — pasa a través ·
      traga y registra a `warning` con la excepción en contexto · **la línea no contiene la dirección** ·
      dirección conocida ⇒ recurso presente · desconocida ⇒ fila sin recurso.
- [ ] `api/tests/Functional/Iam/Identity/RequestPasswordResetThrottleAuditTest.php` — la **única** capa donde
      el presupuesto y su latido pueden ponerse rojos contra el pool real, con reloj/almacén manipulables.
- [ ] `api/features/backoffice/identity/password_reset.feature` — escena con correlation-id fijo que agota el
      presupuesto y afirma sobre `audit_log` con `the SQL result as JSON should be:`, **más** que el cuerpo y
      el estado de las seis respuestas son idénticos. Buscar el vocabulario antes de escribir cualquier step
      (`make php.behat c='-dl'`) y regenerar `api/.behat-step-vocabulary`.
- [ ] `api/features/.../password_reset.feature` — segunda escena: `ALTER TABLE audit_log RENAME … on connection
      "seed"` y el `202` sigue siendo `202`.
- [ ] `docs/architecture-api.md:265` — cuarta acción de la ruta de identidad en la misma frase de
      amplificación, diciendo explícitamente que aquí **no hay auto-límite** (a diferencia de `USER_LOCKED`) y
      que por eso lleva presupuesto propio gastado por la escritura.
- [ ] `PRODUCTION_SECURITY_CHECKLIST.md` — el control nuevo con sus residuos, incluido que **un lector
      autorizado del trail sí distingue** dirección resoluble de no resoluble.
- [ ] `docs/adr/administrative-recovery-channel.md` — anotar que esta observabilidad es **detectivesca y sin
      capacidad de recuperación**: no añade arista al grafo. Redactarlo así y nunca como «se observa la
      recuperación», que induciría a leerlo como una palanca.

**Acceptance Criteria** — cada uno con la mutación que lo pone rojo. Los cuatro marcados **[GATE]** son
fronteras arquitectónicas, no tests de regresión:

- **[GATE] El amplificador está acotado.** 5 aceptadas → 0 filas; 6.ª → 1 fila; 7.ª y 8.ª → sigue habiendo 1.
  *Rojo:* quitar la guarda del presupuesto. **Hay que provocarlo y medirlo**, porque es el único AC que separa
  esta historia de «hemos añadido un INSERT».
- **[GATE] La respuesta no cambia.** Las seis respuestas son indistinguibles en estado, cuerpo y cabeceras
  significativas. *Rojo:* devolver `429`, o añadir cualquier cabecera `RateLimit-*`.
- **[GATE] La fila no contiene la dirección.** Aserto sobre el `jsonb` como texto, **insensible a mayúsculas**,
  igual que el control (a) de `.person-reference-policy:84-85`. *Rojo:* meter `['email' => $email]` en
  `metadata`. **Límite conocido y escrito, no prometido de más:** este aserto cubre el literal y sus casings;
  **no puede** cubrir un digest o una codificación arbitraria — ninguna aserción puede, y una regla de forma
  rompería el contrato de `anonymized_actor_id`. Esa mitad la sostiene la revisión, igual que en el registro.
- **[GATE] El `202` sobrevive al fallo de su propia auditoría.** Con `audit_log` no disponible, la respuesta
  sigue siendo `202` sin cuerpo. *Rojo:* quitar el `catch (Throwable)`, o estrecharlo a `catch (Exception)`.
- **El latido existe: el presupuesto lo gasta la escritura, no la observación suprimida.** Con el almacén
  avanzado una ventana bajo denegación continua, llega **una segunda** fila. *Rojo:* mover el `consume(1)` a
  la rama suprimida — el acarreo se infla y la segunda fila **desaparece**. Sin este AC ese cambio es
  invisible: el techo sigue verde y solo se pierde la detección.
- **La supresión es por dirección.** Agotar `a@…` y provocar `b@…` da **dos** filas. *Rojo:* keyear el
  presupuesto por algo constante.
- **Las dos canonizaciones coinciden.** Agotar con `A@Example.COM` y transicionar con `a@example.com` produce
  **una** fila. *Rojo:* quitar `mb_strtolower`/`trim` de uno solo de los dos métodos. *(Existe porque el fallo
  es silencioso: dos cubos desalineados no rompen nada, solo multiplican filas.)*
- **La atribución es real y su ausencia también.** Dirección resoluble ⇒ `resource_id` = UUID del sujeto y
  `resource_type` obtenido por la constante; no resoluble ⇒ fila escrita con ambos NULL. *Rojo:* escribir el
  literal `'User'` (lo caza `make php.lint.audit-resource`), o no escribir fila en el caso no resoluble.
- **El caso de uso no corre en la rama denegada.** Cero filas en `identity_password_reset_token` y cero
  `PasswordResetRequested` en `event_store` durante el agotamiento. *Rojo:* mover la llamada al caso de uso por
  delante de la guarda del throttle.
- Ninguna escena existente de `password_reset.feature` ni de `login.feature` cambia de color.

## Design Notes

**El mecanismo del precedente NO está disponible aquí, y es la diferencia estructural con BR-4b.**
`InvalidCurrentPasswordAuditListener` engancha `kernel.exception` porque *hay* una excepción que distinguir.
Aquí no la hay: el controlador **retorna** un `202` idéntico byte a byte al de la rama feliz. Un listener de
`kernel.response` no podría separarlos sin que el controlador le dejara un atributo de petición — un canal
implícito, invisible en el tipo, que es la clase de acoplamiento que el precedente evita. Por eso la escritura
vive en el borde del controlador, que es el único sitio donde el `false` existe. **Descartado:** auditar dentro
de `PasswordRecoveryThrottle` (SRP; y sirve también `allowCompletion()`).

**Por qué best-effort, derivado y no elegido.** `SymfonyAuditLogger::writeSecurity()` no captura nada
(`:65-72`): un fallo del `INSERT` propaga. En el precedente eso es aceptable — un `403` que se vuelve `5xx`
sigue siendo un rechazo. Aquí convertiría el `202` uniforme en `500`, que es literalmente lo que la restricción
congelada prohíbe. No es un fallo *diferencial* (fallaría igual para toda dirección, así que no es oráculo),
pero sí un cambio de la respuesta, y esa invariante no admite matices. `Throwable` y no `Exception`: la
garantía es supervivencia frente a **cualquier** fallo del mecanismo de auditoría.

**Por qué la latencia no es objeción.** El `INSERT` y la lectura indexada son sub-milisegundo y la rama
denegada ya paga el suelo del KDF (decenas de ms). El docblock de `PreIdentityTimingFloor:13-17` no solo lo
tolera: prescribe *«equalise the store work inside this same envelope, never defer the write»*. Y hay un efecto
de segundo orden a favor: la rama permitida **ya escribe y ya resuelve la identidad**, así que hacerlo en la
denegada **acerca** las dos rutas en vez de crear una tercera con perfil propio.

**Por qué el discriminador aritmético se descartó, y por qué la misma aritmética obliga a gastar el
presupuesto en la escritura.** La tentación evidente era detectar la transición leyendo el limitador
(`disponibles == 0`). No funciona: `getHitCount()` (`SlidingWindow.php:77-83`) es
`floor(H·(1 − pct) + hits_actuales)`; el término de decaimiento cae a ritmo `H/T` y `H` **es** el conteo que
produjo ese mismo atacante, así que a ritmo constante `getHitCount()` **se estanca** y la condición se cumple
petición tras petición. Lo que la revisión de esta iteración añade es que **la misma aritmética muerde al
presupuesto propio si lo consume la observación suprimida**: el conteo del cubo de auditoría crecería al ritmo
del atacante, el acarreo lo mantendría agotado toda la ventana siguiente, y bajo asedio sostenido se escribiría
**una fila en total**. El techo aguantaría; la detección no. Gastarlo solo al escribir mantiene el conteo en 1
por ventana, el acarreo no se infla, y el régimen permanente es un latido de una fila por hora — **con el mismo
techo duro, porque quien gasta el presupuesto es la propia fila**.

**Descartado: `fixed_window` para el presupuesto de auditoría.** Parecía la salida limpia (rodaje natural, sin
acarreo ponderado) y es **peor**, medido: `Window::getCarriedHitCount()` (`Window.php:78-88`) resta solo
`windowsElapsed × maxSize`, que con `maxSize = 1` es **1 por hora**, mientras `FixedWindowLimiter.php:109` suma
en la rama denegada igual que su hermano. Cualquier ataque por encima de 1 pet./hora deja el cubo agotado para
siempre. Con el gasto movido a la escritura la elección deja de importar; se deja escrito para que nadie lo
«arregle» en esa dirección.

**Las dos ventanas tienen la misma duración, no la misma fase.** Ambas son `sliding_window` de 1 hora, pero
cada cubo arranca en su primer consumo: el de recuperación en la 1.ª petición, el de auditoría en la 6.ª. Decir
«una fila por ventana de recuperación» sería falso; la unidad es **la ventana del presupuesto de auditoría**.
Igualarlas en duración es lo que mantiene la semántica simple; sincronizarlas en fase no es posible ni
necesario.

**Residuos a escribir, no a esconder:**
- **Concurrencia.** `lock_factory: null` y un *peek*→*consume* no atómico permiten que dos peticiones
  simultáneas escriban dos filas. Mismo tipo de residuo que el `USER_LOCKED` duplicado de BR-4b: ruido para el
  operador, nunca pérdida.
- **El presupuesto vive en el pool de caché.** Redespliegue, `cache:clear` o un segundo worker lo reinician o
  lo parten y producen filas extra. Aceptado a propósito: la alternativa persistida exige una tabla keyeada por
  la dirección, es decir mintar el dato de persona que el muro prohíbe. **El estado efímero es aquí la opción
  conservadora, no la perezosa.**
- **La fila dice *que* hubo asedio y *cuándo*, nunca *cuánto*.** Un accidente de seis peticiones y un asedio de
  cien mil producen la misma fila por ventana. Es el precio del techo; el volumen ya lo llevan `anonymous_api`
  y los logs de acceso, y duplicarlo aquí sería el amplificador otra vez.
- **El trail distingue dirección resoluble de no resoluble, y hay que decirlo.** No se presenta como «no hay
  información de existencia»: un lector con `auditTrail.read` (ADMIN / AUDIT_READER) sí la tiene, por la
  presencia o ausencia de `resource_id`. No es alcanzable por el atacante; lo sería si el trail se exfiltra o
  si un tier menor gana lectura. Ocultarlo no compraría privacidad — la ausencia de fila cargaría el mismo bit
  — y costaría la señal del barrido contra direcciones inexistentes.
- **La dirección sigue siendo clave del cubo del limitador**, hoy y sin este cambio
  (`PasswordRecoveryThrottle:38`). El presupuesto nuevo no introduce una clase de dato nueva.
- **`sliding_window` no rellena mientras dure el ataque.** `SlidingWindowLimiter.php:94` suma también al
  denegar, así que el agotamiento de la víctima dura *lo que dure el ataque más una ventana*, no una hora desde
  la quinta petición. **Agrava #602 respecto a como lo describe el issue** y va en el cuerpo del PR. No se
  arregla aquí: tocarlo es endurecer o aflojar la ruta de recuperación, vetado en *Never*.

## Hechos medidos — no re-derivar

Cada uno costó una sonda o una lectura de `vendor/`; ninguno vive en el código de la app.

| Hecho | Medición |
|---|---|
| **La rama denegada del sliding window también suma el hit** | `vendor/symfony/rate-limiter/Policy/SlidingWindowLimiter.php:94` — `$window->add($tokens)`, gemela de la aceptada en `:78`: está en el `else`, no solo en el `if`. Bajo martilleo la ventana no se vacía y **no vuelve a aceptarse ninguna petición** hasta que rueda |
| **Consumir el presupuesto propio en la observación suprimida lo mata** | Con `H` heredado y el atacante a ritmo `R`, `getHitCount() = floor(H·(1−p) + R·p)` se queda clavado en `R` toda la ventana ⇒ `disponibles = 1 − R ≤ 0` siempre ⇒ **una sola fila en todo el asedio**. Derivado de `SlidingWindow.php:38-49,77-83`; **verificar con sonda de almacén antes de dar el AC del latido por bueno** |
| **`consume(0)` es un *peek* gratuito y no mutante** | `SlidingWindowLimiter.php:71-75` retorna antes de tocar la ventana; `$this->storage->save($window)` en `:100` está guardado por `if (0 !== $tokens)` en `:99`. Es lo que hace implementable el gasto-solo-al-escribir |
| **`fixed_window` es PEOR, no la salida** | `Window::getCarriedHitCount()` (`Window.php:78-88`) resta `windowsElapsed × maxSize` = **1 por hora** con `maxSize = 1`, y `FixedWindowLimiter.php:109` suma también al denegar. Cubo agotado para siempre por encima de 1 pet./hora |
| **`getRemainingTokens()` no está acotado y se vuelve negativo** | `vendor/symfony/rate-limiter/RateLimit.php:53-56` devuelve `availableTokens` verbatim; `getAvailableTokens()` (`SlidingWindowLimiter.php:118-120`) es `limit − hitCount`. En denegación es ≤ −1 |
| **`security` propaga; `activity` traga** | `SymfonyAuditLogger.php:65-72` vs `:77-92`. **Ambos escriben síncronamente en el ciclo de petición** — `activity` no va por cola; lo que corre en `kernel.terminate` es la *captura* genérica, no un diferimiento dentro del logger |
| **La dirección en `metadata` sería una fuga INDETECTABLE, no solo sin dueño** | `.person-reference-policy:81-97`: los cuatro controles que sostienen el «cero ids en `metadata`» casan por **id** contra `identity_user` — (a) por el id del sujeto borrado, (b) y (d) por *join*. Una dirección no la ve ninguno. Y ni `DbalAuditActorAnonymiser` ni `DbalAuditResourceAnonymiser` tocan `metadata` |
| **Nombrar al sujeto en `resource_id` NO minta obligación nueva** | `.audit-resource-types:105` ya lleva `User => person :: FulfilIdentityErasure.php :: erase.feature`, y `.person-reference-policy:168-173` confirma que `audit_log.resource_id` está **dentro** del control detectivesco como colaborador cableado. No hay línea de registro que añadir |
| **El throttle no se auto-limita, a diferencia del lockout** | `PasswordRecoveryThrottle::allowRequest()` consume y devuelve `false` en cada llamada; no existe el equivalente al `recordFailedAttempt() === false` que en BR-4b cerraba la transacción. El techo sin guarda propia es `anonymous_api` = **120/min por IP** (`api/.env:83`) × nº de IPs |
| **La rama permitida ya resuelve `email → identity`** | `RequestPasswordReset.php:54,60` — `Email::tryFrom()` + `findByEmail()`. La denegada no. Es el coste exacto de la decisión 2, y es el que acerca ambas ramas |

## Decisiones abiertas — AMBAS RESUELTAS (2026-08-11)

Resueltas por Sergio tras consulta externa, con una precisión y un refinamiento añadidos por la medición de
esta iteración.

1. **Unidad de auditoría: presupuesto propio `recovery_throttle_audit_per_email`, `sliding_window`,
   `limit: 1`, `interval: '1 hour'`, keyeado por la dirección canonizada.** Se descartan una fila por
   denegación (es el amplificador) y la detección aritmética de la transición (refutada por medición). La
   propiedad que se compra no es «detectar el flanco» sino que **el número de escrituras quede desacoplado del
   número de intentos que el atacante puede generar**.
   - **Precisión aceptada:** la unidad es *una observación por dirección y ventana del presupuesto de
     auditoría*, no «un episodio». Un asedio continuo produce varias filas; el mecanismo no detecta un flanco
     semántico. Corregido en *Intent*, con la frase contractual para el PR.
   - **Refinamiento añadido por medición, dentro de la misma decisión:** el presupuesto lo gasta **la
     escritura**, nunca la observación suprimida. Sin eso, «1 por dirección y ventana» era falso en la
     dirección contraria — bajo asedio sostenido habría UNA fila en total. Con eso, el régimen permanente es el
     latido que la decisión pretendía. Tiene AC propio porque es un cambio de una línea, invisible a todo lo
     demás.
2. **`resource_id`: resolver `email → identity` en la rama denegada; con identidad, el UUID del sujeto; sin
   identidad, fila escrita **sin** recurso.** Se descarta la fila siempre sin recurso (desaprovecha una
   atribución que el sistema ya puede obtener por un eje **ya gobernado**, y #602 pregunta justamente *a quién*
   están inutilizando) y se descarta no escribir fila en el caso no resoluble (la ausencia carga el mismo bit y
   pierde la señal del barrido).
   - **No negociable:** tipo por `FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE`, nunca el literal `'User'`;
     derivador bajo `src/Iam/Identity/`; vía `AuditResource::of()`; **ninguna representación de la dirección en
     `metadata`**, en ninguna forma.

## Verification

**Commands:**
- `make php.stan` — exit 0 en cada fichero tocado
- `make php.unit` — verde, incluidos los tests nuevos
- `make php.behat c='features/backoffice/identity/password_reset.feature'` — leer la **línea de resumen**, no
  solo el exit code (un step indefinido sale 0)
- `make php.behat c='features/backoffice/identity/login.feature'` — las escenas de BR-4b siguen verdes
- `make php.quality` y `make php.quality.dry-run` — exit 0
- `make php.lint.audit-resource`, `php.lint.person-reference`, `php.lint.persistent-transport`,
  `php.lint.step-vocabulary`, `php.deptrac` — exit 0

**Manual checks:**
- **Provocar el rojo de cada AC antes de darlo por bueno, y recontar al FINAL** — el conteo se pudre. Los dos
  que no pueden faltar: el del presupuesto (sin él la suite queda verde con una fila por petición) y el del
  **latido** (sin él, mover el `consume(1)` a la rama suprimida no rompe nada visible y la detección
  desaparece en silencio).
- **Verificar con sonda de almacén la afirmación del acarreo** antes de dar por bueno el AC del latido. Está
  derivada de `vendor/`, no medida en ejecución, y es la que sostiene el diseño.
- `curl -k`: las seis respuestas del agotamiento idénticas en estado y cuerpo, sin cabecera nueva.
- El pase adversarial es **gate para ABRIR la PR**, no para llegar a `done` (`CLAUDE.md` → *Security review on
  every change* → *Process*): corre y se escribe en este artefacto **antes** de `gh pr create`. Requiere pedir
  autorización a Sergio para lanzar el subagente read-only.
