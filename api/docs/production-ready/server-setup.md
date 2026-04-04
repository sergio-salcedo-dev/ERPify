# Server Setup & Stack Startup

## Prerequisites

| Requirement | Notes |
|---|---|
| Linux server with Docker Engine ≥ 26 and Docker Compose v2 | Any cloud provider works |
| A domain name with an `A` record pointing to the server IP | Required for automatic Let's Encrypt TLS |
| Git (or another way to copy the repo to the server) | |

Clone the repository on the server and enter the `api/` directory where all
Docker Compose files live:

```console
git clone git@github.com:<your-org>/ERPify.git
cd ERPify/api
```

---

## Configuring a Domain Name

Create a DNS record of type `A` for your domain pointing to the server IP
**before** the first start — Caddy will try to obtain a Let's Encrypt
certificate immediately on boot:

```dns
api.your-domain.com.  IN  A  <server-ip>
```

> [!NOTE]
>
> Let's Encrypt does not issue certificates for bare IP addresses.
> A real domain name is mandatory.

---

## Starting the Stack

All production commands combine the base `compose.yaml` with the
`compose.prod.yaml` override, which switches to the `frankenphp_prod` image
target.

### Option A — inline environment variables

```console
SERVER_NAME=api.your-domain.com \
APP_SECRET=<32-char-hex> \
POSTGRES_USER=erpify_prod \
POSTGRES_PASSWORD=<strong-random-password> \
POSTGRES_DB=erpify_prod \
CADDY_MERCURE_JWT_SECRET=<32-char-hex> \
docker compose \
  -f compose.yaml \
  -f compose.prod.yaml \
  up --wait --detach
```

### Option B — `.env.prod.local` file (recommended)

See [secrets.md](secrets.md) for how to create and wire the file, then simply:

```console
docker compose -f compose.yaml -f compose.prod.yaml up --wait --detach
```

### What happens on startup

The `--wait` flag blocks until all containers report healthy. Once the
`database` service passes its healthcheck, the `php` entrypoint:

1. Waits for PostgreSQL to accept connections.
2. Runs any pending Doctrine migrations automatically.
3. Starts the FrankenPHP worker.

### Verify the stack

```console
docker compose -f compose.yaml -f compose.prod.yaml ps

# Should return {"status":"ok", ...}
curl -sf https://api.your-domain.com/api/v1/backoffice/health | jq .
```

---

## Stopping & Restarting

```console
# Graceful stop (keeps volumes)
docker compose -f compose.yaml -f compose.prod.yaml down

# Restart after a config or image change
docker compose -f compose.yaml -f compose.prod.yaml up --wait --detach
```

> [!WARNING]
>
> Never run `docker compose down --volumes` on production — it destroys the
> `database_data` volume and all PostgreSQL data permanently.
> See also [database.md](database.md).
