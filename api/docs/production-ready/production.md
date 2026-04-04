# Deploying ERPify to Production

This index links to every topic you need to deploy ERPify securely. The stack
is **FrankenPHP + Caddy**, **PostgreSQL 18**, and **Symfony 8** running via
Docker Compose from the `api/` directory.

---

## Guides

| Document | What it covers |
|---|---|
| [server-setup.md](server-setup.md) | Prerequisites, domain DNS, starting and stopping the stack |
| [secrets.md](secrets.md) | Required secrets, generating safe values, `.env.prod.local`, full environment variable reference |
| [database.md](database.md) | PostgreSQL credentials, data persistence, backups, port isolation, migrations, fixtures |
| [build.md](build.md) | Building the production Docker image, PHP extensions, image registry |
| [tls.md](tls.md) | Automatic Let's Encrypt certificates, certificate storage, custom certificates, disabling HTTPS |
| [hardening.md](hardening.md) | Pre-go-live checklist covering application, database, secrets, TLS, Docker image, and network |
| [scaling.md](scaling.md) | Docker Swarm, Kubernetes, and managed database options |

---

## Quick-Start Sequence

If you are deploying for the first time, follow these steps in order:

1. **[server-setup.md §Prerequisites](server-setup.md#prerequisites)** — provision the server and clone the repo.
2. **[secrets.md](secrets.md)** — generate all secrets and create `.env.prod.local`.
3. **[database.md §Credentials](database.md#credentials)** — confirm DB variables are set.
4. **[build.md](build.md)** — build the production image.
5. **[tls.md](tls.md)** — verify DNS is propagated and ports are open.
6. **[server-setup.md §Starting the Stack](server-setup.md#starting-the-stack)** — start the stack.
7. **[hardening.md](hardening.md)** — work through the full checklist before announcing the deployment.

---

## Key Security Rules

> [!CAUTION]
>
> - **Never** commit secrets to git. The defaults in `compose.yaml` are
>   insecure placeholders — every one must be replaced before go-live.
> - **Never** run `docker compose down --volumes` on production — it destroys
>   the database volume permanently.
> - **Never** run `doctrine:fixtures:load` against the production database.
> - **Never** set `APP_DEBUG=1` or `APP_ENV=dev` in production.
