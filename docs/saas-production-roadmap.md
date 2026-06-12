# ERPify — SaaS production-grade roadmap

> Forward plan for evolving ERPify from "hardened single-box prod profile" to
> a SaaS-grade delivery pipeline: registry-published images, real
> staging/prod separation, safe migrations, zero-downtime cutover, automatic
> rollback, and a supply-chain security baseline.
>
> **Status:** planning only — nothing here is implemented yet. Each phase is a
> short-lived branch off `main` delivered in small Conventional-Commits slices,
> matching the cadence used by the prod-hardening work.

## Where we are today (baseline — already shipped)

The `feat(app): production-profile hardening + erpify.local deploy` work landed
the foundation this roadmap builds on. Do **not** re-plan these:

- **Secret delivery** — real prod secrets live in a gitignored root
  `.env.prod.local`, fed to Compose via `--env-file` (wired in
  [`make/config.mk`](../make/config.mk)). The tracked template is
  [`.env.prod.example`](../.env.prod.example); `make prod.env.check` fails fast
  on a missing file / unset / `CHANGE_ME` placeholder. Compose interpolation
  reads only `--env-file`/shell — **not** a service's `env_file:` — so that
  wiring stays in `config.mk`, not on services.
- **Runtime hardening** — [`compose.prod.yaml`](../compose.prod.yaml) requires
  every secret via `${VAR:?msg}` (no weak fallback), drops all caps then
  re-adds the minimum, sets `no-new-privileges`, `read_only`+`tmpfs` on the
  PWA, CPU/memory ceilings, and isolates Postgres on an `internal` network with
  no published port.
- **Reproducible deploy** — `make deploy.local` (→
  [`scripts/deploy/deploy-local.sh`](../scripts/deploy/deploy-local.sh))
  stands the prod profile up at `https://erpify.local` over Caddy
  `tls internal`; `sudo make deploy.local.trust` installs the CA. The day-2
  orchestrator is [`scripts/deploy/deploy.sh`](../scripts/deploy/deploy.sh).
- **Docs** — [`PRODUCTION_SECURITY_CHECKLIST.md`](../PRODUCTION_SECURITY_CHECKLIST.md)
  (authoritative), [`docs/deployment-guide.md`](deployment-guide.md),
  [`docs/vps-deployment.md`](vps-deployment.md),
  [`docs/erpify-local-test-deployment.md`](erpify-local-test-deployment.md).
- **CI** — [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) runs
  quality + tests on push/PR (Docker bake build, PHPUnit/Behat/Vitest/Playwright,
  Symfony security-checker). It does **not** publish images or deploy.

## Carried-forward constraints (frozen)

These come from the implemented prod-hardening spec and remain binding:

- **Never** introduce Docker Swarm, Kubernetes, or a new orchestrator. Stay
  Compose-only. (This shapes the zero-downtime design — see Phase D.)
- **Never** unpin base-image digests or commit a populated `.env.prod.local`.
- The prod profile must stay byte-identical between the LAN test box and a VPS
  except `SERVER_NAME` / origins / secrets.
- Keep `dev`/`ci` overlay behavior unchanged; `make app.dev` must still work.
- Don't touch application/domain code, `api/src`, `pwa/src`, or merged
  migrations as part of this infra work.

---

## Open decisions (resolve before Phase A)

These gate the later phases — answers change the implementation:

1. **Container registry** — GHCR (`ghcr.io/sergio-salcedo-dev/*`) assumed.
   Confirm, or name an alternative (Docker Hub, private registry).
2. **Deploy target & access** — for now the LAN box at `erpify.local`. Is
   production reached over SSH from CI, or pull-based on the box? Is there a
   real second host for staging, or is staging a second stack (different
   `COMPOSE_PROJECT_NAME` + ports) on the same box?
3. **Secrets backend for CI** — GitHub Actions secrets feeding an SSH deploy is
   the simplest. Symfony's encrypted secrets vault (Phase F) is optional and
   only worth it if app-level secrets must travel in the repo.
4. **Versioning scheme** — semver git tags (`vX.Y.Z`) for prod releases +
   commit-SHA tags for every build is assumed below.
5. **Tenancy model** — ERPify is a multi-company SaaS, but the schema has **no
   tenant discriminator yet** (no `company_id` anywhere). Decide: single DB +
   shared schema with a `company_id` column (assumed below — lowest
   operational cost on a Compose-only stack), schema-per-tenant, or
   DB-per-tenant. The answer gates Phase H and shapes every future index.

---

## Phase A — Supply chain & image publishing

**Goal:** every merge to `main` and every `vX.Y.Z` tag produces immutable,
scanned, signed prod images in the registry, addressable by Git SHA.

- New workflow `.github/workflows/release.yml`: reuse the existing Docker bake
  setup to build `frankenphp_prod` + the PWA prod image; tag with
  `:sha-<short>` (always) and `:X.Y.Z` + `:latest` (on tag); push to the
  registry. Gate on the existing `ci.yml` passing.
- **Scan gates:** Trivy (image CVE scan, fail on HIGH/CRITICAL fixable),
  gitleaks (secret scan on the repo/diff). Add SBOM (syft) + cosign signing
  (keyless OIDC) as a follow-up slice.
- **Dependabot** (`.github/dependabot.yml`) for `github-actions`, `composer`,
  and `npm` ecosystems.

**Commit slices:** `ci(shared): publish prod images to registry` ·
`ci(shared): add trivy + gitleaks scan gates` · `ci(shared): SBOM + image
signing` · `build(shared): enable dependabot`.

**Acceptance:** a tagged release yields a pulled-by-digest image; a planted
secret or a fixable CRITICAL CVE fails CI; Dependabot opens update PRs.

## Phase B — Registry-based deploy (decouple build from deploy)

**Goal:** prod runs pre-built, scanned images pulled by tag — no host builds.

- `compose.prod.yaml` already names `image: ${IMAGES_PREFIX:-}app-php-prod`.
  Introduce `IMAGE_TAG` so prod resolves to a specific
  `${IMAGES_PREFIX}app-php-prod:${IMAGE_TAG}` from the registry.
- `scripts/deploy/deploy.sh`: add a registry-pull path
  (`docker compose pull` + `up -d --no-build`) selected for prod/staging; keep
  the local build path for `deploy.local`.
- Record the previously-deployed `IMAGE_TAG` (e.g. in a host state file) so
  Phase D's rollback has a known-good target.

**Commit slices:** `feat(shared): pin prod images by tag` ·
`feat(shared): registry-pull deploy path` · `feat(shared): persist
last-good image tag`.

**Acceptance:** `DEPLOY_ENV=prod IMAGE_TAG=vX.Y.Z scripts/deploy/deploy.sh`
pulls and runs that exact image without building on the host.

## Phase C — Safe Symfony migrations

**Goal:** migrations never break a running release or block rollback.

- Adopt **expand/contract**: additive/backward-compatible migrations only, so
  the currently-running image keeps working while the new one rolls in
  (destructive drops land a release later, after the old code is gone).
- Pre-migration **DB backup** (`pg_dump`) step in the deploy script, retained
  for rollback; document restore.
- Run migrations as a discrete gated step (`make db.migrate ENV=prod`,
  `--all-or-nothing`) **before** cutover; abort the deploy on failure.
- Document the discipline in [`docs/rules/database.md`](rules/database.md) and
  [`docs/deployment-guide.md`](deployment-guide.md).

**Commit slices:** `docs(shared): expand/contract migration policy` ·
`feat(shared): pre-deploy db backup + gated migrate`.

**Acceptance:** a deploy whose migration fails leaves the previous release
serving and the DB untouched; a backup artifact exists per deploy.

## Phase D — Zero-downtime cutover (Compose-only blue-green)

**Goal:** no dropped requests during a release, without an orchestrator.

> **Tradeoff (flag):** true multi-replica rolling deploys need Swarm/K8s, which
> the frozen constraints forbid. On a single Compose host the realistic path is
> **blue-green**: run a second stack (`COMPOSE_PROJECT_NAME=erpify-green`) on
> the new image, health-check it, then flip Caddy's reverse-proxy upstream from
> blue→green and retire blue. A simpler interim step is a health-gated
> `up -d --wait` recreate (brief connection drop on the single FrankenPHP
> instance) — acceptable for the LAN box, not for SaaS SLA.

- Decide blue-green vs graceful-recreate; if blue-green, add an upstream switch
  in [`api/frankenphp/Caddyfile`](../api/frankenphp/Caddyfile) (or a thin front
  Caddy) and the project-name plumbing in `scripts/deploy/`.
- **Messenger worker drain:** stop accepting new work and let the current
  message finish (the consumer already runs with `--time-limit`) before
  replacing the worker container.

**Commit slices:** `feat(shared): blue-green stack plumbing` ·
`feat(shared): caddy upstream cutover` · `feat(shared): graceful messenger
drain on deploy`.

**Acceptance:** a load generator against `/api/v1/health` sees zero failed
requests across a deploy.

## Phase E — Automatic rollback

**Goal:** a failed release self-reverts to the last known-good image.

- `scripts/deploy/deploy.sh`: a `rollback` subcommand that re-pins the
  previous `IMAGE_TAG` (Phase B state) and `up -d`; triggered automatically when
  the post-deploy smoke (`curl -k https://$SERVER_NAME/api/v1/health`) fails.
- Define the DB-rollback policy explicitly: with expand/contract (Phase C) the
  old image runs against the new schema, so image rollback is safe; restoring
  the `pg_dump` is the break-glass path, documented but manual.

**Commit slices:** `feat(shared): rollback subcommand` · `feat(shared):
auto-rollback on failed smoke`.

**Acceptance:** a deliberately-broken image deploy ends with the previous
release healthy and serving, unattended.

## Phase F — Real staging/prod separation

**Goal:** staging is a genuinely separate environment, not a prod alias.

- Today `make/config.mk` maps both `staging` and `prod` to
  `compose.prod.yaml` + `.env.prod.local`. Split them: `.env.staging.local`,
  a distinct `SERVER_NAME` (e.g. `staging.erpify.<domain>` /
  `erpify.staging.local`), distinct `COMPOSE_PROJECT_NAME`, network subnet, and
  volumes. Parametrize rather than fork the overlay if the only diffs are
  env-driven (keeps the "byte-identical profile" constraint).
- CI/CD: deploy to **staging on merge to `main`**, to **prod on `vX.Y.Z` tag**.

**Commit slices:** `feat(shared): staging env + overlay split` ·
`ci(shared): deploy main→staging, tag→prod`.

**Acceptance:** staging and prod run concurrently, isolated DBs/volumes/origins,
from the same image built once.

## Phase G — Secrets vault & ops baseline (optional / later)

- **Symfony secrets vault** — `secrets:set` for app-level secrets, committed
  encrypted, decrypt key (`config/secrets/prod/prod.decrypt.private.php`,
  already gitignored) provided on the host / via a CI secret. Only if secrets
  must live in the repo; otherwise `--env-file` + host file is fine.
- **Ops:** backup rotation + a tested restore drill, uptime/health monitoring,
  log shipping, and a deploy/rollback runbook.

## Phase H — Multi-tenant foundation (`company_id`)

**Goal:** every tenant-owned row is scoped to a company, enforced at the query
level, so two customers can never see each other's data.

> **Scope note:** unlike Phases A–G this is an **application/data phase** — it
> deliberately touches `api/src` and ships migrations, so the carried-forward
> "don't touch application code" constraint (which binds the *infra* phases)
> does not apply here. It still follows the expand/contract migration policy
> from Phase C.

- **Tenant aggregate** — a `Company` aggregate in its own bounded context
  (UUID v7 PK like every entity), plus the membership model
  (user ↔ company) when auth lands.
- **Schema** — `company_id UUID NOT NULL` FK on every tenant-owned table,
  added expand/contract: (1) nullable column + backfill, (2) `NOT NULL` +
  FK constraint one release later. Shared/global tables (e.g. `domain_event`
  audit) carry it too so audit queries are tenant-scopable.
- **Indexes** — composite indexes **led by the tenant**:
  `(company_id, created_at, id)` and tenant-led variants of every column
  exposed in a `SearchFieldMap`/`SortFieldMap`. A bare single-column index on
  a tenant-owned table is a smell once `company_id` exists (every query is
  tenant-scoped, so the planner needs the composite). Verify with
  `EXPLAIN ANALYZE` per `docs/rules/database.md`.
- **Enforcement** — tenant scoping applied at the query level (Doctrine
  filter or a mandatory scope on the shared search/repository seam), never
  left to per-repository discipline; voters only authorize the individual
  resource. Collections are pre-filtered in SQL (OWASP API1/BOLA).
- **Pagination interplay** — keyset cursors must be bound to the tenant via
  the signed cursor fingerprint so a cursor minted under one company is
  rejected under another (see the keyset-pagination ADR in
  [`adr-keyset-pagination.md`](./adr-keyset-pagination.md)).

**Commit slices:** `feat(shared): company aggregate + tenant column expand` ·
`feat(shared): tenant-led composite indexes` · `feat(shared): query-level
tenant scoping` · `feat(shared): contract migration (NOT NULL + FK)`.

**Acceptance:** an integration test proves a tenant-scoped query cannot
return another company's rows even when a repository forgets an explicit
filter; every search-exposed column has a tenant-led composite index.

---

## Suggested sequencing

A → B → C → D → E unlock the core "ship safely, never go down, auto-recover"
loop. F (staging split) can run in parallel after B. G is opportunistic.
H (multi-tenant foundation) is independent of the delivery-pipeline phases
and is a **prerequisite for onboarding a second customer**; sequence it
against product needs, and land it before (or together with) the
keyset-pagination ADR's tenant-led indexes so the indexes are built once.

Keep each phase one short-lived branch; one cohesive concern per commit; update
[`PRODUCTION_SECURITY_CHECKLIST.md`](../PRODUCTION_SECURITY_CHECKLIST.md) and
[`docs/deployment-guide.md`](deployment-guide.md) in the same PR that changes
behavior.
