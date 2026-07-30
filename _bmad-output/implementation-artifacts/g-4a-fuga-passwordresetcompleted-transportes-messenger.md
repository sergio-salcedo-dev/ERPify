---
baseline_commit: f4dbe4d1
---

# Story 1.1 (G-4a): Cerrar la fuga de `PasswordResetCompleted` en los transportes Messenger persistidos

Status: ready-for-dev

<!-- Validación opcional: correr `validate-create-story` antes de `dev-story` para un check de calidad. -->

> **La primera tarea no es código, es una decisión por escrito.** La épica recomienda ① (desenrutar a `sync`)
> pero deja el mecanismo abierto, y la precondición normativa prohíbe empezar a implementar antes de registrar
> la elección. **Léela con los cuatro hechos medidos del bloque siguiente**: dos de ellos no están en el corte
> de épica ni en el addendum y **cambian el coste de ①** — desenrutar no es «no entregar», es *entregar dentro
> de la transacción de escritura*; y el `event_store` conserva el mismo id para siempre pase lo que pase con el
> routing. La decisión se toma sobre esos hechos, no sobre la frase de una línea del addendum.

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

## Decisión abierta — **Tarea 1, precondición normativa de la épica**

Ninguna implementación empieza antes de que esto quede **registrado por escrito** (cuerpo del PR o este
artefacto). La épica recomienda ①; la primera tarea es **confirmarla o refutarla**, no darla por buena en
silencio. La medición (A) abre un sub-fork dentro de ① que la épica no podía ver:

| Opción | Qué hace | Coste medido |
|--------|----------|--------------|
| **①a** | Desenrutar (borrar la línea 28) o enrutar a `sync`, **dejando el reactor** | El envío SMTP entra en la transacción con el lock de fila tomado, y un fallo de correo **rollea el reset**. Notifica antes del commit. **Refutada por medición** salvo argumento explícito en contra. |
| **①b** *(recomendada)* | Desenrutar **y** mover la notificación a un envío **post-commit best-effort** en `CompletePasswordReset`, espejo exacto de `RequestPasswordReset:95–101` y `RevokeSessionsBestEffort` | Garantía **estructural**: no hay copia persistida porque no hay transporte. Sin SMTP en transacción, sin rollback del reset, sin notificar un cambio no comiteado. Cuesta retirar el reactor y su claim de deduplicación (bajo entrega única post-commit no hay redelivery que deduplicar). |
| **②** | Mantener `async` y purgar `messenger_messages` + `failed` en la cadena de erasure | Compensatoria, no estructural: cuerpos serializados, `failed` no se vacía nunca, y añade **otro colaborador** a `FulfilIdentityErasure`, que ya está por encima del umbral y lo declara (`@SuppressWarnings("PHPMD.CouplingBetweenObjects")`, línea 62). |
| **③** | Transporte con TTL | No borra la PII: la **envejece**. Refutada por el propio invariante SI-21. |

**Regístralo así:** opción elegida · por qué · qué alternativa se descarta y con qué argumento · dónde queda
el registro. Si eliges ①b, di explícitamente que retiras el reactor y por qué la deduplicación deja de hacer
falta.

## Acceptance Criteria

**AC1 — El id del sujeto borrado no sobrevive en ningún transporte persistido (FR1).**
**Given** un usuario que completó un restablecimiento de contraseña,
**When** se ejerce su borrado,
**Then** ninguna fila de `messenger_messages` ni de `failed` contiene su id.
*Cómo se pinna:* por **hecho (C)**, en test no existe `messenger_messages`; la aserción es *«el flujo de reset
no crea evento en la cola `async`»* con el vocabulario de `OutboxContext`. El AC se satisface **de raíz** (no
hay transporte) o **por barrido** (opción ②), según la decisión de la Tarea 1 — y el test debe reflejar cuál.

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
*Plantilla de la casa:* test bajo `api/tests/Unit/Shared/Architecture/`, `#[CoversNothing]`, con la preambulo
de fallo en una **constante de clase** para que CI la pueda grepear (precedente:
`EventDispatchGateTest::FAILURE_PREAMBLE`). Si el control se envuelve en un target `php.lint.*`, **tiene que
entrar en `php.quality` Y en `php.quality.dry-run`** (NFR11 — CI corre el *dry-run*; ver
`make/php-quality.mk:148,166,173–174`). Si en cambio se deja como test unitario normal, corre ya bajo
`make php.unit` (`api/tools/phpunit/phpunit.dist.xml` incluye todo `tests/`) y **no** hace falta target nuevo:
di cuál de las dos formas eliges y por qué.

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

- [ ] **Tarea 1 — Registrar la decisión de mecanismo (AC1; PRECONDICIÓN, bloquea todo lo demás)**
  - [ ] Leer los hechos (A) y (B) del `Estado medido` antes de elegir.
  - [ ] Elegir entre ①a / ①b / ② / ③ y escribir: opción · argumento · alternativa descartada · dónde queda.
  - [ ] Confirmar o refutar **por escrito** la recomendación ① de la épica. Si se refuta, decir contra qué hecho.
- [ ] **Tarea 2 — Cerrar la fuga (AC1)**
  - [ ] Aplicar el mecanismo elegido en `api/config/packages/messenger.yaml`.
  - [ ] Si es ①b: mover el envío a post-commit best-effort en `CompletePasswordReset`, espejando
        `RequestPasswordReset:95–101`; retirar `SendEmailOnPasswordResetCompleted` y su claim de deduplicación,
        y borrar su test (`api/tests/Unit/Iam/Identity/Infrastructure/Messenger/SendEmailOnPasswordResetCompletedTest.php`).
  - [ ] Verificar que el evento **se sigue publicando y almacenando** — `password_reset.feature:127` afirma
        `there should be 1 event stored named "erpify.iam.identity.password-reset-completed"` y **no puede
        romperse**: el evento no desaparece, deja de viajar por un transporte persistido.
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
- [ ] **Tarea 6 — Declarar los límites y el hallazgo nuevo (AC5)**
  - [ ] Dejar constancia de que FR9/G-4b sigue fuera de alcance y por qué.
  - [ ] **Abrir issue** por el hecho (B): el `event_store` conserva ids de persona a perpetuidad y ninguna cadena
        de borrado lo toca; ningún ADR declara ese eje borrable. **No lo arregles en este PR.**
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
