# =============================================================================
# PHP test suites (PHPUnit + Behat).
# =============================================================================

# Target names match CI (.github/workflows/ci.yml):
#   php.unit / php.unit.install / php.behat / php.behat.install / php.test

.PHONY: php.unit php.unit.install \
		php.behat php.behat.install \
		php.test \
		php.bench

## —— PHP Unit ——

php.unit: ## PHPUnit; pass c='…' for extra args (e.g. c='--filter SomeTest')
	@$(PHP_TEST) bin/phpunit $(c)

php.unit.install: ## Install PHPUnit tooling (api/tools/phpunit)
	@$(COMPOSER) phpunit-tools-install

## —— Behat ——

php.behat: ## Behat; pass c='…' for extra args, example: php.behat c='features/backoffice/bank/get.feature'
	@$(PHP_BEHAT) php tools/behat/run.php -c tools/behat/behat.yml.dist --format=pretty $(c)

php.behat.install: ## Install Behat tooling (api/tools/behat)
	@$(COMPOSER) behat-tools-install

## —— benchmarks ——

php.bench: ## Run listener performance-budget benchmarks (opt-in, default php.unit skips)
ifeq ($(IN_CONTAINER),false)
	@cd $(API_ROOT) && APP_ENV=test RUN_BENCHMARKS=1 bin/phpunit --group benchmark $(c)
else
	@$(DOCKER_COMPOSE_EXEC) -e APP_ENV=test -e RUN_BENCHMARKS=1 $(PHP_SERVICE) bin/phpunit --group benchmark $(c)
endif


## —— All PHP tests ——

php.test: php.unit php.behat ## Full PHP test suite (PHPUnit + Behat)
