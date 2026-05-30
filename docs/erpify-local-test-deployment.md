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
# Keep SERVER_NAME=erpify.local and CADDY_SERVER_EXTRA_DIRECTIVES=tls internal.
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
`curl -k https://erpify.local/api/v1/health` smoke test. Re-running it is safe
(idempotent). Flags: `--dry-run` (print steps, change nothing),
`--skip-migrations`.

## 4. Trust the internal CA

With `tls internal`, Caddy mints the site cert from its own CA. Export the root
and import it so browsers/clients show a valid certificate:

```bash
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml \
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
- **Firefox** (every OS) keeps its own store — import under *Settings → Privacy &
  Security → Certificates → View Certificates → Authorities → Import*.

Restart the browser, then open `https://erpify.local`.

## 5. Verify

- `https://erpify.local` loads the PWA, with a valid (internally-trusted) lock.
- `https://erpify.local/api/v1/health` returns `200`.
- `docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml ps`
  shows every service healthy.

## Promote to a public VPS

The prod overlay is **byte-identical** to the LAN box; only `.env.prod.local`
and host setup differ. On a VPS with a real domain:

1. **Host prep.** Install Docker + Compose v2, check out the repo. Open the
   firewall for **inbound 80/443 only** (e.g. `ufw allow 80,443/tcp`). Postgres
   already has no published port (backend-internal), so nothing else is exposed.
2. **DNS.** Point an `A`/`AAAA` record for your domain at the box — no
   `/etc/hosts` entry needed.
3. **Secrets / origins** in `.env.prod.local` (freshly generated, never reused
   from the LAN box):
   - `SERVER_NAME=your.domain`
   - `NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://your.domain`
   - **Clear** `CADDY_SERVER_EXTRA_DIRECTIVES=` (empty) so Caddy switches from
     `tls internal` to automatic **ACME** — a publicly-trusted cert, so clients
     import **no** CA (skip step 4 entirely).
   - For real outbound mail, set a real `MAILER_DSN=` (the default
     `null://null` silently discards mail) and `MAILER_FROM` /
     `DEFAULT_NOTIFICATION_EMAIL`.
   - Optionally tune the `*_CPU_LIMIT` / `*_MEM_LIMIT` knobs to the VPS size.
4. **Deploy:** `make deploy.local` (or
   `ENV=prod make docker.up.wait && ENV=prod make db.migrate`). No compose edits
   — the overlay is identical. ACME needs ports 80/443 reachable from the
   internet to issue the cert on first boot.
5. **Verify:** `https://your.domain/api/v1/health` returns `200` with a valid
   public cert; `docker compose … ps` shows every service healthy.

## Troubleshooting

| Symptom                                          | Fix                                                                                                                                                                |
|--------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `prod.env.check` fails                           | Copy `.env.prod.example` → `.env.prod.local`, replace every `CHANGE_ME`.                                                                                           |
| Compose aborts naming a `VAR`                    | A required secret is unset in `.env.prod.local`.                                                                                                                   |
| Browser warns on the cert                        | Import the internal CA root (step 4); restart the browser.                                                                                                         |
| Chrome/Chromium still says `ERR_CERT_AUTHORITY_INVALID` after `update-ca-certificates` | On Linux they use the NSS store, not the system bundle. Add the CA to `~/.pki/nssdb` with `certutil` (step 4, "Chrome / Chromium / Edge"). |
| `erpify.local` won't resolve                     | Add the `/etc/hosts` line (step 2).                                                                                                                                |
| php boot-loops, logs `Malformed parameter "url"` | `POSTGRES_PASSWORD` has a URL-unsafe char (`/`,`+`,`=`). Regenerate with `openssl rand -hex 24`, then recreate the db volume so it re-inits with the new password. |
| Health stays non-200                             | `ENV=prod make docker.logs` — check `php` / `messenger_worker`.                                                                                                    |
| A service won't boot after hardening             | It may need one more capability; add it deliberately and note why in `PRODUCTION_SECURITY_CHECKLIST.md`.                                                           |
