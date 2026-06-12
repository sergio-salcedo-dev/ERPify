# Sentry — Messenger worker crash on a recompiled dev container (fixed)

> **Status:** FIXED — Solution A applied in
> [`compose.dev.yaml`](../../compose.dev.yaml) (`APP_DEBUG=0` + a private
> `messenger_cache` volume on `messenger_worker`). Watch `ERPIFY-API-DEV-7` and
> close it once it stays silent.
> Siblings: [`sentry-boot-probe-noise.md`](./sentry-boot-probe-noise.md) (a
> *different* dev-lifecycle Sentry flood, fixed) and
> [`sentry-domain-error-filtering.md`](./sentry-domain-error-filtering.md)
> (deferred 4xx noise tuning). The performance-tracing side of the same worker is
> covered in [`deployment-guide.md`](../deployment-guide.md) ("Performance tracing
> & the messenger worker").

## Symptom

Sentry issue `ERPIFY-API-DEV-7` — **3 occurrences, 0 users impacted**, all
`environment: dev`, `console.command: messenger:consume` (full command
`'messenger:consume' async --time-limit=3600`):

```
ErrorException: Warning: require(/app/api/var/cache/dev/ContainerLKbhA2o/getConsole_ErrorListenerService.php):
Failed to open stream: No such file or directory
```

The decisive frames in the stacktrace are at **`console.terminate`**, not while a
message is being handled:

```
Symfony\Component\Console\Application::doRunCommand()
  $this->dispatcher->dispatch($event, ConsoleEvents::TERMINATE);
…
EventDispatcher::sortListeners()
  $listener[0] = $listener[0]();        // lazy listener being materialised
…
ContainerLKbhA2o\Erpify_KernelDevDebugContainer::load()
  require(getConsole_ErrorListenerService.php)   // ← file no longer exists
```

This is **not** an application bug and **not** a misconfigured service. The
compiled container that expected to find that file no longer exists on disk. The
worker self-heals — `messenger_worker` runs `restart: unless-stopped`
([`compose.yaml`](../../compose.yaml)), so it restarts against the fresh container
and the in-flight message is retried (at-least-once delivery). The only real cost
is Sentry noise at worker turnover.

## Root cause

A classic Symfony footgun: **a dev-debug DI container that auto-recompiles, plus
a long-lived process that shares that cache directory.** Every piece is present
in our setup:

1. **The worker is long-lived and runs in `dev`.**
   [`compose.yaml`](../../compose.yaml) launches
   `messenger:consume async --time-limit=3600` with
   `APP_ENV: ${APP_ENV:-dev}`. The process boots once and lives up to an hour.

2. **At boot it loads one compiled container class into memory** —
   `ContainerLKbhA2o`. That random `Container<HASH>` token is the name of the
   cache directory for *that* compilation: `var/cache/dev/ContainerLKbhA2o/`.

3. **In `dev` (`APP_DEBUG=1`) Symfony watches source/config files and recompiles
   the container on change**, writing a directory with a **new** hash and
   **deleting the previous one**. Triggers: editing any config/service file,
   `make sf.cc` (`cache:clear`, [`make/symfony.mk`](../../make/symfony.mk)),
   restarting the `php` container, etc.

4. **The dev cache is shared by bind-mount between `php` (web) and
   `messenger_worker`.** [`compose.dev.yaml`](../../compose.dev.yaml) mounts both
   `./api:/app/api` **and** `./api/var:/app/api/var` into the worker — the same
   host `var/cache/dev` the FrankenPHP `php` container uses. The `php` container
   *is* in debug and recompiles on request handling, so **it deletes the
   `ContainerLKbhA2o/` directory out from under the still-running worker.**

5. **Dev services are loaded lazily** — the main container file `require`s a
   service's PHP file only the first time that service is instantiated. On
   `ConsoleEvents::TERMINATE` (fired every time `--time-limit` elapses or a
   message completes) the console tries to materialise `console.error_listener`
   for the first time and `require`s
   `getConsole_ErrorListenerService.php` from the **already-deleted** directory →
   `Failed to open stream`.

So: **stale in-memory dev container in a long-lived worker + the shared dev cache
rebuilt/cleared underneath it.** It surfaces at the `terminate` event because
that is where a previously-unused listener is finally instantiated.

### Why it is rare (3 events)

It needs two things to coincide: a recompilation must land **inside** the
worker's ≤1h window, **and** the worker must then need a service it had not yet
loaded. Hence the low count and the appearance at process turnover rather than
mid-message.

### Can this happen in production? No.

The bug requires **two** preconditions, and **both are dev-only**:

1. **A container that auto-recompiles mid-process.** Only happens with
   `APP_DEBUG=1`. Prod runs `APP_ENV=prod` ⇒ `APP_DEBUG=0`
   ([`compose.prod.yaml`](../../compose.prod.yaml) sets `APP_ENV: prod` on the
   worker), so Symfony does **no** freshness checks and **never recompiles** the
   container while the process is alive — it is immutable for the container's
   lifetime.
2. **A `var/cache` directory a second process can delete.** Dev shares it by
   bind-mount ([`compose.dev.yaml`](../../compose.dev.yaml)). Prod does **not**:
   `compose.prod.yaml` declares **no `volumes:`** on either `php` or
   `messenger_worker`, and the Dockerfile's `VOLUME /app/api/var/`
   ([`../api/Dockerfile`](../../api/Dockerfile)) gives **each container its own
   anonymous `var/` volume** — `php` and the worker never share a cache, and the
   prod image is built once (`app-php-prod`). Nothing can yank the worker's
   container directory.

The `environment: dev` tag on `ERPIFY-API-DEV-7` is itself the proof.

**Guardrail:** the only ways to reintroduce this in prod would be to set
`APP_DEBUG=1` there, or to add a **shared writable** `var/cache` volume across
`php` + `messenger_worker`. Don't do either.

## The fix (applied) — Solution A: isolate the worker from the dev cache churn

Give `messenger_worker` a **stable, private** container cache. Two changes in
[`compose.dev.yaml`](../../compose.dev.yaml) (this is a dev-only concern; prod is
already immutable):

```yaml
messenger_worker:
  environment:
    APP_ENV: dev
    APP_DEBUG: 0                       # no freshness checks → container compiled once, never regenerated
  volumes:
    - messenger_cache:/app/api/var/cache   # private cache; the web container can no longer delete it

volumes:
  messenger_cache:
```

Why both halves are needed:

- **`APP_DEBUG=0`** stops the worker's *own* kernel from watching files and
  recompiling. (A worker has no use for WebProfiler / `DebugClassLoader` /
  `TraceableEventDispatcher` anyway.)
- **The dedicated `messenger_cache` volume** stops the *other* container (`php`,
  still in debug) from deleting the worker's container directory — the named
  volume mounted at `/app/api/var/cache` is more specific than the
  `./api/var:/app/api/var` bind mount, so it wins for that subtree. `var/log` and
  the rest of `var/` stay on the shared bind mount.

`APP_DEBUG=0` alone is **not** sufficient: the cache dir is per-env (`dev`), so
without the private volume the worker would still read `var/cache/dev` and the
web container would still delete it.

**Trade-off:** the worker no longer hot-reloads code. After changing a handler,
restart it: `docker compose restart messenger_worker`. This matches how Messenger
workers behave in prod and is the expected workflow.

## Priority order for ERPify

1. **`APP_DEBUG=0` on `messenger_worker`** — strongly recommended on its own; a
   worker doesn't need debug instrumentation.
2. **Private cache volume for the worker** — also recommended; removes the whole
   class of "container deleted under me" failures.
3. **`messenger:stop-workers` in maintenance scripts** — good complementary
   practice. `make sf.messenger.stop-workers`
   ([`make/symfony.mk`](../../make/symfony.mk)) makes the worker stop cleanly
   between messages so `restart: unless-stopped` brings it back on the fresh
   container. Wire it into flows that run `cache:clear` / `cache:warmup` /
   `composer install` / `composer dump-autoload`. Caveat: it does **not** cover
   dev's spontaneous auto-recompiles (editing a file never calls it), and a hard
   `rm -rf var/cache/*` wipes the stop signal too — so it complements 1+2 rather
   than replacing them.
4. **Do not filter this in Sentry yet.** Apply the fix first. If
   `ERPIFY-API-DEV-7` stays silent for a few weeks, then decide whether a filter
   is even worth it — masking it now would only hide whether the fix worked. (Per
   the repo rule against papering over findings; cf.
   [`sentry-domain-error-filtering.md`](./sentry-domain-error-filtering.md).)

## Triage of the historical issue

| Sentry issue | What it was | Action |
| --- | --- | --- |
| `ERPIFY-API-DEV-7` (3 events) | Worker crash: lazy listener `require` from a dev container directory deleted by the web container's recompile | Solution A applied; self-heals via `restart: unless-stopped`; close once it stays silent |
