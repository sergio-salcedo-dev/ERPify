# VPS deployment & remote operations

Central reference for running ERPify on a public VPS: promoting the prod profile
to a real domain, and accessing the database from your workstation (CLI or a GUI
client) without weakening the production hardening.

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
