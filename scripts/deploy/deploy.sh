#!/bin/bash
set -euo pipefail

# --- Configuration & Colors ---
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export REPO_ROOT

# Initialize variables
DRY_RUN=0
SKIP_MIGRATIONS=0
CI_MODE=0
PROFILE=""
HEALTH_URL="${HEALTH_URL:-https://localhost/api/v1/health}"

# Color Definitions
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
WHITE='\033[1;37m'
NC='\033[0m'

# --- Helper Functions ---

log_info() {
    local timestamp
    if [[ $CI_MODE -eq 1 ]]; then
        timestamp=$(date +"%Y-%m-%d %H:%M:%S")
        echo "[$timestamp] [INFO] $*"
    else
        echo -e "${BLUE}ℹ ${*}${NC}"
    fi
}

log_success() {
    local timestamp
    if [[ $CI_MODE -eq 1 ]]; then
        timestamp=$(date +"%Y-%m-%d %H:%M:%S")
        echo "[$timestamp] [SUCCESS] $*"
    else
        echo -e "${GREEN}✔ ${*}${NC}"
    fi
}

log_warning() {
    local timestamp
    if [[ $CI_MODE -eq 1 ]]; then
        timestamp=$(date +"%Y-%m-%d %H:%M:%S")
        echo "[$timestamp] [WARN] $*"
    else
        echo -e "${YELLOW}⚠ ${*}${NC}"
    fi
}

log_error() {
    local timestamp
    if [[ $CI_MODE -eq 1 ]]; then
        timestamp=$(date +"%Y-%m-%d %H:%M:%S")
        echo "[$timestamp] [ERROR] $*"
    else
        echo -e "${RED}✖ ${*}${NC}"
    fi
}

run_cmd() {
    local desc="$1"
    local cmd="$2"
    local exit_code

    log_info "${desc}..."
    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "${YELLOW}[DRY-RUN]${NC} Would execute: $cmd"
        return 0
    fi

    set +e
    eval "$cmd"
    exit_code=$?
    set -e

    if [[ $exit_code -eq 0 ]]; then
        log_success "${desc} completed"
        return 0
    else
        log_error "${desc} failed with exit code $exit_code"
        return "$exit_code"
    fi
}

# --- Core Logic ---

check_repo() {
    if [[ ! -f "${REPO_ROOT}/Makefile" ]]; then
        log_error "Makefile not found at ${REPO_ROOT}"
        exit 1
    fi
}

deploy_steps() {
    cd "${REPO_ROOT}"
    if [[ $SKIP_MIGRATIONS -eq 0 ]]; then
        run_cmd "1/3: Database migrations" "make db.migrate"
    else
        log_warning "Skipping Migrations (SKIP_MIGRATIONS=1)"
    fi
    run_cmd "2/3: Cache warmup" "make cache.warmup"
    run_cmd "3/3: Reloading workers" "make messenger.stop-workers"
}

check_health() {
    local http_code
    log_info "Verifying health at ${HEALTH_URL}..."

    # Separate declaration/assignment to capture curl exit code correctly
    set +e
    http_code=$(curl -skS --connect-timeout 3 -o /dev/null -w '%{http_code}' "${HEALTH_URL}" 2>/dev/null)
    set -e

    if [[ "$http_code" == "200" ]]; then
        log_success "Application is healthy (200)"
        return 0
    else
        log_warning "Application health check returned: $http_code"
        return 1
    fi
}

# --- Profile Engine ---

run_profile() {
    local target_profile="$1"
    check_repo

    case $target_profile in
        "simple")
            deploy_steps
            ;;
        "advanced"|"ci")
            # In CI mode, we treat health check failures as critical
            check_health || { if [[ $CI_MODE -eq 1 ]]; then exit 1; fi; }
            deploy_steps
            log_info "Stabilizing..."
            sleep 2
            check_health || { if [[ $CI_MODE -eq 1 ]]; then exit 1; fi; }
            ;;
        "check")
            check_health || true
            run_cmd "Database Status" "make db.status"
            ;;
    esac

    log_success "Deployment process finished successfully."
    exit 0
}

# --- Menu UI ---

show_menu() {
    local choice
    clear
    echo -e "${CYAN}┌──────────────────────────────────────────────────────────┐${NC}"
    echo -ne "${CYAN}│${NC}  ENV: "
    [[ $DRY_RUN -eq 1 ]] && echo -ne "${YELLOW}[DRY-RUN] ${NC}" || echo -ne "${GREEN}[LIVE] ${NC}"
    [[ $SKIP_MIGRATIONS -eq 1 ]] && echo -ne "${RED}[NO-DB]${NC}" || echo -ne "${BLUE}[DB-UP]${NC}"
    echo -e "  ${CYAN}│${NC}"
    echo -e "${CYAN}└──────────────────────────────────────────────────────────┘${NC}"

    echo -e "\n${MAGENTA}ERPify Deployment Menu${NC}"
    echo "---------------------------"
    echo -e "1) Simple Deploy"
    echo -e "2) Advanced Deploy"
    echo -e "3) CI/CD Mode (Auto-runs Advanced)"
    echo -e "4) Health/DB Status Only"
    echo "---------------------------"
    echo -e "5) Toggle DRY RUN"
    echo -e "6) Toggle MIGRATIONS"
    echo "---------------------------"
    echo -e "0) Exit"
    echo ""
    read -p "Select option: " choice

    case $choice in
        1) run_profile "simple" ;;
        2) run_profile "advanced" ;;
        3) CI_MODE=1; run_profile "ci" ;;
        4) run_profile "check" ;;
        5) DRY_RUN=$((1 - DRY_RUN)); show_menu ;;
        6) SKIP_MIGRATIONS=$((1 - SKIP_MIGRATIONS)); show_menu ;;
        0) exit 0 ;;
        *) show_menu ;;
    esac
}

# --- Argument Parsing ---

while [[ $# -gt 0 ]]; do
    case $1 in
        --simple) PROFILE="simple"; shift ;;
        --advanced) PROFILE="advanced"; shift ;;
        --ci) PROFILE="ci"; CI_MODE=1; shift ;;
        --check-only) PROFILE="check"; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        --skip-migrations) SKIP_MIGRATIONS=1; shift ;;
        --help|-h)
            echo "Usage: ./deploy.sh [OPTIONS]"
            exit 0
            ;;
        *) log_error "Unknown option: $1"; exit 1 ;;
    esac
done

# --- Execution ---

if [[ -n "${PROFILE:-}" ]]; then
    run_profile "$PROFILE"
else
    show_menu
fi
