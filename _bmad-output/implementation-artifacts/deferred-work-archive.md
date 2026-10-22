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

### DW-4: Reactivate-or-retire decision on the review bot is still the user's and unresolved

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: CLAUDE.md (Code review section)
reason: CLAUDE.md records the review bot as inactive since 2026-08-31 (last real review #903), which stays correct only while it is inactive; reactivating it means reverting that paragraph, and the choice is the user's.
status: done 2026-09-28
archived: 2026-10-21
resolution: closed by human decision: Bot retired; CLAUDE.md already describes the three layers as the surviving control.
decision: 2026-09-28 Retire it permanently and close — Bot retired; CLAUDE.md already describes the three layers as the surviving control.

**The reactivate-or-retire decision on the review bot itself is still the user's and is unresolved.** `CLAUDE.md` now records it as inactive since 2026-08-31 (last real review: #903), which is correct only while it stays inactive — reactivating it means reverting that paragraph. Its own notice reports 46 PRs reviewed and 2 security issues surfaced workspace-wide; the two measured here are the #899 threads, one independently rediscovered by the Blind Hunter layer and one a false positive against a closed epic decision. Two data points, not a verdict on the other 44.

### DW-8: EventStoreSubjectAnonymiser::anonymise takes two consecutive strings; swapping them is a silent no-op

origin: migrated from legacy ledger ("Deferred from: code review of g-5-ids-de-persona-fuera-del-event-store (2026-08-04)"), 2026-09-24
location: api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php:61
reason: An irreversible mutation whose argument order is fixed only by a unit-test spy; a second caller inverting the pair would report 0 anonymised rows indistinguishable from 'no events'. YAGNI with one caller; fix with a subject/pseudonym value object or mandatory named args when a second caller appears.
status: done 2026-09-28
archived: 2026-10-21
resolution: resolved by sweep bundle dw-event-store-anonymiser-typed-args
resolution-undo: d72b63ce85460b3f51815097f28a5b37c80f0840e0f625e0762d17aa1f21b6b6 2026-09-28 7374617475733a206f70656e

**(api/Shared/Event — diseño de firma) `anonymise(string $subjectId, string $pseudonym)` toma dos `string` consecutivos en una mutación irreversible, y permutarlos es un no-op silencioso.** El anonimizador del eje de recurso recibe un `AuditResource` tipado precisamente para que los argumentos no se puedan intercambiar (`FulfilIdentityErasure:157`); aquí lo único que fija el orden es el espía del test unitario, y sólo sobre el orquestador actual. Un segundo llamador que invierta el par busca el pseudónimo, no casa nada, y deja `anonymized_event_rows: 0` en la entrada de cumplimiento — indistinguible de «este sujeto no tenía eventos», con el id real vivo. Con un solo llamador, tiparlo hoy es YAGNI; el argumento crece en cuanto aparezca el segundo. Fix: un value object para el par sujeto/pseudónimo, o parámetros nombrados obligatorios. Ref: `api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php:61`.

### DW-16: event_store stream UNIQUE enforces nothing (NULL tenant_id, NULLS DISTINCT); promised optimistic concurrency does not exist

origin: migrated from legacy ledger ("Deferred from: G-4a — fuga de `PasswordResetCompleted` en los transportes Messenger (2026-07-30)"), 2026-09-24
location: api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php:73-77
reason: tenant_id is always NULL so (NULL, x, 1) duplicates pass, the UniqueConstraintViolation catch is unreachable and EventStreamConcurrencyConflict is never thrown. Not fixed in G-4a because most publishers take no row lock, so NULLS NOT DISTINCT would turn silent races into 409s across ~15 paths; the owning story must decide whether stream version is a real invariant or informative.
status: done 2026-09-29
archived: 2026-10-21
resolution: resolved by sweep bundle dw-event-store-informative-version (#1035)
resolution-undo: e9c25f1139d58488497417fd599a52fe820e5c9fd72ca8394ff120b983635e09 2026-09-29 7374617475733a206f70656e
decision: 2026-09-28 Informative: retire the promise — Retire the optimistic-concurrency claim from DbalEventStore's docblock, remove the unreachable catch and EventStreamConcurrencyConflict, and document aggregate_version as informative in the event-store ADR.

**(api/Shared/Event — concurrencia) El UNIQUE de stream del `event_store` no impone nada, y el control de concurrencia optimista que su docblock promete no existe.** `event_store_stream_version_uniq` es `UNIQUE (tenant_id, aggregate_id, aggregate_version)` y `DbalEventStore:73` escribe `tenant_id` **siempre `NULL`**; PostgreSQL usa `NULLS DISTINCT` por defecto, así que dos filas `(NULL, x, 1)` entran las dos. **Verificado contra la BD viva** (`pg_indexes`, no el fichero de migración): el índice real **no** lleva la cláusula. Luego el `catch (UniqueConstraintViolationException)` de `DbalEventStore:77` es inalcanzable y `EventStreamConcurrencyConflict` no se lanza jamás — `git grep` lo encuentra **solo** en `DbalEventStore` (declaración y lanzamiento): ningún caso de uso lo captura ni reintenta.

**Por qué NO se arregló en G-4a, con la medición que lo decidió.** El pase adversarial de la historia (A-7) fijó el criterio por adelantado: *«enumera los caminos afectados; si aparece más de uno, saca el cambio de la PR»*. Enumeradas las **20** clases que publican eventos de dominio (`git grep -l 'eventBus->publish(' api/src`), **solo 4 toman lock de fila sobre el agregado por el que publican**: `AcceptInvitation`, `ChangeUserStatus`, `ChangeUserRoles` y `CompletePasswordReset` (que bloquea la fila del usuario y publica `PasswordResetCompleted`, cuyo `aggregateId` **es** ese usuario). `RequestPasswordReset` bloquea al usuario pero publica el evento del **token**, así que no cuenta. Los **16 restantes** —todo el ciclo de vida de `Bank` y `BankAccount`, los cuatro casos de `Iam/Invitation` salvo `AcceptInvitation`, `LoginAttemptRegistrar`, y las cuatro rutas de `Iam/Session`— no lo toman. Y el `INSERT` del `event_store` es DBAL crudo que corre en `publish()`, **antes** del flush del ORM, así que el `UPDATE` de la entidad no llega a serializarlos. Activar `NULLS NOT DISTINCT` convertiría en `EventStreamConcurrencyConflict` (409 crudo al cliente) carreras que hoy pasan en silencio, en 16 caminos a la vez y dentro de una historia GDPR. Muy por encima del umbral de A-7.

**Lo que esto significa, y es más que un obstáculo de migración.** La afirmación del docblock de `DbalEventStore:26-27` (la premisa del lock está en el comentario inline de `DbalEventStore:45-46`) («la UNIQUE del stream lo convierte en control de concurrencia optimista») es **falsa hoy**, y su premisa —«serializado por el lock de fila que la transacción de escritura ya mantiene sobre el agregado»— es falsa para 15 de 18 publicadores. Arreglar el índice sin arreglar esa premisa solo cambia *duplicados silenciosos* por *409 silenciosos*. La historia propia debe decidir las dos mitades: si el invariante de versión de stream se quiere de verdad (→ lock o reintento en cada publicador), o si `aggregate_version` es un número informativo (→ retirar la promesa del docblock y la excepción muerta).

**La migración en sí es barata y no bloquea:** `event_store` está hoy a 0 filas (la suite Behat trunca la tabla) y no existe entorno de producción, así que recrear el índice con `NULLS NOT DISTINCT` se aplica sobre tabla vacía. Contar duplicados antes, por si alguna base local acumuló filas: `SELECT count(*) FROM (SELECT 1 FROM event_store GROUP BY tenant_id, aggregate_id, aggregate_version HAVING count(*) > 1) d`. **Si sale > 0 no es un obstáculo: es la prueba de que el defecto ya se materializó.**

**El censo de publicadores está re-medido el 2026-09-20 y se mueve en las dos direcciones.** Son **24** clases que llaman `eventBus->publish(`, no 20: han entrado los cuatro casos de recuperación de credenciales de `Iam/Identity` (`ChangeMyPassword`, `MintRecoverySecret`, `RedeemRecoverySecret`, `RevokeRecoverySecret`), y los tres de recovery-secret **sí** toman el lock del agregado que publican. Conformes son **7** y no 4 — los tres anteriores más `ChangeUserStatus`, `ChangeUserRoles`, `ChangeMyPassword` y `CompletePasswordReset` — porque `LoginAttemptRegistrar` cruzó de lado al arreglarse el lost-update de `failedAttempts`: su camino de fallo resuelve hoy con `findByEmailForUpdate` dentro de `transactional()`, mientras su `clear()` sigue publicando sobre una lectura sin lock. Y hay **2 mixtos**, que es lo que hace cerrar la aritmética (7 + 2 + 15 = 24): `LoginAttemptRegistrar`, conforme en el camino de fallo y no en `clear()`, y `RevokeInvitation`, conforme al publicar la retirada del usuario y no al publicar la de la invitación. Quedan **15** no conformes; la enumeración de abajo sigue siendo el reparto correcto salvo por esos dos movimientos, y su propio «los **16 restantes**» lista quince — error que viene de origen.

**Un publicador más se ha movido al lado no conforme, y hay que heredarlo con la deuda (2026-08-07).** Al sacar el selector de aceptación del `event_store`, los seis eventos de `Iam.Invitation` pasaron a llevar el `invitedUserId` como `aggregateId`. `AcceptInvitation` era uno de los **4** que sí tomaban el lock del agregado por el que publicaban; ahora la clave que gobierna el versionado es el **usuario** mientras el lock que protege la publicación sigue siendo el de la fila **`Invitation`**. No cambia nada hoy —el UNIQUE es inerte— pero al activar `NULLS NOT DISTINCT` este camino pasa a necesitar lock o reintento como los otros 16, y además concentra una familia más de eventos sobre el eje del usuario, que ya compartían `Iam.Identity` y los dos revokes masivos de sesión.

### DW-22: PWA collapses every non-401 /me failure (incl. 503) to 'unauthenticated'

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx:70
reason: Half (a) closed by 31423b68; (b) a store outage 503 still presents as 'session required' and bounces to /login. Deferred, not a bug: ratified Decision F/AC9; routing 503 to /maintenance would be a UX-resilience improvement to the spec.
status: done 2026-09-29
archived: 2026-10-21
resolution: resolved by sweep bundle dw-pwa-me-503-to-maintenance (#1035)
resolution-undo: 1e34e2bec4d8fa80f5ba5ab6505a9700c856b8d10a86062670180f7c1dc50c0d 2026-09-29 7374617475733a206f70656e
decision: 2026-09-28 Route 503 to /maintenance — Distinguish a 503 store-unavailable /me response in the identity adapter and AuthProvider and route it to the existing /maintenance page rather than to /login, keeping network/malformed-body failures on the current path; update Decision F/AC9's record.

**(pwa/access — resiliencia UX) El PWA colapsa todo fallo no-401 de `/me` a «unauthenticated».** `AuthProvider.resolveSession` captura red/cuerpo-malformado/**503** → `null` → `UNAUTHENTICATED`. (a) **cerrada** — medido el 2026-09-20, `LoginForm.tsx:54-60` guarda hoy el `await login()` antes del toast y del `router.push`, así que un blip tras el login deja al usuario en el formulario con un error reintentable en vez de anunciar «Signed in» y rebotar; el arreglo entró el 2026-08-31 (`31423b68`), dos meses después de escribirse esta bala. (b) En un outage de store, `/me` 503 se presenta como «sesión requerida» y manda a `/login` (que también 503); se descarta la distinción 503/401 que el backend construyó (existe `/maintenance`). **Diferido, NO bug:** es la **Decisión F/AC9 ratificada** (`/me` KO → unauthenticated → B1 `/login`, para evitar el spinner infinito). Enrutar 503→`/maintenance` y no rebotar en un blip post-login sería una *mejora* del spec (pase de resiliencia UX), no un defecto. Ref: `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx:70`, `pwa/src/app/(auth)/_components/LoginForm.tsx:53`. **Enmienda 2026-10-22 (Sergio, al reconciliar #1037):** una sonda sin decisión (red, timeout, cuerpo malformado) ya no saca de una caída conocida — mantiene `unavailable` en vez de mandar a `/login` detrás de la misma pasarela; sin caída conocida sigue siendo `unauthenticated`. El re-probe automático con backoff y el criterio «solo 503 `service-unavailable`» de #1037 se descartan: rige el diseño de #1035 (502/503/504, reintento manual).

### DW-24: Orphaned ACTIVE iam_session rows (post-commit correlation failure, re-login without revoke)

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: api/src/Iam/Session/Application/StartSession.php:52
reason: Conscious trade-off documented in StartSession; PruneRetiredSessions now bounds such rows at ~97 days after login, so they are bounded, not avoided, and show as a 'ghost device' for the whole ACTIVE window.
status: done 2026-09-29
archived: 2026-10-21
resolution: resolved by sweep bundle dw-revoke-prior-session-on-relogin (#1035)
resolution-undo: dea7356760358eced66f116cc50b7f0295b0a1ea9d640115599edf4ea64806dd 2026-09-29 7374617475733a206f70656e
decision: 2026-09-28 Revoke the prior session on re-login — When a login mints a new session while the HTTP session already correlates a live iam_session, revoke that previous row in the same transaction, with a functional test showing no ghost device after re-login.

**(api/Iam/Session — higiene de datos) Filas `iam_session` `ACTIVE` huérfanas.** (a) `StartSession` escribe la correlación (`currentSession->set()`) DESPUÉS del commit del row+outbox; si `set()` lanza (p. ej. `getSession()` sin sesión) queda una fila `ACTIVE` sin `iamSessionId` que la referencie. (b) Un re-login con sesión viva acuña una fila nueva y sobrescribe `iamSessionId` sin revocar la previa → la anterior queda `ACTIVE` correlación-huérfana. Tradeoff consciente (documentado en el docblock de `StartSession`: correlación post-commit para no dejar un `iamSessionId` colgando), baja probabilidad; aparece como «dispositivo fantasma» en «mis sesiones». **La poda que esta bala esperaba ya no es futuro** (medido el 2026-09-20): `PruneRetiredSessions` existe, con `REVOKED_RETENTION` de `P30D` y `EXPIRED_RETENTION` de `P90D`, y su segundo disyuntor (`expiresAt < :expiredBefore`, que ignora el estado) **sí** barre una fila ACTIVE huérfana — pero a los ~97 días del login que la acuñó (7d de TTL + 90d de ventana). O sea que la fila está **acotada, no evitada**, y es visible como dispositivo fantasma durante toda la ventana ACTIVE. Ref: `api/src/Iam/Session/Application/StartSession.php:52`. **Acotado al reconciliar #1037 y revertido en #1076 (decisión de Sergio, 2026-10-21):** #1037 limitó la revocación a una sesión previa de la **misma** identidad, atribuyéndolo a una decisión del 2026-10-03 que no consta. Se mantiene la revocación incondicional de #1035: tras el login de otra persona sobre la misma cookie, la fila anterior ya no tiene portador —el firewall migró la sesión nativa y los demás dispositivos de su dueña tienen fila propia—, así que dejarla `ACTIVE` solo le mostraría un dispositivo que ya no tiene.

### DW-26: SessionAdmissionGate matcher (/api/) narrower than the firewall access_control (^/api)

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: api/src/Iam/Session/Infrastructure/Security/SessionAdmissionGate.php:57, api/src/Shared/Http/Infrastructure/ApiRequestMatcher.php
reason: A future route at exactly /api or /apiX would be firewall-authenticated but not session-gated; not exploitable today (everything outside /api/v1/ 404s). Both boundaries should derive from one definition.
status: done 2026-09-28
archived: 2026-10-21
resolution: resolved by sweep bundle dw-api-boundary-single-definition
resolution-undo: 368d96dbce23e1bea2c3b29f624a92313cfa7cc5eabd008f64aec09b18ea97bd 2026-09-28 7374617475733a206f70656e

**(api/Iam/Session — defensa en profundidad) Matcher del gate `/api/` más estrecho que el firewall `^/api`.** `SessionAdmissionGate` gatea vía `ApiRequestMatcher` (prefijo literal `/api/`), mientras `access_control` exige `IS_AUTHENTICATED_FULLY` en el regex `^/api` (sin barra). Una ruta futura montada en `/api` exacto o `/apiX...` quedaría firewall-autenticada pero SIN gate de sesión (una sesión revocada-pero-con-cookie pasaría). No explotable hoy (ninguna ruta fuera de `/api/v1/` → 404), pero ambos límites deberían derivar de una única definición. Ref: `api/src/Iam/Session/Infrastructure/Security/SessionAdmissionGate.php:57`, `api/src/Shared/Http/Infrastructure/ApiRequestMatcher.php`.

### DW-28: Should User store Email as a Doctrine embeddable (VO as persisted state)?

origin: migrated from legacy ledger ("Deferred from: code review of af-1-1-user-aggregate-backoffice-identity-persistencia (2026-07-02)"), 2026-09-24
location: api/src/Iam/Identity/Domain/Entity/User.php
reason: Today email is a scalar string with a transient Email::from() in the constructor; mapping #[ORM\Embedded] would make the VO the state. Open design question left by the architect; blocks nothing — revisit if the identity model grows.
status: done 2026-09-28
archived: 2026-10-21
resolution: closed by human decision: Scalar email plus boundary validation is sufficient; revisit only if the identity model grows.
decision: 2026-09-28 Keep the scalar and close — Scalar email plus boundary validation is sufficient; revisit only if the identity model grows.

**(auth-foundation / futuro) ¿`User` guarda `Email` como Doctrine embeddable (el VO = estado persistido)?** Hoy `email` es `string` escalar + `Email::from()->toString()` transitorio en el constructor. Alternativa: mapear `Email` como `#[ORM\Embedded]` para que el VO sea el estado (embeddable + migración + API de la entidad). Pregunta mayor que Winston dejó anotada — NO bloquea nada, revisar si el modelo de identidad crece. Ref: `api/src/Iam/Identity/Domain/Entity/User.php`.

### DW-29: Durability of the audit security branch (write-before-send) against a caller's transaction

origin: migrated from legacy ledger ("Deferred from: code review of stories 1.3 & 1.4 (2026-06-24)"), 2026-09-24
location: api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php (writeSecurity), DbalAuditLogWriter.php
reason: The synchronous security INSERT uses the shared DBAL connection without its own transaction, so a caller's rollback would revert the denial row, weakening ADR-D3. Low probability; fix the assumption (no business transaction, or a separately committed write). Extends the 're-review the security failure contract' item.
status: done 2026-09-28
archived: 2026-10-21
resolution: fixed in #1026: every request-boundary security write goes through RequestBoundarySecurityAudit, which refuses inside a leaked transaction; the eight findings of the bundle's own review were triaged on the #1026 branch and landed in #1027 (the spec holding that triage log is retired; git keeps it)

**(Epic 2 / Story 2.3) Durabilidad de la rama `security` (write-before-send) frente a una transacción del llamador.** El `INSERT` síncrono de `security` va por la `Connection` DBAL compartida sin transacción propia; si el llamador de Epic 2 abre una transacción de negocio que luego hace rollback, la fila de la denegación se revierte con ella, debilitando "una denegación nunca se pierde" (ADR-D3). Baja probabilidad (un `AccessDeniedException` rara vez tiene transacción de negocio abierta), pero al cablear 2.3 fijar la asunción: o no hay transacción de negocio al escribir la denegación, o la escritura `security` usa una conexión/transacción que commitea aparte. Extiende el item "re-revisar el contrato de fallo de `security` en 2.3". Ref: `api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php` (writeSecurity), `DbalAuditLogWriter.php`.

### DW-30: Residuals of the metadata-to-object coercion: historical audit_log rows stay [] and event_store.metadata still writes []

origin: migrated from legacy ledger ("Deferred from: code review of the metadata-shape fix (2026-09-22)"), 2026-09-24
location: api/src/Shared/Audit (audit_log writer), api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php:73
reason: (a) No backfill of historical [] rows — that would be a fourth sanctioned mutation on an append-only table, not the implementer's decision; object-shaped queries must keep bounding by ::text or jsonb_typeof. (b) event_store.metadata has the same defect and the ADR describes a default the migration lacks. Trigger for (b): the first query treating event_store.metadata as an object.
status: done 2026-09-29
archived: 2026-10-21
resolution: resolved by sweep bundle dw-event-store-informative-version (#1035)
resolution-undo: e9c25f1139d58488497417fd599a52fe820e5c9fd72ca8394ff120b983635e09 2026-09-29 7374617475733a206f70656e
decision: 2026-09-28 Fix (b) only; accept (a) — Make DbalEventStore write event_store.metadata as a JSON object ({} when empty), align the ADR with the migration's actual default, and record that historical audit_log [] rows are accepted without backfill.

**Dos residuos de la coacción de `metadata` a objeto, ninguno de los dos cerrado por ella.** El escritor de `audit_log` ya coacciona el nivel superior, así que **desde ese commit** toda fila nueva guarda `{}`; lo que queda abierto es lo de antes y lo de al lado. (a) **Las filas históricas siguen siendo `[]`**, y no hay backfill: hacerlo sería una cuarta mutación sancionada sobre una tabla append-only, decisión que no es del implementador. Mientras tanto la columna tiene dos formas en cualquier base desplegada, `jsonb_each` aborta sobre las viejas, y la mezcla dura años porque el tier `change` tiene un suelo de conservación de 5 años (`AuditRetentionPolicy::COMPLIANCE_RETENTION_FLOOR`). Cualquier consulta que trate `metadata` como objeto debe seguir acotando por `::text` o filtrar por `jsonb_typeof`. (b) **`event_store.metadata` tiene el defecto idéntico y sigue escribiéndose `[]`** (`DbalEventStore:73`); no rompe nada medible hoy porque su anonimizador opera por `regexp_replace` sobre `::text`, insensible a la forma, pero el ADR ya describe esa columna como `NOT NULL DEFAULT '{}'` mientras la migración no declara default alguno, o sea que el documento describe una forma que el escritor no produce. Trigger de (b): la primera consulta que trate `event_store.metadata` como objeto.

### DW-34: Failure contract of the audit security branch never re-reviewed against its live producer

origin: migrated from legacy ledger ("Deferred from: code review of Epic 1 audit specs (2026-06-23)"), 2026-09-24
location: api/src/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php:64
reason: The awaited condition is met — AccessDeniedAuditListener emits ACCESS_DENIED at AuditLevel::SECURITY on real /api requests — but D1's 'propagate on persistence failure' contract was never checked against that live use case.
status: done 2026-09-28
archived: 2026-10-21
resolution: fixed in #1026: every request-boundary security write goes through RequestBoundarySecurityAudit, which refuses inside a leaked transaction; the eight findings of the bundle's own review were triaged on the #1026 branch and landed in #1027 (the spec holding that triage log is retired; git keeps it)

**(Epic 2) El contrato de fallo de la rama `security` sigue sin re-revisarse, y su disparador ya saltó.** La condición que este item esperaba —un productor real en vez de fixtures sintéticos— se cumple: `AccessDeniedAuditListener:64` emite `ACCESS_DENIED` con `AuditLevel::SECURITY` sobre peticiones `/api` reales. Lo que queda pendiente es la revisión que eso habilitaba: el contrato de fallo decidido en D1 (propagar cuando falle la persistencia) nunca se ha contrastado contra ese caso de uso vivo. Ref: `api/src/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php:64`.

### DW-39: Public Navbar not auth-aware: authenticated users still see 'Sign in' (and 'Backoffice')

origin: migrated from legacy ledger ("Deferred from: code review of landing-login-cta (2026-07-15)"), 2026-09-24
location: pwa/src/app/_components/Navbar.tsx:54-69
reason: Neither CTA consults the session, so 'Sign in' is meaningless for a logged-in user. Out of the landing slice's scope; making the access cluster session-aware (useSession()) is a product + UX decision (consult the access spine).
status: done 2026-09-29
archived: 2026-10-21
resolution: resolved by sweep bundle dw-public-navbar-session-aware (#1035)
resolution-undo: 86daafc5a5042e9f52f2a9dd054271b9d896c4cb2cf567ee0828e525a5e59e9d 2026-09-29 7374617475733a206f70656e
decision: 2026-09-28 Make the access cluster session-aware — Use useSession() in the public Navbar to hide 'Sign in' and show a backoffice entry for authenticated users, with unit tests for both states.

**(pwa/frontoffice · UX · low) El `<Navbar>` público no es auth-aware: un usuario ya autenticado que visita `/` o `/status` sigue viendo el CTA «Sign in» (y «Backoffice»).** El nuevo enlace «Sign in» → `/login` se renderiza incondicionalmente, igual que el botón «Backoffice» preexistente — ninguno de los dos consulta la sesión. Para un usuario logueado, «Sign in» es un CTA sin sentido (aterriza en el formulario de login en vez de entrar al ERP). Fuera de alcance de este slice (que solo añade el punto de entrada de acceso); el redirect/consciencia de sesión ya estaba listado como follow-up. Follow-up: hacer el cluster de acceso del navbar consciente de la sesión (ocultar «Sign in» y/o mostrar «Entrar»/menú de usuario cuando `useSession()` está autenticado), decisión de producto + UX (consultar espina de acceso de Sally). Ref: `pwa/src/app/_components/Navbar.tsx:54-69`. **2026-10-22 (Sergio, al reconciliar #1037):** durante una caída rige el aviso «Sign-in temporarily unavailable» de #1035, no el «Sign in» que #1037 mantenía (decisión del 2026-10-03): el formulario está detrás de la misma pasarela caída.

### DW-44: Erasure repair path can run without writing a row that names the subject (GDPR_SUBJECT_ERASED)

origin: migrated from legacy ledger ("Deferred from: code review of g-2-ids-de-persona-fuera-de-audit-log-metadata (2026-08-04)"), 2026-09-24
location: api/src/Iam (FulfilIdentityErasure:144)
reason: GDPR_SUBJECT_ERASED is conditioned on erasedAnything(); when the identity is gone but references remain only GDPR_ERASURE_EXECUTED is written. Pre-existing; open question whether the resource axis needs its own row. Two tests pin the opposite behaviour on purpose, so changing it reopens their argument.
status: done 2026-09-28
archived: 2026-10-21
resolution: closed by human decision: GDPR_ERASURE_EXECUTED with anonymized_actor_id is sufficient evidence on the repair path.
decision: 2026-09-28 Keep current behaviour and close — GDPR_ERASURE_EXECUTED with anonymized_actor_id is sufficient evidence on the repair path.

**(api/Iam · evidencia de cumplimiento · medium) La ruta de reparación puede ejecutar un borrado sin dejar fila que nombre al sujeto.** `FulfilIdentityErasure:144` condiciona `GDPR_SUBJECT_ERASED` a `$identity->erasedAnything()` (identidad + tokens). Cuando la identidad ya no está pero quedan referencias — el estado exacto que `identity:gdpr:reconcile-subject-references` existe para detectar, y que `UserEraseController` documenta como «a completed cleanup, not a no-op» — solo se escribe `GDPR_ERASURE_EXECUTED`. **Preexistente**: la guarda no cambió en #636, y desde la review esa fila sí lleva `anonymized_actor_id`, así que el pseudónimo ya no queda huérfano. Lo que sigue abierto es si el eje **recurso** debe tener fila propia en ese camino. Dos tests fijan hoy el comportamiento contrario a propósito (`testResourceRowsAloneStillProduceComplianceEvidence`, `testReferenceRowsAloneStillProduceComplianceEvidence`), así que cambiarlo es reabrir su argumento, no corregir un descuido.

### DW-46: audit_log has no CHECK tying actor_type to actor_id nullability

origin: migrated from legacy ledger ("Deferred from: code review of br-4c-602-observabilidad-del-throttle-de-recuperacion (2026-08-12)"), 2026-09-24
location: audit_log schema (api/migrations), api/src/Shared/Audit
reason: Illegal rows are unrepresentable in PHP but not in the plain VARCHAR/nullable UUID columns written by raw DBAL, fixtures and Behat SQL; a user row with NULL actor_id escapes both erasure passes silently. Pre-existing; a schema CHECK plus enum-token CHECK is its own migration and decision.
status: done 2026-09-29
archived: 2026-10-21
resolution: resolved by sweep bundle dw-audit-log-actor-check-constraints (#1035)
resolution-undo: 616f89c8d7dc406a646d9c76ded067eb47fc34773f56778663cd395af56352ca 2026-09-29 7374617475733a206f70656e
decision: 2026-09-28 Add the CHECK constraints — Add a migration with CHECK ((actor_type IN ('anonymous','system')) = (actor_id IS NULL)) and an enum-token CHECK on actor_type, mirror them in the schema listener, fix any fixture/Behat SQL that violates them, and add a functional test proving an illegal row is refused.

**`audit_log` has no CHECK tying `actor_type` to `actor_id` nullability, so illegal rows are representable.** `ActorContext` makes `anonymous`/`system` with an id — and `user`/`api_key` without one — unrepresentable in PHP, but the column is plain `VARCHAR(16)`/nullable `UUID` and the table is written by raw DBAL, fixtures and Behat SQL. A `user` row carrying a NULL `actor_id` is matched by neither erasure pass (the actor pass matches on `actor_id`; the resource pass's metadata guard requires `actor_type = anonymous`), so a person's request metadata would survive both silently. Surfaced by the adversarial pass on the anonymous-actor redaction; pre-existing, and a schema-level fix (`CHECK ((actor_type IN ('anonymous','system')) = (actor_id IS NULL))` plus an enum-token CHECK) is its own migration and its own decision.

### DW-47: audit_log user_agent is client-forgeable as the literal [REDACTED]

origin: migrated from legacy ledger ("Deferred from: code review of br-4c-602-observabilidad-del-throttle-de-recuperacion (2026-08-12)"), 2026-09-24
location: api/src/Shared/Audit (user_agent capture)
reason: Stored verbatim from the header, so a request can write a row indistinguishable from a redacted one; nothing in api/src reads the sentinel, so the harm is to a human reading the trail — but resource-axis redaction now legitimately produces the same shape.
status: done 2026-09-28
archived: 2026-10-21
resolution: resolved by sweep bundle dw-audit-user-agent-sentinel-forgery
resolution-undo: 4f8d06267155660d82fb4f59b2fae8eafa007099e63530253ebb124e4c9c4845 2026-09-28 7374617475733a206f70656e

**`user_agent` is client-forgeable as the literal `[REDACTED]`.** It is stored verbatim from the header with no allowlist, so a request can write a row indistinguishable from a redacted one. Nothing in `api/src` reads the sentinel, so the damage is to a human reading the trail, not to code — but the resource-axis redaction now legitimately produces sentinel-with-`actor_erased = FALSE`, which is the same shape the forgery lands in.

### DW-48: Audit rows committed between the resource-axis UPDATE and the erasure commit keep request metadata

origin: migrated from legacy ledger ("Deferred from: code review of br-4c-602-observabilidad-del-throttle-de-recuperacion (2026-08-12)"), 2026-09-24
location: api/src/Shared/Audit (AuditSubjectRowLock), RecordLockoutAuditBestEffort, RecordRecoveryThrottleAuditBestEffort
reason: Neither late writer contends on identity_user, so the window is closed by nothing; it is recoverable (reconciler surfaces it and re-running the idempotent resource pass redacts it) but nothing re-runs it automatically.
status: done 2026-09-29
archived: 2026-10-21
resolution: resolved by sweep bundle dw-serialise-late-audit-writers-on-identity (#1035)
resolution-undo: 9d428fdb4548d84211b380e0baaa40b44b90e5117b8cbfc08e1afa77869fc35f 2026-09-29 7374617475733a206f70656e
decision: 2026-09-28 Serialise late writers on identity_user — Make the late audit writers take a lock on the subject's identity_user row (or skip when absent) so none can commit inside the erasure window.

**A row committed between the resource-axis `UPDATE` and the erasure's commit keeps its request metadata, and nothing serialises the two.** `AuditSubjectRowLock` scopes its guarantee to the rows existing when it ran, so a `USER_LOCKED` landing after the pass keeps the requester's ip with `resource_erased = FALSE`. **Neither writer of that shape contends on `identity_user`:** `RecordLockoutAuditBestEffort` runs post-commit with the subject id already in hand, and `RecordRecoveryThrottleAuditBestEffort::subjectOf()` resolves the subject with a plain unlocked `findByEmail` from a `kernel.terminate` listener — so the window is closed by nothing, not merely narrow. What makes it *recoverable* rather than permanent is that the late row keeps `resource_id` at the real id with `resource_erased = FALSE`: the reconciler surfaces it, and re-running the erasure's resource pass against that id redacts both columns, because the statement is idempotent by predicate rather than by flag. The residue is therefore narrower than "unhandled" — nothing re-runs it **automatically**, and an operator has to be told by the reconciler first.

### DW-50: hardNavigate: a throwing own-caller callback strands the queued losers

origin: migrated from legacy ledger ("Deferred from: adversarial pass of the #831 residual fix (2026-08-22)"), 2026-09-24
location: pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts (fire)
reason: fire() calls onFailure for the claim's own caller before draining superseded, so a throw leaves queued losers unreported. Not fixed: the module has no error-handling contract for caller callbacks and neither real caller throws.
status: done 2026-09-28
archived: 2026-10-21
resolution: resolved by sweep bundle dw-hardnavigate-drain-losers-on-throw
resolution-undo: 905bfed871485deb8609fc4a9cea47bd91ea83216eae17ac60d1043b629464fc 2026-09-28 7374617475733a206f70656e

**(hardNavigate seam) A throwing own-caller callback strands the queued losers.** `fire()` calls `onFailure("not-committed")` for the claim's own caller and only then drains `superseded`, so a throw in the first leaves every queued loser with no report at all — they wait for a callback that never comes. New in kind: before `superseded` was deferred there were no losers to strand. Not fixed, because the module has no error-handling contract for caller callbacks and inventing one means swallowing errors it cannot interpret; neither of the two real callers throws. The sibling hazard — a throw leaving the CLAIM itself unarmed — was a real defect and is fixed, with the ordering pinned by "arms the new claim before running the preempted caller's callback". Ref: `pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts` (`fire`).

### DW-52: Un metadata.changes almacenado como null o escalar se sirve tal cual y el guard PWA rechaza todo el sobre del detalle.
origin: spec-deferred 0d11259c1d42
location: api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php
source_spec: `spec-audit-change-metadata-shape.md`
severity: low
reason: Preexistente: AuditEventDetailResourceMapper sólo sella arrays; isAuditEventMetadata exige isAuditChanges cuando la clave existe. El listener nunca escribe null/escalar, así que sólo lo produciría otra vía de escritura o una fila corrupta.
status: done 2026-09-28
archived: 2026-10-21
resolution: resolved by sweep bundle dw-audit-detail-changes-shape
resolution-undo: 47b99c0a536e6197cb31dd804d9cd38d0ebecf361b4b3d51d5618b3a4186e8db 2026-09-28 7374617475733a206f70656e

### DW-59: Registrar en docs/rules/security.md el patrón «un límite por path se evalúa como el router: PathRequestMatcher sobre el path decodificado, nunca str_starts_with(getPathInfo())».
origin: spec-deferred ea65b7a10c18
location: docs/rules/security.md
source_spec: `spec-dw-26-api-boundary-single-definition.md`
severity: low
reason: Blind Hunter: el bypass /%61pi/v1/me existía porque un listener comparaba el path crudo mientras firewall y router decodifican. Hoy no queda ningún '/api/' literal en api/src (git grep), pero ninguna regla escrita ni gate impide reintroducir un chequeo crudo. Diferido porque el arreglo edita ficheros de reglas para agentes (docs/rules).
status: done 2026-09-28
archived: 2026-10-21
resolution: fixed in #1027 (the #1026 review, landed after #1026 merged): docs/rules/security.md gains «A path boundary is evaluated the way the router dispatches», and PRODUCTION_SECURITY_CHECKLIST.md states the session gate matches the decoded path

### DW-61: docs/rules/security.md y docs/rules/database.md describen el centinela [REDACTED] y sus dos escritores sin mencionar su reserva ni la reescritura [client-supplied].
origin: spec-deferred 0a417c155324
location: docs/rules/security.md:298
source_spec: `spec-dw-47-audit-user-agent-sentinel-forgery.md`
severity: low
reason: security.md:298 y database.md:75-79 no se tocaron en este cambio; el ADR y PRODUCTION_SECURITY_CHECKLIST.md sí. No afirman nada falso (no dicen que user_agent sea forjable), sólo omiten el patrón nuevo. Diferido porque el arreglo edita ficheros de reglas para agentes (docs/rules).
status: done 2026-09-28
archived: 2026-10-21
resolution: fixed in #1027 (the #1026 review, landed after #1026 merged): docs/rules/security.md and docs/rules/database.md state the [REDACTED] reservation and the [client-supplied] rewrite at capture

### DW-62: Una fila de nivel change SIN clave changes sigue pintando «No changes recorded», afirmación igual de desconocida que la del diff ilegible.
origin: spec-deferred 3f20d89e3077
location: pwa/src/context/backoffice/audit/infrastructure/ui/AuditEntryDrawer.tsx
source_spec: `spec-dw-52-audit-detail-changes-shape.md`
severity: low
reason: AuditEntryDrawer pasa `detail.metadata.changes ?? {}` a AuditChangeDiff cuando no hay flag; comportamiento previo a este cambio, y el capturador siempre escribe `changes` en filas change, así que sólo aparece con otra vía de escritura.
status: done 2026-09-28
archived: 2026-10-21
resolution: fixed in #1027 (the #1026 review, landed after #1026 merged): a change entry with no stored changes says so (audit-entry-drawer__diff--absent) instead of «No changes recorded»

### DW-64: event_store.payload se escribe como array JSON `[]` para eventos cuyo toPrimitives() está vacío (p.ej. BankDeletedDomainEvent).
origin: spec-deferred 8e448123a8db
location: api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php
source_spec: `spec-dw-16-30-event-store-informative-version.md`
severity: low
reason: DbalEventStore::encode() hace json_encode de un array PHP vacío; la columna se lee como objeto. Preexistente y fuera de DW-30(b), que sólo pedía metadata.
status: done 2026-09-29
archived: 2026-10-21
resolution: fixed in #1035 (round-3 review): DbalEventStore writes an empty payload as a JSON object {}, like metadata; readers decode both shapes to the same array

### DW-65: RepositoryUniqueViolationTest no tiene caso para el puerto `image` de ConcurrentUniqueWrite.
origin: spec-deferred 1ab3b10287b9
location: api/tests/Unit/Shared/Persistence/RepositoryUniqueViolationTest.php
source_spec: `spec-dw-16-30-event-store-informative-version.md`
severity: low
reason: api-error-contract.md dice que el `resource` es lo único que distingue los cuatro puertos y que se afirma por puerto; el proveedor del test sólo cubre bank, bank-account e identity-user. Preexistente.
status: done 2026-09-29
archived: 2026-10-21
resolution: fixed in #1035 (round-3 review): RepositoryUniqueViolationTest covers the image port

### DW-66: La sonda en frío de /me que se resuelve después de un login() puede pisar la sesión recién obtenida.
origin: spec-deferred 3ae2cbd0cfb3
location: pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx
source_spec: `spec-dw-22-pwa-me-503-to-maintenance.md`
severity: low
reason: AuthProvider aplica el resultado de la sonda inicial sin secuenciar contra login(); la forma es previa a DW-22 (antes pisaba con null, ahora también con unavailable). Solo ocurre si la sonda en frío tarda más que un login completo.
status: done 2026-09-29
archived: 2026-10-21
resolution: fixed in #1035 (round-3 review): AuthProvider sequences every probe (cold, per-route, login) with a ticket; only the latest applies

### DW-67: Un re-sondeo de /me disparado por navegación puede pisar el resultado de un login() o logout() posterior.
origin: spec-deferred 64a9c85297cc
location: pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx
source_spec: `spec-dw-22-pwa-me-503-to-maintenance.md`
severity: low
reason: Misma causa raíz que la entrada anterior: AuthProvider no secuencia sus sondas (el efecto de ruta cancela solo la suya). Con UNAVAILABLE/HYDRATING RequireAuth no monta los controles de logout; el caso requiere un login() que resuelva antes que el re-sondeo lanzado al llegar a /login.
status: done 2026-09-29
archived: 2026-10-21
resolution: fixed in #1035 (round-3 review): same probe sequencing; logout() also invalidates probes in flight

### DW-68: audit_log.level no tiene CHECK; una fila escrita por SQL crudo con un level desconocido o mal capitalizado no la casa ninguna pasada del pruner.
origin: spec-deferred 473433d709dd
location: api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php
source_spec: `spec-dw-46-audit-log-actor-check-constraints.md`
severity: low
reason: DbalAuditLogPruner borra por WHERE level = :level por cada AuditLevel; un token fuera del enum sobrevive para siempre. Preexistente y fuera del intent de DW-46 (solo actor_type/actor_id).
status: done 2026-09-29
archived: 2026-10-21
resolution: fixed in #1035 (round-3 review): Version20260930072817 adds audit_log_level_check (idempotent, with a pre-flight), guarded by AuditLogCheckEnumTokenGateTest and a drift test against AuditLevel::cases()

### DW-69: Otros dos escritores post-commit nombran al sujeto en resource_id sin bloquear identity_user: RecordLockoutNoticeAuditBestEffort y RecordRecoverySecretAuditBestEffort.
origin: spec-deferred d63734244a81
location: api/src/Iam/Identity/Application/RecordRecoverySecretAuditBestEffort::record(); api/src/Iam/Identity/Application/RecordLockoutNoticeAuditBestEffort::record()
source_spec: `spec-dw-48-serialise-late-audit-writers-on-identity.md`
severity: medium
reason: RecordRecoverySecretAuditBestEffort::record() (L118-127) escribe AuditResource::of(User, $userId) tras el commit de Mint/Redeem/RevokeRecoverySecret sin transacción ni bloqueo; RecordLockoutNoticeAuditBestEffort igual tras NotifyLockedIdentities::save(). Mismo defecto que DW-48 pero fuera de los dos escritores que nombra el intent; el del aviso es riesgo aceptado (@accepted-risk #860, cuyo razonamiento "aggregate-wide concurrency policy" conviene reabrir ahora que el mecanismo existe). Su residuo lo señala identity:gdpr:reconcile-subject-references; el docblock de DbalAuditSubjectRowLock y el ADR ya los nombran como NO serializados.
status: done 2026-09-29
archived: 2026-10-21
resolution: fixed in #1035 (round-3 review): every late audit writer, lockout notice and recovery secret included, writes under IdentityRowLock (lock-only DBAL port); accepted risk #860 closed

### DW-20: Bulk session revocation publishes OtherSessionsRevoked/AllSessionsRevoked even when 0 rows are affected

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-11, revisión fresca)"), 2026-09-24 — merged with ("Deferred from: code review of u-3-cambio-de-estado-suspend-deactivate (2026-07-18)")
location: api/src/Iam/Session/Application/RevokeOtherSessions.php:38, api/src/Iam/Session/Application/RevokeAllSessions.php, api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php:129
reason: bulkRevokeActive ignores the rowcount and both use cases publish unconditionally; inert today (no consumer) but a 'your sessions were closed' reaction would fire on a no-op. Fix when wiring the consumer: return the affected-row count and publish only when > 0 — note status.feature currently pins the event for identities that never logged in and would need updating.
status: done 2026-10-22
archived: 2026-10-22
resolution: fixed in fix/shared-iam-session-residuals-cims: the port returns the bulk UPDATE's affected-row count and RevokeAllSessions / RevokeOtherSessions / EvictOtherSessions publish only over a non-zero one (unit matrix + a 0-session roles.feature case; the session.feature canary seeds a second device to keep pinning the publish path)

**(api/Iam/Session — eventos) La revocación bulk emite su evento aunque afecte 0 filas.** `DoctrineSessionRepository::bulkRevokeActive` ejecuta el `UPDATE` dirigido sin mirar el rowcount, y `RevokeOtherSessions`/`RevokeAllSessions` publican `OtherSessionsRevoked`/`AllSessionsRevoked` incondicionalmente en el outbox. Inerte hoy (R2 wire-on-consumer, Decisión H: sin reactor/consumidor); muerde cuando II-5 (force re-login everywhere) o una notificación reaccione — un «tus sesiones se cerraron» dispararía sobre un revoke de 0 filas (usuario sin sesiones activas, o «cerrar las demás» siendo la actual la única). Fix al cablear el consumidor: `bulkRevokeActive` devuelve el nº de filas afectadas y el handler solo publica si >0. Ref: `api/src/Iam/Session/Application/RevokeOtherSessions.php:38`, `api/src/Iam/Session/Application/RevokeAllSessions.php`, `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php:129`.

**(session · semántica · low) `AllSessionsRevoked` se emite aunque el objetivo tenga 0 sesiones, y el éxito lo fija duro.** `RevokeAllSessions::revoke` publica el evento incondicionalmente; los escenarios de éxito de `status.feature` asertan `1 event … "erpify.iam.session.all-revoked"` para identidades que «never log in». El significado del evento («se revocaron sesiones») diverge de lo ocurrido («nada que revocar»); si `RevokeAllSessions` se endurece a emitir-solo-si-revocó, este test de status (módulo ajeno) rompe. Pre-existente al módulo Session. Ref: `api/src/Iam/Session/Application/RevokeAllSessions.php`.

### DW-21: FindUserOrganizationId resolves the org with a non-deterministic findOneBy under multi-membership

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-11, revisión fresca)"), 2026-09-24
location: api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/DoctrineMembershipRepository.php:39
reason: Safe under today's one-membership-per-user UNIQUE index, but a future multi-org slice would bind sessions to an arbitrary org. Fix when multi-org lands: explicit ordering / deterministic admission org, or a uniqueness assertion.
status: done 2026-10-22
archived: 2026-10-22
resolution: fixed in fix/shared-iam-session-residuals-cims: findByUserId reads unbounded with getOneOrNullResult() and translates NonUniqueResultException to the marker-less MembershipCardinalityViolated (500); FindUserOrganizationId answers ?string (null = no membership) and MembershipNotFound is gone; pinned by a functional test that drops the UNIQUE index inside a rolled-back transaction

**(api/Organization/Membership — determinismo) `FindUserOrganizationId` resuelve la org con `findOneBy` no determinista bajo multi-membership.** `DoctrineMembershipRepository::findByUserId` usa `findOneBy(['userId' => ...])` sin `ORDER BY` ni aserción de fila única; si un usuario llegara a tener >1 membership, la `organizationId` sellada en la sesión acuñada sería la fila que Postgres devuelva primero (arbitraria). Seguro bajo el invariante «una membership por usuario» de hoy (UNIQUE index `membership(user_id)`), pero un futuro slice multi-org ataría sesiones a una org aleatoria sin guardarraíl en este seam. Fix al entrar multi-org: orden explícito / selección de org de admisión determinista, o aserción de unicidad. Ref: `api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/DoctrineMembershipRepository.php:39`, `api/src/Organization/Membership/Application/FindUserOrganizationId.php`.

### DW-23: MembershipNotFound mapped to SessionStoreUnavailable (503 store-unreachable)

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: api/src/Iam/Identity/Infrastructure/Security/SessionMintingSuccessListener.php:57
reason: The catch-all rethrows any minting failure as a store outage, including a permanent admission data gap, which mislabels the cause and floods Sentry. Subsumed by O3 (Sentry dedup/fingerprint); consider a distinct marker for admission data gaps vs store down.
status: done 2026-10-22
archived: 2026-10-22
resolution: fixed in fix/shared-iam-session-residuals-cims: SessionMintingSuccessListener always drops the native session, then answers a raw DBAL failure as 503 service-unavailable, an identity with no membership as the marker-less SessionAdmissionUnresolvable (500 session-admission-unresolvable), and rethrows anything else untouched; docs/api-error-contract.md grades the login's session stage. The Sentry flood O3 asked to dedup is closed by the type itself: every occurrence is one class with one `type`, which Sentry groups into a single issue

**(api/Iam/Identity — observabilidad) `MembershipNotFound` mapeado a `SessionStoreUnavailable` (503 «store-unreachable»).** El `catch (Throwable)` del `SessionMintingSuccessListener` rethrowea `SessionStoreUnavailable::storeUnreachable($throwable)` para cualquier fallo del acuñado, incluido `FindUserOrganizationId` lanzando `MembershipNotFound` para un usuario válidamente autenticado sin membership. G1 ratificó 503 fail-closed, pero el marker miente semánticamente (no es un outage de store) y floodea Sentry ante un gap de datos permanente que un reintento del usuario no resuelve. Subsumido por O3 (dedup/fingerprint Sentry pre-prod); considerar un marker/observabilidad distinta para «gap de datos de admisión» vs «store caído». Ref: `api/src/Iam/Identity/Infrastructure/Security/SessionMintingSuccessListener.php:57`.

### DW-37: Invitation accept: no negative CSRF test (403 without mutation)

origin: migrated from legacy ledger ("Deferred from: code review of ii-4-invitation-accept-pantallas-acceso (2026-07-13)"), 2026-09-24
location: api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationController.php:34
reason: With check_header off (Decision C) stateless CSRF is satisfied by same-origin, which AcceptInvitationOriginListener already enforces, so no input fails CSRF but passes Origin and a negative test cannot fail. Writable once the double-submit check_header follow-up is enabled.
status: done 2026-10-22
archived: 2026-10-22
resolution: fixed in fix/shared-iam-session-residuals-cims: the negative (no X-CSRF-Token header) case was already covered by invitation_accept.feature since #520; the scenario now also asserts nothing moved — invitation still SENT, no iam_session row, no invitation.accepted or session.started event — red when the CSRF attribute is removed

**(Iam/Invitation · test seguridad · con el follow-up CSRF `check_header`) Sin test negativo de CSRF (403 sin mutación).** El accept exige `#[IsCsrfTokenValid('invitation_accept')]`, pero con `check_header` **off** (diferido por Decisión C) el CSRF stateless se satisface por same-origin — que `AcceptInvitationOriginListener` (prio 9) ya impone antes del firewall. No existe un input que CSRF rechace y Origin no, así que un test funcional negativo no puede fallar tal como está cableado. Escribible cuando se habilite el double-submit `check_header` (follow-up de consolidación `LoginOriginListener`). Ref: `api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationController.php:34`.

### DW-38: Invitation accept: no assertion that the session id is regenerated (anti-fixation, NFR3)

origin: migrated from legacy ledger ("Deferred from: code review of ii-4-invitation-accept-pantallas-acceso (2026-07-13)"), 2026-09-24
location: api/tests/Functional/Iam/Invitation/InvitationAcceptFunctionalTest.php
reason: Security::login triggers the native migrate(true); the gap is a regression guard should a future change abandon that path. A robust assertion requires planting the session cookie (fragile with the test client) — bundle with the check_header follow-up.
status: done 2026-10-22
archived: 2026-10-22
resolution: fixed in fix/shared-iam-session-residuals-cims: AssertsSessionIdRotation (plant/hold an id, assert a different one is issued and the old one is 401 on GET /me) applied to login, accept, reset, redeem and change-password; red under session_fixation_strategy: none. It found change-password NOT rotating (SessionStrategyListener skips a same-identity re-login), and POST /login from a signed-in device had the same gap; fixed for every main-firewall login by the RotateSessionIdOnLogin listener (applies the configured strategy when the id was not rotated, before the mint, never twice); the trait also asserts the issued id admits (GET /me 200) and plants an id the store holds; POST /login is the fifth flow

**(Iam/Invitation · test seguridad · anti-fixation) Sin assert de que el id de sesión se regenera (NFR3).** `Security::login` dispara el `migrate(true)` nativo; el funcional ya aserta «exactamente 1 sesión acuñada» pero no planta un id de sesión previo y compara pre/post. Garantía provista por el framework; el gap es un guard de regresión si un futuro cambio abandona el camino `Security::login`. Una aserción funcional robusta requiere plantar la cookie de sesión (frágil con el test client) → bundle con el follow-up `check_header`. Ref: `api/tests/Functional/Iam/Invitation/InvitationAcceptFunctionalTest.php`.

### DW-63: Un changes escalar corrupto nunca pasó por el sellado por campo, así que podría llevar PII sin cifrar servida tal cual por la ruta de detalle.
origin: spec-deferred fb38058ee328
location: api/src/Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php
source_spec: `spec-dw-52-audit-detail-changes-shape.md`
severity: low
reason: Preexistente: la API ya servía el escalar verbatim antes de este cambio (sólo cambia el cliente). Ningún escritor produce un escalar; sólo una fila corrupta. Lo zanjaría comprobar si el anonimizador de recurso o el crypto-shredding alcanzan un metadata.changes no-mapa.
status: done 2026-10-03
archived: 2026-10-22
resolution: verified a real gap in #1037: neither anonymiser nor crypto-shredding reaches metadata.changes. Closed in #1077 by product decision (option c, both sides): the detail mapper serves a `changes` map only on a `change` row and withholds any other non-empty `changes` whole; AuditLogEntry refuses `changes` on any other level so it is never stored; ChangeLevelProvenanceGateTest pins the producers of AuditLevel::CHANGE and the single audit_log append path. PRODUCTION_SECURITY_CHECKLIST.md §7 keeps only a `change` row that bypassed PiiDiffSealer

