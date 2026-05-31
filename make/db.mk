# =============================================================================
# Database (Doctrine migrations, fixtures, psql).
# =============================================================================

.PHONY: db.migrate db.diff db.status db.validate db.load.fixtures db.drop db.reset db.shell db.tunnel db.tunnel.stop

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

# —— Host DB access for GUI clients (pre-production testing) ———————————————————
# Prod/staging keep Postgres on the internal `backend` network with no published
# port (compose.prod.yaml), so a GUI client (PhpStorm/DataGrip) can't reach it
# the way `db.shell` (CLI, via `docker exec`) can. `db.tunnel` starts a throwaway
# socat sidecar that bridges the two networks: it publishes a host port on the
# host-facing `frontend` network and reaches `database` over `backend` (the
# `backend` net is `internal: true`, so a port can't be published from it
# directly). Bound to 127.0.0.1 only, so it never leaves the laptop and never
# touches the deployed stack — you do NOT run this on the VPS. Dev already
# publishes the port, so this is only needed against a local prod/staging stack.
# Default host port 15432 matches the dev stack, so one data source works for
# both; override with DB_TUNNEL_PORT=.
DB_TUNNEL_NAME    ?= $(COMPOSE_PROJECT_NAME)-db-tunnel
DB_TUNNEL_NET_PUB ?= $(COMPOSE_PROJECT_NAME)_frontend
DB_TUNNEL_NET_DB  ?= $(COMPOSE_PROJECT_NAME)_backend
DB_TUNNEL_BIND    ?= 127.0.0.1
DB_TUNNEL_PORT    ?= $(if $(POSTGRES_PORT),$(POSTGRES_PORT),15432)

db.tunnel: ## Expose the prod/staging DB on 127.0.0.1 for a GUI client (pre-prod only, localhost-bound)
	@docker network inspect $(DB_TUNNEL_NET_DB) >/dev/null 2>&1 || { \
		printf 'Network "%s" not found — is the prod/staging stack up? Try: ENV=prod make docker.up\n' '$(DB_TUNNEL_NET_DB)'; \
		exit 1; }
	@docker rm -f $(DB_TUNNEL_NAME) >/dev/null 2>&1 || true
	@docker run --rm -d --name $(DB_TUNNEL_NAME) \
		--network $(DB_TUNNEL_NET_PUB) \
		--publish $(DB_TUNNEL_BIND):$(DB_TUNNEL_PORT):5432 \
		alpine/socat tcp-listen:5432,fork,reuseaddr tcp-connect:$(DB_SERVICE):5432 >/dev/null
	@docker network connect $(DB_TUNNEL_NET_DB) $(DB_TUNNEL_NAME)
	@printf 'DB tunnel up → point your client at %s:%s\n' '$(DB_TUNNEL_BIND)' '$(DB_TUNNEL_PORT)'
	@printf '  database / user / password come from .env.prod.local (POSTGRES_DB / POSTGRES_USER / POSTGRES_PASSWORD)\n'
	@printf '  stop it with: make db.tunnel.stop\n'

db.tunnel.stop: ## Stop the db.tunnel sidecar
	@docker rm -f $(DB_TUNNEL_NAME) >/dev/null 2>&1 && echo 'DB tunnel stopped.' || echo 'No DB tunnel running.'
