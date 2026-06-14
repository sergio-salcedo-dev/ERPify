# Deferred work

## Datadog API — Goals B & C (split off from spec-datadog-api-foundation-apm, 2026-06-10)

Goal A (the `ddtrace` extension — **APM tracer only, profiler deferred** — in the FrankenPHP image, a
profile-gated `datadog-agent` sidecar, and the `DD_*` env scaffolding, all OFF by default) shipped on
`feat/api-datadog-apm`. The foundation makes the two surfaces below drop-in. Both are **billed** on Datadog (no real free tier), so
each stays opt-in behind the same `DD_TRACE_ENABLED`-style env gating + the `datadog` compose profile.

- **Goal B — Logs → Datadog (Monolog).** Ship the API's JSON logs to Datadog. The prep is in place:
  `api/config/packages/monolog.yaml` already has a commented service-handler block (the Sentry pattern)
  and prod already logs JSON to `php://stderr`. Two viable paths: (1) **agent log collection** — flip
  `DD_LOGS_ENABLED=true` on the `datadog-agent` + add the container-label/autodiscovery config so the
  agent tails stdout (no app change, no new dependency — preferred); or (2) a Monolog handler posting to
  the Datadog logs intake API (needs `DD_API_KEY` reachable by the app + CSP/egress review). Add a
  `DD_LOGS_ENABLED` toggle, document the cost, and wire trace-log correlation (`dd.trace_id`) once APM is on.
- **Goal C — Custom metrics (DogStatsD).** A `Metrics` port in `api/src/Shared/` (domain interface) with
  a DogStatsD adapter in `Shared/.../Infrastructure` sending UDP to `DD_DOGSTATSD_URL=udp://datadog-agent:8125`
  (the agent already has `DD_DOGSTATSD_NON_LOCAL_TRAFFIC=true`). Keep the domain pure (port only); the
  adapter is the sole Datadog-aware piece. Gate emission behind a `DD_METRICS_ENABLED` toggle; document
  the per-custom-metric billing. No business call sites in this goal beyond a smoke counter.
- **Continuous profiler — packaging gap found during Goal A.** `install-php-extensions ddtrace` (the
  authorized install route) installs the APM tracer only; it does **not** bundle the `datadog-profiling`
  extension on this FrankenPHP/ZTS build (verified: `php --ri datadog-profiling` → "Extension not
  present"). The profiler IS supported on ZTS + FrankenPHP worker mode since dd-trace-php 0.99.0, so this
  is a packaging gap, not a capability gap. To add it later (Ask First — it changes the image's install
  method to an external bootstrap, which the "no external code in build" constraint deliberately avoids):
  add a Dockerfile step `RUN curl -LO https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php && php datadog-setup.php --php-bin=all --enable-profiling`,
  then enable at runtime with `DD_PROFILING_ENABLED=true` and verify via `php --ri datadog-profiling`.
  There is no `datadog.profiling.enabled` ini key — the env var is the only toggle. The
  `DD_PROFILING_ENABLED` env is already wired (default false) on `php`/`messenger_worker`, so only the
  install step is missing. Profiler is billed; keep it off unless explicitly wanted.
- **Digest-pin the `datadog-agent` image (review follow-up, user-accepted).** Every other base image
  is sha256-digest-pinned (repo policy); the agent ships as the floating major tag
  `gcr.io/datadoghq/agent:7` (a deliberate, user-approved choice at planning time). Pin it by digest
  (`gcr.io/datadoghq/agent:7@sha256:…`) and let Dependabot track bumps, for reproducibility/supply-chain
  parity with frankenphp/postgres/node. Low priority — the agent is opt-in and off by default.
