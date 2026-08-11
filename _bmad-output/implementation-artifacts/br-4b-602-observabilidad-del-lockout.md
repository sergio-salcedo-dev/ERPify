---
title: 'BR-4b · #602 — observabilidad del lockout (control detectivesco)'
type: 'feature'
created: '2026-08-10'
status: 'in-progress'
review_loop_iteration: 0
baseline_commit: 935e86dc
context:
  - '{project-root}/docs/adr/administrative-recovery-channel.md'
  - '{project-root}/docs/adr/identity-invitation-lifecycle.md'
  - '{project-root}/api/.audit-resource-types'
---

> Épica: `epics-backlog-resolution.md` · recorte de BR-4 (`:110`), que cerró sin #602 por decisión
> Rama: `fix/iam-lockout-observability-602-lzwj` · Base: `main` @ `935e86dc`

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problema:** el lockout persistido por identidad emite `UserLocked` y **nadie lo consume** (`git grep UserLocked -- api/src`): no hay fila en `audit_log` ni aviso al dueño de la cuenta, así que un administrador al que le disparan el bloqueo por correo se entera cuando ya no puede entrar. #602 quedó **aceptado tal cual**; esto entrega la mitad de observabilidad que dejó sin elegir.

**Enfoque:** un control **detectivesco** en dos mitades — (A) una fila `security` en `audit_log` por transición a bloqueado, escrita **post-commit best-effort** desde el caso de uso; (B) un aviso al dueño enviado desde un **tick del planificador**, nunca desde la petición, con supresión de 1 por dirección cada 24 h.

## Boundaries & Constraints

**Always:**
- **El control NO añade arista al grafo de recuperación, y no por etiqueta sino por derivación.** El aviso viaja por el correo — el mismo espacio de nombres que el atacante consume para disparar el bloqueo (`administrative-recovery-channel.md:43-50`, I-1 y su corolario). Por eso **no puede llevar nada ejercitable**: ni token, ni selector, ni enlace que conceda algo, ni identificador de canal de recuperación. Solo el hecho y la guía de orden (*expulsa primero, rota después*).
- El `resource_type` se alcanza **por la constante** `FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE`, jamás por el literal `'User'`: `DbalAuditResourceAnonymiser` borra con `WHERE resource_type = :type`, y `PersonResourceErasureGateTest` exige que el literal viva en un solo fichero.
- Todo fichero que derive un tipo-persona vive bajo `src/Iam/Identity/` (regla de contención del gate).
- El mensaje del tick lleva **payload vacío**. Un fan-out por usuario metería la dirección en `messenger_messages`/`failed`, tablas sin TTL que ningún borrado toca — y `.persistent-transport-policy` **no lo detectaría** (está en su propia lista de puntos ciegos).
- La supresión es **estado persistido en la fila del sujeto** (`identity_user.lockout_notified_at`), no un bucket de caché. Así es propiedad del *sistema*: sobrevive al redespliegue, vale entre contenedores, se siembra en Behat como `locked_until`, y se borra con la fila — sin obligación GDPR nueva y sin la dirección como clave de caché.
- El correo se construye con `BulletproofEmailChrome` + `SecuritySenderAddress` (nunca `SecurityLinkMailer`, que exige token y CTA), en inglés, sin IP/dispositivo/marca temporal en el cuerpo.

**Ask First:**
- Poner `MAILER_SECURITY_FROM` / `MAILER_FROM` / `MAILER_DSN` en `compose.prod.yaml` con la forma **`${VAR:?}`** (fail-fast al arrancar) en vez de un valor por defecto. Es lo coherente con `APP_SECRET`/`AUDIT_KEK` en ese fichero y no hay entorno de producción vivo, pero convierte una variable ausente en un arranque fallido.
- Cualquier cambio al `SessionAdmissionGate` o a `revoke-others`. Ambos son trampas anotadas.

**Never:**
- **No tocar `LoginAttemptRegistrar:51`** (lectura sin bloqueo de fila). Cerrar esa carrera abarata el DoS y sigue sin haber palanca de recuperación. Se deja deliberadamente y se dice en el PR.
- No reproponer lo rechazado por el issue: dimensión de origen/IP en el lockout persistido, MFA, filtrar `locked_until` del directorio.
- No `users.unlock`: presupone un segundo administrador alcanzable, que es justo lo que la instalación vulnerable no tiene.
- No Redis, y ya no hay limitador que respaldar: la supresión es una columna.
- **No cerrar la carrera de `:51` para hacer cierto el "una sola fila".** La duplicidad bajo concurrencia se documenta, no se arregla.
- **No outbox** para el correo, y **no reserva condicional del sello**: reservar-antes-de-enviar cambia la semántica de entrega recién fijada y devuelve el silencio de 24 h ante un fallo SMTP.
- Fuera de alcance, sin «mejorarlo» de paso: `SessionAdmissionGate`, `revoke-others`, MFA, dimensión de origen/IP, filtrado de `locked_until` en el directorio, `users.unlock`.
- No reescribir las referencias históricas a `Backoffice/Identity` en los ADR que **decidieron** esa ubicación.

## I/O & Edge-Case Matrix

| Escenario | Entrada / Estado | Comportamiento esperado | Manejo de error |
|---|---|---|---|
| Décimo fallo sobre identidad ACTIVE | `failed_attempts = 9`, credencial errónea | 401 uniforme · `locked_until = now+15m` · 1 fila `audit_log` `USER_LOCKED`/`security`/`resource_id = <sujeto>`/`actor_type = anonymous` | N/A |
| `audit_log` inescribible | idem, tabla renombrada | 401 uniforme · **el bloqueo persiste igual** · warning con la excepción | `catch (Throwable)` post-commit |
| Fallo 11..N dentro de la ventana | ya bloqueado | Sin transacción, sin evento, **sin fila nueva** | N/A |
| Fallo sobre correo desconocido | sin identidad | 401 uniforme · nada escrito | N/A |
| **Dos décimos fallos concurrentes** | dos peticiones cruzan el umbral a la vez | 401 en ambas · **dos `UserLocked` y dos filas** — reflejo fiel del duplicado que `event_store` ya produce | Residuo documentado, no defecto de esta PR |
| Tick con identidad bloqueada | `locked_until > now()`, `lockout_notified_at IS NULL` | 1 correo · se sella `lockout_notified_at` | Envío best-effort: warning y sigue |
| Segundo tick en la misma ventana de 24 h | `lockout_notified_at > now() - 24h` | **0 correos** | N/A |
| Tick a las 24 h + 1 | bloqueo aún vivo, sello caducado | 1 correo · se resella | N/A |
| Fallo SMTP en el tick | mailer lanza | Barrido continúa con el resto; **no se sella** el que falló | Warning; nunca aborta el tick |

</frozen-after-approval>

## Code Map

- `api/src/Iam/Identity/Application/LoginAttemptRegistrar.php` — `:85-91` la transacción; se captura si `UserLocked` iba entre los eventos y se audita **después** del commit
- `api/src/Iam/Identity/Application/ChangeUserRoles.php:165-173` — forma exacta de la llamada a `AuditLogger`
- `api/src/Iam/Identity/Application/SendPasswordChangedEmailBestEffort.php` — patrón `…BestEffort`
- `api/src/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSender.php` — el único correo de seguridad sin enlace; plantilla a copiar (heredoc, sin Twig)
- `api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php` — precedente de puerto estrecho de lectura (`#[AsAlias]`, DBAL crudo); **no** ampliar `UserRepository`
- `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceSchedule.php` — el schedule existente; transporte ya cableado en ambos compose
- `api/src/Iam/Identity/Domain/Entity/User.php:88-97` — mapeo de `failed_attempts`/`locked_until`; `lockout_notified_at` va a su lado
- `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php:47,79-84` — por qué la carrera NO queda serializada aquí (ver *Design Notes*)
- `api/features/backoffice/audit/access_control.feature:49-64` — idioma de aislamiento por `X-Correlation-Id` (**no** `TRUNCATE`)
- `api/.behat-step-vocabulary` — toda escena nueva recalcula clasificaciones `used`/`idle`; gate `make php.lint.step-vocabulary`

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Iam/Identity/Application/RecordLockoutAuditBestEffort.php` -- creado; `catch (Throwable)` + `logger->warning` -- el `Throwable` (no solo DBAL) cierra el escape de `JsonException` desde `DbalAuditLogWriter:54`
- [x] `api/src/Iam/Identity/Application/LoginAttemptRegistrar.php` -- auditoría post-commit, sujeto tomado de `$event->aggregateId()` (`AggregateRoot::id()` es protegido) -- `CLAUDE.md:99`
- [x] `api/src/Iam/Identity/Domain/Repository/LockedIdentityDirectory.php` + `Infrastructure/Persistence/Doctrine/DoctrineLockedIdentityDirectory.php` -- puerto estrecho de **UNA** operación de solo lectura, `findLockedAt($now)`, que devuelve **ids** -- ver el Change Log del 2026-08-11 (segunda ronda): el DTO se descartó y el sello pasó al agregado
- [x] `api/src/Iam/Identity/Application/AccountLockedEmailSender.php` + `Infrastructure/Mail/SymfonyAccountLockedEmailSender.php` + `Application/SendAccountLockedEmailBestEffort.php` -- el `…BestEffort` devuelve **`bool`**, a diferencia de sus hermanos: es lo que permite sellar solo tras un envío confirmado
- [x] `api/src/Iam/Identity/Domain/Entity/User.php` + `api/migrations/2026/Version20260811110107.php` -- columna `lockout_notified_at`, `awaitsLockoutNoticeAt($now, $staleFrom)` (los tres conjuntos) y `markLockoutNotified($at)` (que **no** toca `updatedAt`). Reversibilidad ejecutada: 1 → `down` → 0 → `up` → 1
- [ ] `api/tests/Unit/Iam/Identity/Application/InMemoryUserRepository.php` -- **arreglar `findById()`, que ignora su argumento** y devuelve siempre el mismo preset; sin esto todo test de barrido multi-fila es vacuo. Hacerlo ANTES que el barrido
- [ ] `api/tests/Unit/Iam/Identity/Application/RecordLockoutAuditBestEffortTest.php` -- pasa a través · traga y registra a `warning` con la excepción en contexto · **la línea de log no nombra ningún id**. Hoy: borrar su `logger->warning` deja la suite entera verde, y su cobertura reporta 0% por atribución
- [ ] `api/src/Iam/Identity/Application/NotifyLockedIdentities.php` -- barrido: **un solo `now`**, `awaitsLockoutNoticeAt()` como única autoridad, envío, y sello SOLO si el envío devolvió `true`; `try` **por fila**, nunca envolviendo el barrido entero
- [ ] `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/NotifyLockedIdentitiesMessage.php` + `…Handler.php` -- mensaje **sin payload**
- [ ] `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceSchedule.php` -- `RecurringMessage::every('5 minutes', …)` **propio**; el schedule existente tickea a `1 day` y un barrido diario vería un lock de 15 min por casualidad
- [ ] Dobles nuevos que los ceros de rojos exigen -- `AdvancingClock` (cada `now()` +1 s, y cuenta llamadas: un `FixedClock` **no puede** distinguir «una vez» de «por fila»), un doble de repositorio que registre el ORDEN envío↔sello, y un doble de directorio que devuelva **todo id sembrado sin filtrar** (si replica el SQL, el test prueba el fake)
- [ ] `api/tests/Unit/Iam/Identity/Application/NotifyLockedIdentitiesTest.php` -- tres transiciones de ventana · **dos ticks con el mismo reloj ⇒ 1 correo** (lo único que fija la supresión de punta a punta) · mailer que lanza en la fila 2 y las 1 y 3 sí se intentan y sellan · `now` una sola vez
- [ ] `api/tests/Functional/Iam/Identity/DoctrineLockedIdentityDirectoryTest.php` -- **única capa** donde el binding `DATETIME_IMMUTABLE`, el `>` y el `ORDER BY id` pueden ponerse rojos. Plantilla: `DoctrineLiveIdentityDirectoryTest.php`
- [ ] `api/tests/Unit/.../IdentityMaintenanceScheduleTest.php` -- extender: es el **único** rojo del cableado, porque plegar el tick no añade transporte y `php.lint.schedule-consumption` sigue verde de cualquier modo
- [ ] `api/features/backoffice/identity/login.feature` -- extender `:190-203` con correlation-id fijo + `the SQL result as JSON should be:`; **nueva** escena con `ALTER TABLE audit_log RENAME … on connection "seed"` que prueba que el bloqueo sobrevive. Buscar el vocabulario antes de escribir cualquier step (`make php.behat c='-dl'`) y regenerar `api/.behat-step-vocabulary`
- [ ] `compose.prod.yaml` -- `MAILER_SECURITY_FROM`, `MAILER_FROM`, `MAILER_DSN` (ver *Ask First*, **sin resolver**)
- [ ] `docs/adr/identity-invitation-lifecycle.md` -- **D14**, redactado como *«lockout observability is detective-only»* y **nunca** como «se añade un canal de notificación», que induciría a leerlo como ampliación del grafo: el aviso informa, no concede, y su canal de entrega comparte el espacio de nombres que el atacante ya consume. Más corregir la cabecera `not yet implemented`
- [ ] `docs/architecture-api.md:265` -- añadir `USER_LOCKED` a la taxonomía con el argumento de amplificación + corregir la ruta rancia `Backoffice/Identity` → `Iam/Identity`
- [ ] `PRODUCTION_SECURITY_CHECKLIST.md` -- corregir la entrada rancia `:636-645` (el cambio de rol **sí** se audita; #555 cerró) + entrada del control nuevo con sus residuos

**Acceptance Criteria:**

*Camino feliz y camino de fallo se enuncian por separado a propósito: juntos leerían como una garantía de sincronía entre `event_store` y `audit_log` que el diseño niega deliberadamente.*

- **Feliz:** cada `UserLocked` que **alcanza commit** provoca un intento independiente de proyección, y en condiciones normales una fila `USER_LOCKED` con `level='security'`, `resource_id` = el sujeto y `actor_id IS NULL`, aislada por su `X-Correlation-Id`. (Por evento, no por transición lógica: ver el residuo de concurrencia.)
- **Fallo:** con `audit_log` no disponible, el `UserLocked` **sigue siendo durable** y no existe obligación de fila; `failed_attempts = 10` y `locked_until > now()` — **el bloqueo sobrevive al fallo de su propia auditoría**. La proyección es best-effort y su fallo no revierte el bloqueo.
- **La ventana se falsifica en sus tres transiciones, no como booleano:** `lockout_notified_at IS NULL` → 1 correo y se sella con `now`; sello en `now - 23h59m` → **0** correos; sello en `now - 24h - ε` → 1 correo y se resella. Un test que solo distinga «envía / no envía» no prueba que haya ventana.
- Dado un envío que lanza, entonces `lockout_notified_at` **no** queda sellado — un fallo de entrega no consume la ventana.
- El barrido usa **un solo `now`** por ejecución, tomado del reloj inyectado, para el predicado y para el sello. **Es aserción, no solo implementación:** con el reloj fijado, el sello escrito es idéntico al `now` con que se consultó, para todas las filas del barrido.
- El correo no contiene token, selector, enlace de acción ni identificador de canal de recuperación.
- Ningún escenario existente de `login.feature` ni el pin de `session.feature:86-101` cambia de color.

## Spec Change Log

### 2026-08-10 · revisión hostil del draft (4 hallazgos, 2 bloqueantes)

- **H1 — «exactamente una fila» no demostrado bajo concurrencia. ACEPTADO; AC reformulado.** Mi defensa era mala: creí que `event_store_stream_version_uniq` serializaba la carrera. **No lo hace**, y la razón es el orden de bloqueos: `commit()` hace `save()`→`flush()` (UPDATE, que toma el lock de fila) *antes* de `publish()`, así que la segunda petición se bloquea en el UPDATE, despierta tras el commit de la primera y su sub-select `MAX(aggregate_version)+1` **ya ve la fila ajena** → versión distinta, sin colisión, dos eventos. El lock de fila que parecía proteger es justo lo que separa las versiones. El AC pasa a enunciarse **por evento**, con el residuo escrito. No se cierra la raíz (`:51`): #602 lo prohíbe.
- **H2 — la supresión era propiedad de un contenedor, no del sistema. ACEPTADO; mecanismo sustituido.** Fuera el limitador de caché; entra `identity_user.lockout_notified_at`. Estado evitado: una garantía de «24 h» que muere en cada redespliegue, no vale entre `messenger_worker` y `scheduler_worker`, y tenía **cero rojos** por no haber reloj sembrable. **KEEP:** el sellado ocurre *después* de un envío con éxito, nunca antes — sellar primero convertiría un fallo SMTP en 24 h de silencio.
- **H3 — ventana entre `event_store` y `audit_log`. ACEPTADO** como proyección eventual, nombrada en los residuos.
- **H4 — varias identidades a la misma dirección. REFUTADO por medición**, no por argumento: `identity_user.email` es `uniq_39e1fcde7927c74 UNIQUE, btree (email)` en la tabla viva, y `Email::from()` canonicaliza a minúsculas antes de persistir (`Email.php:11-19`, *"the canonicalisation function of a database constraint"*). Una dirección canónica ⇒ como mucho una identidad ⇒ el barrido no puede duplicar destinatario.

### 2026-08-11 · segunda revisión hostil — GO condicionado (4 precisiones, 0 bloqueantes)

Ninguna tocó el mecanismo; las cuatro cierran contratos que el draft dejaba implícitos y que un revisor futuro habría leído como contradicciones.

- **AC partido en camino feliz y camino de fallo.** Estado evitado: un AC que, leído entero, prometía sincronía entre `event_store` y `audit_log` — exactamente lo que H3 declara que no existe.
- **Carrera entrega/persistencia, escrita como residuo.** El aviso es *at-least-once* respecto al éxito de SMTP: se envía y después se sella, así que un fallo del sello tras una entrega buena reenvía en el tick siguiente. **KEEP:** no arreglarlo con un outbox — reabriría la superficie persistente que el mensaje sin payload existe para evitar.
- **`now` único por barrido.** Estado evitado: un reloj por fila, que haría el predicado y el sello incoherentes dentro de la misma ejecución.
- **El sello se escribe por el puerto estrecho, no por el agregado.** El agregado *declara* la columna (para que muera con su fila); el tick nunca carga `User` ni pasa por `UserRepository`. **KEEP:** es lo que impide que un futuro cambio reutilice el repositorio desde el planificador «porque ya está ahí», arrastrando la credencial al mantenimiento.
- **Exclusión de ticks concurrentes: MEDIDA, no supuesta** (`compose.prod.yaml:173,219-220,225`; `compose.yaml:129`), con su límite dicho — descansa en el pin de `replicas: 1`.

### 2026-08-11 · tercera revisión hostil — GO, 0 bloqueantes (endurece la evidencia, no el diseño)

Confirma el mecanismo entero y no pide rediseño. Lo que añade es evidencia ejecutable:

- **La ventana de 24 h se falsifica en sus TRES transiciones** (nula → envía y sella; `now-23h59m` → 0; `now-24h-ε` → envía y resella), porque un test que solo distinga «envía / no envía» no demuestra que exista ventana, solo un booleano.
- **El `now` único pasa de implementación a aserción**: con el reloj fijado, el sello escrito coincide con el `now` de la consulta en todas las filas.
- **Frase contractual para el cuerpo del PR**, en inglés y literal, para que un revisor futuro no lea la duplicidad como bug de esta PR:
  > *The audit projection is per committed `UserLocked` event, not per logical lockout transition. Concurrent threshold crossings may therefore produce duplicate `USER_LOCKED` audit rows.*
- **Lista de fuera-de-alcance explicitada** en *Never*, con outbox y reserva condicional del sello añadidos a los vetos que ya había.

### 2026-08-11 · segunda ronda de consulta (Winston + Amelia-2) sobre el barrido — **invierte dos decisiones de este artefacto**

Disparada por tres decisiones que tomé sobre la marcha al implementar. Las dos primeras entradas **anulan** lo que decía el Change Log anterior; se dejan ambas para que el cambio sea legible.

- **ANULA el `KEEP` del 2026-08-11 («el sello se escribe por el puerto estrecho, no por el agregado»).** El puerto es ahora de **solo lectura** y el sello lo escribe el agregado vía `UserRepository`. Lo forzó PHPStan (`property.neverAssigned`, luego `property.onlyWritten`: ninguna propiedad mapeada del repo se escribe solo por SQL) y lo confirmó el eje de referencias-a-persona: la columna en la fila del sujeto **no crea obligación GDPR ninguna** (es `DATETIME`, ni entra en el universo del gate), mientras que una tabla aparte habría minteado línea `person ::`, `#[PersonSubjectReference]`, `PersonReferenceSource`, wiring y test. Coste aceptado: el worker sostiene el agregado (con digest) de las identidades que notifica. **D3 (borrar el DTO) queda ENTRAÑADO por esto** — si esta decisión se revierte, el DTO vuelve; no se re-litiga por separado.
- **ANULA «el SQL es pre-filtro, el agregado es la autoridad».** Apuntaba al revés: aflojar el predicado PHP no cambia nada (cero rojo), pero endurecer el SQL hace desaparecer envíos **en silencio y sin que ningún test del agregado pueda verlo**. La duplicación no se sincroniza, **se elimina**: el SQL expresa candidatura (`locked_until > :now`) y la política vive una sola vez en `awaitsLockoutNoticeAt()`. **KEEP:** no estrechar el SQL a `locked_until IS NOT NULL` — `clearLockout()` solo corre en login con éxito, así que quien se bloquea y nunca vuelve conserva expiry para siempre y el conjunto crece sin límite.
- **Defecto real que ninguno de los dos predicados cubría:** quien entra con éxito entre la consulta y la hidratación ya no está bloqueado, pero `clearLockout()` no limpia el sello — el barrido le mandaba un aviso falso **y le quemaba el sello** 24 h. De ahí los tres conjuntos de `awaitsLockoutNoticeAt()`, cada uno con su ruta: `ACTIVE` (bloqueo → desactivación → tick dentro de la ventana → correo a cuenta retirada), sigue-bloqueado (el de arriba), sello caducado.
- **Bug de paginación evitado:** `markLockoutNotified()` tocaba `updatedAt`, que es **columna de keyset** del registro de usuarios (`UserRow:20,38`) — un mailer desatendido reordenando la lista de administradores bajo un cursor abierto. Línea eliminada; además sus dos hermanas de lockout tampoco la tocan.
- **Tres afirmaciones mías, falsas, corregidas en los docblocks:** no hay índice sobre `locked_until` ni `lockout_notified_at` (scan secuencial; **no se añade índice**, sería coste sin medición); «una columna nunca seleccionada no llega al worker» es cierto del puerto y engañoso del tick; y PHPStan no eligió la ubicación de la columna, solo descartó una de cuatro opciones.

## Design Notes

**Por qué post-commit y no en el handler** — corrige la restricción 3 del relevo, refutada por dos consultores independientes:

1. **`event_store` ya guarda la fila atómica.** `DbalEventStore::append()` corre en la misma `wrapInTransaction`, así que todo bloqueo persistido ya tiene registro durable (`aggregate_type = Iam.Identity`). La fila de `audit_log` no es *el registro*: es una **proyección** sobre la superficie del operador, y una proyección es best-effort por definición.
2. **`CLAUDE.md:99` lo prescribe** para eventos de agregado-persona sin enrutar.
3. **El fallo in-transacción es mudo y misdescriptivo.** `save()` hace `flush()`, así que el `UPDATE identity_user` ya está en el cable cuando falla el INSERT; PostgreSQL responde al `COMMIT` abortado con `ROLLBACK` silencioso, y `HandlersLocator` sirve mi handler antes que `RunProjectionsOnDomainEvent`, cuyo `SAVEPOINT DOCTRINE_2` falla `25P02` — el error resultante **nombra al proyector**, no a la auditoría. El `catch (DbalException)` de `ProblemDetailsAuthenticationFailureHandler:103` **no tiene logger**.
4. **Es la única opción falsificable.** Ninguna escena feliz distingue las tres; la renombrada de tabla sí.

**Descartado:** in-transacción con `catch (Throwable)` — el 500 diferencial que lo justificaba es un fantasma (ningún throw no-DBAL es alcanzable con acción constante y UUIDv7), y esa superficie ni siquiera sería nueva: `RunProjectionsOnDomainEvent` ya corre en cada `UserLocked` con `CorruptEventStoreRow` sin guarda.

**Por qué no amplifica escritura.** `recordFailedAttempt()` devuelve `false` mientras la identidad ya está bloqueada, así que no se abre transacción: **una fila por ventana de 15 minutos por hilo secuencial**, techo del propio ataque. No hace falta el presupuesto que `INVALID_CURRENT_PASSWORD` sí necesita. El multiplicador bajo concurrencia es el del residuo de abajo, y es de orden 1, no de orden N.

**Residuos a escribir, no a esconder:**
- **Concurrencia.** Dos décimos fallos simultáneos producen **dos** `UserLocked` y dos filas. La raíz es el lost-update de `LoginAttemptRegistrar:51`, que #602 ordena no cerrar porque hacerlo abarata el DoS sin dar palanca de recuperación. Preexistente en `event_store`: esta PR lo refleja, no lo introduce ni lo agrava. Ruido para el operador, nunca pérdida.
- **Proyección eventual.** Entre el commit y el INSERT de auditoría hay una ventana en la que `event_store` ya afirma el bloqueo y `audit_log` todavía no. Es la contrapartida deliberada de que el lock no pueda caerse por su propia auditoría; **el hecho autoritativo vive en `event_store`, `audit_log` es proyección detectivesca, y el correo es proyección diferida sin capacidad de recuperación.**
- **Carrera entrega/persistencia — el aviso es *at-least-once*, no *exactly-once*.** Se envía y **después** se sella. Si SMTP acepta el mensaje y el `UPDATE` posterior falla (o el worker muere en medio), el sello sigue nulo y un tick posterior vuelve a enviar. Es intencionado: el diseño prefiere un aviso duplicado a suprimir uno cuya entrega no consta. **No se arregla con un outbox** — sería reabrir justo la superficie persistente que el mensaje sin payload existe para evitar. La garantía real, escrita así en el PR: *«no se suprime un envío que no haya sido confirmado como exitoso»*.
- **Exclusión de ticks concurrentes — hecho medido, no supuesto.** El tick es serial: `scheduler_worker` está a `deploy: replicas: 1` (`compose.prod.yaml:219-220`), es el único consumidor de `scheduler_identity_maintenance` (`:225`; `messenger_worker` en `:162` consume solo `async`), en dev hay un único `messenger_worker` sin réplicas (`compose.yaml:129`), y `messenger:consume` procesa serialmente dentro del proceso. **La propiedad descansa en el pin**, que `compose.prod.yaml:173` declara obligatorio — *"MUST stay at replicas: 1; scaling it reintroduces the duplicate-tick problem"*. Escalar ese servicio la rompe; el `UPDATE` condicional del sello no se implementa hoy porque reservar-antes-de-enviar reintroduce el silencio de 24 h ante un fallo SMTP.
- **El sello no se limpia al desbloquear.** `clearLockout()` no toca `lockout_notified_at`: la supresión es puramente temporal. Un bloqueo nuevo dentro de las 24 h siguientes queda silenciado — que es el objetivo, porque es el mismo ataque; limpiarlo al recuperar devolvería el vector de bombardeo por la puerta de atrás (atacante + víctima recuperando en bucle).
- El `user_agent` está a salvo de UTF-8 inválido **por accidente**: `resolveUserAgent()` aplica `mb_substr` incondicionalmente y eso sustituye los bytes malos (medido: `41fffe42` → `413f3f42`). El docblock justifica el truncado por *longitud*; cambiarlo a `substr()` reabriría el agujero sin poner nada rojo.
- El canal de timing queda cerrado **por construcción** (nada del envío ocurre en la petición), no por la propiedad de `kernel.terminate` que medí.

## Hechos medidos — no re-derivar

Cada uno costó una sonda; ninguno vive en el código.

| Hecho | Medición |
|---|---|
| `kernel.terminate` sí saca el trabajo del cable bajo FrankenPHP | control con `sleep(2)` en `kernel.response` = **2.186 s**; el mismo en `terminate` = **0.108 s**, con marcador probando `enter`/`exit` a 2.000106 s. Sin el control, ese 0.108 s era indistinguible de «el listener no se registró». *No se usa* (ganó el barrido), pero descarta la duda si alguien la reabre |
| El vector de `User-Agent` con UTF-8 inválido está **cerrado por accidente** | los bytes sí llegan a PHP (`41fffe42`, `valid_utf8=NO`), pero `SealedAuditEntryFactory::resolveUserAgent()` aplica `mb_substr` **incondicionalmente** y eso los sustituye (`413f3f42`). El docblock justifica el truncado por *longitud*: cambiarlo a `substr()` reabre el agujero **sin poner nada rojo** |
| `identity_user` no tiene índice sobre las columnas del barrido | solo PK + único de `email`. La consulta es scan secuencial; deliberado |
| La exclusión de ticks concurrentes es real | `compose.prod.yaml:173,219-220,225` + `compose.yaml:129` + consumo serial de `messenger:consume`. **Descansa en el pin `replicas: 1`** |
| `InMemoryUserRepository::findById()` ignora su argumento | devuelve siempre `$this->preset`. Todo test de barrido multi-fila contra él sería vacuo |
| La BD de este worktree estaba **sin provisionar** | `identity_user` tenía 0 filas; una sonda mía asertó sobre el vacío antes de detectarlo. `make db.load.fixtures` → 13 identidades. **Afirmar siempre que la siembra tocó N filas antes de asertar** |

**Trampa del entorno:** este stack segfaultea el worker de FrankenPHP (`Restarting (139)`, `zend_mm_heap corrupted`) tras bastante churn de ficheros. Se recupera con `docker compose … restart php`, y el gate se esquiva mientras tanto con `make php.stan PHP_SERVICE=messenger_worker`.

## Decisiones abiertas

1. **`${VAR:?}` en `compose.prod.yaml`** para `MAILER_SECURITY_FROM` / `MAILER_FROM` / `MAILER_DSN` (fail-fast al arrancar) frente a un valor por defecto. Es el *Ask First* del artefacto y **sigue sin resolver**. Contexto: `MAILER_SECURITY_FROM` no está en ningún compose, así que prod cae a `seguridad@erpify.local` (dominio inexistente) y **pasa la guarda en silencio**; y `MAILER_DSN` por defecto es `null://null`, que descarta sin error. Un aviso de lockout es **no solicitado**, así que nadie nota su no-entrega.
2. **`array_filter($ids, is_string(...))` descarta una fila corrupta en silencio**, postura contraria a la que fijó `935e86dc` («fail closed on corrupt identity rows»). Hoy es consistente con su hermano `DoctrineLiveIdentityDirectory`; cambiarlo en uno solo sería incoherente. O ambos, o ninguno, o se anota como residuo.

## Verification

**Commands:**
- `make php.stan` -- exit 0 en cada fichero tocado
- `make php.unit` -- verde, incluidos los tests nuevos
- `make php.behat c='features/backoffice/identity/login.feature'` -- leer la **línea de resumen**, no solo el exit code (un step indefinido sale 0)
- `make php.behat c='features/backoffice/identity/session.feature'` -- el pin de #605 sigue verde
- `make php.quality` y `make php.quality.dry-run` -- exit 0
- `make db.diff` && `make db.migrate` -- migración de `lockout_notified_at`, con `down()` reversible
- `make php.lint.person-reference`, `php.lint.persistent-transport`, `php.lint.audit-resource`, `php.lint.step-vocabulary`, `php.deptrac` -- exit 0

**Manual checks:**
- Provocar el rojo de cada regla nueva antes de darla por buena, y **recontar al final** (el conteo se pudre). De los tres ceros que midió la revisión de falsabilidad, la columna cierra el de la supresión y el funcional cierra el del barrido; **queda uno vivo**: plegar el tick en el schedule existente no añade transporte, así que `php.lint.schedule-consumption` no lo ve. Decirlo en el PR en vez de dejar que parezca cubierto.
- Leer el correo en Mailpit y confirmar que no lleva enlace, token ni selector.
