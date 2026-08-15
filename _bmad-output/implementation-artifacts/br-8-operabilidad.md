---
baseline_commit: a4af085fe685720663e3264b4369305b0dcf8d9f
---

# Story BR-8: Operabilidad — lo que se nota en producción y no en los tests

Status: ready-for-dev

> Épica: [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) · Lote BR-8 · Issues #255 #256 #261 #525 #526 #612
> Rama: `chore/operability-hardening-3ebd` · Worktree: `.claude/worktrees/operability-hardening-3ebd` · Base: `main` @ `a4af085f`
> **Último lote de la épica.** Al cerrarlo toca el criterio de cierre: barrer el backlog otra vez contra `main`.
> **Cuatro de los seis issues contienen una decisión que su propio texto declara no-automatizable.** Este documento las plantea; NO las resuelve.
> Las mediciones de abajo pasaron un **pase adversarial** que refutó tres y corrigió ocho — ver *Pase adversarial*.

---

## Lo que la medición estableció

El lote entró descrito como «backup, retención del transporte `failed`, alarmas de escritura de auditoría, y
la cota de socket del mailer». Medido contra `a4af085f`, y corregido tras el pase adversarial:

**Dos issues describen una superficie que el repositorio tuvo y perdió, y uno describe como latente un defecto
cuya precondición se cayó — la cayó un commit de esta misma épica.** Ninguno de los seis está fabricado: los
dos primeros envejecieron.

### Grupo A · El backup (#255, #256)

| # | Medición contra `a4af085f` |
|---|---|
| **M1** | **Hoy no hay par.** `backup-prod.sh` produce **un solo artefacto**: dump (`:79`), verificación (`:82`), retención (`:87-90`), `ls -lh "$db_file"` (`:93`), sync opcional (`:97-101`). `git grep -n 'objects-'` sobre todo el árbol devuelve sólo `backup-prod.sh:90` y `restore-prod.sh:92,93` — ningún productor. **Pero el par existió**: lo construyó `b0dce6ef` (#253) y lo borró `08f8199b` (#557). #255/#256 se abrieron el 2026-06-13, cuando el par era real. |
| **M2** | **P9 (pinear el `alpine`) apuntaba a código real, desde entonces borrado.** `git show b0dce6ef:scripts/deploy/backup-prod.sh` `:97-101` lleva el `docker run … alpine sh -c 'umask 077; tar czf …'` sin tag que el issue describe. No es un apartado sobre código inexistente: es un apartado que #557 dejó sin objeto. |
| **M3** | **El patrón `objects-*.tar.gz` de la retención (`:90`) no es código muerto: es un barredor de legado.** Cualquier despliegue que corriera `make backup.prod` entre #253 (2026-06-14) y #557 tiene esos archivos en `$BACKUP_DIR`, y `:90` es **lo único que los expira**. Borrarlo los deja huérfanos para siempre. Por separado: la premisa de P3 («dos pasadas independientes de `find -mtime`») **nunca describió el código enviado** — en `b0dce6ef:109-113` la retención era *pair-driven* (un `find` de `db-*` y un `rm` del `objects-` correspondiente), y hoy `:90` es **un** `find` con alternancia `-o`. |
| **M4** | **`:93` es `ls -lh "$db_file"`, pero no es evidencia de que la petición de #255 se haya cumplido.** El mismo `ls -lh` ya estaba en `b0dce6ef:117` (sobre **ambos** artefactos), y #255 se abrió el 2026-06-13, **revisando ese PR**. La petición se hizo con el `ls -lh` delante. Además, `:93` es `ls` crudo a stdout, no pasa por los helpers `log_*` del script, así que en un log de cron no se distingue. Si eso cuenta como «observabilidad» es un juicio, no una medición. |
| **M5** | **La dependencia que #255/#256 declaran es CORRECTA; lo único que derivó es el nombre.** El volumen existió como **`storage_data`** (`85f55b72:compose.prod.yaml:232`, montado en php `:53` y messenger_worker `:155`), y `b0dce6ef:scripts/deploy/backup-prod.sh:37` lo resolvía como `STORAGE_VOLUME="${COMPOSE_PROJECT_NAME}_storage_data"`. **#252 es exactamente el PR que lo desplegó** — su mensaje de commit dice *«fix(deploy): persist prod object storage on a named volume»* y *«pair `object_storage_data` with `database_data` in backup/restore»*; de ahí nace la deriva de nombre. La **guarda de volumen-existe también era real**: vivía en `scripts/deploy/lib/common.sh::require_running_stack()` (`b0dce6ef`, `:36-40`), que `backup-prod.sh:36` invoca, y la borró #557. |
| **M6** | **Cuatro de los ocho pasos del checklist de #256 están rotos, no tres.** La superficie de objetos salió en `08f8199b` (#557): no hay `api/src/Shared/Storage` ni `StoredObject` en `api/src`. Paso 2 (sembrar un `stored_object`) es inejecutable entero; pasos 3 y 6 lo son a medias; y el **paso 5** —«watch the up-front verification pass (PGDMP + `pg_restore -l` + `tar -tzf`)»— nombra **dos** comprobaciones que ya no existen: `verify_objects` lo borró #557, y el `verify_dump` de hoy (`lib/common.sh:45-51`) usa `pg_restore -f /dev/null`, **no** `-l`, con un comentario que explica por qué `-l` no sirve. |
| **M7** | **El código ya reconoce el hueco.** `restore-prod.sh:89-92` avisa: *«`$STAMP` carries `objects-$STAMP.tar.gz`, an object archive this restore does not unpack»* (`:93`) *«The recovery point will be database-only. Abort now if that archive still matters.»* (`:94`). Lo añadió `08f8199b` — **el mismo commit que borró el productor**. |

### Grupo B · El mailer (#612)

| # | Medición contra `a4af085f` |
|---|---|
| **M8** | **La premisa de latencia del issue se cayó, y el propio issue definió el disparador.** #612 midió sobre `f4dbe4d1` (2026-07-30), donde `compose.prod.yaml` tenía `${MAILER_DSN:-null://null}` en sus **dos** entradas de entonces (`:139` messenger_worker, `:202` scheduler_worker; `php` no tenía ninguna). `0fc48fff` (2026-08-11, BR-4b/#602/#683) **cambió esas dos y añadió una tercera en `php`**; hoy son `:46`, `:148`, `:228`, todas `${MAILER_DSN:?…}`. Ningún servicio de `compose.prod.yaml` lleva `profiles:`, así que el `:?` no está condicionado, y `make/deploy.mk:13,26` rechaza además la clave sin valor. **Prod no arranca sin DSN.** Matiz que hay que decir: `:?` exige la variable *puesta*, no *remota* — lo que prohíbe `null://null` en prod es `Shared/Mailer/Infrastructure/DeliverableSecurityTransport.php:56`, y **sólo cubre los mailers de seguridad**, no `PlainTextNotificationMailer`. Y el issue **sí** conocía el DSN de dev (`smtp://mailpit:1025`): lo descartó por instantáneo, no por ausente. |
| **M9** | **El conteo de «both call sites» del issue ya era falso el día que se escribió, y hoy lo es por más.** Seis casos de uso inyectan un envoltorio de correo bloqueante: `Invitation/Application/{SendInvitation.php:54,ResendInvitation.php:31}`, `Identity/Application/{ChangeMyPassword.php:72,NotifyLockedIdentities.php:39,RequestPasswordReset.php:42,CompletePasswordReset.php:61}`. `SendInvitation`/`ResendInvitation` **ya existían en `f4dbe4d1`**. Y hay un séptimo remitente fuera de los envoltorios de Iam: `Backoffice/Bank/Infrastructure/Messenger/SendEmailOnBankChanged.php:24`, que corre en el **`messenger_worker`** del transporte `async`. |
| **M9b** | **Son dos superficies de worker expuestas, no una.** El tick de cinco minutos `NotifyLockedIdentitiesMessage` (`IdentityMaintenanceSchedule`, añadido por `0fc48fff`) manda correo a una persona desde el `scheduler_worker` de **una réplica** — `compose.prod.yaml:227` lo dice: *«this is the worker that sends the lockout notice»*. Y `SendEmailOnBankChanged` cuelga el pool `async`. Además `SmtpTransport` pone `started = false` al fallar (`:219`, `:297`), así que el siguiente `send()` reintenta `start()`: el coste es **60 s por envío**, no 60 s una vez. |
| **M15** | **Enumerar llamantes es el marco equivocado: el timeout vive en el transporte.** `EsmtpTransportFactory::create()` lee `auto_tls`, `require_tls`, `source_ip`, `verify_peer`, `peer_fingerprint`, `local_domain`, `max_per_second`, `restart_threshold`, `ping_threshold` — **no `timeout`**. `SocketStream.php:46` cae a `ini_get('default_socket_timeout')`, y ningún `.ini` de `api/frankenphp/conf.d/` lo fija → **60 s**. **Decorar es alcanzable y suficiente**, verificado hasta el contenedor compilado: la factory se registra como `mailer.transport_factory.smtp` con su tag (`mailer_transports.php:74,79-82`), `FrameworkExtension` sólo poda factories de *bridge*, y `DecoratorServicePass:109-110` traslada el tag al decorador. `SmtpTransport::getStream()` es público y `SocketStream::setTimeout()` también; el socket no se abre hasta `initialize()` (`:135`), y en `:152-163` el mismo valor alimenta el timeout de conexión **y** el de lectura. Nada en `api/src` bypasea el transporte (cero `Transport::fromDsn`, `new EsmtpTransport`, `mail()` o `TransportInterface`). **Cuatro clases inyectan `MailerInterface`**: `Shared/Mailer/Infrastructure/{SecurityLinkMailer.php:33,PlainTextNotificationMailer.php:21}` e `Iam/Identity/Infrastructure/Mail/Symfony{PasswordChanged:38,AccountLocked:41}EmailSender.php`; los `Symfony{Invitation,PasswordReset}EmailSender` **delegan** en `SecurityLinkMailer`, no son seams. |
| **M15b** | **El decorador NO cubre a todos los remitentes incondicionalmente, y esto es lo que hay que falsificar.** Cubre **sólo `smtp`/`smtps`**: un DSN de bridge (`sendgrid+api://`, `ses+https://`), `sendmail://` o `native://` lo construye otra factory sin `SocketStream`, la cota **no aplica y nada se pone rojo**. Y `getStream()` está tipado `AbstractStream` mientras `setTimeout()` es de `SocketStream`, así que el decorador necesita un `instanceof` — **un camino que puede no-opear en silencio**. |

### Grupo C · Scheduler, `failed` y Sentry (#261, #525, #526)

| # | Medición contra `a4af085f` |
|---|---|
| **M10** | **#261 enumera UN tick; hoy hay OCHO, en tres clases `#[AsSchedule]`** — `maintenance` (PruneHandledDomainEvents 1d, **ReportDeadLetterBacklog 1h**), `audit_maintenance` (PruneAuditLog 1d, ReconcileSubjectErasures 1d) e `identity_maintenance` (ReconcilePersonReferences 1d, NotifyLockedIdentities 5min, InspectStoredIdentity 1d, **PruneRetiredSessions 1d**). **El comentario de `compose.prod.yaml:174-178` lista sólo seis y es prosa drifted**: nunca listó el `ReportDeadLetterBacklogMessage` (de #363, siete semanas anterior), y `PruneRetiredSessionsMessage` lo añadió **`a4af085f` — el commit base de esta historia — sin tocar compose**. Lo que sí es exacto es su argumento (`:180-186`): las tres schedules son `->stateful()` y **ninguna** `->lock()` (cero `->lock(` en `api/src`), así que *«this replica pin is the only thing standing between one notice and N»* está sostenido por el código. |
| **M11** | **`symfony/lock` no está instalado**, verificado en tres direcciones: ausente de `require` y `require-dev` de `api/composer.json`, sin `api/vendor/symfony/lock` (comprobado contra el checkout con vendor instalado), y sin entrada `"name": "symfony/lock"` en `composer.lock`. `rate_limiter.yaml:17-24` declara el trade-off, con `lock_factory: null` en los **cinco** budgets (`:45,57,88,99,132`). Dato para DEC-3: una de las menciones de `composer.lock` no es una dependencia opcional sino un **`conflict`** — `symfony/messenger` exige `symfony/lock` `<7.4`, o sea **≥7.4 es el suelo** de un futuro `composer require`. |
| **M12** | **RESUELTA por T3: ya existe un pruner de `failed`.** Cuando se midió no existía ninguno. Atacado por seis vías: los únicos `DELETE FROM` de `api/src` son sobre `bank_count`, `audit_log` y `handled_domain_event` (×2); `messenger_messages` sólo aparece en migraciones como `CREATE`/`DROP`; no hay `messenger:failed:remove` fuera de prosa; ningún target de `make/`, script o compose poda el transporte; los únicos cron del repo son los workflows de CI y el dump de Postgres. `PRODUCTION_SECURITY_CHECKLIST.md:687` lo dice también. **Tres cosas lo leen, todas de sólo lectura**: `MessengerDeadLetterReader` (el único ligado a `@messenger.transport.failed`, `services.yaml:46`, tipado al `ReceiverInterface` de lectura por diseño), y sus dos consumidores — `FailedMessagesStatusCommand` (a demanda) y `ReportDeadLetterBacklogHandler` (**horario**). |
| **M13** | **El warning existe tal cual** — `SymfonyAuditLogger.php:83-91`, `catch (Throwable)` → `warning('Failed to record an activity audit entry.', ['action','level','exception'])` — y está **pinchado por un test**: `SymfonyAuditLoggerTest.php:90-93` hace `assertSame` sobre el array entero, así que una clave añadida lo pone rojo. **Residuo acotado que T4 debe respetar**: «sin PII» vale para las *claves*, no para el mensaje del `Throwable`, que esta clase no controla. El lanzador realista es `DbalAuditLogWriter::write()`, cuyo insert liga `actor_id`, `resource_id`, `metadata`, `ip` y `user_agent`; DBAL 4 ya no imprime los parámetros ligados (`DriverException.php:27`, sin la cláusula `with params [...]` de DBAL 3), así que las formas comunes son limpias — pero la garantía descansa en un formato de vendor, no en una aserción nuestra. |
| **M14** | **La opción 1 de #526 («regla de alerta en Sentry, cero código») no es cara: es IMPOSIBLE tal cual, y está decidido por escrito.** `sentry/sentry-symfony ^5.11.0` está instalado y `SentryBundle` registrado en dev+prod (`bundles.php:16`), pero **ningún registro de Monolog llega a Sentry, de ningún nivel**: el bundle no registra handler de Monolog (cero `Handler` en sus `Resources/config/`), el repo no cablea `LogsHandler` ni `enable_logs`, y las únicas vías vivas del bundle son `register_error_listener` (kernel.exception) y `messenger.enabled` — **un `Throwable` tragado en `SymfonyAuditLogger.php:85` no es ninguna de las dos**. Es una decisión registrada dos veces: `sentry.yaml:14-17` (*«We deliberately do NOT wire the Monolog Sentry handler … avoids double-reporting»*) y `ReportDeadLetterBacklogHandler.php:16-18` (*«A log line (not a Sentry capture) is the signal on purpose»*). El comentario de `monolog.yaml:90` está rancio **sólo en su motivo** («uncomment when sentry/sentry-symfony is installed»); que el bloque esté comentado es deliberado. |

---

## Lo que esto le hace al lote

BR-8 sigue siendo «lo que se nota en producción y no en los tests», pero la medición lo parte en tres grupos
de naturaleza distinta, y **sólo uno es trabajo de escribir código ahora**:

1. **Dos issues que envejecieron con una retirada de superficie** — #255 y #256. No estaban mal: describían el
   backup emparejado que #253 construyó y #557 desmontó. Lo que toca no es corregirlos por falsos, sino
   decidir si la superficie vuelve o si se cierran con evidencia — **y resolver antes qué pasa con los
   archivos de legado que `:90` todavía expira** (M3).
2. **Un defecto que dejó de ser latente** — #612, ascendido por M8 y ensanchado por M9/M9b. Es lo único del
   lote con consecuencia de disponibilidad **hoy**, y llegó ahí por un cambio de esta misma épica.
3. **Tres decisiones abiertas** — #261, #525, #526, y una de ellas (#526) la medición ya la resolvió a medias.

---

## Decisiones que Sergio debe tomar

**Resueltas** (2026-08-15): **DEC-2** → opción 1, el decorador de transporte (T1, hecho). **DEC-4** → 30 días
con poda automática. **DEC-5** → umbral **1** y **sin contador**: `warning` → `error`, nada más. **DEC-1** →
#255 se cierra con evidencia, se retira de la retención el patrón `objects-*.tar.gz` y **el aviso de
`restore-prod.sh:89-92` se conserva**; **#256 se queda abierto** como tarea de ejecución, no de código.
**DEC-3** → **sigue abierta**: #261 no entra en el lote. Las dos últimas en resolverse —la sub-decisión de
DEC-1 y el mecanismo de DEC-5— se detallan bajo la tabla, porque en ambas la resolución **corrige una
medición** de este mismo documento.

**Consecuencia para el cierre de la épica:** con #256 y #261 abiertos, este PR **no cierra BR-8**, y el
criterio de cierre de la épica (barrer el backlog otra vez contra `main`) no se dispara todavía.

| # | Decisión | Lo que la medición aporta |
|---|---|---|
| **DEC-1** · #255/#256 | **Resuelta** — se retira el patrón de la retención, **el aviso de restore se conserva**, #256 sigue abierto. Detalle y corrección de M3: *Sub-decisión de DEC-1*, bajo esta tabla. | La superficie se retiró deliberadamente (#557) y `restore-prod.sh:89-92` ya lleva el aviso. **Sub-decisión obligatoria**: `backup-prod.sh:90` es el único expirador de los `objects-*.tar.gz` de despliegues reales entre #253 y #557 (M3); borrarlo sin más los inmortaliza. |
| **DEC-2** · #612 | Decorador de transporte (opción 1 del issue) vs `default_socket_timeout` global (opción 2). | El decorador está verificado alcanzable y suficiente **para `smtp`/`smtps`** (M15), y **sólo** para eso (M15b) — la opción 2 no tiene ese punto ciego, a cambio de un radio global. El radio real son seis casos de uso, cuatro seams y **dos** superficies de worker (M9, M9b). |
| **DEC-3** · #261 | ¿`wontfix` (seguir en Option A) o instalar `symfony/lock`? | `compose.prod.yaml:180-186` ya escribió el argumento a favor de A **después** de abrirse el issue, y el código lo sostiene (ninguna schedule `->lock()`). Son ocho ticks, no uno (M10). Suelo técnico: `symfony/lock >= 7.4` por el `conflict` de messenger (M11). |
| **DEC-4** · #525 | **Resuelta: 30 días con poda automática, y la ventana ES el SLA de triage** — la tercera pregunta del issue, que ninguna otra cosa del repo respondía. Detalle: *T3 — resultado medido*. | `failed` es durable **a propósito**; podarlo cambia el contrato. Es la única tabla hermana sin retención (M12), y el pruner tendría que convivir con una alarma **horaria**. |
| **DEC-5** · #526 | ~~Regla en Sentry vs chequeo in-app.~~ ~~Queda el **umbral** y de dónde sale el contador.~~ **Resuelta: umbral 1, sin contador** — y la pregunta estaba mal planteada, porque en prod la línea a la que apunta no existe. Detalle: *Mecanismo de DEC-5*, bajo esta tabla. | `ReportDeadLetterBacklogHandler` es el precedente enviado de esta forma exacta, elegido por este mismo motivo. |

### Sub-decisión de DEC-1 · los `objects-*.tar.gz` de legado

**Se retira `-o -name 'objects-*.tar.gz'` de `backup-prod.sh:90`. El aviso de `restore-prod.sh:89-92` se
conserva**, reformulando sólo su segunda línea y **sin** describir el archivo como obsoleto o retirado: un
snapshot antiguo puede contenerlo legítimamente, y ésa es exactamente la condición que detecta.

**M3 queda corregida en su premisa.** «`:90` es lo único que los expira, borrarlo los inmortaliza» databa los
archivos por la fecha de *merge* de #557, no por la de **despliegue**. El productor no es el commit en `main`:
es el script que corre en el VPS, así que mientras prod ejecute un checkout anterior, `objects-*.tar.gz`
**se sigue produciendo**. La vida útil restante del patrón es *(fecha de despliegue de #557) + `RETENTION_DAYS`
+ una ejecución de backup* — una fecha que este repositorio no conoce.

**Y la lectura opuesta también estaba incompleta.** Que el aviso sea inalcanzable porque `list_stamps()`
(`restore-prod.sh:44-47`) enumera sólo `db-*.dump` es cierto **para el ciclo local**, donde dump y archivo
comparten un `find`, una alternancia y un mtime, y mueren juntos. No es cierto globalmente: `[[ -f ]]` mira el
directorio, no el historial. Un restore desde un offsite basado en snapshots —`restic`/`borg`, que el runbook
prescribe como alternativa a un remoto `rclone crypt`— devuelve **ambos** ficheros a `$BACKUP_DIR`, el stamp
reaparece por su dump, y el aviso puede y debe dispararse.

El principio que separa las dos mitades: **retención y validación de restore tienen ciclos de vida distintos.**
La primera es autoridad destructiva sobre una forma de nombre que ninguna versión viva del código produce, y su
fallo es irreversible; el segundo es una lectura defensiva que cuesta cero cuando no aplica e impide un punto
de recuperación silenciosamente incompleto cuando aplica. Compartir un patrón de nombre no las hace una sola
cosa.

**Queda una tarea de operación, no de diseño**, y no bloquea el código: si `ls $BACKUP_DIR/objects-*.tar.gz`
devuelve algo en el host, un barrido único documentado en el cierre de #255. Los tres datos que sólo la máquina
tiene son ése, el `RETENTION_DAYS` real, y si el offsite es un `sync` (propaga borrados) o snapshots (no los
propaga hasta un `forget`/`prune` explícito).

### Mecanismo de DEC-5 · umbral 1, y ningún contador

**`warning(` → `error(` en `SymfonyAuditLogger.php:86`. Nada más: ni contador, ni tabla, ni `#[AsSchedule]`,
ni transporte.**

**El hallazgo que la decide no estaba en ninguna de las dos opciones del issue: en producción esa línea
`warning` no existe.** `monolog.yaml` `when@prod` declara `main` como `fingers_crossed` con
`action_level: error` y `buffer_size: 50` sobre un `nested` a nivel `debug`; la escritura de `activity` corre
en `kernel.terminate` de una respuesta **con éxito**, así que nada eleva el buffer y la línea se descarta. Lo
dice el comentario del propio fichero (`:8-9`): *«those buffer until a 5xx and discard everything otherwise»*.
Hoy el fallo es silencioso al 100 % en todos los modos que **no** son caída de BD — que son precisamente
aquellos para los que se quería la alarma. Subir el nivel no es la alternativa barata al contador: es lo mínimo
para que exista señal alguna.

**Descartado el contador en pool de caché: no puede funcionar aquí.** El pool `app` es el adaptador de
filesystem (los demás backends están comentados; cero Redis en los tres compose) y **ningún servicio monta
volumen sobre `var/cache`** — en dev, `compose.dev.yaml:180` da al worker un `messenger_cache` privado que lo
sombrea a propósito. El `catch` corre en `php` y cualquier tick correría en `scheduler_worker`: el contador
leería **cero para siempre, con todos los gates en verde**. Es la clase de fallo que este lote existe para
denunciar.

**Descartado el contador en Postgres**, que sí sobrevive a lo anterior, por dos razones. Comparte dominio de
fallo con lo que cuenta. Y, sobre todo: **con umbral 1 no hay nada que agregar**. Una tabla, un puerto, un
adaptador, un mensaje, un handler y un tick compran agregación para un requisito que la declara innecesaria, y
su salida sigue siendo una línea a stderr sin alerting — igual que la de una sola palabra. La maquinaria cara
no compra alarma, compra agregación.

**El umbral es 1 y no es configurable.** Una fila de auditoría perdida es un defecto de integridad del rastro,
no una métrica con tolerancia; el recuento, si alguna vez hace falta, viaja como *payload* de la línea y no
como *puerta*. Esto rompe deliberadamente con el precedente `ReportDeadLetterBacklogHandler`, que sí lleva sus
umbrales en el mensaje: allí el backlog tolerable es un juicio operativo, aquí el número tolerable es cero. Y
bajo `fingers_crossed` un umbral de N no de-ruida las N−1 primeras: **las silencia**, porque hoy ninguna es
visible.

**El coste que se aceptó aquí resultó impagable, y la revisión de código lo midió.** Subir el nivel abre el
buffer, y abrir el buffer no emite sólo lo nuestro: `handleBatch` vuelca **todos** los registros que la
petición había acumulado, incluido el `debug` de `ContextListener` con `['username' => getUserIdentifier()]`,
que en esta aplicación **es la dirección de correo de la persona**. Reportar una fila de auditoría perdida
volcando un email a un sumidero sin rotación, sin TTL y sin dueño declarado de su borrado cambia un defecto de
integridad por otro peor; `RedactionDenylist` no puede intervenir, porque filtra por clave y la clave es de un
registro de vendor. Verificado en los cuatro eslabones (`ContextListener:241,248`, `SecurityUser:33`, firewall
`main` sin `stateless`).

**La palanca que esta misma sección nombraba como plan B es la solución.** El canal `observability` es el
stream always-on que el repo construyó para exactamente esta forma —una línea que debe llegar en una respuesta
con éxito— y `monolog.yaml` lo excluye del `fingers_crossed` por su nombre. La línea llega a producción **sin
abrir buffer**: una línea, sin flush, sin amplificación durante la caída que reporta. `error` se mantiene
porque una fila perdida es un defecto de integridad, pero en este canal el nivel ya no decide si la línea
sobrevive — y esa es la propiedad que interesa, porque el nivel es un token que cualquiera mueve y el canal
está en configuración que un gate puede leer.

---

## Alcance

- [x] **T1 — #612: acotar el socket del mailer** (API) — DEC-2 resuelta por Sergio a favor de la opción 1
  - [x] **El arreglo va en el transporte, no en los llamantes** (M15):
        `Shared/Mailer/Infrastructure/TimeBoundedSmtpTransportFactory` decora `mailer.transport_factory.smtp`
        vía `#[AsDecorator]` y llama a `SocketStream::setTimeout()`. Cableado verificado contra el contenedor
        vivo, no deducido: `debug:container` reporta `container.decorator (id: mailer.transport_factory.smtp,
        inner: …\TimeBoundedSmtpTransportFactory.inner)` y el id original quedó como alias privado del decorador.
  - [x] **Un solo valor cubre las dos formas de colgarse *una operación*:** `SocketStream::initialize()` pasa
        el mismo `$timeout` a `stream_socket_client()` (no acepta) **y** a `stream_set_timeout()` (acepta y
        calla), que `AbstractStream::readLine():84` convierte en `TransportException`; también acota el
        handshake de STARTTLS. La segunda es la forma que toma una caída real y la que un timeout de conexión
        solo no vería.
  - [x] **No cubre el envío, y esa distinción es el hallazgo GRAVE de T7** — ver *Pase adversarial (código)*.
        SMTP es una conversación: el coste es *idas y vueltas × la cota*. Por eso el default es **3 s** y no
        10, y por eso la garantía está reformulada en los cuatro sitios donde se afirmaba de más.
  - [x] **Rango aceptado 1–300 s, refutado en los dos extremos.** Todo lo que queda fuera **es un número** —
        ninguna comprobación de tipo lo caza — y cada uno falla peor que no hacer nada. El default se rechaza
        en el constructor; la opción del DSN cae al default.
  - [x] **`make prod.env.check` valida la clave antes de arrancar.** La negativa de la app es ruidosa pero
        **tardía**: los placeholders de env se resuelven en runtime y compilar el contenedor no instancia
        servicios, así que `lint:container` y `make php.lint.prod-container` salen **0** sobre un valor
        rechazado. Esta es la mitad que ocurre en el despliegue.
  - [x] **Test funcional que arranca el kernel.** `MAILER_SMTP_TIMEOUT` aparece en `api/src` **una sola vez**,
        dentro del string del `#[Autowire]`; el unitario inyecta un float y nunca construye contenedor. Con el
        nombre mal escrito, PHPUnit/PHPStan/deptrac/`php.lint.prod-container` seguían **verdes**.
  - [x] **Declarar el punto ciego en vez de esconderlo** (M15b): declarado en el docblock de la clase — la cota
        vale para `smtp://`/`smtps://`; un bridge de API o `sendmail://` construye otra factory sin socket
        nuestro que acotar.
  - [x] **La opción `?timeout=` del DSN se honra por encima del default configurado.** El issue la nombra como
        no-op silencioso; leerla cierra la trampa en vez de dejarla al lado del knob nuevo.
  - [x] Radio cubierto sin enumerar llamantes: la petición, el `scheduler_worker` de una réplica y el
        `messenger_worker` del `async` resuelven todos por esa factory.
  - [x] **`MAILER_SMTP_TIMEOUT` reenviado por compose a los TRES servicios PHP que envían correo**
        (`compose.yaml` php + messenger_worker, `compose.prod.yaml` scheduler_worker, que no hereda de ninguna
        base). Sin ese reenvío, ponerlo en `.env.prod` habría sido **otro no-op silencioso** — el mismo
        defecto que este issue ataca. Probado moviendo el valor: `MAILER_SMTP_TIMEOUT=7 make docker.up` → el
        transporte real sale a 7 s. El overlay de prod **fusiona** el `environment:` base, verificado con
        `docker compose config` (`MAILER_FROM`, declarado sólo en la base, llega al `php` de prod).
  - [x] Actualizar #612 con M8/M9/M9b/M15 y con la garantía reformulada.

### T1 — resultado medido

`api/.env` = **3 s** por defecto (`api/.env.example`, `.env.prod.example`, `compose.yaml` ×2 y
`compose.prod.yaml` alineados). Contra el transporte que construye la app de verdad: **3 s** con el default,
**3 s** honrando `?timeout=3`, con `default_socket_timeout = 60`.

**El número que decidió el default.** Un relé **conforme** —responde correctamente a cada comando, sólo que a
los 9 s— retuvo un envío:

| Escenario | Cota | Total |
|---|---|---|
| 6 idas y vueltas, sin STARTTLS ni AUTH | 10 s | **54,00 s** |
| + STARTTLS + AUTH (≈4 idas más) | 10 s | ≈90 s |
| Peer que gotea un byte cada 2 s sin cerrar línea | 2,5 s | >100 s, matado |

`max_execution_time = 0` en los dos contenedores PHP: nada por encima lo tapa. Con la cota en 10, **el peor
caso era peor que los 60 s que sustituye**. Medido dos veces con instrumentos independientes (54,01 s el
lector de T7, 54,00 s el mío).

**Falsificación, una mitad cada vez** (24 tests / 57 aserciones en verde, el grupo `slow` incluido —
no está excluido en `phpunit.dist.xml`, `make/php-test.mk` ni `ci.yml`, así que corre en la puerta que CI
usa):

| Mutación | Rojos |
|---|---|
| Quitar `setTimeout()` | 8 tests, **y el del socket colgado a 60.06 s** contra la cota — el defecto, medido |
| Ignorar la opción del DSN | `testTheDsnOptionOverridesTheConfiguredDefault` |
| Quitar el suelo del rango | casos `zero`, `negative`, `0.001` (opción) y `0.0`, `-1.0`, `0.5` (default) |
| Quitar el techo del rango | casos `301`, `1e308`, `1e400` (opción) y `300.5`, `1e308` (default) |
| Quitar la guarda `is_numeric` | caso `?timeout[]=` — `(float)['99']` es `1.0`, **dentro** del rango |
| Quitar el early-return `instanceof SmtpTransport` | `testReturnsATransportWithoutASocketOfOursUntouched` |
| `supports()` deja de delegar | `testDelegatesSupportsToTheDecoratedFactory` |
| Escribir mal el nombre de env en `#[Autowire]` | los 2 del test funcional, con `EnvNotFoundException` |
| Vaciar `create()` entero | 8 tests — y **ninguno** de los unitarios de identidad antes de arreglarlos |

**Una mutación con cero rojos, declarada en vez de tapada:** quitar el `instanceof SocketStream` no rojea
**nada** de la suite, porque la factory decorada es `final` y siempre construye un `SocketStream`. No es
código muerto: `make php.stan` sale **2** con `method.notFound` sobre `AbstractStream::setTimeout()`. Su
guardián es PHPStan, y está escrito en el propio código para que nadie lo «simplifique» con la suite en verde.

**Cuatro defectos en los tests, encontrados por falsificación y corregidos.** Los dos primeros, en la primera
vuelta:

1. `is_numeric` empezó midiendo **cero rojos** y parecía código muerto. No lo era: `Dsn::fromString` parsea la
   query con `parse_str`, así que `?timeout[]=99` llega como **array**, y `(float)['99']` es `1.0` — pasaría
   la guarda de positividad y fijaría una cota de un segundo que nadie pidió. Faltaba el caso, no sobraba la
   guarda.
2. Añadido el caso, **seguía sin rojo**: la constante `BOUND` valía `1.0`, exactamente el valor al que castea
   un array no vacío, así que la aserción afirmaba su propio valor de fallo. `BOUND = 2.5` y el rojo aparece.
   Es la misma familia que la siembra vacua de G-1b/G-5: una aserción que no puede fallar.

Los dos siguientes los encontró T7, y el tercero es de la misma familia que los dos de arriba:

3. `testLeavesATransportWithoutASocketStreamUntouched` afirmaba `assertNotInstanceOf(SmtpTransport::class,…)`,
   que es un hecho sobre la factory decorada, no sobre la nuestra. **Medido: vaciando `create()` entero
   rojean 8 tests y ése se queda verde.** Ahora afirma identidad contra un doble.
4. `assertLessThan($unbounded, $elapsed)` no aportaba detección —`BOUND * 5 = 12,5 < 60`, así que toda medida
   que la incumple incumple también la siguiente— **y era la frágil que saltaba primero**: al mutar midió
   *«60.06186 is less than 60.0»*, un margen del 0,1 % que depende de que el timeout del socket se pase de
   largo y no se quede corto. Retirada; el techo se aprieta a `BOUND * 2`, porque `BOUND * 5` no cazaba una
   cota 4× lenta.
- [x] **T2 — DEC-1 · #255/#256: reconciliar el backup con la superficie que existe**
  - [x] **Retirar `-o -name 'objects-*.tar.gz'` de la retención (`:90`) y conservar el aviso de
        `restore-prod.sh:89-92`**, reformulando sólo su segunda línea y sin tacharlo de obsoleto
        (sub-decisión de DEC-1). El barrido de huérfanos locales es tarea de operación, no de código,
        y va documentado en `docs/vps-deployment.md` § Backups.
  - [x] Corregir en ambos issues la **deriva de nombre** `object_storage_data` → `storage_data` — **la
        dependencia a #252 es correcta y se mantiene** (M5). **Corregido más allá del nombre**, porque el pase
        adversarial midió que la frase que lo rodea es falsa: #256 dice que sin el volumen `make backup.prod`
        «aborts on its volume-exists guard», y esa guarda **no existe** (`lib/common.sh:25-36` no mira ningún
        volumen). Cambiar sólo el sustantivo habría dejado una frase que ya no describe el código con aspecto de
        revisada. **Matiz que costó una segunda corrección al issue**: esa guarda SÍ existió
        (`b0dce6ef:lib/common.sh:36-40`), así que la frase era cierta cuando se escribió y la retiró #557 —
        decirle a su autor que era falsa fue el error. La dependencia se conserva como **hecho histórico**,
        no como precondición viva.
  - [x] **Loguear el tamaño del artefacto** — la mitad de #255 que sobrevive a la retirada de la superficie de
        objetos, y que el `ls -lh` preexistente NO cumplía (es de `b0dce6ef`, la PR que #255 revisaba). Se
        emite como línea plana `backup_bytes=<n>`, **antes** de la retención, para que un artefacto sospechoso
        pueda juzgarse antes de que se borre nada en su virtud.
  - [x] **Denunciar los huérfanos en cada ejecución** — la mitad detectiva. Sin ella el único que sabe que
        existen es un runbook que nadie está obligado a leer, y llevan la misma PII que el dump.
  - [x] Corregir el checklist de #256 a sus pasos ejecutables; el paso 5 nombra además un `pg_restore -l` que
        el `verify_dump` de hoy no usa (M6). Reescrito el cuerpo a los pasos que corren, **con el original
        preservado verbatim en un comentario** — borrarlo habría eliminado el registro de que el drill
        emparejado existió de verdad. Añadido el único dato que sólo el host tiene: si `$BACKUP_DIR` guarda
        huérfanos, el barrido documentado aplica.
  - [x] **#255 con su cierre preparado y su evidencia publicada** (#728) — `Closes #255` dispara al mergear,
        no ahora; el comentario de disposición ya está en el issue: tabla de disposición ask-por-ask en un comentario, con los
        commits que construyeron (#253, `b0dce6ef`) y desmontaron (#557, `08f8199b`) la superficie, y con lo
        que el cierre **no** afirma — que un host vivo no tenga huérfanos no se lee desde el repositorio.
- [x] **T3 — DEC-4 · #525: retención de `failed`** (sólo tras la decisión)
  - [x] **Reutilizar, no reinventar — y elegir bien el patrón**: los dos pruners existentes **no son
        intercambiables**. `Shared/Event/Infrastructure/Messenger/DbalHandledDomainEventPruner.php` es un
        `DELETE` desnudo; el que la sección de *Falsificación* obliga a imitar es
        `Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php:142`, con `ORDER BY id … FOR UPDATE`.
  - [x] Colgarlo de `Maintenance/HandledDomainEventMaintenanceSchedule.php`; convive con
        `Maintenance/ReportDeadLetterBacklogHandler.php` (horario) y lee por `MessengerDeadLetterReader`.
  - [x] Un `#[AsSchedule]` nuevo obliga a añadir su transporte al `messenger:consume` de **`compose.yaml`
        (dev) y `compose.prod.yaml`**. Y a **actualizar el comentario de `:174-178`**, que ya va dos ticks
        por detrás (M10).
- [x] **T4 — DEC-5 · #526: hacer que la señal exista** (resuelta: umbral 1, sin contador)
  - [x] `warning(` → `error(` en `SymfonyAuditLogger.php:86`. **Nada más**: ni contador, ni tabla, ni
        `#[AsSchedule]`, ni transporte — y esa ausencia es la propiedad, no la falta de trabajo: **un cambio
        que no declara transporte no puede enviarse muerto.**
  - [x] **El `assertSame` del array de contexto (`SymfonyAuditLoggerTest.php:92`) se queda intacto** — es el
        guardián del residuo M13. Se mueve sólo el nivel (`:89`).
  - [x] Actualizar el docblock de la clase y `docs/architecture-api.md`: ambos dicen «swallowed and logged at
        warning», que deja de describir el código. **Medido: la frase de `architecture-api.md` no nombra el
        nivel** («swallowed and logged»), así que sobrevivía al cambio siendo cierta y vacía; se reescribe
        para que diga *por qué* el nivel es `error`. Los que sí lo nombraban y no estaban en la lista son
        tres del ADR (`audit-activity-log.md`, D3.1) — el cuarto, `:101`, **no se toca**: cae dentro del
        bloque de D3 marcado como registro histórico en `:87`.
  - [x] **No reenviar el mensaje del `Throwable` sin acotarlo** (M13). El `Throwable` sigue viajando entero
        porque el guardián del array de contexto lo pina. Lo que **no** ocurre ya es el arrastre del buffer:
        la revisión de código midió que ese arrastre volcaba el email del usuario, y la línea se movió al
        canal `observability`, que no abre buffer. La exposición del residuo M13 queda en el mensaje del
        `Throwable` y en nada más.
  - [x] Deja de ser espejo de `ReportDeadLetterBacklogHandler`: aquel cuenta filas que **existen**, aquí el
        fallo es que la fila **no se escribió**. Ese es el motivo de que no haya contador.
### T3 — resultado medido

**La advertencia más ruidosa del traspaso no aplicaba, y comprobarlo la desactivó entera.** Decía que un
`#[AsSchedule]` nuevo obliga a cablear su transporte en los dos compose «o sale muerto». No hace falta ninguno:
el tick cuelga de la schedule `maintenance` que ya existe, cuyo `scheduler_maintenance` ya está consumido —y
en el servicio correcto de cada fichero, `messenger_worker` en dev y `scheduler_worker` en prod—. El gate de
consumo sigue verde por construcción, no por vigilancia. Lo que sí estaba rancio es el comentario de
`compose.prod.yaml:174-178`: no listaba `ReportDeadLetterBacklogMessage`, que lleva ahí desde antes.

**El detalle que ningún test sobre `failed` puede ver.** `async` y `failed` son **una sola tabla**
(`messenger_messages`) distinguidas por `queue_name` — los dos DSN son `doctrine://default?queue_name=…` y sólo
una migración crea tabla de Messenger. Un `DELETE` sin ese predicado no poda una carta muerta antes de tiempo:
**borra trabajo en vuelo**, y nada aguas abajo denuncia la ausencia. De ahí que el predicado tenga dos
guardianes — una fila `async` de la misma edad que debe sobrevivir, y un gate que compara la constante contra
`messenger.yaml`, porque renombrar la cola en el DSN dejaría el pruner casando cero filas para siempre, en
silencio y en la dirección segura.

**Falsificación: nueve mutaciones, nueve rojos** (tabla abajo). La primera vuelta tuvo **una con cero rojos**
—ensanchar la ventana a `<=`— porque ninguna fila sembrada caía *exactamente* en el corte; añadida la fila en
la frontera, roja. Misma familia que las cuatro de T1.

| Mutación | Rojos |
|---|---|
| Quitar el predicado `queue_name` | el `async` en vuelo + el gate de alcance |
| Ensanchar la ventana a `<=` | la fila exactamente en el corte |
| Quitar `FOR UPDATE` | gate de código + test de plan |
| Quitar `ORDER BY id` | gate de código + test de plan |
| Quitar la guarda de tamaño de lote | el unitario |
| Desprogramar el tick | el test de la schedule |
| Quitar `->stateful()` | el test del checkpoint |
| Bajar la retención a 1 día | el test del margen contra la alarma |
| Quitar el presupuesto de reloj del drenaje | el test del drenaje acotado |

**El plan, medido en vez de supuesto** (100.000 filas, 25 % en `failed`, `ANALYZE` previo, dentro de una
transacción revertida):

| Correlación `id`/`created_at` | Plan | Filas descartadas por filtro | Tiempo |
|---|---|---|---|
| Invertida (pesimista) | `LockRows` sobre `Index Scan` de la PK | 58.196 | 18,2 ms |
| Real (el más viejo, id más bajo) | `LockRows` sobre `Index Scan` de la PK | 15.000 | 35,2 ms |

`LockRows` queda **encima del recorrido ordenado** en los dos casos, que es la propiedad que el `FOR UPDATE`
compra. **No hace falta índice ni migración**: el recorrido es por clave primaria y la correlación real lo
acorta 4×. La premisa es la misma que el pruner de `audit_log` documenta —el `id` sigue al instante de
llegada— y un backfill la rompería igual.

**Un test que era flaky por construcción, y lo era por mi culpa.** El de plan afirmaba `LockRows` **o** un
recorrido de la PK sobre los datos que hubiera en la base. Medido: la misma mutación salió **verde** con una
población y **roja** con otra. Ahora siembra su propia población y hace `ANALYZE` dentro de la transacción que
revierte — y por eso «quitar `ORDER BY id`» rojea los dos instrumentos de forma estable en vez de uno.

- [ ] **T5 — DEC-3 · #261**: implementar Option B, o cerrar `wontfix` con el argumento de `compose.prod.yaml:180-186`.
- [ ] **T6 — verificación completa** (ver *Gates*)
- [ ] **T7 — segundo pase adversarial** sobre el código que se escriba, antes de la PR que lo lleve.
- [ ] **T8 — cierres con evidencia** para cada issue que cierre sin código

### T4 — resultado medido

**El rojo es la llegada, y salió limpio.** Con `warning(`, el test funcional falla así:

```
the request appended to the log; without growth there is nothing to search
Failed asserting that 9240 is greater than 9240.
```

**Ni un byte.** No es que la línea llegue distinta: es que el fichero no crece, con el `write()` habiendo
lanzado de verdad (`assertSame(1, $writer->attempts)` pasa antes, entre las 9 aserciones que sí corren). Eso
separa *«descartada»* de *«nunca intentada»*, que era la vacuidad a evitar. Con `error(`, verde: 1 test / 11
aserciones.

**Lo que el instrumento tuvo que respetar para no mentir:** la suite comparte **un solo `test.log` que nadie
trunca** (`sf.clear.var.log` es manual y ningún target de test lo invoca) y varios tests provocan 5xx que
activan el mismo handler. Por eso la evidencia es sólo la **cola** añadida desde un offset tomado justo antes
de la petición, y se afirma el mensaje literal, no «el fichero no está vacío». Y se afirma `assertResponseIsSuccessful()`
porque un 404/405 está en `excluded_http_codes`: la línea ausente se leería como «descartada» cuando sería
«nunca auditada».

**La ruta:** `GET /api/v1/me` (`iam_me`), que `AuditPolicy` audita como `activity` por ser un `GET` con
semántica de negocio y sin `_audit_canonical`. **No hay disparador más barato sin autenticar**: `^/api` es
deny-by-default, y las cuatro rutas públicas o no son `GET` o son health, que la política excluye.

**Dos hechos del cableado que la decisión daba por supuestos y no lo estaban:**

1. **El canal es `app`, no `audit`.** `SymfonyAuditLogger` recibe un `LoggerInterface` pelado; no hay ni un
   `#[WithMonologChannel]` en `api/`, y el único cableado por canal del repo es `SearchObservabilityListener`
   con `#[Autowire(service: 'monolog.logger.observability')]`. El canal `audit` declarado en `monolog.yaml:6`
   **no lo consume nadie**. La premisa se sostiene a pesar de esa declaración, no gracias a ella.
2. **La coincidencia `when@test`/`when@prod` es más ancha que `action_level`** — también el `type` del
   handler, el `level` del `nested`, y que ninguno excluya el canal `app` — **y ya está rota en una clave**:
   test excluye `!event`, prod excluye `!deprecation`. Benigno hoy sólo porque ninguno excluye `app`.
   Declarado en el docblock del test; el gate de paridad es trabajo derivado y va en su propia PR (sin el
   test de llegada no protegía nada).

**No es un patrón nuevo, es una regla ya escrita que este sitio se saltó.** `InspectStoredIdentityHandler:20`
y `ReconcileSubjectErasuresHandler:16` ya razonan lo mismo palabra por palabra —*«una alarma que no puede
dispararse en producción no es una alarma»*— y `ReconcileSubjectErasuresHandlerTest` la pina en un test
llamado `itAlarmsAtErrorLevelSoProductionDoesNotDiscardIt`. Lo que ninguno de los dos tiene es el rojo de
llegada: los tres pinan **lo que la fuente llama**, nunca si Monolog lo conserva.

---

## T2 — resultado medido

**No hay ningún gate que lea este cambio, y eso es lo primero que hay que decir.** `.github/workflows/ci.yml`
no tiene paso de shell; `make/ci.mk` es `php.quality`/`pwa.quality` + los tests, ninguno toca `scripts/`;
`make/super-lint.mk` es local, manual y con `GITHUB_TOKEN`, y su único bloque que pondría `VALIDATE_BASH` está
comentado. No hay `.pre-commit-config.yaml`. **«Los tests pasan» no prueba nada sobre este diff.** Por eso la
evidencia se fabricó a mano.

**El `find`, instrumentado en vez de razonado** (`tmp/`, cinco ficheros sembrados con `touch -d`):

| Expresión | Borra |
|---|---|
| la de `main` | `db-old.dump` **y** `objects-old.tar.gz` |
| esta rama | `db-old.dump` |

El delta es **exactamente un fichero**, y es el que se quería.

**Y la cifra que el runbook publica se midió en vez de citarse, que es lo que la salvó de salir mal.** El
enunciado de partida —«`-mtime +N` es estrictamente mayor que N días enteros»— es **impreciso**: `find` tira la
parte fraccionaria de la edad, así que `+N` exige **al menos N+1 días enteros**. Un fichero de **14 días y
medio** no casa con `+14` (medido: sin línea) y sí con `+13` (medido: casa). El de 14 justos sobrevive con
cualquiera de las dos lecturas, que es por lo que el error habría pasado desapercibido.

**Precedencia, comprobada y no supuesta:** el original ya venía con paréntesis explícitos —
`\( -name A -o -name B \) -mtime +N`— así que **no** era el bug clásico de precedencia. Para un `db-*.dump`,
`(A -o B)` vale lo que vale `A`, de modo que retirar la rama `B` no cambia nada de lo que expira. Los
paréntesis se van con ella por quedar envolviendo un solo test.

**Shellcheck, traído a mano en contenedor porque no existe ni en CI ni en la máquina:** cinco hallazgos sobre
los dos scripts, **todos preexistentes y ninguno en las líneas tocadas** — `SC2016` ×2 (falsos positivos por
diseño: las variables las expande el `sh` de dentro del contenedor), `SC1091` ×2 (no sigue el `source`),
`SC2034` sobre `ENV_FILE` (lo consume `lib/common.sh`, que no sigue). Sobre `lib/common.sh` aparte sale un
`SC2148` (sin shebang), el único de nivel `error` del directorio. Ese inventario es lo que el trabajo derivado
de meter shellcheck en CI se encontrará el primer día. `bash -n` limpio en los dos.

**El one-liner del runbook está ejecutado, no sólo escrito.** Contra el mismo directorio de sondeo:
`RETENTION_DAYS=30` no borra nada (el fichero de 30 días no llega a los 31), y con el default 14 borra el
huérfano de 30 días y respeta el de 2. Es la comprobación que convierte la cifra del texto en un hecho.

---

## Pase adversarial (código) — T2

Ejecutado **antes de `gh pr create`**, sobre el árbol final, por tres lectores independientes en sólo lectura
con lentes distintas —pérdida de datos y daño al operador, prosa contra árbol, y honestidad del cierre de
issues—, cada uno instruido a refutar. **Ocho hallazgos: dos GRAVE, cuatro MEDIA, dos LEVE. Todos aplicados.**
Cada cita se re-verificó contra el árbol antes de tocar nada.

**GRAVE · Cerrar #255 con este cambio habría tirado una petición viva.** El argumento del cierre es «la
superficie emparejada ya no existe», y eso vale para **tres de sus cuatro** secciones (ratio de completitud del
par, P3, P9). No vale para la mitad que sobrevive: **loguear el tamaño del `db-<stamp>.dump`**, que es el
artefacto que este script sigue produciendo. Y el `ls -lh` que parecía cumplirlo **ya estaba ahí cuando se
abrió el issue** — verificado: `b0dce6ef:117` es la línea, y `b0dce6ef` es #253, exactamente la PR que #255
estaba revisando. Citarlo como cumplimiento habría sido citar como evidencia lo que el autor del issue ya tenía
delante cuando pidió más. Se implementa aquí (`log_success` con el conteo de bytes exacto, que es lo que un log
de cron puede comparar contra el de ayer) en vez de cerrar por encima.

**GRAVE · La corrección de nombre en #256 dejaba en pie una frase que ya no describe el código.** El issue dice
que el volumen «must exist, or `make backup.prod` **aborts on its volume-exists guard**», y hoy
`require_running_stack()` (`lib/common.sh:25-36`) comprueba `compose.yaml`, el fichero de entorno, `docker` y
que `database` esté corriendo, nada más. Se reescribe la sustancia, no el nombre.

**Y la primera versión de esa corrección estaba mal, en la dirección que más daño hace: le decía al autor que
su frase era falsa.** Era **cierta cuando la escribió** — la guarda existió, en
`b0dce6ef:lib/common.sh:36-40` (`docker volume inspect "$STORAGE_VOLUME"` con su propio `exit 1`), y la borró
#557. Lo dice M5 de este mismo documento veinte líneas más arriba, y lo verifiqué con `git show`. Es
exactamente el error que M5 existe para registrar («M5 era falso, y su acción habría hecho daño»), cometido
sobre M5. El issue se corrige por segunda vez, con el tiempo verbal puesto: cierta al abrirse, retirada por
#557.

**MEDIA ×3, todos sobre el one-liner que yo publicaba para que un operador lo pegue.** (1) Ruta fija
`/var/backups/erpify` ignorando el knob `BACKUP_DIR`: en un host con el directorio movido **y** la ruta por
defecto todavía existente, el barrido no casa nada, no falla, y el operador se va creyendo que lo hizo. (2)
`+14` fijo ignorando `RETENTION_DAYS`: con retención a 30 expira el archivo **antes** que el dump con el que
estaba emparejado. (3) El offsite no sigue al borrado local: un backend espejo (`rclone sync`) lo propaga en el
**siguiente** `make backup.prod`, no al barrer, y uno de instantáneas (`restic`/`borg`) no lo propaga en
absoluto sin un `forget`/`prune` explícito — y son justo los que el propio texto cita como los que devuelven
huérfanos. Los tres corregidos en el bloque.

**MEDIA · El marcador del sprint confundía decisión resuelta con código enviado.** Decía «#526 está resuelta» y
«Enviadas T1 (#725) y T4 (#727)» con #727 **abierta y sin mergear** y #526, por tanto, todavía `OPEN`. Es
exactamente el patrón por el que este repo ya se ha quemado. Separadas las dos claúsulas.

**LEVE ×2.** (1) Pronombre ambiguo en el aviso reformulado: «retention leaves **it** in place» ata por
proximidad a *the recovery point*, no al archivo — y además la retención es ortogonal al motivo de abortar, así
que la cláusula se retira y la línea dice lo que el operador tiene que sopesar. (2) El comentario de lectura
completa estaba sobre el bloque equivocado (describía `verify_dump`, seis líneas más abajo, con un `if` ajeno
en medio); bajado a su sitio, regla del boy scout, declarado aquí.

**Y una imprecisión mía que el pase cazó y que la medición confirmó**: ver *T2 — resultado medido*.

---

## Revisión de seguridad — T2

Recorrida la checklist del `CLAUDE.md` raíz. **No aplican por construcción:** todo el bloque de `api/` (no hay
PHP en el diff) y todo el de `pwa/`. Lo que sí aplica es la clase que este diff sí toca: **borrado de datos y
secretos en logs.**

- **Borrado — y aquí el razonamiento de la primera versión estaba invertido.** Decía que el cambio «reduce lo
  que la retención borra», y lo tomaba como la dirección segura. Para un control de retención sobre **datos
  personales** no lo es: el archivo de objetos lleva la misma PII que el dump (lo dice el comentario de
  `b0dce6ef`), así que retirar su única expiración automática deja esa PII en disco indefinidamente. No es
  «borra menos», es **una obligación de retención que pasa a manos de una persona**. Por eso el script ahora
  la denuncia en cada ejecución, el runbook dice que la sección no es un one-off, y esto entra en
  `PRODUCTION_SECURITY_CHECKLIST.md`: no por patrón nuevo, sino por cambio a un control existente. El comando que sí borra está en el runbook, lo ejecuta una persona,
  y lleva su comando de inspección **delante** — con el mismo predicado que el borrado, más ancho por el lado
  seguro (`ls` sin `-mtime` enseña más de lo que `find -delete` se lleva, nunca menos).
- **PII.** Los dumps llevan datos de negocio y el runbook ya lo dice. El barrido de huérfanos **no** propaga al
  offsite, y eso ahora está escrito: un archivo huérfano sigue en las instantáneas hasta un `forget`/`prune`.
  Es la mitad que se habría quedado sin decir.
- **Secretos.** `BACKUP_SYNC_CMD` puede llevar credenciales; el script lo sabe (`:96`, *«log a static line,
  never the value»*) y el diff no lo toca. El nuevo `log_success` emite una ruta y un entero, nada más.
- **Superficie destructiva.** `restore-prod.sh` sigue con sus dos puertas (`ALLOW_PROD_RESTORE` y la frase
  tecleada); el diff no roza ninguna.

`PRODUCTION_SECURITY_CHECKLIST.md` no se toca: no se introduce patrón de seguridad nuevo y un `find` con un
patrón menos no está en sus disparadores.

---

## Falsificación — cada cláusula tiene su rojo

**Regla del lote, heredada de BR-7: una aserción que pasa no prueba nada hasta que la has visto fallar.**

- **#612 — cumplido, y con una corrección que la propia regla obligó a escribir.** El observable no es «el
  mailer tiene timeout», es **«un SMTP que no responde deja de bloquear más de N segundos»**, falsable con un
  socket que acepta y calla — y ese rojo existe. Pero el enunciado seguía siendo demasiado ancho: lo que la
  cota garantiza es **por operación**, no por envío, y la medida del relé conforme (54 s con la cota en 10) es
  la que lo corrige. De los **dos rojos obligatorios que M15b nombra**, uno se cumplió y el otro **no se
  puede** cumplir y está declarado: el `instanceof SocketStream` **no tiene rojo en la suite** —su guardián es
  `make php.stan`— y el límite de esquema no tiene testigo en runtime, sólo el docblock.
- **#525** — si se poda: rojo de la ventana (una fila justo dentro y otra justo fuera) y rojo del orden de
  borrado, con la disciplina de `DbalAuditLogPruner`, no la del pruner desnudo.
- **#526 — cumplido.** El rojo **no es el umbral**, es la **llegada**, y salió: con `warning` el fichero no
  crece **ni un byte** (`Failed asserting that 9240 is greater than 9240`) habiendo lanzado el `write()`; con
  `error`, verde. Las tres guardas contra vacuidad se sostienen en ese orden — el doble **lanzó**
  (`assertSame(1, $writer->attempts)`), el fichero **creció** contra el offset previo, y sólo entonces se
  busca el mensaje en la **cola** añadida (la suite comparte un `test.log` que nadie trunca). La coincidencia
  `when@test`/`when@prod` resultó ser más ancha que `action_level` y **ya rota en `channels`**: declarada en
  el docblock del test, con el gate de paridad como trabajo derivado en su propia PR.
- **Siembra no vacua** — cualquier aserción de ausencia afirma primero que la siembra insertó N filas.
- **Una aserción cuyo valor esperado coincide con su valor de fallo no es una aserción.** El lote lleva ya
  **tres** de esa familia (`BOUND = 1.0`; `assertNotInstanceOf` sobre un hecho ajeno; y la siembra vacua de
  G-1b/G-5). Al fijar una constante de test, enumerar qué valores produce el código roto y comprobar que
  ninguno coincide.

---

## Gates

`make php.quality`, `make php.stan` sobre cada fichero PHP tocado, `make app.test`. **T2 no corre ninguno de
los tres, y la razón se dice en vez de callarse:** su diff no toca ni un fichero PHP ni de `pwa/`, así que las
tres puertas no leerían nada suyo. Lo que sustituye a un verde ahí está en *T2 — resultado medido*: `bash -n`,
shellcheck traído a mano, el `find` instrumentado y el one-liner del runbook ejecutado. Si T3/T5 tocan schedules o
transportes, además `make php.lint.schedule-consumption` (`make/php-quality.mk:217-220`; tres clases de gate
bajo `api/tests/Unit/Shared/Architecture/`, cableado en `php.quality` y en `php.quality.dry-run`). Lee los
compose de raíz por el bind mount de sólo lectura y **falla en vez de saltar** cuando no está —
`ScheduleConsumptionGateTest.php:165-180` llama a `$this->fail(...)`, y no hay ningún `markTestSkipped`.

**T1 — corridas frescas sobre el árbol final, con su exit code impreso:**

| Puerta | Exit | Nota |
|---|---|---|
| `make php.stan` | **0** | |
| `make php.quality` | **0** | incluye deptrac, `php.lint.prod-container` y `composer.check.missing-deps` |
| `make php.test` | **0** | PHPUnit 2869 tests / 11373 aserciones; Behat 439 escenarios / 4132 pasos |
| `make prod.env.check` | **0/2** | falsificada con seis valores: `(unset)`→0, `3`→0; `10s`, `abc`, `0.5`, `500`→2 |

**T3 — corridas frescas sobre el árbol final, con su exit code impreso:**

| Puerta | Exit | Nota |
|---|---|---|
| `make php.stan` | **0** | |
| `make php.quality` | **0** | |
| `make php.test` | **0** | PHPUnit 2881 tests / 11431 aserciones; Behat 439 escenarios / 4132 pasos |

El delta contra `main` (2869 / 11373) son los **12 tests nuevos** de T3. `PHPUnit Notices` sigue en **2**, los
dos preexistentes: los dos que mis dobles añadieron al principio se fueron al pasar de `createMock` a
`createStub`, que es lo que ya hacía el test hermano del pruner de auditoría — un `createMock` sin
expectativas emite *notice* en PHPUnit 13 y ninguna puerta lo convierte en rojo. `pwa.*` no se corre: cero
ficheros de `pwa/`.

El delta de PHPUnit contra el arranque de la sesión (2855) son exactamente los **14 tests nuevos**: 11 en el
unitario y 3 en el funcional de cableado.

**T4 — corridas frescas sobre el árbol final, con su exit code impreso:**

| Puerta | Exit | Nota |
|---|---|---|
| `make php.stan` | **0** | 1392 ficheros, `No errors` |
| `make php.quality` | **0** | |
| `make php.test` | **0** | PHPUnit 2870 tests / 11384 aserciones; Behat 439 escenarios / 4132 pasos |

El delta contra T1 (2869 / 11373) es **exactamente** el test de llegada: +1 test, +11 aserciones. Behat no se
mueve. **`make pwa.quality` / `make pwa.test` no se corren**: el diff no toca ni un fichero de `pwa/`.

**El verde del test de llegada se midió también en la suite completa**, no sólo con `--filter`: la suite
comparte un `test.log` que nadie trunca, así que un verde aislado no es evidencia de un verde acompañado.

**Dos decisiones de herramienta, nombradas para que sean revisables:**

1. **`filter_var` retirado a favor de `is_numeric`.** `composer-require-checker` pidió declarar `ext-filter`
   en `require` (regla de `api/CLAUDE.md`). Medido antes de añadir la extensión: **con el rango puesto, los
   dos predicados dan el mismo resultado en los nueve casos**, `1e400` incluido (`is_numeric` lo acepta,
   `(float)` lo vuelve `INF`, y el techo lo rechaza). Una extensión declarada que no compra comportamiento no
   se declara.
2. **Dos `@SuppressWarnings` de PHPMD, con su motivo escrito.** `TooManyPublicMethods` (3 de 11 son
   `#[DataProvider]`, públicos por contrato de PHPUnit) y **`Superglobals` sobre `$_ENV`, que es un choque de
   reglas**: `docs/project-context.md:128` *manda* `$_ENV` y prohíbe `getenv()`, y PHPMD lo prohíbe. Se anota
   el conflicto en vez de elegir en silencio; el patrón `@SuppressWarnings("PHPMD.…")` ya se usa en
   `api/tests/Behat/Context/`.

---

## Fuera de alcance

- **`event_store`** — append-only permanente por diseño; #525 lo excluye explícitamente.
- **La ventana de graceful-shutdown** para que `kernel.terminate` llegue a ejecutarse. #526 la nombra como el
  **vector dominante** de pérdida de `activity` y dice que no se ataca con observabilidad del writer.
- **Restaurar la superficie de objetos** (#557). Si DEC-1 va por ahí, es PR propia con su autorización.
- **Cablear el puente Monolog→Sentry.** Revertir la decisión de `sentry.yaml:14-17` es su propia discusión.

---

## Pase adversarial (código) — T7

Ejecutado sobre el **código de T1**, antes de reescribir el cuerpo de la PR, por tres lectores independientes
en sólo lectura con lentes distintas —comportamiento en runtime de la cota, cadena de cableado y despliegue, y
si cada aserción puede fallar—, cada uno instruido a refutar y a resolver la duda en contra. **Dieciséis
hallazgos: uno GRAVE, seis MEDIA, nueve LEVE. Todos aplicados.**

**GRAVE · La garantía anunciada no era la entregada.** La cota es **por operación de socket**, no por envío.
Medida y consecuencias en *T1 — resultado medido*. Lo que la hace grave no es el número sino la dirección:
**con el default que se iba a enviar, el peor caso era peor que el statu quo que el issue existe para
arreglar**, y las cuatro afirmaciones del repositorio decían lo contrario.

**MEDIA:**

1. `0.001` pasaba el guarda y tumba todo envío contra un relé sano (0,01 s, medido) → tragado a `warning` por
   `Send*BestEffort`, con el endpoint devolviendo su 202 uniforme. → rango con suelo.
2. «Fail loud» era **fail tarde**: `lint:container` sale 0 con `MAILER_SMTP_TIMEOUT=0`; la excepción salta al
   construir `mailer.transports`. → `prod.env.check`, y el docblock dice «primer envío», no «arranque».
3. `MAILER_SMTP_TIMEOUT=10s` —la grafía de duración que este repo usa en compose y Caddy— atravesaba
   `prod.env.check`, `docker compose config`, la build y el arranque; después, 500 en cada reset y el tick de
   bloqueo devolviendo `false` en silencio. → validado.
4. **La salida de emergencia documentada no existía.** `api/.env:56`, `api/.env.example:56` y
   `docs-info/production-deployment.md:66` decían los tres que sin la variable el socket cae a 60 s. No cae:
   compose sirve el default igual, y sin ella en ningún sitio lanza `EnvNotFoundException`. → corregidos.
5. **El cableado era lo único infalsificable del PR.** → test funcional que arranca el kernel.
6. `testLeavesATransportWithoutASocketStreamUntouched` no podía fallar. → afirma identidad.

**LEVE (nueve, todos aplicados o declarados):** rango sin techo (`1e308` → `(int)` es 0) · `is_numeric` más
ancho que el predicado del env (`1e400` → `INF` → `ValueError`, que **no** es `TransportExceptionInterface`) ·
el límite de esquema sin testigo en runtime · la rama falsa de `instanceof SocketStream` sin rojo posible ·
`assertLessThan(60, …)` redundante y frágil · techo `BOUND * 5` demasiado flojo · `docs-info/…:69` decía «both
`php` y `messenger_worker`» tres líneas bajo la fila cableada a mano en `scheduler_worker` ·
`docs/deployment-guide.md` y `api/docs/production-ready/secrets.md` sin actualizar · `#[Group('slow')]` no
hace nada.

**Refutado — la hipótesis que se le plantó al lector para que la confirmase.** Se sospechaba que el grupo
`slow` estuviera excluido en alguna puerta, lo que habría dejado muerto el único test que prueba el
observable real. **No lo está** en `phpunit.dist.xml`, `make/php-test.mk` ni `ci.yml`; la corrida de cobertura
de CI reporta el test. Confirmado por medición propia además de la del lector.

**Lo que este pase NO cubre:** la resolución de DNS (`stream_socket_client()` resuelve antes de conectar y su
`$timeout` no gobierna `getaddrinfo()` — tercera vía de cuelgue, acotada sólo por `/etc/resolv.conf`), el
comportamiento bajo el SAPI de worker de FrankenPHP (todas las sondas corrieron bajo CLI), y la imagen de
prod, que ningún workflow construye.

---

## Revisión de seguridad — T1

Recorrida la checklist del `CLAUDE.md` raíz por fichero del diff. **No aplican por construcción:** inyección
(cero SQL/DQL), authn/authz (no hay controller ni handler), mass assignment, codificación de salida,
CORS/CSRF/Mercure, migraciones, dependencias nuevas. Sin ficheros `pwa/`, así que la lista de frontend está
vacía. **Limpio y digno de constar:** el decorador **nunca loguea el `Dsn`**, que puede llevar
`smtp://user:pass@host`.

**Un hallazgo, clase disponibilidad, medido y arreglado aquí (SR-1).** La guarda de positividad cubría la
opción del DSN y dejaba **sin validar** el default inyectado: `EnvVarProcessor.php:273-279` sólo rechaza lo no
numérico, y compose reenvía verbatim porque `${…:-N}` sólo cubre *unset* o vacío. Medido contra el transporte
real: `MAILER_SMTP_TIMEOUT=0` → todo envío falla en **0,00 s** más un `Warning` de vendor en
`AbstractStream.php:91`; `-1` → **no volvió**, matado a los 150 s, 2,5× el default que venía a acotar. El knob
que existe para quitar una espera ilimitada podía reintroducirla, y peor. → rango 1–300, refutado en el
constructor, con rojo por extremo.

`PRODUCTION_SECURITY_CHECKLIST.md` no se toca: un timeout de socket no está en sus disparadores enumerados y
no se introduce patrón de seguridad nuevo. `pwa/docs/production-deployment.md` tampoco: no tiene superficie de
mailer — se dice en la PR en vez de saltárselo en silencio.

---

## Pase adversarial (código) — T4

Ejecutado **antes de `gh pr create`**, sobre el árbol final, por tres lectores independientes en sólo lectura
con lentes distintas —¿pueden fallar estos tests?, ¿es cierta la afirmación sobre producción?, ¿toda frase
escrita o dejada atrás es cierta?—, cada uno instruido a refutar. **Siete hallazgos: uno GRAVE, cuatro MEDIA,
dos LEVE. Todos aplicados.** Cada cita se re-verificó contra el árbol antes de tocar nada.

**GRAVE · «esta escritura corre en `kernel.terminate`» era falso, y lo escribí cinco veces.** `activity` tiene
**dos** caminos de captura, no uno: el hook genérico post-respuesta, y las llamadas explícitas de
`BankAccountSearcher:51` y `BankAccountCollectionSearcher:41`, **en plena petición**, antes de construir la
respuesta — sus rutas llevan `_audit_canonical` justo para que el hook genérico ceda. Lo contradecían el
docblock de `AuditPolicy` y una aserción preexistente del propio unitario («writes the sealed entry **in the
request cycle**»), y hasta la misma línea de `architecture-api.md` que edité, unas cláusulas más adelante.

Y no era sólo fraseo: **la cota de exposición dependía de la premisa falsa**. `FingersCrossedHandler` trae
`stopBuffering = true` por defecto (`:79`), así que tras activarse deja de bufferear y **todo lo que la
petición registre después sale directo**. En el hook post-respuesta apenas queda nada detrás; en una escritura
en plena petición queda el resto de la petición entera. El techo de «hasta 50» no acota ahí. Reformulado en
los cinco sitios, y de paso corregido el párrafo **preexistente** del mismo docblock que decía lo mismo de
más (regla del boy scout, declarada).

**MEDIA · «Doctrine queries included» es falso en producción.** `api/config/packages/doctrine.yaml` no fija
`dbal.logging`, y doctrine-bundle lo default-ea a `%kernel.debug%` (`Configuration.php:178`), que en `prod` es
false. El SQL de Doctrine **no** entra en ese flush salvo que alguien encienda `APP_DEBUG` en prod. En dev y
test sí. Corregido con la mecánica, no con un hedge.

**MEDIA · «nunca actor ni resource id» era una garantía sobre el CONTEXTO vendida como garantía sobre el
registro.** El array de contexto no los lleva, cierto — pero el `Throwable` viaja entero, y
`Driver\PDO\Exception::new()` propaga el mensaje del driver **verbatim** (`Exception.php:24`), y PostgreSQL
incrusta el literal ofensor en `invalid input syntax for type uuid: "…"`. Un id malformado llegando al INSERT
de `DbalAuditLogWriter` acaba en el log dentro del mensaje, bajo ninguna clave nuestra. Atenuante medida:
`JsonFormatter::$includeStacktraces` es `false` por defecto (`:37`), así que la traza con argumentos no sale.
Reescrito para decir de qué es la garantía.

**MEDIA · existir no es alertar, y el docblock lo insinuaba.** El puente Monolog→Sentry está desconectado a
propósito (`sentry.yaml:14-17`) y **ningún compose declara driver de logging**, así que la línea llega a
stderr del contenedor y a nada más. Sigue siendo estrictamente más que una línea que ningún entorno escribía —
pero se dice dónde para el cambio en vez de dejar leer que la operabilidad queda resuelta.

**MEDIA · la medición del ADR se presentaba como afirmación sobre producción.** Se midió bajo `when@test`;
transfiere sólo mientras el acuerdo con `when@prod` aguante, y ese acuerdo **no está gateado**. Acotada al
entorno en el que se tomó, con el riesgo nombrado al lado.

**LEVE ×2, aplicados.** (1) La razón escrita para `assertResponseIsSuccessful()` era incompleta: `AccessLogAuditListener:60`
ya cede ante una respuesta no exitosa, así que un no-2xx no llega ni a intentar la escritura — son dos razones,
no una. (2) `RecordingLogger:13` seguía diciendo «exactly one warning»; es el doble que sostiene el test que
cambié, así que entra por boy scout.

**Lo que el pase intentó y NO consiguió**, que vale tanto como lo que encontró: fabricar un verde falso.
Descartado que el estado del `FingersCrossedHandler` se filtre entre tests (cada `createClient()` da contenedor
nuevo; `$buffering`/`$buffer` son de instancia; los 12 `tearDown()` de `tests/Functional` llaman a
`parent::tearDown()`), y descartado que `attempts === 1` pueda venir de otra escritura (`loginUser()` no hace
HTTP; sólo `Bank` y `BankAccount` son `AuditedEntity`, así que los `flush()` de `User`/`Session` no tocan el
writer; y una escritura `security` en el doble **propagaría** antes de tomar el offset). El rojo medido con
`--filter` reproduce en la suite completa.

**Ese agujero ya no existe:** el test de llegada lee el nivel de los **bytes** que Monolog escribió
(`"level_name":"ERROR"` en el JSON del handler de test), no de un doble, así que una degradación a `warning` o
una promoción a `critical` rojean ahí mismo. Medido: con `warning` el registro **llega** —es un handler
always-on— y falla la aserción de nivel; con el canal por defecto no llega y falla la de llegada.

---

## Revisión de seguridad — T4

Recorrida la checklist del `CLAUDE.md` raíz por fichero del diff. **No aplican por construcción:** inyección
(cero SQL/DQL nuevo), authn/authz (ni controller ni handler nuevos), validación de entrada, mass assignment,
codificación de salida, CORS/CSRF/Mercure, migraciones, dependencias. Sin ficheros `pwa/`: la lista de frontend
está vacía.

**El eje que sí aplica es uno solo, y es el que el cambio mueve: qué llega al almacenamiento de logs**, que
tiene retención propia y ningún dueño declarado de su borrado. Tres medidas, todas arriba: el SQL de Doctrine
**no** entra en el flush en prod; la traza con argumentos **no** sale (`includeStacktraces = false`); y el
mensaje del `Throwable` **sí** puede llevar un id que el driver citó. Ese último es el residuo M13, ahora con
su vector nombrado en vez de descrito de más.

**Coste de volumen, aceptado y declarado:** la línea no lleva presupuesto. Una caída acotada a `audit_log`
emite una por lectura auditada con éxito, donde las proyecciones `security` hermanas de `Iam/Identity` sí
gastan un budget antes de escribir. Con umbral 1 y sin alerting aguas abajo se acepta; si la forma resulta
inaceptable, la palanca es el canal `observability`, no un contador.

`PRODUCTION_SECURITY_CHECKLIST.md` no se toca: subir el nivel de un log ya tragado no introduce patrón de
seguridad nuevo ni está en sus disparadores. `docs/api-error-contract.md` tampoco: gobierna la respuesta HTTP
RFC 9457, y aquí la respuesta sigue siendo 2xx — la excepción nunca llega a `kernel.exception`.

---

## Pase adversarial (código) — T3

Ejecutado **antes de `gh pr create`**, por tres lectores independientes en sólo lectura con lentes distintas
—la sentencia y lo que puede destruir, prosa contra árbol, y si la decisión misma se sostiene—, cada uno
instruido a refutar. **Once hallazgos: tres GRAVE, cinco MEDIA, tres LEVE. Todos aplicados o declarados.**

**GRAVE · El argumento de seguridad descansaba en una alarma que no lee nadie.** «La alarma habrá sonado 29
días antes» sólo vale lo que valga su alcance, y por D3 ese alcance es **una línea en el stderr del
contenedor**: el puente Monolog→Sentry sigue desconectado, ningún compose declara driver de logging, y nada
raspa `messenger:failed:status`. El margen protege contra que el pruner **enmascare** un backlog; no lo
entrega a nadie. Escrito así en el ADR en vez de dejar que el test del margen lo insinúe.

**GRAVE · El SLA de triage que el issue pedía no lo respondía nada.** #525 tiene tres preguntas y la tercera
—cadencia de triage— no estaba contestada ni aquí ni en el runbook. Resuelta sin inventar un número segundo
que mantener sincronizado: **la ventana ES el plazo**. Un fallo que nadie miró en 30 días se borra.

**GRAVE · Lo que la poda destruye no lo cubre `event_store`.** El evento de dominio sobrevive —se anexa antes
del dispatch, atómico con la escritura del agregado—. El **contexto del fallo** no: clase y mensaje de la
excepción, número de reintentos y el sello de redelivery viven **sólo** en `messenger_messages.headers` y se
van con la fila. La poda conserva el *qué* y tira el *porqué*. Declarado en el docblock y en el ADR.

**MEDIA · El drenaje no tenía cota de reloj, y esta clase es justo la que la necesita.** El pruner de
`audit_log` no la lleva porque esa tabla **siempre** se podó: su barrido se encuentra un día de llegadas. Éste
llega a una cola que nada acotó nunca, así que su **primera** ejecución se enfrenta al historial entero
sosteniendo el advisory lock — y `messenger:consume --time-limit` no interrumpe un handler ya en vuelo, así
que bloquearía al scheduler de una réplica con todos los demás ticks detrás. Añadido un presupuesto de reloj
(300 s por defecto), monótono a propósito, con su rojo.

**MEDIA · El test de plan era flaky por construcción** — ver *T3 — resultado medido*. Corregido sembrando su
propia población.

**MEDIA · Las líneas `info` del pruner no las lee producción**, exactamente por lo que T4 acaba de medir. No
se suben de nivel: un salto por lock no es un error. Se declara que son diagnóstico de desarrollo, y que lo
que reporta un pruner parado no es una línea sino **el propio backlog**, que la alarma de al lado denuncia a
`error` cada hora.

**MEDIA ×2 · Huecos de documentación con precedente explícito.** La sección de dead-letter de
`architecture-api.md` documenta la retención de sus hermanas (`handled_domain_event`, `iam_session`) y no
sabía de ésta; el catálogo de gates de `claude-code-quickref.md` describe el par gate-de-código +
test-de-plan del pruner de auditoría por su nombre y no el gemelo que esta PR añade; y el `CLAUDE.md` raíz
tiene una bala **«Deleting from `audit_log`»** sin hermana. Los cuatro rellenados. Añadida además la fila que
faltaba en la tabla de conformidad de `maintenance-job-execution-contract.md`, que es *evidencia* y estaba
incompleta.

**LEVE ×3, declarados y no arreglados.** (1) Ningún `messenger:consume` consume `failed` hoy, así que la
contención con el consumidor del transporte —`FOR UPDATE SKIP LOCKED` contra el nuestro— **no es alcanzable**;
verificado además que **no hay deadlock posible**, porque cada sentencia de bloqueo de ambos lados es
autocommit y no se forma espera cíclica. (2) Un mensaje que falla, se reintenta y vuelve a fallar Symfony lo
**borra** en vez de reencolarlo (guarda de auto-bucle en `SendFailedMessageToFailureTransportListener` +
`reject()`), así que `created_at` mide siempre la entrada a `failed` y la ventana significa lo que dice; es
comportamiento preexistente del framework, digno de un issue propio. (3) La colisión de nombres de advisory
lock con dos nombres es 1/2³² ≈ 2,3·10⁻¹⁰ — el gatillo del retrofit a `hashtextextended` queda *tirado de
verdad*, no hipotético.

**Lo que el pase argumentó y NO se aceptó:** subir la ventana a 90 días para igualar el tramo `activity` de
`audit_log`. Ese tramo sirve a un rastro que alguien puede querer reconstruir; una carta muerta es un elemento
de trabajo, y uno que nadie reclamó en un mes no se reclama en tres. DEC-4 decidió 30 y el argumento del
revisor no lo mueve — pero queda escrito, con su alternativa descartada, en el ADR.

---

## Revisión de seguridad — T3

Recorrida la checklist del `CLAUDE.md` raíz. **No aplican por construcción:** authn/authz (no hay controller
ni ruta), validación de entrada (el único parámetro es un `DateTimeImmutable` que el handler deriva de una
constante), mass assignment, codificación de salida, CORS/CSRF/Mercure, y todo el bloque de `pwa/` (cero
ficheros).

- **Inyección.** La sentencia es una sola cadena constante con tres parámetros ligados (`:queue`,
  `:threshold`, `:batch`), tipados. Cero interpolación. El gate de código refuerza que no haya una segunda.
- **Migraciones.** Ninguna: la medición del plan dice que el recorrido por clave primaria basta, así que no se
  añade índice ni se toca el esquema.
- **Borrado de datos.** Es la clase entera del cambio y está tratada arriba: el predicado de cola tiene dos
  guardianes, la ventana tiene su rojo en la frontera, el drenaje tiene cota, y lo que se pierde
  —contexto del fallo— está declarado en vez de descubierto.
- **GDPR.** La poda **no** es un camino de borrado y el cambio no debilita la prohibición: once frases del
  repo afirmaban «`failed` no tiene TTL ni poda» como justificación de esa prohibición y la mitad pasaba a ser
  falsa; corregidas con una redacción que cierra explícitamente la lectura «total, sólo sobrevive 30 días» —
  el registro y su gate conservan los dientes, y el propio `FAILURE_PREAMBLE` del gate ahora lo dice.
- **Secretos.** Nada nuevo se loguea; las dos líneas emiten un conteo.

`PRODUCTION_SECURITY_CHECKLIST.md` **sí** se toca, y no por patrón nuevo: contenía una de las once frases.

---

## Pase adversarial (medición)

Ejecutado **antes** de abrir la PR, por tres lectores independientes en sólo lectura, uno por grupo, cada uno
instruido a refutar y a resolver la duda en contra. **De quince filas, tres refutadas y ocho corregidas.** Los
tres defectos con consecuencia:

1. **M5 era falso, y su acción habría hecho daño.** Afirmé que la dependencia «#252 / `object_storage_data`»
   era falsa en sus tres cláusulas. Las tres eran ciertas salvo el nombre: el volumen era `storage_data`,
   la guarda vivía en `lib/common.sh`, y **#252 es el PR que desplegó el volumen**. La tarea que escribí
   —«corregir la dependencia falsa»— habría abierto una corrección contra una nota correcta.
   **Causa raíz:** `git log -S` es **ciego a los mensajes de commit**; el literal sólo aparece con `--grep`.
2. **M10 citaba prosa en lugar de medir.** Dije «seis ticks» copiando el comentario de `compose.prod.yaml`.
   Son ocho, y ese comentario está drifted **en el propio commit base de esta historia**. Es exactamente el
   fallo que la historia existe para denunciar, cometido dentro de la historia.
3. **M14 declaraba abierto lo que estaba decidido.** Escribí que si un `warning` llega a Sentry «está sin
   medir». Está medido y registrado dos veces: no llega ninguno. La opción 1 de #526 no es cara, es imposible
   sin revertir una decisión, y eso colapsa DEC-5.

Corregidas además: M2 y M3 (P9 y el barredor de legado apuntan a código que existió, no inexistente), M4 (el
`ls -lh` precede a la petición), M6 (cuatro pasos rotos, no tres), M7 (rango `:92-95`), M8 (dos entradas más
una añadida, no tres cambiadas; `.env.prod.example:70` lleva el prefijo `CHANGE_ME_` que `deploy.mk:26`
rechaza), M9 (seis casos de uso y un séptimo remitente, no tres), M11 (`:17-24`, y el `conflict` que fija el
suelo `>= 7.4`), M12 (tres lectores, y el ligado al transporte es `MessengerDeadLetterReader`), M13 (el
residuo del mensaje del `Throwable`), M15 (la lista de seams estaba mal compuesta en las dos direcciones).

---

## Preguntas

1. ~~**DEC-1** y su sub-pregunta sobre los `objects-*.tar.gz`.~~ **Resuelta** (2026-08-15) — ver *Sub-decisión
   de DEC-1*. Lo que queda no es una pregunta de diseño sino tres datos del host: si quedan huérfanos, el
   `RETENTION_DAYS` real, y si el offsite propaga borrados o guarda snapshots.
2. ~~¿#612 se queda en BR-8 o sale a PR propia?~~ **Resuelta** (2026-08-15): la PR #725 se corta a **T1
   solo**. T2/T3/T4 salen en PRs propias; #256 y #261 siguen abiertos.
3. ~~¿Se toman DEC-2/3/4/5 ahora?~~ **Resueltas las cuatro.** Queda **DEC-3 (#261)** abierta, fuera del lote.
4. **Abierta, nacida del GRAVE de T7:** un techo real sobre el tiempo **total** de un envío no cabe en una
   opción de socket. Las tres formas donde sí cabe son un `--time-limit` de worker, un deadline en los
   envoltorios `Send*BestEffort`, o tapar el `max_execution_time = 0` que hoy no acota nada. ¿Issue propio?

## Dev Agent Record

### Agent Model Used

claude-opus-5[1m]

### Debug Log References

Todas las sondas son desechables, viven en el `tmp/` gitignoreado y **no entran en el commit**.

- Contra el contenedor vivo: construyó el transporte que la app usa de verdad y volcó `getTimeout()`.
  Repetida con `MAILER_SMTP_TIMEOUT=7 make docker.up` para probar que el reenvío por compose no es un no-op.
- **Relé conforme pero lento** (`probe-slow-relay-{server,client}.php`): habla SMTP correctamente con un
  retardo por comando. Es el instrumento que midió los 54,00 s y, con él, el GRAVE de T7.
- **Valores fuera de rango** (`probe-zero-timeout.php`): `0` → fallo en 0,00 s; `-1` → matado a los 150 s.
- **Predicados** (`probe-filtervar.php`): los trece casos de `filter_var` frente a `is_numeric`, que es lo que
  decidió no declarar `ext-filter`.

### Completion Notes List

- **T1 (#612) entregado.** Decorador de transporte, no arreglo por llamante. Verificado en tres planos, que no
  son sustituibles entre sí: el unitario (valor de `getTimeout()`), el socket real (un servidor que acepta y
  calla, 60.06 s → dentro de la cota) y el contenedor vivo (`debug:container` + sonda).
- **La falsificación encontró CUATRO tests que no podían fallar** a lo largo de las dos vueltas, todos
  corregidos y todos ahora con rojo propio. La primera vuelta dio dos (guarda `is_numeric` sin caso que la
  cubriera; `BOUND = 1.0` coincidiendo con el valor de fallo del cast de array) y T7 los otros dos. La
  tercera (`assertNotInstanceOf` sobre un hecho ajeno) sólo apareció con una mutación que **nadie había
  pedido**: vaciar `create()` entero. La lección para el lote está en *Falsificación*.
- **Punto ciego declarado, no tapado:** la cota vale para `smtp`/`smtps`. Un bridge de API o `sendmail://` no
  la recibe; está escrito en el docblock de la clase.
- **T7 ejecutado y aplicado** — ver *Pase adversarial (código)*. Dieciséis hallazgos, uno GRAVE, ninguno
  diferido. Corrigió la garantía central del cambio y bajó el default de 10 a 3.
- **Lo que este PR NO promete:** un techo sobre el tiempo total de un envío. Está dicho en el docblock, en
  `api/.env`, en `docs-info/production-deployment.md` y en el cuerpo de la PR, y es la pregunta 4.
- **No entra en este PR:** T2 (#255), T3 (#525) y T4 (#526) tienen decisión pero salen en PRs propias; #256 y
  #261 se quedan abiertos por decisión de Sergio. **BR-8 no se cierra con este PR.**
- **T4 (#526) entregado en PR propia.** Una línea de producción, y un test que la hace falsable donde el
  unitario no podía: el unitario pina **lo que la fuente llama**, nunca si Monolog lo conserva. Rojo medido —
  con `warning` el log no crece ni un byte.
- **No es un patrón nuevo, es una regla ya escrita que este sitio se saltó.** `InspectStoredIdentityHandler:20`
  y `ReconcileSubjectErasuresHandler:16` ya razonan lo mismo palabra por palabra. Lo que ninguno tiene es el
  rojo de llegada, y por eso los tres siguen sin poder distinguir «lo llamé» de «llegó».
- **El pase adversarial de T4 corrigió la premisa central del cambio, otra vez.** «Corre en `kernel.terminate`»
  era falso —hay un segundo camino de captura, en plena petición— y de esa premisa colgaba la cota de
  exposición. Es el mismo patrón que T7 encontró en T1: la frase que el código cuenta sobre sí mismo es
  exactamente la que hay que mandar a refutar.
- **Dos hermanos fuera de alcance, medidos y por decidir:** `RecordLockoutAuditBestEffort:60` y
  `RecordRecoveryThrottleAuditBestEffort:81` pierden una fila **`security`** y la reportan al mismo nivel
  invisible. DEC-5 decidió una línea; ampliarlo es decisión de Sergio, no efecto colateral de esta PR.

### File List

- `api/src/Shared/Mailer/Infrastructure/TimeBoundedSmtpTransportFactory.php` — nuevo
- `api/tests/Unit/Shared/Mailer/Infrastructure/TimeBoundedSmtpTransportFactoryTest.php` — nuevo
- `api/tests/Functional/Shared/Mailer/TimeBoundedSmtpTransportWiringTest.php` — nuevo; el cableado que
  ninguna otra puerta podía falsificar
- `api/.env`, `api/.env.example`, `.env.prod.example` — `MAILER_SMTP_TIMEOUT` (3 s) y su porqué
- `compose.yaml` (php, messenger_worker), `compose.prod.yaml` (scheduler_worker) — reenvío de la variable
- `make/deploy.mk` — validación de rango en `prod.env.check`
- `docs-info/production-deployment.md`, `docs/deployment-guide.md`,
  `api/docs/production-ready/secrets.md` — la variable, y la corrección de los tres servicios

**T4 (#526)** — PR propia, rama `fix/shared-audit-write-loss-invisible`:

- `api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php` — `warning(` → `error(`, y el docblock
  reescrito (nivel, coste del buffer, alcance de la garantía sobre el contexto, dónde para el cambio)
- `api/tests/Functional/Shared/Audit/ActivityAuditWriteFailureArrivalTest.php` — nuevo; el test de llegada
- `api/tests/Functional/Shared/Audit/Fixtures/ThrowingAuditLogWriter.php` — nuevo; el doble que lanza y cuenta
- `api/tests/Unit/Shared/Audit/Infrastructure/SymfonyAuditLoggerTest.php` — sólo el nivel (`:89`); el
  `assertSame` del array de contexto queda intacto
- `api/tests/Unit/Shared/Audit/Infrastructure/Double/RecordingLogger.php` — docblock, boy scout
- `docs/architecture-api.md`, `docs/adr/audit-activity-log.md` (D3.1, tres frases) — el nivel y su porqué

---

## Review Findings — bmad-code-review (2026-08-15) · T4 / PR #727

Tres capas (*Blind Hunter*, *Edge Case Hunter*, *Acceptance Auditor*) en paralelo, sólo lectura. El pase
adversarial propio de T4 ya había corrido; esto es lo que **sobrevive** a ese pase.

- [x] [Review][Patch] **La activación del buffer vuelca el email del sujeto a stderr** — subir a `error` activa
      `fingers_crossed`, y `handleBatch` emite los ≤50 registros de OTROS componentes: `ContextListener:248`
      loguea a `debug` `['username' => getUserIdentifier()]`, y `SecurityUser:33` devuelve `$user->email()`.
      Firewall `main` sin `stateless`, así que ocurre en cada petición autenticada. `RedactionDenylist` filtra
      por clave y la clave es `username`, en un registro de vendor. **Medido en los cuatro eslabones.**
      Decisión de Sergio: enrutar la línea al canal `observability` (always-on, excluido del `fingers_crossed`),
      que llega sin abrir el buffer. [api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php:114]
- [x] [Review][Patch] **La premisa refutada sobrevive en la línea que este mismo diff edita** — «`activity`
      capture runs on `kernel.terminate`» sigue ahí; sólo cambié el paréntesis del nivel. También en
      `AuditLogWriter.php:11` y en `audit-activity-log.md:155` (D3.1, texto vivo). [docs/architecture-api.md:265]
- [x] [Review][Patch] **El mismo párrafo sigue vendiendo el `warning` tragado como control vivo** del precio de
      `RecordRecoveryThrottleAuditBestEffort` — cierto sobre ESE sitio, pero afirma como operativa una señal que
      este PR acaba de probar inalcanzable. [docs/architecture-api.md:265]
- [x] [Review][Patch] **El reporte puede lanzar desde dentro del `catch`** — un `StreamHandler` hace `fwrite` y
      lanza; en el camino in-request eso convierte una operación con éxito en 500, que es lo que la frontera
      existe para impedir. [api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php:114]
- [x] [Review][Patch] **El test no lee el nivel de los bytes que Monolog escribió** — afirmar el nivel sobre un
      doble no prueba nada, pero afirmarlo en la salida sí, y gratis. Cierra el agujero de `critical`.
      [api/tests/Functional/Shared/Audit/ActivityAuditWriteFailureArrivalTest.php:87]
- [x] [Review][Patch] **`filesize()` está cacheado y el offset previo puede ser rancio** — `clearstatcache` se
      llama antes del segundo `filesize`, no del primero; un offset corto ensancha la ventana leída y hace la
      aserción de crecimiento MÁS fácil, no más difícil.
      [api/tests/Functional/Shared/Audit/ActivityAuditWriteFailureArrivalTest.php:63]
- [x] [Review][Patch] **El doble lanza para TODOS los niveles**, incluidos los dos que propagan por diseño
      (`security`, y el `change` del listener `onFlush`). Hoy es benigno por dos hechos que el test no afirma.
      [api/tests/Functional/Shared/Audit/Fixtures/ThrowingAuditLogWriter.php:26]
- [x] [Review][Patch] **El boceto de Sentry comentado no excluye el canal** que esta línea usa — quien lo
      descomente pagina ante una pérdida que el ADR declara aceptable. [api/config/packages/monolog.yaml:94-99]
- [x] [Review][Patch] **La paridad `when@test`/`when@prod` sigue sin gate**, y el propio test lo dice. Con el
      canal `observability` la dependencia se estrecha, pero no desaparece.
- [x] [Review][Patch] **`RecordingLogger` describe el nivel delegando en configuración que no puede ver** —
      «at the level production delivers» es mentira el día que prod se mueva.
      [api/tests/Unit/Shared/Audit/Infrastructure/Double/RecordingLogger.php:13]
- [x] [Review][Patch] **`#[CoversClass]` atribuye un test de pila entera a la clase que aquí no puede regresar**
      — infla cobertura donde ya la había y no atribuye nada a lo que el test protege de verdad.
      [api/tests/Functional/Shared/Audit/ActivityAuditWriteFailureArrivalTest.php:35]
- [x] [Review][Patch] **Tres afirmaciones del propio artefacto contradicen el código que envía**: *Mecanismo de
      DEC-5* y *Alcance* T4 siguen diciendo «50 registros `debug` — consultas Doctrine incluidas», que el pase
      T4 refutó; y el pase afirma que el docblock del test nombra el caso `critical`, y no lo nombra.
- [x] [Review][Defer] **Los dos hermanos `warning` de `Iam/Identity`** — decidido por Sergio: PR propia después
      de T2/T3. [api/src/Iam/Identity/Application/RecordLockoutAuditBestEffort.php:60]
- [x] [Review][Defer] **La configuración de Monolog restateada en cinco sitios de prosa** — real, pero el ADR
      es quien debe poseer el *porqué*; se paga junto con el gate de paridad.

**Resultado.** Los doce `patch` aplicados; los dos `defer` intactos. El cambio de mecanismo —de subir el nivel
a mover el canal— lo decidió Sergio sobre la medición, no sobre una preferencia: la revisión probó que activar
el buffer vuelca el email del usuario, y el canal `observability` hace llegar la línea sin abrirlo. Puertas
frescas sobre el árbol final: `php.stan` **0**, `php.quality` **0**, `php.test` **0** (PHPUnit 2874 / 11419;
Behat 439 / 4132), `PHPUnit Notices` sigue en los 2 preexistentes.

**Los dos rojos del test, medidos por separado y en corridas aisladas** (encadenar mutaciones en un script
contaminó la primera medición y las dos dieron el mismo mensaje; una a una dan el suyo):

| Mutación | Rojo |
|---|---|
| Volver al canal por defecto | «the request appended nothing to search» — no llega al log always-on |
| Degradar a `warning` | «the arriving record is the error it claims to be» — **llega** y falla el nivel |

El segundo es el que prueba que el nuevo instrumento lee el nivel de los **bytes** que Monolog escribió, no de
un doble. La mutación del canal dispara además la aserción de no-activación del buffer; el orden hace que
reporte primero la de llegada.
