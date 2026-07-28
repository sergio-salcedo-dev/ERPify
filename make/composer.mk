# =============================================================================
# PHP Composer
# All recipes run via $(PHP_CONT) (container) or on host when IN_CONTAINER=false.
# =============================================================================

.PHONY: composer composer.install composer.update composer.upgrade composer.check.all \
        composer.check.platform-reqs composer.check.missing-deps composer.check.unused \
        composer.check.mercure-pin

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

## —— Upstream pin watch ——————————————————————————————————————————————————————

# Host-side (curl + jq, no stack). Run by the weekly CI cron, not by PR lanes:
# it observes upstream state, not this diff. `symfony/mercure-bundle` is pinned
# to the untagged `0.4.x-dev` branch (the `v0.4.2` tag trips the PHPUnit
# deprecation gate under Symfony 8.1 — see api/CLAUDE.md, "Rules that bite");
# the retirement trigger is the first upstream tag newer than `v0.4.2`. A
# network/parse failure exits 1: a watch that cannot see must fail, not pass.
# Tracking issue: https://github.com/sergio-salcedo-dev/ERPify/issues/593
composer.check.mercure-pin: ## Fail once upstream tags symfony/mercure-bundle past v0.4.2 (retire the 0.4.x-dev pin)
	@versions=$$(curl -fsS --max-time 30 https://repo.packagist.org/p2/symfony/mercure-bundle.json \
		| jq -er '.packages["symfony/mercure-bundle"][].version_normalized'); \
	if [ -z "$$versions" ]; then \
		echo "composer.check.mercure-pin: cannot read upstream tags from Packagist — failing loud, not passing blind"; \
		exit 1; \
	fi; \
	newest=$$(printf '%s\n0.4.2.0\n' "$$versions" | sort -V | tail -n 1); \
	if [ "$$newest" = "0.4.2.0" ]; then \
		echo "composer.check.mercure-pin: OK — newest upstream tag is v0.4.2; the 0.4.x-dev pin stands (issue #593)"; \
	else \
		echo "composer.check.mercure-pin: upstream tagged $$newest (newer than 0.4.2) — retire the pin:"; \
		echo "  1. verify the tag contains upstream 8708c813 (api/CLAUDE.md, 'Rules that bite')"; \
		echo "  2. pin the tag in api/composer.json, drop its stability-flags entry, re-run the deprecation gate cold"; \
		echo "  3. close issue #593 and update the acceptance record in PRODUCTION_SECURITY_CHECKLIST.md §7"; \
		exit 1; \
	fi
