# =============================================================================
# GIT CONFIG & SUBMODULES
# =============================================================================

.PHONY: git.container.config.disable-check-safe-directory git.container.config.trust-workdir \
		git.submodule.init git.submodule.update

git.container.config.disable-check-safe-directory: ## Disable Git's safe.directory check inside PHP container (for bind-mounted repo)
	$(DOCKER_COMPOSE_EXEC) -T $(PHP_SERVICE) git config --global --add safe.directory '*'

git.container.config.trust-workdir: ## Mark /app as safe inside PHP container
	$(DOCKER_COMPOSE_EXEC) -T $(PHP_SERVICE) git config --global --add safe.directory /app

git.submodule.init: ## Init submodules (first-time setup)
	git submodule update --init --recursive

git.submodule.update: ## Update submodules to tracked commits
	git submodule update --recursive --remote
