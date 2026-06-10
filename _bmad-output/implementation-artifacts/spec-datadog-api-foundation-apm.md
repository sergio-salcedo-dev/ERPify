---
title: 'Datadog APM foundation para la API (agent sidecar + ddtrace, off por defecto)'
type: 'feature'
created: '2026-06-10'
status: 'done'
baseline_commit: 'b5aa2541e1180ef01456bdf4a43d426b86f2bf89'
context:
  - '{project-root}/api/config/packages/sentry.yaml'
  - '{project-root}/docs/integration-architecture.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** La API ya tiene Sentry para errores, pero no hay APM de Datadog ni un Datadog Agent. Queremos la *fundación* lista para poder activar tracing (y luego profiler/logs/métricas) por entorno sin re-arquitecturar, **con coste cero hasta que se active explícitamente**.

**Approach:** Añadir la extensión PHP `ddtrace` (**solo APM tracer**; el profiler se difiere — `install-php-extensions` no lo empaqueta en esta base FrankenPHP/ZTS) a la imagen FrankenPHP y un sidecar `datadog-agent` en Compose (dev + prod) **gateado por un compose profile**. Cablear el env de unified service tagging (`DD_*`) en `php` y `messenger_worker`, todo **desactivado por defecto** (`DD_TRACE_ENABLED=false`). Nada envía datos hasta que un operador aporte `DD_API_KEY` y habilite el profile `datadog`. Sentry no se toca.

## Boundaries & Constraints

**Always:**
- La extensión se instala en el stage compartido `frankenphp_base` → dev y prod la comparten.
- El sidecar va detrás de `profiles: ["datadog"]`: el `up` por defecto (dev/ci/prod) queda idéntico a hoy.
- `DD_API_KEY` vive **solo en el servicio agent**, nunca commiteado: comentado/`CHANGE_ME` en `*.example`, soft-default vacío (`:-`) en compose, valor real solo en `*.local` (gitignored). El tracer (`php`/worker) no lleva la key.
- Postura por defecto = **todo OFF / coste cero**.
- Replicar la forma de env-gating de `sentry.yaml` (bloques por entorno, var como gate).
- Actualizar docs requeridos + `deferred-work.md`.

**Ask First:**
- Activar cualquier superficie facturable por defecto.
- Ampliar el egress de la red `backend` (prod, `internal: true`).
- Añadir una dependencia composer (no hace falta para auto-instrumentación).
- Cambiar el comando del `messenger_worker`.

**Never:**
- Encender APM/profiler/logs/métricas por defecto.
- Instalar `ddappsec` (appsec).
- Implementar el handler de Logs (Goal B), el código de emisión DogStatsD (Goal C), ni instalar el profiler `datadog-profiling` (requiere `datadog-setup.php` — externo) — todos diferidos.
- Tocar la config/bundle de Sentry.
- Commitear un `DD_API_KEY` real.
- Añadir nuevos targets de make (se usa `COMPOSE_PROFILES`).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Stack por defecto | `make app.dev` (sin profile, sin key) | Stack idéntico a hoy; no existe contenedor `datadog-agent`; `php -m` muestra `ddtrace` cargado pero inerte (`DD_TRACE_ENABLED=false`) | N/A |
| Opt-in dev | `DD_API_KEY=… COMPOSE_PROFILES=datadog DD_TRACE_ENABLED=true make docker.up.wait` | `datadog-agent` arranca healthy; `php`/worker emiten trazas a `datadog-agent:8126` | Sin key el agent crash-loopea → evitado porque el profile está off por defecto |
| Prod sin key (profile on) | `ENV=prod` + profile datadog + `DD_API_KEY` ausente | El `datadog-agent` no llega a healthy (falla su healthcheck de imagen); el resto del stack arranca normal (nada depende del agent). El operador ve que falta la key. | agent self-fail (sin abort de compose) |
| Prod por defecto | `make docker.up ENV=prod` (profile off) | Stack prod sin cambios; sin agent | N/A |

</frozen-after-approval>

## Code Map

- `api/Dockerfile` — stage `frankenphp_base`, bloque `install-php-extensions`: añadir `ddtrace` (solo APM tracer) + ini inert `frankenphp/conf.d/10-ddtrace.ini`.
- `compose.yaml` — servicios base: añadir env `DD_*` a `php` y `messenger_worker` + servicio `datadog-agent` gateado por profile.
- `compose.dev.yaml` — sin cambios: el agent del base es alcanzable por el bridge por defecto en dev.
- `compose.prod.yaml` — agent en red `frontend` (con egress), `no-new-privileges` + resource limits (**sin** `cap_drop: ALL` — el agent gestiona sus caps), `DD_API_KEY` soft-default (NO `:?` — ver Change Log); `DD_ENV` `prod` y `DD_VERSION` vacío en `php`/worker.
- `api/.env.example` / `.env.prod.example` — documentar `DD_*` (ver tarea de env).
- `api/config/packages/sentry.yaml` — **solo referencia de patrón, no editar.**
- `_bmad-output/implementation-artifacts/deferred-work.md` — anexar Goal B (Logs) y Goal C (DogStatsD).
- Docs a actualizar (ver última tarea): `docs/deployment-guide.md`, `docs/integration-architecture.md`, `docs/claude-code-quickref.md`, `docs/architecture-api.md`, `api/CLAUDE.md`, `PRODUCTION_SECURITY_CHECKLIST.md`.

## Tasks & Acceptance

**Execution:**
- [x] `api/Dockerfile` -- añadir `install-php-extensions ddtrace` + COPY del ini inert `frankenphp/conf.d/10-ddtrace.ini` -- instala el tracer APM (una vez para dev y prod), inerte por defecto; **no** instalar `ddappsec`; profiler diferido (no lo empaqueta esta ruta en ZTS).
- [x] `compose.yaml` -- añadir servicio `datadog-agent` (`gcr.io/datadoghq/agent:7`, tag mayor pinneado) con `profiles: ["datadog"]`, env `DD_API_KEY`/`DD_SITE`/`DD_APM_ENABLED`/`DD_APM_NON_LOCAL_TRAFFIC`/`DD_DOGSTATSD_NON_LOCAL_TRAFFIC`, puertos `8126/tcp`+`8125/udp` (sin publicar a host), volúmenes ro `docker.sock`+`/proc`+`/sys/fs/cgroup`; añadir env tracer `DD_*` a `php` y `messenger_worker` (`DD_AGENT_HOST=datadog-agent`, `DD_TRACE_ENABLED=${DD_TRACE_ENABLED:-false}`, `DD_PROFILING_ENABLED=${DD_PROFILING_ENABLED:-false}`, `DD_ENV=${DD_ENV:-dev}`, `DD_SERVICE`, `DD_VERSION=${DD_VERSION:-dev}`; worker con `DD_SERVICE` propio).
- [x] `compose.prod.yaml` -- `datadog-agent` en red `frontend`, `no-new-privileges` + resource limits (**sin** `cap_drop: ALL`, ver nota en el archivo), `DD_API_KEY` soft-default (**sin** `:?` — ver Change Log); `DD_ENV` por defecto `prod` y `DD_VERSION` vacío (no `dev`) en `php`/worker.
- [x] `compose.dev.yaml` -- sin cambios necesarios: el agent del base es alcanzable por el bridge dev por defecto (verificado con `docker compose config`).
- [x] `api/.env.example` y `.env.prod.example` -- documentar todas las `DD_*` con bloques de comentario al estilo del de Sentry; `DD_API_KEY` vacío/`CHANGE_ME`; nota explícita "facturable — off por defecto".
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` -- anexar Goal B (handler Monolog→Datadog) y Goal C (puerto `Metrics` + adaptador DogStatsD) con la prep ya conocida.
- [x] docs (`deployment-guide`, `integration-architecture`, `claude-code-quickref`, `architecture-api`, `api/CLAUDE.md`) + `PRODUCTION_SECURITY_CHECKLIST.md` -- documentar sidecar, env, manejo del secreto `DD_API_KEY` y tabla de activación + coste por superficie.

**Acceptance Criteria:**
- Given un checkout limpio, when corre `make app.dev` sin profile datadog, then el stack arranca como antes y `docker compose ps` no muestra `datadog-agent`.
- Given la imagen php construida, when corre `php -m` dentro del contenedor, then aparece `ddtrace` (el profiler `datadog-profiling` NO — diferido) y con `DD_TRACE_ENABLED=false` no se emiten trazas.
- Given el profile `datadog` activo con `DD_API_KEY`, when arranca el stack, then `datadog-agent` queda healthy y alcanzable en `datadog-agent:8126` desde `php`.
- Given `ENV=prod` con profile datadog y `DD_API_KEY` ausente, when se arranca, then el `datadog-agent` no llega a healthy (falla su healthcheck de imagen) mientras el resto del stack arranca normal (nada depende del agent). (No hay abort de compose: compose evalúa `:?` incluso en servicios con profile desactivado, así que un `:?` rompería el deploy por defecto — ver Spec Change Log.)
- Given el diff, when se revisa, then la config/bundle de Sentry queda intacta y no hay ningún secreto real commiteado.

## Spec Change Log

- **2026-06-10 (step-03 implementation finding) — profiler descoped to deferred.** La verificación sobre
  la imagen dev construida (PHP 8.5, **ZTS**/FrankenPHP) mostró que `install-php-extensions ddtrace` (la
  ruta de instalación autorizada) instala **solo** el tracer APM — `datadog-profiling` está ausente
  (`php --ri datadog-profiling` → not present). El profiler sí soporta ZTS desde dd-trace-php 0.99.0 pero
  requiere el instalador externo `datadog-setup.php --enable-profiling`, que choca con el constraint
  "no código externo en build". **Decisión (aprobada por el usuario):** entregar APM-only ahora; diferir
  el profiler con la receta exacta de activación en `deferred-work.md`. Enmendado: Intent/Never frozen
  ("ddtrace APM-only"), Dockerfile (quitar `IPE_DD_PROFILING`), `10-ddtrace.ini` (quitada la clave ini
  inexistente `datadog.profiling.enabled` — el profiler es env-only vía `DD_PROFILING_ENABLED`), y los
  docs afectados. `DD_PROFILING_ENABLED` queda cableado (default false) como toggle futuro. KEEP: tracer
  APM verificado funcionando + inerte por defecto; el gating por compose-profile; la postura de secreto
  `DD_API_KEY` solo-en-agent.
- **2026-06-10 (step-04 review finding, CRÍTICO) — `${DD_API_KEY:?}` rompía el deploy por defecto.**
  La review hizo asumir que el guard `:?` en el `datadog-agent` (gateado por profile) solo se evaluaría
  con el profile activo. **Falso** en Docker Compose v5.1.4 (verificado): compose interpola `:?` también
  en servicios con profile **desactivado**, así que `docker compose -f compose.yaml -f compose.prod.yaml
  config` (deploy prod por defecto, profile datadog off) **abortaba** exigiendo `DD_API_KEY` — rompiendo
  `make docker.up ENV=prod` y la garantía "stack por defecto intacto". (También habría roto cada
  `make app.dev` si se hubiera añadido `:?` al base, como sugería la review.) **Fix:** `DD_API_KEY`
  soft-default (`${DD_API_KEY:-}`) en base y prod; la key requerida al activar el profile la señala el
  **healthcheck propio de la imagen del agent** (falla sin key), no un abort de compose. Enmendados la
  matriz I/O (frozen), AC4, Code Map, Tasks, Verification y los docs que afirmaban "el guard `:?` aborta".
  KEEP: deploy por defecto SIEMPRE verde (probado dev + prod sin key); `DD_API_KEY` solo-en-agent.
- **2026-06-10 (step-04 patches).** `DD_VERSION` se sobreescribe a vacío en prod (antes heredaba `dev` →
  trazas prod tag `version:dev`); nota de `DD_ENV=staging` (el overlay prod por defecto lo pone `prod`);
  nota de que `DD_TRACE_ENABLED` debe ir emparejado con el profile; aclaración `DD_SERVICE` (web) vs
  `DD_WORKER_SERVICE` (worker); nota de `NON_LOCAL_TRAFFIC` en el security checklist; corregida la línea
  "+ profiler" en `deferred-work.md`; checkbox de docs marcado.
- **2026-06-10 (post-merge, a raíz de pregunta del usuario sobre el commit `fc6316a`) — boot-probe APM
  flood pre-empted.** El entrypoint espera la DB reintentando `bin/console dbal:run-sql "SELECT 1"`
  hasta 60× por arranque; ese bucle bootea el kernel y, con APM activo (ddtrace traza CLI por
  `DD_TRACE_CLI_ENABLED=1`), generaría una traza CLI errónea por reintento — el mismo flood que el commit
  de Sentry `fc6316a` arregló (848 eventos). **Fix:** añadir `DD_TRACE_ENABLED=false` al comando-sonda en
  `api/frankenphp/docker-entrypoint.sh` (junto a `SENTRY_DSN=`); verificado que el override inline gana
  sobre la env/ini del contenedor. Solo afecta cuando se activa APM (default inerte). Docs:
  `docs/sentry-boot-probe-noise.md` + deployment-guide.

## Design Notes

- **Postura de coste (corrección del planning):** Datadog no tiene free tier real (solo ≤5 hosts infra); APM, Logs, Profiler y métricas infra+custom son **todas facturables**. Único estado coste-cero = "no enviar datos" → default: extensión cargada-pero-inerte + agent no arrancado (profile off). Activar es acción deliberada del operador, documentada en la tabla de coste.
- **Por qué compose profile (y no key vacía):** `datadog/agent` crash-loopea sin `DD_API_KEY`; `profiles: ["datadog"]` la deja fuera del `up` por defecto → dev/ci/prod por defecto intactos y gratis.
- **Worker:** `DD_TRACE_CLI_ENABLED` default `1` instrumenta `messenger:consume` automáticamente; dejar default, etiquetar worker con su `DD_SERVICE`; el APM sigue gateado por `DD_TRACE_ENABLED`. La extensión se carga también en `test` (inerte con flag off): verificar que PHPUnit/Behat no se ven afectados.
- **Pin de imagen / `DD_VERSION` (refinamientos aprobados):** el agent se pinea a tag mayor `:7` para evitar saltos inesperados; dejar comentario para pin por digest (Dependabot, paridad con frankenphp/node/debian) como follow-up. `DD_VERSION=${DD_VERSION:-dev}` permite correlación deploy↔trazas: en prod inyectar el commit SHA o el release tag vía `.env.prod.local`/env de deploy (no se commitea valor real).

## Verification

**Commands:**
- `docker compose -f compose.yaml -f compose.dev.yaml config` -- expected: válido; `datadog-agent` bajo profile; `DD_*` en `php`/worker.
- `make app.dev` && `docker compose ps` -- expected: sin `datadog-agent`; stack healthy (baseline sin cambios).
- `docker compose exec php php -m | grep -i datadog` -- expected: `ddtrace` presente; `datadog-profiling` ausente (diferido).
- `COMPOSE_PROFILES=datadog DD_API_KEY=<dummy> make docker.up.wait` && health del agent && `docker compose exec php php -i | grep -i datadog.trace.enabled` -- expected: agent up; trace.enabled `Off` salvo `DD_TRACE_ENABLED=true`.
- `make php.quality` -- expected: verde (no cambia PHP; guard de no-regresión).
- (prod) `docker compose -f compose.yaml -f compose.prod.yaml config` **sin** profile y sin `DD_API_KEY` -- expected: OK (deploy por defecto intacto). Con `--profile datadog` y sin key: config OK; el agent falla su healthcheck en runtime (no abort de compose).

## Suggested Review Order

**Compose wiring (design core)**

- Entry point — profile-gated `datadog-agent` sidecar; off by default, key only here.
  [`compose.yaml:158`](../../compose.yaml#L158)

- Tracer env on `php`/`worker`, all OFF by default (`DD_TRACE_ENABLED=false`, agent host).
  [`compose.yaml:35`](../../compose.yaml#L35)

- Prod overlay: agent on `frontend` (egress), soft `DD_API_KEY` (the critical no-`:?` fix), `DD_ENV`/`DD_VERSION` corrected.
  [`compose.prod.yaml:169`](../../compose.prod.yaml#L169)

**Image (APM tracer, profiler deferred)**

- `ddtrace` install in the shared base stage (flows to dev + prod); no composer dep, no profiler.
  [`Dockerfile:65`](../../api/Dockerfile#L65)

- Inert-by-default ini (`datadog.trace.enabled = 0`) — image is zero-cost standalone.
  [`10-ddtrace.ini:1`](../../api/frankenphp/conf.d/10-ddtrace.ini#L1)

**Secret + env docs**

- Datadog env block: cost note, soft-key behavior, staging/pairing caveats.
  [`.env.prod.example:75`](../../.env.prod.example#L75)

- Security checklist §8: agent-only key, frontend egress, non-local-traffic, read-only mounts.
  [`PRODUCTION_SECURITY_CHECKLIST.md:116`](../../PRODUCTION_SECURITY_CHECKLIST.md#L116)

**Operator docs + deferrals**

- Observability section: enable commands, knobs, cost, worker caveat.
  [`deployment-guide.md:135`](../../docs/deployment-guide.md#L135)

- Deferred Goals B (Logs) / C (DogStatsD) + profiler & digest-pin follow-ups.
  [`deferred-work.md:220`](../../_bmad-output/implementation-artifacts/deferred-work.md#L220)
