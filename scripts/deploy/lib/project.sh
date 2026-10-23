#!/usr/bin/env bash
# =============================================================================
# lib/project.sh — the compose project a prod env file deploys as
# =============================================================================
# A prod env file whose site TLS is Caddy's internal CA is the local rehearsal
# (erpify.local) and deploys as `erpify-prodlocal`; one on public ACME is a real
# host and deploys as `erpify`, the project its volumes already belong to. The
# rehearsal needs its own project because on a dev machine `erpify` is dev's,
# and sharing it hands prod dev's volumes: Postgres applies POSTGRES_PASSWORD
# only when it initialises a volume, so the prod password is refused.
#
# The TLS profile is read exactly as compose.prod.yaml reads it,
# `${CADDY_SERVER_EXTRA_DIRECTIVES-tls internal}`: an ABSENT key means
# `tls internal`, an EMPTY one means ACME.
#
# Sourced for prod_project_name(), or run as `project.sh <env-file>` to print
# the name (make/config.mk does). An explicit COMPOSE_PROJECT_NAME always wins
# in the callers, never here.

prod_project_name() {
  local env_file="$1" line directives
  # No file to read means no profile to infer: keep the historical project.
  [[ -r "$env_file" ]] || { echo "erpify"; return 0; }
  line="$(grep -E '^CADDY_SERVER_EXTRA_DIRECTIVES=' "$env_file" 2>/dev/null | head -n1 || true)"
  if [[ -z "$line" ]]; then
    directives="tls internal"
  else
    directives="${line#*=}"
    directives="${directives%$'\r'}"
    directives="${directives#\"}"; directives="${directives%\"}"
    directives="${directives#\'}"; directives="${directives%\'}"
  fi
  if [[ "$directives" =~ (^|[[:space:]])tls[[:space:]]+internal($|[[:space:]]) ]]; then
    echo "erpify-prodlocal"
  else
    echo "erpify"
  fi
  return 0
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  [[ $# -eq 1 ]] || { echo "usage: $0 <env-file>" >&2; exit 2; }
  prod_project_name "$1"
fi
