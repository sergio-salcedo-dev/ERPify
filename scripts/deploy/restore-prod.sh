#!/usr/bin/env bash
# =============================================================================
# restore-prod.sh — paired restore of the prod stack's two stateful volumes
# =============================================================================
#
# The inverse of backup-prod.sh: restores a <stamp> pair (objects FIRST, then
# the DB dump of the SAME stamp) produced by `make backup.prod`.
#
# DESTRUCTIVE: wipes the object-storage volume and runs pg_restore (in a single
# transaction) over the live database. It is meant first for the **restore drill
# and pre-prod verification** — prove a backup restores on the erpify.local
# rehearsal or a scratch worktree stack before you ever need it for real. Both
# artifacts are fully verified up front, so a corrupt backup is caught before any
# live data is touched, and the run refuses to proceed without an explicit
# confirmation. The writers are restarted on any exit, so a failed restore never
# leaves the app headless.
#
# Environment guard: when SERVER_NAME (from the env file) is anything other than
# an explicitly local host the run is treated as PRODUCTION and requires a second
# opt-in (ALLOW_PROD_RESTORE) plus a typed phrase that includes the stamp —
# RESTORE_YES is ignored there, so a production restore can never be
# scripted/unattended.
#
# Knobs (env):
#   STAMP                  pair to restore (or pass as $1); omit to list options
#   BACKUP_DIR             source directory            (default /var/backups/erpify)
#   PROD_ENV_FILE          compose secrets file        (default .env.prod.local)
#   COMPOSE_PROJECT_NAME   compose project             (default erpify)
#   STORAGE_VOLUME         volume to restore into      (default <project>_storage_data)
#   RESTORE_YES            set to 1 to skip confirmation — NON-production only
#   ALLOW_PROD_RESTORE     set to 1 to permit a restore when SERVER_NAME is non-local
#
# Usage: scripts/deploy/restore-prod.sh <stamp>   (or `STAMP=<stamp> make restore.prod`)
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

ENV_FILE="${PROD_ENV_FILE:-.env.prod.local}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/erpify}"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-erpify}"
STORAGE_VOLUME="${STORAGE_VOLUME:-${COMPOSE_PROJECT_NAME}_storage_data}"
STAMP="${1:-${STAMP:-}}"

source "$REPO_ROOT/scripts/deploy/lib/common.sh"

list_stamps() {
  find "$BACKUP_DIR" -maxdepth 1 -name 'db-*.dump' -printf '%f\n' 2>/dev/null \
    | sed -E 's/^db-(.*)\.dump$/\1/' | sort
}

# —— Preflight ————————————————————————————————————————————————————————————
require_running_stack
command -v flock >/dev/null || { log_error "flock not found (util-linux)."; exit 1; }
[[ -d "$BACKUP_DIR" ]] || { log_error "BACKUP_DIR '$BACKUP_DIR' does not exist."; exit 1; }

# Resolve to an absolute path: a relative BACKUP_DIR would make the `docker run
# -v "$BACKUP_DIR":/src` below read from a *named volume* of that name instead of
# this directory.
BACKUP_DIR="$(cd "$BACKUP_DIR" && pwd)"

# Serialize against backup-prod.sh via the shared lock: a cron backup must not
# archive the storage volume / dump the DB while this restore is wiping and
# reloading them (which would produce a silently corrupt backup).
exec 9>"$BACKUP_DIR/.backup.lock"
flock -n 9 || { log_error "a backup or restore is already running (lock: $BACKUP_DIR/.backup.lock)."; exit 1; }

# Classify the target. Fail safe: ONLY an explicitly local SERVER_NAME is treated
# as non-production. A real domain, an IP, or a bare internal hostname all gate
# the stricter production confirmation below — an unrecognised target must never
# silently fall through to the scriptable non-prod path.
SERVER_NAME="$(grep -E '^SERVER_NAME=' "$ENV_FILE" 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' || true)"
IS_PROD=0
for host in $SERVER_NAME; do
  case "$host" in
    *.local|*.localhost|localhost|127.0.0.1|::1) : ;;
    *) IS_PROD=1 ;;
  esac
done

# —— Resolve the stamp ————————————————————————————————————————————————————
if [[ -z "$STAMP" ]]; then
  log_error "no STAMP given. Available pairs in $BACKUP_DIR:"
  list_stamps | sed 's/^/    /' >&2 || true
  log_error "Re-run: scripts/deploy/restore-prod.sh <stamp>   (or STAMP=<stamp> make restore.prod)"
  exit 1
fi

db_file="$BACKUP_DIR/db-$STAMP.dump"
objects_file="$BACKUP_DIR/objects-$STAMP.tar.gz"

[[ -f "$db_file" ]] || { log_error "missing dump: $db_file"; exit 1; }
[[ -f "$objects_file" ]] || { log_error "missing objects archive: $objects_file"; exit 1; }

# —— Verify the artifacts BEFORE touching live data ————————————————————————
# Full read-back (not just the TOC), so a dump truncated in its data section is
# rejected here rather than failing halfway through the destructive restore.
log_info "Verifying backup pair $STAMP …"
verify_dump "$db_file" || exit 1
verify_objects "$objects_file" || exit 1
log_success "Both artifacts verified (sizes below)."
ls -lh "$db_file" "$objects_file"

# —— Confirm (destructive) ————————————————————————————————————————————————
confirm_tty() { # $1 = expected phrase, $2 = prompt
  local expected="$1" prompt="$2"
  if [[ ! -r /dev/tty ]]; then
    log_error "no TTY for confirmation — refusing to run unattended."
    exit 1
  fi
  local reply
  printf '%s' "$prompt" > /dev/tty
  read -r reply < /dev/tty
  [[ "$reply" == "$expected" ]] || { log_error "confirmation did not match — aborted."; exit 1; }
}

if [[ "$IS_PROD" == "1" ]]; then
  log_warn "════════════════════════════════════════════════════════════════"
  log_warn " PRODUCTION restore — SERVER_NAME='$SERVER_NAME', project '$COMPOSE_PROJECT_NAME'"
  log_warn " This OVERWRITES live data and cannot be undone."
  log_warn "════════════════════════════════════════════════════════════════"
  log_warn "Mandatory checks before you continue:"
  log_warn "  1. A fresh backup of the CURRENT state exists (run 'make backup.prod' first)."
  log_warn "  2. '$STAMP' is the intended recovery point (check the sizes/date above)."
  log_warn "  3. A maintenance window is in effect — php/messenger_worker WILL be stopped (downtime)."
  log_warn "  4. The offsite copy of '$STAMP' is intact, in case this restore goes wrong."
  log_warn "  5. You are on the correct host (project '$COMPOSE_PROJECT_NAME', volume '$STORAGE_VOLUME')."
  if [[ "${ALLOW_PROD_RESTORE:-}" != "1" ]]; then
    log_error "production restore is gated: re-run with ALLOW_PROD_RESTORE=1 once the checklist is satisfied."
    exit 1
  fi
  # Typing the project AND the stamp guards against fat-fingering the wrong host
  # or the wrong recovery point. RESTORE_YES never bypasses this in production.
  confirm_tty "restore $COMPOSE_PROJECT_NAME $STAMP" \
    "Type 'restore $COMPOSE_PROJECT_NAME $STAMP' to proceed: "
elif [[ "${RESTORE_YES:-}" != "1" ]]; then
  log_warn "This DESTROYS current data in project '$COMPOSE_PROJECT_NAME':"
  log_warn "  • wipes volume $STORAGE_VOLUME and unpacks objects-$STAMP.tar.gz"
  log_warn "  • pg_restore --clean over the current database (db-$STAMP.dump)"
  confirm_tty "$COMPOSE_PROJECT_NAME" \
    "Type the project name ($COMPOSE_PROJECT_NAME) to proceed: "
fi

# —— Restore (reverse order: stop writers → objects → DB → start writers) ——
# Once the writers are down, every exit path MUST bring them back: a failure
# mid-restore (corrupt archive, pg_restore error, Ctrl-C) otherwise leaves the
# app headless. The trap restarts them on any exit; `compose start` is
# idempotent, so the happy path clearing the flag below just makes it a no-op.
writers_stopped=0
restart_writers() {
  [[ "$writers_stopped" == "1" ]] || return 0
  log_info "Restarting writers (php, messenger_worker) …"
  compose start php messenger_worker \
    || log_error "could not restart writers — start them manually: 'compose start php messenger_worker'."
}
trap restart_writers EXIT
trap 'exit 130' INT TERM

log_info "Stopping writers (php, messenger_worker) …"
writers_stopped=1
compose stop php messenger_worker

log_info "Restoring objects into $STORAGE_VOLUME …"
obj_base="$(basename "$objects_file")"
docker run --rm \
  -v "$STORAGE_VOLUME":/dst \
  -v "$BACKUP_DIR":/src:ro \
  -e OBJ="$obj_base" \
  alpine sh -c 'find /dst -mindepth 1 -delete && tar xzf "/src/$OBJ" -C /dst'

# --single-transaction wraps the whole restore (including the --clean DROPs) in
# one BEGIN/COMMIT: any error rolls the database back to its pre-restore state
# instead of leaving it half-restored. It implies --exit-on-error.
log_info "Restoring database from $db_file …"
compose exec -T database sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --single-transaction' < "$db_file"

log_info "Starting writers (php, messenger_worker) …"
compose start php messenger_worker
writers_stopped=0

log_success "Restore of pair $STAMP complete."
log_info "Smoke test: GET /api/v1/stored-objects/{hash} for a known object → expect 200 with 'Cache-Control: … immutable'."
