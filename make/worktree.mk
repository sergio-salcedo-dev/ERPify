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
# NAME=<dir|path|branch>  selects the worktree (basename under .claude/worktrees/,
#                  or an absolute path). FORCE=true discards a dirty worktree and
#                  deletes a not-fully-merged branch (squash-merged branches look
#                  unmerged to git, so the common merged-PR case needs FORCE=true).
#
# Recovery when no worktree matches NAME. A removal must clear three independent
# residues — a registered worktree, a directory, a branch — and `git worktree
# remove` can fail having already dropped the registration, so the recipe must be
# able to finish a job it started. It therefore sweeps BOTH leftovers in one run
# and only errors when neither exists:
#   · a leftover directory at .claude/worktrees/<basename NAME> that git no
#     longer tracks — tear its stack down by project name, then rm -rf it;
#   · a leftover local branch named exactly NAME.
#   Both fire when both match, which is what makes NAME=<branch> whole: the
#   branch's basename IS the dir slug, so one command clears the pair.
# Sweeping the directory is the half that must not be skipped, because its
# absence failed SILENTLY: with only the branch arm, NAME=<branch> printed
# "✓ deleted branch" and exited 0 over a 48 MB checkout still on disk, while
# NAME=<dir> exited 1 claiming "no worktree or local branch matches" with the
# directory and the branch both sitting right there. A green over a residue is
# worse than the residue.
# NAME is reduced to its basename before it becomes a path, so nothing outside
# .claude/worktrees/ is reachable by an `rm -rf` — '.', '..' and '/' are refused.
#
# BRANCH/BASE/NAME are read as SHELL variables ("$$NAME"), never spliced into the
# recipe as make variables ('$(NAME)'). Make puts command-line variables into each
# recipe's environment verbatim, so the shell arm is both exact and inert; the
# make arm is textual substitution, and a value carrying a single quote closes the
# literal and the rest executes. Measured: NAME="x'; echo INJECTED; echo '" ran
# that echo inside the `awk -v t=…` on the first line and fed its output onward as
# the resolved worktree path. That is upstream of every guard below, which is why
# the rule is the interpolation form and not a validation regex.
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
	if [ "$(START)" = "true" ]; then \
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
	wt="$$(git -C "$$main" worktree list --porcelain | awk -v t="$$NAME" '$$1=="worktree"{p=$$2; b=p; sub(/.*\//,"",b); if (p==t || b==t){print p; exit}}')"; \
	if [ -z "$$wt" ]; then \
		if [ "$$NAME" = "main" ]; then echo "✗ refusing to delete branch 'main'"; exit 1; fi; \
		found=; \
		base="$$(basename "$$NAME")"; \
		case "$$base" in ''|'.'|'..'|'/') base=;; esac; \
		orphan=; \
		if [ -n "$$base" ] && [ -d "$$main/.claude/worktrees/$$base" ]; then orphan="$$main/.claude/worktrees/$$base"; fi; \
		if [ -n "$$orphan" ]; then \
			found=1; \
			slug="$$(printf '%s' "$$base" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '-' | sed -E 's/^-+|-+$$//g')"; \
			echo "→ git no longer tracks $$orphan but the directory survives; tearing down stack erpify-$$slug by project name"; \
			(cd "$$main" && docker compose -p "erpify-$$slug" -f compose.yaml -f compose.dev.yaml down --remove-orphans --volumes) || true; \
			rm -rf "$$orphan" || { echo "✗ could not delete $$orphan — root-owned files ('Permission denied' → run 'make worktree.chown' first)"; exit 1; }; \
			echo "✓ deleted leftover directory $$orphan"; \
			for b in $$(git -C "$$main" for-each-ref --format='%(refname:short)' refs/heads | awk -v s="$$base" '{n=$$0; sub(/.*\//,"",n); if (n==s) print}'); do \
				[ "$$b" = "$$NAME" ] && continue; \
				echo "• branch '$$b' held that worktree and is still here — delete it with 'make worktree.remove NAME=$$b FORCE=true'"; \
			done; \
		fi; \
		if git -C "$$main" show-ref --verify --quiet "refs/heads/$$NAME"; then \
			found=1; \
			echo "→ no worktree matches NAME=$$NAME; deleting the leftover branch"; \
			git -C "$$main" branch $(if $(FORCE),-D,-d) "$$NAME" && echo "✓ deleted branch $$NAME" \
				|| { echo "• branch '$$NAME' kept — squash-merged branches look unmerged to git; re-run with FORCE=true"; exit 1; }; \
		fi; \
		if [ -z "$$found" ]; then echo "✗ no worktree, leftover directory or local branch matches NAME=$$NAME (see 'make worktree.list')"; exit 1; fi; \
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
	git -C "$$main" worktree remove $(if $(FORCE),--force ,)"$$wt" || { echo "✗ worktree remove failed — dirty worktree (re-run with FORCE=true) or root-owned files ('Permission denied' → run 'make worktree.chown' first)"; exit 1; }; \
	git -C "$$main" worktree prune; \
	if [ -n "$$branch" ]; then \
		git -C "$$main" branch $(if $(FORCE),-D,-d) "$$branch" && echo "✓ deleted branch $$branch" \
			|| echo "• branch '$$branch' kept (squash-merged looks unmerged) — delete with 'make worktree.remove NAME=$$branch FORCE=true'"; \
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
	if [ -z "$$wts" ]; then echo "no linked worktrees to remove"; exit 0; fi; \
	printf '%s\n' "$$wts" | while IFS= read -r wt; do \
		[ -n "$$wt" ] || continue; \
		$(MAKE) --no-print-directory worktree.remove NAME="$$wt" FORCE=$(FORCE) || echo "✗ failed to remove $$wt (continuing)"; \
	done; \
	git -C "$$main" worktree prune

.PHONY: worktree.create worktree.list worktree.remove worktree.chown worktree.remove-all
