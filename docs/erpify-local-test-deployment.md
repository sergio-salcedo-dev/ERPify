# Reproducible `erpify.local` test deployment

Stand the **production** Compose profile up on a LAN box (or your laptop) and
reach it at `https://erpify.local` over Caddy's own (internally-trusted) TLS.
The same profile promotes to a public VPS by swapping `SERVER_NAME` to a real
domain and clearing `CADDY_SERVER_EXTRA_DIRECTIVES` — nothing else changes.

Security gate for the same flow: [`../PRODUCTION_SECURITY_CHECKLIST.md`](../PRODUCTION_SECURITY_CHECKLIST.md).
Operator overview: [`deployment-guide.md`](deployment-guide.md).

## Prerequisites

- Docker + Docker Compose v2 on the host.
- This repo checked out; commands run from the repo root.

## 1. Configure secrets

```bash
cp .env.prod.example .env.prod.local
# Edit .env.prod.local — replace every CHANGE_ME value:
#   APP_SECRET=$(openssl rand -hex 16)
#   POSTGRES_PASSWORD=$(openssl rand -hex 24)   # hex: it goes raw into DATABASE_URL
#   CADDY_MERCURE_JWT_SECRET=$(openssl rand -base64 48)
#   AUDIT_KEK=$(openssl rand -base64 24)        # exactly 32 bytes; -hex 32 / -base64 32 are wrong
#   MAILER_DSN=smtp://mailpit:1025              # compose.rehearsal.yaml's mailpit
#   MAILER_SECURITY_FROM=security@erpify.dev    # a real TLD: .local/.test/.example/
#                                               # .invalid/localhost and no-reply@ are refused
#   DEFAULT_NOTIFICATION_EMAIL=ops@erpify.dev   # bank-change notices; mailpit catches them
#   SENTRY_DSN / NEXT_PUBLIC_SENTRY_DSN         # erpify-api-prod / erpify-pwa-prod client keys
#   SENTRY_ENVIRONMENT=rehearsal                # required (API + PWA): prod.env.check refuses the file without it
# Keep SERVER_NAME=erpify.local and CADDY_SERVER_EXTRA_DIRECTIVES=tls internal.
# Generate the secrets ONCE: Postgres keeps the password it initialised its volume
# with, and data sealed under one AUDIT_KEK is unreadable under another.
make prod.env.check          # validates the file before you go further
```

`.env.prod.local` is gitignored. Never commit it.

## 2. Resolve `erpify.local`

Add a hosts entry so the name resolves to the box running the stack
(`127.0.0.1` if it's the same machine):

```text
127.0.0.1   erpify.local
```

- **Linux / macOS:** append the line to `/etc/hosts` (needs sudo).
- **Windows:** append to `C:\Windows\System32\drivers\etc\hosts` (as Admin).

`make deploy.local` warns (non-fatally) and prints this exact line if the name
doesn't resolve.

## 3. Deploy

```bash
make deploy.local
```

This runs `scripts/deploy/deploy-local.sh`: preflight → `make docker.up.wait
ENV=prod` (build + health gate) → `make db.migrate ENV=prod` → a retried
`curl -k https://erpify.local/api/v1/health` smoke test. It then sets up client
trust as far as it can **without sudo** — exports the CA root to
`./erpify-local-root-ca.crt` and, on Linux with `certutil` available, adds it to
Chromium's per-user NSS store (`~/.pki/nssdb`) — and finally prints the exact
remaining copy/paste commands (hosts entry, system-trust import, Firefox),
**skipping any it detects are already done**. So in many cases steps 2 and 4
below are handled or spelled out for you by the script; they remain the full
per-client reference.

The rehearsal runs as its own compose project, **`erpify-prodlocal`**, never as
dev's `erpify`: sharing it hands prod dev's volumes, and Postgres — which applies
`POSTGRES_PASSWORD` only when it initialises a volume — then refuses the prod
password. The project follows the env file's TLS profile
(`scripts/deploy/lib/prod-env.sh`): `tls internal` is the rehearsal, which also
loads `compose.rehearsal.yaml` — a mailpit for its security mail, UI on
`http://127.0.0.1:8025`; anything else keeps the checkout's own project. Every
`make … ENV=prod` target resolves both on its own, so nothing needs them spelled
out; a bare `docker compose` does, as below. It is one project per machine,
whichever checkout runs it, and it binds the same ports 80/443 as dev —
`make deploy.local.down` before `make app.dev`, `make docker.down` before
`make deploy.local` (which refuses to start while another project holds them).

Promote a different box, never this one in place. Clearing
`CADDY_SERVER_EXTRA_DIRECTIVES` moves the stack to the checkout's own project —
`erpify` in the primary checkout — and on a dev machine `erpify` is dev's, so the
promoted stack would meet dev's Postgres volume and its password. On a box with no
dev stack, `make deploy.local.down` **before** editing the file; the rehearsal's
data stays behind in `erpify-prodlocal_*`.

A rehearsal stood up before the project split lives under `erpify` on dev's
volumes: stop it with `COMPOSE_PROJECT_NAME=erpify make ENV=prod docker.down`,
then `make db.reset` (dev's database, re-seeded) if prod migrations reached it,
and re-run the trust step — the new project's Caddy volume mints a new internal
CA.

Re-running it is safe (idempotent). Flags: `--dry-run` (print steps, change
nothing), `--skip-migrations`, `--no-trust` (skip the CA export / NSS / guidance
phase).

For the root-requiring parts (the `/etc/hosts` entry, the system-trust import,
and the Chromium/NSS import) you can run them all at once with:

```bash
sudo make deploy.local.trust
```

It targets the invoking user (`$SUDO_USER`), not root, so your browser's NSS
store gets the CA. **Do not run `sudo make deploy.local`** — that builds, boots,
and migrates the whole stack as root, leaving root-owned artifacts (the exported
CA, build cache) and trusting the CA in root's profile (`HOME=/root`) instead of
yours, so your browser still wouldn't trust it. Keep the deploy unprivileged and
isolate the few root steps in `deploy.local.trust`. Firefox is GUI-only (step 4).

## 4. Trust the internal CA

With `tls internal`, Caddy mints the site cert from its own CA. Export the root
and import it so browsers/clients show a valid certificate:

```bash
docker compose -p erpify-prodlocal --env-file .env.prod.local \
    -f compose.yaml -f compose.prod.yaml -f compose.rehearsal.yaml \
    cp php:/data/caddy/pki/authorities/local/root.crt ./erpify-local-root-ca.crt
```

`erpify-local-root-ca.crt` is gitignored — it is a per-box artifact (Caddy mints
a unique CA per `caddy_data` volume) regenerated on demand, so never commit it.
This step is **local/test only**; a public VPS on ACME (below) serves a
publicly-trusted cert, so clients import nothing.

Then import `erpify-local-root-ca.crt`:

- **Linux (system trust — CLI: `curl`, `openssl`, Node, etc.):**

  ```bash
  sudo cp erpify-local-root-ca.crt /usr/local/share/ca-certificates/erpify-local-root-ca.crt
  sudo update-ca-certificates
  ```

- **Linux + Chrome / Chromium / Edge / Electron:** these do **not** read the
  system bundle above — on Linux they use a per-user **NSS** store
  (`~/.pki/nssdb`). Add the CA there too, or the browser still shows
  `NET::ERR_CERT_AUTHORITY_INVALID`:

  ```bash
  sudo apt-get install -y libnss3-tools          # provides certutil
  mkdir -p "$HOME/.pki/nssdb"
  # initialise the DB once if it doesn't exist yet:
  [ -f "$HOME/.pki/nssdb/cert9.db" ] || certutil -d sql:"$HOME/.pki/nssdb" -N --empty-password
  certutil -d sql:"$HOME/.pki/nssdb" -A -t "C,," -n "erpify.local Local CA" \
      -i erpify-local-root-ca.crt
  certutil -d sql:"$HOME/.pki/nssdb" -L        # verify it is listed (trust "C,,")
  ```

- **macOS:** `sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain erpify-local-root-ca.crt`
  (Chrome/Safari use the system keychain; Firefox still needs its own import below.)
- **Windows (Admin PowerShell):** `Import-Certificate -FilePath erpify-local-root-ca.crt -CertStoreLocation Cert:\LocalMachine\Root`
- **Firefox** (every OS) keeps its **own** store — it ignores both the system
  bundle and `~/.pki/nssdb`, so import the root into Firefox directly:
  1. Open `about:preferences#privacy` (or ☰ → *Settings* → *Privacy & Security*).
  2. Under **Certificates**, click **View Certificates…**.
  3. Select the **Authorities** tab → **Import…**.
  4. Pick `erpify-local-root-ca.crt` (switch the file filter to *All Files* if
     `.crt` is hidden).
  5. Check **“Trust this CA to identify websites.”** → **OK**.

  If an old `Caddy Local Authority` entry is already listed (e.g. after the
  stack was recreated with a new CA), delete it first, then re-import.

Restart the browser, then open `https://erpify.local`.

### What this changes outside the repo (on your OS)

Trusting the CA writes files **outside the ERPify checkout**. `sudo make
deploy.local.trust` does the Linux ones for you; here is the full footprint so
nothing is a surprise (preview anytime with
`bash scripts/deploy/trust-local.sh --dry-run`):

| File / store (outside the repo)                              | Written by                          | Undo |
|--------------------------------------------------------------|-------------------------------------|------|
| `/etc/hosts` (append `127.0.0.1 erpify.local`)               | hosts step / `deploy.local.trust`   | delete the line |
| `/usr/local/share/ca-certificates/erpify-local-root-ca.crt`  | `update-ca-certificates` step       | `sudo rm` it, then `sudo update-ca-certificates --fresh` |
| `/etc/ssl/certs/ca-certificates.crt` (regenerated)           | `update-ca-certificates`            | regenerated on the line above |
| `~/.pki/nssdb/{cert9.db,key4.db,pkcs11.txt}` (per-user)      | `certutil` (Chrome/Chromium/Edge)   | `certutil -d sql:~/.pki/nssdb -D -n "erpify.local Local CA"` |
| system package `libnss3-tools`                               | `apt-get install` (only if missing) | `sudo apt-get remove libnss3-tools` |
| Firefox profile `cert9.db`                                   | manual GUI import                   | remove the CA under *Authorities* in the cert manager |
| **macOS:** `/Library/Keychains/System.keychain`             | `security add-trusted-cert`         | `sudo security delete-certificate -c "erpify.local"` |
| **Windows:** `Cert:\LocalMachine\Root`                       | `Import-Certificate`                | remove it via `certmgr.msc` → Trusted Root |

Inside the repo, only `./erpify-local-root-ca.crt` is written (gitignored).

## 5. Verify

- `https://erpify.local` loads the PWA, with a valid (internally-trusted) lock.
- `https://erpify.local/api/v1/health` returns `200`.
- `make ENV=prod docker.ps` (or `docker compose -p erpify-prodlocal --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml -f compose.rehearsal.yaml ps`)
  shows every service healthy.

## Inspect the database (psql or a GUI client)

Under the prod overlay Postgres has **no published port** and sits on the
`internal: true` `backend` network, so it is unreachable from the host (and the
internet) by design. Two ways in, depending on what you need:

**CLI — works everywhere, no port, nothing to open:**

```bash
make ENV=prod db.shell        # interactive psql via `docker exec` into the db container
```

**GUI client (PhpStorm / DataGrip / DBeaver) — local pre-prod box:**

```bash
make ENV=prod db.tunnel       # starts a throwaway socat sidecar, binds 127.0.0.1:15432
# … connect your client, then …
make ENV=prod db.tunnel.stop  # tears the sidecar down
```

`db.tunnel` bridges the host-facing `frontend` network (to publish the port)
and `backend` (to reach `database`), because a port can't be published from the
internal network directly. It binds **127.0.0.1 only**, so it never leaves the
laptop, and it touches nothing in the running stack. Point the client at:

| Field    | Value                                            |
|----------|--------------------------------------------------|
| Host     | `127.0.0.1` (or `erpify.local`)                  |
| Port     | `15432` (override: `make ENV=prod db.tunnel DB_TUNNEL_PORT=5432`) |
| Database | `POSTGRES_DB` from `.env.prod.local`             |
| User     | `POSTGRES_USER` from `.env.prod.local`           |
| Password | `POSTGRES_PASSWORD` from `.env.prod.local`       |

Port `15432` matches the dev stack, so a single data source works against both.
This is a **local pre-prod convenience** — never run `db.tunnel` on the VPS as a
standing service.

> **On a remote VPS** the DB-access flow is different (SSH-gated, no published
> port). All options — CLI, GUI SSH tunnel to the pinned container IP, an
> `~/.ssh/config` forward, and the socat fallback — are documented in
> [`vps-deployment.md`](./vps-deployment.md#database-access-from-your-workstation).

## Promote to a public VPS

The prod overlay is **byte-identical** to the LAN box; only `.env.prod.local`
and host setup differ. The full promotion runbook (host prep, firewall, DNS,
ACME, deploy, verify) and remote DB access live in
[`vps-deployment.md`](./vps-deployment.md).

## Troubleshooting

| Symptom                                          | Fix                                                                                                                                                                |
|--------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `prod.env.check` fails                           | Copy `.env.prod.example` → `.env.prod.local`, replace every `CHANGE_ME`.                                                                                           |
| Compose aborts naming a `VAR`                    | A required secret is unset in `.env.prod.local`.                                                                                                                   |
| Browser warns on the cert                        | Import the internal CA root (step 4); restart the browser.                                                                                                         |
| Chrome/Chromium still says `ERR_CERT_AUTHORITY_INVALID` after `update-ca-certificates` | On Linux they use the NSS store, not the system bundle. Add the CA to `~/.pki/nssdb` with `certutil` (step 4, "Chrome / Chromium / Edge"). |
| `erpify.local` won't resolve                     | Add the `/etc/hosts` line (step 2).                                                                                                                                |
| php boot-loops, logs `Malformed parameter "url"` | `POSTGRES_PASSWORD` has a URL-unsafe char (`/`,`+`,`=`). Regenerate with `openssl rand -hex 24`, then `make deploy.local.down` and `docker volume rm erpify-prodlocal_database_data` so it re-inits with the new password (that volume is the rehearsal's alone — never remove `erpify_database_data`, which is dev's). |
| Health stays non-200                             | `make ENV=prod docker.logs` — check `php` / `messenger_worker`.                                                                                                    |
| A service won't boot after hardening             | It may need one more capability; add it deliberately and note why in `PRODUCTION_SECURITY_CHECKLIST.md`.                                                           |
