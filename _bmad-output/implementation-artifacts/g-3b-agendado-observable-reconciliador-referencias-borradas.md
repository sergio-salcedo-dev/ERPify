---
baseline_commit: 9310efeb
---

# Story 1.6 (G-3b): El control detective del eje de recursos se ejecuta, falla de forma observable y alerta

Status: ready-for-dev

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

## Estado medido (`main` @ `9310efeb`)

> *Procedencia:* pase de medición **read-only** (nada ejecutado, nada escrito). Re-verifica las coordenadas.

**El control.** [`ReconcileErasedSubjectReferences`](../../api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php)
inyecta `UserRepository` (`:31-34`) y usa **exactamente una cosa** (`:45-49`): si el id sigue resolviendo a un
`User` vivo. Tiene CLI y **no aparece en ningún schedule**.

**El coste de frontera es cero, medido.** `Iam.Identity` ya tiene capas en
[`deptrac.yaml`](../../api/tools/deptrac/deptrac.yaml) (`:85-90`) y ruleset (`:336-349`); su capa
`Infrastructure` ya admite `Vendor.Symfony` (`:346`, que cubre `Scheduler` y `Messenger\Attribute`), `Vendor.Psr`
(`:342`, `LoggerInterface`) y `Iam.Identity.Application` (`:338`). Los transportes de scheduler **no se declaran
en [`messenger.yaml`](../../api/config/packages/messenger.yaml)**: `AddScheduleMessengerPass` crea
`messenger.transport.scheduler_<nombre>` desde `#[AsSchedule]`, y el autoregistro de
[`services.yaml`](../../api/config/services.yaml) (`:23-27`) hace el resto. **Un schedule + handler bajo
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

**Coste por tick, no presupuestado en el corte.** `DoctrineUserRepository::findById()` es un
`EntityManager::find()`, así que el reconciliador hace **una query y una hidratación completa de `User` por cada
id de sujeto no borrado** — con `email` y `password_hash` residentes en el worker. Hoy vive detrás de un CLI que
lanza un operador; agendado sin más, eso corre solo, a diario, creciendo linealmente.

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

**AC3 — Camino de hallazgo y camino de avería se distinguen.**
**Given** el handler,
**When** falla por avería (excepción) en vez de por hallazgo,
**Then** el mensaje lo distingue. *Medido: una excepción del handler no reintenta, no crea fila en `failed` y no
reinicia el worker — deja una línea `critical` y el bucle sigue. Un reconciliador roto de forma persistente
costaría una `critical` al día, en silencio, para siempre.*

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
- [ ] **Tarea 2 — Puerto de existencia** en `Iam\Identity\Domain\Repository` + adaptador Doctrine, y el control
      pasa a usarlo. (AC4)
- [ ] **Tarea 3 — Mensaje de tick + handler** bajo `src/Iam/Identity/Infrastructure/Messenger/Maintenance/`,
      copiando `ReportDeadLetterBacklogHandler` —**no** `ReconcileSubjectErasuresHandler`—. (AC2, AC3)
- [ ] **Tarea 4 — `IdentityMaintenanceSchedule`** con `#[AsSchedule('identity_maintenance')]` y su
      `RecurringMessage`. Test de existencia al estilo `AuditLogMaintenanceScheduleTest` (`:21-24`). (AC1)
- [ ] **Tarea 5 — Compose**: añadir el transporte a las dos listas de consumo, y actualizar el bloque de
      comentario de `compose.prod.yaml` que hoy nombra solo dos transportes. (AC7)
- [ ] **Tarea 6 — Los dos arreglos plegados** en `ReconcileSubjectErasuresHandler`, con test. (AC8)
- [ ] **Tarea 6 bis — Gate de consumo de schedules** (AC7): test de arquitectura + target `php.lint.*` + sus tres
      inserciones + el bind mount de solo lectura de los Compose, siguiendo el precedente del gate del contrato
      de errores. Su cabecera declara qué **no** prueba (presencia del nombre ≠ worker vivo). **Verifica que el
      `--filter` selecciona la clase listando los tests que el target elige**, no razonándolo — es el defecto
      que #613 pagó.
- [ ] **Tarea 7 — Docs**: `docs/deployment-guide.md` y `docs/architecture-api.md` nombran hoy solo los dos
      transportes existentes; y **corregir D3 de [`dead-letter-observability.md`](../../docs/adr/dead-letter-observability.md)**,
      cuya conclusión es correcta pero cuyo motivo es falso — *«lanzar re-encolaría el tick en `failed`
      (recursión)»* no ocurre: los transportes de scheduler no están entre los de fallo, así que el listener
      corta antes. La conclusión (usar una línea de log) se mantiene; el motivo no se cita hacia adelante.
- [ ] **Tarea 8 — Gates y pase adversarial (AC9 + definición de hecho de la épica).** Ejecuciones frescas con
      exit code. **Pase adversarial por alguien distinto del autor, REGISTRADO, declarando dónde.**

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
