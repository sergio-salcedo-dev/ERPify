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
#   5. trust      — export the internal CA root and (Linux, no sudo) add it to
#                   the per-user NSS store so Chrome/Chromium/Edge trust it
#                   (unless --no-trust)
#   6. next steps — print ONLY the remaining copy/paste commands (hosts entry,
#                   system-trust import, Firefox) needed to reach a *trusted*
#                   https://$SERVER_NAME; already-done steps are skipped
#
# Usage: scripts/deploy/deploy-local.sh [--dry-run] [--skip-migrations] [--no-trust] [-h]
# Also reachable as `make deploy.local`.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

ENV_FILE="${PROD_ENV_FILE:-.env.prod.local}"
HEALTH_PATH="${HEALTH_PATH:-/api/v1/health}"
CA_PATH_IN_CONTAINER="/data/caddy/pki/authorities/local/root.crt"
CA_OUT="erpify-local-root-ca.crt"
# Make `docker compose` target the same project make uses, regardless of cwd basename.
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-erpify}"

DRY_RUN=0
SKIP_MIGRATIONS=0
TRUST=1

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
log_info()    { echo -e "${BLUE}ℹ ${*}${NC}"; }
log_success() { echo -e "${GREEN}✔ ${*}${NC}"; }
log_warning() { echo -e "${YELLOW}⚠ ${*}${NC}"; }
log_error()   { echo -e "${RED}✖ ${*}${NC}" >&2; }

run() {
    local desc="$1"
    local cmd="$2"
    log_info "$desc"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "  ${YELLOW}[DRY-RUN]${NC} $cmd"
        return 0
    fi
    eval "$cmd"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --skip-migrations) SKIP_MIGRATIONS=1; shift ;;
        --no-trust) TRUST=0; shift ;;
        -h|--help)
            grep -E '^# ' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) log_error "Unknown option: $1"; exit 1 ;;
    esac
done

MAKE="make ENV=prod PROD_ENV_FILE=$ENV_FILE"
COMPOSE="docker compose --env-file $ENV_FILE -f compose.yaml -f compose.prod.yaml"
OS="$(uname -s)"

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
NSS_DB="$HOME/.pki/nssdb"
NSS_NICK="${SERVER_NAME} Local CA"

# Authoritative check is the /etc/hosts entry itself — `getent` would resolve a
# `*.local` name via mDNS/avahi even with no hosts entry (false positive) and
# would not flag a conflicting IP.
HOSTS_OK=0
if grep -qE "^[[:space:]]*[0-9.]+([[:space:]]+[^[:space:]#]+)*[[:space:]]+${SERVER_NAME}([[:space:]]|\$)" /etc/hosts 2>/dev/null; then
    HOSTS_OK=1
    log_success "${SERVER_NAME} has an /etc/hosts entry."
else
    log_warning "${SERVER_NAME} has no /etc/hosts entry yet (non-fatal; fixed in the next-steps below)."
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

# —— 5. Client trust (auto, no sudo): export CA + add to Chromium's NSS store ——
# Best-effort: a public/ACME profile has no internal CA, and a transient trust
# failure must never abort an otherwise-healthy deploy.
NSS_TRUSTED=0
if [[ $TRUST -eq 1 ]]; then
    # Public TLS (ACME) ships no internal CA root to export — clients trust the
    # public chain, so there is nothing to install locally.
    SERVER_DIRECTIVES="$(grep -E '^CADDY_SERVER_EXTRA_DIRECTIVES=' "$ENV_FILE" 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"' | xargs || true)"
    if [[ -z "${SERVER_DIRECTIVES//[[:space:]]/}" ]]; then
        log_info "Public/ACME TLS profile detected — clients trust the public CA; skipping internal-CA export."
    else
        log_info "Setting up client trust for the internal CA..."
        # 5a. Export the CA root (idempotent — refreshes after a CA rotation).
        #     Non-fatal: a missing CA must not fail the deploy.
        if run "Exporting internal CA root → ./${CA_OUT}" \
               "$COMPOSE cp php:${CA_PATH_IN_CONTAINER} ./${CA_OUT}"; then
            # 5b. Chrome/Chromium/Edge on Linux read the per-user NSS store, NOT
            #     the system bundle — add it there without sudo when certutil is
            #     available. Each step is best-effort.
            if [[ "$OS" == "Linux" ]]; then
                if [[ $DRY_RUN -eq 1 ]]; then
                    echo -e "  ${YELLOW}[DRY-RUN]${NC} certutil -d sql:${NSS_DB} -A -t 'C,,' -n '${NSS_NICK}' -i ./${CA_OUT}"
                elif command -v certutil >/dev/null 2>&1; then
                    mkdir -p "$NSS_DB"
                    [[ -f "$NSS_DB/cert9.db" ]] || certutil -d sql:"$NSS_DB" -N --empty-password || true
                    certutil -d sql:"$NSS_DB" -D -n "$NSS_NICK" 2>/dev/null || true   # drop stale entry first
                    if certutil -d sql:"$NSS_DB" -A -t "C,," -n "$NSS_NICK" -i "./${CA_OUT}"; then
                        NSS_TRUSTED=1
                        log_success "Chrome/Chromium/Edge trust set (NSS: ${NSS_DB})."
                    else
                        log_warning "Failed to add CA to the NSS store — Chromium-family trust deferred to next-steps."
                    fi
                else
                    log_warning "certutil not found — Chromium-family trust deferred to next-steps."
                fi
            fi
        else
            log_warning "Could not export the internal CA (not found?) — skipping client-trust setup."
        fi
    fi
else
    log_info "Skipping client-trust setup (--no-trust)."
fi

# —— 6. Next steps ————————————————————————————————————————————————————————
# Detect whether CLI/system trust already validates the chain (curl w/o -k).
SYS_TRUSTED=0
if [[ $DRY_RUN -eq 0 && $HOSTS_OK -eq 1 ]] && \
   curl -sS -o /dev/null --connect-timeout 3 "$HEALTH_URL" 2>/dev/null; then
    SYS_TRUSTED=1
fi

echo
log_success "Deploy complete — ${SERVER_NAME} serves the PWA + /api/* over Caddy internal TLS."
echo

# What still needs a privileged (root) action?
NEED_HOSTS=0; [[ $HOSTS_OK   -eq 0 ]] && NEED_HOSTS=1
NEED_SYS=0;   [[ $SYS_TRUSTED -eq 0 ]] && NEED_SYS=1
NEED_NSS=0;   [[ "$OS" == "Linux" && $NSS_TRUSTED -eq 0 ]] && NEED_NSS=1
PRIV_PENDING=$(( NEED_HOSTS + NEED_SYS + NEED_NSS ))

if [[ $PRIV_PENDING -gt 0 || $DRY_RUN -eq 1 ]]; then
    echo "To reach https://${SERVER_NAME} with a TRUSTED certificate:"
    echo
    echo -e "  ${GREEN}▶ Easiest — do every root-requiring trust step in ONE command:${NC}"
    echo -e "        ${YELLOW}sudo make deploy.local.trust${NC}"
    echo "      (adds the /etc/hosts entry, installs the CA into the system trust"
    echo "       store, and adds it to YOUR Chromium/NSS store via \$SUDO_USER)"
    echo "      It writes files OUTSIDE this repo (/etc/hosts, the OS CA store,"
    echo "      ~/.pki/nssdb). Preview them first, no sudo:"
    echo "        bash scripts/deploy/trust-local.sh --dry-run"
    echo
    echo "  …or run them yourself — each tagged whether it needs sudo:"
    echo
    n=1
    if [[ $NEED_HOSTS -eq 1 || $DRY_RUN -eq 1 ]]; then
        echo -e "    ${n}) ${YELLOW}[sudo]${NC} make ${SERVER_NAME} resolve:"
        echo    "         echo '${HOSTS_LINE}' | sudo tee -a /etc/hosts"
        n=$((n + 1))
    fi
    if [[ $NEED_SYS -eq 1 || $DRY_RUN -eq 1 ]]; then
        if [[ "$OS" == "Darwin" ]]; then
            echo -e "    ${n}) ${YELLOW}[sudo]${NC} trust the CA system-wide (Chrome/Safari + CLI):"
            echo    "         sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ./${CA_OUT}"
        else
            echo -e "    ${n}) ${YELLOW}[sudo]${NC} trust the CA for CLI tools (curl/openssl/Node):"
            echo    "         sudo cp ./${CA_OUT} /usr/local/share/ca-certificates/${CA_OUT} && sudo update-ca-certificates"
        fi
        n=$((n + 1))
    fi
    if [[ $NEED_NSS -eq 1 || $DRY_RUN -eq 1 ]]; then
        echo -e "    ${n}) ${YELLOW}[sudo once]${NC} Chrome/Chromium/Edge (per-user NSS):"
        echo    "         sudo apt-get install -y libnss3-tools   # once, provides certutil"
        echo    "         certutil -d sql:\$HOME/.pki/nssdb -A -t 'C,,' -n '${NSS_NICK}' -i ./${CA_OUT}"
        n=$((n + 1))
    fi
    echo -e "    ${n}) ${GREEN}[no sudo — GUI]${NC} Firefox: import ./${CA_OUT} via about:preferences#privacy"
    echo    "         → View Certificates → Authorities → Import → 'Trust this CA to identify websites'."
    echo
    echo "  How to continue: restart the browser, then open  https://${SERVER_NAME}"
    echo "  (re-run 'make deploy.local' to re-verify trust — already-done steps drop off.)"
else
    log_success "Hosts + CLI + Chromium trust already in place. Just open:  https://${SERVER_NAME}"
    echo "  (Firefox keeps its own store — import ./${CA_OUT} via its GUI if you use Firefox.)"
fi
echo "Full per-OS trust guide: docs/erpify-local-test-deployment.md (step 4)."
