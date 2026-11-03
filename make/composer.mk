# =============================================================================
# PHP Composer
# All recipes run via $(PHP_CONT) (container) or on host when IN_CONTAINER=false.
# =============================================================================

.PHONY: composer composer.install composer.update composer.upgrade composer.check.all \
        composer.check.platform-reqs composer.check.missing-deps composer.check.unused \
        composer.check.advisories

## —— Composer ————————————————————————————————————————————————————————————

composer: ## Run composer; pass c='…' (e.g. make composer c='req vendor/pkg')
	@$(COMPOSER) $(c)

composer.install: ## composer install (production-style flags)
	@$(COMPOSER) install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction

composer.update: ## composer update (safe, within composer.json constraints)
	@$(COMPOSER) update -W

composer.upgrade: ## Force upgrade direct Composer deps to the latest stable (bumps constraints across majors)
	@$(PHP) bin/composer-upgrade

composer.check.platform-reqs: ## composer check-platform-reqs
	@$(COMPOSER) check-platform-reqs

composer.check.missing-deps: ## Check for missing composer dependencies
	$(PHP_CONT) sh -c 'CONFIG=$$(find /app/api -name "composer-require-checker.json" | head -n 1); \
	XDEBUG_MODE=off /app/api/vendor/bin/composer-require-checker check --config-file=$$CONFIG /app/api/composer.json'

composer.check.unused: ## Check for unused Composer packages
	$(PHP) vendor/bin/composer-unused \
				--excludePackage=symfony/flex \
				--excludePackage=symfony/runtime \
				--excludePackage=symfony/dotenv \
				--excludePackage=symfony/yaml \
				--excludePackage=nelmio/cors-bundle \
				--ignore-exit-code

composer.check.all: composer.check.platform-reqs composer.check.missing-deps composer.check.unused ## Run all composer integrity checks

# The database is FriendsOfPHP/security-advisories, which the checker downloads on every run
# (the image carries none), so pinning the tag does not freeze the advisories and an offline
# run exits 127 rather than green. Only the lock file is mounted: no source tree, no secrets,
# no docker.sock. Deliberately outside composer.check.all, which runs in the php container.
SECURITY_CHECK_IMAGE ?= ghcr.io/symfony-cli/symfony-cli:v5

composer.check.advisories: ## Check api/composer.lock against known advisories (docker + network)
	@docker run --rm -v "$(API_ROOT)/composer.lock:/composer.lock:ro" $(SECURITY_CHECK_IMAGE) \
		security:check --format ansi --dir /composer.lock
