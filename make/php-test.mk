# =============================================================================
# PHP — Tests
# =============================================================================

## —— PHPUnit ——

php.test.unit: ## Run PHPUnit; pass c= for extra args
	@$(eval c ?=)
	$(DOCKER_EXEC_PHP_TEST) bin/phpunit $(c)

php.test.unit.install: ## Install PHPUnit tooling
	$(DOCKER_EXEC_PHP) composer phpunit-tools-install

## —— Behat (E2E / acceptance) ——

php.test.e2e: ## Run Behat; pass c= for extra args
	@$(eval c ?=)
	$(DOCKER_COMPOSE) $(DOCKER_COMPOSE_ENV) exec -e APP_ENV=test -e MINK_BASE_URL=$(MINK_BASE_URL) $(PHP_SERVICE) \
		php bin/behat --format=pretty $(c)

php.test.e2e.install: ## Install Behat tooling
	$(DOCKER_EXEC_PHP) composer behat-tools-install

## —— Aggregate ——

php.test: php.test.unit php.test.e2e ## Run all PHP tests

.PHONY: php.test.unit php.test.unit.install php.test.e2e php.test.e2e.install php.test
