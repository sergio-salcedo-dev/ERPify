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
     silently discards mail) and `MAILER_FROM`. `DEFAULT_NOTIFICATION_EMAIL` (where
     bank-change notices go) is **required**: `compose.prod.yaml` aborts without it.
   - Leave `SYMFONY_TRUSTED_PROXIES` unset: FrankenPHP is the edge, so Symfony
     trusts no hop. Set it only to the address of a load balancer you put in front.
   - **Sentry is required in prod:** set `SENTRY_DSN=` (from the Sentry MCP) and
     `SENTRY_TRACES_SAMPLE_RATE=0.2` — `make prod.env.check` and the compose `:?`
     guards abort the deploy without them. Events are tagged environment `prod`
     (= `APP_ENV`). Wired in `compose.prod.yaml`
     (see [`deployment-guide.md`](deployment-guide.md)).
   - Optionally tune the `*_CPU_LIMIT` / `*_MEM_LIMIT` knobs to the VPS size.
4. **Deploy:** `make deploy.local` (or
   `ENV=prod make docker.up.wait && ENV=prod make db.migrate`). No compose edits
   — the overlay is identical. ACME needs ports 80/443 reachable from the
   internet to issue the cert on first boot. **The new image is up before the
   migration runs**, so between the two commands the new code meets the old
   schema and a migration that stops writing a still-`NOT NULL` column fails
   every `INSERT` until the migration lands — and rolling the image back later
   needs `down()` run by hand first, which no `make` target does. Both windows
   and the commands that close them:
   [`deployment-guide.md`](deployment-guide.md) § Deploy process / § Rollback.
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
make ENV=prod db.shell   # interactive psql via `docker exec` — no port, no tunnel
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
make ENV=prod db.tunnel   # socat sidecar → binds 127.0.0.1:15432 (loopback only)
# from your laptop
ssh -N -L 15432:127.0.0.1:15432 user@your.domain
# GUI / psql → 127.0.0.1:15432
# when done, on the VPS:
make ENV=prod db.tunnel.stop
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
| D — socat sidecar | `make ENV=prod db.tunnel` | plain `127.0.0.1:15432` | Firewall blocks B/C |

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

`database_data` (PostgreSQL) holds the **rows** of application state, and one dump is a complete recovery
point for them. It is not every byte: the `image_storage` volume holds the canonical bytes of stored images
(`compose.yaml`), and `backup-prod.sh` dumps Postgres only, so a restore returns rows whose bytes are missing,
and leaves the bytes of a subject erased after the dump on disk. The upload surface is withdrawn, so the
volume may be empty today; it is part of the backup scope the day images return, and of the Documents scope
below. `caddy_data` holds Caddy's ACME account key and issued
certificates, and `caddy_config` its autosave. Losing them costs a certificate
re-issue on the next boot, not data — but on a real domain that re-issue counts
against the Let's Encrypt duplicate-certificate rate limit, so snapshot
`caddy_data` too before rebuilding a host. `pg_dump` covers one database, not cluster roles: they are
recreated from `POSTGRES_USER` by Compose, which a drill confirms rather than assumes.

### Taking a backup — `make backup.prod`

`scripts/deploy/backup-prod.sh` produces one timestamped artifact in
`BACKUP_DIR` (default `/var/backups/erpify`):

- `db-<stamp>.dump` — `pg_dump -Fc` exec'd inside the running `database`
  container (MVCC-consistent, no downtime, no published port needed), then
  proven restorable by a full `pg_restore` read-back before the run reports
  success.

The run emits the dump's exact byte count twice: once as a plain `backup_bytes=<n>` line for grepping (the
decorated status lines carry ANSI colour unconditionally, and a cron log is a file, not a terminal), and once
on the success line. It is taken **before** retention runs, so a suspicious artifact can be judged before
anything has been deleted on its strength. That number is the signal worth watching over time: the read-back
proves the archive is *readable*, never that it holds what you expect — an empty schema dumps perfectly
cleanly — so a run whose size falls off a cliff against yesterday's is the cheapest thing you can alert on.

The run also warns when it finds `objects-*.tar.gz` in `BACKUP_DIR`: nothing produces or expires those any
more, and they carry the same personal data as the dump. See *Orphan object archives* below.

Knobs (env vars): `BACKUP_DIR`, `RETENTION_DAYS` (default 14, local pruning),
`BACKUP_MIN_FREE_MB` (default 500, abort if the target FS is below it),
`COMPOSE_PROJECT_NAME` (default: from the env file's TLS profile — the checkout's
own project on a public host, `erpify` from a primary checkout; `erpify-prodlocal`
for the `tls internal` rehearsal;
`make ENV=prod docker.info` prints it), `BACKUP_SYNC_CMD` (offsite hook, below).

### Schedule it (cron)

```cron
PATH=/usr/local/bin:/usr/bin:/bin
15 3 * * * cd /opt/erpify && set -a && . /etc/erpify/backup.env && set +a && BACKUP_SYNC_CMD='restic backup "$(ls -1t /var/backups/erpify/db-*.dump | head -1)" --tag erpify' make backup.prod >> /var/log/erpify-backup.log 2>&1 && curl -fsS -m 10 --retry 3 "$HC_PING_URL" >/dev/null || logger -t erpify-backup "FAILED (backup, upload or ping)"
```

`/etc/erpify/backup.env` is `chmod 600` and holds `RESTIC_REPOSITORY`, `RESTIC_PASSWORD_FILE`, the backend's
credentials and `HC_PING_URL`. The ping URL is a capability secret — anyone holding it can send a green
ping — so it lives there and never in the crontab or a process listing. Only the **newest** dump is sent
offsite: a snapshot of the whole `BACKUP_DIR` would carry up to fourteen dumps and stretch the retention
below by that much.

cron runs with a stripped `PATH` — set it explicitly (or use absolute paths) so
`make`/`docker`/`restic` resolve, otherwise the job dies with
`make: command not found` and only the `|| logger` fires with no detail. The log
captures `ls -lh` output and tool errors (which may include remote names), so
create it `umask 077` / `chmod 600 /var/log/erpify-backup.log`.

**The heartbeat is the alert, `logger` is only the local trace.** The ping fires only when the whole
pipeline exited 0 — dump, read-back, **and** the offsite hook — because `backup-prod.sh` runs under
`set -e` and a failing `BACKUP_SYNC_CMD` fails the run. Configure the check (Healthchecks.io or any
dead-man's switch) to alert when the daily ping is **missing**, with a grace period longer than the
backup-plus-upload time measured in gate 1 and re-checked as the database grows (there is no start ping,
so a run that is merely long looks the same as one that never began). A dead cron, a full disk or a
destroyed VPS leave no log anyone can read, and absence is the only signal they produce. A locally
successful dump with a failed upload is not a disaster-recovery backup and must not ping.

Three limits. **A check that has never been pinged never alerts**: send one ping by hand and confirm the
check reads *up* before relying on it. **The ping proves cron ran and the hook exited 0**, not that the data
is intact, and anyone with the URL can forge it. And the single `logger` message cannot tell a failed backup
from a failed ping; read the log before concluding which. The offsite RPO is 24 hours **only on nights the
ping arrived**: local retention runs before the upload, so a failed upload leaves the local copy fresh and
the offsite one a day older, with the ping as the only signal.

### Offsite copy (required)

A backup on the same disk as the data does not survive the failure modes that
matter (disk loss, VPS compromise, fat-fingered `rm`). Ship `BACKUP_DIR` to an
independent failure domain via `BACKUP_SYNC_CMD`. **Use a snapshot tool (`restic`, or `borg`), not a
mirror.** `rclone sync` propagates a local deletion to the remote on the next run, so one bad `rm` or one
compromised host erases the offsite copy too. A snapshot repository keeps history, **but only a backend that
refuses deletes from the VPS makes that history un-rewritable** (first bullet). **Dumps contain business data
(PII), so the repository is encrypted** — restic does so by default.

`BACKUP_SYNC_CMD` has one contract: **exit 0 means the snapshot was committed to the offsite repository**.
It does not mean the snapshot restores: that is proven by `restic check --read-data-subset=<n>%` from the
admin host and by the drills, never by the nightly exit code. Keep provider details (backend, credentials,
flags) inside the command, so the script never learns what sits behind it.

- **Credentials — to be proven, not assumed.** Retention and verification run from a different host with a
  separate administrative credential; the VPS holds one that cannot delete snapshots. "Cannot delete" is not
  "put-only": `restic backup` reads the repository's config, index and snapshots, and takes and removes
  its own lock files. `rest-server --append-only` is the setup restic documents for this and permits lock
  removal; a bucket with object-lock or an IAM policy without delete can make that lock removal fail and
  leave stale locks, and clearing them (`restic unlock`) needs the admin credential. **Do not add a
  lifecycle rule that expires objects by age**: restic deduplicates, so it would delete packs newer
  snapshots still reference and corrupt the repository, detected only at restore. Gate 2 must show, with the
  VPS's credential and the exact backend, two consecutive successful `restic backup` runs, a successful
  `restic check`, and a refused `restic forget`. `restic init` runs once, from the admin host. If the test
  fails, use `rest-server` or keep the delete-capable credential off the VPS.
- **The repository password is custodied outside the VPS** (password manager or printed copy). A
  password that lives only on the host it protects makes a destroyed VPS an unrecoverable backup. The VPS
  also holds a copy to run the backup, so a compromised VPS can decrypt the repository's whole history:
  append-only protects against deletion, **not against disclosure**.
- **Offsite retention is independent of the 14-day local one**, and it is **provisional**. Run from the admin
  host, away from the 03:15 backup (prune takes an exclusive lock):
  `restic forget --group-by tags --keep-daily 14 --keep-weekly 4 --keep-monthly 3 --prune`.
  `--group-by tags` is required: each snapshot holds a dump with a different path, and the default grouping
  by host and path would put every dump in its own group, so no snapshot would ever be forgotten. The counts
  are snapshots, not calendar days, so missed nights stretch them. The ceiling is not an operational
  question: an old snapshot holds the `dek_keystore` rows of subjects erased since, so **about three months**
  is also how long a restore can undo an erasure (see *Disaster-recovery readiness*). Do not lengthen it
  without the privacy decision recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7.
- **Run `restic check` periodically** (monthly, with a read-data subset). Pruning is a separate command from
  backing up; neither verifies the other.
- **To pull a dump back:** `restic restore latest --tag erpify --target <dir>` with the repository password,
  then point `BACKUP_DIR` at `<dir>` for `make restore.prod`.

The backup strategy assumes local storage is the primary retention layer.
Offsite sync failures do not block local retention management — retention prunes
before the sync hook runs, by design.

### Orphan object archives (an operator sweep, not a one-off)

Retention expires `db-*.dump` and nothing else. A `BACKUP_DIR` may also hold `objects-<stamp>.tar.gz`, from a
host that ran the paired backup or from a snapshot-based offsite (`restic`/`borg`) restoring the whole
directory back. **Those archives carry the same personal data as the dump**, and nothing expires them any
more — so this is a retention obligation you now own by hand, not housekeeping.

**It is not one-off, and the reason is a date this repository does not know.** The producer is the script
running on the VPS, so a host still on a pre-removal checkout keeps writing them, and its deployed retention
keeps expiring them. Sweeping before that deploy is undone by the next cron tick. Sweep **after** the deploy
lands, and then once more after `RETENTION_DAYS + 1` days, when the last archives the old code wrote have
aged past the window.

Look first. Run as the same user cron runs as — `BACKUP_DIR` is `chmod 700`, so a different user gets
permission errors that `find` will not make obvious:

```bash
export BACKUP_DIR="${BACKUP_DIR:?set it to the value your cron line uses}"
ls -lh "$BACKUP_DIR"/objects-*.tar.gz 2>/dev/null || echo '(none)'
```

Then delete, only if the listing is what you expect. `-print` is not decoration: without it "swept nothing"
and "swept twelve" look identical, and every failure mode below reports as success.

```bash
find "$BACKUP_DIR" -maxdepth 1 -name 'objects-????-??-??_??????.tar.gz' -print -delete
```

The name pattern is anchored to the stamp shape on purpose — a bare `objects-*.tar.gz` also matches an
`objects-before-migration.tar.gz` somebody parked there. There is deliberately **no `-mtime`**: the archives
still present are the freshest ones, so an age filter spares exactly what you came to remove. If instead you
are pruning a directory that a snapshot offsite handed back intact, add
`-mtime +"${RETENTION_DAYS:?}"` to keep an archive whose paired dump is still inside the window — and note
`find` discards the fractional part of an age, so **`-mtime +N` needs at least N+1 whole days**.

Two consequences worth knowing before you run it:

- **The offsite copy does not follow.** A mirroring backend (`rclone sync`) propagates the deletion on the
  *next* `make backup.prod`, not when you sweep; a snapshot backend (`restic`/`borg`) does not propagate it at
  all until an explicit `restic forget --prune` / `borg prune`. Until then the archive, and the personal data
  in it, is still offsite.
- **`restore-prod.sh` warns when the stamp you are restoring carries one of these**, and that warning is what
  tells you a recovery point is database-only. Sweeping removes the file the warning tests for, so record the
  stamps you swept (or leave a marker beside them) if that distinction may still matter.

The sweep does not take the `.backup.lock` that `backup-prod.sh` and `restore-prod.sh` share. It does not need
to: those two contend over `db-*.dump` and the lock file itself, which this touches neither of. Do not extend
the pattern without taking the lock.

### Restore — `make restore.prod`

Restore is destructive: it runs `pg_restore` over the live database.
`make restore.prod` wraps the procedure with up-front artifact verification
(PGDMP header + a full `pg_restore` read-back of the whole dump, so a corrupt or
truncated backup is caught *before* any live data is touched), a typed
confirmation, an **atomic DB restore**
(`--single-transaction` — any error rolls back rather than leaving a half-restored
database), and an automatic **writer restart on any failure** so a botched
restore never leaves the app headless. Use it first for the **restore drill and
pre-prod verification** — exercise it on the `erpify.local` rehearsal or a scratch
worktree stack before you ever need it for real.

First find the stamp to restore (newest last):

```bash
ls -1 /var/backups/erpify/db-*.dump   # each db-<stamp>.dump → a <stamp> you can pass
```

Then restore that stamp:

```bash
STAMP=<stamp> make restore.prod                    # asks for confirmation
RESTORE_YES=1 STAMP=<stamp> make restore.prod      # drills/CI: skip the prompt (NON-prod only)
```

Knobs mirror the backup: `BACKUP_DIR`, and `COMPOSE_PROJECT_NAME`, which
resolves from the env file exactly as it did for the backup — set it only if
you overrode it then. If the local dump was already pruned by retention, pull
`db-<stamp>.dump` back from the offsite copy into `BACKUP_DIR` first.

**Production guard.** Unless `SERVER_NAME` in the env file is explicitly local
(`*.local`/`*.localhost`/`localhost`/`127.0.0.1`/`::1`) the run is treated as
**production** — a real domain, an IP, or even a bare internal hostname all
qualify, so an unrecognised target fails safe rather than slipping into the
scriptable path. In production: `RESTORE_YES` no
longer bypasses anything, the script prints the mandatory pre-restore checklist,
and it requires both `ALLOW_PROD_RESTORE=1` and an interactive typed phrase that
**includes the stamp** (`restore <project> <stamp>`) — so a production restore
can never be scripted, and you cannot fat-finger the wrong host or recovery
point. The checklist it enforces:

1. A fresh backup of the **current** state exists (`make backup.prod`) — you are about to overwrite live data.
2. `<stamp>` is the intended recovery point (the script prints the artifact size to confirm).
3. A maintenance window is in effect — `php`/`messenger_worker` are stopped (downtime).
4. The offsite copy of `<stamp>` is intact, in case the restore goes wrong.
5. You are on the correct host (`COMPOSE_PROJECT_NAME`).

Under the hood (the raw commands, e.g. for a host without this checkout —
substitute the project name the backup ran under for `erpify`, and on the
`tls internal` rehearsal add `-f compose.rehearsal.yaml`):

```bash
# 0) stop writers FIRST — they must not race the restore below
docker compose -p erpify --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml \
  stop php messenger_worker

# 1) database — restore the stamp's dump (--single-transaction: wrap the whole
#    restore in one BEGIN/COMMIT so an error rolls back to the pre-restore state
#    instead of leaving a half-restored DB that only looks intact)
docker compose -p erpify --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml \
  exec -T database sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --single-transaction' \
  < /var/backups/erpify/db-<stamp>.dump

# 2) bring the writers back up
docker compose -p erpify --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml \
  start php messenger_worker
```

Unlike `make restore.prod`, this raw sequence has no safety net: if step 1
fails, the writers stay stopped — rerun step 2 yourself to bring them back.

### Restore drill (quarterly)

A backup is only proven by a restore. Once a quarter, restore the latest dump
into the `erpify.local` rehearsal — never the live host once customers exist (the one pre-customer
exception is gate 1 below). On the workstation that
runs it (`make deploy.local`, see `erpify-local-test-deployment.md`), copy the
dump into a local `BACKUP_DIR`, run
`BACKUP_DIR=<dir> STAMP=<stamp> make restore.prod` and confirm a record seeded
before the backup comes back. The target is the rehearsal env file's project,
`erpify-prodlocal`, so neither the live host nor a dev stack is it.

#### End-to-end smoke checklist

Run the whole loop on the `erpify.local` rehearsal (project `erpify-prodlocal`)
— never first on real production:

- [ ] **Stand up the prod profile** — `make deploy.local` (preflight → up → migrate → smoke). Confirm the stack is healthy.
- [ ] **Seed a record** — `POST /api/v1/backoffice/banks` with a JSON body; note the returned `{id}`. `GET /api/v1/backoffice/banks/{id}` → 200.
- [ ] **Back up** — `make backup.prod`. Confirm `db-<stamp>.dump` exists (`ls -1 /var/backups/erpify/`).
- [ ] **Mutate after the backup** — delete that bank (and ideally add a different one), so a successful restore is *observable* (the deleted bank reappears, the post-backup one is gone).
- [ ] **Restore** — `RESTORE_YES=1 STAMP=<stamp> make restore.prod`. Watch the up-front verification pass (PGDMP + full `pg_restore` read-back).
- [ ] **Verify the recovery point** — `GET /api/v1/backoffice/banks/{id}` for the seeded bank → 200; the post-backup mutation is gone.
- [ ] **Confirm writers are up** — `docker compose … ps` shows `php`/`messenger_worker` running again.
- [ ] **(prod dry-run)** Optionally rehearse the production path: with a real `SERVER_NAME`, confirm the run refuses without `ALLOW_PROD_RESTORE=1` and demands the typed `restore <project> <stamp>` phrase.

Only after this loop passes is the backup/restore procedure trusted for real
production.

### Disaster-recovery readiness (go-live gates)

The rehearsal loop above proves the **restore procedure**. It does not prove that the VPS is recoverable:
the dump was too small to say anything about timing, the paths were scratch paths, nothing came back from
offsite, and nobody noticed a silent cron. Three gates stand between the procedure and a recovery, to be
ticked with their evidence before the first customer: gates 1 and 2 are boxes in
`PRODUCTION_SECURITY_CHECKLIST.md` §8, gate 3 is the open §7 item about restores undoing erasures.

1. **Drill on the real VPS**, with the production `BACKUP_DIR` and permissions, on a database of realistic
   size. It is a destructive restore over the live database, acceptable **only before the first customer**;
   afterwards the same drill runs on a scratch host. Record backup, upload, download-and-decrypt, restore and
   application-recovery durations separately: they are the RTO, and none exists until this runs. Include the
   production refusal (`ALLOW_PROD_RESTORE` and the typed phrase) and one authenticated login after the
   restore.
2. **Restore from the offsite repository alone**, on a host that has never seen the VPS, using only the
   independently custodied repository password and the secrets backup below. A recovery that needs the
   failed host is not a recovery. The code must be reachable without the VPS too: check out the git ref the
   dump was taken under before restoring, because `restore-prod.sh` runs no migrations and a dump restored
   onto a different schema version is not a recovery. On any host that is not the VPS: stop
   `messenger_worker` and `scheduler_worker` (or point the mailer at mailpit) so nobody real is emailed from
   restored data, use a neutral `SERVER_NAME` so Caddy does not request a certificate for the production
   domain, and destroy the database afterwards — it holds the resurrected keys of gate 3.
3. **Re-erase after restoring.** A restore returns the whole database to the dump's instant: the
   `dek_keystore` row of a subject erased since comes back with its `wrapped_dek`, and so do their identity
   and bank-account rows. `SubjectErasureReconciler` is an anti-join between destroyed keys and
   `GDPR_SUBJECT_ERASED` evidence, both inside the restored database, so it sees a live row and reports
   nothing; a green reconciler after a restore proves nothing. Until a ledger of destroyed scope ids
   survives outside the database, the runbook is, in order:
   - **Before overwriting**, if the old database is still readable, copy its `GDPR_SUBJECT_ERASED` audit rows
     out. In a total loss nothing can be copied, and the list is **incomplete by construction**.
   - Keep an operator log of confirmed erasures **off the VPS** (an opaque subject reference and a date,
     never a name). It is the only source that survives a total loss, it is itself personal data about erased
     people, and it is deleted when the last snapshot older than its entries has expired.
   - `docker compose … stop scheduler_worker` before the restore: `restore-prod.sh` stops only `php` and
     `messenger_worker`, and the scheduler would keep ticking, mailing locked identities, against restored data.
   - `restore-prod.sh` **restarts `php` and `messenger_worker` itself**, on success and from its exit trap,
     and has no hold option. The moment it returns, stop them again; the seconds between are a window in
     which the API serves resurrected data, and that residual is recorded in §7.
   - Re-run the erasure for every subject in the log with a one-off container
     (`docker compose … run --rm php bin/console identity:gdpr:erase-subject <id>`, and
     `bank-account:gdpr:erase-subject` / `audit:gdpr:erase` where they apply), then start the writers and
     the scheduler.

Declared objectives, to be accepted rather than assumed: **RPO 24 hours** on the nights the heartbeat
arrived (nightly dump), RTO unknown until gate 1. A shorter RPO needs WAL archiving, whose base backup is a
physical one — `pg_dump` cannot seed PITR. Introduce it when a formal RPO below a day is stated, when a
ledger of time-sensitive financial movements goes live, or when a contract requires it, and not before: an
untested WAL archive is not a recovery guarantee. The quarterly drill above stays after go-live, and is
repeated after any change of repository, credential or major version of Postgres or restic: an RTO measured
once goes stale as the database grows.

**Secrets are part of the backup.** A dump without `AUDIT_KEK` cannot be decrypted where it matters, and
the rest of `.env.prod.local` is needed to bring the stack back. Keep them in a secrets store of their own,
reached by a different credential from the data backups', and re-save them on every change of
`.env.prod.local`: a stale copy is the likely failure, and nothing alerts on it. There is one KEK and it is
not rotated (re-wrap tooling is unshipped, checklist §1), so it is kept as long as any live data or retained
dump exists; losing it makes every dump useless. Once rotation exists, a retired KEK is kept as long as the
oldest dump it opens. Keeping the KEK beside an old dump makes any resurrected DEK decryptable, so the dump
retention above is the only bound on that. Store the exact env string: `make prod.env.check` on the clean
host refuses a wrong-length value.

The future Documents module widens this: its encrypted blobs are not in the database dump (nor is
`image_storage`, above), while the keys that open them are. Its backup scope is the blobs, the keystore and
the destroyed-key ledger, restored together, and it does not go live before gate 3 has a durable ledger
behind it. That constraint is prose in this file and in the checklist, enforced by nothing; the Documents
boundary itself is decided in the
[conservation contract](./adr/images-vs-documents-conservation-contract.md), so a Documents spec cites both.

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
