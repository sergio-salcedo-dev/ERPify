---
baseline_commit: 93befb7c
---

# Story 1.6 (G-3b): El control detective del eje de recursos se ejecuta, falla de forma observable y alerta

Status: done

> **LA DECISIÓN ESTÁ TOMADA Y REGISTRADA** (ver *Decisión registrada*): **`Iam/Identity` estrena su propio
> schedule**. La precondición normativa de la épica queda satisfecha por ese bloque. **No la re-abras.**

> **La pregunta de frontera no era la parte difícil, y el corte la sobredimensionó.** Medido: `Iam.Identity` ya
> está registrado en deptrac y su capa `Infrastructure` ya admite lo que hace falta, y los transportes de
> scheduler **no se declaran** en `messenger.yaml` — los crea el compiler pass desde el atributo. **Coste real:
> dos líneas de Compose. Cero de deptrac, cero de allowlist.** El trabajo de verdad de esta historia es **la
> alarma** y **el coste por tick**.

## Story

Como **responsable de cumplimiento**,
quiero que el control que detecta un borrado incompleto se ejecute solo y avise cuando encuentre divergencia,
para que un borrado omitido no espere a que alguien recuerde lanzar un comando.

**Eje que instala:** **agendado + fallo observable + alertado en la misma entrega**, que es lo que SI-21 exige
cuando se admite un control agendado en lugar de un gate de build.
**Invariantes que consume:** SI-21/NFR1, NFR7.
**Dependencias:** ninguna. Independiente de 1.1–1.5 y paralelizable.

## Estado medido (`main` @ `93befb7c`)

> *Procedencia:* pase de medición **read-only** sobre `main @ 93befb7c` (2026-08-03), posterior a G-1c (#634).
> Sustituye a la medición sobre `9310efeb`, que esa entrega dejó rota en dos puntos concretos, ambos anotados
> abajo. Re-verifica las coordenadas antes de citarlas.

**El control, tal como G-1c lo dejó.**
[`ReconcileErasedSubjectReferences`](../../api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php)
ya **no** inyecta `UserRepository`: recibe `PersonResourceReferences`, la colección etiquetada
`iterable<PersonReferenceSource>` y `LiveIdentityDirectory` (`:72-77`). Su entrada es
`unreconciledReferences(): UnreconciledPersonReferences` (`:79`) — un objeto de valor con `total()`,
`axesChecked()`, `axesCheckedKeys()` y `findings()`, **no** la `list<string>` plana que este corte suponía.
Consecuencia para la alarma: hay más que un conteo disponible (total, ejes comprobados, conteo por eje) y
**nada de eso obliga a tocar un solo id**. Tiene CLI y **sigue sin aparecer en ningún schedule**.

**La Tarea 2 está HECHA, y no la hizo esta historia.** El puerto de existencia que el corte pedía existe como
[`LiveIdentityDirectory::existingIdsAmong()`](../../api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php),
resuelto por [`DoctrineLiveIdentityDirectory`](../../api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineLiveIdentityDirectory.php)
en **una** sentencia (`SELECT id FROM identity_user WHERE id IN (:ids)` por `fetchFirstColumn`, sin hidratar
ningún agregado), y el control ya lo usa (`:82`). **AC4 se cumple en `main`**: aquí se verifica, no se
construye. El techo de 65535 parámetros de PostgreSQL queda declarado en el docblock del puerto.

**El coste de frontera es cero, medido.** `Iam.Identity` ya tiene capas en
[`deptrac.yaml`](../../api/tools/deptrac/deptrac.yaml) (`:85-90`) y ruleset (`:336-349`); su capa
`Infrastructure` ya admite `Vendor.Symfony` (`:346`, que cubre `Scheduler` y `Messenger\Attribute`), `Vendor.Psr`
(`:342`, `LoggerInterface`) y `Iam.Identity.Application` (`:338`). Los transportes de scheduler **no se declaran
en [`messenger.yaml`](../../api/config/packages/messenger.yaml)**: `AddScheduleMessengerPass` crea
`messenger.transport.scheduler_<nombre>` desde `#[AsSchedule]`, y el autoregistro de
[`services.yaml`](../../api/config/services.yaml) (`:28-30`) hace el resto. **Un schedule + handler bajo
`src/Iam/Identity/Infrastructure/Messenger/Maintenance/` no toca ni deptrac ni el allowlist ni `messenger.yaml`.**

**El precedente que íbamos a copiar está roto, dos veces.**
`ReconcileSubjectErasuresHandler` loguea a **`warning`** (`:35`), y
[`monolog.yaml`](../../api/config/packages/monolog.yaml) (`:58-67`) usa en prod `fingers_crossed` con
`action_level: error`: **su alarma de divergencia se descarta y nunca ha llegado a producción**. Y en `:37`
loguea **la lista completa de ids de sujeto**. El template correcto es
[`ReportDeadLetterBacklogHandler`](../../api/src/Shared/Event/Infrastructure/Messenger/Maintenance/ReportDeadLetterBacklogHandler.php)
(`:47`, `logger->error(...)`, con el docblock `:14-20` que enuncia el razonamiento que esta historia necesita).

**Sentry no está «sin cablear», y esto cambia el diseño de la alerta.** Está instalado y habilitado
(`config/bundles.php`, `config/packages/sentry.yaml` con `register_error_listener: true` y `messenger` activo
bajo `when@prod`). Lo que **no** está cableado es el handler **Monolog→Sentry**. Consecuencia operativa:
**un `logger->error()` NO llega a Sentry; una excepción lanzada desde un handler de Messenger SÍ.**

**Coste por tick: ya acotado, y por eso deja de ser trabajo de esta historia.** El corte lo presupuestaba
contra `DoctrineUserRepository::findById()` — un `EntityManager::find()` por id, con `email` y `password_hash`
residentes en el worker. G-1c lo sustituyó por la sonda por lotes: **una sentencia por tick, cero
hidrataciones**, sea cual sea el número de sujetos. Lo que queda por presupuestar no es la sonda sino **las
fuentes**: cada `PersonReferenceSource` consulta su propia tabla antes del probe de unión, así que un tick son
`n+1` sentencias con `n` = número de ejes cableados (hoy 5). Todas son `DISTINCT` sobre una columna indexada y
ninguna hidrata.

**Lo que la sonda por lotes NO arregló, y AC3 tiene que cubrir.** Nada envuelve `existingIdsAmong()` en
`ReconcileErasedSubjectReferences:82`: el techo de 65535 parámetros que su propio docblock declara, o un error
transitorio de DBAL, propagan. En el CLI eso sale con **el mismo código que una divergencia real** (bala viva
en `deferred-work.md`); agendado, sería una excepción del handler. Los dos caminos —hallazgo y avería— tienen
que distinguirse en los dos brazos, y esa es la razón por la que el arreglo del exit code entra aquí.

**Hueco de verificación medido:** **nada en el repo comprueba que un `#[AsSchedule]` declarado esté realmente
consumido.** Un schedule puede shippear con su transporte ausente de los dos comandos de Compose y **todos los
gates siguen verdes** — que es exactamente el modo de fallo titular de esta historia, y aplica retroactivamente
a los dos schedules existentes.

## Decisión registrada (precondición normativa de la épica: SATISFECHA)

**Decidido:** 2026-08-01. **Quién:** Sergio, sobre lectura de arquitectura + pase de medición independiente.
**Dónde queda el registro:** este bloque, y el cuerpo del PR debe reproducirlo.

### **`Iam/Identity` estrena su propio schedule** (`identity_maintenance`)

**Alternativas descartadas:**

- **Mudar el control a `Shared/Audit`.** `Shared` aprendería semántica de `Iam`, que es lo que
  `php.lint.bounded-context` y `php.deptrac` existen para rechazar.
- **Invertir la dependencia y dejarlo en `Shared` detrás de un puerto.** Es la que parecía elegante y **muere
  por medición**: el docblock de `PersonResourceReferences` (`:17-20`) ya litiga este caso en el árbol —*«este
  módulo puede listar los ids; no puede saber si un id sigue siendo una persona viva… responder a las dos
  mitades aquí sería la inversión de conocimiento que D4 existe para impedir»*—, y el control necesita además
  saber **qué `resource_type` denota persona**, conocimiento que hoy vive en un **fichero de lint, no en config
  de runtime**. Sería inventar un registro de tipos-persona con resolutores por contexto **para una población de
  uno**. YAGNI, y en la dirección contraria a la que el repo ya decidió.
- **«Todos los schedules viven en `Shared`» como regla.** No lo es: es la forma que tomaron los dos primeros,
  ambos de mantenimiento del propio shared kernel. Nada en el árbol lo declara política. *Si se quisiera que lo
  fuera —un sitio único donde ver todo el cron— sería un argumento legítimo, pero exige ADR; hoy no existe.*

### Lo que la decisión arrastra, y es la mitad que se olvida

1. **La alarma es un `logger->error()` con CONTEO, nunca la lista de ids.** Un control GDPR que escribe ids de
   personas no borradas en stderr/JSON los mete en logs con **su propia retención y sin dueño de borrado
   declarado**: sería **SI-21 violado por el control construido para hacer cumplir SI-21**. Los ids se quedan en
   el CLI, que es operador-driven y efímero.
2. **Un puerto de existencia**, no el repositorio de agregados: `existingIds(list<string>): list<string>` →
   un `SELECT id … WHERE id IN (…)`. Una query, sin hidratación, sin PII en memoria del worker. Precedente en el
   árbol: `BankExistenceChecker`. Vive en `Iam\Identity\Domain\Repository` — **mismo contexto, ningún gate
   implicado**. *Es lo que hace segura la palabra «desatendido», así que entra en esta historia.*
3. **Plegado a propósito, y declarado en el PR:** los dos defectos de `ReconcileSubjectErasuresHandler` —
   `warning` → `error` (`:35`) y lista de ids → conteo (`:37`)— se corrigen aquí, con un test cada uno.
   Están **fuera del alcance nominal**; se pliegan porque shippear un segundo reconciliador que alerta bien al
   lado de uno cuya alarma nunca ha sonado es incoherente, y el arreglo es de una palabra. **Autorizado
   explícitamente (Sergio, 2026-08-01). No se cuela en silencio.**

## Acceptance Criteria

**AC1 — Se ejecuta sin intervención humana (FR8).**
**Given** un borrado que dejó una referencia sin des-identificar,
**When** transcurre el intervalo del control,
**Then** se ejecuta solo y la divergencia queda registrada.

**AC2 — La divergencia es visible en la monitorización, no solo en un stdout (FR8).**
**Given** una divergencia detectada,
**When** se consulta la monitorización,
**Then** hay **una línea estructurada a nivel `error`** con el **conteo** de referencias divergentes —nunca sus
ids—, y el handler es **silencioso** cuando no hay divergencia. **Y** el PR declara que, con el handler
Monolog→Sentry sin cablear, esa línea llega a stderr/JSON **pero no a Sentry**: bajo-declarar es obligatorio.

**AC3 — Camino de hallazgo y camino de avería se distinguen, en los DOS brazos.**
**Given** el control,
**When** falla por avería (la sonda de existencia revienta) en vez de por hallazgo,
**Then** el brazo agendado lo distingue en el mensaje **y** el brazo CLI lo distingue en el **exit code**, con un
tercer código distinto de `FAILURE`. *Medido: una excepción del handler no reintenta, no crea fila en `failed` y
no reinicia el worker — deja una línea `critical` y el bucle sigue. Un reconciliador roto de forma persistente
costaría una `critical` al día, en silencio, para siempre. Y en el CLI, el contrato declarado es el exit code y
nada más, así que hoy «una persona sobrevivió a su borrado» y «la sonda reventó» son indistinguibles para el
único consumidor que el comando declara tener.*

**AC4 — El coste por tick es acotado y sin PII en memoria.**
**Given** el control agendado,
**When** corre,
**Then** resuelve la existencia de sujetos con **una** consulta y **sin hidratar agregados**.

**AC5 — Las tres piezas llegan juntas (SI-21/NFR1).**
**Given** la entrega,
**When** se revisa su alcance,
**Then** agendado, fallo observable y alertado están **los tres**, y consta **por qué esta propiedad no es
estáticamente decidible** — que es lo que autoriza un control agendado en vez de un gate de build.

**AC6 — Fronteras intactas (NFR7).**
**Given** la ubicación elegida,
**When** corren `make php.lint.bounded-context` y `make php.deptrac`,
**Then** siguen verdes **sin haber tocado su configuración ni el allowlist**. Si hiciera falta tocarlos, la
decisión estaría mal implementada.

**AC7 — El transporte está realmente consumido, y un control lo comprueba.**
**Given** un `#[AsSchedule]` cuyo transporte falte en algún comando de consumo,
**When** corre `make php.lint.schedule-consumption`,
**Then** **falla**, nombrando el schedule y el fichero Compose donde falta — y el gate está en `php.quality`
**y** en `php.quality.dry-run`, porque CI corre el *dry-run* (NFR11).
*Además, comprobación viva una vez: `bin/console debug:scheduler` y `debug:messenger` contra el stack, con su
salida — no razonada desde los Makefiles.*

**AC8 — Los dos arreglos plegados, con test.**
`ReconcileSubjectErasuresHandler` alerta a `error` y **no** loguea ids. Un test por cada cosa.

**AC9 — Sin regresión.**
`make php.quality`, `make php.unit`, `make php.behat`, cada uno desde **ejecución fresca con exit code impreso**.

## Tasks / Subtasks

- [x] **Tarea 1 — Registrar la decisión (PRECONDICIÓN).** Hecha: ver *Decisión registrada*. Reprodúcela en el PR.
- [x] **Tarea 2 — Puerto de existencia** en `Iam\Identity\Domain\Repository` + adaptador Doctrine, y el control
      pasa a usarlo. (AC4) — **la entregó G-1c (#634), no esta historia**: `LiveIdentityDirectory::existingIdsAmong()`
      + `DoctrineLiveIdentityDirectory`, una sentencia sin hidratación, ya consumido en `:82`. Aquí se **verifica**
      con `make php.unit`; no se escribe código. Se declara así en el PR para que el revisor no lo busque en el diff.
- [x] **Tarea 3 — Mensaje de tick + handler** bajo `src/Iam/Identity/Infrastructure/Messenger/Maintenance/`,
      copiando `ReportDeadLetterBacklogHandler` —**no** `ReconcileSubjectErasuresHandler`—. (AC2, AC3)
- [x] **Tarea 4 — `IdentityMaintenanceSchedule`** con `#[AsSchedule('identity_maintenance')]` y su
      `RecurringMessage`. Test de existencia al estilo `AuditLogMaintenanceScheduleTest` (`:21-24`). (AC1)
- [x] **Tarea 5 — Compose**: añadir el transporte a las dos listas de consumo, y actualizar el bloque de
      comentario de `compose.prod.yaml` que hoy nombra solo dos transportes. (AC7)
- [x] **Tarea 6 — Los dos arreglos plegados** en `ReconcileSubjectErasuresHandler`, con test. (AC8)
- [x] **Tarea 6 bis — Gate de consumo de schedules** (AC7): test de arquitectura + target `php.lint.*` + sus tres
      inserciones + el bind mount de solo lectura de los Compose, siguiendo el precedente del gate del contrato
      de errores. Su cabecera declara qué **no** prueba (presencia del nombre ≠ worker vivo). **Verifica que el
      `--filter` selecciona la clase listando los tests que el target elige**, no razonándolo — es el defecto
      que #613 pagó.
- [x] **Tarea 6 ter — Tercer exit code en el CLI** (AC3, autorizado por Sergio 2026-08-03): un fallo de la sonda
      sale hoy con el mismo código que una divergencia real, así que un check de monitorización no puede separar
      «una referencia sobrevivió a su borrado» de «la sonda reventó». Capturar y devolver un código distinto de
      `FAILURE`, con test. Al resolverlo, **borrar su bala** del top de `deferred-work.md` (registro solo-pendientes).
- [x] **Tarea 7 — Docs**: son **tres** ficheros, no dos — `docs/deployment-guide.md` (`:32`),
      `docs/architecture-api.md` (`:266`) y **`docs-info/production-deployment.md`** (`:17`, git-trackeado y que el
      corte no nombraba) citan hoy solo `scheduler_maintenance`. **D3 de
      [`dead-letter-observability.md`](../../docs/adr/dead-letter-observability.md) NO se toca: ya está corregido
      en `main`** (`417b14ab`, #614) — su bloque «What throwing would and would not do» ya dice que no hay
      recursión porque los transportes de scheduler no están entre los de fallo.
- [x] **Tarea 8 — Gates y pase adversarial (AC9 + definición de hecho de la épica).** Hecha (2026-08-04):
      gates re-ejecutados frescos tras aplicar la review, con exit code impreso, y **pase adversarial por
      contexto fresco distinto del autor, registrado en la sección *Pase adversarial* + *Review Findings* de
      este artefacto y en el cuerpo del PR #635.**

## Dev Notes

**Operativa que hay que escribir en el PR, o un revisor sacará la conclusión equivocada:**

- **El primer tick no ocurre en el despliegue.** Con periodicidad diaria, la primera ejecución llega ~24 h
  después del primer arranque. Un despliegue verde y sin líneas de log **no prueba que no funcione**. Si la
  demo de aceptación tiene que verse el mismo día, es por CLI o con un intervalo temporal — no ablandando el AC.
- **El checkpoint sobrevive al reinicio**: `--time-limit` hace salir al worker cada hora y el contenedor
  rearranca conservando el volumen, así que los ticks perdidos se recuperan al volver. El schedule nuevo tiene
  **checkpoint propio** y no puede ser inanido por los otros dos.
- **Inanición**: en dev los tres transportes van al mismo worker, así que un reconciliador lento retrasa la
  entrega de eventos **localmente**. En prod el `scheduler_worker` consume solo transportes de scheduler, y su
  `replicas: 1` sigue siendo todo el mecanismo anti-tick-duplicado. Un tercer schedule no añade exposición nueva.

**El gate de consumo ENTRA en la historia (decidido 2026-08-01).** Refleja `#[AsSchedule]` sobre `src/`, deriva
`scheduler_<nombre>` y comprueba su presencia en los comandos de consumo de **los dos** ficheros Compose.

*Por qué no es alcance inflado:* SI-21 exige que **agendado, fallo observable y alertado lleguen juntos**, y
«agendado» **no lo demuestra declarar el atributo** — lo demuestra que alguien consuma el transporte. Sin este
control, la afirmación central de la historia es **infalsable**: el schedule puede shippear muerto y todos los
gates siguen verdes. Es el modo de fallo titular, y además ya está vivo retroactivamente sobre los dos schedules
existentes, que nadie comprueba hoy.

*Coste, con su parte incómoda declarada:* un test (~60 líneas, forma de `tests/Unit/Shared/Architecture/*GateTest.php`),
un target `php.lint.*`, sus tres inserciones (`php.quality`, `php.quality.dry-run`, `.PHONY`) **y un bind mount de
solo lectura** de los Compose de la raíz — dentro del contenedor solo hay `api/`, `public/` y `docs/`. El
precedente exacto es el montaje que ya existe para el gate del contrato de errores, comentario incluido; **eso es
lo que lo hace aceptable: no se inventa un mecanismo, se reusa uno ya juzgado.**

*Y su punto ciego, para la cabecera:* comprueba **presencia del nombre en un comando**, no que el worker esté
vivo, ni que el `--time-limit` sea sano, ni que el servicio esté desplegado. Un verde prueba que el transporte
está cableado, nada más.

## References

- `_bmad-output/planning-artifacts/epics-gdpr-hardening.md` — FR8; NFR1/SI-21, NFR7, NFR10.
- `_bmad-output/planning-artifacts/arch-addendum-gdpr-hardening.md` — SI-21 y la fila **G-3** de localización.
- `docs/adr/dead-letter-observability.md` — D3 (a corregir en su motivo, no en su conclusión).
- `docs/adr/maintenance-job-execution-contract.md` — contrato de ejecución de trabajos de mantenimiento.
- `docs/adr/audit-activity-log.md` — D4 y la obligación distribuida que este control vigila.

## Dev Agent Record

### Gates — ejecuciones frescas, exit code impreso (`93befb7c` + esta rama)

Corridas **posteriores a aplicar la review completa** (2026-08-04), cada una fresca y con su exit code.

| Gate | Exit | Nota |
|---|---|---|
| `make php.stan` | **0** | 1183 ficheros, `level: max` |
| `make php.quality` | **0** | incluye `php.lint.person-reference` (4 filtros), `php.lint.bounded-context`, `php.lint.persistent-transport`, `php.lint.audit-resource`, `php.lint.schedule-consumption`, `php.deptrac`, rector, cs-fixer, phpcs, phpmd |
| `make php.quality.dry-run` | **0** | paridad CI |
| `make php.unit` | **0** | 2212 tests, 9329 aserciones |
| `make php.behat` | **0** | 383 escenarios, 3470 pasos |
| `make php.lint.schedule-consumption` | **0** | 7 + 10 tests; selección de cada `--filter` verificada con `--list-tests` (7 y 10, disjuntos), no razonada |

*Nota de entorno, para que nadie la lea como regresión:* con tres stacks de ERPify y supabase vivos a la
vez, `php.rector` sobre el árbol completo muere con **137 (OOM del kernel)** antes de analizar nada.
Acotado a los ficheros de esta rama sale `0`, y el barrido completo sale `0` con la caché de Rector
caliente. Es presión de memoria de la máquina, no del código.

### Comprobación viva contra el stack (AC7), no razonada desde los Makefiles

`bin/console debug:scheduler` lista `identity_maintenance` con
`ReconcilePersonReferencesMessage` cada 1 día; `debug:messenger` lo resuelve a
`ReconcilePersonReferencesHandler`. Los otros dos schedules siguen intactos.

### Falsificaciones de la pasada de review (2026-08-04)

Cada arreglo de la review se falsificó por separado. Bytes restaurados **por copia**, nunca con
`git checkout --`.

| Probe | Resultado |
|---|---|
| Quitado el `instanceof TransportException` del filtro de Sentry | **1 rojo** — el fallo de sonda vuelve a ser tragado |
| Quitado `->stateful()` de `IdentityMaintenanceSchedule` | **1 rojo** |
| Quitado el `catch (LogicException)` del CLI | **1 rojo + 1 error** — el exit code vuelve a ser `1` == `FAILURE` |
| Iterada la colección etiquetada sin envolver (`foreach` + `axis()` desnudos) | **2 rojos** |
| Quitado `axesCheckedKeys` de la alarma | **1 rojo** |
| Movido `scheduler_identity_maintenance` al `messenger_worker` escalado de prod | **2 rojos**, nombrando servicio y transporte |
| Comentado el comando de consumo entero en `compose.yaml` | **1 rojo**, nombrando los dos transportes que quedan sin consumir |

**Comprobación viva de D1, no razonada:** `bin/console debug:scheduler` ahora emite
`Lock acquired, now computing item "scheduler_checkpoint_identity_maintenance"` para los tres
schedules — el checkpoint se persiste en el pool, que es la diferencia observable entre un periodo
diario que puede vencer y uno que no.

**Comprobación viva de P2:** dentro del contenedor de dev, `ini_get('zend.exception_ignore_args')`
pasó de `''` (capturaba argumentos: los UUID de persona viajaban en los frames del trace que Sentry
serializa) a `'1'` tras el rebuild.

**Gotcha que costó un ciclo y queda anotado en `compose.dev.yaml`:** añadir un argumento al
constructor de un `ScheduleProvider` deja al `messenger_worker` en bucle de arranque (255,
`ArgumentCountError`) porque su `var/cache` es un volumen privado con el factory compilado viejo.
Un `restart` no basta: hay que recrear el volumen.

### Falsificaciones ejecutadas (un gate que no puede ponerse rojo no prueba nada)

- **Gate de consumo:** quitado `scheduler_identity_maintenance` de `compose.yaml` → **1 rojo**,
  nombrando el transporte y el fichero. Bytes restaurados por copia, no por `git checkout`.
- **Gate de consumo sin su mount:** corrido antes de recrear el stack → **4 rojos** con el mensaje
  que nombra el bind mount. Es el guardarraíl anti-inerte: sin fuente, falla en vez de saltarse.
- **Estrechez del `catch` del CLI:** ensanchado a `Throwable` → **1 rojo**. Prueba que el
  `LogicException` de doble eje sigue siendo un bug de cableado ruidoso y no una «avería».
- **Los dos arreglos plegados:** rojo previo de **2 fallos**, uno por arreglo (nivel y conteo).

### Pase adversarial

**EJECUTADO — 2026-08-04**, contexto fresco distinto del autor: cuatro capas paralelas (Blind Hunter
adversarial, Edge Case Hunter, Acceptance Auditor contra este artefacto, y una capa dirigida a las
cuatro hipótesis hostiles que Sergio levantó), todas leyendo el worktree de la rama y no el primario.
**Dónde queda registrado:** esta sección + *Review Findings* abajo, y el cuerpo del PR #635.

Las afirmaciones de mayor impacto se re-verificaron a mano contra `vendor/` y los Compose antes de
aceptarlas: la convergencia entre capas no cuenta como prueba.

### Review Findings

> **Todas las decisiones resueltas y todos los parches aplicados** (2026-08-04). Las cuatro decisiones se
> llevaron a Winston (arquitecto), Amelia (dev) y a una consulta externa independiente; discreparon en dos
> de ellas y la discrepancia se resolvió midiendo, no promediando. Dos conclusiones cambiaron por medición:
> el estrechamiento del filtro de Sentry se sustituyó por una condición de tipo que no deja como
> load-bearing una rama sin tests, y el enmascarado del bind mount **se probó y no funciona** (un mount
> anidado necesita crear su punto de montaje dentro de un padre `read_only`, y los subárboles que importan
> son justo los ausentes del checkout que levanta el stack).

- [x] [Review][Decision] **El schedule diario no puede disparar nunca, ni en dev ni en prod.** El
      `Schedule` no es `->stateful()` ni `->lock()`, así que `MessageGenerator::checkpoint()` construye
      `Checkpoint($name, null, null)` y `from` vive **solo en memoria del proceso**;
      `PeriodicalTrigger::continue($startTime)` lo siembra con el arranque y `getNextRunDate()` devuelve
      `arranque + 1 día`. Los dos consumidores corren `--time-limit=3600` con `restart: unless-stopped`
      (`compose.yaml:129`, `compose.prod.yaml:225`), así que el proceso muere cada hora y `from` se
      resiembra: el próximo tick está siempre ~23 h más allá de un proceso que vive 1 h. Verificado
      leyendo `vendor/symfony/scheduler/{Generator/Checkpoint.php,Generator/MessageGenerator.php,Trigger/PeriodicalTrigger.php}`.
      **Es AC1 incumplido** y es exactamente el modo de fallo titular de la historia —«se agenda y no
      tickea»— un nivel por encima de donde el gate nuevo mira: `php.lint.schedule-consumption` prueba
      que el *nombre* está cableado y declara explícitamente que no prueba entrega. **Preexistente en
      forma** (afecta igual a `scheduler_maintenance` y `scheduler_audit_maintenance`, ambos `1 day`;
      el `1 hour` del dead-letter queda en carrera exacta), pero esta es la entrega que lo convierte en
      una afirmación de cumplimiento. Opciones: `->stateful($cache)` con un pool PSR-6, subir/quitar
      `--time-limit`, o `->lock()`; y decidir si se arregla aquí para los tres schedules o en historia
      propia.
- [x] [Review][Decision] **`SentryEventFilter` descarta el fallo de sonda cuando la causa es un
      `ConnectionException` de DBAL.** `isWorkerConnectionTeardown()` recorre **toda** la cadena
      `getPrevious()` buscando `ConnectionException` y basta con que el tag `console.command` sea
      `messenger:consume` — que es la línea de comando de los dos workers, incluido el
      `scheduler_worker`. `PersonReferenceProbeFailed` encadena la causa DBAL, así que una pérdida de
      conexión a mitad de `existingIdsAmong()` **no llega a Sentry**: solo queda la línea `critical`,
      que es justo la señal que el diseño argumenta insuficiente. La garantía «una excepción del
      handler SÍ llega a Sentry» —enunciada en el docblock del handler, en `.person-reference-policy` y
      en `docs/architecture-api.md`— es falsa para esa clase de avería. Estrechar el filtro toca
      supresión de ruido preexistente y deliberada: decisión tuya.
- [x] [Review][Decision] **El `LogicException` de doble eje sale con `1`, que es `Command::FAILURE`.**
      `Application::run()` coacciona un código ≤ 0 a `1`, así que «una persona sobrevivió a su borrado»
      y «el control está mal cableado y cubre una tabla menos» vuelven a compartir código — el mismo
      argumento con el que se justificó `INVALID`. El test que lo fija usa `CommandTester`, que llama a
      `Command::run()` y **nunca atraviesa** el mapeo de exit code de `Application`. Alcanzable solo
      tras burlar `php.lint.person-reference`, y la ruidosidad es intencionada: decidir si merece un
      cuarto código o se declara residual argumentado.
- [x] [Review][Decision] **La anchura del bind mount `./` se midió sobre ficheros que hoy no existen.**
      La afirmación «ningún credencial bajo el árbol» es puntual y nada la mantiene cierta: el `source`
      es el directorio del proyecto Compose, que **en el checkout primario contiene
      `.claude/worktrees/**`** —el checkout completo de cada otra rama, con su propio `api/.env.local` /
      `pwa/.env.local` gitignorado— más `tmp/` y `.claude/settings.local.json`. Es dev-only y read-only,
      y está declarado en el PR, así que no es contrabando; lo que no está es guardado. La alternativa
      «mover los dos Compose a un subdirectorio y montar ese» no se evaluó.
- [x] [Review][Patch] Lecturas de I/O fuera del envoltorio `PersonReferenceProbeFailed`: el `foreach`
      sobre el tagged iterator construye cada fuente **de forma perezosa** (`RewindableGenerator`), y
      `axis()` se llama fuera del `try` — una avería ahí sale del CLI como `1` == `FAILURE`
      [api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php:131]
- [x] [Review][Patch] Fijar `zend.exception_ignore_args = On` en la config PHP del repo: los UUID de
      persona viajan como `vars` de frame en el stack trace que Sentry serializa para **toda** la cadena
      `previous`, y hoy prod solo se salva por el default de `php.ini-production` que nada en el árbol
      afirma (dev usa `php.ini-development`, donde está `Off`) [api/frankenphp/conf.d/10-app.ini]
- [x] [Review][Patch] El barrido de `#[AsSchedule]` no ve cuatro formas legales — `#[AsSchedule]` a
      secas (que Symfony resuelve a `scheduler_default`), `name:` como argumento nombrado, FQCN — ni la
      ruta `#[AsCronTask]`/`#[AsPeriodicTask]`, que acuña transporte **sin clase `ScheduleProvider`
      alguna**; nada de esto está en la lista de puntos ciegos declarada
      [api/tests/Support/ScheduleConsumption.php:47]
- [x] [Review][Patch] Una línea `messenger:consume` **comentada** cuenta como consumo: el regex corre
      sobre bytes crudos sin quitar comentarios, así que comentar el `command:` del `scheduler_worker`
      deja el gate verde con el schedule muerto — el fixture `compose.missing.yaml` solo cubre el nombre
      suelto en un comentario, no la línea de comando entera
      [api/tests/Support/ScheduleConsumption.php:86]
- [x] [Review][Patch] El gate une los `messenger:consume` de todo el fichero sin noción del servicio
      dueño, así que cablear el transporte de scheduler al `messenger_worker` **escalado** de prod pasa
      verde — y con él la duplicación de ticks que el `replicas: 1` del `scheduler_worker` existe para
      impedir [api/tests/Support/ScheduleConsumption.php:88]
- [x] [Review][Patch] El `break` al primer `-` modela mal `ArgvInput`, que acepta argumentos y opciones
      intercalados: un transporte escrito tras `--time-limit` es invisible para la mitad *stale* (el
      worker no arranca en el siguiente reinicio y el gate calla) y pondría rojo un cableado correcto en
      la mitad directa [api/tests/Support/ScheduleConsumption.php:92]
- [x] [Review][Patch] `axesCheckedKeys()` se computa, se ordena y se tira: la alarma lleva el número
      pero no los nombres, justo cuando el operador menos va a auditar el control — y el CLI sí los
      imprime en sus dos ramas
      [api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/ReconcilePersonReferencesHandler.php:60]
- [x] [Review][Patch] `IdentityMaintenanceScheduleTest` asierta solo `assertCount(1, …)`: sigue verde si
      el periodo pasa a `1 year` o si el mensaje se cambia por otro — las dos únicas cosas que el
      schedule codifica, en un PR cuya tesis es que un verde infalsable es peor que ningún check
      [api/tests/Unit/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceScheduleTest.php:23]
- [x] [Review][Patch] Comentario obsoleto justo encima de la clave que anota: «G-3b SIGUE sin empezar …
      solo hay dos `#[AsSchedule]` y ninguno es del reconciliador» — hay tres y uno es el suyo
      [_bmad-output/implementation-artifacts/sprint-status.yaml:277]
- [x] [Review][Patch] `declaredScheduleNames()` hace `continue` en silencio ante un fichero ilegible, sin
      warning ni aserción de conteo que lo compense [api/tests/Support/ScheduleConsumption.php:43]
- [x] [Review][Defer] `compose.dev.yaml` está fuera de `COMPOSE_FILES`, así que un `command:` en el
      overlay de dev supersedería al de `compose.yaml` sin que el gate lo viera
      [api/tests/Support/ScheduleConsumption.php:24] — diferido, hipotético hoy (el overlay no declara `command:`)
- [x] [Review][Defer] El techo de 65535 parámetros ligados: el docblock del puerto declara que «un
      llamador muy por encima de esa escala debe trocear» y el único llamador no trocea
      [api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php:99] — diferido, escala lejana y hoy falla ruidoso

**Refutada tras verificación** (se registra porque la hipótesis se levantó explícitamente): `axesChecked`
**sí** cuenta fuentes cableadas, no una lista estática de ejes — el mapa recibe una entrada por elemento
iterado del tagged iterator. Y una fuente desaparecida se caza en build/CI por tres controles
independientes (`PersonReferenceSourceGateTest` en sus dos direcciones, la aserción del cableado del
`!tagged_iterator`, y `PersonReferenceCollectionFunctionalTest`, que arranca el contenedor real y compara
`axesChecked()` contra el registro). Queda solo el residual de observabilidad ya recogido como parche.

## File List

**Nuevos**

- `api/src/Iam/Identity/Application/PersonReferenceProbeFailed.php`
- `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceSchedule.php`
- `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/ReconcilePersonReferencesHandler.php`
- `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/ReconcilePersonReferencesMessage.php`
- `api/tests/Support/ScheduleConsumption.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceScheduleTest.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Messenger/Maintenance/ReconcilePersonReferencesHandlerTest.php`
- `api/tests/Unit/Shared/Architecture/ScheduleConsumptionGateTest.php`
- `api/tests/Unit/Shared/Architecture/ScheduleConsumptionRulesGateTest.php`
- `api/tests/Unit/Shared/Architecture/Fixture/ScheduleConsumption/` (4 Compose de fixture + 1 fuente)

**Modificados**

- `api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php`
- `api/src/Iam/Identity/Infrastructure/Cli/ReconcileErasedSubjectReferencesCommand.php`
- `api/src/Shared/Audit/Infrastructure/Messenger/Maintenance/ReconcileSubjectErasuresHandler.php`
- `api/tests/Unit/Iam/Identity/Application/ReconcileErasedSubjectReferencesTest.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Cli/ReconcileErasedSubjectReferencesCommandTest.php`
- `api/tests/Unit/Shared/Audit/Infrastructure/Messenger/Maintenance/ReconcileSubjectErasuresHandlerTest.php`
- `compose.yaml`, `compose.dev.yaml`, `compose.prod.yaml`
- `make/php-quality.mk`
- `api/.person-reference-policy`
- `CLAUDE.md`, `api/CLAUDE.md`
- `docs/architecture-api.md`, `docs/claude-code-quickref.md`, `docs/deployment-guide.md`,
  `docs-info/production-deployment.md`
- `_bmad-output/implementation-artifacts/deferred-work.md` (bala del exit code borrada al resolverse)

## Change Log

- **2026-08-03** — «Estado medido» reescrito contra `main @ 93befb7c`; `baseline_commit` movido de
  `9310efeb` a `93befb7c` porque un review diffeando desde el viejo habría leído todo G-1c como
  parte de esta historia. Tarea 2 marcada hecha-por-G-1c y Tarea 7 reducida (la corrección de D3 del
  ADR ya estaba en `main`, `417b14ab`).
- **2026-08-03** — Implementación completa; AC3 ampliado a los dos brazos y Tarea 6 ter añadida tras
  autorizar Sergio plegar el item diferido del exit code.
