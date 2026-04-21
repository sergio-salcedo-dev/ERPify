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

.PHONY: composer composer.install composer.update composer.checks \
        composer.check.platform-reqs composer.check.deps composer.check.unused \
        sf cc cache.warmup routes symfony.about php.modules \
        messenger.stop-workers
