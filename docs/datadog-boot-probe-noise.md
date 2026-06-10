# Datadog APM — silencing the container boot DB-probe trace flood (pre-empted)

> **Status:** PRE-EMPTED in [`api/frankenphp/docker-entrypoint.sh`](../api/frankenphp/docker-entrypoint.sh)
> before it could ever occur — Datadog APM ships **off by default**.
> Sibling note: [`sentry-boot-probe-noise.md`](./sentry-boot-probe-noise.md) — the
> *same* flood in Sentry, which actually happened (848 events) and prompted this guard.

## Symptom (what would happen)

Datadog APM is off by default, so this never produced a real incident — but it is the
exact analog of the Sentry boot-probe flood. The moment an operator enables APM
(`DD_TRACE_ENABLED=true` + the `datadog` compose profile), every **stack lifecycle
transition** — a cold `make app.dev`, a deploy, a merge-to-`main` redeploy, or a
worktree being created or torn down — would push a burst of **errored APM traces**
into Datadog, all sharing:

- `service: erpify-api` / `erpify-messenger-worker`, `env` per `DD_ENV`
- resource / command `dbal:run-sql` (the boot DB probe), span marked **error**
- DB error: `SQLSTATE[08006] could not translate host name "database" to address:
  Temporary failure in name resolution` (and the connection-refused variant)

Up to 60 retries × 2 services (`php` + `messenger_worker`) × every boot — the same
hundreds-per-window volume the Sentry project saw (one observed Sentry burst: 848
events in ~10 minutes). None of these are application bugs; they are the stack coming
up.

## Root cause

The same entrypoint loop as the Sentry case. The FrankenPHP entrypoint waits for the
database before starting the app, by retrying a probe query once a second for up to 60
attempts:

```sh
# api/frankenphp/docker-entrypoint.sh
until ... || DATABASE_ERROR=$(... php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
    sleep 1; ...
done
```

This runs on **every** container boot, for **both** the `php` (FrankenPHP) and
`messenger_worker` services (the entrypoint fires for `frankenphp`, `php`, and
`bin/console` as `$1`, and both Docker stages — dev and prod — ship the same
entrypoint).

The Datadog-specific trap: the `ddtrace` extension **traces console commands by
default** (`DD_TRACE_CLI_ENABLED=1`). So with APM enabled, each `bin/console
dbal:run-sql` invocation is a CLI trace, and while the `database` host isn't
resolvable/accepting yet — exactly the window during a cold up, a deploy, or a worktree
spin-up — **each failed retry becomes an errored APM trace**. The probe is a
**liveness check**; its failures are *expected*, not errors.

(Sentry captured those same failures via its error listener — see the sibling note.
ddtrace would capture them as APM traces. Two SDKs, one loop, the same flood.)

## The fix

Run the probe with the tracer inert by setting `DD_TRACE_ENABLED=false` for **that one
command** — an inline env assignment overrides the container/ini setting for just that
process — alongside the Sentry `SENTRY_DSN=`:

```sh
until ... || DATABASE_ERROR=$(SENTRY_DSN= DD_TRACE_ENABLED=false php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
```

This is surgical: it suppresses only the boot probe, leaving real runtime tracing (HTTP
requests, message handlers, genuine DB outages at runtime) completely untouched.
Verified that the inline `DD_TRACE_ENABLED=false` beats both the container-level
`DD_TRACE_ENABLED=true` and the `10-ddtrace.ini` default for that one process.

### Why this is safe in prod too

The fix lives in the **shared** entrypoint, so it applies identically in dev and prod —
which is intended: the same flood would otherwise hit prod APM on every deploy once
enabled. No diagnostic value is lost: a database that genuinely never comes up still
exhausts the 60 retries, prints `The database is not up or not reachable`, and
`exit 1`s the container. That failure surfaces through the container's exit status /
healthcheck / orchestrator — APM was never the channel for it.

### Why not just disable CLI tracing globally?

`DD_TRACE_CLI_ENABLED=0` would silence the probe but also the long-lived
`messenger:consume` worker and any future console command worth tracing. Scoping the
suppression to the probe command keeps CLI / worker tracing available when APM is on.

## Status & scope

- Datadog APM is **off by default** (see [`deployment-guide.md`](./deployment-guide.md),
  *Observability — Datadog APM*), so this guard is **preventive** — it ships now so the
  foundation is clean the day APM is turned on, rather than waiting for the flood to
  recur in a new tool.
- The continuous profiler is not installed in the baseline image (deferred); when it
  lands it honors the same `DD_TRACE_ENABLED` / `DD_PROFILING_ENABLED` switches, so the
  probe exclusion already covers it.
- Sibling, still-relevant guard for the *runtime* (not boot) path:
  [`sentry-boot-probe-noise.md`](./sentry-boot-probe-noise.md) documents the Sentry
  incident that motivated this; the `messenger:consume` long-trace caveat is in
  [`deployment-guide.md`](./deployment-guide.md).
