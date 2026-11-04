#!/usr/bin/env bash
# =============================================================================
# What the automation has cost in PRs: open against the cap, and the last N days.
# =============================================================================
#
# Every PR the automation opens runs the full CI, and nothing else counts them. A
# PR closed unmerged is the waste: its CI ran and produced nothing a human kept.
# A PR is the automation's when its body carries AGENT_PR_MARKER.
#
#   status.sh [days]      (default 30)
# =============================================================================

set -euo pipefail
# shellcheck source=scripts/agent/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

DAYS="${1:-30}"
[[ $DAYS =~ ^[0-9]+$ ]] || die "usage: status.sh [days]"
need gh jq date

since="$(date -d "$DAYS days ago" +%F)"
open_now="$(agent_pr_stats open | cut -d' ' -f1)" || open_now=""
window="$(agent_pr_stats all "$since")" || window=""
if [[ -z $open_now || -z $window ]]; then
	echo "could not read pull requests from GitHub (gh auth?)"
	exit 1
fi
read -r w_open w_merged w_closed <<<"$window"
printf 'open agent PRs now : %s of %s allowed (AGENT_MAX_OPEN_PRS)\n' "$open_now" "$AGENT_MAX_OPEN_PRS"
printf 'created since %s : %s merged, %s closed unmerged, %s still open\n' "$since" "$w_merged" "$w_closed" "$w_open"
printf 'each of them ran the full CI; the closed-unmerged ones ran it for nothing\n'
