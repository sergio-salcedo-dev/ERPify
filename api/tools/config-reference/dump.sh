#!/bin/sh
#
# Emits, on stdout, the `config/reference.php` that THIS vendor tree generates — leaving the
# committed file as it found it.
#
# WHAT WRITES THAT FILE
#
# `Symfony\Bundle\FrameworkBundle\DependencyInjection\Compiler\PhpConfigReferenceDumpPass` is a
# COMPILER PASS, registered by FrameworkBundle only when `kernel.debug` is on, writing to
# `.kernel.config_dir/reference.php`. The file is produced during container COMPILATION and nowhere
# else: there is no console command that prints it, and `getConfigDir()` is `private` on Symfony's
# own `KernelTrait`, so redirecting the write to a scratch directory would mean overriding a
# framework internal from application code to satisfy a lint gate. Prototyped and declined.
#
# WHY THIS INVALIDATES THE CACHE AND NOT THE FILE
#
# The pass writes only when the content DIFFERS (`if (!is_file($f) || file_get_contents($f) !==
# $reference)`), and it registers the file as a container RESOURCE — so deleting the tracked file is
# one way to force the recompile. It is the wrong way. Deleting it opens a window in which a tracked
# file is absent from the checkout, and that window is shared: `php.md` hands PHPMD
# `bin,config,src,tests,tools,public` and `api/tools/phpmd/phpmd.xml` excludes only `vendor` and
# `var`, so PDepend parses this file in the same `-j4` sweep. A parse failure there is not local —
# `api/tests/Unit/Gate/PhpmdParsableSyntaxGateTest.php` records that PDepend abandons the run and
# reports `Found 0 violations and 1 error`, which reads as a clean sweep.
#
# Clearing `var/cache/dev` forces the same recompile without touching the tracked file. The pass then
# rewrites it ONLY when it is actually stale, so the window exists only on the run that was going to
# fail anyway, and never on a green one. The cost is the dev container's compiled cache, the same
# thing `make sf.clear.var.cache` discards.
#
# WHY THE GUARD IS THE EXIT CODE AND NOT AN ABSENT FILE
#
# Not deleting the file means "the pass did not run" can no longer be detected by its absence — the
# script would `cat` the unchanged file and report that it matches itself, which is the false green
# this gate exists to be incapable of. The positive signal replaces it: after the cache is cleared,
# `cache:warmup` exiting 0 means the container compiled, and the pass is registered on every debug
# compile. A non-zero exit prints the console's own error rather than guessing at the cause — the
# earlier version discarded stderr and reported every failure as a wrong-environment failure, which
# is the one thing that was always correct.
#
# The output is then checked for being parseable PHP, not merely non-empty: the pass writes with a
# bare `file_put_contents` of ~117 KB, so a warmup killed mid-write leaves a truncated file that
# `[ -s ]` accepts and that `sf.config.reference` would then commit over the real one.
#
# The restore is a function invoked from single-quoted traps: every step is independently tolerant so
# one failure cannot skip the rest, a failed restore says so and names the recovery command, and the
# signal traps `exit` rather than falling through — a trap action that returns lets execution RESUME
# after the signal, which was measured printing the snapshot back to stdout as if it were a match.
#
# WHAT A GREEN DOES NOT PROVE
#
#   - It answers for the DEV vendor tree, the limitation `php.lint.prod-container` records for
#     itself: `composer install --no-dev` prunes require-dev.
#   - It compares against the file in the WORKING TREE. The caller is what compares that against the
#     committed blob; see `php.lint.config-reference`.
#   - Nothing reads this file at runtime — it is array-shape documentation for config
#     autocompletion, so a wrong entry misleads an IDE and breaks nothing.
#   - The `test` kernel runs the same pass (its container registers the file as a resource), so the
#     set of processes that can rewrite it is wider than "a dev boot".
#   - A `SIGKILL` or a container stop between the warmup and the restore bypasses every trap. The
#     file is left as the pass wrote it, not deleted; both callers check it is still present.
set -eu

target="config/reference.php"
lock="var/config-reference-dump.lock"
recover="git checkout -- api/config/reference.php"

if [ ! -f "$target" ]; then
	echo "config-reference/dump.sh: $target is missing from the checkout — restore it with: $recover" >&2
	exit 1
fi

# `mkdir` is the portable atomic test-and-set. Two dumps interleaving would have each restore the
# other's capture, leaving the tracked file rewritten with every gate green.
if ! mkdir "$lock" 2>/dev/null; then
	echo "config-reference/dump.sh: another dump holds $lock — refusing to run concurrently." >&2
	echo "  If no other run is active, remove it: rm -rf api/$lock" >&2
	exit 1
fi

snapshot="$(mktemp)"
warmlog="$(mktemp)"
cp "$target" "$snapshot"
mode="$(stat -c '%a' "$target")"
owner="$(stat -c '%u:%g' "$target")"

restore() {
	if [ -f "$snapshot" ] && ! cmp -s "$snapshot" "$target"; then
		cp "$snapshot" "$target" || {
			echo "config-reference/dump.sh: RESTORE FAILED — recover with: $recover" >&2
		}
	fi
	chmod "$mode" "$target" 2>/dev/null || true
	chown "$owner" "$target" 2>/dev/null || true
	rm -f "$snapshot" "$warmlog"
	rmdir "$lock" 2>/dev/null || true
}

trap 'restore' EXIT
trap 'restore; exit 130' INT
trap 'restore; exit 143' TERM
trap 'restore; exit 129' HUP

# Invalidate the compiled container, never the tracked file.
rm -rf var/cache/dev

if ! php bin/console cache:warmup >"$warmlog" 2>&1; then
	cat "$warmlog" >&2
	echo "config-reference/dump.sh: the dev container did not compile, so the pass never ran." >&2
	echo "  The console's own error is above. This lane needs APP_ENV=dev with APP_DEBUG=1." >&2
	exit 1
fi

if [ ! -s "$target" ]; then
	echo "config-reference/dump.sh: the pass left $target empty." >&2
	exit 1
fi

if ! php -l "$target" >/dev/null 2>&1; then
	echo "config-reference/dump.sh: the pass wrote a file PHP cannot parse — truncated write." >&2
	exit 1
fi

cat "$target"
