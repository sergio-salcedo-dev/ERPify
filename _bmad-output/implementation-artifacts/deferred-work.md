# Deferred Work

### DW-1: PR #943 merged without the three review layers it owed — run a post-hoc pass over feafdddb

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: CLAUDE.md (Code review section, commit feafdddb)
reason: #943 retired a security control from the instructions as a no-story change and merged 2026-09-18 without the three review layers; a post-hoc Blind Hunter / Edge Case Hunter / Acceptance Auditor pass over feafdddb is the remaining option, checking the numbers it wrote into CLAUDE.md against the tree.
status: open

Section note: Session closed with the thread unfinished; at filing time everything in this section was open and nothing blocked.

**PR #943 merged WITHOUT the three review layers, which it owed.** Merged 2026-09-18 as `feafdddb`; the layers were never run. Its body now says so, but the merge is done and that is the record: `CLAUDE.md` states the precedent this violated — the PR that retired the adversarial-pass gate was itself a `chore/` with no story, *"the one change in this repo's history that removed a security control would have been the first to owe nothing"*, and running the layers anyway returned two GRAVE plus a gate that could not fail. #943 had that same shape: a no-story change retiring a security control from the instructions. The rule's trigger is the surface touched, not whether the diff reaches `src/`. **A post-hoc pass over `feafdddb` is the remaining option**, and it is worth taking: the Acceptance Auditor can check the numbers that PR wrote into `CLAUDE.md` against the tree — "21 merged unreviewed", "#903 was the last real review", "no gate reads this file's contents" — and whether making the review-threads rule tool-agnostic dropped something specific and load-bearing; the Blind Hunter can check whether the added paragraph claims more than was measured. Edge Case Hunter will probably return little over a markdown diff; say so rather than padding.

### DW-2: Lesson: #943's argument for skipping its own review was written by the same agent it governed

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: CLAUDE.md
reason: #943 was authored by an agent about a rule governing agents, and the argument for skipping its own review was written into its own body by that agent; nothing mechanical catches that shape — kept as the general lesson, not a detail of the PR.
status: open

**The asymmetry is worth keeping, because it is the general lesson and not a detail of this PR.** #943 was authored by an agent, about a rule governing agents, and the argument for skipping its own review was written into its own body by that same agent. Nothing mechanical catches that shape.

### DW-3: Lesson: an open PR can merge mid-review, and the note recording it landed on a deleted branch

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: n/a
reason: The review debt was named in-session and the PR merged anyway between two turns; the note meant to record it was committed to the branch after it had merged and been deleted, so it is filed on main instead.
status: open

**How it merged unreviewed is itself the second lesson.** The review debt was named in the session, the PR was open, and it merged anyway between two turns — an open PR is a PR that can merge, and nothing in the tooling distinguishes "open, awaiting its review" from "open, ready". The note that was meant to record all this was committed to the PR's own branch AFTER that branch had already merged and been deleted, so it reached nobody; that is why it is filed here on `main` instead.

### DW-4: Reactivate-or-retire decision on the review bot is still the user's and unresolved

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: CLAUDE.md (Code review section)
reason: CLAUDE.md records the review bot as inactive since 2026-08-31 (last real review #903), which stays correct only while it is inactive; reactivating it means reverting that paragraph, and the choice is the user's.
status: open

**The reactivate-or-retire decision on the review bot itself is still the user's and is unresolved.** `CLAUDE.md` now records it as inactive since 2026-08-31 (last real review: #903), which is correct only while it stays inactive — reactivating it means reverting that paragraph. Its own notice reports 46 PRs reviewed and 2 security issues surfaced workspace-wide; the two measured here are the #899 threads, one independently rediscovered by the Blind Hunter layer and one a false positive against a closed epic decision. Two data points, not a verdict on the other 44.

### DW-5: Context note: PR #929 (suite clock pin) merged with its three layers run — nothing left to do

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: n/a
reason: Filed as context only: #929 merged as 4a692aab with its three review layers run, all patch findings applied, both decision-needed items settled, and its worktree and branch cleaned up.
status: open

Context, already finished and needing nothing: PR #929 (the suite clock pin) merged as `4a692aab`, with its three review layers run, all `patch` findings applied, and its two `decision-needed` items settled after consulting three independent readers. Its worktree and branch are cleaned up.

### DW-6: Audit: entry.action and metadata.operation encode the same fact with nothing keeping them in agreement

origin: migrated from legacy ledger ("Deferred from: code review of PR #853 — audit write-operation snapshot header (2026-08-26)"), 2026-09-24
location: api/src/Shared/Audit/Domain/AuditedEntity.php, api/src/Backoffice/Bank/Domain/Entity/Bank.php:118-125
reason: Both auditAction() implementations suffix _CREATED/_UPDATED/_DELETED mechanically so the two agree today, but nothing stops a future module diverging; not blocking with two audited modules. Trigger: the first auditAction() whose suffix is not one of the three verbs.
status: open

**(api/Shared/Audit — redundancia latente) `entry.action` y `metadata.operation` codifican la misma información sin ningún mecanismo que los mantenga de acuerdo.** Ambas implementaciones de `auditAction()` (`Bank`, `BankAccount`) sufijan mecánicamente `_CREATED`/`_UPDATED`/`_DELETED`, así que hoy el kind de escritura es recuperable también del final de `action`. Nada impide que un módulo futuro nombre su acción de otra forma y deje las dos fuentes divergiendo. No bloqueante hoy porque solo hay dos módulos auditados y ambos siguen la convención. Trigger: el primer `auditAction()` cuyo sufijo no termine en uno de los tres verbos. Ref: `api/src/Shared/Audit/Domain/AuditedEntity.php` (contrato), `api/src/Backoffice/Bank/Domain/Entity/Bank.php:118-125`.

### DW-7: Audit diff: unconditional render of Empty fields generalises a privacy argument made only for BankAccount.alias

origin: migrated from legacy ledger ("Deferred from: code review of PR #849 — campos vacíos y dirección inferida en el diff de auditoría (2026-08-26)"), 2026-09-24
location: pwa/src/context/backoffice/audit/infrastructure/ui/AuditChangeDiff.tsx (ChangeKind.Empty)
reason: AuditChangeDiff shows 'Not set' for any nullable #[PersonalData] field on any audited entity, while the product owner's argument only covered alias. Trigger: the first audited nullable #[PersonalData] field whose mere presence/absence is more sensitive — consider a per-field opt-out.
status: open

**(pwa/context/backoffice/audit — alcance de privacidad) El render incondicional de campos `Empty` es genérico a toda `AuditedEntity`, pero el argumento de privacidad que lo autorizó solo razonó sobre `BankAccount.alias`.** `AuditChangeDiff` muestra "Not set" para cualquier campo `#[PersonalData]` nullable nunca poblado, en cualquier entidad auditada — no solo `alias`, el único ejemplo con el que el product owner argumentó la decisión en el spec. Es un hecho forense deliberado y correcto para el caso motivador; lo que falta razonar es la generalización. Trigger: la primera entidad auditada con un campo `#[PersonalData]` nullable donde la mera presencia/ausencia sea más sensible que en `alias` — revisar si ese campo necesita un opt-out por campo en vez de la regla genérica. Ref: `pwa/src/context/backoffice/audit/infrastructure/ui/AuditChangeDiff.tsx` (`ChangeKind.Empty`), `_bmad-output/implementation-artifacts/spec-gh-413-campos-vacios-y-direccion-inferida.md`.

### DW-8: EventStoreSubjectAnonymiser::anonymise takes two consecutive strings; swapping them is a silent no-op

origin: migrated from legacy ledger ("Deferred from: code review of g-5-ids-de-persona-fuera-del-event-store (2026-08-04)"), 2026-09-24
location: api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php:61
reason: An irreversible mutation whose argument order is fixed only by a unit-test spy; a second caller inverting the pair would report 0 anonymised rows indistinguishable from 'no events'. YAGNI with one caller; fix with a subject/pseudonym value object or mandatory named args when a second caller appears.
status: open

**(api/Shared/Event — diseño de firma) `anonymise(string $subjectId, string $pseudonym)` toma dos `string` consecutivos en una mutación irreversible, y permutarlos es un no-op silencioso.** El anonimizador del eje de recurso recibe un `AuditResource` tipado precisamente para que los argumentos no se puedan intercambiar (`FulfilIdentityErasure:157`); aquí lo único que fija el orden es el espía del test unitario, y sólo sobre el orquestador actual. Un segundo llamador que invierta el par busca el pseudónimo, no casa nada, y deja `anonymized_event_rows: 0` en la entrada de cumplimiento — indistinguible de «este sujeto no tenía eventos», con el id real vivo. Con un solo llamador, tiparlo hoy es YAGNI; el argumento crece en cuanto aparezca el segundo. Fix: un value object para el par sujeto/pseudónimo, o parámetros nombrados obligatorios. Ref: `api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php:61`.

### DW-9: D12's 'closed set of sanctioned mutations' is prose with half a gate — UPDATEs on event_store/audit_log are ungated

origin: migrated from legacy ledger ("Deferred from: code review of g-5-ids-de-persona-fuera-del-event-store (2026-08-04)"), 2026-09-24
location: docs/adr/event-store-and-projections.md:294
reason: Four mutations live (one UPDATE on event_store, two UPDATEs plus the retention DELETE on audit_log); AuditPruneStatementGateTest guards only the DELETE, so a new UPDATE enters unnoticed while the ADR promises a closed set. Trigger: the first proposal of another mutation on either table.
status: open

**(api/Shared/Event — gobierno) El «conjunto cerrado de mutaciones sancionadas» que D12 declara es prosa con un gate a medias, y su disparador ya había saltado cuando se escribió esta bala.** Medido el 2026-09-20: viven **cuatro** mutaciones, no una — `event_store` tiene un `UPDATE` (`DbalEventStoreSubjectAnonymiser:63`) y `audit_log` tiene **tres**, los dos `UPDATE` de los ejes actor y recurso (`DbalAuditActorAnonymiser:74`, `DbalAuditResourceAnonymiser:99`) más el `DELETE` de retención (`DbalAuditLogPruner:144`). Las tres de `audit_log` son **anteriores** a esta bala (2026-06-25, 2026-06-26 y 2026-07-27 contra 2026-08-04), así que «la primera propuesta de una segunda mutación» nunca fue futuro. Y el gate existe sólo para una: `AuditPruneStatementGateTest` rechaza un segundo `DELETE FROM audit_log` en todo `src`, pero **nada vigila los `UPDATE`**. Nada cierra el conjunto: `git grep "UPDATE event_store"` es el único control y no está automatizado, así que una segunda mutación entra sin que ninguna puerta lo note, mientras el ADR sigue prometiendo que el conjunto es cerrado. El hueco es simétrico con `audit_log` (mismo patrón, mismo agujero), y por eso es preexistente y no un defecto de esta PR. Trigger: la primera propuesta de una segunda mutación sobre cualquiera de las dos tablas. Ref: `docs/adr/event-store-and-projections.md:294`.

### DW-10: ScheduleConsumption gate: compose.dev.yaml outside COMPOSE_FILES, so a dev-overlay command: is invisible

origin: migrated from legacy ledger ("Deferred from: code review of g-3b-agendado-observable-reconciliador-referencias-borradas (2026-08-04)"), 2026-09-24
location: api/tests/Support/ScheduleConsumption.php:24
reason: COMPOSE_FILES is compose.yaml + compose.prod.yaml while compose.dev.yaml already overrides messenger_worker; a one-line command: there would supersede compose.yaml:129 unseen. Hypothetical today (no command: in the overlay). Trigger: the first command: in compose.dev.yaml.
status: open

**(api/tests — cobertura del gate) `compose.dev.yaml` está fuera de `COMPOSE_FILES`, así que un `command:` en el overlay de dev sería invisible.** `ScheduleConsumption::COMPOSE_FILES` es `['compose.yaml', 'compose.prod.yaml']`, pero `compose.dev.yaml` ya redefine `messenger_worker` (imagen, build, environment, volumes) y es el fichero cuyo trabajo es sobrescribir servicios en dev: añadirle un `command:` es un cambio de una línea e idiomático, y supersedería `compose.yaml:129` sin que el gate lo notara. Hipotético hoy — el overlay no declara `command:` — por eso queda diferido y no como parche. Trigger: el primer `command:` en `compose.dev.yaml`. Ref: `api/tests/Support/ScheduleConsumption.php:24`.

### DW-11: ReconcileErasedSubjectReferences: nobody chunks below the 65535 bound-parameter ceiling

origin: migrated from legacy ledger ("Deferred from: code review of g-3b-agendado-observable-reconciliador-referencias-borradas (2026-08-04)"), 2026-09-24
location: api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php:106, api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php
reason: existingIdsAmong() binds one parameter per id in a single statement; past ~65 536 distinct ids each daily tick fails loudly (PersonReferenceProbeFailed). Far-off scale and a loud failure, hence deferrable. Fix: chunk inside liveAmong().
status: open

**(api/Iam/Identity — escala) El techo de 65535 parámetros ligados no tiene dueño: nadie trocea.** `ReconcileErasedSubjectReferences:106` pasa la unión deduplicada de todos los ejes a `existingIdsAmong()` (vía `liveAmong()`, `:221`), que la expande a un parámetro ligado por id en una sola sentencia, y es el único llamador. Al cruzar ~65 536 ids distintos cada tick diario pasa a fallar y el CLI responde `INVALID` hasta que alguien implemente el troceo. Escala muy lejana, y el fallo es **ruidoso** (`PersonReferenceProbeFailed`) en vez de silencioso, que es lo que lo hace diferible. El docblock del puerto ya no afirma que un llamador trocee — decir la verdad sobre esto es lo que evita que el siguiente llamador dé el problema por resuelto. Fix: trocear en lotes por debajo del techo dentro de `liveAmong()`. Ref: `api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php:106`, `api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php`.

### DW-12: Six person-reference sources read their whole column with no LIMIT or keyset

origin: migrated from legacy ledger ("Deferred from: code review of g-3b-agendado-observable-reconciliador-referencias-borradas (2026-08-04)"), 2026-09-24
location: api/src/**/Dbal*PersonReferences.php (six PersonReferenceSource implementations)
reason: Each source SELECTs its entire table into PHP memory; DbalRecoverySecretPersonReferences even lacks DISTINCT. Degrades (slower daily task) rather than failing hard. Fix: keyset per user_id/resource_id, as DbalAuditLogPruner does. Trigger: the first reconciler tick that overruns its window.
status: open

**(api — escala del control detective) Las seis fuentes de referencias a persona leen su columna entera, sin `LIMIT` ni keyset.** Eran cinco cuando se escribió esta bala; **re-medido el 2026-09-20 son seis**, y la nueva es la que menos acota: `DbalRecoverySecretPersonReferences:48` (#877) hace `SELECT user_id FROM identity_recovery_secret ORDER BY user_id` **sin `DISTINCT`**, así que su resultado crece con las filas y no con las personas — el argumento «`DISTINCT` acota el resultado al número de personas» que sigue abajo no le aplica. Ninguna de las seis tiene `LIMIT`, `setMaxResults`, `OFFSET` ni continuación por keyset. Cada `PersonReferenceSource` hace un `SELECT DISTINCT` sobre toda su tabla y materializa el resultado en memoria PHP antes de que el reconciliador una los ejes: `DbalPersonResourceReferences:37` (`audit_log`, el más grande con diferencia — una fila por evento auditado, no por persona), `DbalMembershipPersonReferences:42`, `DbalSessionPersonReferences:47`, `DbalInvitationPersonReferences:41` y `DbalPasswordResetTokenPersonReferences:48`. `DISTINCT` acota el resultado al número de personas, no al de filas, así que el coste que crece sin techo es el del **scan**, no el del array. Es el mismo eje de escala que el techo de 65535 de la bala anterior y se cruzará antes, pero degrada (una tarea diaria más lenta) en vez de fallar en duro, que es lo que lo hace la menos urgente de las dos. Fix: keyset por `user_id`/`resource_id` en cada fuente, o `LIMIT` con continuación — lo mismo que ya practica `DbalAuditLogPruner`. Trigger: el primer tick del reconciliador que se salga de su ventana.

### DW-13: Erasure-chain prose (command class docblock, setHelp(), controller) still ungated

origin: migrated from legacy ledger ("Deferred from: code review of PR #618 — pase adversarial del eje de referencias a persona (2026-08-01)"), 2026-09-24
location: api/src/Iam/Identity/Application/FulfilIdentityErasureResult.php, api/src/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommand.php
reason: The consent prompt is now rendered from ERASED_CATEGORIES and test-pinned; what remains is free prose that a map cannot reach, deliberately left ungated because a prose gate is fragile — review is the control.
status: open

**(api/Iam/Identity — deriva por construcción) Los tres docblocks que describen la cadena de borrado siguen sin gate.** La mitad que importaba está cerrada: el **prompt de consentimiento** —lo único que el operador consiente— se renderiza hoy desde `FulfilIdentityErasureResult::ERASED_CATEGORIES`, un mapa que vive junto a las propiedades del constructor y que `FulfilIdentityErasureResultTest` mantiene igual a ellas en ambas direcciones, así que una décima propiedad no puede llegar sin su etiqueta. Esa mitad se cerró porque **la deriva que esta bala predijo ocurrió**: `recoverySecretsDeleted` entró en el DTO y en la línea de éxito del CLI mientras el prompt, el `setHelp()` y dos docblocks seguían nombrando ocho eslabones, o sea que el operador consentía a menos de lo que el borrado destruye — medido el 2026-09-20, nueve propiedades contra siete enunciadas. Lo que queda abierto es lo que un mapa no puede alcanzar: el docblock de la clase del comando, el texto de `setHelp()` y el del controlador siguen siendo prosa suelta, corregidos a mano en esa misma pasada. Un gate sobre prosa es frágil y no se construyó a propósito; el control es la revisión. Ref: `api/src/Iam/Identity/Application/FulfilIdentityErasureResult.php`, `api/src/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommand.php`.

### DW-14: event_store axis not covered by ReconcileErasedSubjectReferences — detective control missing, for measurable reasons

origin: migrated from legacy ledger ("Deferred from: G-5 — ids de persona fuera del `event_store` (2026-08-04)"), 2026-09-24
location: api/src/Shared/Event (event_store), api/.person-reference-policy
reason: Copying the audit_log resource-axis pattern fails: no registry-governed type discriminator and no resource_erased flag, so every correct erasure would report as divergence; adding the flag reopens D12. Trigger: event_store gains an erasure flag, or a person aggregate becomes truly event-sourced.
status: open

**(api/Shared/Event — control detective) El eje `event_store` NO entra en `ReconcileErasedSubjectReferences`, y la razón es medible, no un olvido.** El borrado preventivo está hecho; lo que falta es detectar una fila que se le escapara. Copiar el patrón del eje de recurso de `audit_log` **no funciona**, y le faltan las dos cosas que allí lo hacen significar algo: (1) un **discriminador de tipo gobernado por registro** — `DbalPersonResourceReferences` filtra `resource_type = 'User'` y ese vocabulario lo gobierna `.audit-resource-types`; en `event_store` un `SELECT DISTINCT aggregate_id` devuelve ids de banco, cuenta, invitación y token, ninguno resuelve a `identity_user`, y filtrar por `aggregate_type` es **enumerar**, la lista que ya falló dos veces; y (2) un flag **`resource_erased`**, sin el cual el pseudónimo que deja cada borrado **correcto** resuelve a ninguna identidad viva y el reconciliador **reportaría cada borrado que ha hecho como divergencia, para siempre**. Añadir ese flag es una columna nueva en un log append-only: schema listener + migración + camino de escritura + una **segunda** mutación sancionada, o sea reabrir D12.

**Además el hueco de «colaborador especial» tiene plaza única y declarada** (`api/.person-reference-policy`, bloque de puntos ciegos): un segundo eje sin entidad no se añade como fuente sin que el registro estrene antes un verbo para columnas sin entidad. Añadirlo a mano convertiría una excepción argumentada en un patrón.

**Y solo la mitad sería detectable.** El eje de `payload` es estructuralmente invisible a este control: preguntar «¿qué ids de persona guarda el payload?» exige saber bajo qué claves viajan —la enumeración que todo el diseño rechaza— y preguntar «¿qué UUID del payload no respalda ninguna identidad viva?» devuelve todo id de banco, invitación y organización. No hay tercera vía sin crosswalk, y D4 lo veta.

**Trigger:** el día que `event_store` gane un flag de borrado, o que un agregado-persona pase a ser event-sourced de verdad (el mismo trigger (a) que D12 ya declara). Un medio-control cuyo ruido de base es ~100 % deja de leerse, y un hueco nombrado es mejor que un control que nadie cree.

### DW-15: Erasure UPDATE on event_store is a sequential scan inside the erase transaction

origin: migrated from legacy ledger ("Deferred from: G-5 — ids de persona fuera del `event_store` (2026-08-04)"), 2026-09-24
location: api/src/Shared/Event/Infrastructure (DbalEventStoreSubjectAnonymiser)
reason: payload::text ILIKE '%uuid%' cannot use any index (would need GIN + pg_trgm) and runs holding the erasure chain's locks; free today at zero rows and no production. Measure before it matters; not a blocker.
status: open

**(api/Shared/Event — tiempo de lock) El `UPDATE` del borrado hace un scan secuencial y corre dentro de la transacción del erase.** El predicado `payload::text ILIKE '%uuid%'` no lo puede servir ninguno de los cinco índices de la tabla (haría falta GIN + `pg_trgm`), y el `OR` fuerza el scan igualmente. Hoy es gratis —la tabla está a cero filas y no hay producción— pero cuando tenga volumen ese scan corre sosteniendo los locks que la cadena ya tiene sobre `identity_user`, `audit_log`, sesiones, membresía e invitaciones. Medir antes de que importe; no es un blocker.

### DW-16: event_store stream UNIQUE enforces nothing (NULL tenant_id, NULLS DISTINCT); promised optimistic concurrency does not exist

origin: migrated from legacy ledger ("Deferred from: G-4a — fuga de `PasswordResetCompleted` en los transportes Messenger (2026-07-30)"), 2026-09-24
location: api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php:73-77
reason: tenant_id is always NULL so (NULL, x, 1) duplicates pass, the UniqueConstraintViolation catch is unreachable and EventStreamConcurrencyConflict is never thrown. Not fixed in G-4a because most publishers take no row lock, so NULLS NOT DISTINCT would turn silent races into 409s across ~15 paths; the owning story must decide whether stream version is a real invariant or informative.
status: open

**(api/Shared/Event — concurrencia) El UNIQUE de stream del `event_store` no impone nada, y el control de concurrencia optimista que su docblock promete no existe.** `event_store_stream_version_uniq` es `UNIQUE (tenant_id, aggregate_id, aggregate_version)` y `DbalEventStore:73` escribe `tenant_id` **siempre `NULL`**; PostgreSQL usa `NULLS DISTINCT` por defecto, así que dos filas `(NULL, x, 1)` entran las dos. **Verificado contra la BD viva** (`pg_indexes`, no el fichero de migración): el índice real **no** lleva la cláusula. Luego el `catch (UniqueConstraintViolationException)` de `DbalEventStore:77` es inalcanzable y `EventStreamConcurrencyConflict` no se lanza jamás — `git grep` lo encuentra **solo** en `DbalEventStore` (declaración y lanzamiento): ningún caso de uso lo captura ni reintenta.

**Por qué NO se arregló en G-4a, con la medición que lo decidió.** El pase adversarial de la historia (A-7) fijó el criterio por adelantado: *«enumera los caminos afectados; si aparece más de uno, saca el cambio de la PR»*. Enumeradas las **20** clases que publican eventos de dominio (`git grep -l 'eventBus->publish(' api/src`), **solo 4 toman lock de fila sobre el agregado por el que publican**: `AcceptInvitation`, `ChangeUserStatus`, `ChangeUserRoles` y `CompletePasswordReset` (que bloquea la fila del usuario y publica `PasswordResetCompleted`, cuyo `aggregateId` **es** ese usuario). `RequestPasswordReset` bloquea al usuario pero publica el evento del **token**, así que no cuenta. Los **16 restantes** —todo el ciclo de vida de `Bank` y `BankAccount`, los cuatro casos de `Iam/Invitation` salvo `AcceptInvitation`, `LoginAttemptRegistrar`, y las cuatro rutas de `Iam/Session`— no lo toman. Y el `INSERT` del `event_store` es DBAL crudo que corre en `publish()`, **antes** del flush del ORM, así que el `UPDATE` de la entidad no llega a serializarlos. Activar `NULLS NOT DISTINCT` convertiría en `EventStreamConcurrencyConflict` (409 crudo al cliente) carreras que hoy pasan en silencio, en 16 caminos a la vez y dentro de una historia GDPR. Muy por encima del umbral de A-7.

**Lo que esto significa, y es más que un obstáculo de migración.** La afirmación del docblock de `DbalEventStore:26-27` (la premisa del lock está en el comentario inline de `DbalEventStore:45-46`) («la UNIQUE del stream lo convierte en control de concurrencia optimista») es **falsa hoy**, y su premisa —«serializado por el lock de fila que la transacción de escritura ya mantiene sobre el agregado»— es falsa para 15 de 18 publicadores. Arreglar el índice sin arreglar esa premisa solo cambia *duplicados silenciosos* por *409 silenciosos*. La historia propia debe decidir las dos mitades: si el invariante de versión de stream se quiere de verdad (→ lock o reintento en cada publicador), o si `aggregate_version` es un número informativo (→ retirar la promesa del docblock y la excepción muerta).

**La migración en sí es barata y no bloquea:** `event_store` está hoy a 0 filas (la suite Behat trunca la tabla) y no existe entorno de producción, así que recrear el índice con `NULLS NOT DISTINCT` se aplica sobre tabla vacía. Contar duplicados antes, por si alguna base local acumuló filas: `SELECT count(*) FROM (SELECT 1 FROM event_store GROUP BY tenant_id, aggregate_id, aggregate_version HAVING count(*) > 1) d`. **Si sale > 0 no es un obstáculo: es la prueba de que el defecto ya se materializó.**

**El censo de publicadores está re-medido el 2026-09-20 y se mueve en las dos direcciones.** Son **24** clases que llaman `eventBus->publish(`, no 20: han entrado los cuatro casos de recuperación de credenciales de `Iam/Identity` (`ChangeMyPassword`, `MintRecoverySecret`, `RedeemRecoverySecret`, `RevokeRecoverySecret`), y los tres de recovery-secret **sí** toman el lock del agregado que publican. Conformes son **7** y no 4 — los tres anteriores más `ChangeUserStatus`, `ChangeUserRoles`, `ChangeMyPassword` y `CompletePasswordReset` — porque `LoginAttemptRegistrar` cruzó de lado al arreglarse el lost-update de `failedAttempts`: su camino de fallo resuelve hoy con `findByEmailForUpdate` dentro de `transactional()`, mientras su `clear()` sigue publicando sobre una lectura sin lock. Y hay **2 mixtos**, que es lo que hace cerrar la aritmética (7 + 2 + 15 = 24): `LoginAttemptRegistrar`, conforme en el camino de fallo y no en `clear()`, y `RevokeInvitation`, conforme al publicar la retirada del usuario y no al publicar la de la invitación. Quedan **15** no conformes; la enumeración de abajo sigue siendo el reparto correcto salvo por esos dos movimientos, y su propio «los **16 restantes**» lista quince — error que viene de origen.

**Un publicador más se ha movido al lado no conforme, y hay que heredarlo con la deuda (2026-08-07).** Al sacar el selector de aceptación del `event_store`, los seis eventos de `Iam.Invitation` pasaron a llevar el `invitedUserId` como `aggregateId`. `AcceptInvitation` era uno de los **4** que sí tomaban el lock del agregado por el que publicaban; ahora la clave que gobierna el versionado es el **usuario** mientras el lock que protege la publicación sigue siendo el de la fila **`Invitation`**. No cambia nada hoy —el UNIQUE es inerte— pero al activar `NULLS NOT DISTINCT` este camino pasa a necesitar lock o reintento como los otros 16, y además concentra una familia más de eventos sobre el eje del usuario, que ya compartían `Iam.Identity` y los dos revokes masivos de sesión.

### DW-17: Shared/Images: storing bytes is not transactional while writing the reference is

origin: migrated from legacy ledger ("Deferred from: pase adversarial sobre el ADR de contrato de conservación (2026-07-29)"), 2026-09-24
location: Shared/Images (UploadImage; consumers Bank.logoImageId, User.avatarImageId)
reason: The upload side-effect and the consumer's reference write can leave an image without reference or a reference without bytes; the first consuming story must decide who writes the reference and in which transaction (outbox pattern available). Verified open 2026-08-31 — no consumer exists yet, which is its trigger.
status: open

Section note: Accepted as real but outside the conservation-contract ADR (docs/adr/images-vs-documents-conservation-contract.md): decisions for the first story that writes the port, not for the conceptual document.

**(Shared/Images — borde transaccional) Guardar los bytes no es transaccional; escribir la referencia sí.** `UploadImage` produce un `ImageId` con un efecto lateral en almacenamiento, y el agregado consumidor (`Bank.logoImageId`, `User.avatarImageId`) lo persiste dentro de una transacción. Si falla la segunda mitad queda una imagen sin referencia; en el orden inverso, una referencia sin bytes. El repo ya resolvió esta forma con el outbox (`wrapInTransaction(save+publish)`, gate `make php.lint.event-bus`). Es *la* decisión de la primera historia de consumo. **Verificado contra el árbol el 2026-08-31 (retro de la épica Shared/Images): sigue abierto — no existe consumidor, que es su disparador.** Lo que la épica sí fijó, y que la primera historia de consumo hereda en vez de redecidir: el BORRADO ya está ordenado bytes-primero, con el estado «fila presente + objeto ausente» enumerado y probado, y su señal de ciclo de vida viaja por el outbox con `retry_strategy` declarada. Lo que sigue sin dueño es la mitad de ALTA: quién escribe la referencia y en qué transacción.

### DW-18: docs/rules/architecture.md embeddable rule has no live instance

origin: migrated from legacy ledger ("Deferred from: removal of the image upload surface (2026-07-23)"), 2026-09-24
location: docs/rules/architecture.md
reason: The #[ORM\Embeddable] rule uses hypothetical examples and git grep finds none in api/src, against the repo's no-speculative-docs density. Decide when the first real embeddable appears: anchor the example in it, or retire the rule.
status: open

**(docs/rules — ejemplo huérfano) La regla de embeddables no tiene ninguna instancia viva.** `docs/rules/architecture.md` describe el patrón `#[ORM\Embeddable]` con ejemplos hipotéticos: `git grep 'ORM\Embedd' -- api/src` no devuelve nada. La regla sigue siendo orientación legítima, pero contradice la densidad documental del repo (nada especulativo). Decidir al aparecer el primer embeddable real: anclar el ejemplo en él, o retirar la regla.

### DW-19: AuditWriteCaptureListener to-one association branch has no entity exercising it

origin: migrated from legacy ledger ("Deferred from: removal of the image upload surface (2026-07-23)"), 2026-09-24
location: api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php:145-152
reason: The last to-one association in the app (Bank → Media) was removed, so the branch's test was retired while the code stays as general capability. Trigger: the first aggregate with a mapped association — restore the test.
status: open

**(api/Shared/Audit — cobertura) La rama de asociaciones to-one de `AuditWriteCaptureListener` se queda sin ninguna entidad que la ejercite.** `AuditWriteCaptureListener.php:145-152` snapshotea una asociación to-one como el id referenciado; su único fixture posible era el `ManyToOne` de `Bank` hacia `Media`, que era **la última asociación to-one de toda la aplicación** (la regla per-agregado referencia por id, no por grafo — ver [`../../docs/adr/bank-bankaccount-modeling.md`](../../docs/adr/bank-bankaccount-modeling.md)). El código se mantiene porque es capacidad general del listener, pero su test se retiró al quedarse sin sujeto. Trigger: al aparecer el primer agregado con una asociación mapeada, restaurar el test. Ref: `api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php`.

### DW-20: Bulk session revocation publishes OtherSessionsRevoked/AllSessionsRevoked even when 0 rows are affected

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-11, revisión fresca)"), 2026-09-24 — merged with ("Deferred from: code review of u-3-cambio-de-estado-suspend-deactivate (2026-07-18)")
location: api/src/Iam/Session/Application/RevokeOtherSessions.php:38, api/src/Iam/Session/Application/RevokeAllSessions.php, api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php:129
reason: bulkRevokeActive ignores the rowcount and both use cases publish unconditionally; inert today (no consumer) but a 'your sessions were closed' reaction would fire on a no-op. Fix when wiring the consumer: return the affected-row count and publish only when > 0 — note status.feature currently pins the event for identities that never logged in and would need updating.
status: open

**(api/Iam/Session — eventos) La revocación bulk emite su evento aunque afecte 0 filas.** `DoctrineSessionRepository::bulkRevokeActive` ejecuta el `UPDATE` dirigido sin mirar el rowcount, y `RevokeOtherSessions`/`RevokeAllSessions` publican `OtherSessionsRevoked`/`AllSessionsRevoked` incondicionalmente en el outbox. Inerte hoy (R2 wire-on-consumer, Decisión H: sin reactor/consumidor); muerde cuando II-5 (force re-login everywhere) o una notificación reaccione — un «tus sesiones se cerraron» dispararía sobre un revoke de 0 filas (usuario sin sesiones activas, o «cerrar las demás» siendo la actual la única). Fix al cablear el consumidor: `bulkRevokeActive` devuelve el nº de filas afectadas y el handler solo publica si >0. Ref: `api/src/Iam/Session/Application/RevokeOtherSessions.php:38`, `api/src/Iam/Session/Application/RevokeAllSessions.php`, `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php:129`.

**(session · semántica · low) `AllSessionsRevoked` se emite aunque el objetivo tenga 0 sesiones, y el éxito lo fija duro.** `RevokeAllSessions::revoke` publica el evento incondicionalmente; los escenarios de éxito de `status.feature` asertan `1 event … "erpify.iam.session.all-revoked"` para identidades que «never log in». El significado del evento («se revocaron sesiones») diverge de lo ocurrido («nada que revocar»); si `RevokeAllSessions` se endurece a emitir-solo-si-revocó, este test de status (módulo ajeno) rompe. Pre-existente al módulo Session. Ref: `api/src/Iam/Session/Application/RevokeAllSessions.php`.

### DW-21: FindUserOrganizationId resolves the org with a non-deterministic findOneBy under multi-membership

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-11, revisión fresca)"), 2026-09-24
location: api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/DoctrineMembershipRepository.php:39
reason: Safe under today's one-membership-per-user UNIQUE index, but a future multi-org slice would bind sessions to an arbitrary org. Fix when multi-org lands: explicit ordering / deterministic admission org, or a uniqueness assertion.
status: open

**(api/Organization/Membership — determinismo) `FindUserOrganizationId` resuelve la org con `findOneBy` no determinista bajo multi-membership.** `DoctrineMembershipRepository::findByUserId` usa `findOneBy(['userId' => ...])` sin `ORDER BY` ni aserción de fila única; si un usuario llegara a tener >1 membership, la `organizationId` sellada en la sesión acuñada sería la fila que Postgres devuelva primero (arbitraria). Seguro bajo el invariante «una membership por usuario» de hoy (UNIQUE index `membership(user_id)`), pero un futuro slice multi-org ataría sesiones a una org aleatoria sin guardarraíl en este seam. Fix al entrar multi-org: orden explícito / selección de org de admisión determinista, o aserción de unicidad. Ref: `api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/DoctrineMembershipRepository.php:39`, `api/src/Organization/Membership/Application/FindUserOrganizationId.php`.

### DW-22: PWA collapses every non-401 /me failure (incl. 503) to 'unauthenticated'

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx:70
reason: Half (a) closed by 31423b68; (b) a store outage 503 still presents as 'session required' and bounces to /login. Deferred, not a bug: ratified Decision F/AC9; routing 503 to /maintenance would be a UX-resilience improvement to the spec.
status: open

**(pwa/access — resiliencia UX) El PWA colapsa todo fallo no-401 de `/me` a «unauthenticated».** `AuthProvider.resolveSession` captura red/cuerpo-malformado/**503** → `null` → `UNAUTHENTICATED`. (a) **cerrada** — medido el 2026-09-20, `LoginForm.tsx:54-60` guarda hoy el `await login()` antes del toast y del `router.push`, así que un blip tras el login deja al usuario en el formulario con un error reintentable en vez de anunciar «Signed in» y rebotar; el arreglo entró el 2026-08-31 (`31423b68`), dos meses después de escribirse esta bala. (b) En un outage de store, `/me` 503 se presenta como «sesión requerida» y manda a `/login` (que también 503); se descarta la distinción 503/401 que el backend construyó (existe `/maintenance`). **Diferido, NO bug:** es la **Decisión F/AC9 ratificada** (`/me` KO → unauthenticated → B1 `/login`, para evitar el spinner infinito). Enrutar 503→`/maintenance` y no rebotar en un blip post-login sería una *mejora* del spec (pase de resiliencia UX), no un defecto. Ref: `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx:70`, `pwa/src/app/(auth)/_components/LoginForm.tsx:53`.

### DW-23: MembershipNotFound mapped to SessionStoreUnavailable (503 store-unreachable)

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: api/src/Iam/Identity/Infrastructure/Security/SessionMintingSuccessListener.php:57
reason: The catch-all rethrows any minting failure as a store outage, including a permanent admission data gap, which mislabels the cause and floods Sentry. Subsumed by O3 (Sentry dedup/fingerprint); consider a distinct marker for admission data gaps vs store down.
status: open

**(api/Iam/Identity — observabilidad) `MembershipNotFound` mapeado a `SessionStoreUnavailable` (503 «store-unreachable»).** El `catch (Throwable)` del `SessionMintingSuccessListener` rethrowea `SessionStoreUnavailable::storeUnreachable($throwable)` para cualquier fallo del acuñado, incluido `FindUserOrganizationId` lanzando `MembershipNotFound` para un usuario válidamente autenticado sin membership. G1 ratificó 503 fail-closed, pero el marker miente semánticamente (no es un outage de store) y floodea Sentry ante un gap de datos permanente que un reintento del usuario no resuelve. Subsumido por O3 (dedup/fingerprint Sentry pre-prod); considerar un marker/observabilidad distinta para «gap de datos de admisión» vs «store caído». Ref: `api/src/Iam/Identity/Infrastructure/Security/SessionMintingSuccessListener.php:57`.

### DW-24: Orphaned ACTIVE iam_session rows (post-commit correlation failure, re-login without revoke)

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: api/src/Iam/Session/Application/StartSession.php:52
reason: Conscious trade-off documented in StartSession; PruneRetiredSessions now bounds such rows at ~97 days after login, so they are bounded, not avoided, and show as a 'ghost device' for the whole ACTIVE window.
status: open

**(api/Iam/Session — higiene de datos) Filas `iam_session` `ACTIVE` huérfanas.** (a) `StartSession` escribe la correlación (`currentSession->set()`) DESPUÉS del commit del row+outbox; si `set()` lanza (p. ej. `getSession()` sin sesión) queda una fila `ACTIVE` sin `iamSessionId` que la referencie. (b) Un re-login con sesión viva acuña una fila nueva y sobrescribe `iamSessionId` sin revocar la previa → la anterior queda `ACTIVE` correlación-huérfana. Tradeoff consciente (documentado en el docblock de `StartSession`: correlación post-commit para no dejar un `iamSessionId` colgando), baja probabilidad; aparece como «dispositivo fantasma» en «mis sesiones». **La poda que esta bala esperaba ya no es futuro** (medido el 2026-09-20): `PruneRetiredSessions` existe, con `REVOKED_RETENTION` de `P30D` y `EXPIRED_RETENTION` de `P90D`, y su segundo disyuntor (`expiresAt < :expiredBefore`, que ignora el estado) **sí** barre una fila ACTIVE huérfana — pero a los ~97 días del login que la acuñó (7d de TTL + 90d de ventana). O sea que la fila está **acotada, no evitada**, y es visible como dispositivo fantasma durante toda la ventana ACTIVE. Ref: `api/src/Iam/Session/Application/StartSession.php:52`.

### DW-25: Two clocks per aggregate (injected Clock vs static SystemClock) — suite pin widened the divergence; open product-owner decision

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: api/src/Shared (AggregateRoot, SystemClock, DomainEvent:38), api/src/Iam/Session
reason: expiresAt comes from the injected Clock while createdAt/updatedAt and mutator stamps read static SystemClock::now(); since #929 a test session can be created in 2050 and expire in 2026, green. Production unaffected (SystemClockInitializer). Two readings disagree (pass the instant and delete SystemClock vs seed tests from the same clock in 5 files) and the decision is not the implementer's.
status: open

**(api — testabilidad) Dos relojes en cada agregado, y el pin de la suite AGRANDÓ la divergencia en vez de cerrarla. Decisión abierta, del product owner.** `expiresAt` se computa del `Clock` inyectado (vía el caso de uso), mientras `createdAt`/`updatedAt` (`AggregateRoot::__construct`) y los sellos de los mutadores leen el `SystemClock::now()` estático. Esta bala decía «bajo un `FixedClock` en test los timestamps quedan mutuamente inconsistentes» y estimaba la incoherencia en microsegundos; desde #929 son **24 años**: la suite fija el ambiental en `2050-06-15` y `StartSessionTest:26,35` inyecta un `FixedClock` en `2026-07-10`, así que la sesión sale con `createdAt = 2050` y `expiresAt = 2026` — una caducidad anterior a su propia creación, en verde. El salto no es accidente: #929 eligió ese año a propósito, buscando uno que el árbol no usara, y el docblock de `FreezeSystemClockExtension` ya declara que el pin agranda esta divergencia.

**Producción NO está afectada**, y eso está medido: `SystemClockInitializer:39-44` copia el `Clock` del contenedor sobre el ambiental en `kernel.request`, `console.command` y cada mensaje de worker, que son los tres puntos de entrada.

**Lo que esta bala daba por sentado es falso en tres puntos** (medido el 2026-09-20). `Clock` **no** es una dependencia de framework: el ADR lo bendice por su nombre (`docs/adr/external-dependencies-in-domain.md:36`), así que inyectarlo no es la violación que «barrido transversal fuera de scope» insinuaba. Inyectarlo por constructor arreglaría **1 de 16** lecturas de `SystemClock::now()` en `api/src`: **14** son mutadores sobre entidades ya hidratadas y Doctrine no re-ejecuta el constructor, y la decimosexta es `Image`, que ni siquiera extiende `AggregateRoot`. Y hay una **tercera** fuente que ninguna de las dos alcanza: `DomainEvent:38` sella `$occurredOn ?? new DateTimeImmutable()`, desnudo, en `Domain/`.

**Dos lecturas independientes, en desacuerdo, y la decisión no la toma el implementador.** Una propone que el agregado reciba el **instante** (no el reloj) como parámetro de la operación y borrar `SystemClock` entero — completa un patrón que el árbol ya sostiene en 15 firmas de dominio y deja menos código, pero choca con **65 bloques `__factory`** de Alice que llaman a las factorías posicionalmente desde YAML. La otra propone no tocar producción y aplicar en 5 ficheros la regla que `docs/rules/testing.md:83` ya enuncia (sembrar del mismo reloj que lee el sujeto), sobre la base de que hoy **ninguna aserción viva** cambia de signo por la divergencia — una trampa armada, no un fallo.

Dato que pesa en cualquiera de las dos: `Timestamped::setCreatedAt`/`setUpdatedAt` tienen **0 llamantes en `api/src` y 23 en `api/tests`**. Existen sólo para esquivar el sello ambiental, y son exactamente la forma que el checklist de seguridad prohíbe (setters de campos de auditoría en la entidad).

### DW-26: SessionAdmissionGate matcher (/api/) narrower than the firewall access_control (^/api)

origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
location: api/src/Iam/Session/Infrastructure/Security/SessionAdmissionGate.php:57, api/src/Shared/Http/Infrastructure/ApiRequestMatcher.php
reason: A future route at exactly /api or /apiX would be firewall-authenticated but not session-gated; not exploitable today (everything outside /api/v1/ 404s). Both boundaries should derive from one definition.
status: open

**(api/Iam/Session — defensa en profundidad) Matcher del gate `/api/` más estrecho que el firewall `^/api`.** `SessionAdmissionGate` gatea vía `ApiRequestMatcher` (prefijo literal `/api/`), mientras `access_control` exige `IS_AUTHENTICATED_FULLY` en el regex `^/api` (sin barra). Una ruta futura montada en `/api` exacto o `/apiX...` quedaría firewall-autenticada pero SIN gate de sesión (una sesión revocada-pero-con-cookie pasaría). No explotable hoy (ninguna ruta fuera de `/api/v1/` → 404), pero ambos límites deberían derivar de una única definición. Ref: `api/src/Iam/Session/Infrastructure/Security/SessionAdmissionGate.php:57`, `api/src/Shared/Http/Infrastructure/ApiRequestMatcher.php`.

### DW-27: Keyset cursor base-query identity coupled to Doctrine's getDQL() formatting

origin: migrated from legacy ledger ("Deferred from: code review of rm-2-cierre-fingerprint-keyset-437 (2026-07-07)"), 2026-09-24
location: api/src/Shared/Search/Infrastructure/Persistence/Doctrine/DoctrineSearchEngine.php (baseQueryIdentity)
reason: Byte-for-byte DQL determinism between mint and follow-up is an unguarded precondition; no trigger today (4 fixed-shape consumers). Risks: a consumer with auto-generated params or request-conditional JOIN/WHERE (spurious 422 on page 2), or a Doctrine upgrade re-minting all fingerprints. Hardening: a format-independent identity or a request-invariance guardrail.
status: open

**(Shared/Search — hardening) La identidad base-query del cursor está acoplada al formato de `getDQL()` de Doctrine.** `DoctrineSearchEngine::baseQueryIdentity()` sella `$queryBuilder->getDQL()` en el fingerprint; su determinismo byte-a-byte entre mint y follow-up es precondición **no guardada** (mitigada sólo con nota en el docblock). Dos ejes de riesgo, sin trigger hoy (los **4** consumidores son fixed-shape con placeholders nombrados — re-medidos uno a uno el 2026-09-20, y son cuatro consumidores del motor, no los 6 que esta bala registró el 2026-09-03 (el árbol tiene cinco `->paginate(`; el quinto es `AuditTimelineKeysetPaginator`, que es el paginador DBAL hermano y acuña su fingerprint de los criterios, no del texto DQL, así que el eje de riesgo de `baseQueryIdentity()` no le aplica): `git log -S` no encuentra ningún consumidor borrado desde entonces, así que el 6 nunca fue. `doctrine/orm` está bloqueado en 3.6.8, el dato que al eje (b) le faltaba: ninguno usa parámetro auto-generado ni JOIN/WHERE condicional por request, y el único `join` del árbol es incondicional): (a) un consumidor futuro con parámetro auto-generado (`?1`, `expr()->in()`) o JOIN/WHERE condicional por request → 422 espurio en la página 2; (b) un upgrade de Doctrine que cambie la generación de DQL re-acuña todos los fingerprints a la vez (acotado — cursores efímeros). Endurecimiento posible: derivar una identidad base-query estable e independiente del formato (p. ej. parts normalizados o un token de identidad explícito), o un guardrail que asegure base-query request-invariante. Ref: `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/DoctrineSearchEngine.php` (`baseQueryIdentity`).

### DW-28: Should User store Email as a Doctrine embeddable (VO as persisted state)?

origin: migrated from legacy ledger ("Deferred from: code review of af-1-1-user-aggregate-backoffice-identity-persistencia (2026-07-02)"), 2026-09-24
location: api/src/Iam/Identity/Domain/Entity/User.php
reason: Today email is a scalar string with a transient Email::from() in the constructor; mapping #[ORM\Embedded] would make the VO the state. Open design question left by the architect; blocks nothing — revisit if the identity model grows.
status: open

**(auth-foundation / futuro) ¿`User` guarda `Email` como Doctrine embeddable (el VO = estado persistido)?** Hoy `email` es `string` escalar + `Email::from()->toString()` transitorio en el constructor. Alternativa: mapear `Email` como `#[ORM\Embedded]` para que el VO sea el estado (embeddable + migración + API de la entidad). Pregunta mayor que Winston dejó anotada — NO bloquea nada, revisar si el modelo de identidad crece. Ref: `api/src/Iam/Identity/Domain/Entity/User.php`.

### DW-29: Durability of the audit security branch (write-before-send) against a caller's transaction

origin: migrated from legacy ledger ("Deferred from: code review of stories 1.3 & 1.4 (2026-06-24)"), 2026-09-24
location: api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php (writeSecurity), DbalAuditLogWriter.php
reason: The synchronous security INSERT uses the shared DBAL connection without its own transaction, so a caller's rollback would revert the denial row, weakening ADR-D3. Low probability; fix the assumption (no business transaction, or a separately committed write). Extends the 're-review the security failure contract' item.
status: open

**(Epic 2 / Story 2.3) Durabilidad de la rama `security` (write-before-send) frente a una transacción del llamador.** El `INSERT` síncrono de `security` va por la `Connection` DBAL compartida sin transacción propia; si el llamador de Epic 2 abre una transacción de negocio que luego hace rollback, la fila de la denegación se revierte con ella, debilitando "una denegación nunca se pierde" (ADR-D3). Baja probabilidad (un `AccessDeniedException` rara vez tiene transacción de negocio abierta), pero al cablear 2.3 fijar la asunción: o no hay transacción de negocio al escribir la denegación, o la escritura `security` usa una conexión/transacción que commitea aparte. Extiende el item "re-revisar el contrato de fallo de `security` en 2.3". Ref: `api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php` (writeSecurity), `DbalAuditLogWriter.php`.

### DW-30: Residuals of the metadata-to-object coercion: historical audit_log rows stay [] and event_store.metadata still writes []

origin: migrated from legacy ledger ("Deferred from: code review of the metadata-shape fix (2026-09-22)"), 2026-09-24
location: api/src/Shared/Audit (audit_log writer), api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php:73
reason: (a) No backfill of historical [] rows — that would be a fourth sanctioned mutation on an append-only table, not the implementer's decision; object-shaped queries must keep bounding by ::text or jsonb_typeof. (b) event_store.metadata has the same defect and the ADR describes a default the migration lacks. Trigger for (b): the first query treating event_store.metadata as an object.
status: open

**Dos residuos de la coacción de `metadata` a objeto, ninguno de los dos cerrado por ella.** El escritor de `audit_log` ya coacciona el nivel superior, así que **desde ese commit** toda fila nueva guarda `{}`; lo que queda abierto es lo de antes y lo de al lado. (a) **Las filas históricas siguen siendo `[]`**, y no hay backfill: hacerlo sería una cuarta mutación sancionada sobre una tabla append-only, decisión que no es del implementador. Mientras tanto la columna tiene dos formas en cualquier base desplegada, `jsonb_each` aborta sobre las viejas, y la mezcla dura años porque el tier `change` tiene un suelo de conservación de 5 años (`AuditRetentionPolicy::COMPLIANCE_RETENTION_FLOOR`). Cualquier consulta que trate `metadata` como objeto debe seguir acotando por `::text` o filtrar por `jsonb_typeof`. (b) **`event_store.metadata` tiene el defecto idéntico y sigue escribiéndose `[]`** (`DbalEventStore:73`); no rompe nada medible hoy porque su anonimizador opera por `regexp_replace` sobre `::text`, insensible a la forma, pero el ADR ya describe esa columna como `NOT NULL DEFAULT '{}'` mientras la migración no declara default alguno, o sea que el documento describe una forma que el escritor no produce. Trigger de (b): la primera consulta que trate `event_store.metadata` como objeto.

### DW-31: Nested empty 'changes' still travels as a JSON array and the PWA guard rejects it

origin: migrated from legacy ledger ("Deferred from: code review of the metadata-shape fix (2026-09-22)"), 2026-09-24
location: api/src/Shared/Audit/Application/AuditChangeDiff.php, api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php
reason: AuditChangeDiff::of() can return ['changes' => []] and the listener writes it unguarded, so isAuditChanges([]) fails into a mute drawer. Not measured that Doctrine delivers such a changeset — a code-constructible branch, not an observed defect. Cheap fix: a non-empty guard in the listener or sealing changes as an object.
status: open

**(pwa/backoffice/audit — contrato de cable) Un `changes` vacío anidado sigue viajando como array, y el guard lo rechaza igual.** La mitad de raíz está cerrada — el recurso de detalle expresa hoy `metadata` como `ArrayObject` y el normalizador conserva el objeto vacío, medido contra el stack vivo: `{}` en el cable para una fila sin metadata. Lo que la coacción de raíz no alcanza es una clave anidada: `AuditChangeDiff::of()` devuelve `['changes' => []]` cuando el changeset viene vacío o cuando se descartan todas sus entradas (los tres `continue` del recorrido), y `AuditWriteCaptureListener::capture()` escribe la entrada sin guarda de no-vacío, así que la fila se almacena y se sirve como `{"changes": [], "operation": "UPDATED"}` — y `isAuditChanges([])` cae en el mismo `isObjectRecord` que rechaza arrays, con el mismo drawer mudo. **No está medido que Doctrine llegue a entregar un changeset vacío o íntegramente descartable a esa ruta** (`getScheduledEntityUpdates()` normalmente implica changeset no vacío), así que es una rama construible por el código y no un defecto observado. Arreglo barato si se acepta: una guarda de no-vacío en el listener, o sellar `changes` como objeto. Ref: `api/src/Shared/Audit/Application/AuditChangeDiff.php`, `api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php`.

### DW-32: audit metadata PII-free rule (FR12) has no structural enforcement

origin: migrated from legacy ledger ("Deferred from: code review of Epic 1 audit specs (2026-06-23)"), 2026-09-24
location: api/src/Shared/Audit (log() callsites, AuditEntryFactory::create), api/.audit-metadata-keys (absent)
reason: Non-empty metadata is passed at 12 callsites across 4 contexts, plus a 13th producer via AuditWriteCaptureListener that bypasses log(); the revisit trigger has long fired. Closing it is a registry + engine + gate (api/.audit-metadata-keys, like .audit-resource-types) — size L, its own PR, kept out of the 2026-08-28 sweep by explicit product-owner decision.
status: open

**(Epic 2+) `metadata` PII-free sin enforcement estructural.** FR12 ("nunca PII/payload en `metadata`") es hoy solo prosa; un dev puede pasar `metadata: ['iban' => …]` y compila/pasa gates. Aceptable en Epic 1 (un consumidor, `metadata` vacío). Revisit trigger: al entrar el 2.º/3.er consumidor con `metadata` no vacío, añadir un guardrail testable (test de arquitectura que escanee los callsites de `log(...)`, o una allowlist de claves de `metadata` por acción).

**Re-medido el 2026-09-20: el conteo NO ha crecido** — siguen siendo 12 puntos de llamada, 11 ficheros y 4 contextos, y `api/.audit-metadata-keys` sigue sin existir. Lo que la medición de 2026-08-28 no vio es un **decimotercer productor que no pasa por `log()`**: el tier de cambios escribe `metadata` a través de `AuditEntryFactory::create()` después de que `AuditWriteCaptureListener:88` le inyecte `$diff['operation']`, así que un gate que barra sólo los callsites de `log(...)` nacería ciego a él.

**Disparador ya cumplido (medido el 2026-08-28).** El `metadata` no vacío se pasa hoy en **12 puntos de llamada**, en 11 ficheros y **4 contextos acotados** (`Backoffice/Audit`, `Backoffice/BankAccount`, `Iam/Identity`, `Shared/Audit`); en tres de ellos el conjunto de claves se calcula fuera del punto de llamada — `AuditTrailReadAuditListener::metadataFor()`, `StoredIdentityDrift::toAuditMetadata()` y el ternario `lockedUntil` de `RecordLockoutNoticeAuditBestEffort` — así que ahí las claves no son ni legibles en el callsite. El «Revisit trigger» de arriba dejó de estar pendiente hace tiempo. Lo que cierra la bala es un registro `api/.audit-metadata-keys` + motor + gate, al modo de `.audit-resource-types`: talla L y PR propia, fuera del barrido de 2026-08-28 por decisión explícita del product owner.

### DW-33: audit_log.metadata jsonb has no size bound

origin: migrated from legacy ledger ("Deferred from: code review of Epic 1 audit specs (2026-06-23)"), 2026-09-24
location: api/src/Shared/Audit (writer, audit_log schema)
reason: Unlike user_agent VARCHAR(512) or action VARCHAR(100), metadata JSONB is unbounded — an inflation/PII vector once a generic producer fills it. AccessLogAuditListener exists but passes default [] metadata, so the trigger has not fired. Consider a writer-side size bound or an AC forbidding raw query/body dumps.
status: open

**(Epic 2) `metadata` jsonb sin tope de tamaño.** A diferencia de `user_agent` (`VARCHAR(512)`) o `action` (`VARCHAR(100)`), `metadata` jsonb no tiene cota → vector de inflado PII/almacenamiento cuando el access-log genérico de Epic 2 lo pueble automáticamente. **Ese access-log ya no es futuro y el disparador sigue sin saltar** (medido el 2026-09-20): `AccessLogAuditListener` existe desde el 2026-06-25 y es el «access-log genérico» que esta bala esperaba, pero emite `log($action, $level, $resource)` con tres argumentos, así que `metadata` toma el default `[]` y la fila guarda un jsonb vacío. La cota sigue sin existir (`metadata JSONB NOT NULL` sin opciones, frente a `user_agent VARCHAR(512)` y `action VARCHAR(100)`), y el escritor tampoco la impone. Considerar una cota de tamaño en el escritor o un AC de Epic 2 que prohíba volcar `query`/`body` crudos.

### DW-34: Failure contract of the audit security branch never re-reviewed against its live producer

origin: migrated from legacy ledger ("Deferred from: code review of Epic 1 audit specs (2026-06-23)"), 2026-09-24
location: api/src/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php:64
reason: The awaited condition is met — AccessDeniedAuditListener emits ACCESS_DENIED at AuditLevel::SECURITY on real /api requests — but D1's 'propagate on persistence failure' contract was never checked against that live use case.
status: open

**(Epic 2) El contrato de fallo de la rama `security` sigue sin re-revisarse, y su disparador ya saltó.** La condición que este item esperaba —un productor real en vez de fixtures sintéticos— se cumple: `AccessDeniedAuditListener:64` emite `ACCESS_DENIED` con `AuditLevel::SECURITY` sobre peticiones `/api` reales. Lo que queda pendiente es la revisión que eso habilitaba: el contrato de fallo decidido en D1 (propagar cuando falle la persistencia) nunca se ha contrastado contra ese caso de uso vivo. Ref: `api/src/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php:64`.

### DW-35: PWA ui → @/context ban has no allowTypeImports carve-out (latent)

origin: migrated from legacy ledger ("Deferred from: code review of spec-pwa-components-boundary-remediation (2026-06-21)"), 2026-09-24
location: pwa/eslint.config.mjs
reason: Hardening only: a future ui primitive needing a @/context/shared type would be blocked; hypothetical with today's cn/ui/erpify-only layer. The other half (cn.ts uncovered) is closed via dependency-cruiser components-root-is-foundational and ui-is-foundational (measured 2026-09-03).
status: open

PWA component-layer gate — latent coverage gap, hardening only (no current break): the `ui → @/context` ban in `pwa/eslint.config.mjs` has no `allowTypeImports` carve-out, so a future `ui` primitive needing a `@/context/shared/**` *type* would be blocked. Still hypothetical with today's `cn`/`ui`/`erpify`-only `@/components` layer. **La otra mitad de esta bala está cerrada (medido 2026-09-03):** decía que `cn.ts` no lo cubría ninguna regla, y hoy sí — `pwa/.dependency-cruiser.cjs` declara `components-root-is-foundational` sobre `^src/components/` (hoy `cn.ts`, que ningún glob `files` de ESLint alcanza) y `ui-is-foundational` prohíbe que `ui/` llegue a `^src/context/`, `^src/app/` o `^src/components/erpify/` **por cualquier número de saltos**, así que el escape `ui → hermano → context` ya no resuelve.

### DW-36: PermissionVoter::supports() is shape-only and relies on two external assumptions

origin: migrated from legacy ledger ("Deferred from: code review of rm-1-nucleo-autorizacion-voter-puerto-politica (2026-07-06)"), 2026-09-24
location: api/src (PermissionVoter), config security access_decision_manager
reason: Correct only while the decision strategy stays affirmative and no dotted non-permission attribute (e.g. feature.flag) is introduced; safe today and mandated by AC5. Future hardening: a namespaced positive marker or resource/action registry plus a test pinning the strategy assumption.
status: open

**`PermissionVoter::supports()` es sólo-forma (`<resource>.<action>`), así que su corrección de composición depende de dos supuestos externos.** (a) La estrategia del `access_decision_manager` sigue siendo `affirmative` (bajo `unanimous`/`consensus` un DENY del PermissionVoter sobre un atributo no-permiso participaría mal); (b) nunca se introduce un atributo con-punto que NO sea un permiso (p. ej. `feature.flag`) — el voter lo reclamaría y votaría DENY. Hoy es seguro (el vocabulario nativo de Symyfony —`ROLE_*`/`IS_AUTHENTICATED_*`/`PUBLIC_ACCESS`— no lleva punto, y la estrategia es `affirmative` por defecto), y AC5 manda explícitamente el `supports()` basado en forma. Endurecimiento futuro (marcador positivo namespaced o registry de recursos/acciones + test que fije el supuesto de estrategia) = roadmap RM-2+, fuera del alcance aditivo de RM-1.

### DW-37: Invitation accept: no negative CSRF test (403 without mutation)

origin: migrated from legacy ledger ("Deferred from: code review of ii-4-invitation-accept-pantallas-acceso (2026-07-13)"), 2026-09-24
location: api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationController.php:34
reason: With check_header off (Decision C) stateless CSRF is satisfied by same-origin, which AcceptInvitationOriginListener already enforces, so no input fails CSRF but passes Origin and a negative test cannot fail. Writable once the double-submit check_header follow-up is enabled.
status: open

**(Iam/Invitation · test seguridad · con el follow-up CSRF `check_header`) Sin test negativo de CSRF (403 sin mutación).** El accept exige `#[IsCsrfTokenValid('invitation_accept')]`, pero con `check_header` **off** (diferido por Decisión C) el CSRF stateless se satisface por same-origin — que `AcceptInvitationOriginListener` (prio 9) ya impone antes del firewall. No existe un input que CSRF rechace y Origin no, así que un test funcional negativo no puede fallar tal como está cableado. Escribible cuando se habilite el double-submit `check_header` (follow-up de consolidación `LoginOriginListener`). Ref: `api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationController.php:34`.

### DW-38: Invitation accept: no assertion that the session id is regenerated (anti-fixation, NFR3)

origin: migrated from legacy ledger ("Deferred from: code review of ii-4-invitation-accept-pantallas-acceso (2026-07-13)"), 2026-09-24
location: api/tests/Functional/Iam/Invitation/InvitationAcceptFunctionalTest.php
reason: Security::login triggers the native migrate(true); the gap is a regression guard should a future change abandon that path. A robust assertion requires planting the session cookie (fragile with the test client) — bundle with the check_header follow-up.
status: open

**(Iam/Invitation · test seguridad · anti-fixation) Sin assert de que el id de sesión se regenera (NFR3).** `Security::login` dispara el `migrate(true)` nativo; el funcional ya aserta «exactamente 1 sesión acuñada» pero no planta un id de sesión previo y compara pre/post. Garantía provista por el framework; el gap es un guard de regresión si un futuro cambio abandona el camino `Security::login`. Una aserción funcional robusta requiere plantar la cookie de sesión (frágil con el test client) → bundle con el follow-up `check_header`. Ref: `api/tests/Functional/Iam/Invitation/InvitationAcceptFunctionalTest.php`.

### DW-39: Public Navbar not auth-aware: authenticated users still see 'Sign in' (and 'Backoffice')

origin: migrated from legacy ledger ("Deferred from: code review of landing-login-cta (2026-07-15)"), 2026-09-24
location: pwa/src/app/_components/Navbar.tsx:54-69
reason: Neither CTA consults the session, so 'Sign in' is meaningless for a logged-in user. Out of the landing slice's scope; making the access cluster session-aware (useSession()) is a product + UX decision (consult the access spine).
status: open

**(pwa/frontoffice · UX · low) El `<Navbar>` público no es auth-aware: un usuario ya autenticado que visita `/` o `/status` sigue viendo el CTA «Sign in» (y «Backoffice»).** El nuevo enlace «Sign in» → `/login` se renderiza incondicionalmente, igual que el botón «Backoffice» preexistente — ninguno de los dos consulta la sesión. Para un usuario logueado, «Sign in» es un CTA sin sentido (aterriza en el formulario de login en vez de entrar al ERP). Fuera de alcance de este slice (que solo añade el punto de entrada de acceso); el redirect/consciencia de sesión ya estaba listado como follow-up. Follow-up: hacer el cluster de acceso del navbar consciente de la sesión (ocultar «Sign in» y/o mostrar «Entrar»/menú de usuario cuando `useSession()` está autenticado), decisión de producto + UX (consultar espina de acceso de Sally). Ref: `pwa/src/app/_components/Navbar.tsx:54-69`.

### DW-40: AC5 permission-completeness sweep has latent blind spots (Expression, access_control, IsGranted subclasses)

origin: migrated from legacy ledger ("Deferred from: code review of u-1-me-deriva-permisos-gateo-can (2026-07-17)"), 2026-09-24
location: api/tests (PermissionCatalogCoversEveryGatedRouteTest)
reason: The sweep only reflects string-literal/constant #[IsGranted]; it cannot see #[IsGranted(new Expression(...))], security.yaml access_control rules, or IsGranted subclasses. All latent (0 in the tree). Harden when the first Expression or access_control gate lands.
status: open

**AC5: el barrido de completitud de permisos tiene puntos ciegos latentes.** `PermissionCatalogCoversEveryGatedRouteTest` solo refleja atributos `#[IsGranted]` string-literal/constante. No ve: `#[IsGranted(new Expression(...))]` (Symfony expression-language está instalado), reglas `access_control` de `security.yaml`, ni subclases de `IsGranted` (usa match exacto, e `IsGranted` no es `final`). Todos latentes hoy (0 en el árbol). Reforzar el barrido cuando aterrice el primer gate por Expression o por `access_control`.

### DW-41: Behat exact query budget 27 couples the invitation feature to cross-cutting internals

origin: migrated from legacy ledger ("Deferred from: code review of u-2-invitar-alta-invitacion (2026-07-18)"), 2026-09-24
location: api/features/backoffice/identity/invitation_create.feature:47
reason: The exact count folds identity, membership, invitation, event_store, outbox, CDC audit row and BEGIN/COMMIT, so unrelated changes fail an invitation scenario. Accepted house pattern; a repo-wide '<= N' or table-scoped budget would be less false-positive.
status: open

**(behat · brittleness · low) El presupuesto de query exacto `27` acopla la feature de invitación a internals transversales.** `And 27 requests got executed only for doctrine connection "default"` pliega identity+membership+invitation+event_store+outbox+fila CDC de auditoría+BEGIN/COMMIT; cualquier cambio ajeno al listener de auditoría/outbox/event-store desplaza el número y hace fallar un escenario de *invitación* por una razón no-conductual. Patrón aceptado de la casa (`behat-query-budget-transaction-overhead`); si se quiere endurecer, un `<= N` (o acotado a las tablas de la invitación) repo-wide sería menos falso-positivo. Ref: `api/features/backoffice/identity/invitation_create.feature:47`.

### DW-42: Failed-attempt lockout is a DoS vector against privileged identities — MFA/privileged thresholds still open

origin: migrated from legacy ledger ("Deferred from: code review of u-4-edicion-de-roles-candidato (2026-07-19)"), 2026-09-24
location: api/src/Iam/Identity/Application/LoginAttemptRegistrar.php, api/src/Iam/Identity/Domain/Entity/User.php (lockedUntil)
reason: Per-email lockout lets anyone knowing an admin's email lock them out. Adaptive throttling was rejected with argument (ADR administrative-recovery-channel) and the recovery path landed under #602; mandatory MFA plus privileged thresholds/alerts remains open — review before production. Filtering locked_until in DoctrineActiveAdministratorDirectory is closed and rejected; do not re-propose.
status: open

**(api/Iam/Identity · política de lockout · revisar antes de producción) El lockout por intentos fallidos es un vector de DoS contra identidades privilegiadas.** `LoginAttemptRegistrar::recordFailure` bloquea **por email** (`MAX_FAILED_ATTEMPTS=10`, `PT15M`), así que cualquiera que conozca el email de un admin puede dejarlo sin login; `login_throttling: 5` lo ralentiza a ~2 min desde una IP, pero la dimensión por-IP se sortea rotando origen. **De sus tres direcciones quedan viva una y media** (medido el 2026-09-20, y la mitigación que esta bala declaraba está obsoleta: el canal de recuperación ya no exige acceso al servidor). *Throttling adaptativo por origen/reputación:* **descartado con argumento** en `docs/adr/administrative-recovery-channel.md:79-81` — re-deriva `login_throttling`, abandona el único trabajo documentado del contador persistente, y su clave la controla el atacante y le sale gratis rotarla. *Vía de recuperación documentada:* **aterrizada** bajo #602 — la palanca administrativa de desbloqueo (`UserUnlockController`, `users.unlock`), el canal de secreto de recuperación con su ruta pública `recovery/redeem`, y la guía de operador en `docs/deployment-guide.md`. *MFA obligatoria más umbrales y alertas propias para identidades privilegiadas:* **sigue abierta y #602 no la cubre** — no hay implementación alguna, sólo dos menciones prospectivas en `docs/rules/security.md:51` y `docs/adr/auth-rbac-subsystem.md:23`. Ése es el residuo de esta bala; el resto está cerrado. **Cerrado y descartado:** filtrar `locked_until` en `DoctrineActiveAdministratorDirectory` — acoplaría estado de *autenticación* al invariante de *continuidad administrativa*, convirtiendo un fallo coincidental y autocurativo en una congelación de toda la gestión de identidades disparable por un anónimo (cadena tráfico de red → estado de auth → decisión de autorización). No re-proponer. Ref: `api/src/Iam/Identity/Application/LoginAttemptRegistrar.php`, `api/src/Iam/Identity/Domain/Entity/User.php` (`lockedUntil`).

### DW-43: Realtime feed abandoned after terminal denial is never re-armed, even when permission returns

origin: migrated from legacy ledger ("Deferred from: code review of spec-realtime-authorize-terminal-denial (2026-07-22)"), 2026-09-24
location: pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts (handleStreamError)
reason: Deliberate price of cutting the re-authorise loop; the 'realtime unavailable' UI surface was out of scope. Evaluate with that decision: a single long-interval re-arm retry, or an onRealtimeUnavailable seam consumed by the surface.
status: open

**(pwa/real-time · recuperación · low) Un feed abandonado por denegación terminal no se re-arma nunca, ni cuando el permiso vuelve.** Tras un 401/403 la suscripción se cierra y solo un remontaje (o un cambio de `topicsKey`/`authorizePath`) reintenta: si un ADMIN devuelve el rol un segundo después, la pestaña sigue con datos estáticos hasta una recarga completa, sin señal para el usuario. Es el precio deliberado de cortar el bucle de reautorización; la superficie UI del estado «realtime no disponible» quedó explícitamente fuera de alcance (sería un patrón nuevo — hoy `<Can>` gatea JSX renderizado, no capabilities de fondo). A evaluar junto con esa decisión: un único reintento de re-arme a intervalo largo, o un seam `onRealtimeUnavailable` que la superficie consuma. Ref: `pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts` (`handleStreamError`).

### DW-44: Erasure repair path can run without writing a row that names the subject (GDPR_SUBJECT_ERASED)

origin: migrated from legacy ledger ("Deferred from: code review of g-2-ids-de-persona-fuera-de-audit-log-metadata (2026-08-04)"), 2026-09-24
location: api/src/Iam (FulfilIdentityErasure:144)
reason: GDPR_SUBJECT_ERASED is conditioned on erasedAnything(); when the identity is gone but references remain only GDPR_ERASURE_EXECUTED is written. Pre-existing; open question whether the resource axis needs its own row. Two tests pin the opposite behaviour on purpose, so changing it reopens their argument.
status: open

**(api/Iam · evidencia de cumplimiento · medium) La ruta de reparación puede ejecutar un borrado sin dejar fila que nombre al sujeto.** `FulfilIdentityErasure:144` condiciona `GDPR_SUBJECT_ERASED` a `$identity->erasedAnything()` (identidad + tokens). Cuando la identidad ya no está pero quedan referencias — el estado exacto que `identity:gdpr:reconcile-subject-references` existe para detectar, y que `UserEraseController` documenta como «a completed cleanup, not a no-op» — solo se escribe `GDPR_ERASURE_EXECUTED`. **Preexistente**: la guarda no cambió en #636, y desde la review esa fila sí lleva `anonymized_actor_id`, así que el pseudónimo ya no queda huérfano. Lo que sigue abierto es si el eje **recurso** debe tener fila propia en ese camino. Dos tests fijan hoy el comportamiento contrario a propósito (`testResourceRowsAloneStillProduceComplianceEvidence`, `testReferenceRowsAloneStillProduceComplianceEvidence`), así que cambiarlo es reabrir su argumento, no corregir un descuido.

### DW-45: User preferences (theme, language, notifications) editable and persisted from /backoffice/profile/settings

origin: migrated from legacy ledger ("Deferred from: quick-dev intent split of spec-iam-account-profile (2026-08-04)"), 2026-09-24
location: pwa/src/app/backoffice/profile/settings/page.tsx, pwa/src/context/shared/theme/domain/Theme.ts
reason: Split from the My-account spec as an independent deliverable with its own data model: theme is client-only (localStorage), language does not exist as a concept, and there is no notification channel. Server-side persistence needs a new aggregate/table and endpoint.
status: open

**(pwa+api · alcance · medium) Preferencias de usuario (tema, idioma, notificaciones) editables y persistidas desde `/backoffice/profile/settings`.** Separado del spec de «Mi cuenta» porque es un entregable independiente con su propio modelo de datos: **no** toca el agregado `User` (que hoy solo lleva `email`, `password_hash`, `roles`, `status`, `failed_attempts`, `locked_until`), sino que exige decidir dónde vive una preferencia — hoy el tema es puramente cliente (`erpify:theme` en localStorage vía `next-themes`), el idioma no existe como concepto (el traductor está apagado, `config/packages/translation.yaml`) y no hay canal de notificaciones que configurar (`notification/domain/` solo materializa `Toast`). Persistirlas server-side implica agregado/tabla nuevos y endpoint propio, revisable y mergeable sin la vista de perfil. Ref: `pwa/src/app/backoffice/profile/settings/page.tsx` (placeholder solo-título), `pwa/src/context/shared/theme/domain/Theme.ts`.

### DW-46: audit_log has no CHECK tying actor_type to actor_id nullability

origin: migrated from legacy ledger ("Deferred from: code review of br-4c-602-observabilidad-del-throttle-de-recuperacion (2026-08-12)"), 2026-09-24
location: audit_log schema (api/migrations), api/src/Shared/Audit
reason: Illegal rows are unrepresentable in PHP but not in the plain VARCHAR/nullable UUID columns written by raw DBAL, fixtures and Behat SQL; a user row with NULL actor_id escapes both erasure passes silently. Pre-existing; a schema CHECK plus enum-token CHECK is its own migration and decision.
status: open

**`audit_log` has no CHECK tying `actor_type` to `actor_id` nullability, so illegal rows are representable.** `ActorContext` makes `anonymous`/`system` with an id — and `user`/`api_key` without one — unrepresentable in PHP, but the column is plain `VARCHAR(16)`/nullable `UUID` and the table is written by raw DBAL, fixtures and Behat SQL. A `user` row carrying a NULL `actor_id` is matched by neither erasure pass (the actor pass matches on `actor_id`; the resource pass's metadata guard requires `actor_type = anonymous`), so a person's request metadata would survive both silently. Surfaced by the adversarial pass on the anonymous-actor redaction; pre-existing, and a schema-level fix (`CHECK ((actor_type IN ('anonymous','system')) = (actor_id IS NULL))` plus an enum-token CHECK) is its own migration and its own decision.

### DW-47: audit_log user_agent is client-forgeable as the literal [REDACTED]

origin: migrated from legacy ledger ("Deferred from: code review of br-4c-602-observabilidad-del-throttle-de-recuperacion (2026-08-12)"), 2026-09-24
location: api/src/Shared/Audit (user_agent capture)
reason: Stored verbatim from the header, so a request can write a row indistinguishable from a redacted one; nothing in api/src reads the sentinel, so the harm is to a human reading the trail — but resource-axis redaction now legitimately produces the same shape.
status: open

**`user_agent` is client-forgeable as the literal `[REDACTED]`.** It is stored verbatim from the header with no allowlist, so a request can write a row indistinguishable from a redacted one. Nothing in `api/src` reads the sentinel, so the damage is to a human reading the trail, not to code — but the resource-axis redaction now legitimately produces sentinel-with-`actor_erased = FALSE`, which is the same shape the forgery lands in.

### DW-48: Audit rows committed between the resource-axis UPDATE and the erasure commit keep request metadata

origin: migrated from legacy ledger ("Deferred from: code review of br-4c-602-observabilidad-del-throttle-de-recuperacion (2026-08-12)"), 2026-09-24
location: api/src/Shared/Audit (AuditSubjectRowLock), RecordLockoutAuditBestEffort, RecordRecoveryThrottleAuditBestEffort
reason: Neither late writer contends on identity_user, so the window is closed by nothing; it is recoverable (reconciler surfaces it and re-running the idempotent resource pass redacts it) but nothing re-runs it automatically.
status: open

**A row committed between the resource-axis `UPDATE` and the erasure's commit keeps its request metadata, and nothing serialises the two.** `AuditSubjectRowLock` scopes its guarantee to the rows existing when it ran, so a `USER_LOCKED` landing after the pass keeps the requester's ip with `resource_erased = FALSE`. **Neither writer of that shape contends on `identity_user`:** `RecordLockoutAuditBestEffort` runs post-commit with the subject id already in hand, and `RecordRecoveryThrottleAuditBestEffort::subjectOf()` resolves the subject with a plain unlocked `findByEmail` from a `kernel.terminate` listener — so the window is closed by nothing, not merely narrow. What makes it *recoverable* rather than permanent is that the late row keeps `resource_id` at the real id with `resource_erased = FALSE`: the reconciler surfaces it, and re-running the erasure's resource pass against that id redacts both columns, because the statement is idempotent by predicate rather than by flag. The residue is therefore narrower than "unhandled" — nothing re-runs it **automatically**, and an operator has to be told by the reconciler first.

### DW-49: Session retention sweep cost asserted rather than measured

origin: migrated from legacy ledger ("Deferred from: code review of br-5-ciclo-de-vida-iam-session (2026-08-14)"), 2026-09-24
location: api/src/Iam/Session (PruneRetiredSessions), iam_session indexes
reason: Neither retention branch is index-served, so each tick is a sequential scan and catch-up adds scans after an outage; unmeasurable today (zero rows, no production), so an index would be speculative — revisit with a real row count.
status: open

**The session retention sweep's cost is asserted rather than measured, unlike the prune it sits beside.** Neither retention branch is served by an index (`iam_session` carries only `iam_session_pkey` and `idx_iam_session_user_id_status`), so each tick is a sequential scan, and the schedule's catch-up means an outage is followed by one deleting sweep plus one full scan per missed period. `audit_log`'s prune has its equivalent cost measured to the millisecond and documented at the statement. Not measurable today — the table holds zero rows in dev and there is no production deployment — so an index would be a speculative optimisation; revisit with a real row count.

### DW-50: hardNavigate: a throwing own-caller callback strands the queued losers

origin: migrated from legacy ledger ("Deferred from: adversarial pass of the #831 residual fix (2026-08-22)"), 2026-09-24
location: pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts (fire)
reason: fire() calls onFailure for the claim's own caller before draining superseded, so a throw leaves queued losers unreported. Not fixed: the module has no error-handling contract for caller callbacks and neither real caller throws.
status: open

**(hardNavigate seam) A throwing own-caller callback strands the queued losers.** `fire()` calls `onFailure("not-committed")` for the claim's own caller and only then drains `superseded`, so a throw in the first leaves every queued loser with no report at all — they wait for a callback that never comes. New in kind: before `superseded` was deferred there were no losers to strand. Not fixed, because the module has no error-handling contract for caller callbacks and inventing one means swallowing errors it cannot interpret; neither of the two real callers throws. The sibling hazard — a throw leaving the CLAIM itself unarmed — was a real defect and is fixed, with the ordering pinned by "arms the new claim before running the preempted caller's callback". Ref: `pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts` (`fire`).

### DW-51: hardNavigate: preemptible is sticky for the claim's life, partly giving back #830's determinism

origin: migrated from legacy ledger ("Deferred from: adversarial pass of the #831 residual fix (2026-08-22)"), 2026-09-24
location: pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts (NavigationClaim.preemptible)
reason: Once hidden while pending, a claim stays preemptible after re-show, so concurrent races resolve last-wins. Deliberate for #831's scenario; revisit if a third hardNavigate caller appears.
status: open

**(hardNavigate seam) `preemptible` is sticky for the life of the claim, which partly gives back the determinism #830 bought.** Once the document has been hidden while a navigation is pending, that claim stays preemptible even after the tab is visible again and its budget resumes — so for the rest of its life an ordinary concurrent race resolves last-wins rather than first-wins. Deliberate: #831's own scenario is "the user comes back and clicks Sign out", so clearing the flag on re-show would leave that item open. The cost is real and is recorded rather than implied away; revisit if a third `hardNavigate` caller appears, since two callers is what makes the window unobservable today. Ref: `hardNavigate.ts` (`NavigationClaim.preemptible`).
