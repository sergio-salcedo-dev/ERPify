# =============================================================================
# GIT CONFIG & SUBMODULES
# =============================================================================

.PHONY: git.container.config.disable.check.safe.directory git.container.config.trust.workdir \
				git.submodule.init git.submodule.update

git.container.config.disable.check.safe.directory:
	docker compose exec -T $(PHP_SERVICE) git config --global --add safe.directory '*'

git.container.config.trust.workdir: ## Mark /app as safe inside PHP container
	$(DOCKER_COMPOSE) exec -T $(PHP_SERVICE) git config --global --add safe.directory /app

git.submodule.init: ## Update submodules to tracked commits
	git submodule update --init --recursive

git.submodule.update:
	git submodule update --recursive --remote
