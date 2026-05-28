# =============================================================================
# PHP Composer
# All recipes run via $(PHP_CONT) (container) or on host when IN_CONTAINER=false.
# =============================================================================

.PHONY: composer composer.install composer.update composer.check.all \
        composer.check.platform-reqs composer.check.missing-deps composer.check.unused

## —— Composer ————————————————————————————————————————————————————————————

composer: ## Run composer; pass c='…' (e.g. make composer c='req vendor/pkg')
	@$(COMPOSER) $(c)

composer.install: ## composer install (production-style flags)
	@$(COMPOSER) install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction

composer.update: ## composer update
	@$(COMPOSER) update -W

composer.check.platform-reqs: ## composer check-platform-reqs
	@$(COMPOSER) check-platform-reqs

composer.check.missing-deps: ## Check for missing composer dependencies
	$(PHP_CONT) sh -c 'CONFIG=$$(find /app -name "composer-require-checker.json" | head -n 1); \
	XDEBUG_MODE=off /app/vendor/bin/composer-require-checker check --config-file=$$CONFIG /app/composer.json'

composer.check.unused: ## Check for unused Composer packages
	$(PHP) vendor/bin/composer-unused \
				--excludePackage=symfony/flex \
				--excludePackage=symfony/runtime \
				--excludePackage=symfony/dotenv \
				--excludePackage=symfony/yaml \
				--excludePackage=nelmio/cors-bundle \
				--ignore-exit-code

composer.check.all: composer.check.platform-reqs composer.check.missing-deps composer.check.unused ## Run all composer integrity checks
