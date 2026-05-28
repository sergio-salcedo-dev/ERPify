# =============================================================================
# Database (Doctrine migrations, fixtures, psql).
# =============================================================================

.PHONY: db.migrate db.diff db.status db.validate db.load.fixtures db.drop db.reset db.shell

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

db.drop: ## Drop DB (destructive)
	@$(SYMFONY) doctrine:schema:drop --force --full-database --no-interaction

db.reset: db.drop db.migrate db.load.fixtures ## Drop DB → migrate → fixtures (destructive)

db.shell: ## Interactive psql shell in the database container
	$(DOCKER_COMPOSE_EXEC) $(DB_SERVICE) \
		psql --username=$${POSTGRES_USER:-erpify_user} $${POSTGRES_DB:-erpify_db}
