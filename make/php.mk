# =============================================================================
# PHP container utilities
# =============================================================================

.PHONY: php.check.modules \
		php.sh php.bash php.exec \
 		php.fix.ownership

php.check.modules: ## Check PHP modules (check installed modules)
	$(PHP_CONT) php -m

php.sh: ## Shell (sh) in the php container
	@$(DOCKER_COMPOSE_EXEC) $(PHP_SERVICE) sh

php.bash: ## Shell (bash) in the php container
	@$(DOCKER_COMPOSE_EXEC) $(PHP_SERVICE) bash

php.exec: ## Run arbitrary cmd in php container; pass cmd='...'
	@$(DOCKER_COMPOSE_EXEC) $(PHP_SERVICE) $(cmd)

## —— Ownership ——
php.fix.ownership: ## Chown container files to host user (Linux only; fixes CI ACL issues)
	$(DOCKER_COMPOSE_EXEC) -T $(PHP_SERVICE) chown -R $$(id -u):$$(id -g) .
