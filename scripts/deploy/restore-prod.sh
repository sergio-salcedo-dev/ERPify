#!/usr/bin/env bash
# =============================================================================
# restore-prod.sh — paired restore of the prod stack's two stateful volumes
# =============================================================================
#
# The inverse of backup-prod.sh: restores a <stamp> pair (objects FIRST, then
# the DB dump of the SAME stamp) produced by `make backup.prod`.
#
# DESTRUCTIVE: wipes the object-storage volume and runs `pg_restore --clean`
# over the live database. It is meant first for the **restore drill and pre-prod
# verification** — prove a backup restores on the erpify.local rehearsal or a
# scratch worktree stack before you ever need it for real. Both artifacts are
# verified up front, so a corrupt backup is caught before any live data is
# touched, and the run refuses to proceed without an explicit confirmation.
#
# Environment guard: when SERVER_NAME (from the env file) is a real domain the
# run is treated as PRODUCTION and requires a second opt-in (ALLOW_PROD_RESTORE)
# plus a typed phrase that includes the stamp — RESTORE_YES is ignored there, so
# a production restore can never be scripted/unattended.
#
# Knobs (env):
#   STAMP                  pair to restore (or pass as $1); omit to list options
#   BACKUP_DIR             source directory            (default /var/backups/erpify)
#   PROD_ENV_FILE          compose secrets file        (default .env.prod.local)
#   COMPOSE_PROJECT_NAME   compose project             (default erpify)
#   OBJECT_STORAGE_VOLUME  volume to restore into      (default <project>_object_storage_data)
#   RESTORE_YES            set to 1 to skip confirmation — NON-production only
#   ALLOW_PROD_RESTORE     set to 1 to permit a restore when SERVER_NAME is a real domain
#
# Usage: scripts/deploy/restore-prod.sh <stamp>   (or `STAMP=<stamp> make restore.prod`)
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

ENV_FILE="${PROD_ENV_FILE:-.env.prod.local}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/erpify}"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-erpify}"
OBJECT_STORAGE_VOLUME="${OBJECT_STORAGE_VOLUME:-${COMPOSE_PROJECT_NAME}_object_storage_data}"
STAMP="${1:-${STAMP:-}}"

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; YELLOW='\033[0;33m'; NC='\033[0m'
log_info()    { echo -e "${BLUE}ℹ ${*}${NC}"; }
log_success() { echo -e "${GREEN}✔ ${*}${NC}"; }
log_warn()    { echo -e "${YELLOW}! ${*}${NC}"; }
log_error()   { echo -e "${RED}✗ ${*}${NC}" >&2; }

compose() {
  docker compose -p "$COMPOSE_PROJECT_NAME" --env-file "$ENV_FILE" \
    -f compose.yaml -f compose.prod.yaml "$@"
}

list_stamps() {
  find "$BACKUP_DIR" -maxdepth 1 -name 'db-*.dump' -printf '%f\n' 2>/dev/null \
    | sed -E 's/^db-(.*)\.dump$/\1/' | sort
}

# —— Preflight ————————————————————————————————————————————————————————————
[[ -f compose.yaml ]] || { log_error "Run from the repo root (compose.yaml not found)."; exit 1; }
[[ -f "$ENV_FILE" ]] || { log_error "$ENV_FILE not found — see 'make prod.env.check'."; exit 1; }
command -v docker >/dev/null || { log_error "docker not found."; exit 1; }

if [[ -z "$(compose ps --status running -q database)" ]]; then
  log_error "database service is not running (project '$COMPOSE_PROJECT_NAME')."
  exit 1
fi

if ! docker volume inspect "$OBJECT_STORAGE_VOLUME" >/dev/null 2>&1; then
  log_error "volume '$OBJECT_STORAGE_VOLUME' does not exist (project '$COMPOSE_PROJECT_NAME')."
  exit 1
fi

# Classify the target: a non-.local, non-localhost SERVER_NAME is a real
# deployment, which gates the stricter production confirmation below.
SERVER_NAME="$(grep -E '^SERVER_NAME=' "$ENV_FILE" 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' || true)"
IS_PROD=0
for host in $SERVER_NAME; do
  case "$host" in
    *.local|localhost|127.0.0.1|*.localhost|"") : ;;
    *.*) IS_PROD=1 ;;
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
log_info "Verifying backup pair $STAMP …"
head -c 5 "$db_file" | LC_ALL=C grep -qa 'PGDMP' \
  || { log_error "$db_file is not a pg_dump custom archive."; exit 1; }
compose exec -T database pg_restore -l > /dev/null 2>&1 < "$db_file" \
  || { log_error "$db_file is unreadable by pg_restore (truncated/corrupt)."; exit 1; }
tar -tzf "$objects_file" > /dev/null \
  || { log_error "$objects_file is not a valid gzip archive."; exit 1; }
log_success "Both artifacts verified (sizes below)."
ls -lh "$db_file" "$objects_file"

# —— Confirm (destructive) ————————————————————————————————————————————————
confirm_tty() { # $1 = expected phrase, $2 = prompt
  if [[ ! -r /dev/tty ]]; then
    log_error "no TTY for confirmation — refusing to run unattended."
    exit 1
  fi
  local reply
  printf '%s' "$2" > /dev/tty
  read -r reply < /dev/tty
  [[ "$reply" == "$1" ]] || { log_error "confirmation did not match — aborted."; exit 1; }
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
  log_warn "  5. You are on the correct host (project '$COMPOSE_PROJECT_NAME', volume '$OBJECT_STORAGE_VOLUME')."
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
  log_warn "  • wipes volume $OBJECT_STORAGE_VOLUME and unpacks objects-$STAMP.tar.gz"
  log_warn "  • pg_restore --clean over the current database (db-$STAMP.dump)"
  confirm_tty "$COMPOSE_PROJECT_NAME" \
    "Type the project name ($COMPOSE_PROJECT_NAME) to proceed: "
fi

# —— Restore (reverse order: stop writers → objects → DB → start writers) ——
log_info "Stopping writers (php, messenger_worker) …"
compose stop php messenger_worker

log_info "Restoring objects into $OBJECT_STORAGE_VOLUME …"
obj_base="$(basename "$objects_file")"
docker run --rm \
  -v "$OBJECT_STORAGE_VOLUME":/dst \
  -v "$BACKUP_DIR":/src:ro \
  -e OBJ="$obj_base" \
  alpine sh -c 'find /dst -mindepth 1 -delete && tar xzf "/src/$OBJ" -C /dst'

log_info "Restoring database from $db_file …"
compose exec -T database sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --exit-on-error' < "$db_file"

log_info "Starting writers (php, messenger_worker) …"
compose start php messenger_worker

log_success "Restore of pair $STAMP complete."
log_info "Smoke test: GET /api/v1/stored-objects/{hash} for a known object → expect 200 with 'Cache-Control: … immutable'."
