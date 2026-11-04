# =============================================================================
# Unattended agents — scripts/agent/ (Claude Code + Antigravity `agy`).
# =============================================================================
#
# These targets run on the HOST, not in a container: they drive the agent CLIs
# the developer is already signed in to, and create worktrees through
# `make worktree.create`. Runbook: docs/runbooks/agent-automation.md.
#
# agent.install writes systemd USER units that point at THIS checkout's
# scripts, so it refuses to run from a linked worktree: the worktree is removed
# after its PR merges and the timers would then execute a path that no longer
# exists.

AGENT_SYSTEMD_DIR ?= $(HOME)/.config/systemd/user
AGENT_UNITS       := erpify-agent-poll.service erpify-agent-poll.timer \
                     erpify-agent-maintenance.service erpify-agent-maintenance.timer

.PHONY: agent.labels.setup agent.poll agent.maintenance agent.review \
        agent.install agent.uninstall agent.status

## —— Unattended agents ——

agent.labels.setup: ## Create the agent:* labels on GitHub (idempotent; writes to the remote repo)
	@$(PROJECT_ROOT)/scripts/agent/poll-issues.sh --setup-labels

agent.poll: ## Pick up one agent:* labelled issue now and open a PR (DRY_RUN=1 to only print)
	@$(PROJECT_ROOT)/scripts/agent/poll-issues.sh $(if $(DRY_RUN),--dry-run,)

agent.maintenance: ## Run the scheduled agent tasks now (ONLY=<task>, DRY_RUN=1)
	@$(PROJECT_ROOT)/scripts/agent/maintenance.sh $(if $(DRY_RUN),--dry-run,) $(if $(ONLY),--only $(ONLY),)

agent.review: ## Second-opinion PR review, read-only (PR=<n> required; ENGINE=agy|claude, POST=1 to comment)
	@test -n "$(PR)" || { echo "✗ agent.review: PR=<number> is required"; exit 1; }
	@$(PROJECT_ROOT)/scripts/agent/review-pr.sh "$(PR)" $(if $(ENGINE),--engine $(ENGINE),) $(if $(POST),--post,)

agent.install: ## Install and enable the systemd user timers (primary checkout only)
	@case "$(PROJECT_ROOT)" in \
		*/.claude/worktrees/*) echo "✗ agent.install: run it from the primary checkout, not a worktree that will be removed"; exit 1 ;; \
	esac
	@command -v systemctl >/dev/null 2>&1 || { echo "✗ agent.install: systemctl not found"; exit 1; }
	@mkdir -p "$(AGENT_SYSTEMD_DIR)"
	@for unit in $(AGENT_UNITS); do \
		sed 's|@REPO@|$(PROJECT_ROOT)|g' "$(PROJECT_ROOT)/scripts/agent/systemd/$$unit" > "$(AGENT_SYSTEMD_DIR)/$$unit" || exit 1; \
	done
	@systemctl --user daemon-reload
	@systemctl --user enable --now erpify-agent-poll.timer erpify-agent-maintenance.timer
	@echo "✓ agent.install: timers enabled (make agent.status to inspect)"

agent.uninstall: ## Disable and remove the systemd user timers
	@systemctl --user disable --now erpify-agent-poll.timer erpify-agent-maintenance.timer 2>/dev/null || true
	@for unit in $(AGENT_UNITS); do rm -f "$(AGENT_SYSTEMD_DIR)/$$unit"; done
	@systemctl --user daemon-reload
	@echo "✓ agent.uninstall: timers removed"

agent.status: ## Show the agent timers and the tail of today's agent log
	@systemctl --user list-timers 'erpify-agent-*' --no-pager 2>/dev/null || echo "no erpify-agent timers installed"
	@tail -n 20 "$${XDG_STATE_HOME:-$$HOME/.local/state}/erpify-agent/logs/$$(date +%Y%m%d).log" 2>/dev/null || echo "no log for today"
