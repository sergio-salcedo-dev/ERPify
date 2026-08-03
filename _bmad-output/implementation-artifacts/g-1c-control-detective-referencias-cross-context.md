---
baseline_commit: 885ec3da
---

# Story 1.4-bis (G-1c): El control detective alcanza las cuatro referencias del eje

Status: in-progress

> **TRES DECISIONES ESTÁN TOMADAS Y NO SE REABREN** (ver *Decisiones registradas*): ③ **no hay FK** —resuelta a
> favor del código, y la corrección de la regla **ya está en el árbol**, no es tarea tuya—; la forma es **①**,
> ampliar `ReconcileErasedSubjectReferences` con un listador por contexto dueño (② es alternativa descartada, no
> decisión abierta); y la forma del puerto es **(a)**, contrato compartido en `Shared/Privacy/Application`
> recogido por iterador etiquetado, **con tres enmiendas que hay que leer** — el eje de auditoría queda FUERA de
> la colección, el resultado es un VO, y la clave del VO es `string`.

> **Esta historia es PROSPECTIVA: no repara datos, y el PR tiene que decirlo.** No existe entorno de producción
> (`.env.prod.local` ausente), así que no hay sujetos borrados reales cuyas filas huérfanas rescatar — el
> backfill queda descartado por medición. Lo que instala es la detección de que **una vía de escritura futura**
> reintroduzca el residuo en silencio. Es valor legítimo; callarlo hace que el PR se lea como si cerrara un
> hueco vivo, que es el hallazgo I-16 de G-4a repitiéndose (y el bloque que G-3a tuvo que escribir).

## Story

Como **responsable de cumplimiento**,
quiero que un borrado incompleto de cualquiera de las cuatro referencias a persona se **detecte** y no solo se
prevenga,
para que la garantía no dependa de que todo escritor futuro pase por la cadena de erasure.

**Eje que instala:** el control **detective** sobre el eje de referencias a persona — el que dice que la fila
**se fue**, frente al gate de G-1a, que solo prueba que el borrado está **escrito**.
**Invariantes que consume:** SI-21/NFR1. **Invariantes que establece:** ninguno nuevo.
**Dependencias:** G-1b (en `main`, #616/#618/#620). **Orden vs G-3b: indiferente** bajo ① — los listers se suman
a la colección y el schedule que G-3b instale los recoge sin tocar el handler.

## Estado medido (`main` @ `885ec3da`)

> *Procedencia:* pase de medición **read-only** sobre el worktree (nada ejecutado contra la BD, nada escrito).
> Las coordenadas se dan para que las **re-verifiques**, no para que las cites de memoria.

**El reconciliador de hoy, exacto.**
[`ReconcileErasedSubjectReferences`](../../api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php)
(`:41-52`) cubre **un solo eje** — `audit_log`, vía
[`PersonResourceReferences::unerasedIdsOfType()`](../../api/src/Shared/Audit/Application/PersonResourceReferences.php)
con `FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE`. Devuelve `list<string>` **plana** y resuelve la existencia
con `$this->users->findById($id)` **por id** (`:45-47`), que hidrata un `User` entero —con `email` y
`password_hash`— por cada referencia. Las dos trampas que los AC cierran están **vivas y localizadas ahí**.

**El comando YA existe; no crees otro.**
[`ReconcileErasedSubjectReferencesCommand`](../../api/src/Iam/Identity/Infrastructure/Cli/ReconcileErasedSubjectReferencesCommand.php)
(`identity:gdpr:reconcile-subject-references`) imprime el listado y **sale distinto de cero** en divergencia. La
épica dice «un comando, un schedule»: el comando está construido —le crecen los ejes en la salida— y **el
schedule es G-3b**, no esta historia.

**Los cuatro dueños de borrado existen y están declarados.** De
[`api/.person-reference-policy`](../../api/.person-reference-policy), las cuatro líneas `person ::` que no son la
PK del sujeto, con el puerto que cada dueño usa:

| Columna | Dueño declarado | Método de borrado |
|---|---|---|
| `Membership::$userId` | `src/Organization/Membership/Application/PurgeUserMembership.php` | `MembershipRepository::deleteAllForUser()` |
| `Invitation::$invitedUserId` | `src/Iam/Invitation/Application/PurgeUserInvitations.php` | `InvitationRepository::deleteAllForInvitedUser()` |
| `Session::$userId` | `src/Iam/Session/Application/PurgeUserSessions.php` | `SessionRepository::deleteAllForUser()` |
| `PasswordResetToken::$userId` | `src/Iam/Identity/Application/EraseIdentitySubject.php` | `PasswordResetTokenRepository::deleteAllForUser()` |

**Ninguno de los cuatro puertos sabe LISTAR hoy.** Todos saben borrar; ninguno sabe decir *«qué ids de persona
guardo»*. El más cercano, `SessionRepository::findByUserId()`, hidrata agregados **y** aplica el predicado de
vigencia (`status = ACTIVE AND expires_at > now`), así que no ve precisamente las filas expiradas que conservan
`user_id`/`ip`/`device` para siempre. **Hay que añadir el listador; no hay nada que reutilizar.**

**Las dos que faltaban no se auto-sanan** (por eso el alcance son cuatro y no dos): `iam_session` no tiene
reaper de ningún tipo —la expiración es un predicado de lectura, no una transición persistida
([`SessionRepository`](../../api/src/Iam/Session/Domain/Repository/SessionRepository.php) `:13-17`)— y
`PasswordResetTokenRepository::deleteExpired()` existe pero **nadie lo agenda**.

**Solo hay DOS `#[AsSchedule]` en `api/src`,** `AuditLogMaintenanceSchedule` y
`HandledDomainEventMaintenanceSchedule`, y **ninguno agenda este reconciliador**: el
`ReconcileSubjectErasuresMessage` del schedule de auditoría dispara al **hermano de crypto-shredding**
([`SubjectErasureReconciler`](../../api/src/Shared/Audit/Application/SubjectErasureReconciler.php), `Shared/Audit`),
que es otra clase. G-3b sigue sin empezar, medido contra el código y no contra `bmad.status.audit`.

**③ ya está cerrada EN EL ÁRBOL — no es trabajo tuyo.**
[`docs/rules/database.md`](../../docs/rules/database.md) (`:149-156`, `:190-206`, `:214`) ya dice que `User`
**no** es shared kernel, que referenciarlo es **Level 2** (columna de id, sin FK) y que un `ON DELETE CASCADE`
hacia `identity_user` está descartado. El docblock de `MembershipOrganizationForeignKeySchemaListener` era el
que decía la verdad. **Si tocas ese documento, es que has entendido mal el alcance.**

**El gate y su motor, para AC2.** `make php.lint.person-reference` corre **dos clases, cada una por nombre en su
propia ejecución** ([`make/php-quality.mk`](../../make/php-quality.mk) `:148-150`):
[`PersonReferenceGateTest`](../../api/tests/Unit/Shared/Architecture/PersonReferenceGateTest.php) (aserciones
sobre el árbol real) y `PersonReferenceRulesGateTest` (falsabilidad con fixtures). El motor es
[`PersonReferences`](../../api/tests/Support/PersonReferences.php); la exención de la PK del sujeto ya está
implementada —y por el predicado correcto, `#[ORM\Id]`— en
[`UndeclaredPersonReferences::isTheSubjectItself()`](../../api/tests/Support/UndeclaredPersonReferences.php).

**Los seams cross-context existentes.** [`api/.bounded-context-allowlist`](../../api/.bounded-context-allowlist)
declara ya `FulfilIdentityErasure` → `PurgeUserSessions` (`:117`), `PurgeUserMembership` (`:127`) y
`PurgeUserInvitations` (`:128`). Los listers nuevos **necesitan sus propias líneas** y su espejo en
`skip_violations` de [`deptrac.yaml`](../../api/tools/deptrac/deptrac.yaml) (`:446`).

## Decisiones registradas (NO reabrir)

**Decidido:** 2026-08-01, Sergio, sobre el debate Winston/Amelia + consulta externa. **Dónde queda el
registro:** este bloque, `sprint-status.yaml` (`:205-223`) y la épica (`:552-686`); el cuerpo del PR debe
reproducirlo.

- **③ — NO hay FK.** El acoplamiento a nivel de esquema cruzando frontera de contexto compra integridad
  referencial al precio del aislamiento sobre el que descansa el monolito modular, y la cadena de erasure ya
  posee la garantía de forma explícita. La regla era lo que estaba mal, y **ya está corregida**.
- **① — ampliar el reconciliador existente**, con un listador por contexto dueño (espejo de
  `PersonResourceReferences`). Winston, Amelia y una consulta externa convergieron por razones distintas: ②
  abre un ciclo `Iam/Identity ↔ Organization/Membership`; ② triplica un `#[AsSchedule]` que **nada comprueba que
  esté consumido**; y bajo ② el conjunto de ejes no es enumerable contra nada, mientras que bajo ① se ata al
  registro (que es lo que hace posible AC2).
- **El alcance son las CUATRO columnas.** El corte original decía dos: contó los seams que **añadió G-1b** en
  vez de la clase de defecto. Las cuatro comparten el defecto exactamente y **ninguna tiene FK**.

### Forma del puerto — TOMADA: (a), contrato compartido

**Decidido:** 2026-08-03, Sergio, sobre revisión externa contrastada contra el árbol. La forma del puerto era
implementación dentro de ① y la épica no la cerraba; ahora está cerrada.

**(a) Un contrato en `Shared/Privacy/Application`** —las claves del registro que cubre, más los ids de persona
que guarda— con una implementación por contexto dueño, recogidas por **iterador etiquetado**. Descartada **(b)
cuatro puertos distintos**: el constructor pasa a seis colaboradores sobre una clase cuyo hermano
`FulfilIdentityErasure` ya carga `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` por esta misma presión, y
AC2 tendría que enumerar tipos a mano — la enumeración manual que este eje existe para no volver a hacer.

**El argumento que decide, y es de arquitectura, no de ergonomía: bajo (a) no hay ni un import cross-context
nuevo.** El reconciliador importa `Shared\Privacy\Application\…`; cada adaptador importa su propio repositorio y
ese mismo contrato. `Erpify\Shared\…` es Level 3, siempre importable
([`BoundedContextGateTest`](../../api/tests/Unit/Shared/Architecture/BoundedContextGateTest.php) `:37`). Medido:
**cero líneas nuevas en el allowlist y cero `skip_violations` en deptrac**; bajo (b) serían tres y tres. `Shared`
no aprende `Membership`: aprende que existe *fuente de referencias a persona*, que es vocabulario de `Privacy`.

**La plantilla de cableado ya existe — no inventes una.**
[`Shared/Event/Application/Projector.php`](../../api/src/Shared/Event/Application/Projector.php) con
`tags: ['erpify.projector']` ([`services.yaml`](../../api/config/services.yaml) `:21`) consumido por
`$projectors: !tagged_iterator erpify.projector` (`:31`).

#### Enmienda 1 — el eje de `audit_log` queda FUERA de la colección

**No existe ninguna entidad Doctrine en `Shared/Audit`** (cero `#[ORM\Entity]`), y la cabecera del registro
declara `audit_log.resource_id` como punto ciego **estructural**: lo inyecta un listener `postGenerateSchema` y
se escribe por SQL crudo, así que ninguna propiedad lo declara y el barrido por reflexión no puede verlo nunca.
Luego `AuditLog::$resourceId` **no es ni puede ser una clave del registro**.

Si el contrato obligase a declarar claves del registro, el eje de auditoría no podría implementarlo: declararía
una clave que el registro no carga y la dirección «toda clave declarada corresponde a una línea» se pondría roja
por construcción. **Por eso `PersonResourceReferences` sigue siendo un colaborador propio** y el iterador lleva
solo los listers respaldados por registro. AC2 se lee entonces sin excepciones, y cada registro sigue gobernando
su eje —el de recurso ya tiene el suyo, `.audit-resource-types` más el testigo de G-3a. El reconciliador queda
en **tres** colaboradores: el puerto de auditoría, el iterador de listers y el predicado de existencia.

#### Enmienda 2 — el veredicto es un VO, y su clave interna es `string`

`array<PersonReferenceKey, list<string>>` **no es expresable**: las claves de array en PHP son `int|string`, así
que un VO no puede serlo y PHPStan en `level: max` —única puerta de tipos— rechaza el `@var`. El VO guarda
internamente clave `string` (la clave del registro) y expone el tipo rico en su API.

Se adopta el VO **por dos razones, y ninguna es especulativa**: hace imposible intercambiar clave y valor, y saca
al comando de la estructura interna (Tell-Don't-Ask). *«Permite añadir métricas en el futuro»* **no** es
justificación aquí — YAGNI es puerta explícita en este repo y una abstracción que se apoya en ese argumento no
está madura. Con las dos primeras basta.

#### Enmienda 3 — una sola identidad para el eje

El puente es que **el lister declare la clave (o las claves) del registro que cubre**. Así el gate resuelve «toda
línea `person ::` salvo la PK del sujeto tiene un lister que la nombra» sin heurística, y esa misma clave es la
del VO y la que asertan los tests. **Un puente por similitud de nombres (`Membership` ↔ `membership`) es la
enumeración frágil que AC2 existe para eliminar**: no lo escribas. Y la etiqueta que imprima el comando se
**deriva** de la clave — un segundo vocabulario de nombres de tabla nos deja dos nombres por eje al tercer mes.

## Acceptance Criteria

**AC1 — Detecta el huérfano, con el veredicto repartido como el precedente.**
**Given** una fila de cualquiera de las **cuatro** columnas del alcance cuyo id de persona no resuelve a una
identidad viva,
**When** corre el control detective,
**Then** la **reporta**, y el veredicto sale de la diferencia de dos hechos —el contexto dueño lista sus
referencias, `Iam/Identity` resuelve cuáles ya no son sujetos vivos—, **sin que ninguno lea la tabla del otro**.

**AC2 — El conjunto de fuentes es DEMOSTRABLEMENTE completo.**
**Given** el registro `api/.person-reference-policy`, que ya enumera toda referencia a persona con su dueño,
**When** corre la suite,
**Then** un gate falla si **alguna línea `person ::` salvo la PK del sujeto carece de un lister cableado** en el
control detective.
*La exención es la PK del sujeto y nada más* — reutiliza el predicado que ya existe
(`UndeclaredPersonReferences::isTheSubjectItself()`, que la resuelve por `#[ORM\Id]`). **No la escribas por
fichero dueño:** «cuyo dueño no sea `EraseIdentitySubject`» parece lo mismo y saca del gate a
`PasswordResetToken::$userId`, que **sí** está en el alcance — una cuarta parte del gate desactivada, y
compile-clean. Contra el registro de hoy la exención correcta deja exactamente las cuatro.
*Por qué es criterio de aceptación y no mejora:* sin él, olvidar el quinto contexto es compile-clean y habríamos
construido un control detective que necesita su propio control detective. **La prueba de que hace falta es esta
misma historia: su corte enumeró a mano y se dejó dos de cuatro.**

**AC3 — El veredicto lleva atribución por eje.**
**Given** el control cubriendo varios ejes,
**When** devuelve su resultado,
**Then** es **por eje** y **no una lista plana fusionada**, y los tests **asertan la clave**. La épica admite
`array<string, list<string>>` o un VO; **esta historia fija el VO** (Enmienda 2), con clave interna `string`.
*Por qué:* con lista plana, un test que siembre solo el eje de `audit_log` y asierta no-vacío **pasa aunque el
lister de membership no se haya cableado nunca** — SI-23 reintroducido dentro del control que instala SI-21.

**AC4 — No reporta un borrado correcto, y el test no puede pasar en vacío.**
**Given** un borrado completo,
**When** corre el control,
**Then** no reporta nada — y el test que cubre AC1 **siembra la divergencia de verdad**, afirmando que la fila
sembrada **existe** antes de asertar.
*Por qué:* el precedente inmediato de este eje fue un test que no la creaba y pasaba (`INSERT … SELECT` sobre
tabla vacía, #618); y la BD de tests **se migra pero no se provisiona**, así que una siembra que se apoye en
datos preexistentes inserta cero.

**AC5 — Solo lee, y no hidrata.**
**Given** el control,
**When** se inspecciona,
**Then** el puerto de existencia es un **predicado por lotes** (`existingIds(list<string>): list<string>` o
equivalente), no el `findById()` por id de hoy, que hidrata un `User` entero con `email` y `password_hash` por
cada referencia; y
**Then** **solo lee**: reparar es un acto deliberado de operador a través del caso de uso de erasure, no algo que
un chequeo agendado le haga por su cuenta a una tabla de cumplimiento.

**AC6 — Sin regresión, y las puertas se miden ejecutándolas.**
`make php.lint.person-reference`, `make php.stan`, `make php.quality`, `make php.unit` y `make php.behat`, cada
uno desde **ejecución fresca con exit code impreso**. Si añades una clase de gate nueva, **añade su propia línea
`--filter=<NombreExacto>`** al target y **verifica con `--list-tests`** que cada filtro selecciona lo que crees
(un prefijo común no distingue «corrieron las tres» de «una desapareció»).

## Tasks / Subtasks

- [x] **Tarea 1 — Forma del puerto.** Hecha: **(a)** con sus tres enmiendas, ver *Forma del puerto — TOMADA*.
      Reprodúcela en el PR; no la re-argumentes.
- [x] **Tarea 2 — Predicado de existencia por lotes (AC5).** Puerto estrecho propio `LiveIdentityDirectory`
      (`Domain/Repository/`, espejo de `ActiveAdministratorDirectory`) con `existingIdsAmong(list<string>)`, y
      adaptador DBAL `DoctrineLiveIdentityDirectory` — una sola sentencia por lote sobre la PK, sin hidratar.
      Devuelve la **grafía del llamante** porque el hex RFC 4122 es case-insensitive y el consumidor difiere
      con `===`.
- [x] **Tarea 3 — Listers de los cuatro ejes (AC1).** `PersonReferenceSource` en `Shared/Privacy/Application`
      (`axis()` + `retainedPersonIds()`), con cuatro adaptadores DBAL, uno por contexto dueño, recogidos por
      `erpify.person_reference_source`. Solo ids, `DISTINCT` + `ORDER BY` como en `DbalPersonResourceReferences`.
      SQL literal por adaptador y **no** un helper compartido con el nombre de tabla interpolado: un
      identificador no puede ser parámetro ligado, así que el helper sería interpolación en SQL.
- [x] **Tarea 4 — Atribución por eje, en un VO (AC3, Enmienda 2).** `UnreconciledPersonReferences` (clave
      interna `string`) + `PersonReferenceFinding`. Los ejes **limpios se conservan contadas** (`axesChecked()`):
      «comprobado y limpio» y «nunca cableado» no pueden deletrearse igual. La etiqueta se **deriva** de la
      clave — pero en el **presentador**, no en el VO: `DomainPresentationSeparationGateTest` prohíbe `label()`
      en `Domain/` (ADR DPS1/DPS4) y tenía razón, así que vive en `headingFor()` del comando.
- [x] **Tarea 5 — Gate de completitud de listers (AC2).** Motor `PersonReferenceSources` (raíz inyectable, lee
      el eje por reflexión sin construir la clase) + reglas puras `PersonReferenceSourceCoverage` en las dos
      direcciones y la colisión, con la exención extraída a `PersonReferenceKeys` **compartida** con
      `UndeclaredPersonReferences` (una sola definición). Fixtures por escenario en subdirectorios propios y
      dos líneas `--filter` nuevas en `make/php-quality.mk`, cada una verificada con `--list-tests`.
- [x] **Tarea 6 — Seams de arquitectura: comprobar que NO hacen falta.** Verificado **ejecutando**:
      `make php.lint.bounded-context` OK (8 tests) y `make php.deptrac` 0 violaciones / 0 uncovered.
      `git diff` sobre `api/.bounded-context-allowlist` y `api/tools/deptrac/` es **vacío** — cero entradas
      nuevas, como predecía la forma (a).
- [x] **Tarea 6-bis — Boy scout, nombrado.** `BoundedContextGateTest.php:37` ya no lista `User` como shared
      kernel; dice explícitamente que es el agregado de `Iam\Identity` y que referenciarlo es Level 2.
- [x] **Tarea 7 — Tests (AC1, AC3, AC4).** Unitarios con doble parametrizable `FixedPersonReferenceSource`
      **asertando la clave del eje** (incluido el caso que prueba que dos ejes no se fusionan); funcional por
      adaptador contra Postgres real en transacción con rollback, ids por ejecución, aserción por contenencia y
      **conteo de la fila sembrada ANTES de asertar el veredicto**; y un testigo de colección que afirma
      `axesChecked() === 5` a través del contenedor real.
- [x] **Tarea 8 — Documentación obligatoria.** Bloque de puntos ciegos **del control detective** en la cabecera
      de `api/.person-reference-policy` (seis, incluido «prueba que el lister existe y se recoge, nunca que su
      consulta lee la columna correcta»); bullets de [`CLAUDE.md`](../../CLAUDE.md) y
      [`api/CLAUDE.md`](../../api/CLAUDE.md) ampliados, y la entrada del gate en
      [`docs/claude-code-quickref.md`](../../docs/claude-code-quickref.md).
- [ ] **Tarea 9 — Puertas y pase adversarial (AC6 + definición de hecho de la épica).** Puertas **en verde con
      ejecución fresca y exit code impreso** (ver *Completion Notes*). **Pase adversarial pendiente de
      autorización de Sergio**: es obligatorio por CLAUDE.md y esta sesión prohíbe lanzar subagentes sin que lo
      pida el usuario. Sin él no hay `done`.

## Dev Notes

### Anti-patrones concretos de esta historia

- **No crees un comando ni un schedule.** El comando existe; el schedule es G-3b. Si te encuentras escribiendo
  un `#[AsSchedule]`, te has salido del alcance.
- **No toques `docs/rules/database.md`.** ③ ya está cerrada en el árbol.
- **No conviertas el detective en reparador.** AC5 lo prohíbe explícitamente y el docblock del reconciliador ya
  lo declara: *«Repairing is a deliberate operator act»*.
- **No hidrates para saber si algo existe.** Es el defecto que AC5 nombra, y el `findById()` de hoy es su
  instancia.
- **No mates el filtro `resource_erased = FALSE`** del eje de auditoría al refactorizar: sin él, **cada borrado
  correcto se reporta como divergencia** y el control se vuelve ruido que el operador aprende a ignorar — que es
  peor que no tenerlo. Los otros tres ejes no tienen equivalente: ahí la fila **se borra**, no se seudonimiza,
  así que cualquier fila viva con un `user_id` que no resuelve **es** la divergencia.

### Trampas del repo que muerden aquí

- **PHPMD tumba motores de gate por complejidad** (a G-3a se le cayó en 57 con umbral 50). Se arregla
  **separando responsabilidades**, nunca subiendo el umbral ni suprimiendo. El coupling ≤13 aplica también a
  `tests/`.
- **`make php.stan` en cada fichero PHP tocado**, y `make php.quality` al final: CI corre `quality.dry-run`
  (check-only) mientras el local **arregla y enmascara** — barre el sweep completo antes de empujar.
- **Vocabulario Behat: búscalo antes de escribirlo.** `make php.behat c='-dl'` lista los 205 patrones;
  `c="-d '<texto>'"` busca. Si esta historia toca `erase.feature`, es el momento de **gastar** un step idle que
  encaje, no de añadir una frase casi-duplicada.
- **Los subagentes están prohibidos sin autorización explícita del usuario en esta sesión.** El pase adversarial
  de la Tarea 9 es obligatorio por CLAUDE.md y choca con esa prohibición: **pregunta**, no lo omitas ni lo
  autocertifiques.

### Revisión de seguridad (declarar en el PR lo que no aplica)

Control **de solo lectura**, sin superficie HTTP nueva y sin migración (no hay cambio de esquema — dilo
explícitamente). Lo que **sí** aplica y hay que verificar: consultas Doctrine/DBAL **parametrizadas** en los
adaptadores nuevos (`:placeholder`, jamás interpolación); que la salida del comando lleve **ids y nada más** —
un lister que devolviera filas arrastraría `ip`/`device` de `iam_session` o el digest de credencial de
`iam_invitation` a un log; y que no se introduzca ninguna tabla ni consulta que re-ligue un seudónimo con la
persona (D4 prohíbe el crosswalk, y esta historia lee precisamente las dos columnas que lo harían posible).

### Project Structure Notes

- El reconciliador y su comando se quedan donde están (`Iam/Identity/{Application,Infrastructure/Cli}`): el
  contexto que posee a la persona es el que orquesta.
- **El contrato** vive en `Shared/Privacy/Application/` — junto a los dos atributos del eje, que ya viven en
  `Shared/Privacy/Domain/`. **Las implementaciones** viven en sus contextos (`Organization/Membership`,
  `Iam/Invitation`, `Iam/Session`, `Iam/Identity` para los tokens de reset), en
  `Infrastructure/Persistence/Doctrine/`, tagueadas para el iterador. Ninguna importa a otra.
- `PersonResourceReferences` (eje de `audit_log`) **no cambia de sitio ni entra en la colección** — Enmienda 1.
- Motor de gate y dobles en `api/tests/Support/`; fixtures bajo
  `api/tests/Unit/Shared/Architecture/Fixture/`, siguiendo lo que dejó G-3a.
- Sin migración. Sin cambio de esquema. Sin entidad nueva. Sin dependencia nueva de Composer — todo lo que
  esta historia necesita (reflexión, DBAL, PHPUnit, el motor de registro) ya está en el árbol.

### References

- `_bmad-output/planning-artifacts/epics-gdpr-hardening.md` `:552-686` — la historia, sus AC1–AC5 y las dos
  trampas de ①.
- `_bmad-output/planning-artifacts/arch-addendum-gdpr-hardening.md` — SI-21/SI-22/SI-23 y la fila de
  localización de G-1c.
- `_bmad-output/implementation-artifacts/g-3a-segundo-testigo-registro-audit-resource-types.md` — precedente
  inmediato de motor extraíble + fixtures + pase adversarial, y de declarar en el PR lo que la historia **no**
  protege.
- `docs/adr/audit-activity-log.md` — D4 (obligación distribuida, prohibición de crosswalk).
- `api/.person-reference-policy` — el registro y su bloque de puntos ciegos, que esta historia amplía.

## Dev Agent Record

**Rama:** `feat/shared-g1c-control-detective-referencias-persona` (worktree
`shared-g1c-control-detective-referencias-persona-exp9`, base `origin/main` @ `6a2aaace`). La rama `…-exp9`
del contexto **se mergeó como PR #631** a media sesión y GitHub la borró, así que el desarrollo arranca en una
rama nueva desde `main` (decisión de Sergio, 2026-08-03); el fichero de la historia en `main` es idéntico al
del worktree (md5 comprobado), luego no se pierde nada.

### Agent Model Used

Claude Opus 5 (1M context) — `claude-opus-5[1m]`.

### Debug Log References

**Puertas, ejecución fresca con exit code impreso (worktree, stack propio):**

| Puerta | Resultado |
|---|---|
| `make php.stan` | exit 0 — `[OK] No errors` (1164 ficheros) |
| `make php.quality` | exit 0 |
| `make php.quality.dry-run` (paridad CI) | exit 0 |
| `make php.unit` | exit 0 — 2173 tests, 9260 asserts |
| `make php.behat` | exit 0 — 383 escenarios, 3470 steps |
| `make php.lint.person-reference` | exit 0 — **cuatro** ejecuciones (8 + 10 + 5 + 7 tests) |
| `make php.lint.bounded-context` | OK — 8 tests |
| `make php.deptrac` | 0 violations, 0 uncovered, 2954 allowed |

**Selección de los `--filter` verificada con `--list-tests`** (los nombres anidan: `PersonReference…` es
prefijo de `PersonReferenceSource…`): cada uno de los cuatro selecciona **exactamente una** clase.

**Falsificaciones ejecutadas y revertidas** (los rojos se provocaron de verdad, no se asumieron):

1. Clave del eje de sesión → `…::$userIdTYPO`: **2 fallos**, uno por dirección (`Session::$userId` sin lister
   y `…TYPO` sin línea de registro). Restaurado.
2. `_instanceof` del contrato renombrado: `theCollectionIsWiredIntoTheReconciler` rojo con el mensaje exacto,
   más 5 errores de los funcionales (el contenedor deja de compilar). Restaurado.
3. `!tagged_iterator` apuntado a `erpify.projector` (compila, pero la colección es otra): rojo **solo** en las
   dos aserciones de cableado — el gate unitario y `axesChecked() === 5` del testigo funcional. Restaurado y
   re-verificado en verde.

### Completion Notes List

- **Forma (a) confirmada por medición, no por lectura:** cero líneas nuevas en `api/.bounded-context-allowlist`
  y cero `skip_violations` en `deptrac.yaml`; el `git diff` de ambos ficheros es vacío.
- **Un desvío argumentado respecto al corte:** la etiqueta por eje se deriva en el **comando**, no en el VO.
  `DomainPresentationSeparationGateTest` (ADR `domain-presentation-separation.md`, DPS1/DPS4) prohíbe un
  accesor `label()` en `Domain/`, y el gate tiene razón: texto legible es capa de presentación. La propiedad
  que la enmienda 3 pedía — **una** derivación, nunca un segundo vocabulario de nombres de tabla — se conserva
  intacta; solo cambia dónde vive.
- **`PersonReferenceFinding` en vez de un mapa por etiqueta:** dos ejes pueden derivar la misma etiqueta (un
  nombre corto de clase es único por módulo, no por árbol) y un informe donde un eje pisa a otro es el defecto
  del veredicto fusionado trasladado a la salida.
- **El test funcional monolítico se partió en cinco.** PHPMD lo tumbó por acoplamiento 22 > 13 y la respuesta
  correcta no era subir el umbral: cada adaptador tiene ahora su funcional **junto a su contexto** y el testigo
  de la colección queda solo en `Shared/Privacy`.
- **Deuda que NO se ha tocado, deliberadamente:** el lote de `existingIdsAmong()` es una sola sentencia con la
  lista expandida, acotada por el número de personas que la instalación haya tenido. Postgres corta en 65535
  parámetros; a esa escala haría falta trocear. No se trocea hoy (YAGNI, y el rendimiento se mide, no se
  asume), pero queda dicho aquí y en el docblock del puerto.
- **Revisión de seguridad (lo que aplica y lo que no):** sin superficie HTTP nueva, sin ruta, sin voter, sin
  migración y sin cambio de esquema — nada de eso aplica y se dice explícitamente. Sí aplica y se ha
  verificado: todas las consultas nuevas son literales o con parámetro ligado (`IN (:ids)` va como
  `ArrayParameterType::STRING`, jamás interpolación); el contrato devuelve **ids y nada más**, que es lo que
  impide que `ip`/`device` de `iam_session` o el `token_hash` de `iam_invitation` lleguen a una consola; y el
  control **no** crea crosswalk (D4): lee `audit_log` solo donde `resource_erased = FALSE`, es decir ids
  reales, nunca seudónimos, y no escribe nada.
- **La historia es PROSPECTIVA y el PR lo dirá:** no hay entorno de producción (`.env.prod.local` ausente),
  así que no repara datos — instala la detección de que una vía de escritura futura reintroduzca el residuo.
- **Pendiente para `done`:** el pase adversarial por alguien distinto del autor, registrado. Requiere
  autorización explícita de Sergio para lanzar el subagente.

### File List

**Producción — nuevos**

- `api/src/Shared/Privacy/Domain/PersonReferenceAxis.php`
- `api/src/Shared/Privacy/Application/PersonReferenceSource.php`
- `api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php`
- `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineLiveIdentityDirectory.php`
- `api/src/Iam/Identity/Application/UnreconciledPersonReferences.php`
- `api/src/Iam/Identity/Application/PersonReferenceFinding.php`
- `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DbalPasswordResetTokenPersonReferences.php`
- `api/src/Iam/Invitation/Infrastructure/Persistence/Doctrine/DbalInvitationPersonReferences.php`
- `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DbalSessionPersonReferences.php`
- `api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/DbalMembershipPersonReferences.php`

**Producción — modificados**

- `api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php`
- `api/src/Iam/Identity/Infrastructure/Cli/ReconcileErasedSubjectReferencesCommand.php`
- `api/config/services.yaml`

**Tests — nuevos**

- `api/tests/Support/PersonReferenceKeys.php`
- `api/tests/Support/PersonReferenceSourceCoverage.php`
- `api/tests/Support/PersonReferenceSources.php`
- `api/tests/Unit/Shared/Architecture/PersonReferenceSourceGateTest.php`
- `api/tests/Unit/Shared/Architecture/PersonReferenceSourceRulesGateTest.php`
- `api/tests/Unit/Shared/Architecture/Fixture/PersonReferenceSource/Covered/CoveredSourceFixture.php`
- `api/tests/Unit/Shared/Architecture/Fixture/PersonReferenceSource/Covered/AbstractSourceCarrier.php`
- `api/tests/Unit/Shared/Architecture/Fixture/PersonReferenceSource/Duplicated/FirstDuplicateSourceFixture.php`
- `api/tests/Unit/Shared/Architecture/Fixture/PersonReferenceSource/Duplicated/SecondDuplicateSourceFixture.php`
- `api/tests/Unit/Shared/Architecture/Fixture/PersonReferenceSource/ConstructorBound/ConstructorBoundSourceFixture.php`
- `api/tests/Unit/Shared/Privacy/Infrastructure/Double/FixedPersonReferenceSource.php`
- `api/tests/Unit/Iam/Identity/Application/InMemoryLiveIdentityDirectory.php`
- `api/tests/Unit/Iam/Identity/Application/UnreconciledPersonReferencesTest.php`
- `api/tests/Functional/Iam/Identity/DoctrineLiveIdentityDirectoryTest.php`
- `api/tests/Functional/Iam/Identity/DbalPasswordResetTokenPersonReferencesTest.php`
- `api/tests/Functional/Iam/Invitation/DbalInvitationPersonReferencesTest.php`
- `api/tests/Functional/Iam/Session/DbalSessionPersonReferencesTest.php`
- `api/tests/Functional/Organization/Membership/DbalMembershipPersonReferencesTest.php`
- `api/tests/Functional/Shared/Privacy/PersonReferenceCollectionFunctionalTest.php`

**Tests — modificados**

- `api/tests/Support/UndeclaredPersonReferences.php`
- `api/tests/Unit/Iam/Identity/Application/ReconcileErasedSubjectReferencesTest.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Cli/ReconcileErasedSubjectReferencesCommandTest.php`
- `api/tests/Unit/Shared/Architecture/BoundedContextGateTest.php` *(boy scout, Tarea 6-bis)*

**Registro, make y documentación**

- `api/.person-reference-policy`
- `make/php-quality.mk`
- `CLAUDE.md`
- `api/CLAUDE.md`
- `docs/claude-code-quickref.md`
- `_bmad-output/implementation-artifacts/sprint-status.yaml`
- `_bmad-output/implementation-artifacts/g-1c-control-detective-referencias-cross-context.md`

## Change Log

| Fecha | Cambio |
|---|---|
| 2026-08-03 | Tareas 2–8 implementadas: predicado de existencia por lotes, contrato compartido + cuatro listers, veredicto por eje en VO, gate de cobertura con fixtures, seams verificados en cero, boy scout, tests y documentación. Puertas en verde; pase adversarial pendiente de autorización. |
