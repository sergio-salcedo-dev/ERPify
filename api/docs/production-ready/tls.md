# TLS / HTTPS in Production

Caddy, which is embedded in the FrankenPHP image, handles TLS automatically.
No manual certificate management is required under normal circumstances.

> [!NOTE]
>
> This document covers production TLS. For local development TLS (self-signed
> certificates, trusting the local CA) see [`../tls.md`](../tls.md).

---

## Automatic Let's Encrypt Certificates

When `SERVER_NAME` is set to a public domain, Caddy:

1. Serves an HTTP challenge on port 80 to prove domain ownership to Let's Encrypt.
2. Obtains a signed TLS certificate.
3. Automatically renews the certificate before it expires (Let's Encrypt certs
   are valid for 90 days; Caddy renews at ~30 days remaining).

**Requirements:**

| Requirement | Why |
|---|---|
| `SERVER_NAME` set to a real domain (e.g. `api.example.com`) | Let's Encrypt does not issue certificates for IP addresses |
| DNS `A` record already pointing to the server IP | Caddy validates domain ownership immediately on startup |
| Port **80** open in the server firewall | Let's Encrypt HTTP-01 challenge |
| Port **443** open in the server firewall | HTTPS traffic |
| Port **443 UDP** open | HTTP/3 (QUIC) — optional but recommended |

If the certificate request fails (e.g. DNS not propagated yet), Caddy will retry
automatically. Check `docker compose logs php` for certificate-related errors.

---

## Certificate Storage

Certificates and private keys are stored in the `caddy_data` Docker named volume
at `/data/caddy/`. This volume persists across container restarts and
redeployments.

> [!WARNING]
>
> Let's Encrypt **rate-limits** certificate issuance: 5 certificates per
> registered domain per week on the production endpoint. If you destroy the
> `caddy_data` volume repeatedly during testing, you will hit this limit.
> Use the [Let's Encrypt staging environment](https://letsencrypt.org/docs/staging-environment/)
> for testing.

To use the staging CA (issues untrusted certificates, but no rate limits):

```console
CADDY_GLOBAL_OPTIONS="acme_ca https://acme-staging-v02.api.letsencrypt.org/directory" \
docker compose -f compose.yaml -f compose.prod.yaml up --wait --detach
```

---

## HTTP → HTTPS Redirect

Caddy redirects all HTTP traffic to HTTPS by default when `SERVER_NAME` is a
domain name. No configuration change is needed.

---

## Disabling HTTPS (not recommended)

Use this only when ERPify sits behind a TLS-terminating reverse proxy or load
balancer that handles HTTPS for you:

```console
SERVER_NAME=:80 \
docker compose -f compose.yaml -f compose.prod.yaml up --wait --detach
```

In this mode Caddy listens only on port 80 and does not request any
certificates. Make sure the upstream proxy enforces HTTPS and forwards
`X-Forwarded-Proto` headers correctly.

---

## Custom Certificates

If you manage your own certificates (e.g. from a corporate CA or a wildcard
certificate), mount them into the container:

```yaml
# compose.prod.yaml
services:
  php:
    environment:
      CADDY_EXTRA_CONFIG: |
        https:// {
            tls /etc/caddy/certs/tls.pem /etc/caddy/certs/tls.key
        }
    volumes:
      - ./certs/tls.pem:/etc/caddy/certs/tls.pem:ro
      - ./certs/tls.key:/etc/caddy/certs/tls.key:ro
```

Store the certificate files outside the repository (or encrypt them with
`git-crypt` / SOPS) and ensure the private key is not world-readable
(`chmod 600 tls.key`).
