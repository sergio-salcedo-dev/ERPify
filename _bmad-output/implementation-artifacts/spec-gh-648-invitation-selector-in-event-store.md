---
title: 'El selector de aceptación de invitación deja de escribirse en event_store'
type: 'bugfix'
created: '2026-08-07'
status: 'in-progress'
baseline_commit: 5f7d853f
review_loop_iteration: 0
context:
  - '{project-root}/docs/adr/administrative-recovery-channel.md'
  - '{project-root}/docs/adr/event-store-and-projections.md'
  - '{project-root}/api/.persistent-transport-policy'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Los seis eventos de `Invitation` llevan el id de la invitación como `aggregateId`, y ese id **es** la mitad selector del enlace de aceptación (`<invitationId>.<secret>`), así que queda escrito para siempre en `event_store.aggregate_id`. Incumple el corolario I-1 de `administrative-recovery-channel.md`; el flujo hermano de reset ya lo hace bien (`PasswordResetRequested($userId)`). Aparte, los dos comandos CLI imprimen el token completo en stdout aunque el correo se haya entregado.

**Approach:** El `aggregateId` de los seis pasa a ser el `invitedUserId` manteniendo `aggregateType() === 'Iam.Invitation'`; el payload queda vacío y `eventVersion()` sube a `2`. En el CLI, el wrapper best-effort pasa a reportar si el mailer **aceptó el envío sin lanzar**, y los comandos imprimen el token solo bajo `--show-token` o cuando ese envío falló. **No se migran ni reescriben filas históricas de `event_store`:** el contrato nuevo rige las publicaciones nuevas.

## Boundaries & Constraints

**Always:**
- `aggregateType()` sigue siendo `'Iam.Invitation'` — `aggregate_type` nombra la familia/ciclo de vida, `aggregate_id` nombra al sujeto.
- El conjunto cubierto se **descubre** (las clases concretas que usan `CarriesInvitationSnapshot`), nunca se enumera a mano: un séptimo evento queda cubierto sin tocar el test.
- El token se imprime **siempre** que el envío falló, mande el flag lo que mande, con aviso explícito de fallback fuera de banda. Misma corrección en los dos comandos.
- El puerto `InvitationEmailSender::send(): void` no cambia; solo reporta el wrapper concreto, y su contrato se nombra por lo que sabe (**el mailer aceptó el envío sin lanzar**), nunca «entregado».
- El retorno de `invite()`/`resend()` es un objeto inmutable con **dos propiedades nombradas** (token de aceptación, estado del envío); nunca array, tupla ni estructura posicional.
- Sin ids de issue/historia ni comentarios relativos al cambio.
- Ejecución: PR normal (**nunca** `--draft`), parar tras crearla, **no** hacer merge.

**Ask First:**
- Enrutar eventos de `Invitation` en `messenger.yaml`.
- Editar `g-5-ids-de-persona-fuera-del-event-store.md` (`status: done`), cuya línea 54 afirma que `Iam.Invitation => non-person` acierta. Default: **no tocarlo**, anotarlo en la PR. → **Resuelto (Sergio, 2026-08-07): borrar el fichero.** Su historia está `done`, su clave sigue viva en `sprint-status.yaml` y la retro de la épica conserva el registro; ningún enlace Markdown apuntaba a él.
- Migrar o reescribir filas históricas de `event_store`; añadir un `Upcaster` para la v1.

**Never:**
- Tocar el invariante de versión de stream / `NULLS NOT DISTINCT` (`deferred-work.md:30-33` es su dueño).
- Tocar `PRODUCTION_SECURITY_CHECKLIST.md:522-535` (medición separada).
- Cambiar `aggregateType()` a `Iam.Identity`, ni introducir un selector con columna propia (opción B, descartada).
- Aceptar en silencio la forma v1 del payload.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Envelope de los 6 | cada evento recién construido | `aggregateId === invitedUserId`, `!== $invitation->id()`; `toPrimitives() === []`; `aggregateType() === 'Iam.Invitation'`; `eventVersion() === 2` | N/A |
| Round-trip v2 | `fromPrimitives($userId, [], …)` | reconstruye; `invitedUserId() === $userId` | N/A |
| Fila v1 histórica | fila con `event_version = 1` | `DomainEventDeserializer` **falla ruidosamente**: el mapper no registra `(eventName, 1)` | `InvalidArgumentException`, no rehidratación silenciosa |
| CLI, envío aceptado, sin flag | `iam:invitation:create` | exit `0`, **no** imprime el token | N/A |
| CLI, envío aceptado, con flag | `--show-token` | exit `0`, imprime el token | N/A |
| CLI, envío falla, sin flag | mailer lanza | exit `0`, aviso de fallo de envío **e** imprime el token como fallback | excepción absorbida y logueada por el wrapper; no aborta |
| CLI, envío falla, con flag | mailer lanza | exit `0`, mismo aviso, imprime el token | ídem |

</frozen-after-approval>

## Code Map

- `Iam/Invitation/Domain/Event/CarriesInvitationSnapshot.php` -- envelope compartido; el cambio nuclear
- `Iam/Invitation/Domain/Entity/Invitation.php` -- 6 call sites (93, 106, 118, 129, 142, 160)
- `Iam/Identity/Domain/Event/PasswordResetRequested.php` -- la forma objetivo
- `Shared/Event/{Application/Upcaster.php, Infrastructure/Mapper/ReflectionDomainEventMapper.php}` -- por qué el bump de versión basta para rechazar la v1
- `api/.persistent-transport-policy` -- :55-57, clasificación + comentario
- `Iam/Invitation/Application/{SendInvitationEmailBestEffort,SendInvitation,ResendInvitation}.php`
- `Iam/Invitation/Infrastructure/Cli/{Create,Resend}InvitationCommand.php`
- `Iam/Invitation/Infrastructure/Http/CreateInvitationController.php:64` -- **descarta** el retorno como sentencia; con el tipo nuevo sigue compilando, así que la expectativa es **no tocarlo**
- `tests/Unit/Iam/Invitation/**` · `features/backoffice/identity/invitation_{accept,create,revoke}.feature`

## Tasks & Acceptance

**Execution:**
- [x] `CarriesInvitationSnapshot.php` -- constructor a `(string $invitedUserId, ?string $eventId = null, ?DateTimeImmutable $occurredOn = null)`; `toPrimitives()` → `[]`; `fromPrimitives()` desde `$aggregateId`, **ignorando `$body`**; `invitedUserId()` devuelve `aggregateId()`; retirar la propiedad redundante; `eventVersion()` → `2`
- [x] `Invitation.php` -- adaptar los 6 call sites
- [x] `api/.persistent-transport-policy` -- `Iam.Invitation => person` **y reescribir el comentario de :55-56**, que debe dejar inequívoco por qué `person` es correcto **aunque el tipo siga siendo `Iam.Invitation`** (el eje que clasifica es `aggregate_id`, y pasa a denotar a la persona), para que nadie lo «corrija» de vuelta
- [x] **Test nuevo** (`InvitationEnvelopeTest` o equivalente) -- **descubre** las clases que usan el trait y, para cada una, afirma: `aggregateId === invitedUserId`, `aggregateId !== invitationId`, `toPrimitives() === []`, que el id de invitación no aparece por ninguna ruta del payload serializado, `aggregateType() === 'Iam.Invitation'` y `eventVersion() === 2`
- [x] **Falsificación del gate** -- respaldar los bytes; revertir un call site para reintroducir `Invitation::$id` como `aggregateId`; correr el test y **registrar al menos un fallo atribuible al control nuevo**; restaurar copiando los bytes respaldados (nunca `git checkout --`); volver a correrlo en verde. Sin ese ciclo el control no está probado como causal
- [x] `InvitationTest` · `AcceptInvitationTest` · las 3 features -- ajustar aserciones sobre el aggregate id
- [x] `SendInvitationEmailBestEffort.php` -- `send()` reporta si el mailer aceptó el envío sin lanzar; el nombre del método/valor no debe afirmar entrega efectiva
- [x] `SendInvitation.php` · `ResendInvitation.php` -- devolver el objeto inmutable de dos propiedades nombradas (`AcceptedInvitation` ya está tomado en ese namespace)
- [x] `{Create,Resend}InvitationCommand.php` -- `--show-token` (default off) + la tabla de verdad de 4 filas de la matriz + aviso explícito de fallback en fallo
- [x] `CreateInvitationController.php` -- **verificar** que no requiere cambio (sentencia que descarta el retorno); si PHPStan exige algo, hacer el mínimo y decir cuál
- [x] Tests de CLI y casos de uso -- cubrir las 4 filas del CLI incluido el exit code; salida vía `CommandTester` (`SHELL_VERBOSITY=-1` silencia `Application::run()`)
- [x] `administrative-recovery-channel.md` (:66-68) · `event-store-and-projections.md` (D12) -- enmiendas fechadas: el residuo se cierra, el payload deja de llevar el id, y **las filas históricas no se migran**
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` -- **medir primero** qué entrada cubre esta superficie y qué afirmación suya deja de ser cierta; actualizar esa entrada. No inventar contenido
- [x] `deferred-work.md` (:30-33) -- **añadir**, no resolver: el `aggregateId` que gobierna el versionado pasa a ser `invitedUserId`, mientras el lock que protege la publicación sigue siendo el de la fila `Invitation` — divergencia a heredar cuando se active el versionado real

**Acceptance Criteria:**
- Dado un borrado de identidad, cuando corre `FulfilIdentityErasure`, entonces el borrado alcanza el `aggregate_id` de los seis eventos por el eje nuevo.
- Dado el mismo `invitedUserId`, cuando una **misma** erasure anonimiza sus eventos `Iam.Invitation` y `Iam.Identity`, entonces ambos quedan con el **mismo** valor pseudonimizado de `aggregate_id`.
- Dado que los eventos de `Invitation` no están enrutados, cuando corre `make php.lint.persistent-transport` con la clasificación en `person`, entonces el gate sigue verde.
- Dado el conjunto descubierto de eventos del trait, cuando se construye cada uno, entonces ninguno toma `Invitation::$id` como `aggregateId` y todos exponen `aggregateType() === 'Iam.Invitation'`.
- Dado este cambio, cuando se revisa el diff, entonces no contiene migración ni mutación de filas históricas de `event_store`; solo las publicaciones nuevas cumplen el contrato.

## Design Notes

**Los dos objetos de esquema son distintos y conviene nombrarlos exactos** (`EventStoreSchemaListener:58-59`): el UNIQUE es `event_store_stream_version_uniq (tenant_id, aggregate_id, aggregate_version)` y el índice de lectura es `event_store_aggregate_idx (aggregate_type, aggregate_id, sequence)`. El segundo es el que sostiene que el par tipo+id sigue siendo preciso con el tipo intacto; el primero es el que **no** afirmamos aquí, porque su efectividad está pendiente (`deferred-work.md:30-33`).

**El pseudónimo no es determinista y no debe describirse así**: `audit-activity-log.md` D4 veta por nombre tanto la derivación determinista como la tabla de correspondencia. `FulfilIdentityErasure:160-167` acuña uno aleatorio por erasure y lo comparte entre ejes — de ahí que el criterio se enuncie *dentro de la misma erasure*.

**Por qué el bump de `eventVersion()` basta para rechazar la v1 sin escribir un rechazo:** `NullUpcaster::targetVersion()` devuelve la versión almacenada tal cual y `ReflectionDomainEventMapper::classFor()` lanza ante un `(eventName, version)` no registrado. Con las clases declarando `2`, una fila v1 falla al deserializarse en vez de rehidratarse mintiendo. Es el mecanismo que `Upcaster` describe para «la primera vez que se sube una versión»; **no** se añade un upcaster, porque además no podría reparar la mitad de `aggregate_id` (solo transforma el payload) y sería una media garantía con aspecto de garantía.

## Verification

**Commands:**
- `make php.unit c='--filter InvitationEventsTest'` -- exit 0 (9 tests); es el gate nuevo, nombrado aquí para que no quede escondido dentro de la suite
- `make php.behat c='features/backoffice/identity/invitation_create.feature'` -- exit 0 (10 escenarios); el gate end-to-end contra la tabla real
- `make php.lint.persistent-transport` -- exit 0 con la clasificación en `person`
- `make php.stan` · `make php.unit` · `make php.quality` · `make php.quality.dry-run` -- exit 0
- `make php.behat` -- exit 0 (leer el exit code, no el resumen)

**Desviaciones respecto a este spec, dichas y no coladas:**

1. **El gate vive en `InvitationEventsTest`, no en un fichero nuevo.** Crear `InvitationEnvelopeTest` habría dejado dos ficheros casi gemelos sobre el mismo envelope. El filtro de `Verification` se corrigió en consecuencia.
2. **`CreateInvitationController` no se tocó** — la tarea se cerró como verificación: PHPStan pasa en verde sobre `src/` entero, así que la sentencia que descarta el retorno sigue compilando con el tipo nuevo.
3. **Dos documentos más de los listados**: `docs/deployment-guide.md` y `docs/development-guide-api.md` documentaban que el CLI imprime el enlace siempre. Con el default apagado eso deja de ser cierto, y son las instrucciones que sigue un operador aprovisionando un segundo administrador en producción.
4. **La cabecera de `.persistent-transport-policy` también se corrigió**: citaba `Invitation*` como ejemplo del hueco de payload que el gate no alcanza, y esos eventos ya no tienen payload.
5. **PHPMD exigió `@SuppressWarnings("PHPMD.UnusedFormalParameter")` sobre `$body`** en el trait. La firma la impone el padre abstracto y PHPMD no ve ese padre desde dentro de un trait (por eso `PasswordResetRequested`, que ignora `$body` igual, pasa sin supresión). Es el mecanismo que el repo ya usa en diez sitios, siempre con su razón escrita.
6. **La feature ganó dos aserciones SQL en vez de "ajustar" las suyas**: ninguna de las tres asertaba ids de agregado, así que no había nada que ajustar. Se colocaron después del presupuesto de queries para no moverlo.

**Falsificación del gate — ejecutada, con sus rojos contados:**

| # | Regresión introducida | Resultado | Control que mordió |
|---|---|---|---|
| A | `InvitationCreated` vuelve a tomar `Invitation::$id` | exit 2, 9 tests, 1 fallo | `noLifecycleEventCarriesTheInvitationIdInItsEnvelopeOrItsPayload` |
| B | `eventVersion()` vuelve a `1` | exit 2, 9 tests, 1 fallo | `everyEventSharingTheEnvelopeIsKeyedByItsSubjectAtSchemaVersionTwo` |
| D | `InvitationCreated` regresado, suite Behat | exit 2, 1 escenario rojo | la aserción SQL **positiva** |
| E | `InvitationSent` regresado (el positivo sigue verde) | exit 2, 133 pasos verdes y 1 rojo, **sin saltados** | la aserción SQL **negativa** |
| F | guarda de impresión borrada en `ResendInvitationCommand` | exit 2, 4 tests, 1 fallo | `ResendInvitationCommandTest::itKeepsTheTokenOffStdout…` |
| G | guarda de impresión borrada en `CreateInvitationCommand` | exit 2, 4 tests, 1 fallo | `CreateInvitationCommandTest::itKeepsTheTokenOffStdout…` |

E existe porque D dejó el paso negativo en `skipped` — Behat salta lo que sigue a un fallo, así que D por sí solo no probaba que la mitad negativa mordiera. F y G se añadieron **después del pase adversarial**, que midió que borrar la guarda de `resend` dejaba toda la suite en verde. Restauración siempre por copia de los bytes respaldados (`md5sum` verificado), nunca `git checkout --`.

## Pase adversarial — ejecutado 2026-08-07, hallazgos y resolución

Subagente de contexto fresco, solo lectura, sobre `git diff origin/main...HEAD` completo. **0 GRAVE, 7 SERIOUS, 7 MINOR, 2 NIT.** Un pase sin hallazgos también contaría; este los tuvo y están todos resueltos o registrados abajo.

**SERIOUS — todos corregidos en esta misma PR:**

1. **Los seis eventos seguían anunciándose «PII-free» en su docblock** — la lectura exacta que la línea nueva del registro existe para impedir. Un ingeniero que abriera `InvitationSent.php` para cablear un reactor leía «PII-free» y enrutaba. Reescritos los seis: ahora dicen que el aggregate id ES el usuario invitado y que por eso no pueden encolarse en transporte persistido.
2. **`PRODUCTION_SECURITY_CHECKLIST.md`, entrada de `event_store`** — seguía inventariando los seis `Invitation*` como portadores del id *en el payload*. Corregido al eje real, dejando escrito que las filas antiguas no se migran y que por eso el borrado casa **por valor en ambos ejes**.
3. **Tres documentos afirmaban que un upcaster es obligatorio en el primer bump de versión** (`NullUpcaster`, el puerto `Upcaster`, y `docs/architecture/event-catalog.md`, que además no se había tocado y tiene su propia regla «actualiza este fichero al versionar»). Enmendados los tres con el criterio real: upcaster cuando cambia un campo del payload; cuando cambia el **significado del `aggregate_id`**, ningún upcaster llega y lo honesto es el fallo ruidoso.
4. **`DbalEventStoreSubjectAnonymiser`** citaba `invitedUserId` en payload como prueba viva de por qué casa por texto. Reescrito: la prueba ahora son las filas históricas sin migrar, que es lo que de verdad sostiene el argumento.
5. **La mitad `resend` del cambio de seguridad no la falsificaba nada.** Medición del revisor: borrar su guarda dejaba **toda la suite en verde**. Añadido `ResendInvitationCommandTest` con las cuatro filas, más el doble `CapturingInvitationEmailSender`, y falsificadas ambas guardas (F y G arriba). Es el hallazgo más grave del pase y era una omisión mía: había falsificado la mitad de eventos con cuatro rojos y la del CLI con ninguno.
6. **`g-5-…md:54` afirmaba en negrita que `Iam.Invitation => non-person` «ACIERTA»** — era el único sitio del repo argumentando a favor de la clasificación que este cambio invierte, y por tanto una trampa para quien llegara grepeando. Elevado como `Ask First`; Sergio decidió **borrar el fichero**: historia `done`, clave viva conservada en `sprint-status.yaml`, retro de la épica intacta, ningún enlace roto.
7. **La amenaza que el cambio nombraba no es la que cierra.** El selector es la clave primaria de `iam_invitation`, así que un lector de la BD viva sigue pudiendo drenar el presupuesto con `SELECT id FROM iam_invitation WHERE status = 'SENT'`. Lo que este cambio cierra es la **permanencia**: la copia que sobrevive a la invitación y a la persona en un log sin TTL y designado superficie de exportación. Reescrito el docblock del trait para decirlo así, y para decir que cerrar el drenaje exigiría un selector acuñado aparte del aggregate id — que es la opción B, descartada.

**MINOR corregidos:** el paseo por el ciclo de vida en el gate ahora se ata al conjunto **descubierto** (`assertEqualsCanonicalizing`), así que un séptimo evento no puede quedarse fuera de la aserción de seguridad con un suelo de conteo aún en verde; las anclas `file:line` del ADR de recuperación pasadas a pretérito con su versión; docblock de clase del test funcional del CLI; radio de explosión del fallo ruidoso escrito en el ADR y en el trait; el docblock de `SendInvitation` ya no afirma «reportado» sin decir que el controller HTTP lo descarta; y comentario relativo al cambio eliminado del trait («el envelope pasó de X a Y» → «v1 era X; esto es v2»). Añadida además la aserción que faltaba sobre el **criterio de aceptación propio**: `EventStoreSubjectAnonymiserFunctionalTest` ahora siembra también la forma v2 y comprueba que el borrado la alcanza por la columna (3 filas, no 2).

**NIT corregido:** los tests de comando asertan las **dos mitades** del token por separado — una regresión que imprimiera solo el secreto pasaba la comprobación anterior, y el secreto es justo la mitad que un lector de la BD no puede obtener.

**Negativos registrados (ataques que NO encontraron nada):** ningún routing nuevo ni `TransportNamesStamp`; ninguna vía nueva de fuga a log, URI, respuesta o email (el token viaja en el cuerpo JSON, con `#[SensitiveParameter]`); ningún hueco de borrado nuevo (todo camino que publica exige identidad viva, y nada publica después de `anonimiseBusinessLog`); el eje de referencias-a-persona no necesita cambios; el mecanismo de rechazo de v1 verificado extremo a extremo; la aserción Behat `0 records` no puede pasar en vacío (el step falla ante una `DBAL\Exception` y ambas tablas tienen filas); el descubrimiento por reflexión no puede encontrar cero; ningún script, `Makefile`, workflow o doc dependía de la impresión incondicional salvo los cuatro ya actualizados; y la unicidad del stream se preserva al colapsar al mismo seudónimo.

**Pase adversarial (obligatorio, es trabajo de seguridad/GDPR):**
- Subagente de **contexto fresco**, **solo lectura** (prohibidas escrituras y DDL), sobre el **diff completo** contra `origin/main`.
- Corre **antes** de `gh pr create`, no después.
- Sus hallazgos se escriben en este artefacto (sección propia al final) y el cuerpo de la PR cita dónde viven. Un pase sin hallazgos también se registra.
