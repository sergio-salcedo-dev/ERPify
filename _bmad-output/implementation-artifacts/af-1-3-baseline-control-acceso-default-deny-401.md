---
title: 'AF-1.3 · Baseline de control de acceso — default-deny + 401 por el pipeline'
type: 'feature'
created: '2026-07-03'
baseline_commit: '1bcd5e2c30ebf512bcfbadfaef4ae9b08e93ae7e'
status: 'in-review'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-auth-foundation-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/af-1-2-firewall-sesion-securityuser-authenticator-csrf.md'
  - '{project-root}/api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php'
  - '{project-root}/api/src/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponder.php'
  - '{project-root}/api/config/packages/security.yaml'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** AF-1.2 aterrizó el firewall de sesión, pero **todo `/api/*` sigue siendo público**: cualquiera lista
bancos, cuentas o el trail de auditoría sin autenticarse. El riesgo no es de modelo, es de configuración. Y de
paso: el módulo `Frontoffice/Mercure` (bootstrap + publish-demo) es **scaffolding demo muerto** — sin consumidor
vivo (la PWA hace realtime contra el hub `/.well-known/mercure`, no contra esas rutas), que default-deny
obligaría a allowlistear o romper su test.

**Approach:** (1) `access_control` **default-deny** con allowlist mínima; una request no autenticada dispara
`AccessDeniedException` (que el pipeline daría como 403), así que un carve-out en `kernel.exception` reescribe —
**solo** para token no autenticado — a `AuthenticationException` → **401 `unauthenticated`** por el arm existente.
(2) **Borrar el módulo demo `Frontoffice/Mercure`** y su rastro (verificado dead), dejando el allowlist limpio.
Las 2 rutas de audit quedan listas para `#[IsGranted]` de E3.

## Boundaries & Constraints

**Always:**
- **Allowlist mínima** (cada entrada con consumidor no-auth verificado): `^/api/v1/backoffice/login$`
  (json_login), `^/api/v1/backoffice/health` (+`/database`) y `^/api/v1/health$` (CI/monitoreo), `^/api/v1/dev/`
  (la PWA lo fetchea en cada carga) → `PUBLIC_ACCESS`. Catch-all **`^/api` → `IS_AUTHENTICATED_FULLY` en última
  posición** (primer-match-gana; **no** `^/` — deja `/_dev`, `/_wdt`, `/.well-known/mercure` y el proxy PWA
  intactos). Media/Storage y las rutas Mercure demo **NO** se allowlistean.
- No-auth a ruta protegida ⇒ **401 `application/problem+json` `type: unauthenticated`** por el pipeline; el body
  lo construye el arm `AuthenticationException` **ya existente** — el carve-out **solo** reescribe el throwable
  (`$event->setThrowable`, prio **> 32** sobre `AccessDeniedAuditListener`), gated por `ApiRequestMatcher` +
  main-request, y **solo** si el token no está *fully authenticated* (`AuthenticationTrustResolver`);
  autenticado-sin-rol (E3) pasa intacto ⇒ 403. La `AuthenticationException` emitida **sin** `previous`.
- **Borrado Mercure demo:** eliminar `src/Frontoffice/Mercure/` (topic + 2 controllers) + sus 2 tests + layers
  deptrac + regla nelmio `^/api/v1/mercure/` (demo-only) + guard `mercure` de `AuditPolicy` (+docblock) + 2 yields
  de `AuditPolicyTest` + docs stale + entradas Postman. **KEEP intactos** (los usa el realtime REAL):
  `mercure.yaml`, `mercure_subscribe.yaml` (`subscribe:'*'` lo consumen `Bank/BankAccountRealtimeAuthorizeController`),
  el hub Caddy `/.well-known/mercure`, y **todos los steps Behat `Mercure update ...`** (prueban el realtime real).
- Symfony Security confinado a `Infrastructure/`; `deptrac` + `bounded-context` + `error-contract` verdes.
  Las 2 rutas de audit **sin** `#[IsGranted]` (frontera E3).

**Never:**
- `#[IsGranted]`/voter/swap de `ActorContextFactory` → **E3/3.1**. Login UI PWA → aguas abajo.
- Tocar `ProblemDetailsFactory` (arm 401/403 constant-time, pinneado por `ConstantTimeAuthBranchingContractTest`)
  ni `ExceptionResponder` (prio 16 pinneada). Borrar `mercure.yaml`/`mercure_subscribe.yaml`/el hub, o tocar los
  `*RealtimeAuthorizeController` o los steps Behat de Mercure. Un `entry_point` que emita `JsonResponse` propia.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| No-auth a protegida | `GET /api/v1/backoffice/banks` sin sesión | **401** `problem+json` `type: unauthenticated` | carve-out reescribe → pipeline |
| Autenticado a protegida | Alice logueada (cookie) → `GET /banks` | **200** (default-deny satisfecho; sin rol exigido) | N/A |
| Ruta pública sin auth | `POST /login`, `GET /health`, `GET /backoffice/health`, `GET /dev/frankenphp-hot-reload` | alcanzable | N/A |
| Autenticado-sin-rol | (E3) token válido, ruta con rol faltante | 403 `forbidden` (no reescrito) | pipeline (arm existente) |
| Realtime real intacto | PWA autenticada → `BrowserMercureSubscriber` → `/.well-known/mercure` | funciona (hub no tocado) | N/A |

</frozen-after-approval>

## Code Map

**Baseline de acceso:**
- `api/config/packages/security.yaml` -- añadir `access_control` (allowlist + catch-all). **Sin** `entry_point`.
- `…/Backoffice/Identity/Infrastructure/Security/UnauthenticatedAccessListener.php` -- **NUEVO** carve-out
  `#[AsEventListener(KernelEvents::EXCEPTION, priority: 40)]`; inyecta `TokenStorageInterface`,
  `AuthenticationTrustResolverInterface`, `ApiRequestMatcher`.
- `…/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponder.php` -- **solo lectura** (prio 16;
  mapea el throwable reescrito → 401).
- `…/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php` -- **solo lectura** (prio 32).
- `…/Backoffice/Audit/Infrastructure/Controller/{AuditTimelineSearch,AuditEventDetail}Controller.php` -- **solo
  lectura** (sin `#[IsGranted]`, listos para E3).

**Borrado Mercure demo (verificado dead):**
- `api/src/Frontoffice/Mercure/` -- **BORRAR** (`Domain/MercureDemoTopic.php`,
  `Infrastructure/Controller/{MercureBootstrap,MercurePublishDemo}Controller.php`).
- `api/tests/{Functional,Unit}/Frontoffice/Mercure/**` -- **BORRAR** (2 tests).
- `api/tools/deptrac/deptrac.yaml` -- quitar layers/ruleset `Frontoffice.Mercure.{Domain,Infrastructure}`.
- `api/config/packages/nelmio_cors.php` -- quitar la regla `^/api/v1/mercure/` (demo-only; tightening).
- `api/src/Shared/Audit/Domain/AuditPolicy.php` -- quitar `\str_contains($route,'mercure')` (dead) + su mención
  en el docblock.
- `api/tests/Unit/Shared/Audit/Domain/AuditPolicyTest.php` -- quitar los yields `mercure bootstrap` + `publish demo`.
- `docs-info/mercure.md`, `docs-info/mercure-production-deployment.md` -- purgar contenido demo/stale (dejar hub real).
- `api/docs/postman/erpify-api.postman_collection.json` + `README.md` -- quitar las 2 requests/mención demo.

## Tasks & Acceptance

**Execution — baseline de acceso:**
- [x] `security.yaml` -- `access_control`: `PUBLIC_ACCESS` para `^/api/v1/backoffice/login$`,
  `^/api/v1/backoffice/health`, `^/api/v1/health$`, `^/api/v1/dev/`; catch-all `^/api` → `IS_AUTHENTICATED_FULLY`
  (última).
- [x] `UnauthenticatedAccessListener.php` -- gate (`isMainRequest` + `ApiRequestMatcher`); busca
  `AccessDeniedException` en la cadena; si token no *fully authenticated* → `setThrowable(new
  InsufficientAuthenticationException('Authentication required.'))` sin `previous`; autenticado → no-op.
- [x] **Runtime-verify primero** (curl/Behat): no-auth→**401** (no 403) antes de seguir. Fallback si el ordering
  fallara: `entry_point` custom que enrute por el pipeline.
- [x] `tests/Unit/.../UnauthenticatedAccessListenerTest.php` -- no-auth→reescribe; autenticado→no-op;
  no-AccessDenied→no-op; no-`/api`→no-op.
- [x] `features/backoffice/identity/access-control.feature` -- no-auth→401; login Alice→sesión→`GET`
  protegida→200 (cierra la AC diferida de AF-1.2); ruta pública alcanzable sin auth.

**Execution — borrado Mercure demo:**
- [x] Borrar `src/Frontoffice/Mercure/` + los 2 tests; verificar 0 refs colgando (`git grep -i mercuredemo`,
  `MercureBootstrap`, `MercurePublishDemo`).
- [x] `deptrac.yaml` -- quitar los bloques `Frontoffice.Mercure.*`; `nelmio_cors.php` -- quitar `^/api/v1/mercure/`.
- [x] `AuditPolicy.php` + `AuditPolicyTest.php` -- quitar el guard `mercure` (dead) + docblock + 2 yields.
- [x] `docs-info/mercure*.md` + Postman (`json`+`README.md`) -- purgar demo/stale; **no** tocar el hub real.

**Execution — docs & sprint:**
- [x] `docs/rules/security.md` + `PRODUCTION_SECURITY_CHECKLIST.md` -- default-deny + allowlist + carve-out 401 +
  retirada de la CORS `^/api/v1/mercure/`. `docs/architecture-api.md` -- orden del pipeline (40→32→16).
  **`docs/api-error-contract.md` SIN cambio** (la fila 401 + el puente ya existen).
- [x] `sprint-status.yaml` -- `af-1-3` → `in-review`; **corregir `af-1-2` → `done`** (#429 mergeado).

**Acceptance Criteria:**
- Given una ruta protegida, when request sin sesión, then **401 `problem+json` `type: unauthenticated`** por el
  pipeline (arm existente), sin `JsonResponse` manual ni sesión.
- Given una sesión válida (login Alice), when `GET` a ruta protegida, then **200** — el actor resuelve a
  `SecurityUser` con sus `ROLE_*` (cierra AC1-2ª de AF-1.2).
- Given las rutas públicas de la allowlist, when se acceden sin auth, then alcanzan su controller.
- Given el borrado Mercure demo, when `make php.quality` + `php.behat`, then verde: deptrac/bounded-context/
  error-contract sin refs a `Frontoffice.Mercure`, y los steps Behat `Mercure update` (realtime real) siguen pasando.
- Given las 2 rutas de audit, then quedan **sin** `#[IsGranted]`; `ActorContextFactory` no se toca.

## Design Notes

- **Carve-out, no `entry_point` (evidencia empírica).** Verificado en el stack: default-deny config-only da
  **403** (no 401) — `ExceptionResponder` (prio 16) mapea la `AccessDeniedException` antes que el firewall
  (prio 1) convierta a 401. Un `entry_point` llegaría tarde y dispararía doble-log + audit-flood de anónimos.
  El carve-out (prio 40) reescribe el throwable antes de ambos ⇒ un único 401, sin flood, reutiliza el arm
  existente, E3-forward. `ProblemDetailsFactory` intacto ⇒ contrato constant-time intacto.
- **Ubicación** `Backoffice/Identity/Infrastructure/Security/` (familia de adapters de AF-1.2); promocionable a
  `Shared/` con 2º consumidor (Regla de Tres). **Sin `previous`:** encadenar la `AccessDeniedException` haría
  que `AccessDeniedAuditListener` (32) auditara el anónimo.
- **Mercure demo = dead verificado.** El `docs-info/mercure.md` estaba stale (documentaba un `MercureDemoPanel`
  y un curl de CI **ya eliminados**). Consumidor vivo de bootstrap/publish-demo = solo sus backend tests. El
  realtime real es ortogonal (hub Caddy + `*RealtimeAuthorizeController` bajo `/backoffice`, que sí necesitan
  `mercure_subscribe.yaml`).

## Verification

**Commands:**
- `make php.stan PHP_SERVICE=messenger_worker` -- 0 errores (workaround segfault web worker).
- `make php.quality` + `make php.psalm.taint` -- EXIT 0 (deptrac/bounded-context/error-contract; dataflow).
- `make php.unit` + `make php.behat` -- verde, incl. `ConstantTimeAuthBranchingContractTest` intacto,
  `access-control.feature`, y los steps `Mercure update` de bank/bankaccount.
- `git grep -inE "MercureDemo|MercureBootstrap|MercurePublishDemo|frontoffice_mercure"` -- 0 hits tras el borrado.
- Live (`curl -k`, puerto de `make docker.ps`): `GET /banks` sin auth → **401** `type: unauthenticated`; login
  Alice → cookie → `GET /banks` → **200**; `GET /health` → 2xx; `GET /mercure/bootstrap` → **404** (borrada).

## Completion Notes

**Carve-out verified live:** default-deny config-only gave 403 (as predicted); `UnauthenticatedAccessListener`
(prio 40) rewrites the anonymous `AccessDeniedException`→`InsufficientAuthenticationException` → **401
`unauthenticated`** by the existing arm. `AuthenticationTrustResolverInterface` needed
`#[Autowire(service: 'security.authentication.trust_resolver')]` (not auto-aliased).

**Test-auth infrastructure (NOT in the frozen intent — surfaced mid-impl, approved by Sergio):** default-deny
applies in `test`, so the whole suite (26 features + 6 functional) would 401. Resolved with the Symfony-native
`loginUser` idiom, not the header-auth of the Chiliz reference bundles (which contradicts D1): Behat
`SecurityContext` auto-authenticates the Alice fixture per scenario unless `@anonymous` (carried by
`login.feature` + `access_control.feature`'s negative scenarios). Functional tests use the
`AuthenticatesFunctionalRequests` trait (idempotent user; this suite has no DAMA rollback). The firewall's
per-request `refreshUser` (`findByEmail`) is a fixed +1 query that shifted `DoctrineContext` budgets → excluded
at the `TestDebugDataHolder` recording seam by table (`identity_user`, auth-only). `^/api/test/*` (the
`when@test` error-pipeline harness, 18 client sites) is allowlisted rather than retrofitted — inert in prod.

**Mercure demo module removed (verified dead, folded per Sergio):** `Frontoffice/Mercure/` (topic + 2
controllers) + 2 tests + deptrac layers + nelmio `^/api/v1/mercure/` + `AuditPolicy` `mercure` guard + 2
`AuditPolicyTest` yields + Postman + `docs-info/mercure*.md` (were stale — documented a PWA panel + CI curl
already deleted). **Kept:** `mercure.yaml`, `mercure_subscribe.yaml` (real realtime's `Authorization::setCookie`),
the Caddy hub, and every Behat `Mercure update` step (real bank/bankaccount realtime).

**Gates (worktree stack) — all green:** `php.stan` 0 · `php.quality` **EXIT 0** (deptrac 0, bounded-context,
error-contract, phpmd 0, cs-fixer, phpcs, rector, gherkinlint) · `php.psalm.taint` **EXIT 0** · `php.unit`
**1491 OK** (3 pre-existing skips) · `php.behat` **197/197** (2006 steps). `ProblemDetailsFactory` /
`ExceptionResponder` untouched. `sprint-status`: `af-1-2`→`done` (stale bit), `af-1-3`→`in-review`.

## Review (step-04 — 3 adversarial reviewers)

**Verdict:** 0 blockers. Verified untouched: `ProblemDetailsFactory`, `ExceptionResponder`, `mercure.yaml`,
`mercure_subscribe.yaml`, the hub, `ActorContextFactory`; audit routes carry no `#[IsGranted]`; carve-out
faithful to ADR D1/D2/D3.

**Patches applied (4):**
- **[MED] over-broad health allowlist** — unanchored `^/api/v1/backoffice/health` would expose any future
  `/health*` route. Tightened to `^/api/v1/backoffice/health(/database)?$` (keeps both probes public by design —
  `architecture-api.md:103` — without the latent prefix trap).
- **[LOW] cycle-guard in `UnauthenticatedAccessListener::isAccessDenied`** — `spl_object_id` seen-set (matching
  `ProblemDetailsFactory`), since this listener walks the chain first at priority 40.
- **[LOW] `isAuthenticated`→`isFullFledged`** — exact match for the `IS_AUTHENTICATED_FULLY` attribute; a future
  remember-me token (not configured today) gets 401, not 403.
- **[cosmetic] import `User`** in `SecurityContext` (drops a rector-left inline FQCN).

**Deferred (1):** `AccessDeniedAuditListener::isAccessDenied` has the same unguarded chain walk (pre-existing) →
`deferred-work.md`.

**Rejected:** chain-walk DRY (2 occurrences, Rule-of-Three unmet), AC2 prose (actor swap is E3; the test
asserts only 200), `^/api/test/` in base vs `when@test` (deliberate — a `when@test` `access_control` block would
replace, not extend, the list).

**Gates after patches:** php.stan 0 · php.quality EXIT 0 · php.unit (listener 6/6) · php.behat **197/197** ·
live curl ✓ (anchored health verified).
