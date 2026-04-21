# Object storage (Flysystem, content-addressable)

This document explains **what to configure**, how it relates to **BYTEA media**, and **what you must do in production** so uploads survive deploys and backups stay consistent.

**Code map:** `Erpify\Shared\Storage\*`, Flysystem bundle config in [`api/config/packages/flysystem.yaml`](../api/config/packages/flysystem.yaml), [`FlysystemObjectStorage`](../api/src/Shared/Storage/Infrastructure/FlysystemObjectStorage.php) → [`ObjectStoragePort`](../api/src/Shared/Storage/Application/Port/ObjectStoragePort.php).

**Upstream:** [League Flysystem](https://github.com/thephpleague/flysystem) (via [`league/flysystem-bundle`](https://github.com/thephpleague/flysystem-bundle)).

---

## Two ways the API stores images (do not confuse them)

| Mechanism | Storage | Public URL | Typical use in ERPify |
|-----------|---------|------------|------------------------|
| **`Media` entity** | PostgreSQL **BYTEA** | `GET /api/v1/media/{hash}` | Bank **logo** (`logoUrl`) |
| **Object storage port** | **Flysystem** (local disk today; S3 later) | `GET /api/v1/stored-objects/{hash}` | Bank **`storedObjectUrl`**, future entities |

Both use a **64-character hex SHA-256** of normalized image bytes for caching and deduplication, but the **path prefix** on disk is **`objects/{hash}`** (see [`ContentAddressableObjectKey`](../api/src/Shared/Storage/Domain/ContentAddressableObjectKey.php)).

---

## What you must configure

### 1. Environment variables (`api/.env`)

| Variable | Required in prod? | Purpose |
|----------|-------------------|---------|
| **`OBJECT_STORAGE_LOCAL_PATH`** | **Strongly recommended** | Absolute path to the directory Flysystem uses as the **local adapter** root. If unset, Symfony falls back to the default in [`flysystem.yaml`](../api/config/packages/flysystem.yaml): `%kernel.project_dir%/var/storage/objects` inside the container — which is **ephemeral** unless that path is on a **mounted volume**. |
| **`MEDIA_PUBLIC_BASE_URL`** | Optional | If set (e.g. `https://api.example.com`), JSON fields **`logoUrl`** and **`storedObjectUrl`** are built as absolute URLs. If empty, the API uses the current request host or path-only URLs. Set in production when consumers (mobile apps, other origins) need a stable absolute base. |

See the annotated blocks in [`api/.env.example`](../api/.env.example).

### 2. Symfony config

| File | Role |
|------|------|
| [`api/config/packages/flysystem.yaml`](../api/config/packages/flysystem.yaml) | Declares the **`erpify.object_storage.storage`** Flysystem instance (currently **local** adapter). The **`directory`** option is **`%env(OBJECT_STORAGE_LOCAL_PATH)%`**, with a default via `parameters.env(OBJECT_STORAGE_LOCAL_PATH)` when the env var is unset. |

No code change is needed to point production at a different directory: set **`OBJECT_STORAGE_LOCAL_PATH`** in the environment passed to the **`php`** container (or host).

### 3. Database

Migrations add **`bank.stored_object_*`** columns (and any future tables you introduce). Run migrations as part of every deploy (see [production deployment](production-deployment.md#database-migrations)).

---

## Production deployment checklist

Do these **in addition** to the main monorepo checklist in [`docs/production-deployment.md`](production-deployment.md).

1. **Persistent volume**  
   Map **`OBJECT_STORAGE_LOCAL_PATH`** to storage that **survives container recreation** (named Docker volume, bind mount to the host, EBS/EFS, etc.). Do **not** rely on an unmounted path under `/app/var` in production unless you have explicitly mounted it.

2. **Permissions**  
   The user running PHP inside **`frankenphp`** / **`php`** must be able to **read and write** that directory. On first deploy, ensure the directory exists and ownership matches the container user (adjust host `chown`/`chmod` or image `USER` + volume `uid`/`gid` as needed).

3. **Same path for all app replicas**  
   If you run **more than one** `php` pod/instance, they must share the **same** writable storage (NFS, cloud file store, or **S3** once you add an adapter). Multiple instances writing to **different local disks** will cause inconsistent reads and orphan files.

4. **Backups**  
   Include **`OBJECT_STORAGE_LOCAL_PATH`** (or your object-store bucket) in **backup and restore** procedures **together with PostgreSQL**. Restore order: restore DB and files for the **same** point in time to avoid rows pointing to missing `objects/{hash}` files (or blobs without rows).

5. **Public URLs**  
   If clients need absolute **`storedObjectUrl`** / **`logoUrl`**, set **`MEDIA_PUBLIC_BASE_URL`** to your public API origin (HTTPS).

6. **Security**  
   Objects are **not** served as static files from `public/`; delivery goes through Symfony (`StoredObjectGetController`) and is gated by **`StoredObjectAccessPort`** (only hashes still referenced in the DB are served). Keep the storage directory **outside** the web document root.

7. **Smoke test after go-live**  
   Create a bank with multipart field **`stored_object`** (or your integration equivalent), then **`GET /api/v1/stored-objects/{hash}`** from the returned URL and confirm **200**, correct **`Content-Type`**, and **`Cache-Control`** containing **`immutable`**.

---

## Docker Compose example (named volume)

If you use Compose, pass the env var and mount a volume at that path. Example **fragment** (adapt names and paths to your setup):

```yaml
services:
  php:
    environment:
      OBJECT_STORAGE_LOCAL_PATH: /var/lib/erpify/object-storage
    volumes:
      - erpify_object_storage:/var/lib/erpify/object-storage

volumes:
  erpify_object_storage:
```

For **production** merges, you can add this under `compose.dev.yaml` locally or a dedicated `compose.object-storage.yaml` merged with `-f`. The repo root [`compose.yaml`](../compose.yaml) does not mount object storage by default so development can use the default under the project tree.

---

## Operations

| Concern | Guidance |
|---------|----------|
| **Disk usage** | Monitor free space on the volume; large uploads accumulate under **`objects/`** by content hash (deduplicated across rows). |
| **Orphan files** | Removing the last DB row that references a hash triggers **`StoredObjectOrphanCleaner`** (via entity listeners). If you delete rows with raw SQL, you may leave orphan files; a future maintenance command could reconcile. |
| **Future S3** | Add an adapter package (e.g. AWS S3) and a second Flysystem storage in YAML; change the **`#[Target]`** on [`FlysystemObjectStorage`](../api/src/Shared/Storage/Infrastructure/FlysystemObjectStorage.php) or use a parameter — application code should keep using **`ObjectStoragePort`** only. |

---

## Behat

Feature steps for stored-object URLs: **`StoredObjectApiContext`** ([`api/tests/Behat/Context/StoredObjectApiContext.php`](../api/tests/Behat/Context/StoredObjectApiContext.php)), registered in [`api/tools/behat/behat.yml.dist`](../api/tools/behat/behat.yml.dist). Reuse **`MediaApiContext`** for multipart uploads and response headers.

---

## Extending with a new entity

1. **Columns:** Add nullable `stored_object_key`, `stored_object_mime_type`, `stored_object_byte_size`, `stored_object_content_hash` (or equivalent) on the new table, same semantics as **`bank`**.
2. **Write path:** Use **`StoredImageObjectWriter`** (or **`ContentAddressableObjectKey::fromContentHash()`** + **`ObjectStoragePort`**) so all modules share **`objects/{hash}`**.
3. **Public GET + MIME:** Implement **`StoredObjectReferenceInspector`**, tag with **`stored_object.reference_inspector`** (optional **`priority`** on the tag). Picked up by **`CompositeStoredObjectAccess`** and **`StoredObjectOrphanCleaner`** (Symfony **`#[AutowireIterator('stored_object.reference_inspector')]`**).
4. **Orphan deletion:** Add **`#[AsEntityListener(..., event: postRemove)]`** calling **`StoredObjectOrphanCleaner::cleanupAfterRemoval($entity->getStoredObjectContentHash())`** (same pattern as **`BankStoredObjectRemoveListener`**).

---

## Troubleshooting

| Symptom | Things to check |
|---------|------------------|
| **500 / write errors on upload** | Directory missing; permissions; disk full; **`OBJECT_STORAGE_LOCAL_PATH`** typo. |
| **404 on `GET /api/v1/stored-objects/{hash}`** | No row still references that hash; file missing on disk; hash mismatch. |
| **URLs wrong in JSON** | **`MEDIA_PUBLIC_BASE_URL`**, **`DEFAULT_URI`**, reverse proxy **`X-Forwarded-*`** / trusted proxies. |

---

## Related documentation

| Topic | Document |
|-------|----------|
| Full production stack (TLS, DB, Messenger, mail, smoke tests) | [production-deployment.md](production-deployment.md) |
| BYTEA logos and `logoUrl` | [media-upload.md](media-upload.md) |
| PWA build and public API URL | [pwa/docs/production-deployment.md](../pwa/docs/production-deployment.md) |
