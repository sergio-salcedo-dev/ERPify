# =============================================================================
# Git worktree lifecycle — create a worktree, or remove one/all of them cleanly.
# =============================================================================

# Linked worktrees live under .claude/worktrees/ and each runs its own Compose
# project (erpify-<dir-slug>, see config.mk). Removing one therefore means more
# than `git worktree remove`: tear down its isolated stack + volumes first, drop
# the worktree, prune stale metadata, then delete the branch it held. Operates on
# the main checkout regardless of where invoked (porcelain lists it first).
#
# LOCAL ONLY: these targets never touch the remote — `git worktree remove` and
# `git branch -d/-D` are purely local; nothing pushes a branch deletion to origin.
#
# Sub-make calls here (`$(MAKE) -C <wt> …`) re-derive their own erpify-<slug>
# project on their own: config.mk hands COMPOSE_PROJECT_NAME to compose via `-p`
# and does NOT export it, so a nested make never inherits the caller's project.
#
# worktree.create:  BRANCH=<branch> (required) is the new branch. A random 4-char
#                   suffix is appended to BOTH the branch and the dir slug, so the
#                   branch, the .claude/worktrees/<slug> dir and its erpify-<slug>
#                   Compose project are always unique — feat/foo and fix/foo can't
#                   clash, and re-running never collides. BASE=<ref> is the start
#                   point (default main); NAME=<dir-base> overrides the derived dir
#                   slug (still suffixed); START=true also brings the new stack up
#                   via app.dev (the sub-make re-derives its own erpify-<slug>
#                   project — see config.mk on why it isn't inherited).
#                   After checkout the recipe seeds the worktree's .claude/skills/
#                   with the bmad-* skills from its own tracked .agent/skills/
#                   copy: .claude/skills/bmad-*/ is gitignored, so a fresh
#                   checkout lacks it and /bmad-* slash commands would otherwise
#                   be "Unknown command" inside the worktree.
#                   It also links _bmad -> the main checkout's install. /_bmad is
#                   gitignored too, so no worktree ever had it, and every bmad
#                   skill dies at activation: it resolves the workflow block via
#                   _bmad/scripts/resolve_customization.py and its config via
#                   _bmad/bmm/config.yaml. Linked, not copied, so one installed
#                   version and one config serve every worktree — a copy would
#                   drift silently the first time the install is updated. The
#                   link is relative (../../../_bmad), so moving the whole tree
#                   keeps it resolving.
#
# worktree.remove / worktree.remove-all:
# NAME=<dir|path|branch>  selects the worktree by its directory basename under
#                  .claude/worktrees/, by its absolute path (trailing slashes
#                  stripped, so tab-completion works), or by the branch it holds.
#                  FORCE must be exactly `true` to discard a dirty worktree and
#                  delete a not-fully-merged branch (squash-merged branches look
#                  unmerged to git, so the common merged-PR case needs it). Any
#                  other value, `false` included, is not force.
#
# A removal must clear three independent residues — a registered worktree, a
# directory, a branch — and `git worktree remove` drops the registration even when
# deleting the files FAILS. Measured: with an undeletable subdirectory planted, git
# prints `error: failed to delete …`, exits 255, and leaves the checkout on disk
# with its .git file gone and its registration already pruned. So the recipe must
# be able to finish a job it started.
#
# NAME therefore resolves against the registry by path, by directory basename AND
# BY BRANCH, and only what the registry does not know is treated as a residue:
#   · a leftover directory at .claude/worktrees/<basename NAME> that git no
#     longer tracks — tear its stack down by project name, then rm -rf and prune;
#   · a leftover local branch named exactly NAME.
# Both fire when both match, and the recipe errors when neither does.
#
# THE BRANCH ARM OF THE LOOKUP IS A SAFETY GUARD, NOT AN ERGONOMIC ONE. Matching
# only path/basename means NAME=<branch> — a form this file, the help text and the
# recipe's own hints all advertise — can never match a live worktree, so it fell
# through to the residue path; and because worktree.create derives the dir slug
# from the branch basename, that path landed on the LIVE directory and rm -rf'd
# another session's checkout while printing "git no longer tracks" and exiting 0.
# Reproduced, not theorised. Hence also the belt: the directory arm re-asks the
# registry about the exact path and refuses when git still lists it, which holds
# for the spellings the lookup cannot canonicalise (relative paths, and any future
# way for the two to disagree).
#
# Do NOT reintroduce "the branch basename IS the dir slug" as an invariant. It is
# false twice over: NAME=<dir-base> overrides the slug at creation, and the branch
# keeps its case while the dir is slugified, so feat/Foo_Bar lives in foo-bar-<sfx>.
# Where they diverge, no single NAME clears both residues — the recipe says so on
# the way out (a • line plus a non-zero exit) rather than reporting a green.
#
# Exit status is the contract: 0 means nothing is left. A run that deletes the
# directory but leaves a branch it can see, or that could not bring the stack down
# before removing the last handle on it, says so and exits non-zero. The failure
# this whole block exists to close was a green over a 48 MB checkout still on disk.
#
# NAME is reduced to its basename before it becomes a path, so nothing outside
# .claude/worktrees/ is reachable by an `rm -rf` — '', '.', '..', '/' and anything
# still containing a slash are refused.
#
# EVERY user-settable variable in this file — NAME, BRANCH, BASE, START, FORCE —
# is read as a SHELL variable ("$$NAME"), never spliced in as a make variable
# ('$(NAME)'). Make puts command-line and environment variables into each recipe's
# environment verbatim, so the shell arm is both exact and inert; the make arm is
# textual substitution, and a value carrying a single quote closes the literal and
# the rest executes. Measured on two of them: NAME="x'; echo INJECTED; echo '" ran
# that echo inside the `awk -v t=…` on the first line, and the same shape through
# START reached worktree.create's `if [ … = "true" ]`. That is upstream of every
# guard below, which is why the rule is the interpolation form and not a
# validation regex — and why it is stated over the whole set rather than as a list
# of the ones fixed so far. Enumerating them is what left START and remove-all's
# `FORCE=` behind the first time. The check is
#   grep -nE '[^$$]\$$\([A-Za-z]' make/worktree.mk
# — note the leading [^$$], without which every `$$(cmd)` substitution answers as a
# false positive — and it must return only $(PROJECT_ROOT), $(MAKE), $(shell …)
# and $(call …), none of which a caller can set.
#
# Stale dirs: when a worktree's directory was deleted out-of-band (e.g. an agent
# `rm -rf`), its stack can't be torn down via `$(MAKE) -C <dir>`. The recipe then
# re-derives the erpify-<slug> project from the dir basename (same slug rule as
# config.mk) and runs `docker compose -p erpify-<slug> down --volumes` from the
# main checkout so the orphaned containers/volumes don't leak.
#
# worktree.chown: reclaims ownership of the whole .claude/worktrees/ folder.
#                 Worktree stacks write bind-mounted files as root (pwa/.next,
#                 node_modules, api/var, …), which makes `git worktree remove` /
#                 `rm -rf` fail with "Permission denied". Mirrors pwa.chown.next:
#                 sudo chown -R to the host user, dev/test only. Covers every
#                 worktree at once, including stale dirs git no longer tracks.
#                 Run it, then retry worktree.remove.

## —— Worktrees ————————————————————————————————————————————————————————————

worktree.create: ## Create a worktree on a NEW branch BRANCH=<branch> (BASE=main; NAME=<dir-base>); a random suffix keeps branch/dir/stack unique; START=true brings its stack up
	@if [ -z "$$BRANCH" ]; then echo "✗ BRANCH=<branch> required (e.g. BRANCH=feat/backoffice-foo)"; exit 1; fi
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	sfx="$$(LC_ALL=C tr -dc 'a-z0-9' </dev/urandom | head -c 4)"; \
	branch="$$BRANCH-$$sfx"; \
	base="$$(printf '%s' "$${NAME:-$$(basename "$$BRANCH")}" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '-' | sed -E 's/^-+|-+$$//g')"; \
	dir="$$base-$$sfx"; \
	path="$$main/.claude/worktrees/$$dir"; \
	baseref="$${BASE:-main}"; \
	echo "→ creating worktree $$path on new branch $$branch (from $$baseref)"; \
	git -C "$$main" worktree add -b "$$branch" "$$path" "$$baseref" || { echo "✗ git worktree add failed"; exit 1; }; \
	if ls -d "$$path"/.agent/skills/bmad-*/ >/dev/null 2>&1; then \
		mkdir -p "$$path/.claude/skills"; \
		cp -a "$$path"/.agent/skills/bmad-*/ "$$path/.claude/skills/"; \
		echo "→ seeded .claude/skills/bmad-* from tracked .agent/skills (gitignored, missing from checkout)"; \
	fi; \
	if [ -d "$$main/_bmad" ] && [ ! -e "$$path/_bmad" ]; then \
		ln -s ../../../_bmad "$$path/_bmad"; \
		echo "→ linked _bmad -> the main checkout's install (gitignored; every bmad skill reads it on activation)"; \
	fi; \
	if [ "$$START" = "true" ]; then \
		echo "→ bringing up stack erpify-$$dir"; \
		$(MAKE) --no-print-directory -C "$$path" ENV=dev app.dev; \
		echo "✓ worktree ready at $$path (branch $$branch, stack erpify-$$dir up)"; \
	else \
		echo "✓ created worktree $$path (branch $$branch)"; \
		echo "  next: cd $$path && make app.dev"; \
	fi

worktree.list: ## List worktrees (NAME = dir name or path for worktree.remove)
	@git -C "$(PROJECT_ROOT)" worktree list

worktree.remove: ## Remove worktree NAME=<dir|path|branch> + its stack/volumes + branch; also sweeps a leftover dir/branch a half-finished run left behind; FORCE=true drops dirty/unmerged (destructive)
	@if [ -z "$$NAME" ]; then echo "✗ NAME=<worktree-dir-path-or-branch> required (see 'make worktree.list')"; exit 1; fi
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	if [ "$$FORCE" = "true" ]; then bdel=-D; wtf=--force; else bdel=-d; wtf=; fi; \
	t="$$(printf '%s' "$$NAME" | sed -E 's:/+$$::')"; [ -n "$$t" ] || t="$$NAME"; \
	wt="$$(git -C "$$main" worktree list --porcelain | awk -v t="$$t" '\
		$$1=="worktree"{p=$$2; b=p; sub(/.*\//,"",b); if (p==t || b==t){print p; exit}} \
		$$1=="branch"{r=$$2; sub(/^refs\/heads\//,"",r); if (r==t){print p; exit}}')"; \
	if [ -z "$$wt" ]; then \
		if [ "$$t" = "main" ]; then echo "✗ refusing to delete branch 'main'"; exit 1; fi; \
		found=; incomplete=; \
		base="$$(basename "$$t")"; \
		case "$$base" in ''|'.'|'..'|'/'|*/*) base=;; esac; \
		orphan=; \
		if [ -n "$$base" ] && [ -d "$$main/.claude/worktrees/$$base" ]; then orphan="$$main/.claude/worktrees/$$base"; fi; \
		if [ -n "$$orphan" ] && git -C "$$main" worktree list --porcelain | grep -qxF "worktree $$orphan"; then \
			echo "✗ $$orphan is a LIVE worktree git still tracks — refusing to delete it"; \
			echo "  NAME=$$NAME did not resolve to a registered worktree, so this would have been treated as a residue."; \
			echo "  Remove it deliberately by its own name: make worktree.remove NAME=$$base"; \
			exit 1; \
		fi; \
		if [ -n "$$orphan" ]; then \
			found=1; \
			slug="$$(printf '%s' "$$base" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '-' | sed -E 's/^-+|-+$$//g')"; \
			echo "→ git no longer tracks $$orphan but the directory survives; tearing down stack erpify-$$slug by project name"; \
			if ! (cd "$$main" && docker compose -p "erpify-$$slug" -f compose.yaml -f compose.dev.yaml down --remove-orphans --volumes); then \
				echo "• stack erpify-$$slug did NOT come down — its containers and volumes may survive, and deleting the directory removes the last input that reaches them by name"; \
				incomplete=1; \
			fi; \
			rm -rf "$$orphan" || { echo "✗ could not delete $$orphan — root-owned files ('Permission denied' → run 'make worktree.chown' first)"; exit 1; }; \
			git -C "$$main" worktree prune; \
			echo "✓ deleted leftover directory $$orphan"; \
		fi; \
		if git -C "$$main" show-ref --verify --quiet "refs/heads/$$t"; then \
			found=1; \
			echo "→ no worktree matches NAME=$$t; deleting the leftover branch"; \
			git -C "$$main" branch $$bdel "$$t" && echo "✓ deleted branch $$t" \
				|| { echo "• branch '$$t' kept — squash-merged branches look unmerged to git; re-run with FORCE=true"; exit 1; }; \
		elif [ -n "$$orphan" ]; then \
			for b in $$(git -C "$$main" for-each-ref --format='%(refname:short)' refs/heads | awk -v s="$$base" '{n=$$0; sub(/.*\//,"",n); if (n==s) print}'); do \
				echo "• branch '$$b' matches that directory's slug and is still here — confirm it holds no unpushed work, then: make worktree.remove NAME=$$b FORCE=true"; \
				incomplete=1; \
			done; \
		fi; \
		if [ -z "$$found" ]; then echo "✗ no worktree, leftover directory or local branch matches NAME=$$t (see 'make worktree.list')"; exit 1; fi; \
		left="$$(ls -1 "$$main/.claude/worktrees" 2>/dev/null | while IFS= read -r n; do \
			[ -d "$$main/.claude/worktrees/$$n" ] || continue; \
			git -C "$$main" worktree list --porcelain | grep -qxF "worktree $$main/.claude/worktrees/$$n" || printf '%s ' "$$n"; \
		done)"; \
		if [ -n "$$left" ]; then \
			echo "• untracked directories still under .claude/worktrees/: $$left"; \
			echo "  An orphan has no .git file, so nothing can say which branch held it — clear each with 'make worktree.remove NAME=<dir>', or all with 'make worktree.remove-all'."; \
		fi; \
		if [ -n "$$incomplete" ]; then echo "✗ removal incomplete — see the • lines above"; exit 1; fi; \
		exit 0; \
	fi; \
	if [ "$$wt" = "$$main" ]; then echo "✗ refusing to remove the main worktree ($$main)"; exit 1; fi; \
	branch="$$(git -C "$$main" worktree list --porcelain | awk -v p="$$wt" '$$1=="worktree"{w=$$2} $$1=="branch" && w==p {sub("refs/heads/","",$$2); print $$2}')"; \
	if [ -d "$$wt" ]; then \
		echo "→ tearing down stack for $$wt"; \
		$(MAKE) --no-print-directory -C "$$wt" ENV=dev docker.down.clean-volumes || true; \
	else \
		slug="$$(printf '%s' "$$(basename "$$wt")" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '-' | sed -E 's/^-+|-+$$//g')"; \
		echo "→ worktree dir is gone; tearing down stack erpify-$$slug by project name"; \
		(cd "$$main" && docker compose -p "erpify-$$slug" -f compose.yaml -f compose.dev.yaml down --remove-orphans --volumes) || true; \
	fi; \
	echo "→ removing worktree $$wt"; \
	git -C "$$main" worktree remove $$wtf "$$wt" || { echo "✗ worktree remove failed — dirty worktree (re-run with FORCE=true) or root-owned files ('Permission denied' → run 'make worktree.chown' first)"; exit 1; }; \
	git -C "$$main" worktree prune; \
	if [ -n "$$branch" ]; then \
		git -C "$$main" branch $$bdel "$$branch" && echo "✓ deleted branch $$branch" \
			|| { echo "• branch '$$branch' kept (squash-merged looks unmerged) — delete with 'make worktree.remove NAME=$$branch FORCE=true'"; exit 1; }; \
	fi

worktree.chown: ## Reclaim ownership of root-owned container-written files under .claude/worktrees/; fixes 'Permission denied' on worktree.remove (requires sudo; dev/test only)
	$(call guard_var_writable,worktree.chown)
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	dir="$$main/.claude/worktrees"; \
	if [ ! -d "$$dir" ]; then echo "✓ nothing to do — $$dir does not exist"; exit 0; fi; \
	echo "→ sudo chown -R $(shell id -un) $$dir"; \
	sudo chown -R $(shell id -u):$(shell id -g) "$$dir" \
		|| { echo "✗ chown failed — sudo could not authenticate (run this from a terminal, not an agent shell)"; exit 1; }; \
	echo "✓ $$dir now owned by $(shell id -un) — retry 'make worktree.remove NAME=…'"

worktree.remove-all: ## Remove ALL linked worktrees + their stacks/volumes + branches; FORCE=true drops dirty/unmerged (destructive)
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	wts="$$(git -C "$$main" worktree list --porcelain | awk '$$1=="worktree"{c++; if (c>1) print $$2}')"; \
	orphans="$$(ls -1 "$$main/.claude/worktrees" 2>/dev/null | while IFS= read -r n; do \
		[ -d "$$main/.claude/worktrees/$$n" ] || continue; \
		git -C "$$main" worktree list --porcelain | grep -qxF "worktree $$main/.claude/worktrees/$$n" || printf '%s\n' "$$n"; \
	done)"; \
	if [ -z "$$wts" ] && [ -z "$$orphans" ]; then echo "no linked worktrees to remove"; exit 0; fi; \
	printf '%s\n' "$$wts" "$$orphans" | while IFS= read -r wt; do \
		[ -n "$$wt" ] || continue; \
		$(MAKE) --no-print-directory worktree.remove NAME="$$wt" FORCE="$$FORCE" || echo "✗ failed to remove $$wt (continuing)"; \
	done; \
	git -C "$$main" worktree prune

.PHONY: worktree.create worktree.list worktree.remove worktree.chown worktree.remove-all
