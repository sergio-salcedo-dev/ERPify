# Background jobs & scheduling — worker vs cron, and how to scale past one daemon

Decision record + playbook for running asynchronous work in the prod stack
([`compose.prod.yaml`](../compose.prod.yaml)). It answers two recurring
questions:

1. Should the `messenger_worker` be replaced by a host crontab on the VPS?
2. What happens when we need ~10 more daemons or periodic jobs?

**TL;DR — keep the supervised long-running worker; never reach for the host
crontab. Periodic work goes through Symfony Scheduler inside a Messenger
consumer; genuine daemons become Compose services cloned from a shared YAML
anchor.**

---

## 1. Why the current `messenger_worker` design is already right

The prod worker runs:

```text
messenger:consume async --time-limit=3600 --limit=10000 --memory-limit=256M
```

combined with `restart: unless-stopped` (inherited from the base
[`compose.yaml`](../compose.yaml); the prod overlay does not override it).
That pair *is* the pattern Symfony recommends for production:

- `--time-limit` / `--limit` / `--memory-limit` make the process **exit
  cleanly** every hour, every 10k messages, or on memory growth — no
  long-lived PHP leaks.
- Docker's restart policy **resurrects it immediately**. Docker is the
  supervisor; no systemd unit, no supervisord, no cron needed.

So the worker already has the periodic recycling a cron would provide,
without cron's drawbacks.

## 2. Why a host crontab is the wrong tool here

| Concern | Long-running worker (current) | Host crontab + `docker compose run` |
|---|---|---|
| Latency | Milliseconds — events (`BankCreated/Updated/Deleted`) feed the Mercure realtime UI | Up to 60 s (cron granularity) |
| Laptop ↔ VPS parity | Stack is self-contained in Compose; `make deploy.local` rehearses exactly what the VPS runs | Crontab lives on the host OS — must be duplicated and kept in sync by hand on every box |
| Overlap safety | Single supervised process | Needs `flock` to avoid overlapping consumers |
| Startup cost | None (process stays warm) | Container cold-start per invocation |
| Health & limits | Healthcheck, `cap_drop: ALL`, CPU/mem ceilings, central `docker compose logs` | Scattered; none of it travels with the stack |

The parity row is the decisive one for this project: the whole point of the
pre-production rehearsal on the laptop
([`erpify-local-test-deployment.md`](erpify-local-test-deployment.md)) is
that it exercises the same stack later promoted to the VPS
([`vps-deployment.md`](vps-deployment.md)). Host-level cron config breaks
that guarantee.

## 3. Adding new background work — decision table

Work through the rows top-down; most "I need a daemon" requests collapse
into the first two rows.

| You are adding | Where it lives | Marginal cost |
|---|---|---|
| A **periodic job** (report, cleanup, sync every N minutes) | Symfony Scheduler (`#[AsPeriodicTask]` / a `Schedule`) → consumed by an existing or dedicated `messenger:consume` | One PHP class. Nothing in Compose, nothing on the host |
| A **consumer for another queue** | Extra transport on a grouped consumer: `messenger:consume queue_a queue_b queue_c` (priority = argument order) | One command argument |
| A **genuine daemon** (non-Messenger: socket listener, file tailer, …) | New Compose service cloned from the `messenger_worker` pattern via a YAML anchor (see §4) | ~10 lines of YAML + 128–256 MB RAM |

Routing guidance for Scheduler messages: reuse the `async` transport while
jobs are light; move heavy batch jobs (e.g. a 10-minute report) to a
dedicated `scheduler` transport + worker so they never block the
realtime-ish domain events. Transports are declared in
[`api/config/packages/messenger.yaml`](../api/config/packages/messenger.yaml).

## 4. Scaling to ~10 daemons — what actually changes

1. **Memory budget.** Each PHP worker is ~60–150 MB resident, but the
   current default reserves `WORKER_MEM_LIMIT=512m`. Ten clones at the
   default commit 5 GB — that figure sizes the VPS. Set a per-service
   memory limit that matches reality (128–256 MB is plenty for a consumer).
2. **YAML duplication.** The hardening block (`:?` env guards,
   `security_opt`, `cap_drop`, `deploy.resources`, networks, Sentry) is
   ~25 lines per service. Past 2–3 workers, hoist it into an extension
   field and spread it:

   ```yaml
   x-php-worker-base: &php-worker-base
     image: ${IMAGES_PREFIX:-}app-php-prod
     build: { context: ./api, target: frankenphp_prod }
     security_opt: [no-new-privileges:true]
     cap_drop: [ALL]
     networks: [backend]
     # environment block shared via a second anchor

   services:
     invoice_sync_worker:
       <<: *php-worker-base
       command: ["php", "bin/console", "app:invoice-sync-daemon"]
       deploy: { resources: { limits: { memory: 192m } } }
   ```

3. **Queue consolidation.** Several Messenger consumers do not need a
   process each: group queues by criticality (realtime / normal / heavy
   batch) into 2–3 workers via multi-transport `messenger:consume`.
4. **Postgres connections.** Every worker holds DB connections; around
   ~13 PHP services you approach the default `max_connections` sooner than
   expected. That is the point where a pooler (PgBouncer) enters the
   conversation — not before.
5. **Observability ceiling.** `docker compose ps` + healthchecks scale
   fine to ~10–15 services on one VPS. The model breaks around ~50, not
   10 — no orchestrator (Kubernetes/Nomad) is justified by this growth.
6. **Throughput, not topology.** If a single queue lags, scale the
   consumer horizontally first: `docker compose up --scale
   messenger_worker=2`. The Doctrine transport supports concurrent
   consumers.

## 5. Related docs

- [`deployment-guide.md`](deployment-guide.md) — prod Compose services and envs.
- [`integration-architecture.md`](integration-architecture.md) — where the worker sits in the request/event flow.
- [`architecture-api.md`](architecture-api.md) — domain events and Messenger layering.
