# =============================================================================
# PHP Composer
# =============================================================================

composer.check.deps: ## Check for missing composer dependencies
	$(PHP_CONT) sh -c 'XDEBUG_MODE=off /app/vendor/bin/composer-require-checker check --config-file=/app/tools/composer-require-checker/composer-require-checker.json /app/composer.json'

composer.check.platform-reqs: ## Check for missing composer dependencies
	$(COMPOSER) check-platform-reqs

composer.checks: composer.check.platform-reqs composer.check.deps ## Run all linters
