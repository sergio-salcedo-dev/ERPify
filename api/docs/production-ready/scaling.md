# Scaling & Multi-Node Deployment

This document covers options for scaling ERPify beyond a single server.

---

## Docker Swarm

Docker Swarm is directly compatible with the Compose files in this repository.

```console
# Initialise the swarm on the manager node
docker swarm init

# Deploy the stack
docker stack deploy \
  -c compose.yaml \
  -c compose.prod.yaml \
  erpify
```

**Key considerations when running multiple `php` replicas:**

| Concern | Recommendation |
|---|---|
| `caddy_data` and `caddy_config` volumes | Mount a shared NFS volume, or designate a single ingress node that handles TLS termination and forward plain HTTP to the PHP replicas |
| `database_data` volume | PostgreSQL should run on exactly **one** node with a persistent volume — do not replicate the `database` service |
| Migrations | Run migrations once as a one-off task before scaling up PHP replicas, not in the entrypoint when using multiple replicas |
| Sessions | Use a shared session store (e.g. Redis) if the application ever adds stateful sessions |

### Pinning the database service to one node

```yaml
# compose.prod.yaml
services:
  database:
    deploy:
      placement:
        constraints:
          - node.labels.role == db
```

Label the designated DB node:

```console
docker node update --label-add role=db <node-id>
```

---

## Kubernetes

For Kubernetes deployments, adapt the Compose service definitions into K8s
manifests. The structure maps as follows:

| Compose concept | Kubernetes equivalent |
|---|---|
| `php` service | `Deployment` + `Service` |
| `database` service | `StatefulSet` + `Service` (or managed DB like RDS/Cloud SQL) |
| `database_data` volume | `PersistentVolumeClaim` |
| `caddy_data` volume | `PersistentVolumeClaim` (one per ingress pod) |
| Secrets (`.env.prod.local`) | `Secret` objects |
| `depends_on: database` | Init container that checks DB readiness |

### Migrations in Kubernetes

Do not rely on the entrypoint for migrations when running multiple replicas —
it creates a race condition. Instead, run migrations as a Kubernetes `Job`
before rolling out the new `Deployment`:

```yaml
apiVersion: batch/v1
kind: Job
metadata:
  name: erpify-migrate
spec:
  template:
    spec:
      containers:
        - name: migrate
          image: ghcr.io/your-org/erpify-app-php-prod:latest
          command:
            - php
            - bin/console
            - doctrine:migrations:migrate
            - --no-interaction
            - --all-or-nothing
          envFrom:
            - secretRef:
                name: erpify-secrets
      restartPolicy: Never
```

---

## Managed Database (recommended for production)

Instead of running PostgreSQL inside Docker, consider a managed service:

- **AWS RDS / Aurora** (PostgreSQL-compatible)
- **Google Cloud SQL**
- **Azure Database for PostgreSQL**
- **DigitalOcean Managed Databases**

With a managed database, remove the `database` service from `compose.yaml` and
set `DATABASE_URL` directly in `.env.prod.local` (or as a K8s Secret) to point
at the managed endpoint:

```dotenv
DATABASE_URL="postgresql://erpify_prod:<password>@<managed-host>:5432/erpify_prod?serverVersion=18&charset=utf8"
```

Managed databases handle backups, replication, automated failover, and security
patching — removing significant operational burden.
