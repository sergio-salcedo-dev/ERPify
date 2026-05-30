#!/usr/bin/env bash
# =============================================================================
# deploy-local.sh — stand the PROD profile up on this host (default: erpify.local)
# =============================================================================
#
# Idempotent: re-running converges the stack. Steps:
#   1. preflight  — docker present, secrets complete (make prod.env.check),
#                   /etc/hosts resolves SERVER_NAME (warn, non-fatal)
#   2. up         — make docker.up.wait ENV=prod  (build + health gate)
#   3. migrate    — make db.migrate ENV=prod       (unless --skip-migrations)
#   4. smoke      — curl -k https://$SERVER_NAME/api/v1/health  (expects 200)
#   5. hints      — print the CA-trust export + /etc/hosts one-liners
#
# Usage: scripts/deploy/deploy-local.sh [--dry-run] [--skip-migrations] [-h]
# Also reachable as `make deploy.local`.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

ENV_FILE="${PROD_ENV_FILE:-.env.prod.local}"
HEALTH_PATH="${HEALTH_PATH:-/api/v1/health}"
CA_PATH_IN_CONTAINER="/data/caddy/pki/authorities/local/root.crt"
CA_OUT="erpify-local-root-ca.crt"

DRY_RUN=0
SKIP_MIGRATIONS=0

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
log_info()    { echo -e "${BLUE}ℹ ${*}${NC}"; }
log_success() { echo -e "${GREEN}✔ ${*}${NC}"; }
log_warning() { echo -e "${YELLOW}⚠ ${*}${NC}"; }
log_error()   { echo -e "${RED}✖ ${*}${NC}" >&2; }

run() { # run "description" "command"
    log_info "$1"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "  ${YELLOW}[DRY-RUN]${NC} $2"
        return 0
    fi
    eval "$2"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --skip-migrations) SKIP_MIGRATIONS=1; shift ;;
        -h|--help)
            grep -E '^# ' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) log_error "Unknown option: $1"; exit 1 ;;
    esac
done

MAKE="make ENV=prod PROD_ENV_FILE=$ENV_FILE"

# —— 1. Preflight ——————————————————————————————————————————————————————————
log_info "Preflight checks..."
command -v docker >/dev/null 2>&1 || { log_error "docker not found on PATH."; exit 1; }
docker compose version >/dev/null 2>&1 || { log_error "'docker compose' v2 not available."; exit 1; }

# Secret guard — fails fast with copy-from-example instructions.
make PROD_ENV_FILE="$ENV_FILE" prod.env.check

# Resolve the served host from the env file (default erpify.local).
SERVER_NAME="$(grep -E '^SERVER_NAME=' "$ENV_FILE" 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"' | xargs || true)"
SERVER_NAME="${SERVER_NAME:-erpify.local}"
HOSTS_LINE="127.0.0.1   ${SERVER_NAME}"

if getent hosts "$SERVER_NAME" >/dev/null 2>&1 || grep -qE "[[:space:]]${SERVER_NAME}([[:space:]]|\$)" /etc/hosts 2>/dev/null; then
    log_success "${SERVER_NAME} resolves."
else
    log_warning "${SERVER_NAME} is not resolvable. Add this line to /etc/hosts (non-fatal):"
    echo "    ${HOSTS_LINE}"
fi

# —— 2. Bring the stack up ————————————————————————————————————————————————
run "Starting prod stack (build + health gate)" "$MAKE docker.up.wait"

# —— 3. Migrations ————————————————————————————————————————————————————————
if [[ $SKIP_MIGRATIONS -eq 0 ]]; then
    run "Applying database migrations" "$MAKE db.migrate"
else
    log_warning "Skipping migrations (--skip-migrations)."
fi

# —— 4. Smoke test ————————————————————————————————————————————————————————
HEALTH_URL="https://${SERVER_NAME}${HEALTH_PATH}"
if [[ $DRY_RUN -eq 1 ]]; then
    log_info "[DRY-RUN] Would smoke-test ${HEALTH_URL}"
else
    log_info "Smoke-testing ${HEALTH_URL} ..."
    code=000
    for attempt in 1 2 3 4 5 6; do
        code="$(curl -skS -o /dev/null -w '%{http_code}' --connect-timeout 3 "$HEALTH_URL" 2>/dev/null || echo 000)"
        [[ "$code" == "200" ]] && break
        log_warning "  attempt ${attempt}: HTTP ${code} — retrying in 5s..."
        sleep 5
    done
    if [[ "$code" == "200" ]]; then
        log_success "Health check OK (200)."
    else
        log_error "Health check failed (last HTTP ${code}). Inspect: $MAKE docker.logs"
        exit 1
    fi
fi

# —— 5. Trust + hosts hints ———————————————————————————————————————————————
cat <<EOF

$(echo -e "${GREEN}✔ Deploy complete.${NC}") ${SERVER_NAME} is serving the PWA + /api/* over Caddy internal TLS.

To trust the internal CA on this client (so the browser shows a valid cert):
    docker compose --env-file ${ENV_FILE} -f compose.yaml -f compose.prod.yaml \\
        cp php:${CA_PATH_IN_CONTAINER} ./${CA_OUT}
  Then import ./${CA_OUT} into your OS/browser trust store
  (see docs/erpify-local-test-deployment.md for per-OS steps).

If the browser cannot resolve ${SERVER_NAME}, add to /etc/hosts:
    ${HOSTS_LINE}
EOF
