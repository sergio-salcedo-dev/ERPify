# =============================================================================
# PHP
# =============================================================================

.PHONY: php.check.modules symfony.about

php.check.modules: ## Check PHP modules
	$(PHP_CONT) php -m

symfony.about: ## Show Symfony about
	$(PHP_CONT) php bin/console about


## —— Composer ——

composer: ## Run composer; pass c='…', e.g. make composer c='req vendor/pkg'
	@$(eval c ?=)
	$(DOCKER_EXEC_PHP) composer $(c)

composer.install: ## composer install (prod-ish flags)
	$(DOCKER_EXEC_PHP) composer install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction

composer.update: ## composer update
	$(DOCKER_EXEC_PHP) composer update

## —— Symfony Console ——

sf: ## Symfony console; pass c=…, e.g. make sf c=about
	@$(eval c ?=)
	$(DOCKER_EXEC_PHP) php bin/console $(c)

cc: ## Cache clear
	@$(DOCKER_EXEC_PHP) php bin/console cache:clear

cache.warmup: ## Warm up cache
	@$(DOCKER_EXEC_PHP) php bin/console cache:warmup

routes: ## Debug routes (pass f= to filter)
	@$(eval f ?=)
	$(DOCKER_EXEC_PHP) php bin/console debug:router $(if $(f),| grep $(f),)

## —— Database ——

db.migrate: ## Run pending Doctrine migrations
	@$(DOCKER_EXEC_PHP) php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing

db.diff: ## Generate migration from entity/schema diff
	@$(DOCKER_EXEC_PHP) php bin/console doctrine:migrations:diff

db.status: ## Migration status
	@$(DOCKER_EXEC_PHP) php bin/console doctrine:migrations:status

db.validate: ## Validate ORM mapping vs database
	@$(DOCKER_EXEC_PHP) php bin/console doctrine:schema:validate

db.load.fixtures: ## Load Hautelook Alice fixtures (purge first)
	@$(DOCKER_EXEC_PHP) php bin/console hautelook:fixtures:load --no-interaction --purge-with-truncate

db.reset: ## Drop DB → migrate → fixtures (destructive)
	@$(DOCKER_EXEC_PHP) php bin/console doctrine:schema:drop --force --full-database --no-interaction
	@$(DOCKER_EXEC_PHP) php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
	@$(DOCKER_EXEC_PHP) php bin/console hautelook:fixtures:load --no-interaction --purge-with-truncate

db.shell: ## Interactive psql in database container
	$(DOCKER_COMPOSE) $(DOCKER_COMPOSE_ENV) exec $(DB_SERVICE) \
		psql --username=$${POSTGRES_USER:-erpify_user} $${POSTGRES_DB:-erpify_db}

## —— Messenger ——

messenger.stop-workers: ## Stop all messenger workers
	@$(DOCKER_EXEC_PHP) php bin/console messenger:stop-workers

.PHONY: composer composer.install composer.update sf cc cache.warmup routes \
        db.migrate db.diff db.status db.validate db.load.fixtures db.reset db.shell \
        messenger.stop-workers
