# =============================================================================
# Shell lint — shellcheck over every tracked *.sh.
# =============================================================================
#
# `make/super-lint.mk` has offered a shell validator since long before this file,
# and no workflow ever invoked it: `.github/workflows/` held ci.yml and codeql.yml
# and neither named shellcheck or any super-lint target. So the capability was
# present and the gate was not — the shape CLAUDE.md names elsewhere, where a
# mechanism nobody performs is a paragraph rather than a control. The bar was
# real and held by hand: shellcheck 0.11.0 reported nine findings against the
# largest and most-executed shell then in the tree — the adversarial-pass hook,
# which ran on every Bash tool call in every session — while the three older
# `scripts/*.sh` reported zero. That script has since been retired, so the
# example outlives its subject; what it still shows is the asymmetry, which is
# what a hand-held bar looks like just before it slips.
#
# WHY THE INVOCATION IS GUARDED RATHER THAN FOLLOWED BY AN ECHO
#
# `cmd; echo "✓ …"` makes the recipe line's exit status the ECHO's, so shellcheck
# could print a finding, exit 1, and the target would still print its success line
# and exit 0 — measured with a stub that emits a finding and exits 1: the ✓ printed
# and `make shell.lint` returned 0. CI runs this exact target and `shell-lint` gates
# through `ci-success`, so the masking reached the merge button. A gate that cannot
# express a red is the shape this file was written to install, arrived at one
# character of shell punctuation.
#
# WHY ONE INVOCATION OVER THE WHOLE LIST, NEVER A PER-FILE LOOP
#
# shellcheck follows a `source` only into a file that was ALSO given as input.
# Measured on this tree, a per-file loop reports three findings the batch run
# does not: SC1091 "not following: lib/common.sh was not specified as input" on
# each of backup-prod.sh and restore-prod.sh, and SC2034 "ENV_FILE appears
# unused" in backup-prod.sh — which common.sh does use. Those three are
# artefacts of how the tool was called, not properties of the code, and a gate
# that reports them teaches contributors to ignore it.
#
# That guarantee has a bound worth stating rather than discovering: `xargs`
# splits the list once it exceeds ARG_MAX, and each batch is a separate
# invocation, so a source spanning two batches would report SC1091 again. At 20
# scripts the list is three orders of magnitude short of that; it is a note for
# whoever finds this file holding thousands.
#
# THE ENTRY CRITERION IS ZERO, WITH NO BASELINE
#
# At default severity the tracked scripts report zero findings, so a baseline
# could only ever preserve future drift. A genuine false positive is answered at
# its line with a `# shellcheck disable=<code>` carrying the reason it is one —
# visible in review, unlike a baseline file nobody reads. Two live in
# scripts/deploy/: `$POSTGRES_USER` inside a single-quoted `sh -c` is the
# CONTAINER's environment, and expanding it on the host would pass an empty
# value to pg_dump/pg_restore.
#
# THE VERSION IS THE RUNNER'S, DELIBERATELY
#
# CI uses the shellcheck preinstalled in the GitHub runner image rather than a
# pinned one. A pin would be deterministic, but pip is not one of the four
# ecosystems `.github/dependabot.yaml` watches (npm, github-actions, composer,
# docker), so a pinned version here would be the tree's one unwatched pin —
# precisely the shape of #763. The cost is accepted and named: an image bump can
# introduce a finding that was not there yesterday. That finding is still a real
# finding, and the CI job prints `shellcheck --version` so a surprise red is
# diagnosable from the log alone.
#
# THE SUBJECT IS TRACKED SHELL, NOT FILES NAMED *.sh
#
# Selecting on the extension alone would leave five tracked scripts unlinted —
# `api/bin/sf`, `binaries/composer`, `binaries/sf` and two skill runners — and
# "the gate covers shell" would be false in the way this repository keeps paying
# for: nothing goes red, the claim just quietly stops being true. So the list is
# the union of tracked `*.sh` and every tracked file whose FIRST line is a
# sh/bash shebang. `git grep` supplies the second half in ~50 ms over 7.5k files;
# a `head -1` loop over the whole index would not be worth running.
#
# What it still cannot see is an UNTRACKED script: `git ls-files` is the source
# of the list, so a new file is linted from the commit that adds it, not before.
#
# NOT PART OF ci.quality: that aggregate runs php.quality and pwa.quality inside
# their containers, while this needs shellcheck on the host. Making it a member
# would change that aggregate's contract for every contributor. It is its own
# `shell-lint` job in ci.yml for the same reason, gating through `ci-success`.

.PHONY: shell.lint shell.files

## —— Shell ————————————————————————————————————————————————————————————————

# Every tracked shell script: named *.sh, or carrying a shell shebang on line 1.
# `-z` NUL-delimits the path from the `lineno:content` that follows it, because
# a plain `:`-joined `path:lineno:content` line silently mis-splits on a path
# that itself contains a colon (git allows one) -- `awk -F:` then reads a
# fragment of the path as the line number, the shebang test never matches, and
# the file drops out of the list with nothing red to show for it. `-z` puts a
# NUL after the path and after the line number, but each MATCH still ends in a
# real newline (git never NUL-terminates that), so `awk -v RS='\0'` glues one
# match's content onto the next match's path once there is more than one hit --
# measured, that read the 4th tracked shebang's own content as if it were a
# 5th file's path. Reading records by NEWLINE and splitting each on NUL keeps
# the two boundaries apart, because content and paths are always single lines.
shell.files:
	@cd "$(PROJECT_ROOT)" && { \
		git ls-files '*.sh'; \
		git grep -Iz --no-color -n -E '^#!' -- ':!*.sh' \
			| awk -v FS='\0' '{ if ($$2 == 1 && $$3 ~ /^#!.*(\/|env +)(ba)?sh([[:space:]]|$$)/) print $$1 }'; \
	} | sort -u

shell.lint: ## shellcheck every tracked shell script in ONE pass (zero findings, no baseline)
	@command -v shellcheck >/dev/null 2>&1 || { \
		echo "✗ shell.lint: shellcheck not found."; \
		echo "  install it with 'pip install shellcheck-py', your distro package, or a pinned release binary."; \
		exit 1; \
	}
	@cd "$(PROJECT_ROOT)" && files="$$($(MAKE) --no-print-directory shell.files)"; \
	count=$$(printf '%s\n' "$$files" | grep -c .); \
	if [ "$$count" -eq 0 ]; then \
		echo "✗ shell.lint: shell.files discovered zero tracked shell scripts -- the check did not run"; \
		exit 1; \
	fi; \
	if ! printf '%s\n' "$$files" | tr '\n' '\0' | xargs -0 shellcheck -f gcc; then \
		echo "✗ shell.lint: findings above, across $$count tracked shell scripts"; \
		exit 1; \
	fi; \
	echo "✓ shell.lint: no findings across $$count tracked shell scripts"
