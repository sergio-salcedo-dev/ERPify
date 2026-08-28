# ERPify — Documentation Index

> Updated 2026-07-29. Primary entry point for repo-wide docs. AI agents: load [`project-context.md`](./project-context.md) before generating code — its version claims are gated by `make php.lint.project-context`, its prose is not. RFC 9457 error contract: [`api-error-contract.md`](./api-error-contract.md). Deep-dives: 2.

## Project at a glance

- **Type:** Monorepo (multi-part: `api/` + `pwa/`)
- **Purpose:** Construction-industry SaaS ERP/CRM.
- **Primary languages:** PHP 8.5 (API), TypeScript 6 (PWA)
- **Architecture:** DDD + Hexagonal / Clean across both parts.

### `api/` — Symfony API (backend)

- **Tech stack:** Symfony 8.1, FrankenPHP (Caddy), Doctrine ORM 3.6 / DBAL 4.4, PostgreSQL, Symfony Messenger, Mercure.
- **Root:** `api/`
- **Entry point:** `api/src/Kernel.php` via FrankenPHP → `api/public/index.php`
- **Bounded contexts:** `Backoffice/{Audit, Bank, BankAccount, Health}`, `Frontoffice/{Dev, Health, Mercure}`, `Iam/{Identity, Invitation, Session}`, `Organization/{Organization, Membership}`, `Shared/{Access, Audit, Clock, Crypto, ErrorContract, Event, Http, Images, Kernel, Mailer, Monitoring, Persistence, Privacy, Search, Serialization, Token, Uuid, Validation}`

### `pwa/` — Next.js PWA (web)

- **Tech stack:** Next.js 16.2 (App Router, Turbopack), React 19.2, TypeScript 6, Tailwind 4, Shadcn, Inversify 8, Vitest 4, Playwright 1.59.
- **Root:** `pwa/`
- **Entry point:** `pwa/src/app/layout.tsx` + `pwa/src/app/page.tsx`
- **Bounded contexts:** `backoffice/{health}`, `frontoffice/{health}`, `shared/{domain, infrastructure}`

## How `docs/` is organized

Folders are typed by *kind of document*; file names are kebab-case by topic, never sequence-numbered
(ADRs included). Full rule: [`rules/documentation.md`](./rules/documentation.md).

| Folder | Kind of document |
|--------|------------------|
| root | Entry points (`index.md`, `project-context.md`, `project-overview.md`) |
| `adr/` | Decision records — the *why* behind a choice |
| `rules/` | Prescriptive coding & convention rules |
| `architecture/` | Current-state system design |
| `guides/` | How-to / workflow / contribution |
| `operations/` | Deploy, run, troubleshoot |
| `roadmap/` | Living forward plans |
| `.archive/` | Frozen point-in-time reports |

## Files

### AI agent context (load first)

- **[project-context.md](./project-context.md)** — Authoritative constraints for AI code generation; its version claims are gated by `make php.lint.project-context`, its normative prose is not
- **[claude-code-quickref.md](./claude-code-quickref.md)** — Full command catalog, repo layout tables, "adding new code" recipes, gotchas (companion to root `CLAUDE.md`)

### Project overview

- **[project-overview.md](./project-overview.md)** — High-level repo purpose, parts, and tech stack
- **[source-tree-analysis.md](./source-tree-analysis.md)** — Annotated monorepo directory layout

### Architecture

- **[architecture-api.md](./architecture-api.md)** — API layering, stack, Doctrine, Messenger, Mercure
- **[architecture-pwa.md](./architecture-pwa.md)** — PWA layering, Next.js, Inversify DI, testing
- **[integration-architecture.md](./integration-architecture.md)** — How API and PWA share `localhost`
- **[architecture/event-catalog.md](./architecture/event-catalog.md)** — Registry of every event: the three domain events (`bank.created/updated/deleted`, `BankSnapshot` payload), the Mercure real-time wire contract, non-domain audit signals, the `bank_count` projection, the stored-row envelope, and versioning — with an add/evolve checklist
- **[adr/bank-bankaccount-modeling.md](./adr/bank-bankaccount-modeling.md)** — ADR: id-based cross-module references (Bank/BankAccount), schema-aware FK, per-aggregate persistence strategy (state vs event sourcing)
- **[adr/api-resource-dtos.md](./adr/api-resource-dtos.md)** — ADR: per-view Resource DTOs own the HTTP wire contract (entity never serialized, `#[Groups]` retired); flat DTOs in `Application/Resource/` + injectable Infrastructure mappers; four Bank views, one per endpoint (list/detail 6 keys, create/update 5 — distinct classes so each view evolves independently), `BankWithAccountCount` count carrier, byte-stability gate
- **[adr/shared-module-organization.md](./adr/shared-module-organization.md)** — ADR: `Shared` as vertical-slice capability modules over a cross-cutting kernel trio (`Clock`/`Mailer`/`Search`/`Validation` promoted on API; 8 PWA capabilities consolidated); conservative kernel boundary; dead `Guzzle` enum removed
- **[adr/filters-search-criteria.md](./adr/filters-search-criteria.md)** — ADR: generic `filters[]` search vocabulary (SearchQuery/SearchCriteria), rationale and FR/NFR inventory
- **[adr/keyset-pagination.md](./adr/keyset-pagination.md)** — ADR: cursor-only keyset pagination + repositories by composition (IMPLEMENTATION LOCKED, with post-D-1 override note)
- **[adr/domain-event-handler-idempotency.md](./adr/domain-event-handler-idempotency.md)** — ADR: Messenger handler idempotency — raw-DBAL claim table (`handled_domain_event`) + `postGenerateSchema` listener; ORM-entity and `schema_filter` alternatives rejected
- **[adr/dead-letter-observability.md](./adr/dead-letter-observability.md)** — ADR: project view + replay of the Messenger `failed` transport — `messenger:failed:status [--json]` metric, hourly scheduled `error`-log backlog alarm (Monolog→Sentry intentionally unwired, no HTTP health endpoint), `event:dedup:clear` safe-replay escape hatch (resolves #258 opt 1); read via capability-probed `DeadLetterReader`; D4 bounds the queue at 30 days with an automatic prune whose window is coupled to the alarm's
- **[adr/media-vs-documents-upload-boundary.md](./adr/media-vs-documents-upload-boundary.md)** — ADR (superseded on its criterion by [adr/images-vs-documents-conservation-contract.md](./adr/images-vs-documents-conservation-contract.md); its D1/D2 reasoning stands): `UploadedFile` confined to controllers; image input as the `UploadedImage` value object (stream-capable port rejected); large/non-image files deferred to a future `Documents` context with `StoragePort::writeStream`
- **[adr/images-vs-documents-conservation-contract.md](./adr/images-vs-documents-conservation-contract.md)** — ADR (accepted, design): supersedes the media/documents upload boundary — the discriminant is the **conservation contract over the byte** (fungible representation vs evidence), not MIME or size, so a site photograph is a JPEG that belongs with documents; `UploadImage`/`UploadEvidence` as two commands chosen by the use case, no promotion between contracts (promotion creates evidence *as of today*, a different promise), the images module owning canonical representations rather than preservation (rename to renditions triggered by the first cross-context rendition **in merged code**), lifecycle owned by the aggregate and never by storage or by the derivative (a derivative of evidence is dependent, carries its origin, and its owner must destroy the bytes rather than merely drop the reference — the preview that would otherwise survive its own document's erasure), two irreversible rules for the first slice (the domain never knows a physical storage key; no transport type and no caller-supplied location reach the module's `Application` layer) with multipart/S3/antivirus/OCR/versioning/retention deferred freely and dedup/blob/refcount/GC deferred **as redesign-forcing**, and the evidence semantics that constrain it (document as conservation contract, immutable while it exists, erasure by key destruction over `Shared/Crypto` plus a missing stream-oriented encryptor)
- **[adr/image-deletion-signal-transport.md](./adr/image-deletion-signal-transport.md)** — ADR (accepted): `Shared.Image` is classified **`person`** conservatively — one identifier type covers a bank logo and a person's avatar and the module cannot tell them apart by construction — and its deletion signal is routed to the durable `async` transport **anyway**, as the argued exception the registry requires. Leaving it unrouted is the dangerous default, not the safe one: an unrouted `DomainEvent` is handled in process and use cases publish inside `transactional(...)`, so a storage failure would roll back the owner's business write over bytes already destroyed. States what the classification does **not** buy (it deletes nothing; `messenger_messages`, the 30-day `failed` window and `event_store` all outlive every erasure path) and carries the DSN residual as accepted risk rather than closed
- **[adr/audit-activity-log.md](./adr/audit-activity-log.md)** — ADR: operational/actor audit (`AuditEvent` → `audit_log`) as a separate axis from the domain-event stream; hybrid capture + `AuditPolicy`, async Messenger persistence, level-based retention + GDPR, `Shared` backbone / `Backoffice/Audit` read model; D4 assigns erasure of a person-denoting resource to the **owning bounded context** — `Shared/Audit` supplies `AuditResourceAnonymiser` and never learns which types are people, `Iam` chains it inside its own transaction with the same pseudonym as the actor pass, and the distributed obligation is held by the `.audit-resource-types` registry gate plus a reconciler that reports an erased identity the trail still names
- **[adr/regulatory-audit-trail.md](./adr/regulatory-audit-trail.md)** — ADR (accepted, implemented): evolve the trail into an ISO 27001:2022 regulatory record — write capture via Doctrine `onFlush` CDC + field-level diff (not per-event), semantic action atop the diff, `event_store`=business ⊥ `audit_log`+diff=compliance, audit every read with a resource extractor, PII forgetting by crypto-shredding (per-subject DEK, libsodium + Postgres keystore) so append-only/integrity survive erasure, retention with a 5-year floor (not only a ceiling), trail access RBAC-restricted + self-audited (production gate lifted with the auth foundation — Epic 3)
- **[adr/auth-rbac-subsystem.md](./adr/auth-rbac-subsystem.md)** — ADR (accepted, implemented): the greenfield auth/RBAC subsystem behind Epic 3 of the regulatory trail — stateful httpOnly-session Symfony Security firewall (not JWT, same-origin PWA) + CSRF design, a framework-free `User` aggregate in a new `Backoffice/Identity` context with a `SecurityUser` adapter (deptrac-clean; hashing in Infrastructure), static role enum, `#[IsGranted]` + built-in `RoleVoter` over the two audit read routes via the existing 403 pipeline (no new marker), `actor_id` stays nullable with "real attribution" as a seam invariant (reconciles FR15 with D9 tier-1, no schema change), `ActorContextFactory` as the sole authorized identity-attribution seam, and FR14 authorized-read self-audit as a separate durable listener
- **[adr/rbac-authorization-model.md](./adr/rbac-authorization-model.md)** — ADR (accepted, design): the cross-cutting authorization model every future ERP+CRM entity inherits — permission as a derived value `(resource, action)` (not an entity), a single `PermissionVoter` over a neutral `AuthorizationPolicy` port (retiring role-checks from business routes; audit migrates `ROLE_AUDIT_READER` → `auditTrail.read`), per-module permission constants + one declarative tier-verb policy (`VIEWER/EDITOR/MANAGER/ADMIN` + `explicitGrants` for domain-ops/sensitive reads, resource opt-out) giving zero-edit CRUD extension, start in `Backoffice/Identity/Infrastructure/Security` with day-one-neutral interfaces (promote to `Shared/Authorization` on a second consumer), static-now-configurable-later store swap, subject-as-vocabulary (no type yet), the row-level `subject:` door kept open unbuilt (with keyset #437 as co-requisite), and the OCP acceptance criterion + two anti-ABAC tripwires; banks/accounts first slice
- **[adr/authorization-model-boundaries.md](./adr/authorization-model-boundaries.md)** — ADR (accepted): what will never be a `Role` — a platform operator is a separate principal reached by explicit, reason-bearing, audited impersonation (never `Role::SUPER_ADMIN`, never `switch_user`, and needing dual-actor attribution in `audit_log`), and ownership belongs to the user↔organization membership (never a global `Role::OWNER`, since `Role` is shared with the PWA and ownership is org-scoped); introduces neither concept, records the trigger for ownership (a real actor who operates the ERP but must not govern who administers it) and the #549 interaction; also settles that `ADMIN` **keeps** `auditTrail.read` — withdrawing it buys no real separation of duties while `users.invite`/`users.changeRoles` stay ADMIN-only, and costs a bootstrap window with no reader plus an `AUDIT_READER` to keep alive per organization — with the risk bounded by two read-only routes and self-audited reads, explicitly **not** by tamper-evidence (none exists) nor by the 5-year floor (which covers `change` rows only; `security` rows carry a 365-day ceiling, except the two erasure-evidence actions the prune exempts), revisited when a customer requires contractual SoD; and guards the trail's attribution on the write side by refusing to erase any subject still carrying `ADMIN`, now a traceability control rather than a bare authorization step because the demotion writes an explicit `USER_ROLES_CHANGED` `security` row naming the subject in the resource columns — safe only because erasure anonymises both axes in one transaction, and bounded by the 365-day ceiling that ages that record out before the `change` rows it explains; also corrects the Context's own claim that a `users.grant-admin` permission "would have been a no-op" (true of the pre-opt-out superuser `ADMIN`, false once `users` opts out of tiering — a permission can express "`ADMIN` may not" where a higher rung cannot, and `users.grantAdmin` is that row), and states the invariant it still does **not** claim: the ≥1-admin guard makes a peer demotion visible, never impossible
- **[adr/identity-invitation-lifecycle.md](./adr/identity-invitation-lifecycle.md)** — ADR (accepted, design): extends the auth/identity subsystem with invitation-first onboarding and the four state machines — promotes `Backoffice/Identity` to top-level `Iam/{Identity,Invitation,Session}` + a new `Organization/{Organization,Membership}` context (multi-tenant-ready domain, tenancy operation deferred to its own ADR); `User` born `INVITED` (two machines live in parallel, `HashedPassword` nullable until `ACTIVE`), admission via a `UserChecker` making the three-moments rule mechanical (`credentials → identity → admission → session`, stateless account-status walls minting no session), `Invitation` aggregate whose accept POST mints the first session under CSRF + session regeneration, a shared `Shared/Token/SingleUseToken` (security-critical DRY over Rule-of-Three), persisted `LockedUntil` complementing the existing `login_throttling`, a server-side `Session` registry behind a fail-closed admission gate (native storage, logical revocation, `iamSessionId` infra-only, forward-path multi-node = new ADR), uniform revoke-all password reset, and the two invariants as backend contract (timing/status/shape indistinguishability, token-URL hygiene, trust-graded error specificity)
- **[adr/administrative-recovery-channel.md](./adr/administrative-recovery-channel.md)** — ADR (accepted): the two invariants a recovery channel for a denied-entry administrator must hold, so the mechanism stays replaceable — **I-1** the channel is identified in a namespace an attacker knowing only the administrative identity cannot consume (the defect is *channel coupling*: login, the persisted lockout and `password_recovery_per_email` are keyed by the address, `password_change_per_identity` by an identity a stolen session also holds; `token_action_per_selector` is the in-repo counter-example), with the corollary — **already red on the invitation half**, whose events carry the accept link's selector as their `aggregateId` into `event_store` while the reset half names the user instead — that no surface but delivery may emit the identifier; **I-2** at no point in its life may anyone but the customer reconstruct the knowledge to exercise it *without modifying state* — reproducibility, not custody or timing, since material derived from vendor-set secrets passes a chronological test and fails forever, and with no delivery exemption (a vendor-operated relay reads any emailed material with no write); explicitly **not** guaranteeing that the vendor cannot seize the channel, nor that seizing it is **detectable** — `User` is out of the audit CDC, the capture listener is a Doctrine listener raw SQL bypasses, and `clearLockout()` raises no event, so a vendor `UPDATE` leaves no row (detection is the axis blocked on #555); a second administrator judged as having only a destructive edge (demote → hard-delete the row → re-invite), recommended but never enforced since a ≥2 floor would make erasing an administrator need a third; and `identity:lockout:clear` recorded as removing an invisible capability rather than adding one — named and tested, never attributable to a human, since a CLI actor is `system`
- **[adr/maintenance-job-execution-contract.md](./adr/maintenance-job-execution-contract.md)** — ADR: normative contract for scheduled maintenance jobs (audit/dedup pruning, dead-letter alarm) — invariants (single-executor guarantee — currently a Postgres advisory lock, not coupled to it; idempotent chunk convergence; bounded mutation — batching or documented max volume; pure-policy `ExecutionPlan` boundary; materializable plan independent of data cardinality; schedule/job/platform ownership split; at-least-once retry) + mental API (`MaintenanceJob`/`ExecutionPlan`/`ExecutionStep`); interfaces deliberately **not** crystallized in code until a 3rd use case confirms the pattern (premature-convergence avoidance at N=2)
- **[adr/event-driven-architecture.md](./adr/event-driven-architecture.md)** — ADR: `EventBus` port + transactional outbox closing the domain-event dual-write; the three axes (state/atomic, actor/best-effort, read), the no-broker-direct invariant, `wrapInTransaction` as a transitional detail pending the `CommandBus` middleware (#263), and the `php.lint.event-bus` enforcement gate (partially superseded by `event-store-and-projections.md`)
- **[adr/event-store-and-projections.md](./adr/event-store-and-projections.md)** — ADR: `event_store` reproducible log (not Event Sourcing) replacing the `domain_event` audit table — `Shared/Event/{Domain,Application,Infrastructure}` backbone, hardened `DomainEvent` (`fromPrimitives` + injectable identity), mapper/serializer/upcaster seams, `sequence`-ordered raw-DBAL store; projector ≠ reactor with checkpointed catch-up + rebuild; `bank_count` reference projection + `GET …/banks/count` endpoint
- **[adr/behat-event-observability-test-contexts.md](./adr/behat-event-observability-test-contexts.md)** — ADR: Behat step tooling for the event/outbox and log-line seams, ported from internal bundles by capability not class; in-memory `MessengerContext` (D2), `OutboxContext` over the `domain_event` store rows (D3), `LoggerContext` reusing the existing `BufferingLogger` for RFC 9457 log assertions (D4), real-worker `MessengerConsumeContext` (D5), rate-limiter generalization deferred by Rule of Three (D6)
- **[adr/external-dependencies-in-domain.md](./adr/external-dependencies-in-domain.md)** — ADR: PSR interface-only contracts allowed in Domain/Application, frameworks/runtimes confined to Infrastructure, no 1:1 wrapper over a permitted PSR (runtime-not-vendor discriminant; per-dependency table)
- **[adr/domain-enums.md](./adr/domain-enums.md)** — ADR: domain enums are identity-only (`->value` = wire contract, `SCREAMING_SNAKE`), presentation/i18n externalized to the PWA (or an Application/Infrastructure catalog keyed by `->value`, never the enum); generalized anti-regression rule for Domain VOs and Application mappers; string-backed default with int-backed as the hot-path exception (per-aggregate); atomic `enumType` contract swap (the `HumanReadable*` stack removed)
- **[adr/domain-presentation-separation.md](./adr/domain-presentation-separation.md)** — ADR: generalizes the enum case — no `Domain/` type (enum, VO, entity) and no `Application/` DTO/mapper carries display text/formatting/i18n; presentation lives in the presentation layer keyed by identity; split enforcement (the `DomainPresentationSeparationGateTest` name-scan gate over Domain + Application attributes/abstraction, plus review for the semantic half); full-AST/deptrac and Application method-name scanning rejected as FP-prone
- **[adr/test-id-naming-contract.md](./adr/test-id-naming-contract.md)** — ADR (accepted): `data-testid` is a first-class published QA contract — app-wide-unique, DOM-structure-independent, entity identity = UUID v7 suffix (shared vocabulary with `Routes`); BEM semantics disclaimed (Option A keeps `__`/`--` as lexical separators, no rename; hyphen-flat Option B deferred on empirical evidence); layered protection — static-uniqueness guard + consumer-owned `testId`/`testIdPrefix` design rule + two ESLint rules (no presentation-derived testid; dynamic templates carry a non-index uniqueness token, heuristic); System Invariant + named residuals (`--variant` reads BEM-shaped, dynamic-token lint can't prove non-UUID-key uniqueness)
- **[api-error-contract.md](./api-error-contract.md)** — RFC 9457 Problem Details: marker→status map, correlation-id, instance UUIDv7, logging tiers, `exception_category` SRE taxonomy
- **[troubleshooting/sentry-domain-error-filtering.md](./troubleshooting/sentry-domain-error-filtering.md)** — deferred: drop/sample `domain_error` noise in Sentry (`ignore_exceptions` vs `before_send`), with the trade-off
- **[troubleshooting/sentry-boot-probe-noise.md](./troubleshooting/sentry-boot-probe-noise.md)** — fixed: silencing the container boot DB-probe flood (`SENTRY_DSN=` on the entrypoint `SELECT 1` wait), safe in dev + prod
- **[troubleshooting/sentry-messenger-worker-dev-cache-crash.md](./troubleshooting/sentry-messenger-worker-dev-cache-crash.md)** — fixed: dev Messenger worker crashed on a recompiled DI container (shared `var/cache/dev` deleted under the long-lived worker); fix = `APP_DEBUG=0` + private cache volume on the worker

### Deep-Dive Documentation

Detailed exhaustive analysis of specific areas:


### Development & contribution

- **[contribution-guide.md](./contribution-guide.md)** — Branches, commits, PR conventions
- **[deployment-guide.md](./deployment-guide.md)** — Docker Compose envs and prod services
- **[erpify-local-test-deployment.md](./erpify-local-test-deployment.md)** — Step-by-step: run the prod profile at `https://erpify.local` (internal TLS) on a local box
- **[vps-deployment.md](./vps-deployment.md)** — Promote to a public VPS + remote database access (CLI / GUI over SSH)
- **[background-jobs-and-scheduling.md](./background-jobs-and-scheduling.md)** — Decision record: supervised `messenger_worker` over host crontab; how to add periodic jobs (Symfony Scheduler) and scale to many daemons
- **[saas-production-roadmap.md](./saas-production-roadmap.md)** — Forward plan: registry publishing, safe migrations, zero-downtime, rollback, staging/prod split (planning only)
- **[development-guide-api.md](./development-guide-api.md)** — Day-to-day API workflow via `make`
- **[development-guide-pwa.md](./development-guide-pwa.md)** — Day-to-day PWA workflow via `make`

## Related references (outside this folder)

- [CLAUDE.md](../CLAUDE.md) — Repo-wide Claude Code guidance
- [api/CLAUDE.md](../api/CLAUDE.md) · [api/README.md](../api/README.md) · `api/docs/` — API-specific docs
- [pwa/CLAUDE.md](../pwa/CLAUDE.md) · [pwa/README.md](../pwa/README.md) · `pwa/docs/` — PWA-specific docs
- `docs/rules/*.md` — Authoritative coding rules (architecture, clean-code, commits, cqrs-naming, database, documentation, frontend, php-standards, read-side-projections, security, testing)

## Getting started

```bash
# First time
cp api/.env.example api/.env
make docker.up        # full stack on http(s)://localhost
make db.migrate
make db.load.fixtures

# Common daily commands
make docker.up | docker.down | docker.logs | docker.ps |
make php.test | php.quality
make pwa.test | pwa.quality
make app.test | app.quality  # both parts
make composer c='...'    # composer in container
make db.migrate | db.diff | db.status | db.shell
```

Per-part setup: [`development-guide-api.md`](./development-guide-api.md), [`development-guide-pwa.md`](./development-guide-pwa.md).

## For BMad / PRD workflows

When creating a brownfield PRD or feature plan, point the workflow to this index. For scoped features:

- UI-only → [`architecture-pwa.md`](./architecture-pwa.md)
- API-only → [`architecture-api.md`](./architecture-api.md)
- Full-stack → both + [`integration-architecture.md`](./integration-architecture.md)
