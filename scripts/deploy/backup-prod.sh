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

source "$REPO_ROOT/scripts/deploy/lib/common.sh"

# —— Preflight ————————————————————————————————————————————————————————————
require_running_stack
command -v flock >/dev/null || { log_error "flock not found (util-linux)."; exit 1; }

# A non-numeric RETENTION_DAYS makes the later `find -mtime` error out mid-run
# (after a successful dump) or silently prune nothing.
[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || { log_error "RETENTION_DAYS must be a non-negative integer (got '$RETENTION_DAYS')."; exit 1; }
[[ "$MIN_FREE_MB" =~ ^[0-9]+$ ]] || { log_error "BACKUP_MIN_FREE_MB must be a non-negative integer (got '$MIN_FREE_MB')."; exit 1; }

# Dumps contain business data — keep the dir and every artifact unreadable to
# other users. umask must precede mkdir so a freshly created BACKUP_DIR is not
# left world-traversable by the default 022 mask.
umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# Resolve to an absolute path: a relative BACKUP_DIR would make the `docker run
# -v "$BACKUP_DIR":/dst` below create a *named volume* of that name instead of
# bind-mounting the directory, splitting the pair across two locations.
BACKUP_DIR="$(cd "$BACKUP_DIR" && pwd)"

# Prevent overlapping runs (a cron tick overrunning into the next, or a manual
# run racing cron — or a restore racing a backup; restore-prod.sh takes the same
# lock) from interleaving two dumps/archives and from racing the retention
# prune. Non-blocking: a second run bails rather than queuing.
exec 9>"$BACKUP_DIR/.backup.lock"
flock -n 9 || { log_error "another backup/restore is already running (lock: $BACKUP_DIR/.backup.lock)."; exit 1; }

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

# Prove the dump is fully restorable (magic + full read-back), not just headed.
verify_dump "$db_file" || exit 1

# —— 2) Object-storage archive (immutable content-addressed files) ————————
# umask 077 inside the container so the archive is created 0600 from the start:
# it carries the same PII as the dump, and the container root would otherwise
# create it 0644, leaving a world-readable window before any post-hoc chmod.
log_info "Archiving volume $STORAGE_VOLUME to $objects_file …"
docker run --rm \
  -v "$STORAGE_VOLUME":/src:ro \
  -v "$BACKUP_DIR":/dst \
  -e OBJ="$(basename "$objects_file")" \
  alpine sh -c 'umask 077; tar czf "/dst/$OBJ" -C /src .'

verify_objects "$objects_file" || exit 1

# —— 3) Local retention ———————————————————————————————————————————————————
# Retention intentionally runs before offsite sync.
# Local backups are the source of truth; offsite storage is a secondary copy.
# Prune by PAIR, driven by the db dump's age, so a stamp's two artifacts are
# always removed together — never a db dump without its objects (or vice versa).
while IFS= read -r expired_dump; do
  [[ -n "$expired_dump" ]] || continue
  expired_stamp="$(basename "$expired_dump")"; expired_stamp="${expired_stamp#db-}"; expired_stamp="${expired_stamp%.dump}"
  rm -f -- "$expired_dump" "$BACKUP_DIR/objects-$expired_stamp.tar.gz"
done < <(find "$BACKUP_DIR" -maxdepth 1 -name 'db-*.dump' -mtime +"$RETENTION_DAYS")

log_success "Backup pair $STAMP complete:"
ls -lh "$db_file" "$objects_file"

# —— 4) Optional offsite sync ————————————————————————————————————————————
# The command may embed credentials/tokens — log a static line, never the value.
if [[ -n "${BACKUP_SYNC_CMD:-}" ]]; then
  log_info "Running offsite sync hook …"
  sh -c "$BACKUP_SYNC_CMD"
  log_success "Offsite sync done."
fi
