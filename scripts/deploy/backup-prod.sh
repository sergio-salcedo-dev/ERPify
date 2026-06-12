#!/usr/bin/env bash
# =============================================================================
# backup-prod.sh — paired backup of the prod stack's two stateful volumes
# =============================================================================
#
# Produces two artifacts sharing one timestamp in $BACKUP_DIR:
#   db-<stamp>.dump        PostgreSQL logical dump (pg_dump -Fc, MVCC-consistent)
#   objects-<stamp>.tar.gz object-storage volume archive (Flysystem local adapter)
#
# Order is load-bearing — DB dump FIRST, objects AFTER: writers persist the
# object file before the referencing row (see StoredImageObjectWriter usage),
# so every hash referenced in the dump already exists in the later archive.
# RESTORE goes in reverse: objects first, then the DB dump of the SAME stamp
# (runbook: docs/vps-deployment.md § Backups).
#
# Knobs (env):
#   BACKUP_DIR             target directory            (default /var/backups/erpify)
#   RETENTION_DAYS         local retention             (default 14)
#   PROD_ENV_FILE          compose secrets file        (default .env.prod.local)
#   COMPOSE_PROJECT_NAME   compose project             (default erpify)
#   OBJECT_STORAGE_VOLUME  volume to archive           (default <project>_object_storage_data)
#   BACKUP_SYNC_CMD        optional offsite hook, run after a successful backup
#                          (e.g. 'rclone sync /var/backups/erpify remote:erpify-backups')
#
# Usage: scripts/deploy/backup-prod.sh   (or `make backup.prod`)
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

ENV_FILE="${PROD_ENV_FILE:-.env.prod.local}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/erpify}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-erpify}"
OBJECT_STORAGE_VOLUME="${OBJECT_STORAGE_VOLUME:-${COMPOSE_PROJECT_NAME}_object_storage_data}"
STAMP="$(date +%F_%H%M%S)"

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; NC='\033[0m'
log_info()    { echo -e "${BLUE}ℹ ${*}${NC}"; }
log_success() { echo -e "${GREEN}✔ ${*}${NC}"; }
log_error()   { echo -e "${RED}✗ ${*}${NC}" >&2; }

compose() {
  docker compose -p "$COMPOSE_PROJECT_NAME" --env-file "$ENV_FILE" \
    -f compose.yaml -f compose.prod.yaml "$@"
}

# —— Preflight ————————————————————————————————————————————————————————————
[[ -f compose.yaml ]] || { log_error "Run from the repo root (compose.yaml not found)."; exit 1; }
[[ -f "$ENV_FILE" ]] || { log_error "$ENV_FILE not found — see 'make prod.env.check'."; exit 1; }
command -v docker >/dev/null || { log_error "docker not found."; exit 1; }

if [[ -z "$(compose ps -q database)" ]]; then
  log_error "database service is not running (project '$COMPOSE_PROJECT_NAME')."
  exit 1
fi

# Guard against archiving a typo: `docker run -v` would silently CREATE a
# missing volume and back up nothing.
if ! docker volume inspect "$OBJECT_STORAGE_VOLUME" >/dev/null 2>&1; then
  log_error "volume '$OBJECT_STORAGE_VOLUME' does not exist."
  log_error "Check 'make docker.info' for the project name, or set OBJECT_STORAGE_VOLUME."
  exit 1
fi

mkdir -p "$BACKUP_DIR"
# Dumps contain business data — keep artifacts unreadable to other users.
umask 077

db_file="$BACKUP_DIR/db-$STAMP.dump"
objects_file="$BACKUP_DIR/objects-$STAMP.tar.gz"

# —— 1) PostgreSQL logical dump (consistent MVCC snapshot, no downtime) ———
log_info "Dumping database to $db_file …"
compose exec -T database sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$db_file"

# pg_dump custom format starts with the PGDMP magic; an empty/garbage file
# means the dump failed in a way the exit code did not surface.
if ! head -c 5 "$db_file" | grep -q 'PGDMP'; then
  log_error "dump verification failed: $db_file is not a pg_dump custom archive."
  exit 1
fi

# —— 2) Object-storage archive (immutable content-addressed files) ————————
log_info "Archiving volume $OBJECT_STORAGE_VOLUME to $objects_file …"
docker run --rm \
  -v "$OBJECT_STORAGE_VOLUME":/src:ro \
  -v "$BACKUP_DIR":/dst \
  alpine tar czf "/dst/$(basename "$objects_file")" -C /src .

tar -tzf "$objects_file" > /dev/null

# —— 3) Local retention ———————————————————————————————————————————————————
find "$BACKUP_DIR" -maxdepth 1 -name 'db-*.dump' -mtime +"$RETENTION_DAYS" -delete
find "$BACKUP_DIR" -maxdepth 1 -name 'objects-*.tar.gz' -mtime +"$RETENTION_DAYS" -delete

log_success "Backup pair $STAMP complete:"
ls -lh "$db_file" "$objects_file"

# —— 4) Optional offsite sync ————————————————————————————————————————————
if [[ -n "${BACKUP_SYNC_CMD:-}" ]]; then
  log_info "Running offsite sync: $BACKUP_SYNC_CMD"
  sh -c "$BACKUP_SYNC_CMD"
  log_success "Offsite sync done."
fi
