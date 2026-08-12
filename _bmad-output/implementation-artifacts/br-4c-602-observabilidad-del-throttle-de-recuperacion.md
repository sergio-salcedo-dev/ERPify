---
title: 'BR-4c · #602 — observabilidad del agotamiento del throttle de recuperación'
type: 'feature'
created: '2026-08-11'
status: 'ready-for-dev'
review_loop_iteration: 3
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
por dirección canonizada y ventana del presupuesto de auditoría**, escrita *best-effort* en
**`kernel.terminate`**, detrás de un presupuesto propio que acota el amplificador de escritura. La respuesta no
cambia en forma, estado, cuerpo ni latencia — y esto último es **medido**, no afirmado: en banda el diferencial
era de 14,70 ms (20/20 pares); diferida, de −0,37 ms (10/20, azar).

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
- **El presupuesto de auditoría es propio, y se reclama con un `consume()` antes de escribir.** El throttle
  **no puede ser su propia guarda**: es exactamente lo que se está reportando, y nada en esta ruta se
  auto-limita como sí lo hacía `User::recordFailedAttempt()` en BR-4b. Sin guarda propia esto es *«a
  synchronous, per-attempt row on an unbudgeted endpoint … a write amplifier handed to the attacker it is
  meant to record»* (`InvalidCurrentPasswordAuditListener.php:40-42`).
- **Se reclama con `consume()`, nunca con `reserve()`.** Una denegación no cuesta nada al cubo por `consume()`
  (medido), así que la ranura vuelve un intervalo después de la fila que la gastó y el atacante no puede
  alargar su propio silencio. `reserve()` sí infla el cubo en la rama denegada y convertiría la supresión en
  permanente bajo ataque sostenido — tiene AC propio porque nada más lo vería.
- **Las dos claves y la resolución de identidad canonizan por la MISMA función** — `RecoveryBudgetKey`
  delega en `Email`, que es quien define qué es «el mismo buzón». Una clave más débil no es una diferencia
  estética: `mb_strtolower(trim(...))` deja intacto un `\u{00A0}` que `Assert\Email` estricto **admite** y que
  `Email` sí recorta, así que cada relleno minta su propio cubo resolviendo a una sola identidad — y eso
  evadía también `password_recovery_per_email`, no solo su auditoría.
- **La fila nombra al sujeto cuando la dirección resuelve a una identidad**, y el tipo se alcanza por
  `FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE`, jamás por el literal `'User'`; el fichero que lo derive vive
  bajo `src/Iam/Identity/` (regla de contención de `.audit-resource-types`) y pasa por `AuditResource::of()`.
- **La proyección entera corre en `kernel.terminate`**, no en banda. La rama denegada paga el suelo y nombra
  el objetivo en un atributo de petición; el listener resuelve identidad y escribe cuando la respuesta ya está
  en el cable. El listener **no puede** re-derivar el rechazo —el presupuesto ya se consumió y la respuesta es
  idéntica a una servida—, así que el atributo es el único canal honesto, y se paga a cambio de cerrar un canal
  medido.

**Never:**
- **Nada que cambie la respuesta.** Ni `429` por cuenta, ni cabecera `RateLimit-*`, ni cuerpo, ni una rama que
  se pueda cronometrar. `rate_limiter.yaml:47-52` fija el porqué: un `429` por objetivo sería oráculo de
  existencia y permitiría además superponer indefinidamente el token vivo de la víctima. **Este `Never` NO se
  renegoció**: la primera versión lo incumplía (14,70 ms, 20/20) y en vez de reescribir el invariante se movió
  la escritura fuera del ciclo de respuesta hasta cumplirlo (−0,37 ms, 10/20). La cabecera tiene AC propio
  porque `PasswordChangeThrottle:59-65` demuestra que estamparla son tres líneas.
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
| **N-ésima denegación en la misma ventana** | 7.ª…k-ésima | `202` idéntico · **0 filas** · la denegación no cuesta nada al cubo | N/A |
| **Ventana de auditoría rodada, ataque en curso** | ~1 h después, sigue denegando | **1 fila nueva** — latido, no repetición espuria | N/A |
| Dirección que resuelve a identidad | agotada | fila **con** `resource_id` = UUID del sujeto, `resource_type` por la constante | N/A |
| Dirección que no casa con ninguna identidad | barrido de un atacante | fila **sin recurso** (`resource_type`/`resource_id` NULL). **Se escribe igual**: se conserva la señal del barrido | N/A |
| Dirección de un usuario legítimo, agotada por un atacante que la apunta | la víctima pide recuperación y es ahogada | `202` idéntico · **ninguna fila adicional** dentro de la ventana; su petición es N-ésima | N/A |
| Dos denegaciones **concurrentes** cruzando el borde de ventana | dos workers, `lock_factory: null` | `202` en ambas · **dos filas** posibles | Residuo documentado, no defecto |
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

- [x] `api/config/packages/rate_limiter.yaml` + `api/.env` — limitador `recovery_throttle_audit_per_email`,
      `sliding_window`, `limit: 1`, `interval: '1 hour'`, por env. El comentario dice las dos cosas que el YAML
      no muestra: que **su gasto ES la supresión**, y que se reclama con `consume()` y nunca con `reserve()`.
- [x] `api/src/Iam/Identity/Application/RecoveryThrottleAuditBudget.php` — **puerto** de una operación
      (`claimFor`). Es lo que mantiene legal la capa (`Application` no puede depender del adaptador) y lo que
      hace el AC del amplificador falsable sin contenedor.
- [x] `api/src/Iam/Identity/Infrastructure/Security/RateLimiterRecoveryThrottleAuditBudget.php` — adaptador
      `#[AsAlias]` sobre `limiter.recovery_throttle_audit_per_email`. **Clase aparte de
      `PasswordRecoveryThrottle`, no un tercer método suyo**: aquella responde «¿puede continuar esta
      petición?» y sirve además `allowCompletion()`; ésta responde «¿ya se observó este rechazo?».
- [x] `api/src/Iam/Identity/Infrastructure/Security/RecoveryBudgetKey.php` — **fold-in nombrado**: la
      canonización `mb_strtolower(trim(...))` pasa a existir una sola vez y `PasswordRecoveryThrottle:38` la
      consume. No es un `Email` (ése rechaza direcciones malformadas, y estos presupuestos se gastan para toda
      dirección pedida, precisamente para que el limitador no sea sondeable).
- [x] `api/src/Iam/Identity/Application/RecordRecoveryThrottleAuditBestEffort.php` — reclama, resuelve
      `email → identity`, escribe y traga (`catch (Throwable)` + `warning` sin dirección ni id).
- [x] `api/src/Iam/Identity/Infrastructure/Http/RequestPasswordResetController.php` — rama denegada:
      `equalise()` → proyección → `202`.
- [x] `api/tests/Unit/.../RecordRecoveryThrottleAuditBestEffortTest.php` (7 casos) + su doble
      `FixedRecoveryThrottleAuditBudget`.
- [x] `api/tests/Unit/.../RateLimiterRecoveryThrottleAuditBudgetTest.php` (5 casos, uno `#[Group('slow')]`) —
      contra el limitador **real**; un doble asertaría el doble.
- [x] `api/tests/Unit/.../RequestPasswordResetControllerTest.php` — extendido con el GATE del amplificador
      (8 peticiones ⇒ 1 fila) y la independencia por dirección, contra el limitador real.
- [x] `api/features/backoffice/identity/password_reset.feature` — **tres** escenas: agotamiento proyectado una
      sola vez pese a tres rechazos (con una tercera petición en otro casing, que pinea `RecoveryBudgetKey` de
      punta a punta, primeando en minúsculas y pidiendo en otro casing **en la misma única petición**),
      dirección que no nombra a nadie ⇒ fila **sin** recurso, y `ALTER TABLE audit_log RENAME` probando que el
      `202` sobrevive. **Cero steps nuevos**: el vocabulario ya los tenía todos. **Una petición por escena, y
      es restricción del harness, no estilo**: el pool de test es `cache.adapter.array` y el `services_resetter`
      lo limpia en cada `kernel.terminate`, así que un presupuesto primeado lo ve la petición siguiente y
      ninguna más — lo dice el docblock de `RateLimitContext`, que es el fichero que había que leer antes.
- [x] `docs/architecture-api.md` — cuarta acción de la ruta de identidad, en la misma frase de amplificación.
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` — el control con sus **tres** residuos abiertos y la propiedad del
      lector autorizado dicha, no escondida.
- [x] `docs/adr/administrative-recovery-channel.md` — **D6**, redactado como *«detective-only»* y nunca como
      «se monitoriza la recuperación», que induciría a leerlo como mecanismo de continuidad.

**Acceptance Criteria** — cada uno con la mutación que lo pone rojo. Los cuatro marcados **[GATE]** son
fronteras arquitectónicas, no tests de regresión:

- **[GATE] El amplificador está acotado — y se pinea en el UNITARIO, no en aceptación.** Ocho invocaciones del
  controlador contra un `InMemoryStorage` persistente ⇒ **1 fila**. En Behat es inexpresable: el mismo barrido
  que resetea el presupuesto de recuperación resetea el de auditoría, así que una segunda petición llegaría con
  cupo lleno y no sería un rechazo.
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
- **[GATE] La supresión es temporal: el latido existe.** Con el almacén avanzado una ventana bajo denegación
  continua, la ranura vuelve. *Rojo medido:* cambiar `consume()` por `reserve(1)` en el adaptador — su rama
  denegada sí infla el cubo y la ranura no vuelve. **Y el AC necesitó su propia ventana**: a `interval = 1s`
  pasaba con la mutación puesta, porque `InMemoryStorage` desaloja la entrada a ~1 intervalo y toda
  implementación parece viva. A `interval = 2s` con sonda a 2,2 s el rojo sale.
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

### Review Findings — code review 2026-08-12 · TODAS RESUELTAS

Tres capas (blind · edge · auditor) sobre los cuatro commits. Triaje: 3 decisiones, 16 parches, 2 diferidos,
1 descartado. Las tres decisiones las cerró Sergio tras consultar a un AI externo; **discrepé de dos de sus
tres recomendaciones y la discrepancia se resolvió midiendo**, no argumentando.

**D1 — el `Never` de la rama cronometrable. Resuelto por 1D: NO se renegoció el invariante, se cumplió.**
El consultor recomendaba cerrar el canal igualando trabajo (1B), que no tiene implementación barata: igualar
una escritura en base de datos significa hacerla, que es el amplificador. Y se contradecía — argumentaba que
D2 es lo que agrava D1, recomendaba arreglar D2 (lo que restaura el tope) y mantenía 1B a plena potencia.
La cuarta opción que nadie puso sobre la mesa: **diferir la proyección a `kernel.terminate`**, con el
precedente ya resuelto en `AccessLogAuditListener` (incluido el `requestStack->push/pop` sin el cual el sellado
degrada a actor `system`). Medido antes y después: 14,70 ms / 20-de-20 → −0,37 ms / 10-de-20.

**D2 — la divergencia Unicode. Resuelto por 2A, y de acuerdo con el consultor.** `RecoveryBudgetKey` delega
en `Email`. Cierra la cota nueva **y** un bypass preexistente de `password_recovery_per_email`. Sus cuatro
verificaciones previas quedaron descargadas: el coste de `Email::tryFrom` no es clase nueva (la rama servida ya
lo llama), el limitador tiene **un** consumidor, no hay razón para distinguir whitespace RFC-válido de la
identidad, y el fallback conserva el gasto para direcciones malformadas, así que el limitador sigue sin ser
sondeable.

**D3 — la carrera con la erasure. Resuelto por 3A, contra la recomendación del consultor, y por medición.**
Su argumento y el del review descansaban en que el precedente *serializa* por el lock de fila. **No lo hace**:
`LoginAttemptRegistrar::commit()` cierra la transacción en `:103` y audita en `:105-109`, fuera. El lock ya no
existe. El precedente tiene la misma carrera, así que 3B haría de esta ruta la única con tratamiento distinto
para un residuo que el tratamiento establecido acepta — y a cambio daría a un atacante no autenticado un lock
de fila sobre `identity_user` a voluntad, en el endpoint del que trata #602. Si el régimen no admite un fallo
preventivo, el arreglo es al nivel del escritor de auditoría para **todas** las proyecciones post-commit,
incluida la de BR-4b; eso es trabajo propio.

**Los 16 parches, aplicados.** Los de código: el reclamo entra en el swallow (un `LIMIT=0` lanzaba fuera del
`try`); la tercera copia de la canonización pasa por `RecoveryBudgetKey`; el aserto insensible a mayúsculas
deja de ser código muerto y barre la fila entera; el latido afirma haber caído dentro de la banda en vez de
confiar en ello; `#[Group('slow')]` fuera, que anunciaba un interruptor inexistente cuya activación habría
borrado el único falsador. Los de prosa: la razón `Throwable`-sobre-`Exception` era falsa (`JsonException`
extiende `Exception`; el hermano contrasta contra `DbalException`, que sí es cierto), la fila de matriz sobre
el pool decía lo contrario de lo que hacen los adaptadores, `actor_type = anonymous` no está garantizado, y
las cabeceras de los dos registros llevaban cuentas rancias — re-derivadas, no incrementadas.

**Diferidos (preexistentes):** `ip`/`user_agent` que ninguna pasada de borrado limpia (misma forma que
`USER_LOCKED`), y la robustez del `RENAME` en Behat. Ambos en `deferred-work.md`.

## Spec Change Log

### 2026-08-12 · iteración 3 — el code review, y dos discrepancias resueltas midiendo

- **1D no estaba en la lista de opciones y es la que gana.** Diferir a `kernel.terminate` cierra el canal
  temporal sin devolverle trabajo al atacante, sin perder la cota y sin tocar el bloque congelado. Lo que lo
  hizo posible fue leer el precedente entero: `AccessLogAuditListener` ya resolvía la parte difícil.
- **La guarda `isMainRequest()` del listener era código muerto** y se quitó: `TerminateEvent` es `final` y pasa
  `MAIN_REQUEST` a su padre incondicionalmente (`:33`), así que esa comprobación no puede ser falsa. El hermano
  la lleva igualmente.
- **Una medición mía más, refutada:** «el `INSERT` y la lectura indexada son sub-milisegundo». Eran 14,70 ms.
  Es la quinta afirmación de este artefacto que cae, y como las otras cuatro, no cayó razonando.


### 2026-08-11 · iteración 2 — la implementación REFUTA dos hechos que la iteración 1 daba por medidos

Ninguna decisión de Sergio cambia (C + B(i), 1/1 h siguen en pie). Lo que cae es el mecanismo que yo había
justificado debajo, y cae por sonda ejecutada, no por argumento.

- **REFUTADO: «la rama denegada del sliding window suma el hit».** Es verdad del fuente y falso de `consume()`,
  que reserva con `maxTime = 0` y sale por excepción antes de ese `add()`. Medido: `remaining` se queda en `0`
  por `consume()` y cae a `−20` por `reserve(1)`.
- **REFUTADO por lo anterior: «el cubo no se rellena mientras dure el ataque»**, que la iteración 1 llevaba
  además como agravante de #602 para el cuerpo del PR. Retirado.
- **Consecuencia de diseño:** el *peek* con `consume(0)` **se elimina**. Acotaba lo que ya estaba acotado. El
  presupuesto es un `consume()` a secas.
- **Cómo se descubrió, que es la parte reutilizable:** provocando el rojo del AC del latido. No salió. Dos
  hipótesis mías seguidas —ambas derivadas de leer `vendor/`— predijeron un rojo que no ocurrió, y solo una
  sonda ejecutada dio la respuesta. Además destapó que el propio AC era **vacuo** a `interval = 1s`, porque
  `InMemoryStorage` desaloja la entrada a ~1 intervalo (`getExpirationTime()` trunca a `(int)`) y toda
  implementación parece viva; a 2 s el rojo sale.

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

**RETIRADO: «la latencia no es objeción» tal y como se argumentó.** La medición está en *Hechos medidos*: el diferencial es **14,70 ms** de mediana, positivo en 20/20 pares. Lo que sigue conserva la parte del argumento que sí aguanta —que ambas ramas pagan el mismo suelo y que la rama servida también escribe— y **no** la premisa numérica, que era falsa. El `INSERT` y la lectura indexada NO son sub-milisegundo, y la rama
denegada ya paga el suelo del KDF (decenas de ms). El docblock de `PreIdentityTimingFloor:13-17` no solo lo
tolera: prescribe *«equalise the store work inside this same envelope, never defer the write»*. Y hay un efecto
de segundo orden a favor: la rama permitida **ya escribe y ya resuelve la identidad**, así que hacerlo en la
denegada **acerca** las dos rutas en vez de crear una tercera con perfil propio.

**Por qué el presupuesto es un `consume()` a secas, y la creencia que costó dos vueltas.** El mecanismo es una
línea: el limitador acepta la primera reclamación de cada ventana y deniega el resto. Este artefacto llegó ahí
por el camino largo, y el desvío merece quedar escrito porque el error es fácil de repetir: leer
`SlidingWindowLimiter::reserve()` muestra un `$window->add($tokens)` **también en la rama que deniega**
(`:94`), de donde se sigue —y así se afirmó en la iteración 1— que las denegaciones inflan el cubo, que un
atacante puede alargar su propio silencio, y que hace falta un *peek* con `consume(0)` para no gastar el
presupuesto en observaciones suprimidas. **Nada de eso es cierto por `consume()`**, que reserva con
`maxTime = 0` y sale por `MaxWaitDurationExceededException` en `:91`, antes de ese `add()`. La sonda lo mide
en dos líneas y ninguna lectura del fuente lo dice: `remaining` se queda en `0` por `consume()` y cae a `−20`
por `reserve(1)`. El *peek* se retiró: acotaba lo que ya estaba acotado.

**Lo que sí queda como propiedad, y por eso tiene test propio:** la supresión es temporal porque una
denegación no cuesta nada al cubo. Cambiar `consume()` por `reserve()` —un cambio plausible, «para exponer el
retry-after»— hace real la inflación que la lectura sugería y convierte la supresión en permanente bajo ataque
sostenido. Ese es el rojo del latido, y hubo que buscarle la ventana: a un segundo el test es **vacuo**, porque
`InMemoryStorage` desaloja la entrada a ~1 intervalo (`getExpirationTime()` trunca a `(int)`) y toda
implementación parece viva.

**Las dos ventanas tienen la misma duración, no la misma fase.** Ambas son `sliding_window` de 1 hora, pero
cada cubo arranca en su primer consumo: el de recuperación en la 1.ª petición, el de auditoría en la 6.ª. Decir
«una fila por ventana de recuperación» sería falso; la unidad es **la ventana del presupuesto de auditoría**.
Igualarlas en duración es lo que mantiene la semántica simple; sincronizarlas en fase no es posible ni
necesario.

**Residuos a escribir, no a esconder:**
- **Concurrencia.** `lock_factory: null` permite que dos peticiones simultáneas escriban dos filas. Mismo tipo de residuo que el `USER_LOCKED` duplicado de BR-4b: ruido para el
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
- **Canal lateral de temporización sobre la propia ranura de auditoría** (hallazgo del pase adversarial). La
  primera denegación de una ventana cuesta un `SELECT` y un `INSERT`; las siguientes no cuestan ninguno, así
  que un llamante que compare sus **propios** rechazos consecutivos infiere si la ranura de esa dirección
  seguía libre — es decir, si ya se observó un agotamiento suyo dentro de la hora. **No es oráculo de
  existencia**: resoluble y no resoluble hacen la misma lectura y la misma escritura. Lo que impide
  promediarlo es el propio presupuesto, que acota al atacante a **una muestra por dirección y hora**; se
  vuelve digno de cerrar solo si ese presupuesto se ensancha o si desaparece el suelo de temporización.
- **La dirección sigue siendo clave del cubo del limitador**, hoy y sin este cambio
  (`PasswordRecoveryThrottle:38`). El presupuesto nuevo no introduce una clase de dato nueva.
- **RETIRADO: «el cubo no se rellena mientras dure el ataque».** La iteración 1 lo daba por medido y por
  agravante de #602. **Es falso**, y la sonda lo dice: por `consume()` las denegaciones no tocan el conteo, así
  que el presupuesto de la víctima se rellena a su hora y el agotamiento dura lo que dice `interval`. No hay
  nada que llevar al cuerpo del PR por esta vía.

## Hechos medidos — no re-derivar

Cada uno costó una sonda o una lectura de `vendor/`; ninguno vive en el código de la app.

| Hecho | Medición |
|---|---|
| **El `add()` de la rama denegada es INALCANZABLE desde `consume()`** — y esto **refuta** lo que este artefacto afirmó en su iteración 1 | `SlidingWindowLimiter.php:94` existe y es gemela de `:78`, pero `consume()` reserva con `maxTime = 0`, así que `MaxWaitDurationExceededException` se lanza en `:91` y desenrolla **antes** del `add()` de `:94` y del `save()` de `:100`. **Sonda ejecutada** (`limit: 1`, 1 aceptada + 20 denegadas): vía `consume()` `remaining` se queda en **0** y nunca baja; vía `reserve(1)` llega a **−20** |
| **Por tanto un cubo martilleado SÍ se rellena a su hora** | Corolario medido del anterior. No existe la «ventana que no se vacía mientras dure el ataque»: un atacante no puede alargar su propio silencio. Vale para `password_recovery_per_email` igual que para el presupuesto de auditoría |
| **`reserve()` sí infla el cubo, y ese es el rojo del latido** | Misma sonda: tras 20 denegadas por `reserve(1)`, al rodar la ventana el cubo sigue **denegando**. Cambiar `consume()` por `reserve()` «para ver el retry-after» convierte una supresión temporal en permanente bajo ataque |
| **`InMemoryStorage` desaloja a ~1 intervalo y eso hace VACUO todo test de ventana corta** | `getExpirationTime()` (`SlidingWindow.php:59`) **trunca a `(int)`**, así que con `interval = 1s` el desalojo colapsa sobre el rodaje: la entrada desaparece, se acuña ventana nueva y **toda implementación parece viva**. El primer test del latido pasó con la mutación puesta por esto. Con `interval = 2s` y sonda a 2,2 s el rodaje (2,0 s) y el desalojo (~3,0 s) se separan y el rojo sale |
| **`consume(0)` es un *peek* gratuito y no mutante — pero aquí no compra nada** | `SlidingWindowLimiter.php:71-75` retorna antes de tocar la ventana; el `save()` de `:100` está guardado por `if (0 !== $tokens)` en `:99`. Cierto y sin uso: `consume()` a secas ya acota a 1 por ventana |
| **`fixed_window` no es la salida** | `Window::getCarriedHitCount()` (`Window.php:78-88`) resta `windowsElapsed × maxSize` = 1 por ventana con `maxSize = 1`. Irrelevante una vez medido que `consume()` no infla, pero se deja escrito para que nadie lo «arregle» por ahí |
| **`security` propaga; `activity` traga** | `SymfonyAuditLogger.php:65-72` vs `:77-92`. **Ambos escriben síncronamente en el ciclo de petición** — `activity` no va por cola; lo que corre en `kernel.terminate` es la *captura* genérica, no un diferimiento dentro del logger |
| **La dirección en `metadata` sería una fuga INDETECTABLE, no solo sin dueño** | `.person-reference-policy:81-97`: los cuatro controles que sostienen el «cero ids en `metadata`» casan por **id** contra `identity_user` — (a) por el id del sujeto borrado, (b) y (d) por *join*. Una dirección no la ve ninguno. Y ni `DbalAuditActorAnonymiser` ni `DbalAuditResourceAnonymiser` tocan `metadata` |
| **Nombrar al sujeto en `resource_id` NO minta obligación nueva** | `.audit-resource-types:105` ya lleva `User => person :: FulfilIdentityErasure.php :: erase.feature`, y `.person-reference-policy:168-173` confirma que `audit_log.resource_id` está **dentro** del control detectivesco como colaborador cableado. No hay línea de registro que añadir |
| **La diferida cierra el canal — antes/después con el mismo método** | En banda: mediana 333,65 ms (1.ª) vs 318,82 ms (2.ª), delta **14,70 ms**, **20/20** pares positivos. En `kernel.terminate`: 318,47 vs 318,51, delta **−0,37 ms**, **10/20** positivos — azar. Mismo script, mismo stack, 20 direcciones nuevas |
| **`Assert\Email` estricto ADMITE `\u{00A0}` en los bordes; `\u{200B}` y `\uFEFF` no** | Sonda con el validador real sobre `ForgotPasswordRequest`: `\u00A0`+dirección y dirección+`\u00A0` pasan a 202; el viejo `trim()` ASCII los dejaba en la clave mientras `Email` los recortaba ⇒ cubos distintos, una identidad, y el propio `password_recovery_per_email` evadible repitiendo el carácter |
| **El diferencial de latencia entre 1.ª y 2.ª denegación es de ~15 ms, NO sub-milisegundo** | 20 direcciones distintas contra el stack vivo (HTTPS, loopback): mediana **333,65 ms** (1.ª, hace `SELECT`+`INSERT`) vs **318,82 ms** (2.ª, no hace ninguno) ⇒ **delta mediano 14,70 ms**, **positivo en 20/20 pares**, contra una sd de ruido de **5,10 ms** en la línea base. ~3× el ruido y sin necesidad de promediar. **Refuta la premisa numérica con la que este artefacto justificó el canal** |
| **D2 es la cota de D1, no un hallazgo independiente** | El argumento de bajo valor descansa en «una muestra por dirección y hora». Con la divergencia Unicode se mintan cubos ilimitados para la MISMA dirección ⇒ muestras ilimitadas de primera-denegación contra un solo buzón |
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
     latido que la decisión pretendía. **Corregido en la iteración 2 tras medirlo:** el refinamiento era
     innecesario porque su premisa era falsa — `consume()` no infla el cubo al denegar. El presupuesto es un
     `consume()` a secas y el latido lo da el limitador; ver *Spec Change Log*.
2. **`resource_id`: resolver `email → identity` en la rama denegada; con identidad, el UUID del sujeto; sin
   identidad, fila escrita **sin** recurso.** Se descarta la fila siempre sin recurso (desaprovecha una
   atribución que el sistema ya puede obtener por un eje **ya gobernado**, y #602 pregunta justamente *a quién*
   están inutilizando) y se descarta no escribir fila en el caso no resoluble (la ausencia carga el mismo bit y
   pierde la señal del barrido).
   - **No negociable:** tipo por `FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE`, nunca el literal `'User'`;
     derivador bajo `src/Iam/Identity/`; vía `AuditResource::of()`; **ninguna representación de la dirección en
     `metadata`**, en ninguna forma.

## Pase adversarial — 2026-08-11, ANTES de abrir la PR

Lectura hostil por un contexto fresco (subagente read-only, autorizado por Sergio), no por la autora. Devolvió
**NO-GO** con 2 SERIOUS y 4 MINOR. Todos corregidos en esta misma rama, antes de `gh pr create`. Su
verificación negativa se conserva porque vale tanto como los hallazgos.

**SERIOUS — la escena de aceptación principal era VACUA, y por la tercera repetición del mismo error mío.**
Afirmé que el pool del limitador «no se resetea entre escenas». Se resetea **entre peticiones**: el pool de
test es `cache.adapter.array` y el `services_resetter` lo limpia en cada `kernel.terminate`. Mis peticiones 2.ª
y 3.ª por tanto llegaban con presupuesto **lleno**, se **servían** (minteando token y correo), y no llegaban
siquiera a `record()`. Consecuencias medidas por él: borrar la guarda del presupuesto dejaba la escena verde,
y el pin de casing «de punta a punta» que el comentario prometía **no existía**. Corroborado de forma
independiente: `bank/count.feature` manda sietes peticiones en una escena bajo un límite de 5 y pasa.
**Corregido:** una sola petición por escena, primeando en minúsculas y pidiendo en otro casing — lo que sí
pinea `RecoveryBudgetKey` de punta a punta dentro de la regla del harness. El techo de amplificación se declara
donde de verdad vive, el unitario, y la escena dice por qué no puede vivir en aceptación. **Lo que había que
leer antes de escribir la escena era el docblock de `RateLimitContext`, que lo explica en sus propias
palabras.**

**SERIOUS — tres documentos duraderos afirmaban lo contrario de lo medido.** El comentario de la feature, la
lista de tareas del artefacto y un AC. **Corregidos los tres**, con el mecanismo real escrito.

**MINOR — `«swallowing every fault»` era sobreafirmación en el docblock del controlador.** `claimFor()` y el
propio `logger->warning` quedan fuera del `try`. El comportamiento es defendible —el pool ya se consumió sin
guarda dos líneas antes, en el throttle— pero la frase prometía un invariante que el código no sostiene.
**Corregida la frase, no el código.**

**MINOR — canal lateral nuevo, ausente de la lista de residuos.** La primera denegación de una ventana cuesta
`SELECT` + `INSERT` y las siguientes no cuestan ninguno, así que un llamante que compare sus propios rechazos
consecutivos infiere si la ranura de auditoría de esa dirección seguía libre. No es oráculo de existencia
(resoluble y no resoluble hacen la misma lectura y la misma escritura) y el presupuesto lo acota a **una
muestra por dirección y hora**, que es justo lo que impide promediarlo. **Escrito como cuarto residuo** en el
checklist.

**MINOR — `api/.env.example` no nombraba la palanca** que el checklist dice que el operador puede tocar.
Añadidas las dos variables nuevas, más las dos de `PASSWORD_CHANGE` que ya faltaban de antes (boy-scout, en un
fichero que ya tocaba).

**MINOR — la segunda aserción de la escena del `RENAME` es vacua** (`[]` es también lo que daría una proyección
que no existe) y el `RENAME` de vuelta no es a prueba de excepciones. Ambas cosas son el patrón que BR-4b ya
dejó en `main`. **Anotada como comprobación de completitud, no de falsación**, junto a la mitad que sí falsea:
estrechar el swallow pone esa escena —y solo esa— en 500, medido.

**Lo que atacó y NO pudo romper**, que vale tanto como lo anterior:
- **Aritmética del limitador, MEDIDA**: `remaining` se queda en `0` por `consume()` tras 1 aceptada + 20
  denegadas y llega a `−20` por `reserve(1)`; el `hitCount` es `1` antes y `1` después de 50 rechazos. El techo
  «una fila por dirección canonizada e intervalo» aguanta.
- **El test del latido es genuinamente falsable, MEDIDO**: sin mutar, aceptado a +2,2 s; con la mutación que el
  propio test nombra, **rechazado**. Y la entrada de `InMemoryStorage` vive a +2,1 s y ha desaparecido a +3,1 s,
  así que la aserción cae dentro de la banda que discrimina.
- **Cegar el control forzando el fallo del INSERT: sin vector.** Toda columna influida por el atacante está
  acotada aguas arriba — `user_agent` truncado a 512 contra una columna de 512, `correlation_id` validado
  contra UUIDv7 o reacuñado, `ip` por `filter_var`, `metadata` vacío (`JSON_THROW_ON_ERROR` no puede dispararse)
  y `resource_id` ya pasó `Uuid::isValid()`.
- **La dirección no alcanza almacenamiento ni log, MEDIDO**: el mensaje de `DriverException` no lleva los
  parámetros ligados, `zend.exception_ignore_args = On` limpia los argumentos de las trazas, `includeStacktraces`
  no está activo en ningún handler y Sentry está comentado.
- **Cambio de respuesta por entrada: ninguno.** `#[Assert\Email]` en modo estricto acota la entrada a ~320
  caracteres antes del controlador; una dirección malformada nunca alcanza la rama denegada; `Email::tryFrom`
  devuelve null sin lectura de BD; y `CacheStorage` hace sha1 del id, así que la `@` nunca llega a la
  validación de claves PSR-6.
- **Oráculo de temporización por existencia: no explotable**, y con un argumento mejor que el mío — el
  presupuesto acota al atacante a una muestra por dirección y hora, así que el diferencial no se puede
  promediar.
- **Los unitarios afirman el sistema, no el doble**: las dos propiedades que importan corren contra el
  `RateLimiterFactory` real.
- **Registros y puertas limpios**: `.audit-resource-types` ya cubre el tipo, la contención del fichero se
  respeta, la plaza única de `.person-reference-policy` gobierna *fuentes*, no escritores, los cuatro patrones
  Behat ya estaban `used`, y `RecoveryBudgetKey::forEmail` es la expresión verbatim, así que
  `PasswordRecoveryThrottle` no cambió de comportamiento.

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
  **latido** (sin él, cambiar `consume()` por `reserve()` no rompe nada visible y la supresión se vuelve
  permanente bajo ataque).
- **La afirmación del acarreo se verificó con sonda ejecutada y salió FALSA** para `consume()`; el diseño se
  simplificó en consecuencia. Toda afirmación futura sobre la aritmética del limitador se mide, no se lee.
- `curl -k`: las seis respuestas del agotamiento idénticas en estado y cuerpo, sin cabecera nueva.
- El pase adversarial es **gate para ABRIR la PR**, no para llegar a `done` (`CLAUDE.md` → *Security review on
  every change* → *Process*): corre y se escribe en este artefacto **antes** de `gh pr create`. Requiere pedir
  autorización a Sergio para lanzar el subagente read-only.
