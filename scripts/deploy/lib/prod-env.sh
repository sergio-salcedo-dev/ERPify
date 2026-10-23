#!/usr/bin/env bash
# =============================================================================
# lib/prod-env.sh — the one reader of a prod env file and the compose files
# =============================================================================
# make/config.mk, make/deploy.mk (prod.env.check), deploy-local.sh, lib/common.sh,
# backup-prod.sh and restore-prod.sh all ask this file, so the project they act
# on, the overlays they load, the host they treat as production and the keys
# they require cannot drift apart; the ProdEnv*GateTest family executes it.
#
#   profile <env-file>                internal | public — the site TLS mode
#   project <env-file> [<root>]       the compose project a prod stack runs as
#   compose-files <env-file>          the -f list the prod stack loads
#   published-ports <env-file>        host ports it publishes, as <port>/<proto>
#   server-is-local <env-file>        exit 0 iff every SERVER_NAME host is local
#   required-keys [<root>]            every key compose aborts without
#   value <key> <env-file>            the value Compose would use; exit 1 if absent
#   check <env-file> [<root>]         refuse a file Compose would run wrongly
#
# Values are read the way Compose reads an env file: an optional `export ` and
# leading blanks are ignored, the LAST occurrence of a key wins, an unquoted
# value loses a trailing ` # comment`, a quoted one keeps what is inside its
# quotes. A variable exported in the calling shell beats the file for Compose,
# and does here too for the TLS profile and the published ports.
#
# Sourced for its functions, or run with a subcommand.

PROD_ENV_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROD_ENV_ROOT_DEFAULT="$(cd "$PROD_ENV_LIB_DIR/../../.." && pwd)"

# The prod stack's compose files, base first; the rehearsal overlay is added on
# top by prod_compose_files when the profile is internal.
PROD_COMPOSE_BASE=(compose.yaml compose.prod.yaml)
PROD_COMPOSE_REHEARSAL=compose.rehearsal.yaml

# Optional knobs prod.env.check validates when set; each is a `*_MEM_LIMIT` the
# prod stack or the rehearsal overlay interpolates.
PROD_MEM_KNOBS=(PHP_MEM_LIMIT PWA_MEM_LIMIT DB_MEM_LIMIT WORKER_MEM_LIMIT SCHEDULER_MEM_LIMIT REHEARSAL_MAILPIT_MEM_LIMIT)

# Mirrors SecuritySenderAddress::UNDELIVERABLE_DOMAINS and its no-reply refusal,
# which otherwise fire only at send time.
PROD_UNDELIVERABLE_DOMAINS=(test example invalid localhost local)

# Every line declaring <key>, in file order.
prod_env_lines() {
  local key="$1" file="$2"
  grep -E "^[[:space:]]*(export[[:space:]]+)?${key}[[:space:]]*=" "$file" 2>/dev/null
  return 0
}

prod_env_value() {
  local key="$1" file="$2" line value
  [[ -r "$file" ]] || return 1
  line="$(prod_env_lines "$key" "$file" | tail -n1)"
  [[ -n "$line" ]] || return 1
  value="${line#*=}"
  value="${value%$'\r'}"
  value="${value#"${value%%[![:space:]]*}"}"
  if [[ "$value" =~ ^\"([^\"]*)\"[[:space:]]*(#.*)?$ ]] || [[ "$value" =~ ^\'([^\']*)\'[[:space:]]*(#.*)?$ ]]; then
    value="${BASH_REMATCH[1]}"
  else
    value="$(printf '%s' "$value" | sed -E -e 's/(^|[[:space:]]+)#.*$//' -e 's/^[[:space:]]+//' -e 's/[[:space:]]+$//')"
  fi
  printf '%s\n' "$value"
  return 0
}

# The keys among <key>… that <file> declares more than once.
prod_env_duplicates() {
  local file="$1" key
  shift
  for key in "$@"; do
    [[ "$(prod_env_lines "$key" "$file" | wc -l)" -gt 1 ]] && echo "$key"
  done
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

# The project a checkout's own stacks run as: `erpify` in the primary checkout,
# `erpify-<slug>` in a linked worktree — the rule make/config.mk applies to dev.
prod_checkout_project() {
  local root="${1:-$PROD_ENV_ROOT_DEFAULT}" common slug
  common="$(cd "$root" 2>/dev/null && git rev-parse --git-common-dir 2>/dev/null || true)"
  if [[ -n "$common" ]] && [[ "$(cd "$root" && cd "$common/.." 2>/dev/null && pwd -P)" != "$(cd "$root" && pwd -P)" ]]; then
    slug="$(basename "$root" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '-' | sed -E 's/^-+|-+$//g')"
    echo "erpify-${slug}"
  else
    echo erpify
  fi
  return 0
}

# The rehearsal runs as its own project because on a dev machine the checkout's
# project is dev's, and sharing it hands prod dev's volumes (Postgres applies
# POSTGRES_PASSWORD only when it initialises one). Anything else runs as the
# checkout's project: `erpify` on a host deploying from its primary checkout.
prod_project_name() {
  if [[ "$(prod_tls_profile "$1")" == internal ]]; then
    echo erpify-prodlocal
  else
    prod_checkout_project "${2:-}"
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

# The shell's value, else the file's, else compose's default.
prod_port() {
  local var="$1" default="$2" file="$3" port
  port="${!var:-}"
  [[ -n "$port" ]] || port="$(prod_env_value "$var" "$file" 2>/dev/null || true)"
  echo "${port:-$default}"
  return 0
}

prod_published_ports() {
  local file="$1"
  echo "$(prod_port HTTP_PORT 80 "$file")/tcp"
  echo "$(prod_port HTTPS_PORT 443 "$file")/tcp"
  echo "$(prod_port HTTP3_PORT 443 "$file")/udp"
  [[ "$(prod_tls_profile "$file")" == internal ]] && echo "$(prod_port MAILPIT_UI_PORT 8025 "$file")/tcp"
  return 0
}

# Fail safe: only an explicitly local SERVER_NAME is non-production. A real
# domain, an IP, a bare internal hostname or a duplicated key all count as
# production, so an unrecognised target never takes the scriptable path.
prod_server_is_local() {
  local file="$1" server host
  [[ -z "$(prod_env_duplicates "$file" SERVER_NAME)" ]] || return 1
  server="$(prod_env_value SERVER_NAME "$file" 2>/dev/null || true)"
  [[ -n "$server" ]] || return 1
  for host in $server; do
    case "$host" in
      *.local|*.localhost|localhost|127.0.0.1|::1) : ;;
      *) return 1 ;;
    esac
  done
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

prod_mem_limit_error() {
  local key="$1" val="$2" digits unit mult bytes
  if ! [[ "$val" =~ ^[0-9]{1,9}[bBkKmMgG]$ ]]; then
    echo "    - $key='$val': not a valid Docker memory limit (need a positive integer, max 9 digits, with a b/k/m/g suffix — a bare '512' reads as 512 BYTES)"
    return 0
  fi
  digits="${val%?}"
  unit="$(printf '%s' "${val: -1}" | tr '[:upper:]' '[:lower:]')"
  case "$unit" in b) mult=1 ;; k) mult=1024 ;; m) mult=1048576 ;; g) mult=1073741824 ;; esac
  bytes=$(( 10#$digits * mult ))
  if [[ "$bytes" -lt 6291456 ]]; then
    echo "    - $key='$val' ($bytes bytes): below Docker's own ~6 MiB minimum — the container fails to start with 'Minimum memory limit allowed is 6MB', naming neither key nor service"
  fi
  return 0
}

prod_env_check() {
  local file="$1" root="${2:-$PROD_ENV_ROOT_DEFAULT}" keys key value missing=() duplicated kek_bytes
  local sender domain pw tmo bad_mem=""
  keys="$(prod_required_keys "$root")" || return 1
  [[ -n "$keys" ]] || { echo "✗ No required keys derived from the prod compose files — refusing an empty set."; return 1; }

  # shellcheck disable=SC2086 # $keys is a newline-separated list of identifiers
  duplicated="$(prod_env_duplicates "$file" $keys CADDY_SERVER_EXTRA_DIRECTIVES MAILER_SMTP_TIMEOUT "${PROD_MEM_KNOBS[@]}")"
  if [[ -n "$duplicated" ]]; then
    echo "✗ $file declares these keys more than once (Compose uses the last, so which value runs is hidden):"
    printf '%s\n' "$duplicated" | sed 's/^/    - /'
    return 1
  fi

  for key in $keys; do
    if ! value="$(prod_env_value "$key" "$file")" || [[ -z "$value" || "$value" == CHANGE_ME* || "$value" == *examplePublicKey@* ]]; then
      missing+=("$key")
    fi
  done
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

  sender="$(prod_env_value MAILER_SECURITY_FROM "$file" | tr '[:upper:]' '[:lower:]')"
  domain="${sender##*@}"; domain="${domain##*.}"
  if [[ "$sender" == *noreply* || "$sender" == *no-reply* ]] || [[ " ${PROD_UNDELIVERABLE_DOMAINS[*]} " == *" $domain "* ]]; then
    echo "✗ MAILER_SECURITY_FROM='$sender' is refused at send time: it must be a monitored, replyable mailbox"
    echo "  on a real domain (no no-reply@, no .local/.test/.example/.invalid/localhost)."
    return 1
  fi

  pw="$(prod_env_value POSTGRES_PASSWORD "$file")"
  if [[ "$pw" =~ [/+=:@?\ ] ]]; then
    echo "✗ POSTGRES_PASSWORD contains a URL-unsafe char (/ + = : @ ? or space) — it corrupts DATABASE_URL."
    echo "  Regenerate URL-safe, e.g.: openssl rand -hex 24"
    return 1
  fi

  tmo="$(prod_env_value MAILER_SMTP_TIMEOUT "$file" 2>/dev/null || true)"
  if [[ -n "$tmo" ]]; then
    if ! [[ "$tmo" =~ ^[0-9]+(\.[0-9]+)?$ ]]; then
      echo "✗ MAILER_SMTP_TIMEOUT must be a plain number of seconds — got '$tmo'."
      echo "  A duration spelling like '10s' passes compose, the image build and the container lint,"
      echo "  then throws on the first email and returns false from every lockout tick, silently."
      return 1
    fi
    if [[ "$(awk -v v="$tmo" 'BEGIN { print (v >= 1 && v <= 300) ? 1 : 0 }')" != "1" ]]; then
      echo "✗ MAILER_SMTP_TIMEOUT must be between 1 and 300 seconds — got '$tmo'."
      echo "  Below 1 no relay completes a round trip, so mail is discarded while looking configured;"
      echo "  above 300 the bound no longer protects the five-minute lockout tick it exists for."
      return 1
    fi
  fi

  for key in "${PROD_MEM_KNOBS[@]}"; do
    value="$(prod_env_value "$key" "$file" 2>/dev/null || true)"
    [[ -n "$value" ]] && bad_mem+="$(prod_mem_limit_error "$key" "$value")"$'\n'
  done
  if [[ -n "${bad_mem//$'\n'/}" ]]; then
    echo "✗ $file has invalid memory-limit knobs:"
    printf '%s' "$bad_mem" | sed '/^$/d'
    return 1
  fi
  return 0
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -uo pipefail
  cmd="${1:-}"; shift || true
  case "$cmd" in
    profile)         prod_tls_profile "${1:?env file}" ;;
    project)         prod_project_name "${1:?env file}" "${2:-}" ;;
    compose-files)   prod_compose_files "${1:?env file}" ;;
    published-ports) prod_published_ports "${1:?env file}" ;;
    server-is-local) prod_server_is_local "${1:?env file}" ;;
    required-keys)   prod_required_keys "${1:-}" ;;
    value)           prod_env_value "${1:?key}" "${2:?env file}" ;;
    check)           prod_env_check "${1:?env file}" "${2:-}" ;;
    *) echo "usage: $0 {profile|project|compose-files|published-ports|server-is-local|required-keys|value|check} …" >&2; exit 2 ;;
  esac
fi
