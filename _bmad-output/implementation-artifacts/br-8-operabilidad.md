---
baseline_commit: a4af085fe685720663e3264b4369305b0dcf8d9f
---

# Story BR-8: Operabilidad — lo que se nota en producción y no en los tests

Status: ready-for-dev

> Épica: [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) · Lote BR-8 · Issues #255 #256 #261 #525 #526 #612
> Rama: `chore/operability-hardening-3ebd` · Worktree: `.claude/worktrees/operability-hardening-3ebd` · Base: `main` @ `a4af085f`
> **Último lote de la épica.** Al cerrarlo toca el criterio de cierre: barrer el backlog otra vez contra `main`.
> **Cuatro de los seis issues contienen una decisión que su propio texto declara no-automatizable.** Este documento las plantea; NO las resuelve.

---

## Lo que la medición refutó

El lote entró descrito como «backup, retención del transporte `failed`, alarmas de escritura de auditoría, y
la cota de socket del mailer». Medido contra `a4af085f`:

**Dos issues describen una superficie que el repositorio no tiene, y uno describe como latente un defecto cuya
precondición se cayó hace tres días — la cayó un commit de esta misma épica.**

| # | Afirmación heredada | Medición contra `a4af085f` |
|---|---|---|
| **M1** | #255/#256: el backup produce un **par** `db-<stamp>.dump` + `objects-<stamp>.tar.gz` | **No hay par.** `backup-prod.sh` produce **un solo artefacto**: dump (`:79`), verificación (`:82`), retención (`:87-90`), `ls -lh "$db_file"` (`:93`), sync opcional (`:97-101`). No existe paso de archivado de objetos. |
| **M2** | #255: «el paso de archivo de objetos usa un `alpine` sin tag» (P9) | **Ese paso no existe.** No hay `tar`, ni `docker run`, ni `alpine` en el script. P9 es un apartado sobre código ausente. |
| **M3** | #255: riesgo de par desemparejado por retención con dos `find -mtime` (P3) | **Inalcanzable.** La retención (`:90`) sí barre `-name 'objects-*.tar.gz'`, pero **nada los crea**: es un patrón muerto que busca ficheros que ningún productor emite. Sin segundo artefacto no hay par que pueda desemparejarse. |
| **M4** | #255: «loguear el tamaño del artefacto» es trabajo pendiente | **La mitad viva ya está hecha.** `:93` es `ls -lh "$db_file"` — tamaño del único artefacto que existe. Lo que falta del apartado de observabilidad es el *ratio de completitud de pares*, y eso es M1. |
| **M5** | #255/#256: «Depends on #252 — el volumen `object_storage_data` debe existir, o `make backup.prod` aborta en su guarda de volumen-existe» | **Falso en las tres cláusulas.** (a) `git log -S'object_storage_data'` sobre **todo** el historial devuelve **cero** commits: el literal nunca se escribió en este repo. (b) No hay ninguna guarda de volumen-existe en `backup-prod.sh`. (c) **#252 no es ese issue**: es un PR MERGED, «fix(shared): senior-eyes correctness review — surface silently swallowed failures». La dependencia apunta a un número que no es lo que la nota dice. |
| **M6** | #256: checklist de drill de 8 pasos, ejecutable | **Tres pasos son inejecutables** — sembrar un `stored_object`, confirmar que el par comparte `<stamp>`, y verificar el objeto sembrado tras el restore. La superficie de objetos se retiró en `08f8199b` («chore(api): remove the image upload surface», #557): no existe `api/src/Shared/Storage` ni ningún `StoredObject` en `api/src`. |
| **M7** | El desemparejamiento es teórico y nadie lo ha visto | **Ya está reconocido en el código.** `restore-prod.sh:92-93` avisa: *«`$STAMP` carries `objects-$STAMP.tar.gz`, an object archive this restore does not unpack»*. Alguien vio el hueco y dejó la defensa; lo que no se hizo fue reconciliar los issues con ella. |
| **M8** | #612: el hang del mailer es **latente, no vivo**, porque «`MAILER_DSN` es `null://null` en `compose.yaml` **y** `compose.prod.yaml`; ninguna configuración del repositorio apunta a un SMTP remoto» | **La premisa se cayó, y el propio issue definió el disparador.** El issue midió sobre `f4dbe4d1` (2026-07-30), donde `compose.prod.yaml` decía `${MAILER_DSN:-null://null}`. El **2026-08-11**, `0fc48fff` (BR-4b / #602 / #683) lo cambió a `${MAILER_DSN:?MAILER_DSN is required in prod (null://null discards mail silently)}` en sus **tres** servicios (`:46`, `:148`, `:228`), y `.env.prod.example:70` propone `smtp://user:pass@smtp.example.com:587`. **Prod ya no puede arrancar sin un DSN, y el ejemplo que se copia es remoto.** El tripwire que el issue escribió —*«the moment `MAILER_DSN` points at a real remote SMTP server»*— es exactamente esto. |
| **M9** | #612: «This issue covers **both** call sites. Fixing one is half a mitigation» | **Hoy son tres, y el tercero no está en el issue.** El mismo `0fc48fff` añadió `NotifyLockedIdentitiesMessage`, un tick de scheduler **cada cinco minutos** que manda correo a una persona. `compose.prod.yaml:227-228` lo dice sin rodeos: *«Required for the same reason as on php: this is the worker that sends the lockout notice»*. Un SMTP colgado ya no cuesta sólo latencia en una petición: cuelga un worker de **una sola réplica** cuyo periodo de tick es 5 min y cuyo bloqueo por defecto es 60 s. |
| **M15** | #612: «This issue covers both **call sites**» — el issue razona el defecto enumerando llamantes | **Enumerar llamantes es la forma equivocada de diseñar este arreglo, y por eso el conteo del issue no cierra nunca.** El timeout no vive en el llamante: vive en el **transporte** (`EsmtpTransportFactory` no lee `timeout`; `SocketStream::getTimeout()` cae a `default_socket_timeout` = 60 s). Un decorador de la factory cubre **todos** los remitentes de una vez. Medidos hoy hay como mínimo cuatro seams de envío —`Shared/Mailer/Infrastructure/SecurityLinkMailer.php` (el compartido), `Iam/Identity/Infrastructure/Mail/Symfony{PasswordChanged,AccountLocked}EmailSender.php` e `Iam/Invitation/Infrastructure/Mail/SymfonyInvitationEmailSender.php`— y siete casos de uso que los llaman. **El conteo importa para el radio de impacto, no para el diseño**; «fixing one is half a mitigation» describe un arreglo por llamante que nadie debería escribir. |
| **M10** | #261: Option B (lockear el schedule y disolver `scheduler_worker`) es un trade limpio dependencia-contra-servicio | **El coste de equivocarse cambió de naturaleza, y el repo ya lo argumentó.** `compose.prod.yaml:180-186`: *«THE STAKES OF THE PIN CHANGED with the lockout notice… This one SENDS MAIL TO A PERSON, and it sends before it stamps the suppression window… Two clocks would therefore race send-then-stamp and deliver the notice twice, at someone whose account an attacker is already driving… this replica pin is the only thing standing between one notice and N.»* El issue enumera **un** tick (`PruneHandledDomainEvents`); hoy son **seis** (`:174-178`). |
| **M11** | #261: «`symfony/lock` no está instalado» | **Cierto, y verificado en las tres direcciones.** No está en `api/composer.json` require, no existe `api/vendor/symfony/lock`, y `rate_limiter.yaml:17-23` declara el trade-off por escrito (`lock_factory: null` × 5 budgets). Sólo aparece en `composer.lock` como dependencia *opcional declarada por otros paquetes*, nunca instalada. |
| **M12** | #525: el transporte `failed` no tiene retención | **Cierto, sin matices.** `api/src/Shared/Event/Infrastructure/Messenger/Maintenance/` contiene `HandledDomainEventMaintenanceSchedule`, `PruneHandledDomainEvents{Handler,Message}` y `ReportDeadLetterBacklog{Handler,Message}`. **No hay ningún pruner de `failed`**; lo único que lo toca es `Cli/FailedMessagesStatusCommand.php`, que sólo lee. |
| **M13** | #526: el warning existe y nadie lo agrega | **Cierto.** `SymfonyAuditLogger.php:83-91` — `try { … } catch (Throwable) { warning('Failed to record an activity audit entry.', ['action','level','exception']) }`, sin PII. |
| **M14** | #526 opción 1: «regla de alerta en Sentry, cero código de app» | **No es cero, y la nota que gobierna el cableado está rancia.** `sentry/sentry-symfony ^5.11.0` **sí** está instalado y `SentryBundle` registrado en dev+prod (`bundles.php:16`), pero `monolog.yaml:90` sigue diciendo *«Sentry wiring — uncomment when sentry/sentry-symfony is installed»* sobre un bloque **comentado** con `action_level: error`. Que un `warning` llegue hoy a Sentry depende de la integración por defecto del bundle, no de esa configuración — **está sin medir, y medirlo es el primer paso de #526, no un detalle**. |

---

## Lo que esto le hace al lote

BR-8 entró en la épica como «lo que se nota en producción y no en los tests». Sigue siéndolo, pero la
medición lo parte en tres grupos con naturaleza distinta, y **sólo uno de ellos es trabajo de escribir código
ahora**:

1. **Prosa podrida sobre superficie retirada** — #255 y #256. No se arreglan: se **corrigen o se cierran con
   evidencia**. Su contenido vivo es minúsculo (M4) y su contenido muerto es la mayoría (M1, M2, M3, M5, M6).
2. **Un defecto que dejó de ser latente** — #612, ascendido por M8 y agravado por M9. Es lo único del lote con
   consecuencia de disponibilidad **hoy**, y llegó ahí por un cambio de esta misma épica.
3. **Tres decisiones abiertas** — #261, #525, #526. Los tres issues dicen explícitamente que la decisión no es
   del implementador (#261 *«Decision needed… this can be `wontfix`»*; #525 *«Decisión pendiente (por eso es
   issue, no fix-en-caliente)»*; #526 *«dos formas posibles (elegir según cómo alarme el stack)»*).

---

## Decisiones que Sergio debe tomar antes de T2 en adelante

Ninguna se toma en esta historia. Cada una lleva su argumento medido y sus descartes.

| # | Decisión | Lo que la medición aporta que el issue no sabía |
|---|---|---|
| **DEC-1** · #255/#256 | ¿Se **restaura** el archivado de objetos, o se **retiran** las dos mitades muertas (el `objects-*` de la retención y el checklist de par de #256) y se cierran ambos issues con evidencia? | La superficie de objetos se retiró deliberadamente (#557) y hay un ADR-de-hecho en `restore-prod.sh:92-93`. Restaurarla sería reabrir una decisión cerrada; retirarla es terminar una migración a medias. |
| **DEC-2** · #612 | Opción 1 (decorador que llama a `SocketStream::setTimeout()`) vs opción 2 (`default_socket_timeout` global). El issue prefiere la 1. | Ahora hay **tres** call sites, no dos (M9), y el tercero corre en un worker de una réplica. El radio de la opción 2 crece en consecuencia. |
| **DEC-3** · #261 | ¿`wontfix` (quedarse en Option A) o instalar `symfony/lock`? | El propio `compose.prod.yaml:180-195` ya escribió el argumento a favor de Option A **después** de abrirse el issue, y lo llama *«tracked as a follow-up»* — que es este issue. Un lock mal puesto ya no cuesta un barrido redundante: cuesta una segunda notificación de seguridad a alguien bajo ataque. |
| **DEC-4** · #525 | Ventana de retención de `failed`, borrado automático vs herramienta de operador, y SLA de triage. | `failed` es durable **a propósito**. Podarlo cambia el contrato. La única tabla hermana sin retención es ésta (M12). |
| **DEC-5** · #526 | Regla en Sentry vs chequeo in-app con umbral (espejo de `ReportDeadLetterBacklogHandler`). | La opción 1 no es «cero código» hasta que se mida si un `warning` llega hoy a Sentry (M14), y hay una nota rancia en `monolog.yaml:90` que hay que reconciliar en cualquiera de los dos caminos. |

---

## Alcance

Orden deliberado: primero lo que ya es un defecto vivo, luego la prosa podrida, y las decisiones sólo cuando
estén tomadas.

- [ ] **T1 — #612: acotar el socket del mailer** (API) — *no bloqueado por ninguna decisión salvo DEC-2*
  - [ ] **El arreglo va en el transporte, no en los llamantes** (M15): decorar `EsmtpTransportFactory` para
        llamar a `SocketStream::setTimeout()` con un valor configurado. Clase nueva en `Infrastructure/`
        (`Shared/Mailer/Infrastructure/` es donde ya vive `SecurityLinkMailer`) — **nunca en `Domain/`**, que
        no puede importar Symfony Mailer.
  - [ ] **No escribir un arreglo por call site.** El radio medido es de cuatro seams de envío y siete casos de
        uso; un decorador de transporte los cubre todos y ninguno de ellos debe conocer el timeout.
  - [ ] Radio a verificar tras el cambio, porque es el que el issue no conoce: la ruta del scheduler
        (`Iam/Identity/Application/NotifyLockedIdentities.php`, disparada por
        `Infrastructure/Messenger/Maintenance/NotifyLockedIdentitiesHandler.php` bajo
        `IdentityMaintenanceSchedule`, tick de 5 min) corre en el `scheduler_worker` de **una sola réplica**:
        ahí un bloqueo de 60 s por identidad no cuesta una petición lenta, cuesta el reloj.
  - [ ] Actualizar #612 con la medición de M8/M9/M15 **antes** de implementar: su sección «Why it is not fixed
        now» ya no describe el repositorio, y su «both call sites» nunca fue la forma del arreglo.
- [ ] **T2 — DEC-1 · #255/#256: reconciliar el backup con la superficie que existe**
  - [ ] Si se retira: quitar el `-name 'objects-*.tar.gz'` muerto de `backup-prod.sh:90`, decidir qué hacer con
        el aviso de `restore-prod.sh:92-93`, y corregir el checklist de #256 a los cinco pasos ejecutables.
  - [ ] Corregir la dependencia falsa «#252 / `object_storage_data`» en ambos issues (M5).
- [ ] **T3 — DEC-4 · #525: retención de `failed`** (sólo tras la decisión)
  - [ ] **Reutilizar, no reinventar.** El patrón es `Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php`
        y su hermano `Shared/Event/Infrastructure/Messenger/DbalHandledDomainEventPruner.php`; el schedule al
        que colgarlo es `Shared/Event/Infrastructure/Messenger/Maintenance/HandledDomainEventMaintenanceSchedule.php`.
        El lector existente es `MessengerDeadLetterReader`, y la alarma con la que debe convivir es
        `Maintenance/ReportDeadLetterBacklogHandler.php`.
  - [ ] Un `#[AsSchedule]` nuevo obliga a añadir su transporte al `messenger:consume` de **`compose.yaml`
        (dev) y `compose.prod.yaml`** — sin eso se despliega muerto con todos los gates en verde.
- [ ] **T4 — DEC-5 · #526: alerta sobre el pico de warnings** (sólo tras la decisión)
  - [ ] Medir primero si un `warning` llega hoy a Sentry; reconciliar la nota rancia de `monolog.yaml:90`.
- [ ] **T5 — DEC-3 · #261**: implementar Option B, o cerrar `wontfix` con el argumento de `compose.prod.yaml:180-195`.
- [ ] **T6 — verificación completa** (ver *Gates*)
- [ ] **T7 — pase adversarial**, registrado en esta historia **antes** de `gh pr create`
- [ ] **T8 — cierres con evidencia** para cada issue que cierre sin código

---

## Falsificación — cada cláusula tiene su rojo

**Regla del lote, heredada de BR-7: una aserción que pasa no prueba nada hasta que la has visto fallar.**

- **#612** — el observable no es «el mailer tiene timeout», es **«un SMTP que no responde deja de bloquear más
  de N segundos»**. Falsable con un socket que acepta y calla. Los tres call sites necesitan su rojo por
  separado: cubrir uno y declarar el lote es exactamente el «half a mitigation» que el issue nombra.
- **#525** — si se poda: provocar el rojo de la ventana (una fila justo dentro y otra justo fuera) y el del
  orden de borrado. Precedente obligatorio: el `DELETE` de `audit_log` va con `ORDER BY id … FOR UPDATE` y
  está pinchado **dos veces** (lectura de fuente + plan real de Postgres). Un pruner de `failed` que ignore
  esa disciplina repite un defecto ya pagado.
- **#526** — el rojo es **el umbral**, no el log: afirmar que por debajo no emite y por encima sí.
- **Siembra no vacua** — cualquier aserción de ausencia afirma primero que la siembra insertó N filas. Es la
  contramedida de los dos tests vacuos de G-1b/G-5 y es action item cerrado de la retro de gdpr-hardening.

---

## Gates

`make php.quality`, `make php.stan` sobre cada fichero PHP tocado, `make app.test`. Si T3/T5 tocan schedules o
transportes, además `make php.lint.schedule-consumption` — un `#[AsSchedule]` cuyo transporte nadie consume
**compila, registra y se despliega muerto con todos los demás gates en verde**, y este lote toca justo esa
zona. Si T5 disuelve `scheduler_worker`, ese gate lee los compose de raíz por el bind mount de sólo lectura y
**falla en vez de saltar** cuando el mount no está.

---

## Fuera de alcance

- **`event_store`** — append-only permanente por diseño; #525 lo excluye explícitamente.
- **La ventana de graceful-shutdown** para que `kernel.terminate` llegue a ejecutarse. #526 la nombra como el
  **vector dominante** de pérdida de `activity` y dice que no se ataca con observabilidad del writer. No es
  este lote.
- **Restaurar la superficie de objetos** (#557). Si DEC-1 va por ahí, es PR propia con su autorización.

---

## Riesgo de colisión — otra sesión está trabajando en este repo

Durante la creación de esta historia `main` avanzó seis commits (`d07ba35f` → `a4af085f`) y apareció el
worktree `docs/advisory-lock-key-space-r17t`, con `3c689e50 docs(shared): state the advisory lock's real
32-bit key space (DF-1) (#372)`. **Toca el espacio de claves de advisory lock**, que es exactamente el
mecanismo que la propuesta de #525 usaría («borrado en lotes bajo advisory lock») y vecino de DEC-3.
Reconciliar antes de escribir T3/T5.

---

## Preguntas

1. **DEC-1 es la que desbloquea más trabajo**: ¿retiramos las mitades muertas del backup, o el archivado de
   objetos vuelve? Todo #255 y medio #256 dependen de la respuesta.
2. #612 subió de latente a vivo por un commit propio de esta épica. ¿Se queda en BR-8 o sale a PR propia por
   ser el único con consecuencia de disponibilidad?
3. ¿Se toman DEC-3/4/5 ahora, o BR-8 entrega T1+T2 y las tres decisiones se registran para un lote posterior?

## Dev Agent Record

### Agent Model Used

claude-opus-5[1m]

### Debug Log References

### Completion Notes List

### File List
