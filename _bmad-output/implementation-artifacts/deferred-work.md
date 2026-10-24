# Deferred Work

### DW-1: PR #943 merged without the three review layers it owed — run a post-hoc pass over feafdddb

status: done 2026-09-24
origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
archived: 2026-09-28

### DW-2: Lesson: #943's argument for skipping its own review was written by the same agent it governed

origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
location: CLAUDE.md
reason: #943 was authored by an agent about a rule governing agents, and the argument for skipping its own review was written into its own body by that agent; nothing mechanical catches that shape — kept as the general lesson, not a detail of the PR.
status: open

**The asymmetry is worth keeping, because it is the general lesson and not a detail of this PR.** #943 was authored by an agent, about a rule governing agents, and the argument for skipping its own review was written into its own body by that same agent. Nothing mechanical catches that shape.

### DW-3: Lesson: an open PR can merge mid-review, and the note recording it landed on a deleted branch

status: done 2026-09-24
origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
archived: 2026-09-28

### DW-4: Reactivate-or-retire decision on the review bot is still the user's and unresolved

status: done 2026-09-28
origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
archived: 2026-10-21

### DW-5: Context note: PR #929 (suite clock pin) merged with its three layers run — nothing left to do

status: done 2026-09-24
origin: migrated from legacy ledger ("Deferred from: retiring the named review bot from the code-review rule (2026-09-19)"), 2026-09-24
archived: 2026-09-28

### DW-6: Audit: entry.action and metadata.operation encode the same fact with nothing keeping them in agreement

status: done 2026-09-24
resolution: resolved by sweep bundle dw-audit-change-metadata-shape
resolution-undo: 83fdd0c39cf8bd1d2b727223562a30b0190f4386896a08a9d24f7158115f442b 2026-09-24 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of PR #853 — audit write-operation snapshot header (2026-08-26)"), 2026-09-24
archived: 2026-09-28

### DW-7: Audit diff: unconditional render of Empty fields generalises a privacy argument made only for BankAccount.alias

origin: migrated from legacy ledger ("Deferred from: code review of PR #849 — campos vacíos y dirección inferida en el diff de auditoría (2026-08-26)"), 2026-09-24
location: pwa/src/context/backoffice/audit/infrastructure/ui/AuditChangeDiff.tsx (ChangeKind.Empty)
reason: AuditChangeDiff shows 'Not set' for any nullable #[PersonalData] field on any audited entity, while the product owner's argument only covered alias. Trigger: the first audited nullable #[PersonalData] field whose mere presence/absence is more sensitive — consider a per-field opt-out.
status: open

**(pwa/context/backoffice/audit — alcance de privacidad) El render incondicional de campos `Empty` es genérico a toda `AuditedEntity`, pero el argumento de privacidad que lo autorizó solo razonó sobre `BankAccount.alias`.** `AuditChangeDiff` muestra "Not set" para cualquier campo `#[PersonalData]` nullable nunca poblado, en cualquier entidad auditada — no solo `alias`, el único ejemplo con el que el product owner argumentó la decisión en el spec. Es un hecho forense deliberado y correcto para el caso motivador; lo que falta razonar es la generalización. Trigger: la primera entidad auditada con un campo `#[PersonalData]` nullable donde la mera presencia/ausencia sea más sensible que en `alias` — revisar si ese campo necesita un opt-out por campo en vez de la regla genérica. Ref: `pwa/src/context/backoffice/audit/infrastructure/ui/AuditChangeDiff.tsx` (`ChangeKind.Empty`), `_bmad-output/implementation-artifacts/spec-gh-413-campos-vacios-y-direccion-inferida.md`.

**Re-medido el 2026-10-24: sigue abierta, el trigger no ha saltado.**

- **Evidencia:** solo dos entidades implementan `AuditedEntity` (`Bank` y `BankAccount`), y `#[PersonalData]` solo aparece en `BankAccount`: `holderName` e `iban`, ambos `string`, y `alias`, `?string`. El único campo `#[PersonalData]` nullable de una entidad auditada sigue siendo `BankAccount.alias`, el mismo sobre el que razonó el product owner.
- **Condición de cierre:** el trigger de arriba, es decir, la primera entidad auditada con otro campo `#[PersonalData]` nullable.
- **Alcance de la evidencia:** lo medido es un `grep` de `implements AuditedEntity` y `#[PersonalData]` sobre `api/src`. No juzga si la presencia o ausencia de `alias` es sensible; eso lo decidió el product owner.

### DW-8: EventStoreSubjectAnonymiser::anonymise takes two consecutive strings; swapping them is a silent no-op

status: done 2026-09-28
resolution: resolved by sweep bundle dw-event-store-anonymiser-typed-args
resolution-undo: d72b63ce85460b3f51815097f28a5b37c80f0840e0f625e0762d17aa1f21b6b6 2026-09-28 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of g-5-ids-de-persona-fuera-del-event-store (2026-08-04)"), 2026-09-24
archived: 2026-10-21

### DW-9: D12's 'closed set of sanctioned mutations' is prose with half a gate — UPDATEs on event_store/audit_log are ungated

status: done 2026-09-24
resolution: resolved by sweep bundle dw-sanctioned-mutation-gate
resolution-undo: 6ceb7fb97093ce893838d60471c9b3ca85d52b3c9446bfd8b89cc312d387e409 2026-09-24 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of g-5-ids-de-persona-fuera-del-event-store (2026-08-04)"), 2026-09-24
archived: 2026-09-28

### DW-10: ScheduleConsumption gate: compose.dev.yaml outside COMPOSE_FILES, so a dev-overlay command: is invisible

status: done 2026-09-24
resolution: resolved by sweep bundle dw-schedule-gate-dev-overlay
resolution-undo: cacf4b726cf8c87a9729aa42d7cfa36c84e944695a00b0988301d0ce7cfdc33f 2026-09-24 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of g-3b-agendado-observable-reconciliador-referencias-borradas (2026-08-04)"), 2026-09-24
archived: 2026-09-28

### DW-11: ReconcileErasedSubjectReferences: nobody chunks below the 65535 bound-parameter ceiling

status: done 2026-09-24
resolution: resolved by sweep bundle dw-reconciler-scale-bounds
resolution-undo: 6033fe42be6e27d58bb581a1bfcf05a1707cf2ed0af97f1a8e9ac09e18008873 2026-09-24 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of g-3b-agendado-observable-reconciliador-referencias-borradas (2026-08-04)"), 2026-09-24
archived: 2026-09-28

### DW-12: Six person-reference sources read their whole column with no LIMIT or keyset

status: done 2026-09-24
resolution: resolved by sweep bundle dw-reconciler-scale-bounds
resolution-undo: 6033fe42be6e27d58bb581a1bfcf05a1707cf2ed0af97f1a8e9ac09e18008873 2026-09-24 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of g-3b-agendado-observable-reconciliador-referencias-borradas (2026-08-04)"), 2026-09-24
archived: 2026-09-28

### DW-13: Erasure-chain prose (command class docblock, setHelp(), controller) still ungated

status: done 2026-10-23
origin: migrated from legacy ledger ("Deferred from: code review of PR #618 — pase adversarial del eje de referencias a persona (2026-08-01)"), 2026-09-24
archived: 2026-10-24

### DW-14: event_store axis not covered by ReconcileErasedSubjectReferences — detective control missing, for measurable reasons

origin: migrated from legacy ledger ("Deferred from: G-5 — ids de persona fuera del `event_store` (2026-08-04)"), 2026-09-24
location: api/src/Shared/Event (event_store), api/.person-reference-policy
reason: Copying the audit_log resource-axis pattern fails: no registry-governed type discriminator and no resource_erased flag, so every correct erasure would report as divergence; adding the flag reopens D12. Trigger: event_store gains an erasure flag, or a person aggregate becomes truly event-sourced.
status: open

**(api/Shared/Event — control detective) El eje `event_store` NO entra en `ReconcileErasedSubjectReferences`, y la razón es medible, no un olvido.** El borrado preventivo está hecho; lo que falta es detectar una fila que se le escapara. Copiar el patrón del eje de recurso de `audit_log` **no funciona**, y le faltan las dos cosas que allí lo hacen significar algo: (1) un **discriminador de tipo gobernado por registro** — `DbalPersonResourceReferences` filtra `resource_type = 'User'` y ese vocabulario lo gobierna `.audit-resource-types`; en `event_store` un `SELECT DISTINCT aggregate_id` devuelve ids de banco, cuenta, invitación y token, ninguno resuelve a `identity_user`, y filtrar por `aggregate_type` es **enumerar**, la lista que ya falló dos veces; y (2) un flag **`resource_erased`**, sin el cual el pseudónimo que deja cada borrado **correcto** resuelve a ninguna identidad viva y el reconciliador **reportaría cada borrado que ha hecho como divergencia, para siempre**. Añadir ese flag es una columna nueva en un log append-only: schema listener + migración + camino de escritura + una **segunda** mutación sancionada, o sea reabrir D12.

**Además el hueco de «colaborador especial» tiene plaza única y declarada** (`api/.person-reference-policy`, bloque de puntos ciegos): un segundo eje sin entidad no se añade como fuente sin que el registro estrene antes un verbo para columnas sin entidad. Añadirlo a mano convertiría una excepción argumentada en un patrón.

**Y solo la mitad sería detectable.** El eje de `payload` es estructuralmente invisible a este control: preguntar «¿qué ids de persona guarda el payload?» exige saber bajo qué claves viajan —la enumeración que todo el diseño rechaza— y preguntar «¿qué UUID del payload no respalda ninguna identidad viva?» devuelve todo id de banco, invitación y organización. No hay tercera vía sin crosswalk, y D4 lo veta.

**Trigger:** el día que `event_store` gane un flag de borrado, o que un agregado-persona pase a ser event-sourced de verdad (el mismo trigger (a) que D12 ya declara). Un medio-control cuyo ruido de base es ~100 % deja de leerse, y un hueco nombrado es mejor que un control que nadie cree.

**Re-medido el 2026-10-23 (#1103): sigue abierta por diseño, con decisión del usuario.**

- **Evidencia:** `api/src/Shared/Event/Application/EventStore.php` solo expone `append` y `stream`. Ningún agregado-persona se rehidrata desde `event_store` y la tabla no tiene flag de borrado. El trigger de D12 no ha saltado.
- **Riesgo residual:** si el borrado preventivo deja escapar un id de persona en `event_store`, nadie lo detecta. Los proyectores lo leen en cada replay.
- **Condición de cierre:** el trigger de D12, es decir, un flag de borrado en `event_store` o un agregado-persona event-sourced de verdad.
- **Por qué no ahora:** añadir ese flag reabre D12.
- **Alcance de la evidencia:** que el trigger no haya saltado prueba solo que ese mecanismo no existe hoy. No prueba que no haya riesgo.
- **Lo que sí cambió:** #1103 indexa el predicado preventivo (DW-15) sin estrechar su alcance.

### DW-15: Erasure UPDATE on event_store is a sequential scan inside the erase transaction

status: done 2026-10-23
origin: migrated from legacy ledger ("Deferred from: G-5 — ids de persona fuera del `event_store` (2026-08-04)"), 2026-09-24
archived: 2026-10-24

### DW-16: event_store stream UNIQUE enforces nothing (NULL tenant_id, NULLS DISTINCT); promised optimistic concurrency does not exist

status: done 2026-09-29
resolution: resolved by sweep bundle dw-event-store-informative-version (#1035)
resolution-undo: e9c25f1139d58488497417fd599a52fe820e5c9fd72ca8394ff120b983635e09 2026-09-29 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: G-4a — fuga de `PasswordResetCompleted` en los transportes Messenger (2026-07-30)"), 2026-09-24
archived: 2026-10-21

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

status: done 2026-10-22
origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-11, revisión fresca)"), 2026-09-24 — merged with ("Deferred from: code review of u-3-cambio-de-estado-suspend-deactivate (2026-07-18)")
archived: 2026-10-22

### DW-21: FindUserOrganizationId resolves the org with a non-deterministic findOneBy under multi-membership

status: done 2026-10-22
origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-11, revisión fresca)"), 2026-09-24
archived: 2026-10-22

### DW-22: PWA collapses every non-401 /me failure (incl. 503) to 'unauthenticated'

status: done 2026-09-29
resolution: resolved by sweep bundle dw-pwa-me-503-to-maintenance (#1035)
resolution-undo: 1e34e2bec4d8fa80f5ba5ab6505a9700c856b8d10a86062670180f7c1dc50c0d 2026-09-29 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
archived: 2026-10-21

### DW-23: MembershipNotFound mapped to SessionStoreUnavailable (503 store-unreachable)

status: done 2026-10-22
origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
archived: 2026-10-22

### DW-24: Orphaned ACTIVE iam_session rows (post-commit correlation failure, re-login without revoke)

status: done 2026-09-29
resolution: resolved by sweep bundle dw-revoke-prior-session-on-relogin (#1035)
resolution-undo: dea7356760358eced66f116cc50b7f0295b0a1ea9d640115599edf4ea64806dd 2026-09-29 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
archived: 2026-10-21

### DW-25: Two clocks per aggregate (injected Clock vs static SystemClock) — suite pin widened the divergence; open product-owner decision

status: done 2026-09-24
resolution: resolved by sweep bundle dw-suite-clock-seed-alignment
resolution-undo: 50bf58aed32c39a16719ba9d0928cafec956e63e0ca154fc6e26c8044b0f5547 2026-09-24 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
archived: 2026-09-28

### DW-26: SessionAdmissionGate matcher (/api/) narrower than the firewall access_control (^/api)

status: done 2026-09-28
resolution: resolved by sweep bundle dw-api-boundary-single-definition
resolution-undo: 368d96dbce23e1bea2c3b29f624a92313cfa7cc5eabd008f64aec09b18ea97bd 2026-09-28 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of ii-7-session-lifecycle-registry-gate-failclosed (2026-07-10)"), 2026-09-24
archived: 2026-10-21

### DW-27: Keyset cursor base-query identity coupled to Doctrine's getDQL() formatting

origin: migrated from legacy ledger ("Deferred from: code review of rm-2-cierre-fingerprint-keyset-437 (2026-07-07)"), 2026-09-24
location: api/src/Shared/Search/Infrastructure/Persistence/Doctrine/DoctrineSearchEngine.php (baseQueryIdentity)
reason: Byte-for-byte DQL determinism between mint and follow-up is an unguarded precondition; no trigger today (4 fixed-shape consumers). Risks: a consumer with auto-generated params or request-conditional JOIN/WHERE (spurious 422 on page 2), or a Doctrine upgrade re-minting all fingerprints. Hardening: a format-independent identity or a request-invariance guardrail.
status: open

**(Shared/Search — hardening) La identidad base-query del cursor está acoplada al formato de `getDQL()` de Doctrine.** `DoctrineSearchEngine::baseQueryIdentity()` sella `$queryBuilder->getDQL()` en el fingerprint; su determinismo byte-a-byte entre mint y follow-up es precondición **no guardada** (mitigada sólo con nota en el docblock). Dos ejes de riesgo, sin trigger hoy (los **4** consumidores son fixed-shape con placeholders nombrados — re-medidos uno a uno el 2026-09-20, y son cuatro consumidores del motor, no los 6 que esta bala registró el 2026-09-03 (el árbol tiene cinco `->paginate(`; el quinto es `AuditTimelineKeysetPaginator`, que es el paginador DBAL hermano y acuña su fingerprint de los criterios, no del texto DQL, así que el eje de riesgo de `baseQueryIdentity()` no le aplica): `git log -S` no encuentra ningún consumidor borrado desde entonces, así que el 6 nunca fue. `doctrine/orm` está bloqueado en 3.6.8, el dato que al eje (b) le faltaba: ninguno usa parámetro auto-generado ni JOIN/WHERE condicional por request, y el único `join` del árbol es incondicional): (a) un consumidor futuro con parámetro auto-generado (`?1`, `expr()->in()`) o JOIN/WHERE condicional por request → 422 espurio en la página 2; (b) un upgrade de Doctrine que cambie la generación de DQL re-acuña todos los fingerprints a la vez (acotado — cursores efímeros). Endurecimiento posible: derivar una identidad base-query estable e independiente del formato (p. ej. parts normalizados o un token de identidad explícito), o un guardrail que asegure base-query request-invariante. Ref: `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/DoctrineSearchEngine.php` (`baseQueryIdentity`).

### DW-28: Should User store Email as a Doctrine embeddable (VO as persisted state)?

status: done 2026-09-28
origin: migrated from legacy ledger ("Deferred from: code review of af-1-1-user-aggregate-backoffice-identity-persistencia (2026-07-02)"), 2026-09-24
archived: 2026-10-21

### DW-29: Durability of the audit security branch (write-before-send) against a caller's transaction

status: done 2026-09-28
origin: migrated from legacy ledger ("Deferred from: code review of stories 1.3 & 1.4 (2026-06-24)"), 2026-09-24
archived: 2026-10-21

### DW-30: Residuals of the metadata-to-object coercion: historical audit_log rows stay [] and event_store.metadata still writes []

status: done 2026-09-29
resolution: resolved by sweep bundle dw-event-store-informative-version (#1035)
resolution-undo: e9c25f1139d58488497417fd599a52fe820e5c9fd72ca8394ff120b983635e09 2026-09-29 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of the metadata-shape fix (2026-09-22)"), 2026-09-24
archived: 2026-10-21

### DW-31: Nested empty 'changes' still travels as a JSON array and the PWA guard rejects it

status: done 2026-09-24
resolution: resolved by sweep bundle dw-audit-change-metadata-shape
resolution-undo: 83fdd0c39cf8bd1d2b727223562a30b0190f4386896a08a9d24f7158115f442b 2026-09-24 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of the metadata-shape fix (2026-09-22)"), 2026-09-24
archived: 2026-09-28

### DW-32: audit metadata PII-free rule (FR12) has no structural enforcement

status: done 2026-10-23
origin: migrated from legacy ledger ("Deferred from: code review of Epic 1 audit specs (2026-06-23)"), 2026-09-24
archived: 2026-10-24

### DW-33: audit_log.metadata jsonb has no size bound

status: done 2026-10-23
origin: migrated from legacy ledger ("Deferred from: code review of Epic 1 audit specs (2026-06-23)"), 2026-09-24
archived: 2026-10-24

### DW-34: Failure contract of the audit security branch never re-reviewed against its live producer

status: done 2026-09-28
origin: migrated from legacy ledger ("Deferred from: code review of Epic 1 audit specs (2026-06-23)"), 2026-09-24
archived: 2026-10-21

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

**Re-medido el 2026-10-24 (#TBD): el supuesto (a) queda fijado y el (b) sigue abierto, y es peor de lo que dice esta entrada.**

- **(a) fijado:** `PermissionVoterAccessDecisionTest::testPermissionAttributesAreDecidedAffirmativelyWithThePermissionVoterAsTheirOnlyVoter` lee la estrategia de la decisión que toma el `access_decision_manager` cableado (`AccessDecision::$strategy`) y exige `affirmative`. También exige que `PermissionVoter` sea el único voter que vota sobre un atributo de permiso. Declarar otra estrategia en cualquier fichero de configuración lo pone en rojo.
- **(b) abierto:** el voter no votaría DENY sobre un atributo con punto que no sea un permiso, como dice la entrada. Puede votar **GRANT**. `StaticAuthorizationPolicy` concede por tier cualquier recurso que no esté en `TIER_OPT_OUT`, así que `featureFlag.read` se concede a VIEWER y cualquier `<x>.<y>` a ADMIN (`ADMIN => ['*']`). Bajo `affirmative`, ese GRANT anula el DENY de un voter propio de ese atributo. Que la estrategia sea `affirmative` no lo protege, sino que lo hace posible.
- **Condición de cierre:** un marcador positivo o un registro de recursos y acciones en `PermissionVoter::supports()`. Es un cambio del núcleo de autorización (`AuthorizationCoreIsClosedForModificationTest`) y lo decide el usuario.
- **Hoy:** no existe ningún atributo con punto que no sea un permiso, así que el riesgo no está vivo.

### DW-37: Invitation accept: no negative CSRF test (403 without mutation)

status: done 2026-10-22
origin: migrated from legacy ledger ("Deferred from: code review of ii-4-invitation-accept-pantallas-acceso (2026-07-13)"), 2026-09-24
archived: 2026-10-22

### DW-38: Invitation accept: no assertion that the session id is regenerated (anti-fixation, NFR3)

status: done 2026-10-22
origin: migrated from legacy ledger ("Deferred from: code review of ii-4-invitation-accept-pantallas-acceso (2026-07-13)"), 2026-09-24
archived: 2026-10-22

### DW-39: Public Navbar not auth-aware: authenticated users still see 'Sign in' (and 'Backoffice')

status: done 2026-09-29
resolution: resolved by sweep bundle dw-public-navbar-session-aware (#1035)
resolution-undo: 86daafc5a5042e9f52f2a9dd054271b9d896c4cb2cf567ee0828e525a5e59e9d 2026-09-29 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of landing-login-cta (2026-07-15)"), 2026-09-24
archived: 2026-10-21

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
decision: 2026-09-28 Keep open

**(pwa/real-time · recuperación · low) Un feed abandonado por denegación terminal no se re-arma nunca, ni cuando el permiso vuelve.** Tras un 401/403 la suscripción se cierra y solo un remontaje (o un cambio de `topicsKey`/`authorizePath`) reintenta: si un ADMIN devuelve el rol un segundo después, la pestaña sigue con datos estáticos hasta una recarga completa, sin señal para el usuario. Es el precio deliberado de cortar el bucle de reautorización; la superficie UI del estado «realtime no disponible» quedó explícitamente fuera de alcance (sería un patrón nuevo — hoy `<Can>` gatea JSX renderizado, no capabilities de fondo). A evaluar junto con esa decisión: un único reintento de re-arme a intervalo largo, o un seam `onRealtimeUnavailable` que la superficie consuma. Ref: `pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts` (`handleStreamError`).

### DW-44: Erasure repair path can run without writing a row that names the subject (GDPR_SUBJECT_ERASED)

status: done 2026-09-28
origin: migrated from legacy ledger ("Deferred from: code review of g-2-ids-de-persona-fuera-de-audit-log-metadata (2026-08-04)"), 2026-09-24
archived: 2026-10-21

### DW-45: User preferences (theme, language, notifications) editable and persisted from /backoffice/profile/settings

origin: migrated from legacy ledger ("Deferred from: quick-dev intent split of spec-iam-account-profile (2026-08-04)"), 2026-09-24
location: pwa/src/app/backoffice/profile/settings/page.tsx, pwa/src/context/shared/theme/domain/Theme.ts
reason: Split from the My-account spec as an independent deliverable with its own data model: theme is client-only (localStorage), language does not exist as a concept, and there is no notification channel. Server-side persistence needs a new aggregate/table and endpoint.
status: open
decision: 2026-09-28 Keep open for a dedicated epic

**(pwa+api · alcance · medium) Preferencias de usuario (tema, idioma, notificaciones) editables y persistidas desde `/backoffice/profile/settings`.** Separado del spec de «Mi cuenta» porque es un entregable independiente con su propio modelo de datos: **no** toca el agregado `User` (que hoy solo lleva `email`, `password_hash`, `roles`, `status`, `failed_attempts`, `locked_until`), sino que exige decidir dónde vive una preferencia — hoy el tema es puramente cliente (`erpify:theme` en localStorage vía `next-themes`), el idioma no existe como concepto (el traductor está apagado, `config/packages/translation.yaml`) y no hay canal de notificaciones que configurar (`notification/domain/` solo materializa `Toast`). Persistirlas server-side implica agregado/tabla nuevos y endpoint propio, revisable y mergeable sin la vista de perfil. Ref: `pwa/src/app/backoffice/profile/settings/page.tsx` (placeholder solo-título), `pwa/src/context/shared/theme/domain/Theme.ts`.

### DW-46: audit_log has no CHECK tying actor_type to actor_id nullability

status: done 2026-09-29
resolution: resolved by sweep bundle dw-audit-log-actor-check-constraints (#1035)
resolution-undo: 616f89c8d7dc406a646d9c76ded067eb47fc34773f56778663cd395af56352ca 2026-09-29 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of br-4c-602-observabilidad-del-throttle-de-recuperacion (2026-08-12)"), 2026-09-24
archived: 2026-10-21

### DW-47: audit_log user_agent is client-forgeable as the literal [REDACTED]

status: done 2026-09-28
resolution: resolved by sweep bundle dw-audit-user-agent-sentinel-forgery
resolution-undo: 4f8d06267155660d82fb4f59b2fae8eafa007099e63530253ebb124e4c9c4845 2026-09-28 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of br-4c-602-observabilidad-del-throttle-de-recuperacion (2026-08-12)"), 2026-09-24
archived: 2026-10-21

### DW-48: Audit rows committed between the resource-axis UPDATE and the erasure commit keep request metadata

status: done 2026-09-29
resolution: resolved by sweep bundle dw-serialise-late-audit-writers-on-identity (#1035)
resolution-undo: 9d428fdb4548d84211b380e0baaa40b44b90e5117b8cbfc08e1afa77869fc35f 2026-09-29 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: code review of br-4c-602-observabilidad-del-throttle-de-recuperacion (2026-08-12)"), 2026-09-24
archived: 2026-10-21

### DW-49: Session retention sweep cost asserted rather than measured

origin: migrated from legacy ledger ("Deferred from: code review of br-5-ciclo-de-vida-iam-session (2026-08-14)"), 2026-09-24
location: api/src/Iam/Session (PruneRetiredSessions), iam_session indexes
reason: Neither retention branch is index-served, so each tick is a sequential scan and catch-up adds scans after an outage; unmeasurable today (zero rows, no production), so an index would be speculative — revisit with a real row count.
status: open

**The session retention sweep's cost is asserted rather than measured, unlike the prune it sits beside.** Neither retention branch is served by an index (`iam_session` carries only `iam_session_pkey` and `idx_iam_session_user_id_status`), so each tick is a sequential scan, and the schedule's catch-up means an outage is followed by one deleting sweep plus one full scan per missed period. `audit_log`'s prune has its equivalent cost measured to the millisecond and documented at the statement. Not measurable today — the table holds zero rows in dev and there is no production deployment — so an index would be a speculative optimisation; revisit with a real row count.

### DW-50: hardNavigate: a throwing own-caller callback strands the queued losers

status: done 2026-09-28
resolution: resolved by sweep bundle dw-hardnavigate-drain-losers-on-throw
resolution-undo: 905bfed871485deb8609fc4a9cea47bd91ea83216eae17ac60d1043b629464fc 2026-09-28 7374617475733a206f70656e
origin: migrated from legacy ledger ("Deferred from: adversarial pass of the #831 residual fix (2026-08-22)"), 2026-09-24
archived: 2026-10-21

### DW-51: hardNavigate: preemptible is sticky for the claim's life, partly giving back #830's determinism

origin: migrated from legacy ledger ("Deferred from: adversarial pass of the #831 residual fix (2026-08-22)"), 2026-09-24
location: pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts (NavigationClaim.preemptible)
reason: Once hidden while pending, a claim stays preemptible after re-show, so concurrent races resolve last-wins. Deliberate for #831's scenario; revisit if a third hardNavigate caller appears.
status: open

**(hardNavigate seam) `preemptible` is sticky for the life of the claim, which partly gives back the determinism #830 bought.** Once the document has been hidden while a navigation is pending, that claim stays preemptible even after the tab is visible again and its budget resumes — so for the rest of its life an ordinary concurrent race resolves last-wins rather than first-wins. Deliberate: #831's own scenario is "the user comes back and clicks Sign out", so clearing the flag on re-show would leave that item open. The cost is real and is recorded rather than implied away; revisit if a third `hardNavigate` caller appears, since two callers is what makes the window unobservable today. Ref: `hardNavigate.ts` (`NavigationClaim.preemptible`).

### DW-52: Un metadata.changes almacenado como null o escalar se sirve tal cual y el guard PWA rechaza todo el sobre del detalle.

status: done 2026-09-28
resolution: resolved by sweep bundle dw-audit-detail-changes-shape
resolution-undo: 47b99c0a536e6197cb31dd804d9cd38d0ebecf361b4b3d51d5618b3a4186e8db 2026-09-28 7374617475733a206f70656e
origin: spec-deferred 0d11259c1d42
source_spec: `spec-audit-change-metadata-shape.md`
severity: low
archived: 2026-10-21

### DW-53: CLAUDE.md "Required checks" no nombra que añadir una mutación sobre event_store/audit_log exige una línea en SANCTIONED de SanctionedLogMutationGateTest más la decisión en el ADR.

status: done 2026-09-28
origin: spec-deferred 95691e997eda
source_spec: `spec-dw-9-sanctioned-mutation-gate.md`
severity: low
archived: 2026-09-28

### DW-54: El merge de pila sólo modela `command`; `include:`, `extends:`, `profiles:`, `entrypoint:` y `deploy.replicas: 0` en un overlay se ignoran en silencio.

status: done 2026-09-25
origin: spec-deferred e0a580f06eae
source_spec: `spec-dw-10-schedule-gate-dev-overlay.md`
severity: medium
archived: 2026-09-28

### DW-55: El bullet "Declaring an #[AsSchedule]" del CLAUDE.md raíz no menciona que un `command:` en un overlay reemplaza el de la base.

status: done 2026-09-28
origin: spec-deferred ea851dd8e2c3
source_spec: `spec-dw-10-schedule-gate-dev-overlay.md`
severity: low
archived: 2026-09-28

### DW-56: El bullet «Reading the clock in a test» del CLAUDE.md raíz no menciona el guardarraíl de FixedClock ni la retirada de los setters de Timestamped.

status: done 2026-09-28
origin: spec-deferred e33d5b51c137
source_spec: `spec-dw-25-suite-clock-seed-alignment.md`
severity: low
archived: 2026-09-28

### DW-57: Cinco tests preexistentes siguen llamando a SystemClock::reset(), que docs/rules/testing.md prohíbe.

status: done 2026-09-25
origin: spec-deferred b9870c785d25
source_spec: `spec-dw-25-suite-clock-seed-alignment.md`
severity: low
archived: 2026-09-28

### DW-58: Los tests que construyen sesiones ya caducadas con SessionMother::active(expiresAt: <pasado>) siguen produciendo filas con caducidad anterior a su createdAt, y el guardarraíl no lo ve.

status: done 2026-09-25
origin: spec-deferred c8d88fb2b946
source_spec: `spec-dw-25-suite-clock-seed-alignment.md`
severity: medium
archived: 2026-09-28

### DW-59: Registrar en docs/rules/security.md el patrón «un límite por path se evalúa como el router: PathRequestMatcher sobre el path decodificado, nunca str_starts_with(getPathInfo())».

status: done 2026-09-28
origin: spec-deferred ea65b7a10c18
source_spec: `spec-dw-26-api-boundary-single-definition.md`
severity: low
archived: 2026-10-21

### DW-60: Las filas de audit_log persistidas antes de este cambio con un User-Agent igual a [REDACTED] no se reescriben.

status: done 2026-10-23
origin: spec-deferred ee2d683b793e
source_spec: `spec-dw-47-audit-user-agent-sentinel-forgery.md`
severity: low
archived: 2026-10-24

### DW-61: docs/rules/security.md y docs/rules/database.md describen el centinela [REDACTED] y sus dos escritores sin mencionar su reserva ni la reescritura [client-supplied].

status: done 2026-09-28
origin: spec-deferred 0a417c155324
source_spec: `spec-dw-47-audit-user-agent-sentinel-forgery.md`
severity: low
archived: 2026-10-21

### DW-62: Una fila de nivel change SIN clave changes sigue pintando «No changes recorded», afirmación igual de desconocida que la del diff ilegible.

status: done 2026-09-28
origin: spec-deferred 3f20d89e3077
source_spec: `spec-dw-52-audit-detail-changes-shape.md`
severity: low
archived: 2026-10-21

### DW-63: Un changes escalar corrupto nunca pasó por el sellado por campo, así que podría llevar PII sin cifrar servida tal cual por la ruta de detalle.

status: done 2026-10-03
origin: spec-deferred fb38058ee328
source_spec: `spec-dw-52-audit-detail-changes-shape.md`
severity: low
archived: 2026-10-22

### DW-64: event_store.payload se escribe como array JSON `[]` para eventos cuyo toPrimitives() está vacío (p.ej. BankDeletedDomainEvent).

status: done 2026-09-29
origin: spec-deferred 8e448123a8db
source_spec: `spec-dw-16-30-event-store-informative-version.md`
severity: low
archived: 2026-10-21

### DW-65: RepositoryUniqueViolationTest no tiene caso para el puerto `image` de ConcurrentUniqueWrite.

status: done 2026-09-29
origin: spec-deferred 1ab3b10287b9
source_spec: `spec-dw-16-30-event-store-informative-version.md`
severity: low
archived: 2026-10-21

### DW-66: La sonda en frío de /me que se resuelve después de un login() puede pisar la sesión recién obtenida.

status: done 2026-09-29
origin: spec-deferred 3ae2cbd0cfb3
source_spec: `spec-dw-22-pwa-me-503-to-maintenance.md`
severity: low
archived: 2026-10-21

### DW-67: Un re-sondeo de /me disparado por navegación puede pisar el resultado de un login() o logout() posterior.

status: done 2026-09-29
origin: spec-deferred 64a9c85297cc
source_spec: `spec-dw-22-pwa-me-503-to-maintenance.md`
severity: low
archived: 2026-10-21

### DW-68: audit_log.level no tiene CHECK; una fila escrita por SQL crudo con un level desconocido o mal capitalizado no la casa ninguna pasada del pruner.

status: done 2026-09-29
origin: spec-deferred 473433d709dd
source_spec: `spec-dw-46-audit-log-actor-check-constraints.md`
severity: low
archived: 2026-10-21

### DW-69: Otros dos escritores post-commit nombran al sujeto en resource_id sin bloquear identity_user: RecordLockoutNoticeAuditBestEffort y RecordRecoverySecretAuditBestEffort.

status: done 2026-09-29
origin: spec-deferred d63734244a81
source_spec: `spec-dw-48-serialise-late-audit-writers-on-identity.md`
severity: medium
archived: 2026-10-21

