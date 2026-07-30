---
baseline_commit: f4dbe4d1
---

# Story 1.1 (G-4a): Cerrar la fuga de `PasswordResetCompleted` en los transportes Messenger persistidos

Status: ready-for-dev

<!-- Validación opcional: correr `validate-create-story` antes de `dev-story` para un check de calidad. -->

> **La decisión de mecanismo YA ESTÁ TOMADA y registrada** (ver *Decisión registrada*, más abajo): **①b**,
> ratificada por Sergio el 2026-07-30 tras consulta a tres analistas independientes. La precondición normativa
> de la épica queda satisfecha por este bloque; **no la re-abras**, pero **léela entera antes de tocar código**:
> el argumento que la sostiene es lo que impide implementar ①a por accidente, que es el error natural aquí
> (parece el mismo cambio de una línea y rompe producción).

## Story

Como **sujeto de datos que ha ejercido su derecho de supresión**,
quiero que ninguna copia de mi identificador sobreviva en las tablas de Messenger,
para que el borrado que la aplicación me confirma sea cierto también fuera de las tablas de negocio.

**Eje que instala:** declaración (la regla afilada, donde vive la decisión de routing) · mecanismo (la fuga
cerrada) · control (un test que falla si el evento vuelve a un transporte persistido).
**Invariantes que consume:** SI-21/NFR1, NFR9 (el crypto-shredding cubre **solo** `audit_log`).
**Invariantes que establece:** ninguno nuevo — es la **primera instancia** de SI-21, no una excepción a él.
**Dependencias:** ninguna. Primera del DAG por ser la única fuga viva medida.

## Estado medido (`main` @ `f4dbe4d1` — `api/` idéntico a `471ae66f`, el commit contra el que midió la épica)

**Verificado con `git diff --name-only 471ae66f..f4dbe4d1 -- api/ make/ compose*.yaml` → vacío.**

### La fuga (lo que la épica ya registra, con coordenadas)

1. `api/config/packages/messenger.yaml:28` enruta `Erpify\Iam\Identity\Domain\Event\PasswordResetCompleted: async`.
   El comentario que la autoriza está en las líneas 25–27 («payload is the aggregate id alone»).
2. `api/src/Iam/Identity/Domain/Event/PasswordResetCompleted.php` — `aggregateType()` devuelve `'Iam.Identity'`,
   `toPrimitives()` devuelve `[]`, y el docblock (líneas 11–16) declara *«The subject is the aggregate id
   alone»*. Luego **el `aggregateId` del sobre ES el id del usuario**: no hay payload donde esconderlo.
3. `async` = `%env(MESSENGER_TRANSPORT_DSN)%` → transporte Doctrine (`messenger_messages`);
   `failed: doctrine://default?queue_name=failed` (`messenger.yaml:6–14`). **Ninguno tiene TTL ni poda.**
4. `api/src/Iam/Identity/Application/FulfilIdentityErasure.php` encadena `EraseIdentitySubject`,
   `AuditActorAnonymiser`, `AuditResourceAnonymiser`, `PurgeUserSessions` y `AuditLogger`. **Cero tablas de
   Messenger.** Tras el erase, el id real sobrevive en `messenger_messages` y en `failed`.

### Hechos medidos que el corte de épica NO registra — léelos antes de decidir

**(A) Desenrutar / `sync` no significa «no se entrega»: el reactor pasa a correr DENTRO de la transacción.**

- `api/src/Iam/Identity/Application/CompletePasswordReset.php:85–104` — `$this->eventBus->publish(...)` está en
  la **línea 101**, dentro de `transactionManager->transactional(...)`, y **después** de
  `findByIdForUpdate()` (línea 90): el lock de la fila del usuario está tomado.
- `api/src/Shared/Event/Infrastructure/Bus/SymfonyMessengerEventBus.php:26–33` lo dice literalmente:
  *«callers invoke this inside the use-case `wrapInTransaction` … @throws ExceptionInterface transport send
  failure on the Doctrine outbox, **or a sync-routed handler throwing**»*.
- `api/src/Shared/Event/Infrastructure/Messenger/RunProjectionsOnDomainEvent.php:27` es
  `#[AsMessageHandler(handles: DomainEvent::class)]` → **todo `DomainEvent` tiene handler**, así que borrar la
  línea de routing **no** dispara `allow_no_handlers: false` (default, `api/config/reference.php:460`). Es
  exactamente la forma en la que ya viven los otros 15 eventos de `Iam/*` hoy: sin enrutar, manejados en
  proceso, dentro de la transacción.
- **Consecuencia si ① se implementa dejando el reactor donde está:**
  `SendEmailOnPasswordResetCompleted` → `SymfonyPasswordChangedEmailSender::send()` → `MailerInterface::send()`
  hace **I/O SMTP dentro de la transacción abierta y con el lock de fila tomado**; y el handler **re-lanza**
  (`SendEmailOnPasswordResetCompleted.php:54–58`) → `publish()` lanza → **rollback del reset entero**. Una
  caída del servidor de correo dejaría de romper un email y pasaría a romper el restablecimiento de contraseña.
  Y al revés: el correo sale **antes** del commit, así que un commit fallido notifica un cambio que no ocurrió.
- `Symfony\Component\Mailer\Messenger\SendEmailMessage` **no está enrutado** (medido: no hay entrada; el
  comentario de `messenger.yaml:25–27` explica que es deliberado), luego el envío es SMTP síncrono, no diferido.
- **El repo ya tiene la forma correcta y no hay que inventarla:**
  `api/src/Iam/Identity/Application/RequestPasswordReset.php:95–101` envía **post-commit y best-effort** vía
  `SendPasswordResetEmailBestEffort` (traga y loguea); y el paso 4 del docblock de `CompletePasswordReset`
  (líneas 40–42) usa el mismo patrón con `RevokeSessionsBestEffort`, con el razonamiento escrito: *«a revoke
  failure is swallowed there rather than stranding a reset that committed»*.

**(B) El `event_store` conserva el mismo `aggregate_id` para siempre, y ninguna cadena de borrado lo toca.**
**FUERA DE ALCANCE de esta historia — pero no puede quedar implícito.**

- `api/src/Shared/Event/Infrastructure/Messenger/PersistDomainEventMiddleware.php:31–33` añade **todo**
  `DomainEvent` despachado a `event_store` **antes** de `SendMessageMiddleware`. Ocurre con `async`, con `sync`
  y sin enrutar: **el routing no lo cambia**.
- `api/migrations/2026/Version20260616201857.php:37` — `event_store (… aggregate_id UUID NOT NULL …)`, tabla
  append-only permanente.
- Ninguna ruta de borrado toca `event_store` (las únicas escrituras destructivas están en
  `api/tests/Behat/Context/EventStoreContext.php:65` y `FixturesContext.php:131`, ambas de test).
- **Qué significa para ti:** ① hace cierto el AC1 de la épica (`messenger_messages` + `failed`); **no** hace
  cierto «ninguna copia de mi id sobrevive en ninguna tabla». No escribas un AC ni un test que afirme eso.
- **Y es más grande que este evento:** `password-reset-requested`, `all-revoked`, `session-started`… todos
  llevan id de persona en `event_store` hoy. `docs/adr/regulatory-audit-trail.md:29` separa `event_store` (log
  de **negocio**) del rastro regulatorio (*«retention-bound, PII-erasable»*), así que **nada declara hoy que el
  `event_store` sea borrable**. → **Hallazgo nuevo: propón issue, no lo arregles aquí** (ver Tarea 6).

**(C) En el entorno de test NO existe `messenger_messages`.** `messenger.yaml` bajo `when@test` mapea `async` y
`failed` a `in-memory://?serialize=true`. **No pinnees el AC1 con SQL sobre `messenger_messages` en Behat**: no
hay tabla. Lo assertable es el vocabulario de outbox que ya existe —
`api/tests/Behat/Context/OutboxContext.php:86` (`:number outbox events were created on the queue :queueName`) y
`:196` (`there should not have been an outbox event created containing:`). **No escribas un step nuevo sin
listar antes el vocabulario** (`make php.behat c='-dl'`, `make php.behat c="-d '<texto>'"`) — regla de
`api/CLAUDE.md`.

**(D) Un docblock ya es falso hoy.** `PasswordResetCompleted.php:15` dice *«Emitted to the outbox with no
consumer yet (wire-on-consumer)»*, pero `SendEmailOnPasswordResetCompleted` lo consume y
`docs/architecture/event-catalog.md:239` lo lista como consumidor. Es la regla del boy-scout sobre un fichero
que la historia toca de todas formas.

## Decisión registrada — mecanismo **①b** (precondición normativa de la épica: SATISFECHA)

**Decidido:** 2026-07-30. **Quién:** Sergio, tras consulta a tres analistas independientes sobre el mismo
dossier medido — arquitectura (Winston), implementación (Amelia) y un tercero externo (ChatGPT), este último vía
`tmp/bmad-md/consult-g4a-messenger-leak-mechanism-20260730.md`. **Dónde queda el registro:** este bloque, y el
cuerpo del PR debe reproducirlo.

**Los tres convergieron en ①b por tres argumentos independientes**, lo cual es señal pero **no es verificación**
(el pase adversarial de NFR10 sigue siendo obligatorio y lo debe hacer alguien distinto del autor):

- *Arquitectura:* el reactor vive en `Iam/Identity/Infrastructure` y reacciona a un evento de `Iam/Identity`,
  dependiendo del `UserRepository` y del sender **del mismo módulo**. **Formulado con precisión, porque esta
  frase se citará fuera de contexto:** un reactor *sí* aporta desacoplamiento de compilación y de extensión
  siempre; lo que ocurre **en este caso concreto** es que ese desacoplamiento no es arquitectónicamente
  relevante —mismo bounded context, mismo módulo, mismo repositorio, mismo caso de uso, mismo despliegue, sin
  asincronía real y sin consumidores alternativos— y por tanto **no justifica su coste**: transporte persistido
  + deduplicación + tabla de claims + su poda. No es «el reactor no desacopla»; es «aquí lo que desacopla no
  paga lo que cuesta, y al desenrutar además rompería la frontera transaccional».
- *Implementación:* ①a muere por una vía extra — el `claim()` del deduplicador correría en la misma transacción
  que el rollback, así que **el propio guard de idempotencia se revierte mientras el SMTP ya salió**.
- *Externo:* desenrutar no rompe el diseño, **revela un error de diseño que ya existía**; `async` lo ocultaba.

**Sub-fork medido que la épica no podía ver** (nace del hecho (A)) y que es la razón de que ①a esté descartada:

| Opción | Qué hace | Coste medido |
|--------|----------|--------------|
| **①a** | Desenrutar (borrar la línea 28) o enrutar a `sync`, **dejando el reactor** | El envío SMTP entra en la transacción con el lock de fila tomado, y un fallo de correo **rollea el reset**. Notifica antes del commit. **Refutada por medición** salvo argumento explícito en contra. |
| **①b** ✅ **ELEGIDA** | Desenrutar **y** mover la notificación a un envío **post-commit best-effort** en `CompletePasswordReset`, espejo exacto de `RequestPasswordReset:95–101` y `RevokeSessionsBestEffort` | Garantía **estructural**: no hay copia persistida porque no hay transporte. Sin SMTP en transacción, sin rollback del reset, sin notificar un cambio no comiteado. Cuesta retirar el reactor y su claim de deduplicación (bajo entrega única post-commit no hay redelivery que deduplicar). |
| **②** | Mantener `async` y purgar `messenger_messages` + `failed` en la cadena de erasure | Compensatoria, no estructural: cuerpos serializados, `failed` no se vacía nunca, y añade **otro colaborador** a `FulfilIdentityErasure`, que ya está por encima del umbral y lo declara (`@SuppressWarnings("PHPMD.CouplingBetweenObjects")`, línea 62). |
| **③** | Transporte con TTL | No borra la PII: la **envejece**. Refutada por el propio invariante SI-21. |

**Alternativas descartadas, con su argumento (no re-proponer sin refutar esto):**

- **①a** — el SMTP entra en la transacción con el lock de fila tomado y su fallo **rollea el reset**. Envolver
  el reactor en un `catch` total no la salva: quitaría el rollback pero **no el lock-hold**, que es el problema
  de escalabilidad.
- **②** — dos razones medidas, cualquiera basta. (i) El `body` de `messenger_messages` es
  `addslashes(serialize($envelope))` (`vendor/symfony/messenger/Transport/Serialization/PhpSerializer.php:159–171`
  devuelve **solo** `body`, sin emitir `headers`, y no hay `serializer:` configurado): el id va PHP-serializado
  en un `TEXT`, no en columna indexable. (ii) Un mensaje en vuelo (`delivered_at IS NOT NULL`) durante la purga
  **se re-inserta en `failed` después del commit** — es la resurrección asíncrona de #376 con otro nombre.
  Además diluiría el `@SuppressWarnings` de `FulfilIdentityErasure:62`, que hoy está justificado por la
  **atomicidad del acto de des-identificar**; podar una cola no es parte de ese acto.
- **③** — un TTL no borra la PII: la **envejece**. Y donde la retención es indefinida es en `failed`, que es
  justo lo que cubre ④ (abajo), pero cableado en un DSN que nadie mira en vez de en un mensaje programado que
  se loguea y se testea.
- **Despacho de eventos post-commit (`AfterCommitDispatcher`)** — propuesta por el analista externo como
  «arquitectónicamente superior». **Descartada por incompatibilidad, no por coste:** este repo ya tiene ese
  patrón y se llama **outbox** — el `event_store` y la fila del transporte se insertan **dentro** de la
  transacción a propósito, para que log y encolado commiteen atómicamente con el agregado
  (`PersistDomainEventMiddleware:14–18`). Diferir el dispatch al post-commit **destruye esa atomicidad y
  convierte el outbox en un dual-write**.
- **`kernel.terminate`** — depende de HTTP: rompe CLI, workers y tests.

**Consecuencia directa de ①b sobre la idempotencia (decidida, no abierta):** el `DomainEventHandlerDeduplicator`
**se elimina para este envío**, no se simplifica. Existe contra la redelivery *at-least-once* del transporte
(`api/src/Shared/Event/Application/DomainEventHandlerDeduplicator.php:7–12`); sin transporte no hay redelivery.
Reintento HTTP, doble submit y reintento del caso de uso mueren todos en el `consume()` single-use del token
(`CompletePasswordReset.php:93–95`), ya pinnado en `password_reset.feature:139–144`. El cambio de *at-least-once*
a *at-most-once* es la elección correcta para esta notificación y ya está aceptado en `RevokeSessionsBestEffort`.

**Honestidad debida — el mejor argumento CONTRA ①b, que hay que responder y no callar:** el reactor resuelve el
usuario en el momento del handling (`SendEmailOnPasswordResetCompleted.php:47–51`), así que un sujeto borrado
entre el commit y el envío **no recibe el correo** — su docblock lo vende como garantía anti-resurrección. Con
envío post-commit inmediato esa ventana casi desaparece, así que en la práctica ①b empata o mejora; pero
decláralo en el PR en vez de dejarlo sin respuesta.

## ④ — Poda programada de la cola `failed` (**dentro del alcance de esta historia**, decisión de Sergio)

**Medido:** existe un `#[AsSchedule('maintenance')]`
(`api/src/Shared/Event/Infrastructure/Messenger/Maintenance/HandledDomainEventMaintenanceSchedule.php:19–29`)
con poda diaria de candados de deduplicación y **chequeo horario del backlog de dead-letter que alarma sobre
umbral**, consumido por el worker. Y hay cuatro ficheros que **leen** `failed` (`DeadLetterReader`,
`MessengerDeadLetterReader`, `FailedMessagesStatusCommand`, `ReportDeadLetterBacklogHandler`). **`grep` confirma
que nada la drena jamás:** la cola muerta se observa pero no se vacía.

**Por qué entra aquí:** ①b arregla *este* caso; ④ cierra la *clase*. Sin ④, el día que alguien enrute otro
evento con un id de persona en el sobre, `failed` vuelve a acumular referencias sin dueño de borrado — que es
literalmente lo que SI-21 prohíbe. Es un tercer `RecurringMessage` sobre un patrón ya establecido y testeado
(espeja `PruneHandledDomainEventsHandler`), **sin tocar `FulfilIdentityErasure`** y sin carrera con el worker.

**Ojo con el alcance:** ④ es *retención de cola*, no borrado por sujeto. No enumera sujetos ni conoce personas.
Dilo así en el PR — si se describe como «borrado GDPR» se está prometiendo algo que no hace.

## Acceptance Criteria

**AC1 — El transporte persistido deja de retener el id del sujeto (FR1).**
**Given** el flujo de restablecimiento de contraseña,
**When** un usuario lo completa,
**Then** **no se crea ninguna fila de transporte** para ese evento — ni en `async` ni, por tanto, en `failed`.
*Cómo se pinna:* por **hecho (C)**, en test no existe `messenger_messages`; la aserción es
`0 outbox events were created on the queue "async"` (`OutboxContext:86`), falsable de verdad porque el soporte
lanza si la cola no es inspeccionable.

> **Redacción deliberada, no descuido.** Este AC dice *«el transporte persistido ya no retiene el id del
> sujeto»*, **no** *«ninguna copia del id del sujeto sobrevive al borrado»*. La segunda frase es
> **verificablemente falsa** con un `SELECT aggregate_id FROM event_store` después de una erasure (hecho (B)),
> y los tres analistas coincidieron en que declararla sería exactamente el patrón que ya nos mordió: prometer
> una garantía que el código no da. **No la re-amplíes al escribir el PR.**

**AC2 — La garantía no se compra dejando de notificar.**
**Given** el flujo de restablecimiento,
**When** un usuario lo completa,
**Then** la notificación por correo se sigue enviando.
*Cómo se pinna:* `NotificationContext` ya tiene el vocabulario (`:number notification email was sent`,
`The notification email recipient should be :value`, `The notification email subject should be equal to :value`
— `api/tests/Behat/Context/NotificationContext.php:39,58,73`).

**AC3 — Un control falla si el evento vuelve a un transporte persistido, y enuncia la REGLA.**
**Given** un cambio futuro que devuelva `PasswordResetCompleted` a un transporte persistido,
**When** corre la suite,
**Then** un control **falla**, y su mensaje enuncia *«un payload "solo el id del agregado" es seguro si y solo
si el agregado no es una persona»* — **no** solo el nombre del evento.
*Forma decidida (no la re-inventes) — es lo único que hace fallar la reintroducción: ni unit ni Behat ven la
línea de routing.* **Registro declarativo cruzado contra `routing`, no lista negra de un evento:**

- Registro nuevo `api/.persistent-transport-policy`, en la **raíz de `api/`** como `.audit-resource-types` (es
  artefacto de revisión, no módulo). Líneas del tipo `Iam.Identity => person`, clavadas en **`aggregateType()`**
  y **NO** en el FQCN del evento — un rename del FQCN dejaría el registro mudo.
- Gate bajo `api/tests/Unit/Shared/Architecture/`, `#[CoversNothing]`, preámbulo de fallo en **constante de
  clase** para que CI lo grepee (precedente: `EventDispatchGateTest::FAILURE_PREAMBLE`). Recorre cada entrada de
  `framework.messenger.routing`, resuelve su `aggregateType()` **por reflexión** y falla si es `person` y el
  transporte no es `sync`.
- **Excepción explícita** con la forma `Iam.Identity => person :: docs/adr/<fichero>.md`, y el gate hace
  `assertFileExists` sobre esa ruta — espeja `PersonResourceErasureGateTest:82–87`.
- **Lo que impide que se autosatisfaga (SI-23):** el **check de completitud** — todo `aggregateType()` que
  aparezca en `routing` debe estar clasificado. Enrutar un evento nuevo sin línea rompe el build; añadir la
  línea obliga a decidir; declarar excepción obliga a que exista un ADR real.
- **Sí, target `php.lint.*` propio**, cableado en `php.quality` **Y** en `php.quality.dry-run`
  (NFR11 — CI corre el *dry-run*; `make/php-quality.mk:148,166,173–174`), siguiendo la convención uno-a-uno de
  los gates existentes para que CI diga **qué frontera** se rompió.
- Pinna que el gate **detecta** (fixture sucio → falla) y que **no es vacuo** (escanea ≥1 entrada), como
  `EventDispatchGateTest::testFixtureExposesMatcher` / `testGateScansAtLeastOneApplicationFile`.

**AC3b — El envío queda pinnado como POST-commit, no solo como «se envía».**
**Given** el flujo de restablecimiento,
**When** corre la suite unitaria,
**Then** existe `CompletePasswordResetTest` (**hoy no existe** — hueco medido) que pinna **el orden**, porque
«se envía» pasa igual si el envío está dentro de la transacción:
① un doble de `TransactionManager` que exponga `committed`, y el spy del mailer **registra su valor al ser
llamado** → aserción `true` (esto es lo que mata ①a); ② un mailer que **lanza** → el reset queda commiteado
igual (token consumido, credencial cambiada, sin excepción), que es lo que pinna el best-effort.

**AC3c — La cola `failed` deja de ser retención indefinida (④).**
**Given** mensajes muertos que superan la antigüedad configurada,
**When** corre el schedule de mantenimiento,
**Then** se podan **sin intervención humana**, mediante un tercer `RecurringMessage` en
`HandledDomainEventMaintenanceSchedule`, espejando `PruneHandledDomainEventsHandler`. El handler **no enumera
sujetos ni conoce personas**: es retención de cola por antigüedad. La alarma horaria de backlog existente sigue
funcionando.

**AC4 — El comentario de routing enuncia la regla sobre el código ACTUAL.**
**Given** el comentario de `messenger.yaml:25–27`, que hoy autoriza la cola con un razonamiento correcto para
`Bank` y falso para `User`,
**When** se corrige el routing,
**Then** el comentario enuncia la regla sobre el código actual, **sin narrar el cambio ni citar el defecto
anterior** (`CLAUDE.md` → *Code comments*: prohibidos los comentarios relativos al cambio y los IDs de historia).

**AC5 — El alcance queda declarado, no implícito.**
**Given** que el control cubre un solo evento,
**When** se cierra la historia,
**Then** consta que generalizarlo a *«todo evento cuyo agregado sea una persona»* **es** FR9/G-4b y sigue fuera
de alcance (bloqueada por la pregunta abierta de *ownership de referencias nacidas en configuración*).

**AC6 — Sin regresión + gates verdes.**
`make php.quality` (incluye `php.deptrac`, `php.lint.error-contract`, `php.lint.bounded-context`,
`php.lint.event-bus`, `php.lint.audit-resource`), `make php.unit`, `make php.behat`. Cada uno desde una
**ejecución fresca**, con el exit code impreso — nunca «verde» leído de un log anterior.

## Tasks / Subtasks

- [x] **Tarea 1 — Registrar la decisión de mecanismo (PRECONDICIÓN).** HECHA: ver *Decisión registrada*.
      Reprodúcela en el cuerpo del PR; no la re-abras.
- [ ] **Tarea 2 — Cerrar la fuga con ①b (AC1)**
  - [ ] Borrar la línea 28 de `api/config/packages/messenger.yaml` (**desenrutar**, no enrutar a `sync://`:
        `sync://` re-despacha por el mismo bus y repite la pila de middleware, incluido un `INSERT` extra a
        `event_store` dentro de la transacción con el lock tomado — absorbido por `ON CONFLICT`, pero no gratis).
  - [ ] Mover el envío a **post-commit best-effort** en `CompletePasswordReset`, espejando
        `RequestPasswordReset:95–101`: swallow + log, para que un mailer caído no convierta un reset commiteado
        en un 500.
  - [ ] Retirar `SendEmailOnPasswordResetCompleted`, su `DomainEventHandlerDeduplicator` para este envío y su
        test (`api/tests/Unit/Iam/Identity/Infrastructure/Messenger/SendEmailOnPasswordResetCompletedTest.php`).
  - [ ] Verificar que el evento **se sigue publicando y almacenando** — `password_reset.feature:127` afirma
        `there should be 1 event stored named "erpify.iam.identity.password-reset-completed"` y **no puede
        romperse**: el evento no desaparece, deja de viajar por un transporte persistido.
  - [ ] `sync: 'sync://'` (`messenger.yaml:15`) queda como **config muerta** — ninguna ruta apunta ahí. Decide
        si se retira (boy-scout) o se conserva, y dilo.
- [ ] **Tarea 2b — Poda programada de `failed` (AC3c, ④)**
  - [ ] Tercer `RecurringMessage` en `HandledDomainEventMaintenanceSchedule` + handler espejando
        `PruneHandledDomainEventsHandler`, con su test.
  - [ ] Describirlo en el PR como **retención de cola**, nunca como «borrado GDPR» — no enumera sujetos.
- [ ] **Tarea 3 — El control que enuncia la regla (AC3)**
  - [ ] Test bajo `api/tests/Unit/Shared/Architecture/`, sobre `config/packages/messenger.yaml`, con la regla en
        el mensaje de fallo y la preámbulo en constante de clase.
  - [ ] Decidir y justificar: test unitario suelto **o** target `php.lint.*`; si es target, cablearlo en
        `php.quality` **y** `php.quality.dry-run`.
  - [ ] Pinnear que el control **detecta** (fixture sucio → falla) y que **no es vacuo** (escanea ≥1 entrada),
        siguiendo `EventDispatchGateTest::testFixtureExposesMatcher` / `testGateScansAtLeastOneApplicationFile`.
- [ ] **Tarea 4 — Comentarios y docs sobre el código actual (AC4, AC5, hecho (D))**
  - [ ] Reescribir el comentario de routing enunciando la regla afilada, sin narrar el cambio.
  - [ ] Corregir el docblock falso de `PasswordResetCompleted.php:15` (*«no consumer yet»*).
  - [ ] Actualizar `docs/architecture/event-catalog.md` (tabla de `Iam.Identity` línea 239, la prosa de
        líneas 65–73, y el paso 2 del checklist *Adding or evolving an event* si la regla cambia el «route it
        → async» por defecto) y `docs/architecture-api.md:275–281`.
- [ ] **Tarea 5 — Cobertura de comportamiento (AC1, AC2)**
  - [ ] Antes de escribir steps: `make php.behat c='-dl'` y `make php.behat c="-d 'outbox'"`. Reutilizar.
  - [ ] Escenario(s) en `api/features/backoffice/identity/password_reset.feature` que pinnen: el reset sigue
        notificando (AC2) y no deja evento en la cola persistida (AC1).
  - [ ] Ojo con `api/features/backoffice/users/erase.feature`: **no** añadas ahí una aserción de
        `messenger_messages` — por (C) no existe en test.
- [ ] **Tarea 6 — Declarar los límites y colocar los hallazgos (AC5)**
  - [ ] Dejar constancia de que FR9/G-4b sigue fuera de alcance y por qué.
  - [ ] **Hallazgo (B) — `event_store` retiene ids de persona.** **NO se arregla en este PR** y **no se abre
        issue** (decisión de Sergio: el contador de issues ya está saturado). Se añade como **historia nueva a
        `_bmad-output/planning-artifacts/epics-gdpr-hardening.md`**, que ya tiene espina, DAG y definición de
        hecho. Razón de que no quepa aquí: `aggregate_id` es `UUID NOT NULL`, **clave de stream e índice**
        (`event_store_stream_version_uniq`, `event_store_aggregate_idx`), así que el crypto-shredding que el repo
        usa en `audit_log` **no aplica a una columna clave**, y D4 veta la tabla de correspondencia. La única vía
        viable —que el `aggregate_id` **nazca** como sustituto derivado por sujeto y la erasure destruya el
        secreto de derivación— toca todos los eventos, el replay de proyecciones y los checkpoints: es
        **estrategia de persistencia**, decisión del usuario y material de ADR, no una tarea.
        *Inventario medido para esa historia:* como `aggregate_id` — `PasswordResetCompleted`, `UserSuspended`,
        `UserDeactivated`, `UserRolesChanged`, `UserLocked` (`User.php:176,191,206,224,264`) y
        `PasswordResetRequested` (grabado sobre el agregado del token pero construido con el id del **usuario**,
        `PasswordResetToken.php:70`); en el **payload** — `SessionStarted` (`['userId' => …]`) y los seis
        `Invitation*` (`invitedUserId`). Ninguno está enrutado, así que ninguno pasa por la cola: **viven solo
        en `event_store`, para siempre.** Dato que refuerza la premisa: **aquí no hay Event Sourcing** — ningún
        agregado se rehidrata de eventos (`User` es entidad Doctrine); `event_store` es log de negocio + fuente
        de proyecciones. Sin replay de agregados no hay coartada para conservar el UUID real, pero **sí** hay
        replay de proyecciones, así que el id no se puede anular sin más.
- [ ] **Tarea 6b — UNIQUE de stream inoperante (se intenta EN ESTE PR, con chequeo previo)**
  - [ ] **Medido:** `event_store_stream_version_uniq` es `UNIQUE (tenant_id, aggregate_id, aggregate_version)` y
        `DbalEventStore:73` escribe `tenant_id` **siempre `NULL`**. PostgreSQL usa `NULLS DISTINCT` por defecto,
        así que dos filas `(NULL, x, 1)` **no colisionan**: el control de concurrencia optimista que
        `DbalEventStore:26–27` afirma **no existe hoy**, y su `catch (UniqueConstraintViolationException)` es
        inalcanzable.
  - [x] **Semántica CONFIRMADA empíricamente** (2026-07-30, PostgreSQL 18.3, tabla temporal, sin tocar datos
        reales): con un índice único `(tenant_id, aggregate_id, aggregate_version)` **idéntico al del esquema
        vivo**, dos filas `(NULL, <mismo uuid>, 1)` **entran las dos**; añadiendo `NULLS NOT DISTINCT`, la
        segunda es rechazada con `duplicate key value violates unique constraint`. El índice real se leyó de
        `pg_indexes`, no del fichero de migración, y **no lleva la cláusula**. Deja de ser razonamiento.
  - [ ] **PENDIENTE Y BLOQUEANTE — contar duplicados en PRODUCCIÓN.** El conteo en la base de desarrollo salió
        `0`, pero es **vacuo**: `event_store` tiene **0 filas** ahí (la suite Behat la trunca,
        `FixturesContext.php:131`). Cero duplicados sobre cero filas no autoriza nada. La consulta que decide es
        `SELECT count(*) FROM (SELECT 1 FROM event_store GROUP BY tenant_id, aggregate_id, aggregate_version
        HAVING count(*) > 1) d` **contra producción** (acceso remoto en `docs/vps-deployment.md`). Si devuelve
        > 0, la recreación del índice **falla** y esta tarea sale del PR.
  - [ ] Si está limpio: migración que recrea el índice con `NULLS NOT DISTINCT` (PostgreSQL 18 lo soporta),
        `down()` reversible, más un test que fije el comportamiento nuevo.
  - [ ] **Declarar el cambio de comportamiento en el PR:** appends concurrentes al mismo agregado que hoy pasan
        en silencio empezarán a dar `EventStreamConcurrencyConflict`. Es el comportamiento que el docblock ya
        promete, pero es un cambio real y puede destapar carreras latentes.
- [ ] **Tarea 6c — Runbook de despliegue (las filas de HOY no las arregla el código)**
  - [ ] `①b` cierra la fuga **futura**; las filas ya escritas siguen ahí. Orden decidido, **sin downtime y sin
        perder notificaciones**: desplegar → dejar que los workers **drenen `async` solos** (cuenta a 0; esos
        usuarios sí reciben su correo) → purgar solo lo que quede en `failed`. *No hace falta parar workers:*
        tras el deploy no se escribe ni una fila más de este tipo.
  - [ ] **La purga se hace por tipo de mensaje, no enumerando sujetos borrados** — se retira la clase entera y
        nadie toca un UUID. **Cuidado con el patrón:** el `body` es `addslashes(serialize($envelope))` y
        `addslashes` **duplica cada backslash**, así que un `LIKE '%Domain\Event\PasswordResetCompleted%'` **no
        casa**. Usa el nombre corto de clase, sin backslashes: `LIKE '%PasswordResetCompleted%'`. Un
        `SELECT count(*)` con el mismo `WHERE` **antes** es el inventario.
  - [ ] Decir explícitamente que el runbook **no toca `event_store`** (ver Tarea 6).
- [ ] **Tarea 7 — Gates y pase adversarial (AC6 + definición de hecho de la épica)**
  - [ ] `make php.quality`, `make php.unit`, `make php.behat` — frescos, con exit code.
  - [ ] Recorrer la checklist de seguridad de `CLAUDE.md` sobre el diff (ver *Seguridad* abajo).
  - [ ] **Pase adversarial por alguien distinto del autor, REGISTRADO**, declarando dónde quedó. Sin él la
        historia no llega a `done` (NFR10). Un pase que no encuentra nada cuenta — y también se declara.

## Dev Notes

### Reuse map — lo que ya existe y NO se reinventa

| Necesidad | Ya existe | Ruta |
|-----------|-----------|------|
| Envío de correo post-commit que traga fallos | `SendPasswordResetEmailBestEffort` (patrón exacto a espejar) | `api/src/Iam/Identity/Application/SendPasswordResetEmailBestEffort.php` |
| Envío del correo «tu contraseña ha cambiado» | `PasswordChangedEmailSender` (puerto) + `SymfonyPasswordChangedEmailSender` (adaptador) | `api/src/Iam/Identity/Application/PasswordChangedEmailSender.php`, `api/src/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSender.php` |
| Gate de arquitectura con fixture y anti-vacuidad | `EventDispatchGateTest` | `api/tests/Unit/Shared/Architecture/EventDispatchGateTest.php` |
| Gate que lee un registro y falla con mensaje prescriptivo | `PersonResourceErasureGateTest` | `api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php` |
| Aserciones de transporte/outbox | `OutboxContext` (36 steps ya declarados) | `api/tests/Behat/Context/OutboxContext.php` |
| Aserciones de correo | `NotificationContext` | `api/tests/Behat/Context/NotificationContext.php` |
| Aserciones sobre `event_store` | `EventStoreContext` | `api/tests/Behat/Context/EventStoreContext.php` |

### Anti-patrones concretos que esta historia invita a cometer

1. **Asumir que `sync` = «no se ejecuta».** Se ejecuta, y dentro de la transacción con el lock tomado. Hecho (A).
2. **Assertar `SELECT … FROM messenger_messages` en Behat.** No existe en test. Hecho (C).
3. **Afirmar en un AC, comentario o PR que «ninguna copia del id sobrevive».** El `event_store` la conserva.
   Hecho (B).
4. **Escribir un step de Behat nuevo sin listar el vocabulario.** `api/CLAUDE.md` lo prohíbe explícitamente:
   más de la mitad de los 205 steps está ocioso, y una frase casi-duplicada parte el vocabulario en dos mitades
   medio usadas.
5. **Comentar el cambio en vez del código.** Nada de «antes esto iba a `async`», ni `G-4a`, ni `FR1`, ni `SI-21`
   en comentarios de `src`. La trazabilidad vive en el PR.
6. **Meter el barrido de Messenger dentro de `FulfilIdentityErasure` sin pensar el coupling** (si sale ②): la
   clase ya declara y justifica estar por encima del umbral de PHPMD.
7. **Generalizar el control a «todo evento cuyo agregado sea una persona».** Eso es FR9/G-4b, bloqueada. AC5.

### Arquitectura y fronteras

- El cambio vive en `Iam/Identity` (`Application/` + `Infrastructure/Mail`) y en configuración
  (`api/config/packages/messenger.yaml`). **No** cruza a otro contexto: no toca `Shared/Audit`, ni
  `Organization/`, ni `Iam/Invitation`. `php.lint.bounded-context` y `php.deptrac` no deberían moverse.
- Si el control nuevo se coloca bajo `api/tests/Unit/Shared/Architecture/`, es un test, no un módulo: no
  requiere registro en `tools/deptrac/deptrac.yaml` (deptrac analiza `api/src`).
- `EventBus` sigue siendo el puerto de publicación; **no** importar `MessageBusInterface` en `Application/`
  (`php.lint.event-bus` lo rompe y el allowlist está vacío de entradas).

### Testing

- Suite unitaria: `api/tools/phpunit/phpunit.dist.xml` incluye todo `../../tests`, con
  `failOnDeprecation`/`failOnNotice`/`failOnWarning` en `true`.
- Tests existentes que tocan el área y **no pueden quedar rojos ni borrados sin argumento**:
  `api/tests/Unit/Iam/Identity/Domain/Event/PasswordResetCompletedTest.php`,
  `api/tests/Unit/Iam/Identity/Domain/Entity/UserResetPasswordTest.php`,
  `api/tests/Unit/Iam/Identity/Infrastructure/Messenger/SendEmailOnPasswordResetCompletedTest.php` (este último
  desaparece **solo** si ①b retira el reactor — dilo en el PR).
- Behat resetea la base y borra el ADMIN sembrado para e2e: si tocas fixtures, siembra **después**
  (`organization:provision` primero).
- Cobertura Sonar: usa `#[CoversClass]` en tests nuevos de clases de producción; `#[CoversNothing]` **solo**
  para el gate de arquitectura (es el precedente de los seis gates existentes).

### Seguridad (checklist de `CLAUDE.md` aplicada a este diff)

- **Secretos:** el evento no lleva token ni PII en payload y no debe empezar a llevarlos. `SendEmailMessage`
  sigue **sin enrutar** — no lo enrutes «de paso»: serializaría el `Email` completo con el token en claro.
- **Datos personales:** el objetivo del cambio. Verifica que la notificación no gana contexto de petición (IP,
  timestamp, user-agent): el docblock de `SymfonyPasswordChangedEmailSender:21–23` explica que el cuerpo es
  estático **a propósito**, porque interpolarlo lo arrastraría al evento y a su fila permanente de `event_store`.
- **Handlers de Messenger idempotentes:** si ①b retira el reactor, la idempotencia deja de ser necesaria porque
  desaparece la entrega at-least-once — **dilo**, no lo dejes implícito.
- **Migraciones:** esta historia no debería necesitar ninguna. Si crees que sí, para y explica por qué.
- **Inyección / authz / validación / RFC 9457:** sin superficie HTTP nueva. Declara «no aplica» en el PR en vez
  de omitirlo en silencio.

### Docs a actualizar (regla de `CLAUDE.md` → *Keeping docs up to date*)

- `docs/architecture/event-catalog.md` — tabla `Iam.Identity` (línea 239), prosa de líneas 65–73, y el paso 2
  del checklist final si la regla por defecto cambia.
- `docs/architecture-api.md:275–281` — el párrafo que hoy describe la notificación como *«async the safe way»*.
- `PRODUCTION_SECURITY_CHECKLIST.md` / `docs/rules/security.md` **solo si** el cambio introduce un patrón nuevo
  (la regla afilada como criterio de enrutado probablemente lo es — evalúalo, no lo des por hecho).

### Inteligencia de la historia anterior (U-5b, `users-admin`, merged)

- `FulfilIdentityErasure` nació ahí y **ya está por encima del umbral de coupling de PHPMD, con supresión
  argumentada**. Cualquier colaborador nuevo (opción ②) reabre esa discusión.
- El patrón *decisión antes que código* ya se usó ahí (D1–D6 en el artefacto). Este es el mismo mecanismo,
  ahora con rango normativo de épica.
- El pase adversarial sobre U-5b es lo que abrió #545 y #561 — es decir, esta épica existe **porque** el pase
  adversarial encontró lo que la convergencia entre análisis no encontró. No lo trates como trámite.

### Git intelligence

Últimos commits relevantes: `f4dbe4d1` (corte de la épica, #609), `471ae66f` (afinado de SI-21 y eliminación de
la contradicción G-4a/eje, #607), `bb973516` (addendum, #606), `3ca55f4a` (pin de que un lockout no expulsa una
sesión viva, #605). Los tres primeros son **documentales**: `api/` no se ha movido desde la medición, así que
el `Estado medido` de arriba es la foto vigente, no una foto podrida.

## References

- `_bmad-output/planning-artifacts/epics-gdpr-hardening.md` — FR1, NFR1/SI-21, NFR9, NFR10, NFR11; Story 1.1.
- `_bmad-output/planning-artifacts/arch-addendum-gdpr-hardening.md` — SI-21/SI-22/SI-23, fila **G-4a** de la
  tabla de localización, DAG safe-first, y la pregunta abierta que bloquea G-4b.
- `docs/adr/regulatory-audit-trail.md` — D15 y la separación `event_store` (negocio) vs rastro regulatorio
  (PII-erasable), línea 29.
- `docs/adr/audit-activity-log.md` — D4 (prohibición de crosswalk).
- `docs/adr/event-store-and-projections.md` — D8 (fallo de publish aborta la escritura), D10 (política de payload).
- `docs/architecture/event-catalog.md` — catálogo de eventos y checklist *Adding or evolving an event*.
- `CLAUDE.md` — *Security review on every change* → **Process** (pase adversarial registrado); *Code comments*;
  *Keeping docs up to date*.
- `api/CLAUDE.md` — vocabulario Behat como activo a gastar; deptrac; gates.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
