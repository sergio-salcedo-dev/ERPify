# =============================================================================
# Database (Doctrine migrations, fixtures, psql).
# =============================================================================

.PHONY: db.migrate db.diff db.status db.validate db.load.fixtures db.drop db.reset db.test.prepare db.test.reset db.test.shell db.shell db.tunnel db.tunnel.stop

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

# Creates and migrates the `APP_ENV=test` database — `<dbname>_test`, per the dbname_suffix in
# config/packages/test/doctrine.yaml. A prerequisite of the PHPUnit targets rather than a step a developer is
# expected to remember: WebTestCase tests build their own rows but nothing in the PHPUnit lane creates the
# schema, so without this the first functional test dies on a database that does not exist yet.
#
# Behat is not wired to it because FixturesContext already runs both commands itself before the first
# scenario, and it prepares a different database — that lane sets TEST_TOKEN=_behat, so the two hold one each.
#
# The guard runs first, and it has to: this target is a PREREQUISITE of the PHPUnit targets, so make runs it
# before `bin/phpunit` ever loads the bootstrap that checks. Its second command is a migrate, so with the
# suffix broken a feature branch's new migration would land on the runtime database — a committed schema
# change — and the suite would refuse only afterwards.
db.test.prepare: ## Create + migrate the test database (<dbname>_test); idempotent
	$(call guard_var_writable,db.test.prepare)
	@$(PHP_TEST) php tools/phpunit/assert-test-database.php
	@$(PHP_TEST) bin/console doctrine:database:create --if-not-exists --no-interaction
	@$(PHP_TEST) bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration

# The destructive counterpart. `db.drop`/`db.reset` run under APP_ENV=dev and therefore cannot reach a test
# database at all, which leaves one state unrecoverable without this: editing a migration already applied
# there — permitted on a feature branch — leaves `--allow-no-migration` reporting nothing to do against a
# schema that no longer matches the file.
#
# Swept by prefix rather than by naming the two lanes, because the set is open: PHPUnit holds `<dbname>_test`,
# Behat holds `<dbname>_test_behat` plus the `_behat_backup` clone FixturesContext leaves behind, and a
# ParaTest token would add one per process. `bin/console doctrine:database:drop` cannot do it — the lane
# suffix comes from each lane's own bootstrap, not from the console.
#
# The subshell around the exec is load-bearing: DOCKER_COMPOSE_EXEC expands to `cd <dir> && docker …`, so
# without it the pipe feeds `cd`, psql reads nothing, and the target drops nothing while exiting 0 —
# measured. Grouping makes stdin reach the exec.
#
# `\gexec` runs the generated statements inside the same psql, so there is no second process whose failure a
# pipeline would swallow, and `ON_ERROR_STOP=1` makes a failed DROP a non-zero exit instead of a silent
# survivor — a reset that dropped nothing while reporting success is the exact shape this target exists to
# rule out. The database name is bound with `-v` and read as `:'db'`, which psql quotes as a literal, so no
# value is interpolated into SQL. `starts_with` rather than LIKE keeps the `_` in `_test` literal instead of
# a single-character wildcard, and `%I` quotes each identifier; the prefix contains `_test`, so the runtime
# database can never be in the result set.
#
# The name comes from POSTGRES_DB as make's shell sees it, which is the same source `db.shell` has always
# used — not from DATABASE_URL. A per-developer override in api/.env.local or api/.env.test.local moves the
# suite's database without moving this, and the sweep then matches nothing. Container-only, like `db.shell`.
db.test.reset: ## Drop every <dbname>_test* database (both lanes + clones), then recreate the PHPUnit one (destructive; test DBs only)
	$(call guard_var_writable,db.test.reset)
	@printf '%s\n' \
		"select format('DROP DATABASE IF EXISTS %I WITH (FORCE);', datname) from pg_database where starts_with(datname, :'db' || '_test')" \
		"\\gexec" \
		| ( $(DOCKER_COMPOSE_EXEC) -T $(DB_SERVICE) \
			psql -v ON_ERROR_STOP=1 -v db=$${POSTGRES_DB:-erpify_db} \
			--username=$${POSTGRES_USER:-erpify_user} --dbname=postgres --quiet -f - )
	@$(MAKE) db.test.prepare

# psql against the PHPUnit lane's database. `db.shell` opens the runtime one, so without this there is no
# way to inspect what a failing functional test actually wrote.
db.test.shell: ## Interactive psql shell in the test database
	$(DOCKER_COMPOSE_EXEC) $(DB_SERVICE) \
		psql --username=$${POSTGRES_USER:-erpify_user} $${POSTGRES_DB:-erpify_db}_test

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
