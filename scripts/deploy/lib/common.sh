# =============================================================================
# lib/common.sh — shared helpers for the paired backup/restore scripts
# =============================================================================
# Sourced by backup-prod.sh and restore-prod.sh. The caller must already have
# cd'd to the repo root and set COMPOSE_PROJECT_NAME, ENV_FILE and STORAGE_VOLUME
# before invoking compose()/require_running_stack()/verify_dump().
#
# Not executable on its own; meant for `source`.

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; YELLOW='\033[0;33m'; NC='\033[0m'
log_info()    { echo -e "${BLUE}ℹ ${*}${NC}"; return 0; }
log_success() { echo -e "${GREEN}✔ ${*}${NC}"; return 0; }
log_warn()    { echo -e "${YELLOW}! ${*}${NC}"; return 0; }
log_error()   { echo -e "${RED}✗ ${*}${NC}" >&2; return 0; }

compose() {
  docker compose -p "$COMPOSE_PROJECT_NAME" --env-file "$ENV_FILE" \
    -f compose.yaml -f compose.prod.yaml "$@"
  return $?
}

# Preflight shared by both scripts: repo root, env file, docker, a RUNNING
# database service (a created/exited/restarting container has an id too but
# cannot answer pg_dump/pg_restore), and the storage volume actually existing
# (docker run -v would otherwise silently create a missing volume).
require_running_stack() {
  [[ -f compose.yaml ]] || { log_error "Run from the repo root (compose.yaml not found)."; exit 1; }
  [[ -f "$ENV_FILE" ]] || { log_error "$ENV_FILE not found — see 'make prod.env.check'."; exit 1; }
  command -v docker >/dev/null || { log_error "docker not found."; exit 1; }

  if [[ -z "$(compose ps --status running -q database)" ]]; then
    log_error "database service is not running (project '$COMPOSE_PROJECT_NAME')."
    exit 1
  fi

  if ! docker volume inspect "$STORAGE_VOLUME" >/dev/null 2>&1; then
    log_error "volume '$STORAGE_VOLUME' does not exist (project '$COMPOSE_PROJECT_NAME')."
    log_error "Check 'make docker.info' for the project name, or set STORAGE_VOLUME."
    exit 1
  fi

  return 0
}

# Prove a pg_dump custom-format archive is fully restorable. The PGDMP magic
# rules out an empty/garbage file; `pg_restore -f /dev/null` then reads and
# decompresses the WHOLE archive (TOC + every data block) so a dump truncated in
# its data section is rejected here — `pg_restore -l` only parses the leading TOC
# and would pass such a file. Runs in the database container; reads the host file
# over stdin and emits no SQL anywhere (no -d), so it has no side effects.
# $1 = path to the db-<stamp>.dump file.
verify_dump() {
  local file="$1"
  head -c 5 "$file" | LC_ALL=C grep -qa 'PGDMP' \
    || { log_error "$file is not a pg_dump custom archive."; return 1; }
  compose exec -T database pg_restore -f /dev/null > /dev/null 2>&1 < "$file" \
    || { log_error "$file is unreadable by pg_restore (truncated/corrupt)."; return 1; }
}

# Prove a gzip tar archive is intact end to end (gzip CRC + tar structure).
# $1 = path to the objects-<stamp>.tar.gz file.
verify_objects() {
  local file="$1"
  tar -tzf "$file" > /dev/null \
    || { log_error "$file is not a valid gzip archive."; return 1; }
}
