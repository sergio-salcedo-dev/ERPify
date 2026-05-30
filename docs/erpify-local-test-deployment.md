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
#   POSTGRES_PASSWORD=$(openssl rand -base64 24)
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

Then import `erpify-local-root-ca.crt`:

- **Linux (system trust):**

  ```bash
  sudo cp erpify-local-root-ca.crt /usr/local/share/ca-certificates/erpify-local-root-ca.crt
  sudo update-ca-certificates
  ```

- **macOS:** `sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain erpify-local-root-ca.crt`
- **Windows (Admin PowerShell):** `Import-Certificate -FilePath erpify-local-root-ca.crt -CertStoreLocation Cert:\LocalMachine\Root`
- **Firefox** keeps its own store — import under *Settings → Privacy & Security →
  Certificates → View Certificates → Authorities → Import*.

Restart the browser, then open `https://erpify.local`.

## 5. Verify

- `https://erpify.local` loads the PWA, with a valid (internally-trusted) lock.
- `https://erpify.local/api/v1/health` returns `200`.
- `docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml ps`
  shows every service healthy.

## Promote to a public VPS

On a VPS with a real domain and open 80/443:

1. Point DNS `A`/`AAAA` at the box (no hosts file needed).
2. In `.env.prod.local`: set `SERVER_NAME=your.domain`,
   `NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://your.domain`, and **clear**
   `CADDY_SERVER_EXTRA_DIRECTIVES=` so Caddy uses automatic ACME.
3. `make deploy.local` (or `ENV=prod make docker.up.wait && ENV=prod make db.migrate`).
   No compose edits — the overlay is identical.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `prod.env.check` fails | Copy `.env.prod.example` → `.env.prod.local`, replace every `CHANGE_ME`. |
| Compose aborts naming a `VAR` | A required secret is unset in `.env.prod.local`. |
| Browser warns on the cert | Import the internal CA root (step 4); restart the browser. |
| `erpify.local` won't resolve | Add the `/etc/hosts` line (step 2). |
| Health stays non-200 | `ENV=prod make docker.logs` — check `php` / `messenger_worker`. |
| A service won't boot after hardening | It may need one more capability; add it deliberately and note why in `PRODUCTION_SECURITY_CHECKLIST.md`. |
