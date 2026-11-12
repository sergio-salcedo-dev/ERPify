# Make targets (run from repo root)

All targets are ENV-aware (`ENV=dev|ci|staging|prod`) and default to `IN_CONTAINER=true` — they exec in the `php` container via `docker compose`.

## Composer / Symfony

-   `make composer c='…'` — composer in the container (e.g. `c='req vendor/pkg'`).
-   `make composer.install`, `composer.update`, `composer.check.all` (platform-reqs + require-checker + unused).
-   `make sf c='…'` — Symfony console. Shortcuts: `make sf.cc` (cache:clear), `make sf.cache.warmup`, `make sf.routes f='filter'`, `make sf.about`.

## Tests

-   `make php.test` = `php.unit` + `php.behat`.
-   `make php.unit c='--filter SomeTest'` — PHPUnit single filter.
-   `make php.behat c='features/foo.feature:42'` — single scenario.
-   No separate install: Behat and PHPUnit both run from the app vendor.

## Lint / static analysis

-   `make php.quality` — full sweep (PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS).
-   `make php.prepush` — the command to run before pushing PHP: `php.quality` (fixers apply), then `php.quality.dry-run` (the check-only sweep CI runs, on the tree the fixers left), then `php.unit`. Exit 0 from `php.quality` alone does not mean CI-clean, because a fixer that repaired the drift exits 0 over it.
-   Individual: `php.stan[.baseline]`, `php.rector[.dry-run]`, `php.cs-fixer[.dry-run]`, `php.md`, `php.cs[.dry-run]`.
-   Drift gates (in `php.quality`): `php.lint.error-contract`; `php.lint.bounded-context` (bounded-context isolation — Level 1 cross-context `Domain`/`Application`/`Infrastructure` import fails, Level 2 cross-context FK warns; seams in `api/.bounded-context-allowlist`); `php.lint.step-vocabulary` (every Behat step pattern classified `used` / `idle` / `manual` / `refused` in `api/.behat-step-vocabulary`, recomputed from the tree; blind spots in that file's header); `php.lint.audit-evidence` (every audit action an `AuditLogger` collaborator declares as a string constant classified `evidence` / `ordinary` in `api/.audit-evidence-actions`, with the `evidence` half kept equal to `AuditErasureEvidence::ACTIONS` — the closed set the retention prune exempts, because evidence may not expire before the thing it attests); `php.lint.audit-resource` (every audit `resource_type` classified person-denoting or not in `api/.audit-resource-types`, a `person` type naming both the erasure use case that wires `AuditResourceAnonymiser` and the acceptance scenario proving no row survives). `php.lint.gate-placement` (every artifact gate — a kernel-free test whose subject is a repository artifact — classified `home` / `mirrored :: <directory>` / `undecided :: <reason>` in `api/.artifact-gate-placement`, recomputed from the tree; rule in `docs/rules/testing.md`). `php.lint.document-custody` (the Documents custody log may be changed by exactly one statement, the actor anonymisation — SQL, DBAL table API, DQL/QueryBuilder naming the entity and `remove()` are read; `docs/adr/documents-custody-log.md` D3). `php.lint.crypto-read-boundary` (a file under `src` outside `Shared/Crypto/` that calls a read method of `EnvelopeEncryptor`/`StreamEnvelopeEncryptor` — every public method that is not `encrypt`, `seal` or `destroyScope`, derived by reflection — must be registered beside a test (PHPUnit functional or Behat feature) holding `dek-destroyed` and `decryption-failed` and a `docs/api-error-contract.md` line that cites it and names `dek-destroyed`; #418). `php.lint.documents-ingest` (no HTTP transport type or caller-supplied location past `Infrastructure` in `src/Documents`; the three Documents entities stay out of `#[AuditedEntity]`; production may open `documents.ingest_enabled` — any value but the literal `false` counts — only with a valid restore-proof record for its declared backup mechanism, `docs/runbooks/documents-restore-proof.md`, checked against the compiled prod container too, with `DocumentsIngestGate` bound to exactly that parameter; every service running the Documents sweeps mounts the document storage; the upload route's path, cookie condition and 100 MiB bound agree across Caddy, the API and the PWA redaction; the plaintext spool is confined to a tmpfs). None of these gates judges a classification — the human classifies, the automation verifies — and each enumerates the rest of its blind spots in its registry header. The list here is not the catalog; `make help` is.
-   `php.deptrac` (in `php.quality`) — deptrac architecture-boundary gate: hexagonal layering (Infrastructure → Application → Domain), bounded-context isolation (defence-in-depth alongside `php.lint.bounded-context`), and the Domain/Application external-dependency allowlist (PSR / `symfony/uid` / passive-metadata attributes inward; frameworks confined to `Infrastructure/`). Config `tools/deptrac/deptrac.yaml`; grandfathered inner-layer deps in `tools/deptrac/deptrac.baseline.yaml` (regen with `php.deptrac.baseline`).

## Database (Doctrine)

-   `make db.migrate` — run pending migrations.
-   `make db.diff` — generate a migration from entity/schema diff (**review before committing**).
-   `make db.status`, `make db.validate` (ORM mapping ↔ DB).
-   `make db.load.fixtures` — purge + load Hautelook Alice fixtures, then replay every projection over the seeded event log (`event:projection:rebuild --all`).
-   `make db.reset` — drop → migrate → fixtures (**destructive**).
-   `make db.shell` — interactive psql.

## Messenger

-   `make sf.messenger.stop-workers` — use after deploys so workers pick up new code.

## Xdebug

-   `make xdebug.enable` / `xdebug.disable` / `xdebug.status`.

## Stack helpers

Full list in root [`../../CLAUDE.md`](../../CLAUDE.md).

-   `make php.bash` — bash shell in the `php` container.

## Running PHP on the host

Set `IN_CONTAINER=false` to skip the container and run against your host PHP — useful for quick tool runs if your host has the right extensions. Default is container mode; CI is safe either way.
