# Sentry — silencing the container boot DB-probe flood (fixed)

> **Status:** FIXED in [`api/frankenphp/docker-entrypoint.sh`](../../api/frankenphp/docker-entrypoint.sh).
> Sibling note: [`sentry-domain-error-filtering.md`](./sentry-domain-error-filtering.md)
> (a *different*, still-deferred decision about 4xx `domain_error` noise).

## Symptom

The `erpify-api-dev` Sentry project filled up with database-connectivity errors
that always clustered around **stack lifecycle transitions** — a cold
`make app.dev`, a deploy, a merge-to-`main` redeploy, or a worktree being created
or torn down. A single window produced **hundreds** of events (one observed burst:
848 in ~10 minutes). All shared:

- `environment: dev`, `users impacted: 0`
- `console.command: dbal:run-sql`, full command `'dbal:run-sql' -q 'SELECT 1'`
- Error: `SQLSTATE[08006] could not translate host name "database" to address:
  Temporary failure in name resolution` (and the connection-refused variant)

A smaller, related trickle appeared on **teardown**: a Messenger
`TransportException: terminating connection due to administrator command` — the
worker's in-flight query killed when Postgres received `SIGTERM`.

None of these are application bugs. They are the stack coming up or going down.

## Root cause

The FrankenPHP entrypoint waits for the database before starting the app, by
retrying a probe query once a second for up to 60 attempts:

```sh
# api/frankenphp/docker-entrypoint.sh
until ... || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
    sleep 1; ...
done
```

This runs on **every** container boot, for **both** the `php` (FrankenPHP) and
`messenger_worker` services (the entrypoint fires for `frankenphp`, `php`, and
`bin/console` as `$1`, and both Docker stages — dev and prod — ship the same
entrypoint).

The trap: `bin/console dbal:run-sql` **boots the full Symfony kernel with the
Sentry SDK active** (`register_error_listener: true` in
[`config/packages/sentry.yaml`](../../api/config/packages/sentry.yaml)). So while
the `database` host isn't resolvable/accepting yet — exactly the window during a
cold up, a deploy, or a worktree spin-up — **each failed retry is captured as a
Sentry error**. Two containers × up to 60 retries × several stack cycles =
hundreds of events. The probe is a **liveness check**; its failures are
*expected*, not errors.

## The fix

Run the probe with the observability SDKs inert for **that one command** — clear
`SENTRY_DSN` (an empty DSN makes the Sentry SDK a no-op) and set
`DD_TRACE_ENABLED=false` (so ddtrace doesn't trace the probe):

```sh
until ... || DATABASE_ERROR=$(SENTRY_DSN= DD_TRACE_ENABLED=false php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
```

This is surgical: it suppresses only the boot probe, leaving real runtime error
reporting (HTTP requests, message handlers, genuine DB outages at runtime)
completely untouched.

The `DD_TRACE_ENABLED=false` prefix in the same command is the **Datadog APM**
analog of this fix — ddtrace would otherwise emit an errored CLI trace per failed
retry once APM is enabled. That preventive guard is documented on its own in
[`datadog-boot-probe-noise.md`](./datadog-boot-probe-noise.md).

### Why this is safe in prod too

The fix lives in the **shared** entrypoint, so it applies identically in dev and
prod — which is intended: the same flood would otherwise hit prod on every
deploy. No diagnostic value is lost: a database that genuinely never comes up
still exhausts the 60 retries, prints `The database is not up or not reachable`,
and `exit 1`s the container. That failure surfaces through the container's exit
status / healthcheck / orchestrator — Sentry was never the channel for it.

We deliberately did **not** add the Doctrine `ConnectionException` to
`ignore_exceptions`: that would also hide a **real** database outage hit during
live request handling, which *is* a page-worthy event.

## Triage of the historical issues

| Sentry issue | What it was | Action |
| --- | --- | --- |
| `ERPIFY-API-DEV-5` (848 events) | The boot DB-probe flood | Fixed at source by this change |
| `ERPIFY-API-DEV-3` (2 events) | Same connection failure via a DBAL path mid-transition | Resolved (transient lifecycle noise) |
| `ERPIFY-API-DEV-4` (1 event) | Worker query killed by Postgres `SIGTERM` on teardown | Resolved (clean-shutdown artifact) |

Issues 3 and 4 are one-to-two-event, clean transition artifacts — resolved in
Sentry rather than chased with code. If a `terminating connection due to
administrator command` ever recurs at volume *outside* a deploy/teardown window,
that would be worth investigating as a real connection-stability problem.

## Recurrence via the Messenger worker (fixed in `before_send`)

The boot-probe fix above only silenced the `dbal:run-sql` liveness probe. The
**same teardown noise re-surfaced through a different path** — the
`messenger:consume` worker's own PostgreSQL connection — as a clustered pair of
unhandled errors one second apart on every stack down/restart/deploy:

| Sentry issue | Exception (outermost) | Origin | Trigger |
| --- | --- | --- | --- |
| `126426150` | `Messenger\…\TransportException` wrapping DBAL `ConnectionLost` | `PostgreSqlConnection::get()` → `LISTEN "messenger_messages"` | Postgres admin-terminates the backend (`terminating connection due to administrator command`, 08006) as the `database` container is killed |
| `126426149` | DBAL `ConnectionException` | `PostgreSqlConnection::__destruct()` → `unlisten()` → `UNLISTEN "messenger_messages"` | a beat later the worker shuts down and the `database` host no longer resolves (`could not translate host name "database"`, 08006 DNS failure) |

The worker holds a long-lived `LISTEN` connection and runs `UNLISTEN` in its
shutdown destructor; both are caught in the container-lifecycle transition. The
worker is self-healing (`restart: unless-stopped` reconnects on the next boot),
so the real signal is the container exit status — not Sentry.

`messenger:consume` is excluded from Sentry **tracing**
([`sentry.yaml`](../../api/config/packages/sentry.yaml)), but
`register_error_listener: true` still **captures** its unhandled exceptions, so
these reached Sentry.

### The fix

[`SentryEventFilter`](../../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventFilter.php)
(the `before_send` filter) now drops a DBAL connection-loss **only when it
originates from the worker transport**. Both conditions must hold:

1. the throwable chain contains a `Doctrine\DBAL\Exception\ConnectionException`
   (its subclass `ConnectionLost` and a wrapping `TransportException` are covered
   by walking the chain), **and**
2. the event came from the worker — either the `console.command: messenger:consume`
   tag (the `LISTEN` path, issue `126426150`) or a `PostgreSqlConnection` frame in
   the stack trace (the destructor `UNLISTEN` path, issue `126426149`, which
   carries no command tag).

A genuine DB outage during **HTTP request handling** has neither marker — no
`messenger:consume` tag, no `PostgreSqlConnection` in its trace — so it is left
untouched and still pages, exactly as the boot-probe fix deliberately preserved.
This is why we still do **not** add the DBAL exceptions to `ignore_exceptions`:
that blunt switch would also hide the page-worthy HTTP-path outage.
