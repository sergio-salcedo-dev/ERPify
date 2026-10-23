#!/usr/bin/env bash
# =============================================================================
# lib/prod-env.sh — the one reader of a prod env file and the compose files
# =============================================================================
# make/config.mk, make/deploy.mk (prod.env.check), deploy-local.sh, lib/common.sh,
# backup-prod.sh and restore-prod.sh all ask this file, so the project they act
# on, the overlays they load and the keys they require cannot drift apart; and
# ProdEnvSupportScriptGateTest executes it, so its answers are pinned.
#
#   profile <env-file>         internal | public — the site TLS mode
#   project <env-file>         erpify-prodlocal (internal) | erpify (public)
#   compose-files <env-file>   the -f list the prod stack loads
#   required-keys [<root>]     every key compose aborts without
#   value <key> <env-file>     the value Compose would use; exit 1 if absent
#   check <env-file> [<root>]  refuse a file Compose would run wrongly
#
# Values are read the way Compose reads an env file: the LAST occurrence of a
# key wins, an unquoted value loses a trailing ` # comment`, a quoted one keeps
# what is inside its quotes. A variable exported in the calling shell beats the
# file for Compose, and does here too for the TLS profile.
#
# Sourced for its functions, or run with a subcommand.

PROD_ENV_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROD_ENV_ROOT_DEFAULT="$(cd "$PROD_ENV_LIB_DIR/../../.." && pwd)"

# The prod stack's compose files, base first; the rehearsal overlay is added on
# top by prod_compose_files when the profile is internal.
PROD_COMPOSE_BASE=(compose.yaml compose.prod.yaml)
PROD_COMPOSE_REHEARSAL=compose.rehearsal.yaml

prod_env_value() {
  local key="$1" file="$2" line value
  [[ -r "$file" ]] || return 1
  line="$(grep -E "^${key}=" "$file" | tail -n1)" || return 1
  [[ -n "$line" ]] || return 1
  value="${line#*=}"
  value="${value%$'\r'}"
  if [[ "$value" =~ ^\"(.*)\"[[:space:]]*(#.*)?$ ]] || [[ "$value" =~ ^\'(.*)\'[[:space:]]*(#.*)?$ ]]; then
    value="${BASH_REMATCH[1]}"
  else
    value="$(printf '%s' "$value" | sed -E -e 's/(^|[[:space:]]+)#.*$//' -e 's/^[[:space:]]+//' -e 's/[[:space:]]+$//')"
  fi
  printf '%s\n' "$value"
  return 0
}

# compose.prod.yaml reads `${CADDY_SERVER_EXTRA_DIRECTIVES-tls internal}`: an
# ABSENT key means `tls internal`, an EMPTY one means public ACME. No readable
# file means no profile to infer, so the historical public reading is kept.
prod_tls_profile() {
  local file="$1" directives
  if [[ -n "${CADDY_SERVER_EXTRA_DIRECTIVES+set}" ]]; then
    directives="$CADDY_SERVER_EXTRA_DIRECTIVES"
  elif [[ ! -r "$file" ]]; then
    echo public
    return 0
  elif ! directives="$(prod_env_value CADDY_SERVER_EXTRA_DIRECTIVES "$file")"; then
    directives="tls internal"
  fi
  if [[ "$directives" =~ (^|[[:space:]])tls[[:space:]]+internal($|[[:space:]]) ]]; then
    echo internal
  else
    echo public
  fi
  return 0
}

# The rehearsal gets its own project because on a dev machine `erpify` is dev's,
# and sharing it hands prod dev's volumes (Postgres applies POSTGRES_PASSWORD
# only when it initialises one). A public host keeps `erpify`, where its volumes
# already live.
prod_project_name() {
  if [[ "$(prod_tls_profile "$1")" == internal ]]; then
    echo erpify-prodlocal
  else
    echo erpify
  fi
  return 0
}

prod_compose_files() {
  local args=() f
  for f in "${PROD_COMPOSE_BASE[@]}"; do args+=(-f "$f"); done
  [[ "$(prod_tls_profile "$1")" == internal ]] && args+=(-f "$PROD_COMPOSE_REHEARSAL")
  echo "${args[*]}"
  return 0
}

# Every `${VAR:?…}` or `${VAR?…}` outside a whole comment line, in the files
# every prod stack loads. A missing file is an error, never a smaller set.
prod_required_keys() {
  local root="${1:-$PROD_ENV_ROOT_DEFAULT}" f
  for f in "${PROD_COMPOSE_BASE[@]}"; do
    [[ -r "$root/$f" ]] || { echo "prod-env.sh: cannot read $root/$f" >&2; return 1; }
  done
  for f in "${PROD_COMPOSE_BASE[@]}"; do cat "$root/$f"; done \
    | sed -e '/^[[:space:]]*#/d' \
    | grep -oE '\$\{[A-Z0-9_]+:?\?' \
    | tr -d '$''{:?' \
    | sort -u
  return 0
}

prod_env_check() {
  local file="$1" root="${2:-$PROD_ENV_ROOT_DEFAULT}" keys key value missing=() duplicated=() kek_bytes
  keys="$(prod_required_keys "$root")" || return 1
  [[ -n "$keys" ]] || { echo "✗ No required keys derived from the prod compose files — refusing an empty set."; return 1; }
  for key in $keys; do
    [[ "$(grep -cE "^${key}=" "$file")" -gt 1 ]] && duplicated+=("$key")
    if ! value="$(prod_env_value "$key" "$file")" || [[ -z "$value" || "$value" == CHANGE_ME* || "$value" == *examplePublicKey@* ]]; then
      missing+=("$key")
    fi
  done
  if [[ ${#duplicated[@]} -gt 0 ]]; then
    echo "✗ $file declares these required keys more than once (Compose uses the last):"
    printf '    - %s\n' "${duplicated[@]}"
    return 1
  fi
  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "✗ $file has unset or placeholder required keys:"
    printf '    - %s\n' "${missing[@]}"
    echo "  Edit $file and replace every CHANGE_ME value (see .env.prod.example)."
    return 1
  fi
  kek_bytes="$(prod_env_value AUDIT_KEK "$file" | tr -d '\n' | wc -c)"
  if [[ "$kek_bytes" -ne 32 ]]; then
    echo "✗ AUDIT_KEK must be exactly 32 bytes — got $kek_bytes."
    echo "  Generate with: openssl rand -base64 24   (-hex 32 and -base64 32 are the wrong length)"
    return 1
  fi
  return 0
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -uo pipefail
  cmd="${1:-}"; shift || true
  case "$cmd" in
    profile)       prod_tls_profile "${1:?env file}" ;;
    project)       prod_project_name "${1:?env file}" ;;
    compose-files) prod_compose_files "${1:?env file}" ;;
    required-keys) prod_required_keys "${1:-}" ;;
    value)         prod_env_value "${1:?key}" "${2:?env file}" ;;
    check)         prod_env_check "${1:?env file}" "${2:-}" ;;
    *) echo "usage: $0 {profile|project|compose-files|required-keys|value|check} …" >&2; exit 2 ;;
  esac
fi
