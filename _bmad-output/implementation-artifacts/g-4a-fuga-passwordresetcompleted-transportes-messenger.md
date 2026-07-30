---
baseline_commit: f4dbe4d1
---

# Story 1.1 (G-4a): Cerrar la fuga de `PasswordResetCompleted` en los transportes Messenger persistidos

Status: review

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

> **Qué significa «fuga viva» aquí, dicho con precisión.** **No existe entorno de producción** — el proyecto
> sigue en desarrollo (confirmado por Sergio, 2026-07-30). Luego **el dato personal de ninguna persona real ha
> quedado expuesto**: lo vivo es el **mecanismo**, no un incidente. Esto no rebaja la historia, y conviene ver
> por qué **con el propio criterio de la épica**: #564 quedó fuera *por alcanzabilidad* —su ventana exige
> réplicas de `php` que `compose.prod.yaml` no declara, así que es **latente**—, mientras que aquí el camino se
> ejecuta **cada vez que alguien completa un restablecimiento**, hoy, en desarrollo, sin topología especial. La
> distinción de la épica se sostiene: G-4a es **alcanzable**, #564 no. Lo que cambia es la redacción del PR: se
> arregla **antes** de que existan datos reales, que es el momento barato, y **no** se escribe que se contuvo
> una fuga de datos personales — porque no la hubo.

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
| **①b** ✅ **ELEGIDA** | Desenrutar **y** mover la notificación a un envío **post-commit best-effort** en `CompletePasswordReset`, espejo exacto de `RequestPasswordReset:95–101` y `RevokeSessionsBestEffort` | Garantía **estructural**: no hay copia persistida porque no hay transporte. Sin SMTP en transacción y sin notificar un cambio no comiteado. **Matiz que el pase adversarial corrigió: «sin rollback del reset» era demasiado fuerte** — desenrutar mete en la transacción no solo el reactor de correo sino también `RunProjectionsOnDomainEvent`, y un proyector que lance sí rolearía el reset (ver hallazgo A-3). Cuesta retirar el reactor y su claim de deduplicación (bajo entrega única post-commit no hay redelivery que deduplicar). |
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

## ④ — Poda de la cola `failed`: **FUERA DE ALCANCE** (revertido tras el pase adversarial)

**Entró por decisión de Sergio y sale por medición.** El propio autor de la propuesta (Winston) la retiró:
*«la saco; el revisor tiene razón y yo la propuse sin medir»*. Los tres analistas coinciden ahora en sacarla, y
el argumento es el mismo por tres caminos:

- **Con `maxBacklog = 0`, el estado estable declarado de `failed` es VACÍO.** Un podador por antigüedad es
  entonces **código muerto en el caso sano y destructivo justo en el caso enfermo**: solo llega a disparar
  cuando un humano ya falló en actuar, y entonces borra la evidencia y el contador. Es lo contrario de un
  guardarraíl.
- **`failed` no es almacenamiento: es una bandeja de trabajo.** Un mensaje muerto es un efecto de negocio que
  no ocurrió — una incidencia viva, más parecida a un ticket abierto que a un log. La retención indefinida es
  legítima *mientras represente trabajo pendiente*, y podarla la deja de convertir en work queue.
- **Y no hace falta aquí:** ①b retira el único evento enrutado que porta un id de persona, y el gate de AC3
  impide encolar el siguiente. La retención de `failed` pasa a ser **higiene operativa, no GDPR**.

**La consecuencia arquitectónica que sale de esto es más fuerte que la poda, y conviene dejarla escrita:** si un
mensaje **no puede** vivir indefinidamente en `failed`, el defecto está en **el mensaje**, no en la política de
retención — ese mensaje no debería contener el dato. Que es exactamente la tesis de esta épica.

**Forma que tendría que tener si algún día vuelve** (para que nadie la re-proponga peor): nunca barrido por
edad como higiene rutinaria, sino **alarmar → intervención humana → si nadie interviene en X, registrar y
eliminar**, con ventana de retención **estrictamente mayor** que el SLA de escalado, poda limitada a clases de
mensaje listadas explícitamente, traza estructurada antes del `DELETE` (clase, conteo, fecha, antigüedad,
motivo — **nunca ids**), y tests **del observable, no del mecanismo**: mientras quede un mensaje fuera de
política la alarma debe seguir disparando *después* de podar.

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
- **OBLIGATORIO — el gate ingenuo es evadible por cinco vías, y sin cubrirlas no sirve de nada.** Medido en
  `vendor/symfony/messenger/Handler/HandlersLocator.php:62–71`: `listTypes()` devuelve la clase **más**
  `class_parents()`, `class_implements()`, comodines de namespace y `'*'`; y
  `Transport/Sender/SendersLocator.php:50–76` recorre esa lista contra el mapa **y además** aplica
  `#[AsMessage(transport:)]` declarado en el propio evento **sin ninguna entrada en `routing`**. Es decir,
  devuelven el id al transporte persistido: `Erpify\Shared\Event\Domain\DomainEvent: async`, un comodín
  `Erpify\Iam\Identity\Domain\Event\*: async`, `'*': async`, una **interfaz** que el evento implemente,
  o un `#[AsMessage('async')]` sobre la clase. **Ninguna es una clase concreta con `aggregateType()`
  invocable**, así que un gate que itere las claves de `routing` esperando FQCN las salta — y el check de
  completitud tampoco puede clasificarlas, luego tampoco rompe el build. El gate debe resolver cada clave
  a su **conjunto de eventos alcanzables** (no a una clase), y escanear `#[AsMessage]` en `api/src`.
- Pinna que el gate **detecta** (fixture sucio → falla) y que **no es vacuo** (escanea ≥1 entrada), como
  `EventDispatchGateTest::testFixtureExposesMatcher` / `testGateScansAtLeastOneApplicationFile`. **Añade un
  fixture por cada una de las cinco vías de arriba** — si el gate no las caza, no protege nada.

**AC3b — El envío queda pinnado como POST-commit, no solo como «se envía».**
**Given** el flujo de restablecimiento,
**When** corre la suite unitaria,
**Then** existe `CompletePasswordResetTest` (**hoy no existe** — hueco medido) que pinna **el orden**, porque
«se envía» pasa igual si el envío está dentro de la transacción:
① un doble de `TransactionManager` que exponga `committed`, y el spy del mailer **registra su valor al ser
llamado** → aserción `true` (esto es lo que mata ①a); ② un mailer que **lanza** → el reset queda commiteado
igual (token consumido, credencial cambiada, sin excepción), que es lo que pinna el best-effort; ③ **el orden
frente a la revocación** — los dobles de `RevokeSessionsBestEffort` y del mailer escriben en un **registrador
compartido** (`$orden[] = 'revoke'` / `$orden[] = 'send'`) y la aserción es
`assertSame(['revoke', 'send'], $orden)`, que se rompe si alguien sube el envío una línea.
**Son tres propiedades distintas y ninguna sustituye a otra:** post-commit, best-effort y orden.

**AC3c — El correo deja de afirmar lo que el sistema no garantiza.**
**Given** el cuerpo de la notificación de contraseña cambiada,
**When** se revisa tras el cambio de orden,
**Then** **no afirma** *«We signed out all your open sessions for security»*: la revocación es best-effort y
traga sus fallos (`RevokeSessionsBestEffort.php:27–36`), así que el correo estaría declarando un hecho no
garantizado — y con el envío ya ordenado después de la revocación lo declararía **de forma determinista**.
Se reescribe para informar del **cambio de contraseña** sin afirmar un resultado absoluto.
*Alternativa descartada:* hacer la revocación obligatoria — cambiaría el contrato del endpoint (hoy un fallo de
revocación deja el reset válido; pasaría a invalidarlo) e introduciría un motivo de error nuevo dependiente de
infraestructura. Eso es otra historia.

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
- [x] **Tarea 2 — Cerrar la fuga con ①b (AC1)**
  - [x] Borrar la línea 28 de `api/config/packages/messenger.yaml` (**desenrutar**, no enrutar a `sync://`:
        `sync://` re-despacha por el mismo bus y repite la pila de middleware, incluido un `INSERT` extra a
        `event_store` dentro de la transacción con el lock tomado — absorbido por `ON CONFLICT`, pero no gratis).
  - [x] Mover el envío a **post-commit best-effort** en `CompletePasswordReset`, espejando
        `RequestPasswordReset:95–101`: swallow + log, para que un mailer caído no convierta un reset commiteado
        en un 500.
  - [x] Retirar `SendEmailOnPasswordResetCompleted`, su `DomainEventHandlerDeduplicator` para este envío y su
        test (`api/tests/Unit/Iam/Identity/Infrastructure/Messenger/SendEmailOnPasswordResetCompletedTest.php`).
  - [x] Verificar que el evento **se sigue publicando y almacenando** — `password_reset.feature:127` afirma
        `there should be 1 event stored named "erpify.iam.identity.password-reset-completed"` y **no puede
        romperse**: el evento no desaparece, deja de viajar por un transporte persistido.
  - [x] `sync: 'sync://'` (`messenger.yaml:15`) queda como **config muerta** — ninguna ruta apunta ahí. Decide
        si se retira (boy-scout) o se conserva, y dilo.
- [x] **Tarea 2b — Orden y texto del correo (AC3b, AC3c)**
  - [x] El envío va **DESPUÉS** de `revokeSessions` (`CompletePasswordReset.php:106`) y antes del `return`.
        **No** es «espejo exacto» del caso hermano: de `RequestPasswordReset` se hereda el **patrón**
        (post-commit + swallow), **no la posición** (última línea). La contención precede a la comunicación.
  - [x] Reescribir el cuerpo de `SymfonyPasswordChangedEmailSender` (líneas 57 y 72) para no afirmar el cierre
        de sesiones.
  - [x] **NO acotes el timeout SMTP aquí — está en el issue #612, con tripwire.** Medido: `EsmtpTransportFactory`
        **no lee ninguna opción `timeout` del DSN** (`?timeout=5` no hace nada), y `SocketStream::getTimeout()`
        cae a `ini_get('default_socket_timeout')` = **60 s**; `setTimeout()` existe y nadie lo llama. Y el riesgo
        es **latente**: `MAILER_DSN` es `null://null` en `compose.yaml` y `compose.prod.yaml`, y solo
        `compose.dev.yaml` usa `smtp://mailpit:1025` (local, instantáneo) — **ninguna configuración del repo
        apunta a un SMTP remoto**, que es la misma razón de alcanzabilidad por la que #564 quedó fuera de la
        épica. *Tripwire:* el día que `MAILER_DSN` apunte a un SMTP remoto real pasa a defecto de disponibilidad.
        El issue cubre **ambos** call sites (`CompletePasswordReset` y `RequestPasswordReset.php:100`).
  - [x] **Consecuencia que NO debes escribir mal en el PR:** con `null://null` desplegado, la notificación
        **tampoco se entrega** realmente. AC2 se cumple a nivel de código, no de configuración desplegada. No
        escribas «se sigue notificando» sin ese matiz.

- [x] **Tarea 3 — El control que enuncia la regla (AC3)**
  - [x] Test bajo `api/tests/Unit/Shared/Architecture/`, sobre `config/packages/messenger.yaml`, con la regla en
        el mensaje de fallo y la preámbulo en constante de clase.
  - [x] Decidir y justificar: test unitario suelto **o** target `php.lint.*`; si es target, cablearlo en
        `php.quality` **y** `php.quality.dry-run`.
  - [x] Pinnear que el control **detecta** (fixture sucio → falla) y que **no es vacuo** (escanea ≥1 entrada),
        siguiendo `EventDispatchGateTest::testFixtureExposesMatcher` / `testGateScansAtLeastOneApplicationFile`.
- [x] **Tarea 4 — Comentarios y docs sobre el código actual (AC4, AC5, hecho (D))**
  - [x] Reescribir el comentario de routing enunciando la regla afilada, sin narrar el cambio.
  - [x] Corregir el docblock falso de `PasswordResetCompleted.php:15` (*«no consumer yet»*).
  - [x] Actualizar `docs/architecture/event-catalog.md` (tabla de `Iam.Identity` línea 239, la prosa de
        líneas 65–73, y el paso 2 del checklist *Adding or evolving an event* si la regla cambia el «route it
        → async» por defecto) y `docs/architecture-api.md:275–281`.
- [x] **Tarea 5 — Cobertura de comportamiento (AC1, AC2)**
  - [x] Antes de escribir steps: `make php.behat c='-dl'` y `make php.behat c="-d 'outbox'"`. Reutilizar.
  - [x] Escenario(s) en `api/features/backoffice/identity/password_reset.feature` que pinnen: el reset sigue
        notificando (AC2) y no deja evento en la cola persistida (AC1).
  - [x] Ojo con `api/features/backoffice/users/erase.feature`: **no** añadas ahí una aserción de
        `messenger_messages` — por (C) no existe en test.
- [x] **Tarea 6 — Declarar los límites y colocar los hallazgos (AC5)**
  - [x] Dejar constancia de que FR9/G-4b sigue fuera de alcance y por qué.
  - [x] **Hallazgo (B) — `event_store` retiene ids de persona.** **NO se arregla en este PR** y **no se abre
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
  - [x] **La precondición de duplicados NO bloquea: no existe entorno de producción.** El proyecto sigue en
        desarrollo y no hay despliegue real (confirmado por Sergio, 2026-07-30), así que **no hay base con datos
        acumulados sobre la que la recreación del índice pueda fallar**. La comprobación en desarrollo dio `0`
        duplicados y además `event_store` está a **0 filas** (la suite Behat trunca la tabla,
        `FixturesContext.php:131`). La migración se aplica sobre tablas vacías.
  - [ ] **Resto de precaución, barata:** cualquier base local de un desarrollador con filas acumuladas podría
        tener duplicados y hacer fallar el boot (las migraciones corren en el entrypoint). Si el `up()` revienta
        creando el índice, la causa es esa — cuéntalos con
        `SELECT count(*) FROM (SELECT 1 FROM event_store GROUP BY tenant_id, aggregate_id, aggregate_version
        HAVING count(*) > 1) d`. **Y si alguna vez sale > 0, no es solo un obstáculo para la migración: es la
        prueba de que el defecto ya se materializó** y hay streams con versiones repetidas. Merece mirarse por
        sí mismo.
  - [ ] Migración que recrea el índice con `NULLS NOT DISTINCT` (PostgreSQL 18.3 confirmado en el stack local),
        `down()` reversible, más un test que fije el comportamiento nuevo.
  - [ ] **Declarar el cambio de comportamiento en el PR:** appends concurrentes al mismo agregado que hoy pasan
        en silencio empezarán a dar `EventStreamConcurrencyConflict`. Es el comportamiento que el docblock ya
        promete, pero es un cambio real y puede destapar carreras latentes.
- [x] **Tarea 6c — Runbook de migración de datos: NO SE EJECUTA, se documenta**
  - [ ] **No hay filas que limpiar y por tanto no hay runbook que correr.** El proyecto está en desarrollo y
        **no existe entorno de producción** (confirmado por Sergio, 2026-07-30): no hay `messenger_messages` ni
        `failed` desplegados con ids de personas reales. Lo que en un sistema desplegado sería un paso de la
        entrega, aquí es **vacío**. **No inventes trabajo de limpieza para justificarlo, ni escribas en el PR
        que «se purgaron las filas existentes»** — no había ninguna.
  - [ ] Lo que sí hay que hacer: **dejar el procedimiento escrito** en el PR para el día que exista despliegue,
        porque el conocimiento se pierde y esta historia es donde se midió. Orden correcto —**sin downtime y sin
        perder notificaciones**— sería: desplegar → dejar que los workers **drenen `async` solos** (cuenta a 0;
        **y aquí está la trampa que el pase adversarial destapó:** ese drenado **NO envía los correos**, porque
        el mismo despliegue retira `SendEmailOnPasswordResetCompleted` y el único handler que queda es
        `RunProjectionsOnDomainEvent`. O se drena **antes** de desplegar, o se acepta la pérdida de esas
        notificaciones y **se declara**. *No hace falta parar workers:* tras el deploy no se escribe ni
        una fila más de este tipo.
  - [ ] Y el detalle que hace que el `LIKE` funcione, porque es el que se olvida: la purga va **por tipo de
        mensaje, no enumerando sujetos** — se retira la clase entera y nadie toca un UUID. El `body` es
        `addslashes(serialize($envelope))` y `addslashes` **duplica cada backslash**, así que
        `LIKE '%Domain\Event\PasswordResetCompleted%'` **no casa**; usa el nombre corto de clase, sin
        backslashes: `LIKE '%PasswordResetCompleted%'`.
  - [ ] Decir explícitamente que ese procedimiento **no tocaría `event_store`** (ver Tarea 6).
- [x] **Tarea 7 — Gates y pase adversarial (AC6 + definición de hecho de la épica)**
  - [x] `make php.quality`, `make php.unit`, `make php.behat` — frescos, con exit code.
  - [x] Recorrer la checklist de seguridad de `CLAUDE.md` sobre el diff (ver *Seguridad* abajo).
  - [x] **Pase adversarial por alguien distinto del autor, REGISTRADO**, declarando dónde quedó. Sin él la
        historia no llega a `done` (NFR10). Un pase que no encuentra nada cuenta — y también se declara.

## Pase adversarial — REGISTRADO (NFR10 / `CLAUDE.md` → *Security review* → **Process**)

**Dónde queda el registro: aquí, y debe reproducirse en el cuerpo del PR.** **Cuándo:** 2026-07-30, sobre el
contrato y la decisión, **antes** de escribir código. **Quién:** lectura hostil por un revisor **distinto del
autor** de la historia, con instrucción explícita de *romper*, prohibición de aceptar como cierta ninguna
afirmación del artefacto, y obligación de re-medir. **Cobertura declarada:** el artefacto y el código de `api/`;
**no** cubre ejecución de gates, PWA, ni la implementación (que no existe todavía). **Veredicto:** la dirección
①b aguanta; **el contrato no aguantaba** — siete hallazgos, cuatro de ellos ya incorporados arriba.

**Yo verifiqué cada hallazgo antes de aceptarlo, y el revisor también falló un detalle:** situó el
`@SuppressWarnings` de `FulfilIdentityErasure` en la línea 63; está en la **62**. Su recuento de 8 colaboradores
en el constructor sí es correcto. Trátalo como a los demás analistas: acierta en lo grande, hay que medirle lo
pequeño.

### A-1 (ALTA) — el gate era evadible por cinco vías. **INCORPORADO en AC3.**

### A-2 (MEDIA-ALTA) — el envío queda entre el commit y la revocación. **RESUELTO: va DESPUÉS de `revokeSessions`.**

`CompletePasswordReset.php:106` revoca todas las sesiones **post-commit**, y `SendEmailMessage` no está enrutado,
luego `MailerInterface::send()` **bloquea**. Si el envío se coloca antes de esa línea —que es lo que sugiere
«espejo exacto de `RequestPasswordReset:95–101`», donde el envío va al final del método— entonces **con un
servidor de correo colgado las sesiones de una cuenta recién comprometida siguen vivas durante todo el timeout
SMTP**, que es justo la ventana que este flujo existe para cerrar; y además retrasa la cookie de la sesión nueva.
**El espejo no es transplantable:** `RequestPasswordReset` es anónimo y de respuesta uniforme; `complete()`
termina en una revocación crítica. **Requisito: el envío va DESPUÉS de `revokeSessions`**, y AC3b debe pinnar
también ese orden, no solo el orden respecto al commit.

### A-3 (MEDIA) — desenrutar mete el runner de proyecciones en la transacción. **INCORPORADO en la tabla ①b.**

`RunProjectionsOnDomainEvent` maneja **todo** `DomainEvent`, y `ProjectionRunner::catchUp` hace
`entityManager->wrapInTransaction(...)` con `checkpointStore->lockAndRead($name)` **por cada proyector**
(`ProjectionRunner.php:41–44,56–60`). Al desenrutar, eso pasa a ejecutarse dentro de la transacción del reset,
tomando locks de `projection_checkpoint`, y **un proyector que lance rolea el reset**. *Contexto que el revisor
no dio y que baja la gravedad:* esto **ya es así hoy para los otros 15 eventos sin enrutar**, así que ①b alinea
este evento con la norma en vez de estrenar un riesgo. Pero la frase «sin rollback del reset» era falsa y está
corregida.

### A-4 (MEDIA) — ④ desarma el único observable de `failed`. **RESUELTO: ④ sale del alcance.**

Medido: `ReportDeadLetterBacklogMessage` nace con `maxBacklog = 0` y `maxAgeHours = 24`, y el handler solo calla
si `total <= maxBacklog` **y** `oldestAgeHours <= maxAgeHours` (`ReportDeadLetterBacklogHandler.php:43–45`). Con
`maxBacklog = 0`, **hoy cualquier mensaje en reposo dispara la alarma**, y esa alarma es la única señal (Sentry
está deliberadamente sin cablear). Podar `failed` convierte «alarma hasta que un humano haga
`messenger:failed:retry`» en **pérdida silenciosa del efecto**. La afirmación de AC3c «la alarma horaria
existente sigue funcionando» es **engañosa**. **Resuelto sacando ④ del alcance** — ver la sección *④ … FUERA DE ALCANCE* arriba, con el argumento de los tres analistas y la forma que tendría que tener si algún día vuelve.

### A-5 (MEDIA) — el runbook se contradecía. **INCORPORADO en la Tarea 6c.**

### A-6 (BAJA-MEDIA) — el correo miente, y con A-2 mal resuelto mentiría siempre.

`SymfonyPasswordChangedEmailSender.php:57,72` afirma *«We signed out all your open sessions for security»*, pero
`RevokeSessionsBestEffort` **traga el fallo**. Hoy el orden es indeterminado; con el envío antes de la revocación
el correo precedería **siempre** al hecho que afirma. Resolver A-2 lo mitiga; queda el caso del revoke fallido.
Decide si el texto se ablanda o si se acepta y se declara.

### A-7 (BAJA-MEDIA) — nadie captura `EventStreamConcurrencyConflict`. **Afecta a la Tarea 6b.**

`git grep` en `api/src` lo encuentra **solo** en `DbalEventStore` (declaración y lanzamiento): **ningún caso de
uso reintenta**. Activar `NULLS NOT DISTINCT` convierte appends concurrentes al mismo agregado en un 409 crudo
al cliente por caminos que hoy pasan en silencio. Enumera esos caminos antes de mergear, o añade reintento, o
saca 6b del PR. **Y ten presente que es un cambio de concurrencia del `event_store` compartido embutido en una
historia GDPR** — si al enumerarlos aparece más de un camino afectado, sácalo.

### Sospecha no cerrada (pase del contrato)

AC1 (`0 outbox events … on queue "async"`) **también pasa si el reset falló** y no hubo evento. Escribe la
aserción en el escenario que ya lleva `password_reset.feature:127` (que exige el evento almacenado), para que no
pueda leerse verde por ausencia de flujo.

### Detalle que invalida el `LIKE` del runbook

`PhpSerializer::encode()` hace `base64_encode($body)` si el resultado no es UTF-8 válido
(`vendor/symfony/messenger/Transport/Serialization/PhpSerializer.php:165–167`). En ese caso **ningún `LIKE` de
texto plano casa**. El runbook debe contemplarlo.

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

Claude Opus 5 (1M context) — `claude-opus-5[1m]`.

### Debug Log References

Gates, ejecución fresca con exit code capturado (2026-07-30, worktree
`iam-password-reset-completed-transport-leak-dw8t`):

- `make php.quality` → **0** (incluye `php.stan`, `php.md`, `php.deptrac` 0 violations / 0 uncovered,
  `php.lint.error-contract`, `php.lint.bounded-context`, `php.lint.event-bus`, `php.lint.audit-resource`,
  `php.lint.persistent-transport`).
- `make php.unit` → **0** — 2075 tests, 9047 aserciones. Los 2 *notices* / 2 *skipped* son los benchmarks
  opt-in de `ExceptionResponderBenchmarkTest`, preexistentes (`make php.bench` los ejecuta).
- `make php.behat` → **0** — 382 escenarios, 3456 steps.

### Completion Notes List

**Corrección medida al contrato de la historia.** AC3b afirma que `CompletePasswordResetTest` «hoy no existe
— hueco medido». **Existe** desde #491 (269 líneas, `git log` sobre el fichero lo confirma). El trabajo no fue
crearlo sino extenderlo; las tres propiedades nuevas viven en una clase hermana,
`CompletePasswordResetNotificationTest`, porque son un contrato distinto con su propio fixture (y porque
juntas rompían el umbral de métodos públicas de PHPMD).

**AC1 — fuga cerrada.** Línea de routing borrada (desenrutado, no `sync://`). Falsificado dos veces contra la
config real: reintroducir la línea exacta hace fallar el gate y el escenario Behat (`1 outbox events were
created on queue "async", but 0 was expected`); un comodín `Erpify\Iam\Identity\Domain\Event\*: async` hace
fallar el gate señalando los **seis** eventos de `Iam.Identity`.

**AC2 — se sigue notificando.** Envío inline post-commit vía `SendPasswordChangedEmailBestEffort`
(espejo de `SendPasswordResetEmailBestEffort`). *Matiz obligatorio y no omitido:* con `MAILER_DSN=null://null`
en `compose.yaml` y `compose.prod.yaml`, la notificación **no se entrega** realmente — AC2 se cumple a nivel de
código, no de configuración desplegada.

**AC3 — el control.** Registro `api/.persistent-transport-policy` (clavado en `aggregateType()`, no en el
FQCN) + `PersistentTransportPolicyGateTest` + motor `Erpify\Tests\Support\PersistentTransportPolicy` + target
`make php.lint.persistent-transport`, cableado en `php.quality` **y** `php.quality.dry-run`. Las seis vías que
`SendersLocator` resuelve están cubiertas con un fixture cada una (clase exacta, clase padre, **interfaz**,
comodín de namespace, `'*'` desnudo, y `#[AsMessage]` sin entrada de routing). Verificado contra
`vendor/symfony/messenger/Handler/HandlersLocator.php:62-71` y `Transport/Sender/SendersLocator.php:50-79`, no
contra memoria. Ningún evento de `src` lleva `#[AsMessage]` hoy, ni ninguno implementa interfaz: por eso esas
dos vías necesitan fixtures propios bajo `tests/Unit/Shared/Architecture/Fixture/`.

**Desviación argumentada del contrato en el check de completitud.** La historia lo acota a *«todo
`aggregateType()` que aparezca en `routing`»*. Implementado más amplio: **todo `aggregateType()` declarado en
`src`**, enrutado o no. Razón: la versión estrecha deja que la clasificación la escriba quien enruta, en el
mismo diff que introduce el defecto — podría escribir `Iam.Identity => non-person` y pasar. Clasificando ya,
tiene que **sobrescribir** una línea `person` existente, que es un diff mucho más visible. Coste: 5 líneas de
registro y una línea por agregado nuevo.

**AC3b — tres propiedades, tres tests, cada uno falsificado.** Muestreo *en el momento del envío* (hook
`observe` en el doble del mailer) en vez de un registrador compartido: pinna lo mismo sin instrumentar el
`InMemorySessionRepository` del contexto `Iam/Session` desde un test de `Iam/Identity`. Falsificaciones
ejecutadas y restauradas copiando bytes (nunca `git checkout --`): envío dentro de la transacción → falla
«sent inside the transaction»; envío antes del revoke → falla «sent before the sessions were revoked».

**AC3c — el correo.** *«We signed out all your open sessions for security»* retirado. La revocación es
best-effort y traga sus fallos, así que el correo afirmaba un resultado no garantizado — y con el envío ya
ordenado **después** del revoke lo afirmaría de forma determinista en la ejecución donde el revoke falló. Texto
nuevo: *«Your previous password no longer works.»*, que es lo que el reemplazo de la credencial sí garantiza.
Un test pinna la ausencia de las tres formas de la afirmación retirada, no solo la presencia de la nueva.

**AC4 — comentarios.** El comentario de routing enuncia la regla sobre el código actual, sin narrar el cambio
ni citar el defecto anterior; sin IDs de historia ni de requisito en `src`. Corregido el docblock falso de
`PasswordResetCompleted` (*«no consumer yet»*) — y de paso su afirmación de que el evento es «PII-free», que es
justo lo contrario de la premisa de esta historia.

**AC5 — límites declarados.** FR9/G-4b (generalizar a *«todo evento cuyo agregado sea una persona»*) sigue
fuera de alcance, bloqueada por la pregunta abierta de *ownership de referencias nacidas en configuración*. El
hallazgo (B) —`event_store` conserva el id real para siempre— ya es la **Story 1.7 (G-5)** de la épica; no se
abre issue. La cabecera del registro y `PRODUCTION_SECURITY_CHECKLIST.md` declaran los dos límites del gate: es
del **agregado**, no del payload (`Iam.Session`.`userId`, `Iam.Invitation`.`invitedUserId` quedan fuera), y no
dice nada del `event_store`.

**Tarea 6b — SACADA del PR por su propio criterio, con la medición que lo decide.** A-7 fijó por adelantado:
*«enumera los caminos afectados; si aparece más de uno, sácalo»*. Enumeradas las **20** clases que publican eventos de
dominio: **solo 4 toman lock de fila sobre el agregado por el que publican** (`AcceptInvitation`,
`ChangeUserStatus`, `ChangeUserRoles` y `CompletePasswordReset`). Las **16** restantes no. *(La cifra
«15 de 18» que esta nota llevó primero era errónea; la corrigió el pase adversarial — ver I-14.)* Además el `INSERT` del `event_store` es DBAL crudo
que corre en `publish()`, **antes** del flush del ORM, así que el `UPDATE` de la entidad no los serializa
tampoco. Activar `NULLS NOT DISTINCT` convertiría carreras hoy silenciosas en 409 crudos en 15 caminos a la vez,
dentro de una historia GDPR. Registrado en `deferred-work.md` con la enumeración completa y con el hallazgo más
grande que destapa: la premisa del docblock de `DbalEventStore:26-27` («serializado por el lock de fila que la
transacción ya mantiene») es **falsa para 15 de 18 publicadores**, así que arreglar el índice sin arreglar eso
solo cambia duplicados silenciosos por 409 silenciosos. *Confirmado empíricamente contra la BD viva* vía
`pg_indexes`: el índice real no lleva la cláusula.

**`sync: 'sync://'` — se CONSERVA, y no por inercia.** Es el único transporte no persistido que la config
declara, y es el valor que la política nombra como destino permitido para un agregado-persona. Retirarlo
dejaría indeclarable la propia excepción sancionada del gate.

**Deuda propuesta, NO aplicada (queda a decisión de Sergio).** `CompletePasswordReset` sube a acoplamiento 13
con el colaborador nuevo y lleva `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` **con su argumento en el
docblock**. El único colaborador retirable honestamente es la muralla de estado: `wallUnlessActive()` le hace
dos preguntas al agregado y decide por él (Tell-Don't-Ask), así que `User` podría poseerla y llevarse
`AccountSuspended`/`AccountDeactivated` con ella → acoplamiento 11. Es un cambio a una muralla de seguridad y no
se cuela en una PR de GDPR.

**Nota de seguridad honesta, no omitida.** Al retirar el reactor desaparece su resolución del destinatario en
el momento del handling, que su docblock vendía como garantía anti-resurrección. Con envío post-commit inmediato
la ventana pasa de *segundos-a-minutos en un worker* a *milisegundos*, y la dirección se lee de la fila viva
dentro de la transacción: en la práctica mejora. Y segundo: `SendPasswordChangedEmailBestEffort` loguea
`['exception' => $throwable]` sin destinatario — igual que su hermano —, pero un `TransportException` de
Symfony puede llevar la dirección dentro de su mensaje. Preexistente en `SendPasswordResetEmailBestEffort`; se
declara en vez de callarse.

**Checklist de seguridad de `CLAUDE.md`, clase por clase.** *Frontend (`pwa/`)*: **no aplica** — cero cambios
en `pwa/`. *Backend*: **inyección** no aplica (ninguna query nueva; el gate lee YAML y reflexión sobre ficheros
del repo, sin entrada de usuario); **authn/authz** no aplica (ningún controller ni handler HTTP nuevo; el
handler retirado no tenía voter porque era consumidor de bus); **validación de entrada** no aplica (ninguna
superficie HTTP ni DTO nuevos); **mass assignment** no aplica; **encoding/serialización** — el cuerpo del
correo sigue estático y escapado por `BulletproofEmailChrome`, sin interpolar dato de usuario; **secretos** —
el evento no lleva token y `SendEmailMessage` sigue **sin enrutar** (verificado, no asumido), sin `.env*` en el
diff; **CORS/CSRF/Mercure** intactos; **migraciones** ninguna (6b diferida a propósito); **handlers de
Messenger idempotentes** — la dedup del reactor desaparece **porque desaparece la entrega at-least-once**, no
por descuido: sin transporte no hay redelivery, y los reintentos que quedan mueren en el `consume()` de un solo
uso del token, ya pinnado en `password_reset.feature`. Queda dicho, no implícito.

**Estado de la rama frente a `main`.** La rama parte de `f4dbe4d1` (baseline de la historia) y `main` está
**1 commit por delante** (`009b0756`, #611, sobre `pwa/`). Por eso un `git diff main` muestra `pwa/CLAUDE.md` y
`pwa/eslint.config.mjs` como revertidos: no son cambios de esta rama. Conviene rebasar antes de mergear.

**Pase adversarial de la IMPLEMENTACIÓN: EJECUTADO y registrado abajo** (sección *Pase adversarial —
IMPLEMENTACIÓN*). Encontró un defecto ALTO en el propio cambio y varias afirmaciones falsas; todo lo
confirmado está arreglado y re-falsificado.

### File List

**Nuevos**

- `api/.persistent-transport-policy`
- `api/src/Iam/Identity/Application/SendPasswordChangedEmailBestEffort.php`
- `api/tests/Support/PersistentTransportPolicy.php`
- `api/tests/Support/MessengerRoutingConfig.php`
- `api/tests/Unit/Shared/Architecture/PersistentTransportPolicyGateTest.php`
- `api/tests/Unit/Shared/Architecture/PersistentTransportRoutingShapeGateTest.php`
- `api/tests/Unit/Shared/Architecture/Fixture/PersonAggregateFixtureEvent.php`
- `api/tests/Unit/Shared/Architecture/Fixture/PersonScopedFixtureEvent.php`
- `api/tests/Unit/Shared/Architecture/Fixture/AsMessageRoutedFixtureEvent.php`
- `api/tests/Unit/Shared/Architecture/Fixture/AsMessageInheritedFixtureEvent.php`
- `api/tests/Unit/Shared/Architecture/Fixture/AbstractAsMessageCarrier.php`
- `api/tests/Unit/Shared/Architecture/Fixture/AsMessageCarrierContract.php`
- `api/tests/Unit/Shared/Architecture/Fixture/PersistedRoutingFixtureAttribute.php`
- `api/tests/Unit/Iam/Identity/Application/CompletePasswordResetNotificationTest.php`
- `api/tests/Unit/Iam/Identity/Application/SendPasswordChangedEmailBestEffortTest.php`
- `api/tests/Unit/Iam/Identity/Application/RecordingLogger.php`

**Modificados**

- `api/config/packages/messenger.yaml`
- `api/src/Iam/Identity/Application/CompletePasswordReset.php`
- `api/src/Iam/Identity/Application/PasswordChangedEmailSender.php`
- `api/src/Iam/Identity/Domain/Event/PasswordResetCompleted.php`
- `api/src/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSender.php`
- `api/features/backoffice/identity/password_reset.feature`
- `api/tests/Unit/Iam/Identity/Application/CompletePasswordResetTest.php`
- `api/tests/Unit/Iam/Identity/Application/InlineTransactionManager.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSenderTest.php`
- `make/php-quality.mk`
- `CLAUDE.md`
- `PRODUCTION_SECURITY_CHECKLIST.md`
- `docs/architecture-api.md`
- `docs/architecture/event-catalog.md`
- `docs/claude-code-quickref.md`
- `docs/rules/security.md`
- `_bmad-output/implementation-artifacts/deferred-work.md`
- `_bmad-output/implementation-artifacts/sprint-status.yaml`
- `_bmad-output/planning-artifacts/epics-gdpr-hardening.md`

**Movidos**

- `api/tests/Unit/Iam/Identity/Infrastructure/Messenger/RecordingPasswordChangedEmailSender.php` →
  `api/tests/Unit/Iam/Identity/Application/RecordingPasswordChangedEmailSender.php`

**Borrados**

- `api/src/Iam/Identity/Infrastructure/Messenger/SendEmailOnPasswordResetCompleted.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Messenger/SendEmailOnPasswordResetCompletedTest.php`

## Change Log

| Fecha | Cambio |
|-------|--------|
| 2026-07-30 | Implementación de ①b: evento desenrutado, notificación post-commit best-effort tras la revocación, reactor y su dedup retirados. |
| 2026-07-30 | Gate nuevo `php.lint.persistent-transport` + registro `api/.persistent-transport-policy`, cubriendo las seis formas de routing que `SendersLocator` resuelve. |
| 2026-07-30 | Cuerpo del correo reescrito: deja de afirmar el cierre de sesiones, que es best-effort. |
| 2026-07-30 | Tarea 6b (UNIQUE de stream) sacada del PR por el criterio de A-7 — 16 de 20 publicadores sin lock de fila sobre su agregado; registrada en `deferred-work.md`. |
| 2026-07-30 | Pase adversarial de la implementación: 17 hallazgos. `Iam.Session` reclasificado a `person` (dos de sus eventos llevan el id de usuario como `aggregate_id`), lector de `#[AsMessage]` alineado con Symfony, gate leyendo toda la config y uniendo entornos, resolución por evento en vez de por clave, y el correo reducido a lo que la credencial garantiza. |


## Pase adversarial — IMPLEMENTACIÓN (NFR10). REGISTRADO

**Dónde queda:** aquí, y reproducido en el cuerpo del PR. **Cuándo:** 2026-07-30, sobre el código ya escrito.
**Quién:** tres lecturas hostiles independientes por revisores **distintos del autor**, con instrucción
explícita de *refutar* y de re-medir contra `vendor/` y la BD, y prohibición de aceptar como cierta ninguna
afirmación del artefacto. **Alcance declarado:** (1) el gate nuevo y su motor; (2) el reorden de
`complete()`, la transacción y la entrega; (3) toda afirmación del diff — prosa, comentario, cuerpo del
correo, checklist — contra el código. **No cubre:** PWA (no hay cambios), ni el despliegue.
**Veredicto: la dirección ①b aguanta; la implementación NO aguantaba.**

**Yo verifiqué cada hallazgo antes de aceptarlo, y los revisores también fallaron detalles** — uno situó una
falsificación como "no detectada" que en realidad falló por *mi* arnés de shell (backslashes duplicados), y
otro dio por bueno un `#[AsMessage]` como «el único vector sin rastro en config» cuando `TransportNamesStamp`
también lo es. Trátalos como a los analistas: aciertan en lo grande, hay que medirles lo pequeño.

### I-1 (ALTA) — `Iam.Session => non-person` era FALSO, y el gate habría dado luz verde a la misma fuga. **ARREGLADO.**

Hallado por dos de los tres revisores por caminos distintos. `AllSessionsRevoked` y `OtherSessionsRevoked`
tienen **`aggregateId` = `userId`** y payload vacío (`AllSessionsRevoked.php:12-14`, `RevokeAllSessions.php:35`,
`RevokeOtherSessions.php:39`): forma **byte-idéntica** a `PasswordResetCompleted`. Mi registro los declaraba
seguros. Enrutar `AllSessionsRevoked: async` pasaba el gate en verde metiendo un id de persona en
`messenger_messages` — el defecto exacto que la historia cierra, por el evento hermano que **el mismo
`complete()` publica**.

La causa raíz es de modelado, no un descuido: **la clave del registro (`aggregateType`) es más gruesa que la
propiedad que clasifica (qué denota el `aggregate_id`)**, y `Iam.Session` es mixto. Arreglado clasificándolo
`person` (el tipo toma el veredicto de su evento más expuesto), reescribiendo la cabecera —que describía la
exposición de Session como *de payload*, que es justo lo que la hacía invisible— y **declarando la coarseness
como límite** en vez de dejarla implícita. Falsificado: `AllSessionsRevoked: async` ahora rompe el gate.

### I-2 (ALTA) — el gate no leía `#[AsMessage]` como lo lee Symfony. **ARREGLADO.**

`SendersLocator::getTransportNamesFromAttribute()` (`vendor/.../SendersLocator.php:82-89`) recorre
`[$clase] + class_parents() + class_implements()`, filtra con `ReflectionAttribute::IS_INSTANCEOF` y **fusiona**
con `array_merge`. Mi lector hacía las tres cosas mal: solo la clase concreta, sin `IS_INSTANCEOF`, y
asignando (last-wins). Consecuencia medida por el revisor: `#[AsMessage('async')]` sobre un padre abstracto,
sobre una interfaz o vía subclase del atributo enruta y el gate no ve nada; y con `#[AsMessage('async')]
#[AsMessage('sync')]` el gate reportaba solo `sync`. Arreglado y cubierto con fixtures propios
(`AbstractAsMessageCarrier`, `AsMessageCarrierContract`, `PersistedRoutingFixtureAttribute`,
`AsMessageInheritedFixtureEvent`, y el atributo repetido en `AsMessageRoutedFixtureEvent`).

### I-3 (ALTA) — el gate leía UN fichero de config. **ARREGLADO.**

Symfony fusiona `config/packages/*.yaml`, `config/packages/<env>/*.yaml` y `config/services*.yaml`. Un
`config/packages/prod/messenger.yaml` enrutaba de verdad y era invisible — y `config/packages/test/` ya existe
como precedente. Ahora lee todos. La config en PHP no se parsea: se **tripwirea** (falla si un `.php` de
config menciona Messenger) en vez de fingir que la entiende.

### I-4 (ALTA) — la unión de entornos: incluir `when@*` **debilitaba** el gate. **ARREGLADO.**

Mi razonamiento escrito («incluirlos solo puede hacerlo más fuerte») era **falso para mi propio código**:
`$routes[$key] = ...` es last-wins y las secciones se leen en orden de documento, así que una entrada
`when@test` (donde todo es in-memory) **borraba** la ruta de producción. Ahora es unión.

### I-5 (MEDIA) — la forma `senders:` anidada. **ARREGLADO.** Deprecada en 8.1 y viva hasta 9.0
(`Configuration.php:1720-1723`); mi lector se quedaba solo con strings y la tiraba en silencio.

### I-6 (MEDIA) — `sync` se confiaba por NOMBRE. **ARREGLADO** con un control que lee el DSN: redefinir
`sync: 'doctrine://…'` convertía la única escapatoria sancionada en la fuga, con todo verde.

### I-7 (MEDIA) — dos aserciones del gate se volvían mutuamente insatisfacibles. **ARREGLADO.** El canario
exigía un `person` **sin excepción**; el check de obsolescencia exige que todo tipo del registro lo emita
algún evento. El día que `Iam.Identity` ganara un ADR de excepción —el camino que el propio diseño bendice—
un test exigiría borrar la línea y el otro conservarla, sin build verde posible. Ahora el canario exige un
`person` cualquiera.

### I-8 (MEDIA) — `assertFileExists` acepta DIRECTORIOS. **ARREGLADO.** `person :: docs` silenciaba la
política sin ADR alguno. Ahora exige fichero real con sufijo `.md`.

### I-9 (MEDIA) — falso positivo del comodín. **ARREGLADO reestructurando.** `SendersLocator` salta los tipos
comodín en cuanto un tipo concreto ya casó, así que `{'*': async, Evento: sync}` es correcto y mi gate lo
marcaba en rojo. La causa era resolver **por clave de routing**; ahora resuelve **por evento**, portando
`SendersLocator::send()` y `HandlersLocator::listTypes()`. El mismo cambio elimina la clase de falso positivo
que enseña a la gente a rodear un gate.

### I-10 (MEDIA) — nada pinnaba que un reset RECHAZADO no envía correo. **ARREGLADO.**

El revisor construyó una implementación errónea que pasaba **todos** los tests del diff: enviar el correo
desde un `catch` alrededor de la transacción. Notificaría un cambio de contraseña que no ocurrió a cada cuenta
walled y a cada token caducado — alerta de seguridad falsa, y oráculo de existencia en los caminos walled.
Mi primer arreglo fue **cosmético** y lo descubrí falsificando: las tres rechazos del helper compartido lanzan
*antes* de abrir la transacción, así que la aserción no mordía. Los casos que muerden son los dos rechazos
**dentro** de la transacción; ahí están ahora, y la implementación errónea produce 2 fallos.

### I-11 (MEDIA) — `SendPasswordChangedEmailBestEffort` no tenía test propio. **ARREGLADO.** Sustituir todo el
cuerpo del `catch` por `// ignore` pasaba la suite entera. Ahora hay tres tests: pasa-a-través, traga-y-loguea
a WARNING con la excepción en contexto, y **no** loguea el destinatario.

### I-12 (MEDIA) — la observabilidad que se pierde no estaba declarada. **DECLARADO** (no arreglado aquí).

Medido por el revisor: en prod `monolog.yaml:61-67` es `fingers_crossed` con `action_level: error`, así que un
`warning` sin error acompañante **se descarta**: un fallo de envío no deja rastro. Lo que el reactor borrado
daba y ya no existe: captura en Sentry desde el worker, fila en `failed` reintentable a mano, y la alarma de
dead-letter (que dispara con `maxBacklog = 0`). El intercambio de *entrega* (at-least-once → at-most-once) sí
estaba declarado; el de *observabilidad* no lo estaba, y no es lo mismo. **No se arregla en esta PR** —
cablear una alarma es otra decisión— pero queda dicho aquí y en el PR, y la analogía con
`RevokeSessionsBestEffort` **no es fiel**: aquel traga un efecto *redundante* (la credencial ya
des-autentica), mientras que esta notificación es el único canal fuera de banda por el que la víctima de un
robo de cuenta se entera.

### I-13 (MEDIA) — el envío bloquea **antes** del login, y eso no estaba escrito. **DECLARADO.**

`complete()` retorna y el controller hace `security->login(...)`. Con SMTP colgado y sin timeout configurado
(`SocketStream` cae a `default_socket_timeout`, 60 s — issue #612), el usuario espera con **todas sus sesiones
ya revocadas** y sin cookie nueva; si el cliente abandona, el token ya está consumido y hay que reiniciar el
flujo. El docblock argumentaba el orden frente al revoke y callaba el orden frente al login.

### I-14 (MEDIA) — la cifra «15 de 18 publicadores» era **errónea**. **CORREGIDA a 16 de 20.**

Recuento propio: `git grep -l 'eventBus->publish(' api/src` → **20** clases. Con lock de fila sobre el
agregado por el que publican: `AcceptInvitation`, `ChangeUserStatus`, `ChangeUserRoles` y —lo que yo había
omitido— **`CompletePasswordReset`**, que bloquea la fila del usuario y publica un evento cuyo `aggregateId`
**es** ese usuario. Son 4, no 3. `RequestPasswordReset` bloquea al usuario pero publica el evento del token.
Corregido en `deferred-work.md`, en estas notas y en el PR. La cifra es *load-bearing*: es la medición que
justificó diferir la Tarea 6b, y la iba a heredar la historia siguiente.

### I-15 (MEDIA) — el inventario de ids de persona en `event_store` estaba **incompleto**. **CORREGIDO.**

Faltaban `AllSessionsRevoked` y `OtherSessionsRevoked` (como `aggregate_id`) y `SessionRevoked` (en payload).
Importa porque la **Story 1.7 (G-5)** dice explícitamente *«el inventario está ahí y no se reenumera»*: iba a
heredar una lista corta en tres eventos, dos de ellos de la forma más expuesta.

### I-16 (MEDIA) — documentación con afirmaciones falsas o contradictorias. **CORREGIDAS.**

`PRODUCTION_SECURITY_CHECKLIST.md` seguía diciendo, 100 líneas antes de mi entrada nueva, que la notificación
*«rides the async reactor»* — el reactor que esta PR borra. `event-catalog.md` decía «dos agregados emiten» y
«todos van a `async`» (son cinco tipos y 7 de 23 eventos), y que el evento «también se encola» siempre.
`architecture-api.md` decía «una excepción deliberada» en la viñeta a la que yo había añadido la tercera. Y
**ambos** ficheros seguían prometiendo el control de concurrencia optimista del `event_store` que esta misma
rama midió como **inexistente** — dejar la promesa mientras se difiere el arreglo es exactamente el modo de
fallo del repo.

### I-17 (BAJA) — *«Your previous password no longer works»* tampoco estaba garantizado. **ARREGLADO.**

No hay constraint que exija que la contraseña nueva difiera de la vieja (`ResetPasswordRequest` solo valida
`NotBlank` + `Length`), así que un reset a la misma contraseña falsifica la frase en la ejecución que la
envía. Es el mismo defecto que la frase que ella sustituía. El correo ahora afirma **solo** el cambio de
credencial. Y el razonamiento con el que yo había retirado la frase anterior era **erróneo**: dije «el sistema
no lo garantiza», pero el revisor midió que la credencial **sí** des-autentica nativamente vía
`ContextListener::hasUserChanged()`; el motivo correcto es que las filas de `iam_session` pueden quedar
`ACTIVE` en el registro propio de la app y la des-autenticación es perezosa. Corregido en el docblock.

### Hallazgos NO arreglados, declarados como límites del gate

`TransportNamesStamp` (se honra **antes** que el mapa y que el atributo), mensajes que no son `DomainEvent`,
config en PHP (tripwireada), y el **payload** frente al `aggregate_id`. Los cuatro están ahora en la cabecera
del registro, que es el sitio donde alguien lee qué prueba un build verde.
