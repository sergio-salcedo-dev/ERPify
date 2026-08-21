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
# a sibling business module and therefore a seam.
#
# Enumerating the contexts instead fails open, and the enumeration had already gone
# stale: it named Backoffice and Frontoffice, while deptrac.yaml carries seams for Iam
# and Organization too. Measured, not inferred: the committed baseline held no seam of
# theirs, so nothing had been regenerated since those contexts appeared — the defect was
# latent, never shipped. Regenerating under the old rule produced 41 entries against 21
# under this one, which is what a materialised version of it would have looked like.
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)" # -> api root

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
    #
    # `module()` reduces a class to Erpify\<Context>\<Module>, tolerating the quoting deptrac's YAML dumper
    # applies to some targets — matching the raw line instead would let a quoted seam through, which is the
    # direction that fails OPEN and puts back exactly what this strip exists to remove.
    #
    # A target inside the importer's OWN module is inner-layer debt (Application reaching its Infrastructure),
    # not a published seam, so it stays: dropping it would leave a real violation with nowhere legal to be
    # recorded, since hand-editing this file is forbidden.
    awk '
        function module(s,   parts, count) {
            sub(/^[[:space:]]*-[[:space:]]*/, "", s)
            sub(/^[[:space:]]+/, "", s)
            sub(/:[[:space:]]*$/, "", s)
            gsub(/["'"'"']/, "", s)
            count = split(s, parts, /\\/)
            return count >= 3 ? parts[1] "\\" parts[2] "\\" parts[3] : s
        }
        /^    [^ ].*:$/      { if (key != "" && n > 0) { print key; for (i = 0; i < n; i++) print item[i] } key = $0; keyModule = module($0); n = 0; next }
        /^      - /          {
                                 target = $0
                                 sub(/^[[:space:]]*-[[:space:]]*/, "", target)
                                 gsub(/["'"'"']/, "", target)
                                 if (target ~ /^Erpify\\/ && target !~ /^Erpify\\Shared\\/ && module($0) != keyModule) next
                                 item[n++] = $0
                                 next
                             }
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
