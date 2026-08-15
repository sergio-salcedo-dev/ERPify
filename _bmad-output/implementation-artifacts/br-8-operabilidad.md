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
| **M12** | **No existe ningún pruner de `failed`.** Atacado por seis vías: los únicos `DELETE FROM` de `api/src` son sobre `bank_count`, `audit_log` y `handled_domain_event` (×2); `messenger_messages` sólo aparece en migraciones como `CREATE`/`DROP`; no hay `messenger:failed:remove` fuera de prosa; ningún target de `make/`, script o compose poda el transporte; los únicos cron del repo son los workflows de CI y el dump de Postgres. `PRODUCTION_SECURITY_CHECKLIST.md:687` lo dice también. **Tres cosas lo leen, todas de sólo lectura**: `MessengerDeadLetterReader` (el único ligado a `@messenger.transport.failed`, `services.yaml:46`, tipado al `ReceiverInterface` de lectura por diseño), y sus dos consumidores — `FailedMessagesStatusCommand` (a demanda) y `ReportDeadLetterBacklogHandler` (**horario**). |
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
| **DEC-4** · #525 | Ventana de retención de `failed`, borrado automático vs herramienta de operador, y SLA de triage. | `failed` es durable **a propósito**; podarlo cambia el contrato. Es la única tabla hermana sin retención (M12), y el pruner tendría que convivir con una alarma **horaria**. |
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

**Coste aceptado y declarado:** subir el nivel abre el buffer, así que la línea arrastra hasta 50 registros
`debug` —consultas Doctrine incluidas— por ocurrencia, lo que **aumenta** la exposición del residuo M13 en vez
de reducirla. Se acepta a cambio de que la señal exista. Si esa forma resulta operativamente inaceptable, la
palanca es la arquitectura de logging y ya está en el repo —el canal `observability` es un stream always-on
excluido del `fingers_crossed` por este mismo motivo—, **no** una tabla contadora.

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
        volumen). Cambiar sólo el sustantivo habría dejado la falsedad con aspecto de revisada. La dependencia
        se conserva como **hecho histórico**, no como precondición viva.
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
- [ ] **T3 — DEC-4 · #525: retención de `failed`** (sólo tras la decisión)
  - [ ] **Reutilizar, no reinventar — y elegir bien el patrón**: los dos pruners existentes **no son
        intercambiables**. `Shared/Event/Infrastructure/Messenger/DbalHandledDomainEventPruner.php` es un
        `DELETE` desnudo; el que la sección de *Falsificación* obliga a imitar es
        `Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php:142`, con `ORDER BY id … FOR UPDATE`.
  - [ ] Colgarlo de `Maintenance/HandledDomainEventMaintenanceSchedule.php`; convive con
        `Maintenance/ReportDeadLetterBacklogHandler.php` (horario) y lee por `MessengerDeadLetterReader`.
  - [ ] Un `#[AsSchedule]` nuevo obliga a añadir su transporte al `messenger:consume` de **`compose.yaml`
        (dev) y `compose.prod.yaml`**. Y a **actualizar el comentario de `:174-178`**, que ya va dos ticks
        por detrás (M10).
- [ ] **T4 — DEC-5 · #526: hacer que la señal exista** (resuelta: umbral 1, sin contador)
  - [ ] `warning(` → `error(` en `SymfonyAuditLogger.php:86`. **Nada más**: ni contador, ni tabla, ni
        `#[AsSchedule]`, ni transporte — y esa ausencia es la propiedad, no la falta de trabajo: **un cambio
        que no declara transporte no puede enviarse muerto.**
  - [ ] **El `assertSame` del array de contexto (`SymfonyAuditLoggerTest.php:92`) se queda intacto** — es el
        guardián del residuo M13. Se mueve sólo el nivel (`:89`).
  - [ ] Actualizar el docblock de la clase y `docs/architecture-api.md`: ambos dicen «swallowed and logged at
        warning», que deja de describir el código.
  - [ ] **No reenviar el mensaje del `Throwable` sin acotarlo** (M13), y declarar que subir el nivel
        **aumenta** su exposición: la línea ahora sí se escribe, y arrastra hasta 50 registros del buffer.
  - [ ] Deja de ser espejo de `ReportDeadLetterBacklogHandler`: aquel cuenta filas que **existen**, aquí el
        fallo es que la fila **no se escribió**. Ese es el motivo de que no haya contador.
- [ ] **T5 — DEC-3 · #261**: implementar Option B, o cerrar `wontfix` con el argumento de `compose.prod.yaml:180-186`.
- [ ] **T6 — verificación completa** (ver *Gates*)
- [ ] **T7 — segundo pase adversarial** sobre el código que se escriba, antes de la PR que lo lleve.
- [ ] **T8 — cierres con evidencia** para cada issue que cierre sin código

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
- **#526** — el rojo **no es el umbral**, es la **llegada**: un test funcional que sustituye el writer por un
  doble que lanza, golpea una ruta auditada y lee `api/var/log/test.log`. Antes del cambio la línea **no
  está** (buffer, descartada); después **sí**. `when@test` comparte con `when@prod` el `action_level: error`,
  que es lo que se pina — y esa coincidencia es hoy una casualidad de la que el test depende: declararlo.
  Contra vacuidad: afirmar que el doble **lanzó** (`assertSame(1, $writer->attempts)`) y que el fichero
  **creció** respecto del offset previo, **antes** de buscar el mensaje.
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

El delta de PHPUnit contra el arranque de la sesión (2855) son exactamente los **14 tests nuevos**: 11 en el
unitario y 3 en el funcional de cableado.

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
- **No entra en el PR de T1:** T2 (#255), T3 (#525) y T4 (#526) salen en PRs propias; #256 y #261 se quedan
  abiertos por decisión de Sergio. **BR-8 no se cierra con ninguna de las cuatro.**

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

**T2 (#255)** — PR propia, rama `fix/shared-backup-retention-drift`:

- `scripts/deploy/backup-prod.sh` — retención a un solo patrón, `pwd -P`, pipeline `-print0`, conteo de bytes
  antes del borrado, aviso de huérfanos
- `scripts/deploy/restore-prod.sh` — `pwd -P`, segunda línea del aviso, banner de sección a su sitio
- `docs/vps-deployment.md` — sección del barrido de huérfanos, y el conteo de bytes en *Taking a backup*
- `PRODUCTION_SECURITY_CHECKLIST.md` — la expiración automática de PII que pasa a manos de un operador
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — marcador y comentario

---

## Review Findings — bmad-code-review (2026-08-15) · T2 / PR #728

Tres capas en paralelo, sólo lectura, sobre el árbol final. Lo que sobrevive al pase adversarial propio de T2.

- [x] [Review][Patch] **Un `BACKUP_DIR` que sea enlace simbólico deja la retención muda para siempre** — `find`
      por defecto es `-P` y NO desciende un enlace pasado como punto de partida; `cd … && pwd` es lógico y no lo
      normaliza. **Medido: 1 coincidencia por la ruta real, 0 por el enlace; `pwd -P` lo arregla.** Mover los
      backups a otro disco es la maniobra de operación más normal que hay, y el fallo aparece días después como
      «los backups han dejado de correr». Alcanza también a `list_stamps()`.
      [scripts/deploy/backup-prod.sh:53]
- [x] [Review][Patch] **El bloque publicado borra antes de que el operador pueda leer lo que se le dice que
      lea** — `ls` y `find … -delete` son dos comandos sin encadenar dentro de un mismo bloque: pegarlo entero
      ejecuta el borrado en la misma pulsación, y un `ls` que falle no lo detiene. [docs/vps-deployment.md:231]
- [x] [Review][Patch] **Se retira la única expiración automática de archivos que llevan la MISMA PII que el
      dump** (`b0dce6ef` lo dice en su propio comentario), y la revisión de seguridad de T2 tomó «borra menos»
      como seguro. Para un control de retención sobre datos personales, borrar menos es la dirección de fallo.
      [scripts/deploy/backup-prod.sh:95]
- [x] [Review][Patch] **El barrido «one-off» no puede barrer los archivos que más probablemente están ahí** —
      filtra por `-mtime +N`, y los que existen al desplegar son justo los de los últimos días. El operador lo
      corre, no borra nada, y no vuelve. [docs/vps-deployment.md:234]
- [x] [Review][Patch] **Los `:-` del bloque hacen lo contrario de lo que su propio párrafo ordena** — «toma los
      dos knobs de tu línea de cron» no lo puede imponer la prosa; sin variables puestas, los defaults se
      aplican en silencio. [docs/vps-deployment.md:232]
- [x] [Review][Patch] **`find … -delete` no imprime nada**, así que «no barrió nada» y «barrió doce» se ven
      igual — es el multiplicador que vuelve infalsificables los demás no-ops. [docs/vps-deployment.md:234]
- [x] [Review][Patch] **La sustitución de procesos descarta el exit status de `find`**, y un salto de línea en
      un nombre parte la ruta y hace que `rm` dispare relativo a `$REPO_ROOT`. [scripts/deploy/backup-prod.sh:92]
- [x] [Review][Patch] **El conteo de bytes: producido DESPUÉS del borrado que podría haber gateado, emitido por
      `log_success` con color ANSI incondicional (menos greppable que el `ls -lh` al que venía a superar), y
      `$(wc -c)` bajo `set -e` puede abortar la ejecución tras un dump bueno y ANTES del sync offsite.
      **Medido: exit 1.** [scripts/deploy/backup-prod.sh:101]
- [x] [Review][Patch] **`RETENTION_DAYS=0` en el bloque publicado** — valor que el propio script acepta, y
      `-mtime +0` se lleva el archivo emparejado con el dump de ayer. [docs/vps-deployment.md:235]
- [x] [Review][Patch] **Le dije al autor de #256 que una frase suya era falsa, y era cierta cuando la
      escribió** — la guarda de volumen-existe SÍ existía (`b0dce6ef:lib/common.sh:36-40`, `docker volume
      inspect`) y la borró #557. M5 del propio artefacto lo dice; mis líneas nuevas lo contradicen. **Verificado
      con `git show`.** Hay que corregir el issue otra vez y reconciliar el artefacto.
- [x] [Review][Patch] **Nada avisa de que hay huérfanos** — el diseño pasa a depender de que alguien lea el
      runbook. `backup-prod.sh` ya recorre el directorio; falta la mitad detectiva que el repo aplica en otros
      ejes. [scripts/deploy/backup-prod.sh:92]
- [x] [Review][Patch] **La retención sigue borrando en silencio**, en el mismo bloque que este cambio edita
      para observabilidad. [scripts/deploy/backup-prod.sh:94]
- [x] [Review][Patch] **`[x] #255 cerrado con evidencia` se afirma en pasado** cuando `Closes #255` sólo dispara
      al mergear — el mismo patrón «decisión resuelta ≠ código enviado» que este commit corrige para T4 y no
      aplica a T2. Y el comentario del sprint fija estado transitorio de PRs que nada actualizará.
- [x] [Review][Patch] **Dos encabezados `##` quedaron dentro de la lista de *Alcance***, así que T3–T8 cuelgan
      de «Revisión de seguridad — T2»; y el `###` de T2 va pegado a la última viñeta sin línea en blanco.
- [x] [Review][Patch] **El conteo de bytes no tiene viñeta en el checklist de T2**, y la sección *Gates* nunca
      dice «no se corrieron, y por qué» — lo importante está bien (no reclama verde falso), falta la frase.
- [x] [Review][Patch] **Prosa de T1 en pie y citas desplazadas por mi propio diff** — «No entra en este PR: T2…»
      sigue ahí, la *File List* no lista ningún fichero de T2, y las tres citas `restore-prod.sh:89-92` pasaron
      a `:90-93` al bajar el comentario de lectura completa.
- [x] [Review][Patch] **El banner de sección quedó un bloque por encima de lo que nombra** — efecto colateral
      de mi propio movimiento boy-scout. [scripts/deploy/restore-prod.sh:89]
- [x] [Review][Patch] **Cuatro precisiones del runbook**: `ls` falla en un host limpio y no hay nota de permisos
      (el directorio es 700); el barrido esquiva el `flock` que ambos scripts consideran obligatorio; el párrafo
      del offsite confunde el archivo con el dump que el barrido no toca; y el glob es más ancho que la forma
      del stamp. [docs/vps-deployment.md:224-254]
- [x] [Review][Patch] **Tras el barrido, el aviso de restore que la sección defiende deja de dispararse**, y
      «Abort now» no tiene punto de aborto en el camino `RESTORE_YES=1`. [scripts/deploy/restore-prod.sh:90-93]
- [x] [Review][Defer] **Un solo marcador de sprint no puede representar cuatro tareas que salen por separado**
      — `bmad.status.audit` queda rojo con una prescripción equivocada. El fichero ya tiene el precedente
      (`br-4b`, `br-4c` con clave propia); partir `br-8` es cambio de la historia, no de esta PR.

**Resultado.** Los diecinueve `patch` aplicados; el `defer` intacto. Dos cambian lo que el PR *significa*, no
sólo cómo está escrito:

1. **`pwd -P`.** `find` es `-P` por defecto y **no desciende** un enlace simbólico pasado como punto de
   partida; `cd … && pwd` es lógico y no lo resuelve. Medido: 1 coincidencia por la ruta real, **0** por el
   enlace. Mover `BACKUP_DIR` a otro disco —la maniobra de operación más común que hay— dejaba la retención
   muda para siempre, reportando éxito, hasta que el preflight de espacio empezara a abortar backups días
   después. Es un defecto **preexistente** que mi runbook heredaba; se arregla en los dos scripts.
2. **La revisión de seguridad estaba invertida.** Decía que «borra menos» es la dirección segura. Para un
   control de retención sobre datos personales no lo es: el archivo de objetos lleva la misma PII que el dump,
   así que retirar su única expiración automática no es tranquilidad, es una **obligación de retención que
   pasa a manos de una persona**. De ahí el aviso en cada ejecución, la sección del runbook que ya no se llama
   *one-off*, y la entrada **sin marcar** en `PRODUCTION_SECURITY_CHECKLIST.md`, que se cierra cuando el
   despliegue haya aterrizado y el barrido se haya corrido dos veces.

**Y una corrección que salió del repositorio.** Le dije al autor de #256 que su frase sobre la guarda de
volumen era falsa. Era **cierta cuando la escribió** (`b0dce6ef:lib/common.sh:36-40`, `docker volume inspect`
con su propio `exit 1`) y la borró #557. M5 de este mismo documento lo tenía medido veinte líneas más arriba
del sitio donde escribí lo contrario. El issue lleva ya la corrección de la corrección, con el commit citado.
