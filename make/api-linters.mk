# =============================================================================
# API Linters & static analysis
# =============================================================================

# —— Container helpers ————————————————————————————————————————————————————————
PHP_TEST = cd $(ROOT_DIR) && docker compose $(COMPOSE_DEV) exec -e APP_ENV=test php
PHP = cd $(ROOT_DIR) && docker compose $(COMPOSE_DEV) exec php
COMPOSER = $(PHP) composer

## —— PHPStan ——

php.phpstan: ## PHPStan static analysis; pass c= for extra args
	@$(eval c ?=)
	$(PHP_TEST) vendor/bin/phpstan analyse --configuration api/tools/phpstan/phpstan.neon $(c)

php.phpstan.baseline: ## Generate PHPStan baseline
	$(PHP_TEST) vendor/bin/phpstan analyse --configuration api/tools/phpstan/phpstan.neon --generate-baseline api/tools/phpstan/phpstan-baseline.neon

## —— Lint suite ——

lint: php.phpstan ## Run all linters
