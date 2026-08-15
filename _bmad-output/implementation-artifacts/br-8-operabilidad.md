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
| **M7** | **El código ya reconoce el hueco.** `restore-prod.sh:92-95` avisa: *«`$STAMP` carries `objects-$STAMP.tar.gz`, an object archive this restore does not unpack»* (`:93`) *«The recovery point will be database-only. Abort now if that archive still matters.»* (`:94`). Lo añadió `08f8199b` — **el mismo commit que borró el productor**. |

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

Ninguna se toma en esta historia.

| # | Decisión | Lo que la medición aporta |
|---|---|---|
| **DEC-1** · #255/#256 | ¿Vuelve el archivado de objetos, o se cierran ambos con evidencia y se retira lo que quedó a medias? | La superficie se retiró deliberadamente (#557) y `restore-prod.sh:92-95` ya lleva el aviso. **Sub-decisión obligatoria**: `backup-prod.sh:90` es el único expirador de los `objects-*.tar.gz` de despliegues reales entre #253 y #557 (M3); borrarlo sin más los inmortaliza. |
| **DEC-2** · #612 | Decorador de transporte (opción 1 del issue) vs `default_socket_timeout` global (opción 2). | El decorador está verificado alcanzable y suficiente **para `smtp`/`smtps`** (M15), y **sólo** para eso (M15b) — la opción 2 no tiene ese punto ciego, a cambio de un radio global. El radio real son seis casos de uso, cuatro seams y **dos** superficies de worker (M9, M9b). |
| **DEC-3** · #261 | ¿`wontfix` (seguir en Option A) o instalar `symfony/lock`? | `compose.prod.yaml:180-186` ya escribió el argumento a favor de A **después** de abrirse el issue, y el código lo sostiene (ninguna schedule `->lock()`). Son ocho ticks, no uno (M10). Suelo técnico: `symfony/lock >= 7.4` por el `conflict` de messenger (M11). |
| **DEC-4** · #525 | Ventana de retención de `failed`, borrado automático vs herramienta de operador, y SLA de triage. | `failed` es durable **a propósito**; podarlo cambia el contrato. Es la única tabla hermana sin retención (M12), y el pruner tendría que convivir con una alarma **horaria**. |
| **DEC-5** · #526 | ~~Regla en Sentry vs chequeo in-app.~~ **La medición la colapsa**: la opción 1 exige revertir una decisión registrada dos veces (M14). Lo que queda por decidir es el **umbral** y de dónde sale el contador. | `ReportDeadLetterBacklogHandler` es el precedente enviado de esta forma exacta, elegido por este mismo motivo. |

---

## Alcance

- [ ] **T1 — #612: acotar el socket del mailer** (API) — *sólo bloqueado por DEC-2*
  - [ ] **El arreglo va en el transporte, no en los llamantes** (M15): decorar `EsmtpTransportFactory` para
        llamar a `SocketStream::setTimeout()`. Clase nueva en `Infrastructure/` (`Shared/Mailer/Infrastructure/`,
        donde ya viven `SecurityLinkMailer` y `PlainTextNotificationMailer`) — **nunca en `Domain/`**, que
        deptrac le prohíbe importar Symfony Mailer (`deptrac.yaml:190-195`, `:211-215`).
  - [ ] **Declarar el punto ciego en vez de esconderlo** (M15b): la cota vale para `smtp`/`smtps`; un DSN de
        bridge o `sendmail://` la deja sin efecto. Si eso no es aceptable, es DEC-2 otra vez.
  - [ ] Radio a cubrir, que el issue no enumera: **dos** superficies de worker además de la petición — el
        `scheduler_worker` de una réplica (`NotifyLockedIdentities`, tick de 5 min) y el `messenger_worker`
        del `async` (`SendEmailOnBankChanged`), este último **sin** la guarda de `DeliverableSecurityTransport`.
  - [ ] Actualizar #612 con M8/M9/M9b/M15 **antes** de implementar: su premisa de latencia ya no describe el
        repositorio, y su «both call sites» era inexacto el día que se escribió.
- [ ] **T2 — DEC-1 · #255/#256: reconciliar el backup con la superficie que existe**
  - [ ] **No borrar el `-name 'objects-*.tar.gz'` de `:90` sin resolver antes los archivos de legado** (M3).
  - [ ] Corregir en ambos issues la **deriva de nombre** `object_storage_data` → `storage_data` — **la
        dependencia a #252 es correcta y se mantiene** (M5).
  - [ ] Corregir el checklist de #256 a sus pasos ejecutables; el paso 5 nombra además un `pg_restore -l` que
        el `verify_dump` de hoy no usa (M6).
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
- [ ] **T4 — DEC-5 · #526: alerta sobre el pico de warnings** (umbral por decidir)
  - [ ] Espejo de `ReportDeadLetterBacklogHandler`: silencioso salvo breach, `error(...)` estructurado.
  - [ ] **No reenviar el mensaje del `Throwable` sin acotarlo** (M13): las claves están limpias, el mensaje no
        lo garantiza este repo.
- [ ] **T5 — DEC-3 · #261**: implementar Option B, o cerrar `wontfix` con el argumento de `compose.prod.yaml:180-186`.
- [ ] **T6 — verificación completa** (ver *Gates*)
- [ ] **T7 — segundo pase adversarial** sobre el código que se escriba, antes de la PR que lo lleve.
- [ ] **T8 — cierres con evidencia** para cada issue que cierre sin código

---

## Falsificación — cada cláusula tiene su rojo

**Regla del lote, heredada de BR-7: una aserción que pasa no prueba nada hasta que la has visto fallar.**

- **#612** — el observable no es «el mailer tiene timeout», es **«un SMTP que no responde deja de bloquear más
  de N segundos»**. Falsable con un socket que acepta y calla. **Dos rojos obligatorios que M15b nombra**: que
  el `instanceof SocketStream` del decorador no esté no-opeando en silencio, y que un DSN que no sea
  `smtp`/`smtps` quede fuera de la cota **de forma declarada**, no accidental.
- **#525** — si se poda: rojo de la ventana (una fila justo dentro y otra justo fuera) y rojo del orden de
  borrado, con la disciplina de `DbalAuditLogPruner`, no la del pruner desnudo.
- **#526** — el rojo es **el umbral**: por debajo no emite, por encima sí.
- **Siembra no vacua** — cualquier aserción de ausencia afirma primero que la siembra insertó N filas.

---

## Gates

`make php.quality`, `make php.stan` sobre cada fichero PHP tocado, `make app.test`. Si T3/T5 tocan schedules o
transportes, además `make php.lint.schedule-consumption` (`make/php-quality.mk:217-220`; tres clases de gate
bajo `api/tests/Unit/Shared/Architecture/`, cableado en `php.quality` y en `php.quality.dry-run`). Lee los
compose de raíz por el bind mount de sólo lectura y **falla en vez de saltar** cuando no está —
`ScheduleConsumptionGateTest.php:165-180` llama a `$this->fail(...)`, y no hay ningún `markTestSkipped`.

---

## Fuera de alcance

- **`event_store`** — append-only permanente por diseño; #525 lo excluye explícitamente.
- **La ventana de graceful-shutdown** para que `kernel.terminate` llegue a ejecutarse. #526 la nombra como el
  **vector dominante** de pérdida de `activity` y dice que no se ataca con observabilidad del writer.
- **Restaurar la superficie de objetos** (#557). Si DEC-1 va por ahí, es PR propia con su autorización.
- **Cablear el puente Monolog→Sentry.** Revertir la decisión de `sentry.yaml:14-17` es su propia discusión.

---

## Pase adversarial

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

1. **DEC-1 desbloquea más trabajo que ninguna**, y ahora tiene una sub-pregunta con consecuencia operativa:
   ¿qué pasa con los `objects-*.tar.gz` de despliegues reales que `backup-prod.sh:90` es lo único en expirar?
2. #612 subió de latente a vivo por un commit propio de esta épica, y su radio son dos superficies de worker.
   ¿Se queda en BR-8 o sale a PR propia por ser el único con consecuencia de disponibilidad?
3. ¿Se toman DEC-2/3/4/5 ahora, o BR-8 entrega T1+T2 y el resto se registra para un lote posterior?

## Dev Agent Record

### Agent Model Used

claude-opus-5[1m]

### Debug Log References

### Completion Notes List

### File List
