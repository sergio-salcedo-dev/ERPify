# ADR — Auditoría operativa / de actor (`AuditLogger` → `audit_log`), eje separado del stream de dominio

> **Status:** accepted · **Date:** 2026-06-14 · **Last reviewed:** 2026-07-20 (D3 amended by D3.1 — `activity` writes synchronously)
> · **Scope:** cross-cutting `Shared` subsystem (capture + contract) + `Backoffice/Audit` module
> (read side). `BankAccountsViewed` is its first consumer; the **subsystem implementation** is
> its own epic and is not mixed with the code of the feature that surfaced it.
>
> Contexto temporal: la aplicación **no está en producción**; las tablas y políticas de retención
> de este ADR nacen sin compatibilidad hacia atrás.

## Contexto

Un ERP necesita responder preguntas que el stream de dominio **no** responde: *¿qué hizo un usuario
a lo largo de su jornada?*, *¿accedió o modificó algo que no debía?*, *reconstruir su sesión antes
de que algo se rompiera*. Esto es **observabilidad del actor**, no verdad de negocio.

ERPify ya tiene una auditoría, pero de **otro eje**: cada `DomainEvent` despachado se persiste en
`domain_event` como `StoredDomainEvent` (ver [`architecture-api.md`](../architecture-api.md) y la
tabla de [`bank-bankaccount-modeling.md`](./bank-bankaccount-modeling.md)). Eso audita *qué le pasó
al agregado* (estado), no *qué hizo la persona* (operación). Modelar `BankAccountsViewed` —una
lectura— hizo visible el hueco: una vista no es un cambio de estado de ningún agregado.

## Decisiones

### D1 — Eje separado: `AuditLogger` ≠ `DomainEvent`; **no** se renombra `StoredDomainEvent`

Se introduce un eje nuevo —auditoría operativa/de actor—, independiente del bus de dominio. La
superficie pública es **un único seam**, el puerto `AuditLogger->log(action, level, resource?,
metadata?)`; los módulos no conocen nada más. Tres ejes, sin solape:

| Pregunta                           | Mecanismo                    | Tabla                                |
|------------------------------------|------------------------------|--------------------------------------|
| ¿Qué le pasó al agregado? (estado) | `DomainEvent` → `EventBus`          | `domain_event` (`StoredDomainEvent`) |
| ¿Qué hizo el actor? (operación)    | `AuditLogger->log(...)`      | `audit_log` (`AuditLogEntry`)        |
| ¿Cómo funciona el sistema?         | logs / métricas              | —                                    |

**No existe un tipo público `AuditEvent`.** Detrás del seam viven dos piezas internas de
`Shared/Audit`, nunca expuestas a otros contextos:

- `RecordAuditEntry` — mensaje interno de Messenger (la vía async; ver D3). **No** es `DomainEvent`,
  **no** es evento de integración, **no** se publica fuera de `Shared/Audit`, **no** entra en el
  *event catalog* y **no** lo escucha ningún otro bounded context: existe solo como transporte
  interno hacia el worker de auditoría.
- `AuditLogEntry` — el modelo persistido append-only de la fila de `audit_log` (DBAL crudo, sin
  entidad ORM).

El nombre verbo-imperativo (`RecordAuditEntry`) y la ausencia de un tipo `AuditEvent` hacen las dos
fugas semánticas habituales —"parece evento → al event catalog", "va por un bus → lo escucho en otro
contexto"— estructuralmente imposibles, no solo prohibidas por convención.

`StoredDomainEvent` es la **foto en reposo de un `DomainEvent`**, no un "evento de auditoría
genérico": el nombre es correcto y se mantiene.

Descartado: que el mensaje de auditoría (`RecordAuditEntry`) extienda `DomainEvent` — lo despacharía al `EventBus`
y lo persistiría en `domain_event` vía `PersistDomainEventMiddleware`, contaminando outbox/replay y
lo que consumen otros contextos con telemetría de lectura; además `aggregateId` no aplica a una
vista de lista. Descartado: un tipo público `AuditEvent` o un sufijo `...Command`/`...Event` para el
mensaje interno — arrastra semántica de event bus / CommandBus aunque no se use y reintroduce justo
la fuga que este eje evita. Descartado: renombrar `StoredDomainEvent → AuditLogEntry` — pierde
precisión **y** colisiona con el concepto nuevo.

### D2 — Captura híbrida; la **política decide antes de persistir**, no el hook

Dos puntos de captura, una sola decisión de "qué se guarda":

- **Access-log genérico**: un `EventSubscriber` sobre **`kernel.terminate`** (se ejecuta *tras*
  enviar la respuesta → coste de latencia nulo), acotado a `/api/*`.
- **Acciones de fuerte semántica**: llamadas explícitas `AuditLogger->log(...)` en Application para
  exportaciones, vistas de datos sensibles, denegaciones de permiso, etc. (contexto que el hook no
  conoce).

El kernel **solo captura contexto**; un `AuditPolicy` decide si la interacción es auditable y a qué
nivel **antes** de invocar `AuditLogger->log(...)`. Las denegaciones de permiso se enganchan al pipeline
RFC 9457 existente (un listener sobre `AccessDeniedException`, ver
[`api-error-contract.md`](../api-error-contract.md)), no esparcidas por los handlers.

Descartado: *coarse-grained* (solo endpoints sensibles) → pierde acciones de UI relevantes para la
reconstrucción de jornada. Descartado: *fine-grained* (cada request) → "log explosion" (assets,
proxy al PWA, Mercure, healthchecks). Descartado: capturar todo y **decidir en la persistencia** →
IO desperdiciado y decisión tardía.

### D3 — Persistencia por nivel: `activity` async, `security` write-before-send; transporte `audit` dedicado

> **Enmendada por [D3.1](#d31--enmienda-activity-pasa-a-escritura-síncrona-retira-la-cola-cierra-376).** El mecanismo de
> entrega de `activity` pasó a **escritura síncrona**; el transporte `audit`, `RecordAuditEntry` y su handler dejaron de
> existir. El **contrato de durabilidad por nivel** (`activity` best-effort · `security` propaga el fallo) que fija esta
> decisión **se conserva íntegro** — es ortogonal al mecanismo. El texto siguiente queda como registro histórico.

`AuditLogger->log(...)` **ramifica por `level`**, porque los dos niveles tienen contratos de
durabilidad distintos:

- **`activity`** (alto volumen, observabilidad) — `AuditLogger` despacha un `RecordAuditEntry` al
  transporte Messenger dedicado `audit` y un `RecordAuditEntryHandler` lo inserta en `audit_log`.
  Modelo **best-effort**: el *request path* queda libre de IO de auditoría y **acepta** perder un
  registro si el proceso muere antes de encolar.
- **`security`** (denegaciones, elevaciones, uso de API keys — raro y fuera del path caliente) —
  inserción **síncrona write-before-send** en el mismo ciclo de request: una denegación nunca se
  pierde en silencio si la request llegó a ejecutarse. Reutiliza el escritor DBAL idempotente
  (`INSERT … ON CONFLICT (id) DO NOTHING`), de modo que un eventual reintento async de respaldo es
  un no-op por PK. Si esa inserción síncrona falla, la **excepción se propaga** (la request denegada
  no se completa en silencio): la frontera best-effort (swallow + warning) aplica **solo** a
  `activity`, nunca a `security`.

**Transporte `audit` dedicado** (no comparte el `async` de los `DomainEvent`): son dos modelos de
cola distintos —el de dominio es *consistency-sensitive* (write-before-send/outbox), el de auditoría
es *loss-tolerant* y de mayor volumen—. Aislarlos fija tres invariantes operativas: auditoría no
puede bloquear la propagación de `DomainEvent` (head-of-line coupling), no puede saturar el
transporte `failed` de dominio, y su escalado no afecta a los consumidores de `event_store`. En dev
se pliega en `messenger_worker`; en prod puede tener su propio worker (mismo patrón que
`scheduler_maintenance` / `scheduler_worker`).

**Durabilidad de `security` frente a la transacción del llamador (invariante a cablear en Epic 2).**
El escritor DBAL no abre transacción propia: usa la `Connection` por defecto, así que un `INSERT`
`security` síncrono se enrola en la transacción que el request tenga abierta. En Epic 1 no existe
productor `security` y el único camino vivo (`activity`) escribe en el worker, fuera de toda
transacción de negocio, por lo que la garantía no está en riesgo todavía. Pero cuando Epic 2 capture
`AccessDeniedException`, la denegación puede ocurrir dentro de un request con una transacción de
negocio que luego haga rollback, que se llevaría consigo la fila `security` —rompiendo "una
denegación nunca se pierde"—. Invariante de diseño que el productor `security` de Epic 2 **debe**
satisfacer: o no hay transacción de negocio abierta al registrar la denegación, o la escritura
`security` persiste **fuera** de la transacción ambiente y commitea aparte (p. ej. una segunda
conexión DBAL o semántica `REQUIRES_NEW`). El mecanismo concreto se decide en Epic 2; aquí se fija
que «escribir-antes-de-responder» sólo es durable si la escritura no comparte la transacción de
negocio que puede revertirse.

El sistema es, por diseño, **observabilidad operativa con pérdida parcial tolerada en `activity`**,
**no** logging forense uniforme: `security` es traza *compliance-grade* (durable), `activity` es
telemetría de jornada (best-effort).

Descartado: best-effort **uniforme** para ambos niveles — abre un *gap* silencioso justo en el nivel
`security`, que es el que existe para la investigación forense y el cumplimiento (D4/D6). Descartado:
`INSERT` síncrono **para todo** → latencia p95 y contención de DB en el alto volumen de `activity`.
Descartado: compartir el transporte `async` con los `DomainEvent` → mezcla semánticas de
retry/DLQ/throughput y reintroduce el acoplamiento de fallos que D1 evita en el tipo.

### D3.1 — Enmienda: `activity` pasa a escritura síncrona (retira la cola, cierra #376)

**Enmienda a D3 (issue #376).** `activity` deja de encolarse: se escribe **síncronamente** vía `AuditLogWriter`, igual
que `security` y `change`. Se eliminan `RecordAuditEntry`, `RecordAuditEntryHandler` y el transporte `audit`. **D4 y
D4.1 no cambian.**

**Qué disparó la revisita.** #376: una entrada `activity` **encolada antes** de un borrado GDPR y **consumida después**
re-insertaba el `actor_id`/`ip`/`user_agent` originales con `actor_erased = FALSE` — resucitando la PII que el `UPDATE`
de anonimización (D4) acababa de borrar. `ON CONFLICT (id) DO NOTHING` no protege: la fila tardía tiene id propio. El
trigger de revisita que U-5b dispara (exponer el erase a admins en consola) eleva la frecuencia del disparo.

**Por qué se retira la cola en vez de compensarla.** Se descartó un *tombstone* de `actor_id`s: un mapa
`(actor_id → pseudónimo)` es **exactamente la tabla de mapeo que D4 prohíbe por escrito**, y una huella del `actor_id`
no es de un solo sentido porque el espacio de ids es **enumerable** (con la tabla `users` se recupera el mapa) — sería
un oráculo de re-identificación, estrictamente más débil que el HMAC→UUID que D4 ya descartó. Drenar antes de borrar es
**incorrecto**: no se puede drenar lo que el transporte `failed` reinyecta semanas después.

**La justificación de D3 no se sostiene.** D3 justificaba la cola con «libera el request path de IO de auditoría» y
«latencia p95». Pero el transporte es `doctrine://default` —la cola `audit` es una tabla Postgres en la **misma base y
conexión** que `audit_log`—, y la captura de `activity` corre en `kernel.terminate`, **después** de enviar la respuesta.
Medido (microbenchmark de una conexión + pgbench bajo carga concurrente, tablas scratch de DDL idéntico): el `INSERT`
directo no añade latencia visible al cliente (post-terminate), y bajo carga los dos caminos son **indistinguibles** en
throughput/latencia (el coste de commit/fsync domina y es idéntico). La vía async hacía **~3,2x el trabajo total de BD**
por entrada. Así que la cola no compraba lo que D3 le atribuía.

**Bonus estructural.** La cola era una **segunda copia durable de PII** (`messenger_messages`, y su sumidero permanente
en el transporte `failed`) que ninguna política de este ADR —retención, borrado, redacción— gobernaba. Retirarla la
elimina.

**Ventana residual (aceptada).** Una petición que **empieza antes** del `UPDATE` de anonimización y cuya escritura en
`terminate` **commitea después** aún registra el `actor_id` original. Es la **misma carrera de duración-de-request** que
`security` (write-before-send) y `change` (`onFlush`) ya tienen, y que este ADR tolera desde el día uno — no una laguna
nueva. La cola convertía esa ventana de milisegundos en una **ilimitada** (reintentos, `failed`, replay diferido); la
enmienda la devuelve a su duración natural.

**Contrato preservado.** El SLA por nivel de D3 es ortogonal al mecanismo y **no cambia**: `activity` sigue siendo
best-effort (fallo tragado + `warning` sin contexto tainted), `security` sigue propagando el fallo. La rama `change`
(síncrona en la transacción de flush) es intacta.

**Riesgo aceptado (best-effort, explícito).** Con la cola, un hipo transitorio de BD dejaba la entrada `activity` en el
transporte `failed` para reintentar; ahora ese mismo hipo **la pierde** (se traga + `warning`). Es la contrapartida
consciente de retirar la cola, y cae dentro del best-effort que D3 ya declara. **No se añade reintento síncrono:** para
este write —`INSERT` autocommit de una fila con `id` v7 acuñado por el cliente, esquema con solo PK (sin FK ni otro
UNIQUE)— las clases reintentables (deadlock/serialization) no contienden, y la que sí ocurre (conexión perdida) no se
recupera sobre la misma `Connection` (DBAL 4 no reconecta) sin arrastrar gestión de ciclo de vida de conexión al camino
best-effort. La pérdida se cubre por **observabilidad** (alarma sobre el pico de ese `warning`), no por durabilidad:
best-effort significa pérdida **visible**, no pérdida evitada. El vector dominante de pérdida sigue siendo que
`kernel.terminate` no dispare (SIGTERM/reciclado del worker), que ningún reintento de BD toca.

**Alcance de esa decisión: el `INSERT` de `activity`, no toda contención de la aplicación.** El argumento mide un
write concreto —una fila, autocommit, esquema con solo PK— y para ése se sostiene. No cubre la transacción del
borrado GDPR, que tiene otra forma: `FulfilIdentityErasure` corre el pase de actor, el pase de recurso y el
anonimizador del `event_store` bajo una sola transacción, y el último es un `payload::text ILIKE` que fuerza scan
secuencial sobre filas que el outbox está insertando en paralelo. Ahí las clases reintentables sí son plausibles.
**Lo que se añade no es reintento** —la decisión de arriba sigue intacta— **sino traducción**:
`DoctrineTransactionManager` convierte cualquier `RetryableException` de DBAL en `TransientTransactionFailure`, o
sea 503 `transient-transaction-failure`, de modo que un `40P01` deja de salir como 500 `unhandled-exception`. El
llamador recibe «reinténtalo» en vez de «el servidor está roto»; reintentar sigue siendo cosa suya.

**Trigger de revisita.** Si `MESSENGER_TRANSPORT_DSN` migra a un **broker real** (AMQP/Redis/SQS), el `dispatch` pasa a
ser más barato que un write a BD y la absorción de picos deja de ser gratis — reconsiderar entonces una cola de
`activity` (y, con ella, la pregunta del tombstone). Hoy, con transporte Doctrine, la cola era coste neto.

Descartado: *tombstone* consultado en la escritura (reintroduce la tabla de mapeo que D4 rompe). Descartado: barrido
periódico que re-anonimiza (convierte un invariante en convergencia eventual gobernada por la frecuencia de un cron).
Descartado: documentar la ventana async como riesgo residual (no sustituye a cerrarla).

### D4 — Niveles, retención diferenciada, append-only y GDPR

**Tres niveles** (`activity`, `security`, `change`). El nivel no es sólo una ventana de
retención: **fija también cómo se escribe la fila** —`activity` en diferido, `security` síncrono antes de la
respuesta, `change` dentro de la transacción que describe—, de modo que una propuesta de cuarto nivel tiene
que aportar un modo de escritura nuevo, no sólo una retención distinta. `audit_log` es **append-only**: la
ruta caliente sólo inserta.

`audit_log` **es PII** (`actor_id`, `ip`, `user_agent`). Las mutaciones no-append **no** son scripts
operativos sueltos: el log admite un **conjunto cerrado de tres políticas de mutación de primera clase**
—la poda, el borrado GDPR del eje **actor** y el del eje **recurso**—, cada una con semántica definida y
disparador propio; cualquier otra escritura es append.

**La poda exime la evidencia del propio borrado, y eso no la convierte en una cuarta política.** Sigue
siendo un solo `DELETE` con un disparador; lo que cambia es su predicado, que ahora excluye
`AuditErasureEvidence::ACTIONS`. El principio es **la evidencia no puede caducar antes que aquello que
atestigua**: la lápida de `dek_keystore` que responde por un `GDPR_SUBJECT_ERASED` se conserva para siempre
a propósito y el reconciliador las anti-junta sin cota temporal, así que dejar caducar la evidencia vuelve
ese par **insatisfacible** — cada sujeto crypto-shredded pasa a reportarse como divergencia, a diario y para
siempre. La exención llavea por `action` y **nunca** por `level`, porque `action` es lo que lee el control
detectivo: discriminar por columnas distintas es exactamente cómo los dos controles derivarían en silencio.
Va como dato en `AuditRetentionThreshold` —no como literal dentro del SQL del adaptador— y viaja en **todas**
las líneas del plan, no sólo en la de `security`, para que mover la evidencia de nivel no reabra el agujero.

**Qué se vuelve inmortal, dicho entero porque es la exención lo que lo vuelve inmortal.** Estas filas se
acuñan por `SealedAuditEntryFactory`, que sella metadata de petición en **toda** entrada; un borrado
ejecutado por HTTP escribe por tanto el `ip` y el `user_agent` del administrador actuante junto a su
`actor_id`, y el anonimizador del eje de recurso deja esas dos columnas intactas allí donde el actor fue
identificado —precisamente porque son suyas y no del sujeto—. La exención retiene **las tres**, que son el
mismo trío que `docs/rules/database.md` nombra como el PII cuya ventana acotada *es* la minimización. Hay
vía de eliminación y es **discrecional**: el pase de actor casa por `actor_id`, así que esas columnas se
limpian sólo si ese administrador es borrado a su vez. Los caminos por CLI no están expuestos: corren fuera
de petición como `system`, con ambas columnas nulas.

**Lo que esto no cierra, dicho para que no se lea como cerrado.** Las dos acciones de evidencia no tienen la
misma retención correcta, sólo el mismo suelo de «más que el techo de `security`». `SUBJECT_ERASED` necesita
*nunca*, porque su falsador es eterno. `ACTOR_TRAIL_ERASED` responde por marcas `actor_erased` en filas
`change`, que se podan a los cinco años: retenerla para siempre **sobre-retiene al administrador actuante**
más allá del punto en que algo pueda contrastarlo, que es justo la minimización que la poda ejecuta. Ambas
quedan exentas porque ambas deben sobrevivir al techo; acotar la segunda a un suelo, o no sellar metadata de
petición en estas filas al escribirlas, son decisiones de privacidad con su propio coste y se registran sin
tomarlas.

**«Cerrado» significa cerrado por revisión, no por gate.** Nada automatizado impide una cuarta política:
`git grep` sobre la tabla es el único control y no está cableado a ninguna puerta, de modo que una mutación
nueva entra si el diff pasa desapercibido. Se declara porque la frase se lee como una garantía mecánica y no
lo es. El hueco es **idéntico y simétrico** al de D12 en
[`event-store-and-projections.md`](./event-store-and-projections.md), con el mismo trigger: la primera
propuesta de una mutación adicional sobre cualquiera de las dos tablas es cuando el gate compra algo.

- **Política de retención (la poda) — la *única* `DELETE`.** Retención **por nivel** (`security` >
  `activity`), expresada como dato por una `AuditRetentionPolicy` de dominio (`thresholdsAt(now)` → un
  plan de cutoffs por nivel) y ejecutada por un pruner idempotente que **borra en lotes** (acota duración
  de lock y presión de vacuum; la exposición real es el barrido inicial/backfill, no el ~día de
  steady-state) bajo un **advisory lock** de Postgres reutilizable (un único barrido a la vez —
  defense-in-depth ante un scheduler escalado por error, no requisito de corrección: el `DELETE` por
  condición temporal es idempotente y prod corre un `scheduler_worker` de réplica única). Reutiliza el
  patrón `HandledDomainEventPruner` (ver [`domain-event-handler-idempotency.md`](./domain-event-handler-idempotency.md))
  y es el **primer caso conforme** al [`maintenance-job-execution-contract.md`](./maintenance-job-execution-contract.md).
- **Política de cumplimiento GDPR (el erasure) — `UPDATE`, nunca `DELETE`.** El "olvídame"
  **anonimiza de forma irreversible la identidad del actor conservando la traza de seguridad**. Un único
  `UPDATE` reescribe `actor_id` en todas las filas del sujeto con **un UUID aleatorio nuevo acuñado en el
  borrado** —sin valor original, sin tabla de mapeo, sin derivación determinista— y redige
  `ip`/`user_agent` al centinela `[REDACTED]`. No borra filas; la traza (`action`, `level`, `occurred_on`,
  recurso, correlación) sobrevive y queda **correlacionada intra-sujeto** porque las N filas comparten el
  nuevo id. Al no quedar nada que invierta el reemplazo, el vínculo con la persona se rompe de verdad
  (anonimización efectiva, Recital 26), no una pseudonimización con clave reversible (Art. 4(5)).
  `ip`/`user_agent` se **almacenan completos** y solo se redactan en la supresión (minimización en el
  **disparador GDPR**, no en origen), preservando el valor forense hasta entonces. Lo dispara un comando
  de consola (`audit:gdpr:erase <actor-id>`; operador-driven mientras no haya auth que proteja un
  endpoint) y se **auto-audita** como entrada `security` `GDPR_ERASURE_EXECUTED` con el pseudónimo
  resultante —nunca el id original—, de modo que el cumplimiento es demostrable sin re-identificar.
  Coherente con "hard delete por defecto, salvo que el borrado rompa un requisito" de
  [`rules/database.md`](../rules/database.md); se registra en [`rules/security.md`](../rules/security.md)
  y `PRODUCTION_SECURITY_CHECKLIST.md`.
  **Atomicidad borrado ↔ auto-auditoría (limitación conocida).** El `UPDATE` de anonimización commitea y
  *luego* se emite la entrada `security` `GDPR_ERASURE_EXECUTED`; no comparten transacción. Una caída del
  proceso entre ambos pasos deja el borrado hecho sin su evidencia de auditoría — no corrompe datos ni
  incumple el "olvídame", pero erosiona la trazabilidad que este eje preserva. Aceptable mientras el único
  disparador sea un comando de consola síncrono y operador-driven. **Trigger de revisita:** el día que
  aparezca un segundo disparador (endpoint HTTP, Scheduler, API) o se exija garantía dura, envolver
  `anonymise` + self-audit en un único caso de uso transaccional (`EraseActorAuditTrailUseCase`) con el CLI
  como adaptador fino — el mismo tipo de invariante de transacción que D3 fija para la escritura `security`.
  **Un segundo *statement* lo dispara igual que un segundo disparador:** si la decisión de #555 hace que
  `anonymise` ejecute dos `UPDATE`, la ventana deja de ser «borrado sin evidencia» y pasa a ser un borrado
  *a medias* — actor anonimizado y recurso no — sobre un camino hoy correcto. Enrutar entonces por
  `TransactionManager`, nunca una transacción DBAL cruda anidada bajo `wrapInTransaction`. *Nota sobre la
  razón, corregida al medir contra `doctrine/dbal` 4.4.4:* la justificación era «no hay
  `nest_transactions_with_savepoints` configurado, así que anidar degradaría a rollback-only», y esa premisa
  ya no describe esta versión — DBAL usa savepoints en toda transacción anidada y
  `setNestTransactionsWithSavepoints(false)` lanza. La regla se mantiene por su otra razón, que sigue en
  pie: el borrado a medias es un estado observable, y una sola frontera de transacción es lo que lo impide.
- **`resource_id` no es identidad del sujeto borrado, y su borrado es del contexto dueño.** Esta política
  de auditoría anonimiza el eje de *actor* y aporta el `UPDATE` del eje de recurso, pero no decide qué
  tipos denotan personas: si un recurso representa **directamente** a una persona física, su borrado GDPR
  es responsabilidad del bounded context dueño del recurso, que es quien lo encadena.
  **Consecuencia, de obligada lectura antes de auditar un recurso-persona:** una fila cuyo actor pueda ser
  *la misma persona* que su recurso queda, tras el borrado, con el seudónimo fresco en `actor_id` y el id
  real en `resource_id` — un **crosswalk reversible** que re-atribuye todas las demás filas anonimizadas de
  esa persona, porque `resourceId` es filtro indexado del API de lectura y `ADMIN` tiene `auditTrail.read`.
  `GDPR_SUBJECT_ERASED` se libraba **solo** porque el auto-borrado está prohibido, así que su actor nunca es
  el sujeto. Ya no depende solo de eso: esa fila **nombra** al sujeto como su recurso, y el anonimizador de
  recurso reescribe su `resource_id` en la misma transacción, así que el crosswalk queda cerrado por los dos
  lados. Se descubrió al intentar auditar `ChangeUserRoles`, donde el auto-cambio de roles hace
  `actor_id == resource_id`.
  **Mecanismo (la asignación se mantiene, no se revierte).** El eje de recurso lo borra el contexto dueño
  a través de `AuditResourceAnonymiser::anonymise(AuditResource, $pseudonym)`: este módulo aporta el
  `UPDATE` y **nunca** aprende qué tipos denotan personas — el tipo lo pasa el llamador. `Iam` lo encadena
  dentro de la transacción que ya posee (`FulfilIdentityErasure`), con **el mismo seudónimo** que el eje de
  actor, de modo que una persona no se parte en dos identidades anónimas. Puerto separado del de actor a
  propósito: D15 mantiene ambos borrados como operaciones distintas, así que la interfaz de actor no crece
  un verbo de recurso y su CLI sigue siendo de un solo statement.
  **Asimetría de columnas, load-bearing.** El paso de recurso escribe `resource_id` y `resource_erased`, y
  **no toca** `actor_id` ni `actor_erased`; tampoco `ip`/`user_agent`, **salvo cuando
  `actor_type = anonymous`**. Es una sola regla leída con precisión, no una regla con un parche: una fila que
  nombra al sujeto *suele* haberla escrito otro (un admin actuando sobre él), y redactar eso destruiría la
  evidencia de un tercero y lo marcaría falsamente como actor borrado. La regla se apoya en **saber de quién
  es** el dato: es del actor identificado, y no del sujeto. Con un actor jamás identificado no se sabe, y esa
  es toda la diferencia: la dirección es la del solicitante, que en estos dos escritores puede ser el propio
  sujeto provocando su bloqueo o su recuperación, o un extraño haciéndoselo. La fila no guarda discriminante
  y el statement no puede inventarlo. **Hoy tampoco se sella ninguno en escritura**, y acuñarlo sería su
  propio cambio con su propio coste: el solicitante está sin autenticar y solo aporta una identidad
  *reclamada*, así que el único candidato es una heurística —¿coincide la dirección con alguna que el sujeto
  haya tenido en `iam_session`?— pagada haciendo que el escritor de auditoría lea PII de otro contexto en el
  momento de la captura. Sopesado y no tomado; no imposible. Se **yerra hacia el borrado**.
  **El coste, dicho y no escondido:** cuando el solicitante era un extraño —un atacante bloqueando a una
  víctima— su dirección se destruye también. Lo que sobrevive es el **hecho y no el valor**, aunque no por la
  razón que parece: `ip` **sí** la influye el cliente —`getClientIp()` honra `X-Forwarded-For` desde
  cualquier salto dentro de `SYMFONY_TRUSTED_PROXIES`—, pero Symfony descarta todo valor reenviado que
  falle `FILTER_VALIDATE_IP`, así que el centinela en concreto no es forjable en esa columna. Un centinela
  ahí solo puede venir, por tanto, de uno de los dos pases, y `actor_erased` los distingue (`true` el de
  actor; `false` junto a `resource_erased = true`, éste). `user_agent` no tiene ese filtro y sí es forjable
  como literal: la inferencia se apoya solo en `ip`. Un investigador ve que allí
  había algo y que una erasure lo quitó. Errar al revés no es la opción segura que parece: el reconciliador
  solo lee `resource_erased = FALSE`, así que una dirección que se deje aquí es una que ninguna
  reconciliación podrá sacar nunca. **Es una capacidad nueva del insider, no una ampliada:** el pase de
  actor casa `actor_id = <sujeto>`, así que cada columna que reescribe era de la persona borrada; éste casa
  por recurso y destruye metadata de quien *actuó*, de modo que un admin borrando una cuenta atacada
  destruye la dirección del atacante — algo que ninguna acción de admin podía hacer antes.
  **Cuestión abierta, de DPO y no de código:** si esa IP es legalmente dato del sujeto cuando la
  fila no puede decir de quién es. El `CASE` vive en el `SET` y no en el `WHERE`,
  porque como filtro dejaría de seudonimizar `resource_id` en las filas escritas por un admin. `actor_erased`
  sigue intacto: D4.1 lo define como «identificado y luego borrado», y subirlo en una fila nunca identificada
  corrompe la única columna que responde si allí hubo una persona.
  **El predicado es `actor_type`, nunca `actor_id IS NULL`**, que también casaría las filas `system`. Que
  éstas no lleven metadata de petición es cierto pero *emergente*: lo sostienen dos lecturas independientes
  del mismo `RequestStack` en dos clases, sin guarda ni test que las ate. El token del enum no puede
  ensancharse en silencio como sí puede ese acoplamiento. Cada columna se guarda además contra la no-nulidad,
  para no reescribir un valor jamás capturado como evidencia de una redacción que no ocurrió. Y **no-nulo no
  basta**: `SealedAuditEntryFactory` solo comprueba `null`, así que una cabecera `User-Agent:` vacía sella
  `''`; la guarda es por tanto no-nulo **y** no-vacío.
  **Lo que este paso no alcanza, dicho para que no se lea como cobertura total:** el throttle de recuperación
  también emite filas anónimas **sin recurso** cuando la dirección no resuelve a nadie, igual que toda fila
  anónima del log de acceso. Ningún statement del eje de recurso puede casarlas —no hay sujeto al que
  encadenarlas—, y su minimización es la **retención por nivel**, no el borrado. Decirlo importa porque una
  IP es dato personal aunque nuestro esquema no la vincule a nadie: lo que las cubre es que caducan, no que
  no importen.
  **Contar las filas redactadas en `GDPR_ERASURE_EXECUTED`: pesado y declinado por el fondo, no por alcance.**
  Se evaluó publicar ese conteo junto a `anonymized_resource_rows`. Cabe en la misma ida y vuelta (CTE
  modificadora con `RETURNING` y `COUNT(*) FILTER`, presupuesto de consultas intacto), así que lo que decide
  no es el precio, y son dos razones independientes. **La primera: el número no responde a la pregunta que lo
  pide.** La fila no registra de quién es la dirección —esa es la premisa de todo este apartado—, luego el
  conteo suma las del propio sujeto y las de extraños sin poder separarlas; y como `ip` se captura en toda
  petición en vuelo, aproxima «cuántas veces este sujeto se bloqueó o pidió recuperación», no «cuánta
  evidencia ajena murió». Un dato con forma de evidencia que no lo es, en el único sitio cuyo propósito
  declarado es ser evidencia forense independiente, es peor que su ausencia. **La segunda: ya es derivable,
  y almacenarlo sería una segunda fuente de verdad.** El mismo `UPDATE` escribe el pseudónimo y la
  redacción, y `GDPR_ERASURE_EXECUTED` sella ese pseudónimo, así que las filas se recuperan por
  `resource_id = <pseudónimo> AND resource_erased AND actor_type = 'anonymous' AND NOT actor_erased AND
  (ip = '[REDACTED]' OR user_agent = '[REDACTED]')` —indexado, retroactivo a toda erasure aún en retención,
  cobertura que un campo nuevo no tendría—. Un entero almacenado, en cambio, sobrevive a las filas que
  cuenta: son más antiguas que la entrada de cumplimiento, luego la poda las retira primero y deja un número
  infalsificable. **Lo que esto no es:** la derivación es una consulta que nadie ha escrito todavía, no un
  invariante comprobado — el mismo estado en que sigue el *cross-check* de D4.1. Se revisita cuando exista un
  consumidor que la haga falsificable, o si la fila llega a sellar un discriminante del solicitante; y no
  antes, porque una novena clave libre en `metadata` gastaría el YAGNI con que este ADR aplazó pasar esa
  estructura a tipos.
  **Que sea del contexto dueño lo vuelve una obligación distribuida**, así que lleva su control detectivo,
  igual que el crypto-shredding lleva el suyo: `api/.audit-resource-types` clasifica cada `resource_type`
  como persona o no, y una línea `person` declara **dos** rutas —quién lo borra y qué lo atestigua—. El gate
  `make php.lint.audit-resource` rompe la build ante un tipo sin clasificar, ante un tipo-persona cuyo caso
  de uso declarado no cablea el anonimizador, y ante un testigo que no sea un escenario de aceptación con un
  solo escenario que siembre una fila del tipo y afirme que ninguna sobrevive. El testigo existe porque el
  literal del tipo-persona vive en la constante del propio fichero que la línea declara como borrador: sin él
  la vigencia de la línea se satisface con la declaración que verifica.
  `identity:gdpr:reconcile-subject-references` reporta identidades ya borradas que el rastro siga nombrando
  por su id real.
- **Sin payload sensible** en `metadata` (IDs y discriminantes, no cuerpos de entidad), invariante en la
  que se apoya el erasure: por eso **no** redige `metadata`. **Y ningún id de persona** — que es el caso
  que el trigger de revisita anticipaba. Se disparó: el borrado de identidad escribía ahí el id real del
  sujeto. Se resolvió **sin** redactor y **sin** cuarta política, moviendo ese id al eje `resource_id`, que
  ya tiene anonimizador. La asimetría es la razón: ninguna mutación de este conjunto entra en el JSON, así
  que un id de persona en `metadata` sobrevive al borrado que lo escribió, mientras que en el eje de recurso
  lo reescribe el anonimizador que ese mismo borrado ya ejecuta, en la misma transacción.
  **Lo que sostiene la invariante no es un gate**, y la elección está argumentada: un pseudónimo es un UUID
  indistinguible por forma de un id real, luego prohibir «UUIDs en `metadata`» rompería el contrato de
  `anonymized_actor_id` (D4.1), y prohibir **por nombre de clave** sería una declaración que se comprueba a
  sí misma. Lo sostiene un testigo de aceptación falsable en `api/features/backoffice/users/erase.feature`.
  **Trigger de revisita vigente:** **cualquier** camino de escritura que vuelva a meter un id de persona en
  `metadata`. Decía «un segundo», y estaba descontado en uno: tras el cambio no queda ninguno, así que «el
  segundo» se habría negado a dispararse ante la primera regresión — que es exactamente recrear la fuga que
  esta decisión cerró. Lo que la Regla de Tres protege es el **registro declarativo de claves**, y para eso
  la cuenta que importa es cuántos caminos existen a la vez, no cuántos han existido.

**Origen de `ip` (trust boundary).** El valor de `ip` se toma de la entrada *rightmost* de
`X-Forwarded-For` —la que añade Caddy, no falsificable—, con trusted proxies configurados, heredando
exactamente la decisión que ya usa el rate-limiter del PWA (ver `PRODUCTION_SECURITY_CHECKLIST.md`).
**Nunca** el `X-Forwarded-For` crudo del cliente (spoofeable). La captura se ejecuta en Epic 2; aquí
se fija el contrato del dato.

**Input no confiable (*tainted*).** `ip`, `user_agent` y `metadata` son datos controlados por el
cliente / no confiables: deben escaparse al renderizarse en la UI admin (Epic 4) —nunca
`dangerouslySetInnerHTML` ni HTML sin escape, también en los exports—.

Descartado: misma retención para todo (incumple separación legal de seguridad vs actividad).
Descartado: borrado físico en *erasure* (destruye la auditoría de seguridad, que es justo lo que se
quiere preservar). Descartado: minimización en origen (truncar `ip` a /24·/48 y normalizar `user_agent`
al insertar) — degrada permanentemente la fidelidad forense del nivel `security`, que existe justo para
investigar; la minimización se aplica en la supresión, no en la inserción. Descartado: pseudonimizar
`actor_id` con un hash con clave (HMAC→UUID) — es reversible con la clave, así que la fila **sigue siendo
dato personal** (Art. 4(5)), y añade gestión/rotación de un secreto cuya fuga re-vincularía todos los
borrados; el "olvídame" pide romper el vínculo, no custodiarlo. Descartado: un centinela único global para
todos los borrados — destruye la correlación intra-sujeto que la traza de seguridad necesita.

### D4.1 — `actor_erased`: estado de anonimización GDPR materializado y consultable (extiende D4)

D4 fija que el *erasure* anonimiza al actor (un `UPDATE` que reescribe `actor_id` con un UUID nuevo
aleatorio y redacta `ip`/`user_agent` a `[REDACTED]`), pero **no deja una marca consultable** de que
la fila quedó anonimizada: tras el borrado `actor_type` sigue siendo `user` (D7) y `actor_id` es un
UUID válido cualquiera — indistinguible de un actor normal. El *tell* que queda (`ip`/`user_agent =
[REDACTED]`) vive en campos que el read model de investigación (D5) expone **solo en el detalle**, no
en la fila del timeline. Y la implicación va **en un solo sentido**: el borrado de actor escribe siempre
ese centinela, pero verlo no prueba que haya habido un borrado de actor. El paso de recurso lo escribe
también sobre filas de actor anónimo (D4), donde significa
`resource_erased = TRUE ∧ actor_type = anonymous ∧ actor_erased = FALSE`. Son dos rutas de mutación que
comparten un centinela normativo, no una cuarta política; el conjunto de D4 sigue cerrado en tres. La UI
de investigación debe mostrar al actor anonimizado **como tal también
en la fila**, **sin** subir `ip`/`user_agent` (PII de mayor sensibilidad) a la fila esbelta.

**Decisión.** Se materializa un booleano **`actor_erased`** (`NOT NULL`) en `audit_log`, **fijado en
el mismo `UPDATE`** del erasure (junto al remint de `actor_id` y la redacción de `ip`/`user_agent`), y
expuesto por el read model de `Backoffice/Audit` (D5) tanto en la fila del timeline como en el
detalle. Es un **tercer eje** del registro —la *disposición / ciclo de vida* del actor— **ortogonal**
a `actor_type` (D7, *quién*) y a `level` (D4, *qué clase de auditoría*). Como el resto de columnas de
`audit_log`, **no lleva default de columna** (la abstracción de esquema no lo expresa y un default
vivo provocaría drift en `make db.diff`): el writer la inserta en `false` y el erasure la fija en
`true` — el mismo patrón "el writer aporta todo valor" que ya gobierna `level`/`actor_type`.

**Por qué materializar un hecho derivable.** `actor_erased` es derivable en principio, pero se
**materializa** (no se deriva en lectura) porque: (a) se conoce y se fija dentro de una mutación que el
erasure **ya ejecuta** (coste de escritura ~0); (b) la lectura —el timeline— es la **consulta más
caliente** del subsistema y debe tener **cero derivación**; (c) es un hecho *compliance-critical* con
**asimetría de falso negativo** (mostrar como identificable a un sujeto borrado es el peor resultado),
así que conviene un **único punto de escritura testeable**. Es una decisión de *materialización*
(snapshot de un hecho barato de derivar, por rendimiento de lectura + autoridad en escritura), no de
normalización.

**Atomicidad.** El flag vive en el **mismo `UPDATE`** que el remint de `actor_id` y la redacción de
`ip`/`user_agent`, **no** en el paso de auto-auditoría. D4 ya reconoce que ese `UPDATE` y la entrada
`GDPR_ERASURE_EXECUTED` **no comparten transacción**; poniendo el flag en el `UPDATE` de redacción,
hereda exactamente su atomicidad (una caída antes del self-audit deja las filas **correctamente
marcadas**). Derivar el estado (alternativa descartada abajo) convertiría esa misma ventana de caída
en **falsos negativos**.

**Evidencia independiente + comprobación de integridad.** `GDPR_ERASURE_EXECUTED` se conserva como
**evidencia forense independiente** (no como fuente de verdad de la UI): registra el pseudónimo
resultante en `metadata.anonymized_actor_id` —**parte del contrato de ese evento**, no una clave
incidental—, con `actor_type = system` (el actor del borrado es el proceso operador, **no** el sujeto;
nunca el id original). Esto habilita un *cross-check* reconciliable: todo `actor_erased = true` ⟺
existe un `GDPR_ERASURE_EXECUTED` con ese pseudónimo; una divergencia es una violación de integridad
**detectable** (p. ej. desde un job de mantenimiento), no un acoplamiento (la UI no deriva del evento).

**La clave la escriben las DOS rutas**, y decirlo aquí es la mitad que faltaba: el CLI operador
(`EraseActorAuditTrailCommand`) y la supresión por HTTP (`FulfilIdentityErasure`). Mientras la segunda
escribía solo conteos, el ⟺ de arriba era insatisfacible para toda identidad borrada por la ruta
principal, y nada lo señalaba: el contrato vivía en este documento y en un solo llamador. El *cross-check*
sigue sin implementarse como job — lo que existe es la clave sobre la que construirlo.

**Invariante (protegido por test).** Tras el erasure debe cumplirse **siempre**:
`actor_erased = true ∧ actor_id ≠ original ∧ ip = '[REDACTED]' ∧ user_agent = '[REDACTED]'`.

**Naming.** El campo se llama `actor_erased` (no `anonymized`) para no colisionar con
`actor_type = anonymous` (D7) — un actor **nunca identificado** (ruta pública), categóricamente
distinto de uno **identificado y luego borrado**. La columna nombra la *causa/ciclo de vida*; la UI
rotula el *estado legal resultante* («anonimizado (GDPR) · no identificable») y **nunca** muestra el
UUID nuevo como un id.

**No es PII.** El flag es un booleano: por eso viaja en la fila esbelta del timeline sin necesidad de
subir `ip`/`user_agent`. Filtrar por `actor_erased = true` **no** reidentifica al interesado (útil para
auditorías de cumplimiento).

Descartado: **derivar de `ip = '[REDACTED]'`** — invierte la dirección de dependencias (el read model
pasaría a depender de un centinela interno del erasure) y exigiría subir `ip` a la fila o derivar el
bool en la consulta. Descartado: **derivar de la existencia de `GDPR_ERASURE_EXECUTED`** — acopla la
lectura al nombre de la acción y a cómo quedó registrada, introduce **múltiples** falsos negativos
(rename de acción, migración histórica olvidada, fallo del self-audit) y mete un `JOIN`/`EXISTS` en la
consulta más caliente. Descartado: **no exponerlo y resolver solo en el detalle** — induce al
investigador a leer un sujeto borrado como identificable (peor que una columna de más). Descartado:
**codificar la anonimización en `actor_type`** (un valor `erased`) — destruye la información forense de
*qué tipo* de actor era (`user` vs `api_key`) y conflagra el eje «quién» (D7) con el eje «ciclo de
vida», justo la mezcla de ejes que D7 evita.

**Trigger de revisita.** Cuando aparezca un **segundo** evento de cumplimiento
(`GDPR_EXPORT_COMPLETED`, `GDPR_ERASURE_FAILED`, …), introducir una **estructura tipada** para esos
eventos en vez de confiar en claves libres de `metadata` (hoy YAGNI: un solo evento de cumplimiento).

### D5 — Ubicación: backbone en `Shared/`, consulta en `Backoffice/Audit/`

- **`Shared/`** (transversal a todos los contextos): puerto `AuditLogger` (seam), mensaje interno
  `RecordAuditEntry`/modelo `AuditLogEntry`, `ActorContext`, `AuditPolicy` genérica, el subscriber de
  captura, el adaptador de storage y el transporte `audit`.
- **`Backoffice/Audit/`**: read model de investigación — timeline, filtros (actor / fecha /
  recurso), proyecciones, UI admin. **Backoffice no escribe auditoría, solo la consume.**

Respeta el gate de aislamiento de contextos (`make php.lint.bounded-context`): `Erpify\Shared\…` es
siempre importable; la captura no entra en el `Domain/` de ningún contexto.

Descartado: todo en `Backoffice` (la captura es transversal, la disparan todos los contextos).
Descartado: todo en `Shared` (la vista/consulta admin es una feature de negocio, no kernel).

### D6 — `ActorContext` + correlación obligatoria desde el día 1; `audit_session` diferido

Toda entrada de auditoría (`AuditLogEntry`) lleva, sin excepción, `correlation_id` (id de request estable) y `actor_id`
(*nullable* hoy —rutas públicas / acciones de sistema—, **no-null** en cuanto exista auth real).
Esto es lo que habilita la "reconstrucción de jornada" sin tabla de sesión: `actor_id` +
`correlation_id` + `occurred_on` reconstruyen el timeline.

Descartado **hoy**: tabla `audit_session` con ciclo de vida propio (¿cuándo cierra una sesión?) —
especulativo, sin flujo que lo pida (CLAUDE.md: "nada especulativo"). **Trigger de revisita**:
cuando exista auth con sesiones y un caso de investigación que exija correlación de sesión explícita
más allá de la agregación por `actor_id` + ventana temporal.

### D7 — `ActorContext` tipado: `actor_type` obligatorio, `actor_id` nullable según el tipo

D6 fija `actor_id` *nullable* hasta que exista auth, pero un `actor_id = null` es ambiguo: ¿ruta
anónima, proceso de sistema (cron/scheduler), API key o webhook externo? Esa fuga semántica degrada
justo la consulta forense que el subsistema existe para servir. Se cierra tipando el actor:

```text
ActorType  enum  anonymous | system | api_key | user
```

- `actor_type` es **obligatorio** en toda entrada de auditoría (nunca null).
- `actor_id` es nullable y su presencia depende del tipo.

| `actor_type` | `actor_id`       |
|--------------|------------------|
| `anonymous`  | `null`           |
| `system`     | `null`           |
| `api_key`    | `<api_key_uuid>` |
| `user`       | `<user_uuid>`    |

`ActorContext` (en `Shared/…/Audit/Domain`, value object de dominio, sin dependencias de framework):

```php
final readonly class ActorContext
{
    public function __construct(
        public ActorType $type,
        public ?string $actorId,
    ) {}
}
```

`actor_type` (quién) y `level` de D4 (`activity`/`security`, qué clase de auditoría) son ejes
**ortogonales**; no se colapsan. Esto **completa** D6, no lo revoca: la correlación obligatoria y el
`actor_id` nullable-hasta-auth siguen vigentes; solo se añade el discriminante de tipo.

Descartado: derivar el tipo de `actor_id IS NULL` + heurística de ruta — no es consultable, es
frágil y se rompe en cuanto entra `api_key`. Descartado: enum más rico (`cron`, `webhook`) hoy —
`system` los cubre; se expande cuando un caso real lo exija (YAGNI).

## Esbozo de esquema (`audit_log`)

```text
id              uuid v7      PK (Shared/Domain/Entity/Identifiable, id app-assigned)
level           enum         activity | security
action          string       p.ej. BANK_ACCOUNTS_VIEWED, UNAUTHORIZED_UPDATE_ATTEMPT
actor_type      enum         anonymous|system|api_key|user — obligatorio (D7)
actor_id        uuid         NULL salvo api_key/user (D7)
correlation_id  uuid         obligatorio (request id estable)
resource_type   string       NULL  (p.ej. BankAccount)
resource_id     uuid         NULL
metadata        jsonb        sin payload sensible
ip              varchar(45)  NULL
user_agent      string       NULL
actor_erased    boolean      NOT NULL — false al insertar (writer); true tras erasure GDPR (D4.1), fijado en
                             el mismo UPDATE que el remint de actor_id y la redacción de ip/user_agent
occurred_on     timestamptz
```

`ip` se persiste como `varchar(45)` (cabe una IPv6) y no como `inet`: DBAL no modela el tipo `inet`
y ninguna consulta usa operadores CIDR/subred — solo se almacena como evidencia.

`level` y `actor_type` se persisten como `VARCHAR` guardando el `->value` de enums PHP
string-backed (`EnumType` es una constraint de Symfony Validator, no un Doctrine `Type`; no se usan
enums nativos de Postgres).

Índices previstos: `(actor_id, occurred_on)` (jornada), `(correlation_id)` (request), `(level,
occurred_on)` (retención/prune), `(resource_type, resource_id)` (investigación por recurso).

## Alcance de captura — Fase 1

D2 fija el *mecanismo* (captura híbrida, la política decide); esto fija el *alcance* inicial para
que no derive en criterios por desarrollador. La `AuditPolicy` clasifica por **categoría**; los
`action` concretos aterrizan por módulo (hoy solo existe y está cableado `BANK_ACCOUNTS_VIEWED`; el
resto son ejemplos, algunos de módulos futuros, marcados con \*).

- **`activity`** — navegación a pantallas de backoffice, consultas de listado y de detalle,
  búsquedas, filtros, exportaciones.
  Ej.: `BANK_ACCOUNTS_VIEWED`, `BANK_ACCOUNT_VIEWED`\*, `CUSTOMERS_SEARCHED`\*, `INVOICES_EXPORTED`\*.
- **`security`** — `AccessDeniedException`, accesos a recursos fuera de alcance, login/logout (cuando
  exista auth), uso de API keys, elevaciones de permiso, operaciones admin sensibles.
  Ej.: `ACCESS_DENIED`, `UNAUTHORIZED_UPDATE_ATTEMPT`\*, `ROLE_ELEVATION_ATTEMPT`\*.
- **Nunca** — assets, health checks, Mercure, polling, requests técnicos y cualquier endpoint HTTP
  genérico. En particular **no** existe un `action` `HTTP_REQUEST` (sería el *log explosion* que D2
  rechaza: capturar todo y decidir tarde).

(\* `action` aún no definido; aterriza cuando exista el módulo.)

## Triggers de revisita

(a) Auth real / multirrol → `actor_id` pasa a `NOT NULL` y entra `audit_session` si hay caso. (b)
Volumen de `activity` que exija particionado temporal o un *sink* externo (deja de ser PostgreSQL).
(c) Necesidad de exponer la auditoría a un contexto cliente (hoy solo Backoffice la consume). (d)
**Eje de clasificación estable**: cuando el read model de `Backoffice/Audit` necesite dashboards,
filtros o agregaciones sobre la actividad, introducir una `category`/`activity_type`
(navigation|view|export|search…) desacoplada del texto `ROUTE_*`, en vez de depender de patrones de
cadena sobre `action` (y, de paso, *hedge* del contrato de nombre de ruta estable de arriba). **No se
añade hoy**: sin consumidor de lectura sería una columna sin lector y congelaría un vocabulario antes de
conocer las preguntas de investigación que debe responder; al estar pre-producción, añadirla entonces es
barato (sin *backfill*). La taxonomía debe **emerger de las consultas del read model**, no precederlas.

## Implementación

Subsistema transversal: su construcción es una épica propia y no se mezcla con el código de la
feature que la originó. Secuencia sugerida: (1) `Shared` — `AuditLogger` (seam) + `ActorContext` +
`AuditPolicy` + `RecordAuditEntry`/`RecordAuditEntryHandler` + escritor `AuditLogEntry` (DBAL) +
transporte `audit` + migración `audit_log`; (2) captura — subscriber `kernel.terminate` + listener
de `AccessDeniedException` (vía `security` síncrona) + `ActorContext`; (3) retención —
`AuditLogPruner` (Scheduler) + anonimización GDPR (`audit:gdpr:erase`); (4) `Backoffice/Audit` — read
model + UI de investigación. El estado de implementación vive en el issue/PR correspondiente.

**Estructura del adaptador (sellado ≠ enrutado).** El adaptador del seam separa dos
responsabilidades con motivos de cambio distintos: `SymfonyAuditLogger` (puerto `AuditLogger`)
**enruta por `level`** (activity async / security síncrono) y delega la **construcción sellada** de
la entrada en un puerto aparte, `AuditEntryFactory` (`SealedAuditEntryFactory`), que sella el
contexto de confianza —actor (`ActorContextFactory`), `correlation_id` (RequestStack), instante
(`Clock`) e `id`— dentro del ciclo de request. Sellar fuera del seam de enrutado, y no en un
`buildEntry()` privado del logger, mantiene cada pieza testeable en aislamiento y hace que, cuando
entre User/RBAC, sólo cambie el proveedor de actor, no el enrutado.

**Captura híbrida — decisiones de Epic 2.** El *mecanismo* de D2 aterriza así: una `AuditPolicy` pura
(`Audit/Domain`) clasifica la interacción por nombre de ruta, método y la **declaración de auditoría
canónica de la propia ruta**; los hooks viven en `Audit/Infrastructure/Http`. La vía genérica
(`AccessLogAuditListener` sobre `kernel.terminate`) deriva la `action` del nombre de ruta con prefijo
`ROUTE_` (la identidad la pone cada módulo; nunca un `HTTP_REQUEST` genérico) y se **inhibe** en rutas que
ya tienen representación auditiva canónica explícita (`backoffice_bank_account_search` →
`BANK_ACCOUNTS_VIEWED`), evitando filas duplicadas; sólo audita respuestas exitosas. La deduplicación
canónica está protegida mediante tests de comportamiento (una ruta canónica produce exactamente una fila,
una infra ninguna), no sólo por disciplina humana. **El hecho «esta ruta
tiene auditoría canónica» pertenece al módulo dueño de la ruta, no al subsistema de auditoría:** la ruta lo
declara como un *route default* interno (`_audit_canonical`, prefijo `_` = atributo de framework, nunca
argumento de controlador), el listener lo lee de los atributos de la request y lo pasa a la policy como
`HttpInteraction::hasCanonicalAudit`. Así `AuditPolicy` **no mantiene ninguna lista de nombres de ruta de
módulos concretos** —que con decenas de bounded contexts degeneraría en una *god policy*—; un módulo nuevo
que quiera auditoría canónica cambia su propia ruta, no esta clase compartida. `terminate` corre tras
vaciarse el `RequestStack`, así que el listener **re-establece la request** para que el sellado resuelva
actor `anonymous` + correlación + `ip`/`user_agent` reales, no un acto de `system`. La vía `security`
(`AccessDeniedAuditListener` sobre `kernel.exception`, prioridad > `ExceptionResponder`, puramente aditivo)
registra `ACCESS_DENIED` síncrono sellando la ruta objetivo en `metadata` para el análisis forense por
recurso (la `action` permanece de cardinalidad 1 —indexable y agregable—; la ruta es la dimensión, no el
nombre del evento); satisface el invariante de D3 porque en `kernel.exception` cualquier
transacción de negocio ya hizo rollback en su handler, de modo que la escritura `security` commitea
independiente — una conexión DBAL dedicada queda como *trigger de revisita* si algún flujo registrara una
denegación con una transacción de negocio aún abierta. El contrato de `ip` de D4 se cumple con
`Request::getClientIp()` (misma decisión de *trusted proxies* que el rate-limiter), sellado en
`SealedAuditEntryFactory` junto con `user_agent` (recortado al ancho de columna). La frontera `/api/` que
acota ambos listeners se declara una sola vez (`ApiRequestMatcher`) para que no diverjan; `Shared/ErrorContract`
mantiene su propia copia para el pipeline de errores y unificarlas es un cambio aparte.

**Contratos y heurísticos de la captura (endurecidos en Epic 2).** Tres supuestos que el código ya asume
y que aquí se fijan como contrato, para que una refactorización futura no los rompa en silencio:

- **El nombre de ruta es un contrato de auditoría estable.** La vía genérica promueve el nombre de ruta a
  `action` persistida (`backoffice_bank_search` → `ROUTE_BACKOFFICE_BANK_SEARCH`). Esa `action` es un
  identificador **forense e histórico** en disco: renombrar la ruta (`..._search` → `..._list`) parte la
  serie histórica de esa actividad. Por tanto, una vez que una ruta emite `activity` genérica, su nombre es
  un **contrato estable**; renombrarla es un cambio con impacto en auditoría, no una refactorización
  puramente técnica, y exige una estrategia de continuidad (alias en la consulta del read model o
  *backfill*).
- **El método HTTP es un heurístico temporal, no el modelo definitivo.** `GET ⇒ activity` asume `GET` =
  lectura segura y todo lo demás = no-actividad. Es correcto para el alcance de Epic 2, pero no sobrevive a
  `GET` sensibles (export, descarga, nómina, movimientos bancarios) ni a `POST` que son lecturas (search,
  *report-builder*, *query*). El modelo destino es una clasificación explícita
  (`SAFE_READ`/`SENSITIVE_READ`/`WRITE`), que se introduce **cuando exista el primer caso real**; hasta
  entonces el método es el discriminante consciente.
- **SLA por nivel: `activity` best-effort, `security` durable.** Además del *swallow* en el dispatch (D3),
  la captura genérica de `activity` depende de que `kernel.terminate` llegue a ejecutarse: un `SIGTERM` /
  reciclado de pod tras enviar la respuesta puede perder el registro — es una garantía estrictamente menor
  que la de un encolado dentro del ciclo. `security` es **durable** (write-before-send síncrono enganchado a
  `kernel.exception`, dentro del ciclo, con propagación de fallo): nunca depende de `terminate`. El sistema
  es, por contrato, *`activity` = observabilidad de jornada con pérdida parcial tolerada; `security` = traza
  compliance-grade*. Si en el futuro `activity` necesitara garantía de entrega, el *trigger* es revisar su
  punto de captura (hoy `kernel.terminate`, elegido por **latencia nula**) frente a la durabilidad — son
  objetivos en tensión y la elección de Epic 2 prioriza el path de request.

**Secuencia frente a auth (cerrada):** el backbone de auditoría se implementa **antes** de User/RBAC.
`actor_id` permanece nullable (`actor_type` ∈ {`anonymous`, `system`, `api_key`}) hasta que exista
autenticación; el día que entre User solo cambia el proveedor de `ActorContext` (`ActorContextFactory`)
— schema, bus, storage, retención y read model no se tocan.
