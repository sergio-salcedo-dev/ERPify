# =============================================================================
# PHP Composer
# =============================================================================

.PHONY: composer.check.deps composer.check.platform-reqs composer.unused composer.checks

composer.check.deps: ## Check for missing composer dependencies
	$(PHP_CONT) sh -c 'CONFIG=$$(find /app -name "composer-require-checker.json" | head -n 1); \
	XDEBUG_MODE=off /app/vendor/bin/composer-require-checker check --config-file=$$CONFIG /app/composer.json'

composer.check.platform-reqs: ## Check for missing composer dependencies
	$(COMPOSER) check-platform-reqs

composer.unused: ## Check for unused Composer packages
	$(PHP) vendor/bin/composer-unused \
				--excludePackage=symfony/flex \
				--excludePackage=symfony/runtime \
				--excludePackage=symfony/dotenv \
				--excludePackage=symfony/yaml \
				--excludePackage=nelmio/cors-bundle \
				--ignore-exit-code

composer.checks: composer.check.platform-reqs composer.check.deps composer.unused ## Run all composer linters
