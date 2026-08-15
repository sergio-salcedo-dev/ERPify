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
# prints `error: failed to delete …`, exits 255, the checkout is still on disk and
# the registration is already gone. (Whether the checkout's `.git` file also went
# depends on readdir order against the undeletable entry — it is not a marker you
# may test for.) So the recipe must be able to finish a job it started.
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
# Reproduced, not theorised.
#
# THE BELT IS A CONTAINMENT TEST, NOT AN EQUALITY TEST, and that distinction is
# the second version of the same bug. Asking only "is $orphan itself registered?"
# says nothing about a worktree registered INSIDE it, so `NAME=<parent>` sailed
# through and deleted a live nested checkout. The predicate is therefore
# `p == $orphan || p starts with "$orphan/"` over every registered path, and the
# same predicate decides what counts as untracked everywhere else in this file.
# It reads the registry ONCE, up front, and refuses outright if that read comes
# back empty — an `rm -rf` gated on a command that might have failed is not gated.
# Its remaining unstated assumption: .claude/worktrees is a real directory, not a
# symlink, since git registers resolved paths while this builds literal ones.
#
# Do NOT reintroduce "the branch basename IS the dir slug" as an invariant. It is
# false twice over: NAME=<dir-base> overrides the slug at creation, and the branch
# keeps its case while the dir is slugified, so feat/Foo_Bar lives in foo-bar-<sfx>.
# Comparing SLUGS rather than basenames recovers the second case, and it has to be
# done in BOTH directions or the asymmetry just moves: dir→branch when NAME names
# the directory, and branch→dir (try $root/<basename>, then $root/<slug of it>)
# when NAME names the branch. Fixing only the first left `NAME=chore/Foo-ab12`
# deleting the branch and exiting 0 with foo-ab12/ still on disk. The remaining
# case — NAME=<dir-base> overriding the slug at creation — is genuinely
# unattributable, and is reported rather than guessed at.
#
# Exit status is the contract, stated at the strength it is actually implemented:
# 0 means this invocation cleared everything it could attribute to NAME. A stack
# that would not come down, a branch that survives its directory, a sub-removal
# that failed under remove-all — each prints a • and exits non-zero, on BOTH the
# registered and the residue path (converting only one of them is how the previous
# version claimed this three times and delivered it on the arm nobody takes).
# Residues it CANNOT attribute — an untracked directory that no branch slugifies
# to, and which may not even be a worktree — are listed with ℹ and do not fail the
# run, because the alternative is a tool that can never exit 0. Read the two
# markers as what they are: • is something this run owed you, ℹ is something for
# you to look at. The failure all of this exists to close was a green over a 48 MB
# checkout still on disk; a green over a residue nobody can attribute is a
# different, smaller claim, and it is the one being made.
#
# NAME is reduced to its basename before it becomes a path, so nothing outside
# .claude/worktrees/ is reachable by an `rm -rf` — '', '.', '..', '/' and anything
# still containing a slash are refused.
#
# The five variables this file's own interface exposes — NAME, BRANCH, BASE,
# START, FORCE — are read as SHELL variables ("$$NAME"), never spliced in as make
# variables ('$(NAME)'). Make puts command-line and environment variables into each
# recipe's environment verbatim, so the shell arm is both exact and inert; the make
# arm is textual substitution, and a value carrying a single quote closes the
# literal and the rest executes. Measured on two of them: NAME="x'; echo INJECTED;
# echo '" ran that echo inside the `awk -v t=…` on the first line, and the same
# shape through START reached worktree.create's `if [ … = "true" ]`. That is
# upstream of every guard below, which is why the rule is the interpolation form
# and not a validation regex.
#
# Scope, stated because the obvious wider claim is false: PROJECT_ROOT and ENV are
# still make-spliced here, and both ARE caller-settable (a command-line assignment
# beats config.mk's `:=`, and ENV is a `?=`). They are repo-wide make plumbing
# spliced across every module, so pinning them in this one file would buy nothing
# and hide the real scope. Nothing here is a privilege boundary either way — a
# caller who can set them already has a shell; what the rule buys is that a value
# an AGENT composed cannot silently become code.
#
# The check, and it is the second thing this comment got wrong: written with make's
# `$$` escaping it becomes an unsatisfiable ERE (a literal `$` followed by an
# end-of-line anchor) that matches nothing, ever — a gate that could not go red,
# in the paragraph policing the rule. Paste this into a SHELL, not a recipe:
#   grep -nE '[^$]\$\([A-Za-z]' make/worktree.mk | grep -v ':[[:space:]]*#'
# The leading [^$] is what stops every `$$(cmd)` substitution answering as a false
# positive, and the second grep drops this comment's own prose. It must return only
# $(PROJECT_ROOT), $(MAKE), $(shell …), $(call …) and $(worktree_slugify).
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

# The one slug rule, as a stdin→stdout filter so a single definition serves the
# dir name, the Compose project and the branch comparisons. It must match
# config.mk's WORKTREE_SLUG or the by-project-name teardown targets the wrong
# stack. `\n` is kept in the tr set deliberately: the collision check feeds it a
# LIST, and the original per-value form folded newlines into '-'.
worktree_slugify = tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9\n' '-' | sed -E 's/^-+|-+$$//g'

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

worktree.list: ## List REGISTERED worktrees (dir name, path or branch all work as NAME); leftover directories are not listed here — worktree.remove reports them
	@git -C "$(PROJECT_ROOT)" worktree list

worktree.remove: ## Remove worktree NAME=<dir|path|branch> + its stack/volumes + branch; also sweeps a leftover dir/branch a half-finished run left behind; FORCE=true drops dirty/unmerged (destructive)
	@if [ -z "$$NAME" ]; then echo "✗ NAME=<worktree-dir-path-or-branch> required (see 'make worktree.list')"; exit 1; fi
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	root="$$main/.claude/worktrees"; \
	if [ "$$FORCE" = "true" ]; then bdel=-D; wtf=--force; else bdel=-d; wtf=; fi; \
	t="$$(printf '%s' "$$NAME" | sed -E 's:/+$$::')"; [ -n "$$t" ] || t="$$NAME"; \
	reg="$$(git -C "$$main" worktree list --porcelain | sed -n 's/^worktree //p')"; \
	[ -n "$$reg" ] || { echo "✗ could not read the worktree registry — refusing to classify anything as a residue"; exit 1; }; \
	wt="$$(git -C "$$main" worktree list --porcelain | awk -v t="$$t" '\
		$$1=="worktree"{p=$$2; b=p; sub(/.*\//,"",b); if (p==t || b==t){print p; exit}} \
		$$1=="branch"{r=$$2; sub(/^refs\/heads\//,"",r); if (r==t){print p; exit}}')"; \
	incomplete=; \
	if [ -z "$$wt" ]; then \
		if [ "$$t" = "main" ]; then echo "✗ refusing to delete branch 'main'"; exit 1; fi; \
		found=; \
		base="$$(basename "$$t")"; \
		case "$$base" in ''|'.'|'..'|'/'|*/*) base=;; esac; \
		orphan=; \
		if [ -n "$$base" ]; then \
			bslug="$$(printf '%s' "$$base" | $(worktree_slugify))"; \
			if [ -d "$$root/$$base" ]; then orphan="$$root/$$base"; \
			elif [ -n "$$bslug" ] && [ -d "$$root/$$bslug" ]; then orphan="$$root/$$bslug"; fi; \
		fi; \
		if [ -n "$$orphan" ] && printf '%s\n' "$$reg" | awk -v o="$$orphan" '$$0==o || index($$0, o "/")==1 {f=1} END{exit !f}'; then \
			echo "✗ $$orphan is (or contains) a worktree git still tracks — refusing to delete it"; \
			echo "  NAME=$$NAME resolved to no registered worktree, so it would have been treated as a residue."; \
			echo "  Other sessions run out of this directory. Confirm the checkout is yours, then remove it by its own"; \
			echo "  registered name: make worktree.remove NAME=<dir>   (see 'make worktree.list')"; \
			exit 1; \
		fi; \
		if [ -n "$$orphan" ]; then \
			found=1; \
			slug="$$(basename "$$orphan" | $(worktree_slugify))"; \
			if printf '%s\n' "$$reg" | awk -v s="$$slug" '{b=$$0; sub(/.*\//,"",b); print b}' | $(worktree_slugify) | grep -qxF "$$slug"; then \
				echo "• a registered worktree slugifies to the same Compose project erpify-$$slug — NOT tearing it down, that stack is not this directory's"; \
				incomplete=1; \
			else \
				echo "→ git no longer tracks $$orphan but the directory survives; tearing down stack erpify-$$slug by project name"; \
				if ! (cd "$$main" && docker compose -p "erpify-$$slug" -f compose.yaml -f compose.dev.yaml down --remove-orphans --volumes); then \
					echo "• stack erpify-$$slug did NOT come down; reach it by name with:"; \
					echo "    docker compose -p erpify-$$slug -f compose.yaml -f compose.dev.yaml down --remove-orphans --volumes"; \
					incomplete=1; \
				fi; \
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
			for b in $$(git -C "$$main" for-each-ref --format='%(refname:short)' refs/heads); do \
				[ "$$(printf '%s' "$$b" | sed 's:.*/::' | $(worktree_slugify))" = "$$slug" ] || continue; \
				echo "• branch '$$b' slugifies to that directory — confirm it holds no unpushed work, then: make worktree.remove NAME=$$b FORCE=true"; \
				incomplete=1; \
			done; \
		fi; \
		if [ -z "$$found" ]; then echo "✗ no worktree, leftover directory or local branch matches NAME=$$t (see 'make worktree.list')"; exit 1; fi; \
	else \
		if [ "$$wt" = "$$main" ]; then echo "✗ refusing to remove the main worktree ($$main)"; exit 1; fi; \
		branch="$$(git -C "$$main" worktree list --porcelain | awk -v p="$$wt" '$$1=="worktree"{w=$$2} $$1=="branch" && w==p {sub("refs/heads/","",$$2); print $$2}')"; \
		slug="$$(printf '%s' "$$(basename "$$wt")" | $(worktree_slugify))"; \
		if [ -d "$$wt" ]; then \
			echo "→ tearing down stack for $$wt"; \
			$(MAKE) --no-print-directory -C "$$wt" ENV=dev docker.down.clean-volumes \
				|| { echo "• stack erpify-$$slug did NOT come down; after this removal reach it by name with:"; \
					echo "    docker compose -p erpify-$$slug -f compose.yaml -f compose.dev.yaml down --remove-orphans --volumes"; \
					incomplete=1; }; \
		else \
			echo "→ worktree dir is gone; tearing down stack erpify-$$slug by project name"; \
			(cd "$$main" && docker compose -p "erpify-$$slug" -f compose.yaml -f compose.dev.yaml down --remove-orphans --volumes) \
				|| { echo "• stack erpify-$$slug did NOT come down; rerun that compose command by hand"; incomplete=1; }; \
		fi; \
		echo "→ removing worktree $$wt"; \
		git -C "$$main" worktree remove $$wtf "$$wt" || { echo "✗ worktree remove failed — dirty worktree (re-run with FORCE=true) or root-owned files ('Permission denied' → run 'make worktree.chown' first)"; exit 1; }; \
		git -C "$$main" worktree prune; \
		if [ -n "$$branch" ]; then \
			git -C "$$main" branch $$bdel "$$branch" && echo "✓ deleted branch $$branch" \
				|| { echo "• branch '$$branch' kept (squash-merged looks unmerged) — delete with 'make worktree.remove NAME=$$branch FORCE=true'"; exit 1; }; \
		fi; \
	fi; \
	left=; for p in "$$root"/* "$$root"/.[!.]*; do \
		[ -d "$$p" ] || continue; \
		printf '%s\n' "$$reg" | grep -qxF "$$p" || left="$$left$${p##*/} "; \
	done; \
	if [ -n "$$left" ]; then \
		echo "ℹ directories under .claude/worktrees/ that git does not track: $$left"; \
		echo "  A residue carries no marker, and one of these may not be a worktree at all — nothing on disk says"; \
		echo "  which branch held it. Inspect, then clear one with 'make worktree.remove NAME=<dir>'."; \
	fi; \
	if [ -n "$$incomplete" ]; then echo "✗ removal incomplete — see the • lines above"; exit 1; fi

worktree.chown: ## Reclaim ownership of root-owned container-written files under .claude/worktrees/; fixes 'Permission denied' on worktree.remove (requires sudo; dev/test only)
	$(call guard_var_writable,worktree.chown)
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	dir="$$main/.claude/worktrees"; \
	if [ ! -d "$$dir" ]; then echo "✓ nothing to do — $$dir does not exist"; exit 0; fi; \
	echo "→ sudo chown -R $(shell id -un) $$dir"; \
	sudo chown -R $(shell id -u):$(shell id -g) "$$dir" \
		|| { echo "✗ chown failed — sudo could not authenticate (run this from a terminal, not an agent shell)"; exit 1; }; \
	echo "✓ $$dir now owned by $(shell id -un) — retry 'make worktree.remove NAME=…'"

worktree.remove-all: ## Remove every REGISTERED worktree + its stack/volumes + branch (never an untracked directory — clear those one by one); exits non-zero if any did not remove cleanly; FORCE=true drops dirty/unmerged (destructive)
	@main="$$(git -C "$(PROJECT_ROOT)" worktree list --porcelain | awk '/^worktree /{print $$2; exit}')"; \
	wts="$$(git -C "$$main" worktree list --porcelain | awk '$$1=="worktree"{c++; if (c>1) print $$2}')"; \
	if [ -z "$$wts" ]; then echo "no linked worktrees to remove"; exit 0; fi; \
	fail="$$(mktemp)"; \
	printf '%s\n' "$$wts" | while IFS= read -r wt; do \
		[ -n "$$wt" ] || continue; \
		$(MAKE) --no-print-directory worktree.remove NAME="$$wt" FORCE="$$FORCE" \
			|| { echo "✗ failed to remove $$wt (continuing)"; echo x >> "$$fail"; }; \
	done; \
	git -C "$$main" worktree prune; \
	n="$$(wc -l < "$$fail" | tr -d ' ')"; rm -f "$$fail"; \
	if [ "$$n" != "0" ]; then echo "✗ $$n worktree(s) did not remove cleanly — rerun them individually"; exit 1; fi

.PHONY: worktree.create worktree.list worktree.remove worktree.chown worktree.remove-all
