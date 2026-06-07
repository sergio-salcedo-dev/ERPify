---
title: 'Cierre de 3 items diferidos: core dump FrankenPHP, guard de envelope PWA, aserciones e2e shortName'
type: 'chore' # feature | bugfix | refactor | chore
created: '2026-06-07'
status: 'done' # draft | ready-for-dev | in-progress | in-review | done
baseline_commit: '32954eeddb1c495c1f94ee56892f38581ccea827'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Tres items de `deferred-work.md` siguen abiertos: (1) frankenphp (PID 1 del contenedor `php`) vuelca un `core.1` de ~1 GB en `api/` y se reinicia durante `make php.unit`/`php.behat` (45 reinicios observados en el stack del worktree; `ulimit -c unlimited`); (2) la frontera HTTP del PWA hace blind-cast (`as T`) del envelope paginado de búsqueda — drift de forma aflora como `TypeError` crudo en el mapper; (3) dos specs e2e asertan `shortName` con `.toLocaleUpperCase()` en vez de la regla real de la API (`NormalizedText::toAsciiUpper`, que además quita diacríticos).

**Approach:** Una sola PR desde `main` (decisión explícita del usuario) que: desactiva core dumps en el contenedor vía `ulimits` de Compose + escopa el watcher (`hot_reload`) fuera de `var/` + gitignora `api/core.*`; añade un guard tipado opcional en `HttpClient` y guards manuales para los envelopes de `ApiBankRepository` (error tipado en la frontera, sin deps nuevas); cambia las dos aserciones e2e para asertar contra el valor devuelto por la API (patrón de `banks-containment.spec.ts`); y elimina las tres secciones cerradas de `deferred-work.md` (partiendo del estado local sin commitear del checkout principal, que incluye dos de las tres secciones).

## Boundaries & Constraints

**Always:** Trabajar en un worktree nuevo (`make worktree.create BRANCH=chore/close-deferred-coredump-envelope-e2e`); copiar el `deferred-work.md` local (no commiteado) del checkout principal al worktree antes de editarlo; commits convencionales separados por concern; verificar la versión pinneada de FrankenPHP antes de usar sintaxis de `hot_reload`/`watch` (docs vía context7); PR única contra `main`, sin merge.

**Ask First:** Eliminar `hot_reload` por completo si no admite escopado de rutas (degrada DX de dev); cualquier cambio que afecte al stage prod del Dockerfile más allá de `ulimits`.

**Never:** Commitear `api/config/reference.php` (auto-generado, está sucio en el checkout principal); tocar `banks-realtime.spec.ts` (sus `.toLocaleUpperCase()` son seeding, no aserciones — la API canonicaliza); añadir zod/valibot al guard (zod existe pero solo para forms; el patrón de frontera es guard manual tipo `isProblemDetails`); tocar las demás secciones de `deferred-work.md`; ensanchar CSP ni headers.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Envelope válido | 200 con `{data: [...], pagination: {cursor, hasMorePages}}` | `search()` devuelve `BankSearchPage` como hoy | N/A |
| Envelope malformado | 200 con `{data: null}` o sin `pagination` | Error tipado en la frontera (HttpError con `problem.type: "malformed-response-envelope"`) | Lanzado por `FetchHttpClient` cuando el guard falla; UI lo trata como HttpError existente |
| Envelope single malformado | 200 de `find/create/update` sin `data` objeto | Mismo error tipado | Ídem |
| Test run con fix | `make php.unit` en el stack del worktree | Sin `core.*` en `api/`, sin reinicio del contenedor `php` | N/A |
| shortName con diacríticos | Seed e2e (hipotético no-ASCII) | Aserción usa el valor persistido devuelto por la API | N/A |

</frozen-after-approval>

## Code Map

- `compose.yaml` -- servicio `php` (y `messenger_worker` si comparte imagen): añadir `ulimits.core: {soft: 0, hard: 0}`
- `compose.dev.yaml:32-34` -- `FRANKENPHP_WORKER_CONFIG` (ya escopado a `src/**/*.php`) y `FRANKENPHP_SITE_CONFIG` (default `hot_reload`, sin escopar — culpable probable del watcher sobre `var/`)
- `api/Dockerfile:72,97` -- `ENV FRANKENPHP_WORKER_CONFIG=watch` (bare = vigila todo `/app/api`; alinear con el default escopado) y CMD dev con `--watch` (config-watch de Caddy, inofensivo)
- `api/frankenphp/Caddyfile:85` -- `{$FRANKENPHP_SITE_CONFIG}` dentro del bloque `php`
- `api/.gitignore` -- añadir `/core.*`
- `pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts:113,159` -- blind cast `as T` en `get`/`post`/`put`; `toHttpError` + `isProblemDetails` son el patrón a seguir
- `pwa/src/context/shared/infrastructure/HttpClient/HttpError.ts` -- error de frontera existente (lleva `ProblemDetails`)
- `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts:7-58` -- `BankSearchResponse`/`BankSingleResponse` y los 4 métodos que desempaquetan sin guard
- `pwa/tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts` + `pwa/tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts` -- suites a extender
- `pwa/tests/e2e/backoffice/banks-real-api.spec.ts:131,150-151` y `banks-real-api-flows.spec.ts:201,220` -- aserciones con `.toLocaleUpperCase()`
- `pwa/tests/e2e/backoffice/banks-containment.spec.ts:156` y `pwa/tests/e2e/fixtures/banks-real-api.ts:60-74` -- patrón correcto: `createBank` devuelve el `ApiBank` persistido; asertar `bank.shortName`
- `_bmad-output/implementation-artifacts/deferred-work.md` -- eliminar las 3 secciones cerradas (partir del estado local del checkout principal)

## Tasks & Acceptance

**Execution:**
- [x] `compose.yaml` -- añadir `ulimits: {core: {soft: 0, hard: 0}}` al servicio `php` (y `messenger_worker` si está definido aparte) -- corta el core dump de raíz en dev/prod/ci/worktrees
- [x] `api/Dockerfile` -- cambiar `ENV FRANKENPHP_WORKER_CONFIG=watch` por el valor escopado `watch /app/api/src/**/*.php` -- elimina el default bare-watch latente
- [x] `compose.dev.yaml` (o `Caddyfile`) -- escopar `hot_reload` fuera de `var/` según la sintaxis soportada por la versión pinneada de FrankenPHP (verificar docs); si no admite rutas → Ask First -- evita el storm del watcher en test runs
- [x] `api/.gitignore` -- añadir `/core.*` -- guardarraíl si algo vuelve a volcar
- [x] `pwa/.../HttpClient/HttpClient.ts` -- añadir param opcional `validate?: (body: unknown) => body is T` a `get`/`post`/`put` (interface + Fetch + Mock); si falla, lanzar `HttpError` con ProblemDetails sintetizado `type: "malformed-response-envelope"` (mismo molde que `toHttpError`) -- error tipado en la costura, sin tocar call sites existentes
- [x] `pwa/.../bank/infrastructure/ApiBankRepository.ts` -- guards manuales `isBankSearchResponse`/`isBankSingleResponse` (colocados junto a sus interfaces) y pasarlos en los 4 métodos que desempaquetan -- cierra el blind-trust en `data`/`pagination`
- [x] `pwa/tests/.../FetchHttpClient.test.ts` + `ApiBankRepository.test.ts` -- casos de la matriz I/O (envelope malformado → error tipado; válido → comportamiento intacto) -- cubre el gap actual
- [x] `pwa/tests/e2e/backoffice/banks-real-api.spec.ts` + `banks-real-api-flows.spec.ts` -- quitar `.toLocaleUpperCase()` del valor aserado; tras el create, obtener el bank persistido (`api.get` con el id de la URL, ya capturado en flows) y asertar `data.shortName` -- aserción contra la regla real de la API
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` -- copiar el estado local del checkout principal y eliminar exactamente las secciones «FrankenPHP core dump…», «Deferred from: code review of feat/api-flat-pagination-envelope-pa5z…» y «From: code review of fix/pwa-e2e-tooltip-and-test-types…» -- cierre documental
- [x] Docs -- nota breve del `ulimits`/watcher donde corresponda (`docs/claude-code-quickref.md` gotcha; `docs/deployment-guide.md` si el cambio toca prod) -- regla "keeping docs up to date" de CLAUDE.md

**Acceptance Criteria:**
- Given el stack del worktree levantado, when corre `make php.unit` y `make php.behat`, then no aparece `api/core.*`, `docker inspect` no incrementa `RestartCount` del `php` y `ulimit -c` dentro del contenedor es `0`
- Given un 200 con envelope sin `pagination`, when `ApiBankRepository.search()`, then lanza `HttpError` con `problem.type = "malformed-response-envelope"` (no `TypeError`)
- Given los specs e2e modificados, when corren en CI, then las aserciones de shortName usan el valor devuelto por la API y la suite pasa
- Given la PR mergeada, when se lee `deferred-work.md`, then las 3 secciones cerradas no existen y el resto queda intacto

## Spec Change Log

## Design Notes

- El guard va en la costura `HttpClient` (param opcional) y no dentro del repo: detección temprana, un solo tipo de error para toda forma inválida, y los repos declaran su contrato pasando el guard. Reusar `HttpError` (no una clase nueva) mantiene el manejo uniforme aguas arriba; `problem.type` discrimina.
- e2e no corre en el host (sin browsers Playwright para ubuntu26.04) — verificación local = unit + quality + curl; e2e se valida en la CI de la PR.
- `--watch` del CMD dev es el config-watch de Caddy (vigila el Caddyfile), no el watcher de PHP; no tocarlo.

## Verification

**Commands:**
- `make pwa.quality` -- expected: ESLint + Prettier limpios
- `make pwa.test.unit` -- expected: suites de HttpClient/ApiBankRepository en verde incluyendo los casos nuevos
- `make php.unit && make php.behat` (en el worktree, stack arriba) -- expected: verde, sin `api/core.*`, sin reinicio del contenedor `php`
- `docker compose exec php sh -c 'ulimit -c'` (en el worktree) -- expected: `0`
- `npx tsc --noEmit --incremental false` (en `pwa/`, o en contenedor) -- expected: exit 0 (los e2e specs no los typechequea `next build`)

**Manual checks (if no CLI):**
- Hot-reload de dev sigue funcionando tras escopar `hot_reload` (editar un PHP en `src/` y ver el reload)
- `git status` del worktree no incluye `api/config/reference.php`

## Suggested Review Order

**Guard de envelope en la frontera HTTP (la pieza de diseño)**

- La costura: `ResponseGuard<T>` opcional en el contrato `HttpClient`; opt-in, sin romper call sites
  [`HttpClient.ts:9`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L9)

- `parseBody`: guard rechazado → `HttpError` tipado (`malformed-response-envelope`), mismo molde que `toHttpError`
  [`HttpClient.ts:177`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L177)

- Guards manuales del repo (patrón `isProblemDetails`, sin zod) aplicados en los 4 métodos
  [`ApiBankRepository.ts:34`](../../pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts#L34)

**Core dump de FrankenPHP (infra)**

- Causa raíz: `hot_reload` sin escopar vigilaba todo `/app/api`; ahora `src/` + `config/` (sin brace-globs — `${VAR-default}` corta en la primera `}`)
  [`compose.dev.yaml:38`](../../compose.dev.yaml#L38)

- Cinturón y tirantes: `ulimits.core: 0` en `php` y `messenger_worker`, todos los entornos
  [`compose.yaml:8`](../../compose.yaml#L8)

- Default de imagen alineado: el `watch` pelado del stage dev también queda escopado
  [`Dockerfile:74`](../../api/Dockerfile#L74)

- Guardarraíl final: `api/core.*` gitignorado
  [`.gitignore:36`](../../api/.gitignore#L36)

**Aserciones e2e contra el valor canónico de la API**

- El probe GET devuelve el `shortName` persistido (`toAsciiUpper`); la aserción deja de aproximar con `toLocaleUpperCase()`
  [`banks-real-api.spec.ts:151`](../../pwa/tests/e2e/backoffice/banks-real-api.spec.ts#L151)

- Mismo patrón en el flujo inline (reutiliza el probe que ya existía)
  [`banks-real-api-flows.spec.ts:224`](../../pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts#L224)

**Periféricos**

- Matriz I/O del guard: malformado, no-JSON, 204-con-guard, passthrough sin guard
  [`FetchHttpClient.test.ts:143`](../../pwa/tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts#L143)

- Los guards reales verificados a través de la costura pública del mock
  [`ApiBankRepository.test.ts:68`](../../pwa/tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts#L68)

- Gotcha documentado (quickref) y nota de hardening (deployment guide)
  [`claude-code-quickref.md:223`](../../docs/claude-code-quickref.md#L223)

- Cierre documental: las 3 secciones eliminadas de deferred-work
  [`deferred-work.md:1`](deferred-work.md#L1)
