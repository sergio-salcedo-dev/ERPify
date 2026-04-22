#!/usr/bin/env bash
# Extract data-testid="..." attributes with file:line prefix.
# Usage: extract-testids.sh <output_file> [search_dir]

set -euo pipefail
export LC_ALL=C

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <output_file> [search_dir]" >&2
    exit 1
fi

output=$1
search_dir=${2:-.}

if [[ ! -d $search_dir ]]; then
    echo "Error: '$search_dir' is not a directory" >&2
    exit 1
fi

mkdir -p "$(dirname -- "$output")"

grep -rHnEo --include='*.ts' --include='*.tsx' --include='*.js' --include='*.jsx' --include='*.html' \
    'data-testid="[^"]*"' "$search_dir" \
    | sort -t: -k1,1 -k2,2n -u \
    > "$output"

count=$(wc -l < "$output")
echo "Extracted $count data-testid attributes into $output"
