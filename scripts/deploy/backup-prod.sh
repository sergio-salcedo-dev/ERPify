#!/usr/bin/env bash
# =============================================================================
# backup-prod.sh — backup of all application state (Postgres)
# =============================================================================
#
# Produces one timestamped artifact in $BACKUP_DIR:
#   db-<stamp>.dump        PostgreSQL logical dump (pg_dump -Fc, MVCC-consistent)
#
# Restore the same stamp with restore-prod.sh (runbook:
# docs/vps-deployment.md § Backups).
#
# Knobs (env):
#   BACKUP_DIR             target directory            (default /var/backups/erpify)
#   RETENTION_DAYS         local retention             (default 14)
#   BACKUP_MIN_FREE_MB     min free space preflight    (default 500)
#   PROD_ENV_FILE          compose secrets file        (default .env.prod.local)
#   COMPOSE_PROJECT_NAME   compose project             (default erpify)
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

# Normalise to an absolute, SYMLINK-RESOLVED path. `pwd` alone is logical, and `find` defaults to `-P`: given
# a symlink as its starting point it examines the link, not the directory behind it — so on a host where
# BACKUP_DIR was relocated the usual way (a link to a bigger disk) retention would match nothing, report
# success, and let the directory grow until the free-space preflight starts aborting backups days later.
BACKUP_DIR="$(cd "$BACKUP_DIR" && pwd -P)"

# Prevent overlapping runs (a cron tick overrunning into the next, or a manual
# run racing cron — or a restore racing a backup; restore-prod.sh takes the same
# lock) from interleaving two dumps and from racing the retention prune.
# Non-blocking: a second run bails rather than queuing.
exec 9>"$BACKUP_DIR/.backup.lock"
flock -n 9 || { log_error "another backup/restore is already running (lock: $BACKUP_DIR/.backup.lock)."; exit 1; }

# Decide the artifact name only after the lock is held, so two runs can never
# pick the same name before one holds the lock. UTC: a local-time stamp repeats
# an hour at the DST fall-back, which would collide two artifacts.
STAMP="$(date -u +%F_%H%M%S)"

# Fail before dumping if the target filesystem is short on space: a dump that
# fills the disk leaves a half-written, unrestorable artifact.
avail_mb=$(($(df -Pk "$BACKUP_DIR" | awk 'NR==2 {print $4}') / 1024))
if (( avail_mb < MIN_FREE_MB )); then
  log_error "only ${avail_mb}MB free in $BACKUP_DIR (need ≥ ${MIN_FREE_MB}MB; tune BACKUP_MIN_FREE_MB)."
  exit 1
fi

db_file="$BACKUP_DIR/db-$STAMP.dump"

# —— 1) PostgreSQL logical dump (consistent MVCC snapshot, no downtime) ———
log_info "Dumping database to $db_file …"
compose exec -T database sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$db_file"

# Prove the dump is fully restorable (magic + full read-back), not just headed.
verify_dump "$db_file" || exit 1

# The byte count is the cheap smoke signal a cron log can be grepped or thresholded on: verify_dump proves
# the archive is readable, never that it holds what you expect, and an empty schema dumps clean. It is taken
# BEFORE anything is deleted, so a run that produced a suspicious artifact can be judged before retention has
# acted on its strength; `|| echo unknown` keeps a cosmetic value from aborting a run under `set -e` after a
# good dump and before the offsite sync. `stat` is exact and unpadded, unlike `wc -c`.
db_bytes=$(stat -c %s "$db_file" 2>/dev/null || echo unknown)
# A plain line as well as the decorated one: log_success emits unconditional ANSI colour, and the cron log
# this number exists to be compared in is a file, not a terminal.
echo "backup_bytes=$db_bytes"

# —— 2) Local retention ———————————————————————————————————————————————————
# Retention intentionally runs before offsite sync.
# Local backups are the source of truth; offsite storage is a secondary copy.
#
# It expires the one artifact kind this script writes, and nothing else. A
# $BACKUP_DIR holding an objects-<stamp>.tar.gz beside a dump is holding an
# archive no run here produces or expires: sweeping those is a one-off operator
# task, documented in docs/vps-deployment.md § Backups.
# A pipeline rather than a process substitution, so `pipefail` sees a `find` that failed instead of the loop
# reading zero lines and the run reporting success over a retention that degraded to a no-op. `-print0` is
# what makes a filename containing a newline impossible to split into two paths, the second of which would
# resolve relative to the repo root.
find "$BACKUP_DIR" -maxdepth 1 -name 'db-*.dump' -mtime +"$RETENTION_DAYS" -print0 |
  while IFS= read -r -d '' expired_artifact; do
    rm -f -- "$expired_artifact"
    log_info "Expired $expired_artifact"
  done

# The detective half of the decision to stop expiring object archives: this script steps over them, so
# without a line here the only thing that knows they exist is a runbook nobody is prompted to read. They
# carry the same personal data as the dump, so an unbounded pile of them is a retention problem, not clutter.
orphan_objects=$(find "$BACKUP_DIR" -maxdepth 1 -name 'objects-*.tar.gz' -printf . 2>/dev/null | wc -c)
if (( orphan_objects > 0 )); then
  log_warn "$orphan_objects object archive(s) in $BACKUP_DIR that nothing here produces or expires."
  log_warn "They hold the same personal data as the dump — see docs/vps-deployment.md § Backups."
fi

log_success "Backup $STAMP complete: $db_file ($db_bytes bytes)"
ls -lh "$db_file"

# —— 3) Optional offsite sync ————————————————————————————————————————————
# The command may embed credentials/tokens — log a static line, never the value.
if [[ -n "${BACKUP_SYNC_CMD:-}" ]]; then
  log_info "Running offsite sync hook …"
  sh -c "$BACKUP_SYNC_CMD"
  log_success "Offsite sync done."
fi
