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
#   BACKUP_MIN_FREE_MB     min free space preflight    (default 500)
#   PROD_ENV_FILE          compose secrets file        (default .env.prod.local)
#   COMPOSE_PROJECT_NAME   compose project             (default erpify)
#   STORAGE_VOLUME         volume to archive           (default <project>_storage_data)
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
MIN_FREE_MB="${BACKUP_MIN_FREE_MB:-500}"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-erpify}"
STORAGE_VOLUME="${STORAGE_VOLUME:-${COMPOSE_PROJECT_NAME}_storage_data}"

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
command -v flock >/dev/null || { log_error "flock not found (util-linux)."; exit 1; }

# A non-numeric RETENTION_DAYS makes the later `find -mtime` error out mid-run
# (after a successful dump) or silently prune nothing.
[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || { log_error "RETENTION_DAYS must be a non-negative integer (got '$RETENTION_DAYS')."; exit 1; }
[[ "$MIN_FREE_MB" =~ ^[0-9]+$ ]] || { log_error "BACKUP_MIN_FREE_MB must be a non-negative integer (got '$MIN_FREE_MB')."; exit 1; }

# A bare id is not enough — a created/exited/restarting container also has one;
# only a running container can answer `pg_dump`.
if [[ -z "$(compose ps --status running -q database)" ]]; then
  log_error "database service is not running (project '$COMPOSE_PROJECT_NAME')."
  exit 1
fi

# Guard against archiving a typo: `docker run -v` would silently CREATE a
# missing volume and back up nothing.
if ! docker volume inspect "$STORAGE_VOLUME" >/dev/null 2>&1; then
  log_error "volume '$STORAGE_VOLUME' does not exist."
  log_error "Check 'make docker.info' for the project name, or set STORAGE_VOLUME."
  exit 1
fi

# Dumps contain business data — keep the dir and every artifact unreadable to
# other users. umask must precede mkdir so a freshly created BACKUP_DIR is not
# left world-traversable by the default 022 mask.
umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# Prevent overlapping runs (a cron tick overrunning into the next, or a manual
# run racing cron) from interleaving two dumps/archives — and from racing each
# other's retention prune. Non-blocking: a second run bails rather than queuing.
exec 9>"$BACKUP_DIR/.backup.lock"
flock -n 9 || { log_error "another backup is already running (lock: $BACKUP_DIR/.backup.lock)."; exit 1; }

# Decide artifact names only after the lock is held, so two runs can never pick
# the same name before one holds the lock. UTC: a local-time stamp repeats an
# hour at the DST fall-back, which would collide a pair.
STAMP="$(date -u +%F_%H%M%S)"

# Fail before dumping if the target filesystem is short on space: a dump that
# fills the disk leaves a half-written, unrestorable artifact.
avail_mb=$(($(df -Pk "$BACKUP_DIR" | awk 'NR==2 {print $4}') / 1024))
if (( avail_mb < MIN_FREE_MB )); then
  log_error "only ${avail_mb}MB free in $BACKUP_DIR (need ≥ ${MIN_FREE_MB}MB; tune BACKUP_MIN_FREE_MB)."
  exit 1
fi

db_file="$BACKUP_DIR/db-$STAMP.dump"
objects_file="$BACKUP_DIR/objects-$STAMP.tar.gz"

# —— 1) PostgreSQL logical dump (consistent MVCC snapshot, no downtime) ———
log_info "Dumping database to $db_file …"
compose exec -T database sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$db_file"

# pg_dump custom format starts with the PGDMP magic; an empty/garbage file
# means the dump failed in a way the exit code did not surface. (LC_ALL=C + -a
# so grep treats the binary header as text across grep implementations.)
if ! head -c 5 "$db_file" | LC_ALL=C grep -qa 'PGDMP'; then
  log_error "dump verification failed: $db_file is not a pg_dump custom archive."
  exit 1
fi

# A valid header says nothing about a dump truncated mid-write (connection drop,
# disk full, OOM). pg_restore -l parses the whole table-of-contents and fails on
# a corrupt/partial archive — the only cheap proof the dump is restorable.
if ! compose exec -T database pg_restore -l > /dev/null 2>&1 < "$db_file"; then
  log_error "dump verification failed: pg_restore could not read $db_file (truncated/corrupt)."
  exit 1
fi

# —— 2) Object-storage archive (immutable content-addressed files) ————————
log_info "Archiving volume $STORAGE_VOLUME to $objects_file …"
docker run --rm \
  -v "$STORAGE_VOLUME":/src:ro \
  -v "$BACKUP_DIR":/dst \
  alpine tar czf "/dst/$(basename "$objects_file")" -C /src .

# The archive is created by root inside the container, which ignores the host
# umask — tighten it to match the dump's 0600 (it carries the same PII).
chmod 600 "$objects_file"

tar -tzf "$objects_file" > /dev/null

# —— 3) Local retention ———————————————————————————————————————————————————
# Retention intentionally runs before offsite sync.
# Local backups are the source of truth; offsite storage is a secondary copy.
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
