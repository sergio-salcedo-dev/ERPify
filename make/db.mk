# make/db.mk — Database (Doctrine migrations, fixtures, psql).

## —— Database ——

db.migrate: ## Run pending Doctrine migrations
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction --all-or-nothing

db.diff: ## Generate migration from entity/schema diff
	@$(SYMFONY) doctrine:migrations:diff

db.status: ## Migration status
	@$(SYMFONY) doctrine:migrations:status

db.validate: ## Validate ORM mapping against the database
	@$(SYMFONY) doctrine:schema:validate

db.load.fixtures: ## Load Hautelook Alice fixtures (purge first)
	@$(SYMFONY) hautelook:fixtures:load --no-interaction --purge-with-truncate

db.reset: ## Drop DB → migrate → fixtures (destructive)
	@$(SYMFONY) doctrine:schema:drop --force --full-database --no-interaction
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction --all-or-nothing
	@$(SYMFONY) hautelook:fixtures:load --no-interaction --purge-with-truncate

db.shell: ## Interactive psql shell in the database container
	$(DC) exec $(DB_SERVICE) \
		psql --username=$${POSTGRES_USER:-erpify_user} $${POSTGRES_DB:-erpify_db}

.PHONY: db.migrate db.diff db.status db.validate db.load.fixtures db.reset db.shell
