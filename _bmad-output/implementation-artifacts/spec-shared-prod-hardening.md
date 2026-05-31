---
title: 'Production-profile hardening + reproducible erpify.local deploy'
type: 'chore'
created: '2026-05-30'
status: 'draft'
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
- `make/docker.mk` / new `make/deploy.mk` -- new `deploy.local` + `prod.env.check` targets.
- `Makefile` / `make/help.mk` -- include new module, help text.
- `api/frankenphp/Caddyfile` -- confirm `{$CADDY_SERVER_EXTRA_DIRECTIVES}` injection point for `tls internal` (read-only check; no edit unless needed).
- `.gitignore` -- ensure `.env.prod.local` ignored; keep `.env.prod.example` tracked.
- `.env.prod.example` (new, root) -- versioned prod template.
- `scripts/deploy.sh` (new) -- idempotent deploy/validate/smoke.
- `PRODUCTION_SECURITY_CHECKLIST.md` (new, root) -- authoritative checklist.
- `docs/deployment-guide.md`, `docs/erpify-local-test-deployment.md` (new), `docs/claude-code-quickref.md`, `CLAUDE.md`, `api/README.md`, `pwa/README.md` -- docs sync.

## Tasks & Acceptance

**Execution (each bullet ≈ one commit):**
- [ ] `.gitignore` + `.env.prod.example` -- add root prod env template (all required vars, secure placeholders, `openssl rand` hints, `erpify.local` origins) and confirm `.env.prod.local` is ignored. _(commit: `chore(shared): add prod env template`)_
- [ ] `make/config.mk` + new `make/deploy.mk` + `Makefile`/`make/help.mk` -- wire `--env-file $(PROD_ENV_FILE)` for prod/staging; add `prod.env.check` (fails on missing file / unset/placeholder required keys) and `deploy.local`. _(commit: `build(shared): wire prod env-file and deploy targets`)_
- [ ] `compose.yaml` + `compose.prod.yaml` -- neutralize weak prod fallbacks via `${VAR:?msg}` (DATABASE_URL, POSTGRES_PASSWORD, Mercure secret, APP_SECRET) on php/messenger_worker/database; add `frontend`/`backend` networks with `backend: internal`. _(commit: `fix(shared): require prod secrets, isolate db network`)_
- [ ] `compose.prod.yaml` -- runtime hardening: `security_opt no-new-privileges`, `cap_drop: [ALL]` + minimal `cap_add` per service, `read_only` + `tmpfs` where viable, parametrizable `deploy.resources.limits` (`${*_CPU_LIMIT}`/`${*_MEM_LIMIT}` defaults). _(commit: `fix(shared): harden prod container runtime`)_
- [ ] `compose.prod.yaml` -- `SERVER_NAME: ${SERVER_NAME:-erpify.local}`, `CADDY_SERVER_EXTRA_DIRECTIVES: tls internal`, align `DEFAULT_URI`/`MERCURE_PUBLIC_URL`/`NEXT_PUBLIC_SYMFONY_API_BASE_URL` to the prod host. _(commit: `feat(shared): erpify.local internal-TLS prod profile`)_
- [ ] `scripts/deploy.sh` -- idempotent: preflight (docker, env file via `prod.env.check`, `/etc/hosts` warn), `make docker.up ENV=prod` (build+wait), `make db.migrate ENV=prod`, smoke `curl -k https://erpify.local/api/v1/health`, print CA-export + hosts hints. _(commit: `feat(shared): add reproducible deploy script`)_
- [ ] `PRODUCTION_SECURITY_CHECKLIST.md` (new) + docs sync (`docs/deployment-guide.md`, new `docs/erpify-local-test-deployment.md`, `docs/claude-code-quickref.md`, `CLAUDE.md` stack line drift Symfony 7→8, `api/README.md`, `pwa/README.md`). _(commit: `docs(shared): document prod hardening and erpify.local deploy`)_

**Acceptance Criteria:**
- Given `ENV=prod` and a complete `.env.prod.local`, when `make deploy.local`, then the stack reaches healthy, migrations apply, and `https://erpify.local` serves the PWA + `/api/*` over Caddy internal TLS.
- Given any required prod secret unset, when `docker compose` (prod) starts, then it aborts naming the missing var — no weak default is used.
- Given `ENV` unset/`dev`, when `make app.dev`, then dev behavior is unchanged (no `--env-file`, no hardening side effects).
- Given the prod profile, when inspected, then Postgres is on an `internal` network with no published host port, and every service has `no-new-privileges` + dropped caps.
- Given a fresh VPS with a public domain, when `SERVER_NAME` + origins + secrets are set, then the same overlay deploys with real ACME TLS and no compose edits.

## Design Notes

Compose interpolation (`${VAR}` in the compose file) reads only the shell env or `--env-file`/default `.env` — **not** a service's `env_file:`. So prod secrets must flow through `--env-file` wiring in `config.mk`, not `env_file:` on services.

Caddy serves a non-public name; `tls internal` makes it mint a cert from its own CA (no ACME). Clients trust it by importing the root from `/data/caddy/pki/authorities/local/root.crt` (document the `docker compose cp` one-liner). On the VPS, dropping `tls internal` + a public `SERVER_NAME` re-enables automatic ACME — same file.

Minimal caps: postgres ≈ `CHOWN,DAC_OVERRIDE,FOWNER,SETGID,SETUID`; frankenphp/php ≈ `NET_BIND_SERVICE` (binds 80/443). Verify empirically; widen only if a service fails to boot (that is an "Ask First" if it needs more than these).

## Verification

**Commands:**
- `ENV=prod make prod.env.check` -- expected: fails clearly without `.env.prod.local`, passes with a complete one.
- `docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local config` -- expected: renders with no weak defaults; DB has no published ports; networks/caps/limits present.
- `make app.dev` (or `docker compose -f compose.yaml -f compose.dev.yaml config`) -- expected: dev overlay unchanged.
- `bash scripts/deploy.sh` on the test box -- expected: healthy stack, migrations applied, smoke 200.
- `make pwa.quality` / markdown lint on touched `.md` -- expected: clean (no broken links, concrete-file link style).

**Manual checks:**
- From a trusting client, `https://erpify.local` loads PWA + reaches `/api/*`; browser shows valid (internally-trusted) TLS.
- `docker compose ps` shows all services healthy under the prod overlay.
