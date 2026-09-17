#!/bin/sh
#
# Emits, on stdout, the `config/reference.php` that THIS vendor tree generates — leaving the
# committed file exactly as it found it.
#
# WHAT WRITES THAT FILE, AND WHY THIS SCRIPT LOOKS THE WAY IT DOES
#
# `Symfony\Bundle\FrameworkBundle\DependencyInjection\Compiler\PhpConfigReferenceDumpPass` is a
# COMPILER PASS, registered by FrameworkBundle only when `kernel.debug` is on, writing to
# `.kernel.config_dir/reference.php`. So the file is produced during container COMPILATION and
# nowhere else — there is no console command that prints it, and `getConfigDir()` is `private` on
# Symfony's own `KernelTrait`, so redirecting the write to a scratch directory would mean
# overriding a framework internal from application code to satisfy a lint gate. Declined.
#
# Two measured properties of that pass make the snapshot-and-restore below correct rather than
# merely convenient:
#
#   - It writes only when the content DIFFERS: `if (!is_file($f) || file_get_contents($f) !==
#     $reference)`. A warmup over an already-correct file is therefore a no-op, which is why
#     "regenerate and look at the diff" silently did nothing on some runs and not others.
#   - It registers the file as a container RESOURCE (`addResource(new FileResource(...))`). So
#     REMOVING the file is itself the invalidation that forces the recompile — this script does not
#     have to clear `var/cache/dev`, and a developer's warm dev cache survives running the gate.
#
# Removing it first is also the guard against a false green. If the pass does not run — wrong
# environment, debug off, an unwritable directory — there is no file to read and this script exits
# non-zero, instead of printing back the snapshot it took and reporting that the committed file
# matches itself.
#
# The restore runs from a `trap` on EXIT/INT/TERM/HUP, so an interrupted or failed run puts the
# tracked file back — content, MODE and OWNER. The last two are not belt-and-braces: this script
# runs as root inside the container against a bind mount, and `cp` onto a path that no longer
# exists creates it with the SOURCE's mode. `mktemp` makes the snapshot 0600, so the naive restore
# hands the developer back a `root:root 0600` file that git itself cannot read
# ("error: open(...): Permission denied"). Measured, by provoking the failure path. The window in which `config/reference.php` differs on disk is the one cost
# this design has, and it is bounded: within `php.quality.dry-run -j4` the only two tools that name
# this file — `api/tools/ecs/.php-cs-fixer.dist.php` and `api/tools/rector/rector.php` — name it to
# EXCLUDE it, and nothing in the repository reads its contents.
#
# WHAT A GREEN DOES NOT PROVE
#
#   - It answers for the DEV vendor tree, the same limitation `php.lint.prod-container` records for
#     itself. `composer install --no-dev` prunes require-dev, so a bundle that contributes
#     configuration only there is reflected here and absent from the production image.
#   - It proves the committed file equals what this checkout generates — never that the file is
#     USEFUL. Nothing reads it at runtime; it is array-shape documentation for config
#     autocompletion, so a wrong entry misleads an IDE and breaks nothing.
#   - It says nothing about the OTHER generated artefacts of a container compile. This gate watches
#     one file.
#   - Content is deterministic, whether the pass RUNS is not — that asymmetry is the whole reason
#     this script deletes the file rather than diffing whatever a warmup happened to leave behind.
set -eu

target="config/reference.php"

if [ ! -f "$target" ]; then
	echo "config-reference/dump.sh: $target is missing from the checkout" >&2
	exit 1
fi

snapshot="$(mktemp)"
cp "$target" "$snapshot"
mode="$(stat -c '%a' "$target")"
owner="$(stat -c '%u:%g' "$target")"
# shellcheck disable=SC2064 # every value is expanded NOW on purpose: the trap must restore the
# path and the attributes this invocation captured, not whatever the variables hold when it fires.
trap "cp '$snapshot' '$target'; chmod '$mode' '$target'; chown '$owner' '$target' 2>/dev/null || true; rm -f '$snapshot'" EXIT INT TERM HUP

rm -f "$target"
php bin/console cache:warmup >/dev/null 2>&1 || true

if [ ! -s "$target" ]; then
	echo "config-reference/dump.sh: the config-reference pass wrote nothing — it did not run." >&2
	echo "  It needs APP_ENV=dev with APP_DEBUG=1 (the pass is registered only under kernel.debug)." >&2
	exit 1
fi

cat "$target"
