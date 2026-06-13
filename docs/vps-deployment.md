# VPS deployment & remote operations

Central reference for running ERPify on a public VPS: promoting the prod profile
to a real domain, accessing the database from your workstation (CLI or a GUI
client) without weakening the production hardening, and the backup/restore
runbook for the stack's stateful volumes.

For the **local** `erpify.local` rehearsal (internal TLS, pre-prod on a laptop),
see [`erpify-local-test-deployment.md`](./erpify-local-test-deployment.md). The
prod overlay is byte-identical between the two — only `.env.prod.local` and host
setup differ.

---

## Promote to a public VPS

On a VPS with a real domain:

1. **Host prep.** Install Docker + Compose v2, check out the repo. Open the
   firewall for **inbound 80/443 only** (e.g. `ufw allow 80,443/tcp`). Postgres
   has no published port (backend-internal), so nothing else is exposed. SSH
   (22) stays restricted to your management access — never open 5432.
2. **DNS.** Point an `A`/`AAAA` record for your domain at the box — no
   `/etc/hosts` entry needed.
3. **Secrets / origins** in `.env.prod.local` (freshly generated, never reused
   from the LAN box):
   - `SERVER_NAME=your.domain`
   - `NEXT_PUBLIC_API_BASE_URL=https://your.domain`
   - **Clear** `CADDY_SERVER_EXTRA_DIRECTIVES=` (empty) so Caddy switches from
     `tls internal` to automatic **ACME** — a publicly-trusted cert, so clients
     import **no** CA.
   - For real outbound mail, set a real `MAILER_DSN=` (the default `null://null`
     silently discards mail) and `MAILER_FROM` / `DEFAULT_NOTIFICATION_EMAIL`.
   - **Sentry is required in prod:** set `SENTRY_DSN=` (from the Sentry MCP) and
     `SENTRY_TRACES_SAMPLE_RATE=0.2` — `make prod.env.check` and the compose `:?`
     guards abort the deploy without them. Events are tagged environment `prod`
     (= `APP_ENV`). Wired in `compose.prod.yaml`
     (see [`deployment-guide.md`](deployment-guide.md)).
   - Optionally tune the `*_CPU_LIMIT` / `*_MEM_LIMIT` knobs to the VPS size.
4. **Deploy:** `make deploy.local` (or
   `ENV=prod make docker.up.wait && ENV=prod make db.migrate`). No compose edits
   — the overlay is identical. ACME needs ports 80/443 reachable from the
   internet to issue the cert on first boot.
5. **Verify:** `https://your.domain/api/v1/health` returns `200` with a valid
   public cert; `docker compose … ps` shows every service healthy.

---

## Database access from your workstation

Under the prod overlay Postgres sits on the `internal: true` `backend` network
with **no published port** — unreachable from the host and the internet by
design. **Never publish 5432 to the internet** to work around this. Reach it
over SSH instead.

The `database` service has a **stable internal IP** (`DB_BACKEND_IP`, default
`10.89.0.10`, on `DB_BACKEND_SUBNET`, default `10.89.0.0/24`; both set in
`compose.prod.yaml`, overridable in `.env.prod.local`). That fixed address is
what the SSH-tunnel options below target — it survives restarts, so a saved GUI
data source keeps working.

### Option A — CLI over SSH (quick monitoring)

```bash
ssh user@your.domain
make db.shell            # interactive psql via `docker exec` — no port, no tunnel
```

Best for ad-hoc inspection and monitoring. Fully SSH-gated; nothing to open or
tear down.

### Option B — GUI client, built-in SSH tunnel → container IP (recommended)

No sidecar on the VPS. The client SSHes in and connects to the pinned container
IP from the VPS's perspective:

- **PhpStorm / DataGrip:** *Data Source → SSH/SSL → Use SSH tunnel* → VPS host,
  user, key. **General tab:** Host = `10.89.0.10`, Port = `5432`.
- **DBeaver:** *Connection settings → Network → SSH* → VPS host, user, key.
  Main tab: Host = `10.89.0.10`, Port = `5432`.
- Database / user / password come from `.env.prod.local`
  (`POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD`).

Works because the Docker host can route to the container on the internal network
(`internal: true` blocks external egress and host-port publishing, not the host
reaching a container on its own bridge). See the firewall caveat below.

### Option C — `~/.ssh/config` LocalForward (one config, no GUI tunnel tab)

Put the forward in your SSH config so a plain `ssh` opens it, and point the GUI
at plain `localhost` with **no** SSH settings:

```sshconfig
# ~/.ssh/config
Host erpify-vps
    HostName your.domain
    User youruser
    IdentityFile ~/.ssh/id_ed25519
    LocalForward 15432 10.89.0.10:5432   # endpoint resolved on the VPS → DB container
```

```bash
ssh -fN erpify-vps                       # open the forward in the background
# GUI / psql → 127.0.0.1:15432, no tunnel config
```

The `LocalForward` target resolves on the VPS, so it lands on the pinned
container IP. PhpStorm can also reuse this entry via *SSH tunnel → Authentication
type: OpenSSH config and authentication agent*.

### Option D — socat sidecar fallback (when forwarding is blocked)

If the VPS firewall drops host→container forwarding (see caveat), publish a
**loopback-only** port with the bundled sidecar, then SSH-forward it:

```bash
# on the VPS
make db.tunnel            # socat sidecar → binds 127.0.0.1:15432 (loopback only)
# from your laptop
ssh -N -L 15432:127.0.0.1:15432 user@your.domain
# GUI / psql → 127.0.0.1:15432
# when done, on the VPS:
make db.tunnel.stop
```

`db.tunnel` bridges `frontend` (to publish) and `backend` (to reach the db); it
binds `127.0.0.1` only and is never exposed to the internet. Run it only while
you need it — never as a standing service.

### Which option to use

| Option | Setup on VPS | GUI config | Best for |
|--------|--------------|------------|----------|
| A — `db.shell` over SSH | none | n/a (CLI) | Quick checks, monitoring |
| B — GUI SSH tunnel → IP | none | SSH tab + Host=`10.89.0.10` | Day-to-day GUI work |
| C — `~/.ssh/config` forward | none | plain `127.0.0.1:15432` | One config for shell + GUI |
| D — socat sidecar | `make db.tunnel` | plain `127.0.0.1:15432` | Firewall blocks B/C |

### Firewall caveat (test before relying on B/C)

Options B and C depend on Docker's default `FORWARD` accept rule, which is
standard but can be broken by a strict host firewall (custom `iptables` /
`nftables`, or `ufw` configured to drop forwarded traffic). Test on the VPS:

```bash
nc -vz 10.89.0.10 5432        # succeeds → B/C work; refused/timeout → use D
```

### Subnet collision

If `10.89.0.0/24` clashes with an existing network on the VPS (some providers
use `10.x` for private networking), override both knobs in `.env.prod.local`:

```dotenv
DB_BACKEND_SUBNET=10.123.45.0/24
DB_BACKEND_IP=10.123.45.10
```

Recreate the stack so the database picks up the new address
(`ENV=prod make docker.up`), then update the host/IP in your GUI / ssh config.

---

## Backups

The prod stack has two stateful volumes — `database_data` (PostgreSQL) and
`object_storage_data` (Flysystem uploads) — and they form **one logical
dataset**: a DB row references its `objects/{hash}` file, so they are backed up
and restored **as a pair from the same point in time** (rationale:
[`object-storage.md`](../docs-info/object-storage.md)).

### Taking a backup — `make backup.prod`

`scripts/deploy/backup-prod.sh` produces two artifacts sharing one timestamp in
`BACKUP_DIR` (default `/var/backups/erpify`):

1. `db-<stamp>.dump` — `pg_dump -Fc` exec'd inside the running `database`
   container (MVCC-consistent, no downtime, no published port needed).
2. `objects-<stamp>.tar.gz` — archive of the object-storage volume.

The order is load-bearing and the script enforces it: **DB first, objects
after**. Writers persist the object file *before* the referencing row, so every
hash referenced in the dump already exists in the later archive. The only
residual window is the orphan cleaner deleting a file between the two steps —
run the backup in a low-traffic window (or stop `messenger_worker` during it)
if you need to exclude even that.

Knobs (env vars): `BACKUP_DIR`, `RETENTION_DAYS` (default 14, local pruning),
`BACKUP_MIN_FREE_MB` (default 500, abort if the target FS is below it),
`OBJECT_STORAGE_VOLUME` (defaults to `<project>_object_storage_data` — `make
docker.info` prints `<project>`, and `docker volume ls` confirms the full
name), `BACKUP_SYNC_CMD` (offsite hook, below).

### Schedule it (cron)

```cron
PATH=/usr/local/bin:/usr/bin:/bin
15 3 * * * cd /opt/erpify && BACKUP_SYNC_CMD='rclone sync /var/backups/erpify remote:erpify-backups' make backup.prod >> /var/log/erpify-backup.log 2>&1 || logger -t erpify-backup FAILED
```

cron runs with a stripped `PATH` — set it explicitly (or use absolute paths) so
`make`/`docker`/`rclone` resolve, otherwise the job dies with
`make: command not found` and only the `|| logger` fires with no detail. The log
captures `ls -lh` output and tool errors (which may include remote names), so
create it `umask 077` / `chmod 600 /var/log/erpify-backup.log`.

A backup that fails silently is no backup — keep the `|| logger` (or wire a
real alert) so failures surface.

### Offsite copy (required)

A backup on the same disk as the data does not survive the failure modes that
matter (disk loss, VPS compromise, fat-fingered `rm`). Sync `BACKUP_DIR` to an
independent location via `BACKUP_SYNC_CMD` — e.g. `rclone sync` to S3/B2/Drive.
**Dumps contain business data (PII): encrypt the offsite copy** — use an
`rclone` `crypt` remote, or switch the whole pipeline to `restic`/`borg`
(encrypted + deduplicating; content-addressed files dedupe almost perfectly
across days).

The backup strategy assumes local storage is the primary retention layer.
Offsite sync failures do not block local retention management — retention prunes
before the sync hook runs, by design.

### Restore — `make restore.prod` (reverse order: objects first, DB after, same stamp)

Restore is destructive: it wipes the object-storage volume and runs
`pg_restore --clean` over the live database. `make restore.prod` wraps the
procedure with up-front artifact verification (PGDMP header + `pg_restore -l` +
`tar -tzf`, so a corrupt backup is caught *before* any live data is touched) and
a typed confirmation. Use it first for the **restore drill and pre-prod
verification** — exercise it on the `erpify.local` rehearsal or a scratch
worktree stack before you ever need it for real.

First find the stamp to restore (newest last):

```bash
ls -1 /var/backups/erpify/db-*.dump   # each db-<stamp>.dump → a <stamp> you can pass
```

Then restore that pair:

```bash
STAMP=<stamp> make restore.prod                    # asks for confirmation
RESTORE_YES=1 STAMP=<stamp> make restore.prod      # drills/CI: skip the prompt (NON-prod only)
```

Knobs mirror the backup: `BACKUP_DIR`, and `COMPOSE_PROJECT_NAME` /
`OBJECT_STORAGE_VOLUME` must match what the backup used. If the local pair was
already pruned by retention, pull `db-<stamp>.dump` + `objects-<stamp>.tar.gz`
back from the offsite copy into `BACKUP_DIR` first.

**Production guard.** When `SERVER_NAME` in the env file is a real domain (not
`*.local`/`localhost`) the run is treated as **production**: `RESTORE_YES` no
longer bypasses anything, the script prints the mandatory pre-restore checklist,
and it requires both `ALLOW_PROD_RESTORE=1` and an interactive typed phrase that
**includes the stamp** (`restore <project> <stamp>`) — so a production restore
can never be scripted, and you cannot fat-finger the wrong host or recovery
point. The checklist it enforces:

1. A fresh backup of the **current** state exists (`make backup.prod`) — you are about to overwrite live data.
2. `<stamp>` is the intended recovery point (the script prints both artifact sizes to confirm).
3. A maintenance window is in effect — `php`/`messenger_worker` are stopped (downtime).
4. The offsite copy of `<stamp>` is intact, in case the restore goes wrong.
5. You are on the correct host (`COMPOSE_PROJECT_NAME` / `OBJECT_STORAGE_VOLUME`).

Under the hood (the raw commands, e.g. for a host without this checkout — substitute
`<project>_object_storage_data` if the backup ran under a different project, or
`docker run -v` silently **creates** an empty volume and you restore into the wrong place):

```bash
# 0) stop writers FIRST — they must not race the destructive wipe below
docker compose -p erpify --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml \
  stop php messenger_worker

# 1) objects — wipe the volume (incl. dotfiles) and unpack the archive
docker run --rm -v erpify_object_storage_data:/dst -v /var/backups/erpify:/src:ro \
  alpine sh -c 'find /dst -mindepth 1 -delete && tar xzf /src/objects-<stamp>.tar.gz -C /dst'

# 2) database — restore the SAME stamp's dump (--exit-on-error: stop on the first
#    failure instead of leaving a half-restored DB that only looks intact)
docker compose -p erpify --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml \
  exec -T database sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --exit-on-error' \
  < /var/backups/erpify/db-<stamp>.dump

# 3) bring the writers back up
docker compose -p erpify --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml \
  start php messenger_worker
```

`rm -rf /dst/*` would leave the previous dataset's dotfiles behind (the archive
captures them via `tar -C /src .`) — `find /dst -mindepth 1 -delete` gives a
truly clean target.

### Restore drill (quarterly)

A backup is only proven by a restore. Once a quarter, restore the latest pair
into a scratch stack (a worktree stack works) with
`STAMP=<stamp> make restore.prod` and run the object-storage smoke test: create
a bank with a `stored_object` upload, then `GET /api/v1/stored-objects/{hash}` →
expect 200 with `Cache-Control: … immutable`.

#### End-to-end smoke checklist

Run the whole loop on the `erpify.local` rehearsal (or a scratch worktree stack)
— never first on real production:

- [ ] **Stand up the prod profile** — `make deploy.local` (preflight → up → migrate → smoke). Confirm the stack is healthy.
- [ ] **Seed an object** — create a bank with a `stored_object` upload; note its `{hash}`. `GET /api/v1/stored-objects/{hash}` → 200, `Cache-Control: … immutable`.
- [ ] **Back up** — `make backup.prod`. Confirm both `db-<stamp>.dump` and `objects-<stamp>.tar.gz` exist (`ls -1 /var/backups/erpify/`), sharing one `<stamp>`.
- [ ] **Mutate after the backup** — delete that bank (and ideally add a different one), so a successful restore is *observable* (the deleted object reappears, the post-backup one is gone).
- [ ] **Restore** — `RESTORE_YES=1 STAMP=<stamp> make restore.prod`. Watch the up-front verification pass (PGDMP + `pg_restore -l` + `tar -tzf`).
- [ ] **Verify the recovery point** — `GET /api/v1/stored-objects/{hash}` for the seeded object → 200, `Cache-Control: … immutable`; the bank is back; the post-backup mutation is gone.
- [ ] **Confirm writers are up** — `docker compose … ps` shows `php`/`messenger_worker` running again.
- [ ] **(prod dry-run)** Optionally rehearse the production path: with a real `SERVER_NAME`, confirm the run refuses without `ALLOW_PROD_RESTORE=1` and demands the typed `restore <project> <stamp>` phrase.

Only after this loop passes is the backup/restore pair trusted for real
production.

---

## Security notes

- The database stays `internal: true` with **no published port** in every
  option here — the pinned IP changes nothing about exposure.
- Keep the inbound firewall at **80/443 only**; never open 5432 to the internet.
- Authenticate SSH with keys, not passwords; restrict who can reach port 22.
- `make db.tunnel` is a **local / SSH-gated convenience**. Bind stays
  `127.0.0.1`; stop it (`make db.tunnel.stop`) when finished. Never leave it
  running on a VPS.
- See [`../PRODUCTION_SECURITY_CHECKLIST.md`](../PRODUCTION_SECURITY_CHECKLIST.md)
  and [`rules/security.md`](./rules/security.md).
