#!/bin/sh
# Regenerate the deptrac baseline (grandfathered inner-layer framework deps).
#
# `make php.deptrac.baseline` calls this. deptrac's baseline formatter re-dumps the
# whole active skip_violations — including the published cross-context seams that
# live in skip_violations in deptrac.yaml (mirror of api/.bounded-context-allowlist).
# This script strips those seams back out so the baseline stays debt-only and the
# seam allowlist stays single-sourced in deptrac.yaml.
#
# The discriminator is stated as a rule, not as a list of contexts: inner-layer
# framework debt targets a vendor or Erpify\Shared\*, so ANY other Erpify\* target is
# a sibling business module and therefore a seam. Enumerating the contexts instead
# fails open — the list named only Backoffice and Frontoffice, so once Iam and
# Organization existed every regen quietly grandfathered their published seams into
# the baseline, turning a ratchet against debt into a record of it.
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)" # -> api root

raw="$(mktemp)"
out="$(mktemp)"
trap 'rm -f "$raw" "$out"' EXIT

# deptrac exits non-zero whenever it dumps violations into the baseline — that is the
# expected outcome here, so don't let `set -e` abort. Validate the output instead.
vendor/bin/deptrac --config-file=tools/deptrac/deptrac.yaml \
    --cache-file=var/cache/.deptrac.cache --no-progress \
    analyse --formatter=baseline --output="$raw" || true
grep -q '^  skip_violations:' "$raw" || {
    echo "deptrac produced no usable baseline (config/parse error?)." >&2
    exit 1
}

{
    cat tools/deptrac/deptrac.baseline.header.txt
    # Drop cross-context seam item lines, and any importer key left with no items.
    awk '
        /^    [^ ].*:$/      { if (key != "" && n > 0) { print key; for (i = 0; i < n; i++) print item[i] } key = $0; n = 0; next }
        /^      - /          { if ($0 ~ /^      - Erpify\\/ && $0 !~ /^      - Erpify\\Shared\\/) next; item[n++] = $0; next }
                             { if (key != "" && n > 0) { print key; for (i = 0; i < n; i++) print item[i] } key = ""; n = 0; print }
        END                  { if (key != "" && n > 0) { print key; for (i = 0; i < n; i++) print item[i] } }
    ' "$raw"
} > "$out"

# Only overwrite the committed baseline once the new content is fully built and
# non-empty, so a mid-pipeline failure (awk/cat error under `set -e`) leaves the
# existing baseline intact. Write *through* the existing file rather than `mv`-ing
# a tmpfile in: the container runs as root, and an mv'd /tmp file would leave a
# root-owned baseline on the host bind mount.
test -s "$out"
cat "$out" > tools/deptrac/deptrac.baseline.yaml

echo "Regenerated tools/deptrac/deptrac.baseline.yaml (cross-context seams stripped)."
