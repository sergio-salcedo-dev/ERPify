#!/usr/bin/env bash
# =============================================================================
# Delete the directory a worktree removal left behind
# =============================================================================
#
#   scripts/worktree-rm-residue.sh <main-checkout> <dir>
#
# `git worktree remove` drops the registration even when deleting the files
# fails, and the files it cannot delete are the ones the worktree's containers
# wrote as root through bind mounts (api/var, pwa/.next, node_modules, ...). The
# host user cannot unlink those. `make worktree.chown` is a `sudo` that asks for
# a terminal, so it cannot run from an agent shell, from the unattended
# `scripts/agent/` automation or from a `!` command, and it reclaims the whole
# folder, other sessions' worktrees included.
#
# This clears the directory the way it was written: as root, inside a throwaway
# container that mounts ONLY that directory. No sudo, nothing else touched.
#
# It is the destructive half of worktree.remove, so it re-derives every guard
# itself instead of trusting its caller. The first three each close a reproduced
# deletion of another session's live checkout (or its container equivalent):
#
#   1. <dir> is a DIRECT child of <main-checkout>/.claude/worktrees and its name
#      is [A-Za-z0-9._-]+ (worktree directories are slugs), with no control
#      character anywhere in the path. This runs BEFORE the registry is read:
#      `git worktree list --porcelain` does not quote, so a newline in a path
#      splits it across two lines and the `worktree ` prefix filter below keeps
#      only the first, and the containment test would then never match a
#      registered worktree named that way. Refusing such a name outright is
#      what keeps "one registry line = one path" true.
#   2. It is a real directory, not a symlink, and its physical path equals the
#      one given. The container mounts the path the daemon resolves, so a link
#      planted under .claude/worktrees would empty whatever it points at.
#   3. git does not track it AND tracks nothing inside it. Equality is not
#      enough: a registered worktree nested in <dir> survives an equality test.
#      The registry is read once and an empty read is a refusal, because a
#      deletion gated on a command that might have failed is not gated.
#
# `rm -rf` runs first and the container only for what it could not delete, so a
# tree the host user owns never needs Docker. In the container `find -xdev
# -delete` stops at a mount point inside the residue (EBUSY) where `rm -rf`
# would empty the filesystem mounted there; busybox reports that with exit 0, so
# the verdict is the `rmdir` that follows, which fails while anything remains.
# The bind is `--mount`, which errors on a missing source where `-v` creates it
# — against a remote daemon (DOCKER_HOST, a context) that would be a new
# root-owned directory on a machine this script never meant to touch.
# Exit 0 means <dir> is gone.
# =============================================================================

set -euo pipefail

# Pulled on first use. A tag, not a digest: nothing here can be a supply-chain
# input beyond `find` and `rm`, and the container has no network once pulled.
readonly IMAGE='alpine:3'

die() {
	printf '✗ %s\n' "$*" >&2
	exit 1
}

main=${1:-}
dir=${2:-}
[[ -n $main && -n $dir ]] || die "usage: ${0##*/} <main-checkout> <dir>"

main=${main%/}
dir=${dir%/}
root="$main/.claude/worktrees"

[[ $main$dir =~ [[:cntrl:]] ]] && die "refusing: a control character in the path"
[[ $dir == "$root"/* ]] || die "refusing $dir: not inside $root"
name=${dir#"$root"/}
[[ -n $name && $name != */* && $name != . && $name != .. ]] ||
	die "refusing $dir: not a direct child of $root"
[[ $name =~ ^[A-Za-z0-9._-]+$ ]] ||
	die "refusing $dir: its name is not [A-Za-z0-9._-]+ (a worktree directory is a slug; clear anything else by hand)"

if [[ ! -e $dir && ! -L $dir ]]; then
	echo "✓ $dir is already gone"
	exit 0
fi
[[ -d $dir && ! -L $dir ]] || die "refusing $dir: not a real directory"
[[ $(readlink -f -- "$dir") == "$dir" ]] || die "refusing $dir: its physical path differs (symlink in the path)"

reg=$(git -C "$main" worktree list --porcelain | sed -n 's/^worktree //p')
[[ -n $reg ]] || die "could not read the worktree registry — refusing to delete anything"
# The path goes through ENVIRON, not `awk -v`, which interprets escape sequences and
# would compare a backslash in <main> as something else. The name is a strict slug
# by now, so this guards the part of the path the name check does not cover.
if DIR=$dir awk '$0 == ENVIRON["DIR"] || index($0, ENVIRON["DIR"] "/") == 1 { f = 1 } END { exit !f }' <<<"$reg"; then
	die "git still tracks $dir (or a worktree inside it) — remove it by its registered name"
fi

if rm -rf -- "$dir" 2>/dev/null; then
	echo "✓ deleted $dir"
	exit 0
fi

[[ $dir != *[\",]* ]] || die "refusing to mount $dir in a container: a ',' or '\"' would rewrite the --mount option"
command -v docker >/dev/null 2>&1 || die "$dir holds root-owned files and docker is not available to clear them"

echo "→ $dir holds root-owned files; clearing them as root in a throwaway container that mounts only that directory"
docker run --rm --network none --mount "type=bind,src=$dir,dst=/target" "$IMAGE" \
	find /target -xdev -mindepth 1 -delete ||
	die "the container could not clear $dir"
rmdir -- "$dir" || die "the container left something in $dir (a mount point, or an error above) — nothing more was deleted"
echo "✓ deleted $dir"
