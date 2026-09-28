### DW-1: PR #943 merged without the three review layers it owed — run a post-hoc pass over feafdddb

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: CLAUDE.md (Code review section, commit feafdddb)
reason: #943 retired a security control from the instructions as a no-story change and merged 2026-09-18 without the three review layers; a post-hoc Blind Hunter / Edge Case Hunter / Acceptance Auditor pass over feafdddb is the remaining option, checking the numbers it wrote into CLAUDE.md against the tree.
status: done 2026-09-24
archived: 2026-09-28
resolution: already resolved: 4bb9638d (#965) ran the Acceptance Auditor half of the post-hoc pass over feafdddb: corrected '21 merged' to 14 and the '#903 last review' claim (CLAUDE.md:306), with its own three review layers over that correction

Section note: Session closed with the thread unfinished; at filing time everything in this section was open and nothing blocked.

**PR #943 merged WITHOUT the three review layers, which it owed.** Merged 2026-09-18 as `feafdddb`; the layers were never run. Its body now says so, but the merge is done and that is the record: `CLAUDE.md` states the precedent this violated — the PR that retired the adversarial-pass gate was itself a `chore/` with no story, *"the one change in this repo's history that removed a security control would have been the first to owe nothing"*, and running the layers anyway returned two GRAVE plus a gate that could not fail. #943 had that same shape: a no-story change retiring a security control from the instructions. The rule's trigger is the surface touched, not whether the diff reaches `src/`. **A post-hoc pass over `feafdddb` is the remaining option**, and it is worth taking: the Acceptance Auditor can check the numbers that PR wrote into `CLAUDE.md` against the tree — "21 merged unreviewed", "#903 was the last real review", "no gate reads this file's contents" — and whether making the review-threads rule tool-agnostic dropped something specific and load-bearing; the Blind Hunter can check whether the added paragraph claims more than was measured. Edge Case Hunter will probably return little over a markdown diff; say so rather than padding.

### DW-3: Lesson: an open PR can merge mid-review, and the note recording it landed on a deleted branch

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: n/a
reason: The review debt was named in-session and the PR merged anyway between two turns; the note meant to record it was committed to the branch after it had merged and been deleted, so it is filed on main instead.
status: done 2026-09-24
archived: 2026-09-28
resolution: already resolved: Lesson now recorded in CLAUDE.md:258 ('A PR that is open is a PR that can merge…') and the note itself landed on main via 37e8ea61 (#947)

**How it merged unreviewed is itself the second lesson.** The review debt was named in the session, the PR was open, and it merged anyway between two turns — an open PR is a PR that can merge, and nothing in the tooling distinguishes "open, awaiting its review" from "open, ready". The note that was meant to record all this was committed to the PR's own branch AFTER that branch had already merged and been deleted, so it reached nobody; that is why it is filed here on `main` instead.

### DW-5: Context note: PR #929 (suite clock pin) merged with its three layers run — nothing left to do

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: n/a
reason: Filed as context only: #929 merged as 4a692aab with its three review layers run, all patch findings applied, both decision-needed items settled, and its worktree and branch cleaned up.
status: done 2026-09-24
archived: 2026-09-28
resolution: already resolved: Context-only entry: 4a692aab (#929) is on main with its review layers run; nothing was ever owed

Context, already finished and needing nothing: PR #929 (the suite clock pin) merged as `4a692aab`, with its three review layers run, all `patch` findings applied, and its two `decision-needed` items settled after consulting three independent readers. Its worktree and branch are cleaned up.

### DW-6: Audit: entry.action and metadata.operation encode the same fact with nothing keeping them in agreement

origin: migrated from legacy ledger ("Deferred from: code review of PR #853 — audit write-operation snapshot header (2026-08-26)"), 2026-09-24
location: api/src/Shared/Audit/Domain/AuditedEntity.php, api/src/Backoffice/Bank/Domain/Entity/Bank.php:118-125
reason: Both auditAction() implementations suffix _CREATED/_UPDATED/_DELETED mechanically so the two agree today, but nothing stops a future module diverging; not blocking with two audited modules. Trigger: the first auditAction() whose suffix is not one of the three verbs.
status: done 2026-09-24
archived: 2026-09-28
resolution: resolved by sweep bundle dw-audit-change-metadata-shape
resolution-undo: 83fdd0c39cf8bd1d2b727223562a30b0190f4386896a08a9d24f7158115f442b 2026-09-24 7374617475733a206f70656e

**(api/Shared/Audit — redundancia latente) `entry.action` y `metadata.operation` codifican la misma información sin ningún mecanismo que los mantenga de acuerdo.** Ambas implementaciones de `auditAction()` (`Bank`, `BankAccount`) sufijan mecánicamente `_CREATED`/`_UPDATED`/`_DELETED`, así que hoy el kind de escritura es recuperable también del final de `action`. Nada impide que un módulo futuro nombre su acción de otra forma y deje las dos fuentes divergiendo. No bloqueante hoy porque solo hay dos módulos auditados y ambos siguen la convención. Trigger: el primer `auditAction()` cuyo sufijo no termine en uno de los tres verbos. Ref: `api/src/Shared/Audit/Domain/AuditedEntity.php` (contrato), `api/src/Backoffice/Bank/Domain/Entity/Bank.php:118-125`.

### DW-9: D12's 'closed set of sanctioned mutations' is prose with half a gate — UPDATEs on event_store/audit_log are ungated

origin: migrated from legacy ledger ("Deferred from: code review of g-5-ids-de-persona-fuera-del-event-store (2026-08-04)"), 2026-09-24
location: docs/adr/event-store-and-projections.md:294
reason: Four mutations live (one UPDATE on event_store, two UPDATEs plus the retention DELETE on audit_log); AuditPruneStatementGateTest guards only the DELETE, so a new UPDATE enters unnoticed while the ADR promises a closed set. Trigger: the first proposal of another mutation on either table.
status: done 2026-09-24
archived: 2026-09-28
resolution: resolved by sweep bundle dw-sanctioned-mutation-gate
resolution-undo: 6ceb7fb97093ce893838d60471c9b3ca85d52b3c9446bfd8b89cc312d387e409 2026-09-24 7374617475733a206f70656e

**(api/Shared/Event — gobierno) El «conjunto cerrado de mutaciones sancionadas» que D12 declara es prosa con un gate a medias, y su disparador ya había saltado cuando se escribió esta bala.** Medido el 2026-09-20: viven **cuatro** mutaciones, no una — `event_store` tiene un `UPDATE` (`DbalEventStoreSubjectAnonymiser:63`) y `audit_log` tiene **tres**, los dos `UPDATE` de los ejes actor y recurso (`DbalAuditActorAnonymiser:74`, `DbalAuditResourceAnonymiser:99`) más el `DELETE` de retención (`DbalAuditLogPruner:144`). Las tres de `audit_log` son **anteriores** a esta bala (2026-06-25, 2026-06-26 y 2026-07-27 contra 2026-08-04), así que «la primera propuesta de una segunda mutación» nunca fue futuro. Y el gate existe sólo para una: `AuditPruneStatementGateTest` rechaza un segundo `DELETE FROM audit_log` en todo `src`, pero **nada vigila los `UPDATE`**. Nada cierra el conjunto: `git grep "UPDATE event_store"` es el único control y no está automatizado, así que una segunda mutación entra sin que ninguna puerta lo note, mientras el ADR sigue prometiendo que el conjunto es cerrado. El hueco es simétrico con `audit_log` (mismo patrón, mismo agujero), y por eso es preexistente y no un defecto de esta PR. Trigger: la primera propuesta de una segunda mutación sobre cualquiera de las dos tablas. Ref: `docs/adr/event-store-and-projections.md:294`.

### DW-10: ScheduleConsumption gate: compose.dev.yaml outside COMPOSE_FILES, so a dev-overlay command: is invisible

origin: migrated from legacy ledger ("Deferred from: code review of g-3b-agendado-observable-reconciliador-referencias-borradas (2026-08-04)"), 2026-09-24
location: api/tests/Support/ScheduleConsumption.php:24
reason: COMPOSE_FILES is compose.yaml + compose.prod.yaml while compose.dev.yaml already overrides messenger_worker; a one-line command: there would supersede compose.yaml:129 unseen. Hypothetical today (no command: in the overlay). Trigger: the first command: in compose.dev.yaml.
status: done 2026-09-24
archived: 2026-09-28
resolution: resolved by sweep bundle dw-schedule-gate-dev-overlay
resolution-undo: cacf4b726cf8c87a9729aa42d7cfa36c84e944695a00b0988301d0ce7cfdc33f 2026-09-24 7374617475733a206f70656e

**(api/tests — cobertura del gate) `compose.dev.yaml` está fuera de `COMPOSE_FILES`, así que un `command:` en el overlay de dev sería invisible.** `ScheduleConsumption::COMPOSE_FILES` es `['compose.yaml', 'compose.prod.yaml']`, pero `compose.dev.yaml` ya redefine `messenger_worker` (imagen, build, environment, volumes) y es el fichero cuyo trabajo es sobrescribir servicios en dev: añadirle un `command:` es un cambio de una línea e idiomático, y supersedería `compose.yaml:129` sin que el gate lo notara. Hipotético hoy — el overlay no declara `command:` — por eso queda diferido y no como parche. Trigger: el primer `command:` en `compose.dev.yaml`. Ref: `api/tests/Support/ScheduleConsumption.php:24`.

### DW-11: ReconcileErasedSubjectReferences: nobody chunks below the 65535 bound-parameter ceiling

origin: migrated from legacy ledger ("Deferred from: code review of g-3b-agendado-observable-reconciliador-referencias-borradas (2026-08-04)"), 2026-09-24
location: api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php:106, api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php
reason: existingIdsAmong() binds one parameter per id in a single statement; past ~65 536 distinct ids each daily tick fails loudly (PersonReferenceProbeFailed). Far-off scale and a loud failure, hence deferrable. Fix: chunk inside liveAmong().
status: done 2026-09-24
archived: 2026-09-28
resolution: resolved by sweep bundle dw-reconciler-scale-bounds
resolution-undo: 6033fe42be6e27d58bb581a1bfcf05a1707cf2ed0af97f1a8e9ac09e18008873 2026-09-24 7374617475733a206f70656e

**(api/Iam/Identity — escala) El techo de 65535 parámetros ligados no tiene dueño: nadie trocea.** `ReconcileErasedSubjectReferences:106` pasa la unión deduplicada de todos los ejes a `existingIdsAmong()` (vía `liveAmong()`, `:221`), que la expande a un parámetro ligado por id en una sola sentencia, y es el único llamador. Al cruzar ~65 536 ids distintos cada tick diario pasa a fallar y el CLI responde `INVALID` hasta que alguien implemente el troceo. Escala muy lejana, y el fallo es **ruidoso** (`PersonReferenceProbeFailed`) en vez de silencioso, que es lo que lo hace diferible. El docblock del puerto ya no afirma que un llamador trocee — decir la verdad sobre esto es lo que evita que el siguiente llamador dé el problema por resuelto. Fix: trocear en lotes por debajo del techo dentro de `liveAmong()`. Ref: `api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php:106`, `api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php`.

### DW-12: Six person-reference sources read their whole column with no LIMIT or keyset

origin: migrated from legacy ledger ("Deferred from: code review of g-3b-agendado-observable-reconciliador-referencias-borradas (2026-08-04)"), 2026-09-24
location: api/src/**/Dbal*PersonReferences.php (six PersonReferenceSource implementations)
reason: Each source SELECTs its entire table into PHP memory; DbalRecoverySecretPersonReferences even lacks DISTINCT. Degrades (slower daily task) rather than failing hard. Fix: keyset per user_id/resource_id, as DbalAuditLogPruner does. Trigger: the first reconciler tick that overruns its window.
status: done 2026-09-24
archived: 2026-09-28
resolution: resolved by sweep bundle dw-reconciler-scale-bounds
resolution-undo: 6033fe42be6e27d58bb581a1bfcf05a1707cf2ed0af97f1a8e9ac09e18008873 2026-09-24 7374617475733a206f70656e

**(api — escala del control detective) Las seis fuentes de referencias a persona leen su columna entera, sin `LIMIT` ni keyset.** Eran cinco cuando se escribió esta bala; **re-medido el 2026-09-20 son seis**, y la nueva es la que menos acota: `DbalRecoverySecretPersonReferences:48` (#877) hace `SELECT user_id FROM identity_recovery_secret ORDER BY user_id` **sin `DISTINCT`**, así que su resultado crece con las filas y no con las personas — el argumento «`DISTINCT` acota el resultado al número de personas» que sigue abajo no le aplica. Ninguna de las seis tiene `LIMIT`, `setMaxResults`, `OFFSET` ni continuación por keyset. Cada `PersonReferenceSource` hace un `SELECT DISTINCT` sobre toda su tabla y materializa el resultado en memoria PHP antes de que el reconciliador una los ejes: `DbalPersonResourceReferences:37` (`audit_log`, el más grande con diferencia — una fila por evento auditado, no por persona), `DbalMembershipPersonReferences:42`, `DbalSessionPersonReferences:47`, `DbalInvitationPersonReferences:41` y `DbalPasswordResetTokenPersonReferences:48`. `DISTINCT` acota el resultado al número de personas, no al de filas, así que el coste que crece sin techo es el del **scan**, no el del array. Es el mismo eje de escala que el techo de 65535 de la bala anterior y se cruzará antes, pero degrada (una tarea diaria más lenta) en vez de fallar en duro, que es lo que lo hace la menos urgente de las dos. Fix: keyset por `user_id`/`resource_id` en cada fuente, o `LIMIT` con continuación — lo mismo que ya practica `DbalAuditLogPruner`. Trigger: el primer tick del reconciliador que se salga de su ventana.

### DW-25: Two clocks per aggregate (injected Clock vs static SystemClock) — suite pin widened the divergence; open product-owner decision

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: api/src/Shared (AggregateRoot, SystemClock, DomainEvent:38), api/src/Iam/Session
reason: expiresAt comes from the injected Clock while createdAt/updatedAt and mutator stamps read static SystemClock::now(); since #929 a test session can be created in 2050 and expire in 2026, green. Production unaffected (SystemClockInitializer). Two readings disagree (pass the instant and delete SystemClock vs seed tests from the same clock in 5 files) and the decision is not the implementer's.
status: done 2026-09-24
archived: 2026-09-28
resolution: resolved by sweep bundle dw-suite-clock-seed-alignment
resolution-undo: 50bf58aed32c39a16719ba9d0928cafec956e63e0ca154fc6e26c8044b0f5547 2026-09-24 7374617475733a206f70656e

**(api — testabilidad) Dos relojes en cada agregado, y el pin de la suite AGRANDÓ la divergencia en vez de cerrarla. Decisión abierta, del product owner.** `expiresAt` se computa del `Clock` inyectado (vía el caso de uso), mientras `createdAt`/`updatedAt` (`AggregateRoot::__construct`) y los sellos de los mutadores leen el `SystemClock::now()` estático. Esta bala decía «bajo un `FixedClock` en test los timestamps quedan mutuamente inconsistentes» y estimaba la incoherencia en microsegundos; desde #929 son **24 años**: la suite fija el ambiental en `2050-06-15` y `StartSessionTest:26,35` inyecta un `FixedClock` en `2026-07-10`, así que la sesión sale con `createdAt = 2050` y `expiresAt = 2026` — una caducidad anterior a su propia creación, en verde. El salto no es accidente: #929 eligió ese año a propósito, buscando uno que el árbol no usara, y el docblock de `FreezeSystemClockExtension` ya declara que el pin agranda esta divergencia.

**Producción NO está afectada**, y eso está medido: `SystemClockInitializer:39-44` copia el `Clock` del contenedor sobre el ambiental en `kernel.request`, `console.command` y cada mensaje de worker, que son los tres puntos de entrada.

**Lo que esta bala daba por sentado es falso en tres puntos** (medido el 2026-09-20). `Clock` **no** es una dependencia de framework: el ADR lo bendice por su nombre (`docs/adr/external-dependencies-in-domain.md:36`), así que inyectarlo no es la violación que «barrido transversal fuera de scope» insinuaba. Inyectarlo por constructor arreglaría **1 de 16** lecturas de `SystemClock::now()` en `api/src`: **14** son mutadores sobre entidades ya hidratadas y Doctrine no re-ejecuta el constructor, y la decimosexta es `Image`, que ni siquiera extiende `AggregateRoot`. Y hay una **tercera** fuente que ninguna de las dos alcanza: `DomainEvent:38` sella `$occurredOn ?? new DateTimeImmutable()`, desnudo, en `Domain/`.

**Dos lecturas independientes, en desacuerdo, y la decisión no la toma el implementador.** Una propone que el agregado reciba el **instante** (no el reloj) como parámetro de la operación y borrar `SystemClock` entero — completa un patrón que el árbol ya sostiene en 15 firmas de dominio y deja menos código, pero choca con **65 bloques `__factory`** de Alice que llaman a las factorías posicionalmente desde YAML. La otra propone no tocar producción y aplicar en 5 ficheros la regla que `docs/rules/testing.md:83` ya enuncia (sembrar del mismo reloj que lee el sujeto), sobre la base de que hoy **ninguna aserción viva** cambia de signo por la divergencia — una trampa armada, no un fallo.

Dato que pesa en cualquiera de las dos: `Timestamped::setCreatedAt`/`setUpdatedAt` tienen **0 llamantes en `api/src` y 23 en `api/tests`**. Existen sólo para esquivar el sello ambiental, y son exactamente la forma que el checklist de seguridad prohíbe (setters de campos de auditoría en la entidad).

### DW-31: Nested empty 'changes' still travels as a JSON array and the PWA guard rejects it

origin: migrated from legacy ledger ("Deferred from: code review of the metadata-shape fix (2026-09-22)"), 2026-09-24
location: api/src/Shared/Audit/Application/AuditChangeDiff.php, api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php
reason: AuditChangeDiff::of() can return ['changes' => []] and the listener writes it unguarded, so isAuditChanges([]) fails into a mute drawer. Not measured that Doctrine delivers such a changeset — a code-constructible branch, not an observed defect. Cheap fix: a non-empty guard in the listener or sealing changes as an object.
status: done 2026-09-24
archived: 2026-09-28
resolution: resolved by sweep bundle dw-audit-change-metadata-shape
resolution-undo: 83fdd0c39cf8bd1d2b727223562a30b0190f4386896a08a9d24f7158115f442b 2026-09-24 7374617475733a206f70656e

**(pwa/backoffice/audit — contrato de cable) Un `changes` vacío anidado sigue viajando como array, y el guard lo rechaza igual.** La mitad de raíz está cerrada — el recurso de detalle expresa hoy `metadata` como `ArrayObject` y el normalizador conserva el objeto vacío, medido contra el stack vivo: `{}` en el cable para una fila sin metadata. Lo que la coacción de raíz no alcanza es una clave anidada: `AuditChangeDiff::of()` devuelve `['changes' => []]` cuando el changeset viene vacío o cuando se descartan todas sus entradas (los tres `continue` del recorrido), y `AuditWriteCaptureListener::capture()` escribe la entrada sin guarda de no-vacío, así que la fila se almacena y se sirve como `{"changes": [], "operation": "UPDATED"}` — y `isAuditChanges([])` cae en el mismo `isObjectRecord` que rechaza arrays, con el mismo drawer mudo. **No está medido que Doctrine llegue a entregar un changeset vacío o íntegramente descartable a esa ruta** (`getScheduledEntityUpdates()` normalmente implica changeset no vacío), así que es una rama construible por el código y no un defecto observado. Arreglo barato si se acepta: una guarda de no-vacío en el listener, o sellar `changes` como objeto. Ref: `api/src/Shared/Audit/Application/AuditChangeDiff.php`, `api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php`.

### DW-53: CLAUDE.md "Required checks" no nombra que añadir una mutación sobre event_store/audit_log exige una línea en SANCTIONED de SanctionedLogMutationGateTest más la decisión en el ADR.
origin: spec-deferred 95691e997eda
location: CLAUDE.md (Required checks)
source_spec: `spec-dw-9-sanctioned-mutation-gate.md`
severity: low
reason: Blind Hunter / Intent Auditor: los ADR y el quickref ya lo dicen, pero el fichero que los agentes leen primero no; el arreglo edita un fichero de contexto de agente, que el triage manda diferir.
status: done 2026-09-28
archived: 2026-09-28
resolution: fixed in #997: CLAUDE.md → Required checks names the closed mutation set and the SANCTIONED + ADR pair

### DW-54: El merge de pila sólo modela `command`; `include:`, `extends:`, `profiles:`, `entrypoint:` y `deploy.replicas: 0` en un overlay se ignoran en silencio.
origin: spec-deferred e0a580f06eae
location: api/tests/Support/ComposeStackCommands.php:34
source_spec: `spec-dw-10-schedule-gate-dev-overlay.md`
severity: medium
reason: Son claves YAML planas: parsean sin error y ComposeStackCommands::of() no las mira, así que un consume heredado por extends o traído por include se lee como "no consume nada", y un servicio bajo profiles se cuenta como consumidor aunque no arranque. Preexistente: el lector por fichero tenía el mismo punto ciego (el docblock del gate ya nombra extends). BoundedContainerLogRetentionGateTest ya rechaza include; el mismo rechazo aquí cerraría esa mitad.
status: done 2026-09-25
archived: 2026-09-28
resolution: fixed in #997 (review pass): ComposeStackCommands refuses a top-level `include:` and a service's `extends:` / `profiles:`, falsified by ScheduleStackMergeRulesGateTest; `entrypoint:` and `deploy.replicas: 0` stay read past, as its docblock states

### DW-55: El bullet "Declaring an #[AsSchedule]" del CLAUDE.md raíz no menciona que un `command:` en un overlay reemplaza el de la base.
origin: spec-deferred ea851dd8e2c3
location: CLAUDE.md
source_spec: `spec-dw-10-schedule-gate-dev-overlay.md`
severity: low
reason: Sigue siendo correcto para el árbol actual (añadir el transporte en compose.yaml y compose.prod.yaml), pero no avisa de la trampa que este cambio cierra. Editar ficheros de contexto de agente se difiere por regla del workflow.
status: done 2026-09-28
archived: 2026-09-28
resolution: fixed in #997: the CLAUDE.md #[AsSchedule] bullet says the gate reads stacks, an overlay command: replaces the base's, and include:/extends:/profiles: are refused

### DW-56: El bullet «Reading the clock in a test» del CLAUDE.md raíz no menciona el guardarraíl de FixedClock ni la retirada de los setters de Timestamped.
origin: spec-deferred e33d5b51c137
location: CLAUDE.md
source_spec: `spec-dw-25-suite-clock-seed-alignment.md`
severity: low
reason: Sigue siendo correcto (SystemClock::set / pin), pero no avisa de la regla nueva que ahora impone el doble. Editar ficheros de contexto de agente se difiere por regla del workflow; docs/rules/testing.md ya la documenta.
status: done 2026-09-28
archived: 2026-09-28
resolution: fixed in #997: the CLAUDE.md clock bullet names the FixedClock read guard and the removed Timestamped setters

### DW-57: Cinco tests preexistentes siguen llamando a SystemClock::reset(), que docs/rules/testing.md prohíbe.
origin: spec-deferred b9870c785d25
location: api/tests/Unit/Backoffice/Bank/Domain/Entity/BankTest.php:28
source_spec: `spec-dw-25-suite-clock-seed-alignment.md`
severity: low
reason: BankRenameNoOpTest:40, BankTest:28, BankAccountWriteEventTest:33, StoredBankAccountFixture:32, RevokeCurrentSessionBestEffortTest:63. Inocuos hoy (el pin de Finished restaura), no introducidos por este cambio; basta sustituirlos por FreezeSystemClockExtension::pin() o borrar el tearDown.
status: done 2026-09-25
archived: 2026-09-28
resolution: fixed in #997 (review pass): the five tearDown() calls restore with FreezeSystemClockExtension::pin(); only SystemClock's own tests still call reset()

### DW-58: Los tests que construyen sesiones ya caducadas con SessionMother::active(expiresAt: <pasado>) siguen produciendo filas con caducidad anterior a su createdAt, y el guardarraíl no lo ve.
origin: spec-deferred c8d88fb2b946
location: api/tests/Unit/Iam/Session/Domain/Entity/Mother/SessionMother.php
source_spec: `spec-dw-25-suite-clock-seed-alignment.md`
severity: medium
reason: El guardarraíl compara relojes en la lectura; una caducidad explícita pasada a la Mother no pasa por ningún reloj. Ej.: PruneRetiredSessionsTest::activeSession('-91 days') sella createdAt=NOW y expiresAt=NOW-91d. Preexistente. Lo resolvería construir cada sesión bajo un reloj ambiental en expiresAt-TTL, o una aserción createdAt<=expiresAt en la Mother.
status: done 2026-09-25
archived: 2026-09-28
resolution: fixed in #997 (review pass): SessionMother builds an already-expired session one TTL before its expiry and restores the exact ambient clock object, pinned by SessionMotherTest
