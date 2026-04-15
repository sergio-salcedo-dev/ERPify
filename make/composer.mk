# =============================================================================
# PHP Composer
# =============================================================================

.PHONY: composer.check.deps composer.check.platform-reqs composer.checks

composer.check.deps: ## Check for missing composer dependencies
	$(PHP_CONT) sh -c 'CONFIG=$$(find /app -name "composer-require-checker.json" | head -n 1); \
	XDEBUG_MODE=off /app/vendor/bin/composer-require-checker check --config-file=$$CONFIG /app/composer.json'

composer.check.platform-reqs: ## Check for missing composer dependencies
	$(COMPOSER) check-platform-reqs

composer.checks: composer.check.platform-reqs composer.check.deps ## Run all linters
