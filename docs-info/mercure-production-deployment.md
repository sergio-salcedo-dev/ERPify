# Mercure — production deployment (step by step)

Use this guide **after** the general monorepo flow: [pwa/docs/production-deployment.md](../pwa/docs/production-deployment.md) (images, TLS, CORS, PWA build args). Process overview: [mercure.md](mercure.md).

---

## Generating `MERCURE_JWT_SECRET` (and what it is)

`MERCURE_JWT_SECRET` in [`api/.env`](../api/.env) is **not** a file to generate: it is a **single shared symmetric key** (HMAC) used to sign JWTs that Symfony sends to the Mercure hub (publish) and, when you use subscriber cookies, to authorize browsers. The Caddy hub must verify tokens with the **identical** secret.

**Requirements**

- **Cryptographically random**, at least **32 bytes** of entropy (longer is fine). The placeholder `!ChangeThisMercureHubJWTSecretKey!` is only for local/dev templates.
- **One distinct value per environment** (dev, staging, prod). Never copy production into git or into `NEXT_PUBLIC_*` variables.

**How to generate (pick one)**

```bash
openssl rand -hex 32
```

```bash
openssl rand -base64 48
```

```bash
python3 -c "import secrets; print(secrets.token_hex(32))"
```

**Where to set it**

1. **Symfony:** `MERCURE_JWT_SECRET` (runtime env or secret store → container env).
2. **Same value** for the hub in this repo’s Compose layout: **`CADDY_MERCURE_JWT_SECRET`**, which feeds Caddy `MERCURE_PUBLISHER_JWT_KEY` / `MERCURE_SUBSCRIBER_JWT_KEY` (see root [`compose.yaml`](../compose.yaml)).

If the two differ, you get **401** on publish or subscribe.

---

## Avoiding leaks and security mistakes (production)

| Risk | Mitigation |
|------|------------|
| Secret in Git | Keep only placeholders in tracked `.env` / `.env.example`. Real values in **secret manager**, **host env**, or **CI secrets** injected at deploy. |
| Secret in client bundles | Never put `MERCURE_JWT_SECRET` in **`NEXT_PUBLIC_*`** or any browser-exposed config. |
| Logs / support tickets | Do not log full env dumps, `phpinfo()`, or error pages that echo env in prod (`APP_DEBUG=0`). |
| Compose files in repos | Use `${CADDY_MERCURE_JWT_SECRET}` references, not literal keys, in committed YAML. |
| Stale keys after incident | **Rotate** the secret and redeploy Symfony + Caddy together; invalidate old sessions if needed. |
| Over-broad hub access | Harden Caddy `mercure { }` (e.g. drop **`anonymous`** in production). See §5 below. |
| Demo endpoints | `POST /api/v1/mercure/publish-demo` is **404 in prod** (`APP_ENV=prod`); do not enable dev env on the public internet. |

---

## Pre-production checklist (no leaks / safe Mercure)

Use this before cutover or after any secret or infra change.

- [ ] Production `MERCURE_JWT_SECRET` generated with a secure command (e.g. `openssl rand -hex 32`), not guessed or reused from dev.
- [ ] Same value configured for **both** Symfony (`MERCURE_JWT_SECRET`) and **Caddy/hub** (`CADDY_MERCURE_JWT_SECRET` or equivalent).
- [ ] No production secret committed; `.env.local` / host secrets untracked; CI variables marked **secret** (masked in logs where supported).
- [ ] `APP_ENV=prod`, `APP_DEBUG=0`; no debug toolbar or stack traces exposing config.
- [ ] `CORS_ALLOW_ORIGINS` lists **exact** origins (no `*`) and matches real browser origins using credentialed Mercure bootstrap.
- [ ] HTTPS on the public site; `MERCURE_PUBLIC_URL` uses **https** on that hostname.
- [ ] Hub `anonymous` reviewed: disabled or acceptable for your threat model.
- [ ] `POST .../mercure/publish-demo` returns **404** on production host.
- [ ] Smoke: `GET /api/v1/mercure/bootstrap` over HTTPS; Mercure SSE works after deploy.

---

## 1. Prerequisites

- Public **HTTPS** hostname (same host for pages, `/api`, and `/.well-known/mercure` is simplest).
- **Secret store** (Kubernetes Secrets, Vault, etc.).

## 2. Wire URLs (after the secret)

1. **`MERCURE_PUBLIC_URL`** — browser URL, e.g. `https://app.example.com/.well-known/mercure`.
2. **`MERCURE_URL`** — URL the **Symfony container** uses to publish (often internal, e.g. `http://php/.well-known/mercure`).

See [Symfony Mercure configuration](https://symfony.com/doc/current/mercure.html#configuration).

## 3. TLS, cookies, and origins

1. HTTPS everywhere for the public site.
2. **`CORS_ALLOW_ORIGINS`**: explicit list, no `*`; align with [`nelmio_cors.php`](../api/config/packages/nelmio_cors.php) credentialed path for `/api/v1/mercure/`.

## 4. Hub hardening (recommended)

1. Review `mercure { }` in [`api/frankenphp/Caddyfile`](../api/frankenphp/Caddyfile).
2. In production, **remove or restrict `anonymous`** so subscribers need a valid JWT (e.g. `mercureAuthorization` cookie from bootstrap).
3. Redeploy and re-test SSE.

## 5. Smoke tests after deploy

1. `curl -fsS https://your-domain/api/v1/health`
2. `curl -fsS https://your-domain/api/v1/mercure/bootstrap` contains `urn:erpify:mercure:demo`
3. Browser: landing **Mercure Connect** + a real publish path you trust.

## 6. Operations

- Bolt DB path **`/data/mercure.db`** in the container; backup if you rely on hub state.
- Watch logs for **401/403** after rotations.

## Further reading

- [mercure.md](mercure.md) · [Symfony Mercure](https://symfony.com/doc/current/mercure.html) · [secrets.md](../api/docs/production-ready/secrets.md)
