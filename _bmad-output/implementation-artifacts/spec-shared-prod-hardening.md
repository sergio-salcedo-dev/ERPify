---
title: 'Production-profile hardening + reproducible erpify.local deploy'
type: 'chore'
created: '2026-05-30'
status: 'in-progress'  # code review 2026-05-31: 6 patches applied; 2 action items open (pwa read_only tmpfs MEDIUM, reference.php revert LOW)
context:
  - '{project-root}/CLAUDE.md'
  - '{project-root}/docs/project-context.md'
  - '{project-root}/docs/deployment-guide.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The prod Compose profile still falls back to weak inline defaults (`erpify_password`, `!ChangeThis…!`), has no root secret-delivery mechanism wired for Compose interpolation, no container runtime hardening, and no TLS story for a non-public host. There is no reproducible way to stand the stack up with the production profile on a LAN test box reachable at `https://erpify.local`, and `PRODUCTION_SECURITY_CHECKLIST.md` (cited as authoritative by `CLAUDE.md`) does not exist.

**Approach:** Harden the prod overlay (required-secret syntax, `no-new-privileges`, `cap_drop`, internal DB network, parametrizable resource limits, Caddy `tls internal`), deliver prod secrets via a gitignored root `.env.prod.local` (versioned `.env.prod.example` + Make `--env-file` wiring + validation guard), add an idempotent `scripts/deploy.sh` + `make deploy.local` target, and document the whole flow so the same profile carries forward to a VPS by only swapping `SERVER_NAME` to a public domain. Ship in small phased commits.

## Boundaries & Constraints

**Always:** Keep `dev` overlay behavior unchanged (`make app.dev` must still work). Base images stay digest-pinned. Required prod secrets use Compose `${VAR:?msg}` so a missing secret aborts startup — never a weak fallback. The prod profile must be byte-identical between the test box and the VPS except `SERVER_NAME`/origins/secrets. Conventional Commits; one cohesive concern per commit.

**Ask First:** Removing or renaming any existing Make target or compose service; changing app-level Mercure `anonymous`/`subscriptions` semantics; adding a new runtime dependency to any image; anything that would alter the `dev`/`ci` overlay.

**Never:** Commit real secrets or a populated `.env.prod.local`. Unpin base image digests. Introduce Docker Swarm, Kubernetes, or a new orchestration tool. Touch application/domain code, migrations, or `api/src` / `pwa/src`. Broaden CORS to wildcards.

## I/O & Edge-Case Matrix

| Scenario                             | Input / State                                                           | Expected Output / Behavior                                                                                 | Error Handling               |
|--------------------------------------|-------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------|------------------------------|
| Happy deploy                         | `.env.prod.local` present & complete, `erpify.local` in `/etc/hosts`    | `make deploy.local` brings stack up (ENV=prod), migrates, smoke test passes, prints CA-trust + hosts hints | N/A                          |
| Missing secret file                  | `.env.prod.local` absent                                                | Guard fails fast with copy-from-example instruction; stack not started                                     | Exit non-zero, clear message |
| Placeholder secret left              | `.env.prod.local` still holds an example placeholder/empty required var | Guard rejects before `up`; lists offending keys                                                            | Exit non-zero                |
| Missing required var at compose time | secret var unset despite file                                           | `${VAR:?}` aborts `docker compose` with the var name                                                       | Compose exits non-zero       |
| Hosts entry missing                  | `erpify.local` not resolvable                                           | Deploy warns (non-fatal) with the exact `/etc/hosts` line to add                                           | Warning, continues           |

</frozen-after-approval>

## Code Map

- `compose.yaml` -- base stack; holds weak inline defaults to neutralize on the prod path; add named networks.
- `compose.prod.yaml` -- prod overlay; target for required-secret syntax, hardening, `tls internal`, `erpify.local` defaults, resource limits, internal DB net.
- `compose.dev.yaml` -- dev overlay; must stay functionally unchanged (verify only).
- `make/config.mk` -- `DOCKER_COMPOSE` wiring; add `--env-file` for prod/staging + secret-file path var.
- `make/docker.mk` / new `make/deploy.mk` -- new `deploy.local`, `deploy.local.trust` + `prod.env.check` targets.
- `Makefile` / `make/help.mk` -- include new module, help text.
- `api/frankenphp/Caddyfile` -- confirm `{$CADDY_SERVER_EXTRA_DIRECTIVES}` injection point for `tls internal` (read-only check; no edit unless needed).
- `.gitignore` -- ensure `.env.prod.local` ignored; keep `.env.prod.example` tracked.
- `.env.prod.example` (new, root) -- versioned prod template.
- `scripts/deploy/deploy-local.sh` (new) -- idempotent deploy/validate/smoke; run via `make deploy.local`.
- `scripts/deploy/trust-local.sh` (new) -- privileged client CA-trust helper; run via `sudo make deploy.local.trust`.
- `scripts/deploy/deploy.sh` + `scripts/deploy/README.md` (pre-existing) -- day-2 deploy orchestrator (`--simple/--advanced/--ci/--check`); reconciled to the prod-env model (was calling dev-context + non-existent make targets).
- `PRODUCTION_SECURITY_CHECKLIST.md` (new, root) -- authoritative checklist.
- `docs/deployment-guide.md`, `docs/erpify-local-test-deployment.md` (new), `docs/claude-code-quickref.md`, `CLAUDE.md`, `api/README.md`, `pwa/README.md` -- docs sync.

## Tasks & Acceptance

**Execution (each bullet ≈ one commit):**
- [x] `.gitignore` + `.env.prod.example` -- add root prod env template (all required vars, secure placeholders, `openssl rand` hints, `erpify.local` origins) and confirm `.env.prod.local` is ignored. _(commit: `chore(shared): add prod env template`)_
- [x] `make/config.mk` + new `make/deploy.mk` + `Makefile`/`make/help.mk` -- wire `--env-file $(PROD_ENV_FILE)` for prod/staging; add `prod.env.check` (fails on missing file / unset/placeholder required keys) and `deploy.local`. _(commit: `build(shared): wire prod env-file and deploy targets`)_
- [x] `compose.yaml` + `compose.prod.yaml` -- neutralize weak prod fallbacks via `${VAR:?msg}` (DATABASE_URL, POSTGRES_PASSWORD, Mercure secret, APP_SECRET) on php/messenger_worker/database; add `frontend`/`backend` networks with `backend: internal`. _(commit: `fix(shared): require prod secrets, isolate db network`)_
- [x] `compose.prod.yaml` -- runtime hardening: `security_opt no-new-privileges`, `cap_drop: [ALL]` + minimal `cap_add` per service, `read_only` + `tmpfs` where viable, parametrizable `deploy.resources.limits` (`${*_CPU_LIMIT}`/`${*_MEM_LIMIT}` defaults). _(commit: `fix(shared): harden prod container runtime`)_
- [x] `compose.prod.yaml` -- `SERVER_NAME: ${SERVER_NAME:-erpify.local}`, `CADDY_SERVER_EXTRA_DIRECTIVES: tls internal`, align `DEFAULT_URI`/`MERCURE_PUBLIC_URL`/`NEXT_PUBLIC_SYMFONY_API_BASE_URL` to the prod host. _(commit: `feat(shared): erpify.local internal-TLS prod profile`)_
- [x] `scripts/deploy/deploy-local.sh` (run via `make deploy.local`) -- idempotent: preflight (docker, env file via `prod.env.check`, `/etc/hosts` warn), `make docker.up.wait ENV=prod` (build+wait), `make db.migrate ENV=prod`, smoke `curl -k https://erpify.local/api/v1/health`, print CA-export + hosts hints. _(commit: `feat(shared): add reproducible deploy script`)_
- [x] `scripts/deploy/trust-local.sh` + `make deploy.local.trust` -- privileged client-trust helper: append `erpify.local` to `/etc/hosts`, install Caddy's exported root CA into the system trust store and the Chromium/Firefox NSS DBs so clients see valid TLS without `-k`; discloses every OS file it touches. _(commit: `feat(shared): one-command CA trust helper with OS-file disclosure`)_
- [x] `scripts/deploy/deploy.sh` + README -- reconcile the pre-existing day-2 orchestrator with the prod-env model: pass `ENV=$DEPLOY_ENV` (default prod) to every `make` call, fix broken target names (`cache.warmup`→`sf.cache.warmup`, `messenger.stop-workers`→`sf.messenger.stop-workers`), derive health URL from `SERVER_NAME`, run `prod.env.check` preflight for prod/staging. _(commit: `fix(shared): reconcile deploy.sh with prod env model`)_
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` (new) + docs sync (`docs/deployment-guide.md`, new `docs/erpify-local-test-deployment.md`, `docs/claude-code-quickref.md`, `CLAUDE.md` stack line drift Symfony 7→8, `api/README.md`, `pwa/README.md`). _(commit: `docs(shared): document prod hardening and erpify.local deploy`)_

**Acceptance Criteria:**
- Given `ENV=prod` and a complete `.env.prod.local`, when `make deploy.local`, then the stack reaches healthy, migrations apply, and `https://erpify.local` serves the PWA + `/api/*` over Caddy internal TLS.
- Given any required prod secret unset, when `docker compose` (prod) starts, then it aborts naming the missing var — no weak default is used.
- Given `ENV` unset/`dev`, when `make app.dev`, then dev behavior is unchanged (no `--env-file`, no hardening side effects).
- Given the prod profile, when inspected, then Postgres is on an `internal` network with no published host port, and every service has `no-new-privileges` + dropped caps.
- Given a fresh VPS with a public domain, when `SERVER_NAME` + origins + secrets are set, then the same overlay deploys with real ACME TLS and no compose edits.
- Given `DEPLOY_ENV=prod ./scripts/deploy/deploy.sh`, when it runs, then it validates `.env.prod.local` via `prod.env.check` first and every `make` call targets the prod overlay (`ENV=prod`) using the correct `sf.*` targets — no dev-context fallback, no "no rule to make target".
- Given a trusting client, when `sudo make deploy.local.trust` runs, then `erpify.local` resolves and the exported Caddy root CA is installed into the system trust store + the Chromium-family NSS store, so `https://erpify.local` validates without `-k`; Firefox uses its own per-profile store and is imported via GUI (it cannot be reliably scripted); the helper discloses every OS file it modifies.

## Design Notes

Compose interpolation (`${VAR}` in the compose file) reads only the shell env or `--env-file`/default `.env` — **not** a service's `env_file:`. So prod secrets must flow through `--env-file` wiring in `config.mk`, not `env_file:` on services.

Caddy serves a non-public name; `tls internal` makes it mint a cert from its own CA (no ACME). Clients trust it by importing the root from `/data/caddy/pki/authorities/local/root.crt` (document the `docker compose cp` one-liner). On the VPS, dropping `tls internal` + a public `SERVER_NAME` re-enables automatic ACME — same file.

Minimal caps: postgres ≈ `CHOWN,DAC_OVERRIDE,FOWNER,SETGID,SETUID`; frankenphp/php ≈ `NET_BIND_SERVICE` (binds 80/443). Verify empirically; widen only if a service fails to boot (that is an "Ask First" if it needs more than these).

## Verification

**Commands:**
- `ENV=prod make prod.env.check` -- expected: fails clearly without `.env.prod.local`, passes with a complete one.
- `docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local config` -- expected: renders with no weak defaults; DB has no published ports; networks/caps/limits present.
- `make app.dev` (or `docker compose -f compose.yaml -f compose.dev.yaml config`) -- expected: dev overlay unchanged.
- `make deploy.local` (runs `scripts/deploy/deploy-local.sh`) on the test box -- expected: healthy stack, migrations applied, smoke 200. Then `sudo make deploy.local.trust` for client TLS trust.
- `make pwa.quality` / markdown lint on touched `.md` -- expected: clean (no broken links, concrete-file link style).

**Manual checks:**
- From a trusting client, `https://erpify.local` loads PWA + reaches `/api/*`; browser shows valid (internally-trusted) TLS.
- `docker compose ps` shows all services healthy under the prod overlay.

## Review Findings

_Adversarial code review (Blind Hunter + Edge Case Hunter + Acceptance Auditor), 2026-05-31 — branch `feat/shared-prod-hardening` vs `main`._

### Decision needed

- [x] [Review][Decision] **RESOLVED (AC7 text reconciled)** — AC7 vs implementation: helper does not install into Firefox NSS — AC7 states `deploy.local.trust` installs the CA into "Chromium/**Firefox** NSS trust stores", but `scripts/deploy/trust-local.sh` deliberately touches only the system bundle + Chromium NSS (`~/.pki/nssdb`) and leaves Firefox GUI-only (trust-local.sh:1331-1332,1449-1451). Defensible (Firefox's per-profile store can't be reliably scripted), but the AC wording is unmet. Resolve by reconciling the AC text or implementing Firefox NSS scripting.

### Patch

- [x] [Review][Patch][HIGH] **APPLIED** — Client-trust phase is fatal on a missing internal CA — on a VPS (ACME, `CADDY_SERVER_EXTRA_DIRECTIVES=` empty) there is no CA at `/data/caddy/pki/authorities/local/root.crt`, so `docker compose cp` inside `run()` (eval under `set -euo pipefail`) aborts *after* a healthy deploy. The VPS doc tells operators to run `make deploy.local`, which cannot pass `--no-trust`. Make the whole trust phase non-fatal (`|| log_warning`) and/or auto-skip when the directive is empty. [scripts/deploy/deploy-local.sh:43-52,129-130; docs/erpify-local-test-deployment.md:184]
- [ ] [Review][Patch][MEDIUM] `pwa` `read_only` + only `/tmp` tmpfs → EROFS on `.next/cache` — any ISR / data-cache / on-demand-revalidate / image-optimization route makes Next write under `/app/pwa/.next/cache`, which is read-only; fails at request time (passes the health gate). Verify the PWA's caching mode, then add `/app/pwa/.next/cache` to the `pwa` tmpfs list. [compose.prod.yaml (pwa service)]
- [x] [Review][Patch][MEDIUM] **APPLIED** — `deploy.sh` accepts arbitrary `DEPLOY_ENV` → silent dev deploy — a typo (`production`, `PROD`) skips the `prod.env.check` preflight and `--env-file` wiring (which key on exact `prod`/`staging`) and falls through to the dev overlay with weak fallbacks. Validate `DEPLOY_ENV ∈ {dev,ci,staging,prod}` and fail fast. [scripts/deploy/deploy.sh]
- [x] [Review][Patch][MEDIUM] **APPLIED** — `prod.env.check` does not enforce `POSTGRES_PASSWORD` URL-safety — a `/ + = : @` char (e.g. `openssl rand -base64`) passes the guard then crashes php boot with `MalformedDsnException` via `DATABASE_URL`. Documented in troubleshooting but not guarded. Reject non-URL-safe `POSTGRES_PASSWORD`. [make/deploy.mk:24-28]
- [ ] [Review][Patch][LOW] `api/config/reference.php` modified despite CLAUDE.md "Do not touch" (auto-generated) — spurious `// Default: null` comment reorder. Revert to `main`. [api/config/reference.php:944-945]
- [x] [Review][Patch][LOW] **APPLIED** — `prod.env.check` placeholder/empty detection weaknesses — `grep -q 'CHANGE_ME'` matches the substring anywhere (rejects a legit secret containing it); a whitespace-only or CRLF (`\r`) value passes `[ -z ]` and survives into `DATABASE_URL`/`SERVER_NAME`. Anchor (`^CHANGE_ME`) and strip `\r` + re-test after trimming. [make/deploy.mk:25-26]
- [x] [Review][Patch][LOW] **APPLIED** — Hosts preflight reports false `HOSTS_OK` — `getent hosts` resolves `*.local` via mDNS/avahi even without an `/etc/hosts` entry, and a conflicting-IP entry isn't detected. Rely on the `/etc/hosts` grep / assert the resolved address is `127.0.0.1` locally. [scripts/deploy/deploy-local.sh:86]

### Deferred

- [x] [Review][Defer] Spec Task 3 / Code Map claim a `compose.yaml` change that never happened — all secret-neutralization + named networks landed only in `compose.prod.yaml`; outcome is correct/cleaner, spec text is inaccurate. — spec hygiene
- [x] [Review][Defer] Two uncatalogued `api/` edits — `api/frankenphp/docker-entrypoint.sh` (functional, verified correct & necessary for prod boot; guards composer reconcile behind `command -v composer`) and `reference.php` (see patch). Not in Code Map/Tasks; acknowledge in PR description. — out of declared scope
- [x] [Review][Defer] `trust-local.sh` assumes `runuser` present — on a minimal/busybox host the NSS step aborts mid-trust after touching `/etc/hosts` + system CA. [scripts/deploy/trust-local.sh] — narrow host edge case
- [x] [Review][Defer] `CADDY_SERVER_EXTRA_DIRECTIVES` single-dash default is correct (empty→ACME) but *deleting* the line silently re-enables `tls internal` on a VPS — add a guard comment in `.env.prod.example`. [compose.prod.yaml:500] — robustness/doc
- [x] [Review][Defer] Smoke-test retry window ~30s — `docker.up.wait --wait` already health-gates before smoke so likely sufficient; extend only if cold prod boots exceed it. [scripts/deploy/deploy-local.sh:110-115] — tuning

### Dismissed (noise / false positive / handled)

- `deploy.resources.limits` "ignored by `docker compose up`" (claimed HIGH) — false positive: Compose **v2** (used here, `docker compose ... up --wait`) honors `deploy.resources.limits` cpus/memory; `--compatibility` was a v1 requirement. Blind Hunter lacked the v2 context.
- macOS next-steps never collapse; `-h` help dumps banner lines — cosmetic.
- `eval` of `$PROD_ENV_FILE`/`$ENV_FILE` — operator-supplied, negligible risk.
- `logname`/`SUDO_USER` unset; DB healthcheck/connectivity; worker-on-`frontend`; `deploy.local.trust` sudo guard — verified handled in the diff or surrounding files.
