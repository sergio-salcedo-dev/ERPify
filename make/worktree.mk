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
#
# worktree.remove / worktree.remove-all:
# NAME=<dir|path>  selects the worktree (basename under .claude/worktrees/, or an
#                  absolute path). FORCE=true discards a dirty worktree and deletes
#                  a not-fully-merged branch (squash-merged branches look unmerged
#                  to git, so the common merged-PR case needs FORCE=true).

## —— Worktrees ————————————————————————————————————————————————————————————

worktree.create: ## Create a worktree on a NEW branch BRANCH=<branch> (BASE=main; NAME=<dir-base>); a random suffix keeps branch/dir/stack unique; START=true brings its stack up
	@if [ -z "$(BRANCH)" ]; then echo "✗ BRANCH=<branch> required (e.g. BRANCH=feat/backoffice-foo)"; exit 1; fi
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	sfx="$$(LC_ALL=C tr -dc 'a-z0-9' </dev/urandom | head -c 4)"; \
	branch="$(BRANCH)-$$sfx"; \
	base="$$(printf '%s' "$(if $(NAME),$(NAME),$$(basename '$(BRANCH)'))" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '-' | sed -E 's/^-+|-+$$//g')"; \
	dir="$$base-$$sfx"; \
	path="$$main/.claude/worktrees/$$dir"; \
	baseref='$(if $(BASE),$(BASE),main)'; \
	echo "→ creating worktree $$path on new branch $$branch (from $$baseref)"; \
	git -C "$$main" worktree add -b "$$branch" "$$path" "$$baseref" || { echo "✗ git worktree add failed"; exit 1; }; \
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

worktree.remove: ## Remove worktree NAME=<dir|path> + its stack/volumes + branch; FORCE=true drops dirty/unmerged (destructive)
	@if [ -z "$(NAME)" ]; then echo "✗ NAME=<worktree-dir-or-path> required (see 'make worktree.list')"; exit 1; fi
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	wt="$$(git -C "$$main" worktree list --porcelain | awk -v t='$(NAME)' '$$1=="worktree"{p=$$2; b=p; sub(/.*\//,"",b); if (p==t || b==t){print p; exit}}')"; \
	if [ -z "$$wt" ]; then echo "✗ no worktree matches NAME=$(NAME) (see 'make worktree.list')"; exit 1; fi; \
	if [ "$$wt" = "$$main" ]; then echo "✗ refusing to remove the main worktree ($$main)"; exit 1; fi; \
	branch="$$(git -C "$$main" worktree list --porcelain | awk -v p="$$wt" '$$1=="worktree"{w=$$2} $$1=="branch" && w==p {sub("refs/heads/","",$$2); print $$2}')"; \
	if [ -d "$$wt" ]; then \
		echo "→ tearing down stack for $$wt"; \
		$(MAKE) --no-print-directory -C "$$wt" ENV=dev docker.down.clean-volumes || true; \
	fi; \
	echo "→ removing worktree $$wt"; \
	git -C "$$main" worktree remove $(if $(FORCE),--force ,)"$$wt" || { echo "✗ worktree has changes; re-run with FORCE=true to discard"; exit 1; }; \
	git -C "$$main" worktree prune; \
	if [ -n "$$branch" ]; then \
		git -C "$$main" branch $(if $(FORCE),-D,-d) "$$branch" && echo "✓ deleted branch $$branch" \
			|| echo "• branch '$$branch' kept (not fully merged; re-run with FORCE=true to delete)"; \
	fi

worktree.remove-all: ## Remove ALL linked worktrees + their stacks/volumes + branches; FORCE=true drops dirty/unmerged (destructive)
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	wts="$$(git -C "$$main" worktree list --porcelain | awk '$$1=="worktree"{c++; if (c>1) print $$2}')"; \
	if [ -z "$$wts" ]; then echo "no linked worktrees to remove"; exit 0; fi; \
	printf '%s\n' "$$wts" | while IFS= read -r wt; do \
		[ -n "$$wt" ] || continue; \
		$(MAKE) --no-print-directory worktree.remove NAME="$$wt" FORCE=$(FORCE) || echo "✗ failed to remove $$wt (continuing)"; \
	done; \
	git -C "$$main" worktree prune

.PHONY: worktree.create worktree.list worktree.remove worktree.remove-all
