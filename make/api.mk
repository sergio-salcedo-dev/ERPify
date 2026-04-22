# make/api.mk — Composer, Symfony console, Messenger.
# All recipes run via $(PHP_CONT) (container) or on host when IN_CONTAINER=false.

## —— Composer ————————————————————————————————————————————————————————————

composer: ## Run composer; pass c='…' (e.g. make composer c='req vendor/pkg')
	@$(eval c ?=)
	@$(COMPOSER) $(c)

composer.install: ## composer install (production-style flags)
	@$(COMPOSER) install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction

composer.update: ## composer update
	@$(COMPOSER) update

composer.check.platform-reqs: ## composer check-platform-reqs
	@$(COMPOSER) check-platform-reqs

composer.check.deps: ## composer-require-checker
	@$(PHP_CONT) composer-require-checker check --config-file=tools/composer-require-checker/config.json

composer.check.unused: ## composer-unused
	@$(PHP) tools/composer-unused/vendor/icanhazstring/composer-unused/bin/composer-unused

composer.unused: ## Check for unused Composer packages
	$(PHP) vendor/bin/composer-unused \
				--excludePackage=symfony/flex \
				--excludePackage=symfony/runtime \
				--excludePackage=symfony/dotenv \
				--excludePackage=symfony/yaml \
				--excludePackage=nelmio/cors-bundle \
				--ignore-exit-code

composer.checks: composer.check.platform-reqs composer.check.deps composer.check.unused ## Run all composer integrity checks

## —— Symfony console ——————————————————————————————————————————————————————

sf: ## Run Symfony console; pass c='…' (e.g. make sf c='about')
	@$(eval c ?=)
	@$(SYMFONY) $(c)

cc: ## cache:clear
	@$(SYMFONY) cache:clear

cache.warmup: ## cache:warmup (use after deploy)
	@$(SYMFONY) cache:warmup

routes: ## debug:router (filter with f='…')
	@$(eval f ?=)
	@$(SYMFONY) debug:router $(if $(f),--show-controllers | grep $(f),)

symfony.about: ## bin/console about
	@$(SYMFONY) about

php.modules: ## php -m (list extensions)
	@$(PHP_CONT) php -m

## —— Messenger ————————————————————————————————————————————————————————————

messenger.stop-workers: ## Stop all messenger workers (use after deploy)
	@$(SYMFONY) messenger:stop-workers

## —— api/var maintenance (dev/test only) ——————————————————————————————————
# Container runs as root, so files it writes to bind-mounted api/var are
# root-owned on the host. These targets delegate deletion to the container
# (no sudo needed), or offer a one-shot host-side chown as an escape hatch.

var.clear: ## Remove api/var/{cache,log} contents (container-side, no sudo)
	$(call guard_var_writable,var.clear)
	@$(PHP_CONT) sh -c 'rm -rf var/cache/* var/log/*'
	@echo "✓ api/var/{cache,log} cleared"

var.clear.log: ## Remove only api/var/log contents (container-side, no sudo)
	$(call guard_var_writable,var.clear.log)
	@$(PHP_CONT) sh -c 'rm -f var/log/*.log'
	@echo "✓ api/var/log cleared"

var.chown: ## Reclaim ownership of api/var on the host (requires sudo)
	$(call guard_var_writable,var.chown)
	@sudo chown -R $(shell id -u):$(shell id -g) $(API_ROOT)/var
	@echo "✓ api/var now owned by $(shell id -un)"

.PHONY: composer composer.install composer.update composer.checks \
        composer.check.platform-reqs composer.check.deps composer.check.unused \
        sf cc cache.warmup routes symfony.about php.modules \
        messenger.stop-workers \
        var.clear var.clear.log var.chown
