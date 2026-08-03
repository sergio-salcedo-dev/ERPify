---
baseline_commit: 885ec3da
---

# Story 1.4-bis (G-1c): El control detective alcanza las cuatro referencias del eje

Status: ready-for-dev

> **DOS DECISIONES ESTÁN TOMADAS Y NO SE REABREN** (ver *Decisiones registradas*): ③ **no hay FK** —resuelta a
> favor del código, y la corrección de la regla **ya está en el árbol**, no es tarea tuya— y la forma es **①**:
> ampliar `ReconcileErasedSubjectReferences` con un listador por contexto dueño. ② (un reconciliador por
> contexto) es **alternativa descartada**, no decisión abierta.

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

### Lo que las decisiones NO fijan, y tienes que resolver tú

La **forma del puerto** es implementación dentro de ①, y la épica no la cierra. Dos formas, con el trade-off
medido; **elige, arguméntalo en el PR, y no lo dejes implícito**:

- **(a) Una interfaz común en `Shared/Privacy/Application`** —el eje que declara, más los ids que guarda— con
  una implementación por contexto dueño, recogidas por **iterador etiquetado**. *A favor:* AC2 se vuelve
  mecánico (el gate mapea línea del registro → lister cableado sobre una colección enumerable), y el
  constructor del reconciliador **no crece** — recuerda que `FulfilIdentityErasure` ya carga
  `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` por exactamente esta presión. *En contra:* `Shared`
  aprende que existe un vocabulario de ejes.
- **(b) Cuatro puertos distintos**, uno en el `Application/` de cada contexto dueño, inyectados por nombre.
  *A favor:* espejo literal de `PersonResourceReferences`, cero vocabulario compartido. *En contra:* el
  constructor pasa a cinco colaboradores y sube el coupling; y AC2 tiene que enumerar tipos a mano, que es
  justamente la enumeración manual que este eje existe para no volver a hacer.

**Recomendación (no decisión):** (a), porque AC2 es criterio de aceptación y (b) lo encarece sin comprar nada
que ① no tenga ya. Si eliges (b), el gate de AC2 sigue siendo obligatorio: resuélvelo y dilo.

**El puente que AC2 necesita, sea cual sea la forma.** El gate compara dos conjuntos y hay que decidir **sobre
qué identidad** los compara. La clave del registro es `<Fqcn>::$<propiedad>`; el lister conoce su entidad. La
forma barata es que **el lister declare la clave (o las claves) del registro que cubre** — así el gate resuelve
«toda línea `person ::` salvo la PK del sujeto tiene un lister que la nombra» sin heurística de nombres, y esa
misma clave es la que AC3 usa como clave de eje en el resultado. **Un puente por similitud de nombres
(`Membership` ↔ `membership`) es exactamente la enumeración frágil que el AC existe para eliminar**: no lo
escribas. Si el lister declara la clave, la coherencia clave-declarada ↔ entidad-real la comprueba el mismo
gate, y con eso las tres piezas —registro, lister, eje del veredicto— hablan de un único identificador.

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
**Then** es **por eje** (`array<string, list<string>>` o un VO), **no una lista plana fusionada**, y los tests
**asertan la clave**.
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

- [ ] **Tarea 1 — Elegir la forma del puerto (precondición de todo lo demás).** (a) o (b) de arriba, con el
      argumento escrito. Regístralo aquí y en el cuerpo del PR.
- [ ] **Tarea 2 — Predicado de existencia por lotes (AC5).** Sustituye `findById()` por el predicado. Decide si
      cuelga de `UserRepository` o de un puerto de lectura estrecho propio — el precedente de puerto estrecho en
      este contexto es `ActiveAdministratorDirectory` (`Domain/Repository/`), y es el que respeta ISP.
- [ ] **Tarea 3 — Listers de los cuatro ejes (AC1).** Uno por contexto dueño, con su adaptador Doctrine/DBAL.
      **Solo lectura, sin hidratar**: devuelve ids, no agregados. Sigue a `DbalPersonResourceReferences` —
      `DISTINCT` y `ORDER BY` no son cosméticos ahí (un alerta que diffea la salida dispararía con ruido sin
      ellos), y el mismo argumento aplica aquí.
- [ ] **Tarea 4 — Atribución por eje (AC3).** Cambia el retorno del reconciliador y **propaga a la salida del
      comando** (`ReconcileErasedSubjectReferencesCommand`, que hoy imprime una lista plana). El nombre del eje
      debe ser el mismo string por el que AC2 lo enumera.
- [ ] **Tarea 5 — Gate de completitud de listers (AC2).** Motor en `api/tests/Support/`, con raíz inyectable
      (espejo de `PersonReferences::fromGateLocation()`), y **fixtures** para provocar su rojo — no borrando
      artefactos reales. Registra su ejecución propia en `make/php-quality.mk`.
- [ ] **Tarea 6 — Seams de arquitectura.** Una línea **por fichero** en `api/.bounded-context-allowlist`
      (`<path> => <Fqcn>`) por cada import cross-context nuevo, y su espejo en `skip_violations` de
      `deptrac.yaml`. Verifica con `make php.lint.bounded-context` y `make php.deptrac`, no por lectura.
- [ ] **Tarea 7 — Tests (AC1, AC3, AC4).** Unitarios del reconciliador con dobles por eje (patrón
      `FixedPersonResourceReferences`) **asertando la clave del eje**; funcionales de cada adaptador contra
      Postgres real dentro de transacción con rollback, ids generados por ejecución y aserción **por
      contenencia** (la BD dev está sucia — el patrón exacto está en
      `PersonResourceReferencesFunctionalTest`).
- [ ] **Tarea 8 — Documentación obligatoria.** La cabecera de `api/.person-reference-policy` gana los puntos
      ciegos **del control nuevo** (qué NO detecta el detective); el bullet de
      [`CLAUDE.md`](../../CLAUDE.md) («Persisting a person's id») y el de
      [`api/CLAUDE.md`](../../api/CLAUDE.md) describen lo que el gate comprueba y **crecen con AC2**.
- [ ] **Tarea 9 — Puertas y pase adversarial (AC6 + definición de hecho de la épica).** Ejecuciones frescas con
      exit code. **Pase adversarial por alguien distinto del autor, REGISTRADO, declarando dónde.** Sin él no
      hay `done`.

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
- Los listers de los otros tres ejes viven en **sus** contextos (`Organization/Membership`, `Iam/Invitation`,
  `Iam/Session`), puerto en `Application/` y adaptador en `Infrastructure/Persistence/Doctrine/`. Es la misma
  dirección de dependencia que ya publican los tres `PurgeUser*`.
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

**Rama:** `feat/shared-g1c-control-detective-referencias-persona-exp9` (worktree aislado, base `main` @
`885ec3da`).

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
