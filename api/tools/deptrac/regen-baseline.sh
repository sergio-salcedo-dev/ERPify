#!/bin/sh
# Regenerate the deptrac baseline (grandfathered inner-layer framework deps).
#
# `make php.deptrac.baseline` calls this. deptrac's baseline formatter re-dumps the
# whole active skip_violations — including the published cross-context seams that
# live in skip_violations in deptrac.yaml (mirror of api/.bounded-context-allowlist).
# This script strips those seams back out so the baseline stays debt-only and the
# seam allowlist stays single-sourced in deptrac.yaml. A seam is any skipped target
# in another business module's namespace (Erpify\Backoffice\* / Erpify\Frontoffice\*);
# inner-layer framework debt targets vendors or Erpify\Shared\*, never a sibling module.
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)" # -> api root

raw="$(mktemp)"
trap 'rm -f "$raw"' EXIT

vendor/bin/deptrac --config-file=tools/deptrac/deptrac.yaml \
    --cache-file=var/cache/.deptrac.cache --no-progress \
    analyse --formatter=baseline --output="$raw"

{
    cat tools/deptrac/deptrac.baseline.header.txt
    # Drop cross-context seam item lines, and any importer key left with no items.
    awk '
        /^    [^ ].*:$/      { if (key != "" && n > 0) { print key; for (i = 0; i < n; i++) print item[i] } key = $0; n = 0; next }
        /^      - /          { if ($0 ~ /^      - Erpify\\(Backoffice|Frontoffice)\\/) next; item[n++] = $0; next }
                             { if (key != "" && n > 0) { print key; for (i = 0; i < n; i++) print item[i] } key = ""; n = 0; print }
        END                  { if (key != "" && n > 0) { print key; for (i = 0; i < n; i++) print item[i] } }
    ' "$raw"
} > tools/deptrac/deptrac.baseline.yaml

echo "Regenerated tools/deptrac/deptrac.baseline.yaml (cross-context seams stripped)."
