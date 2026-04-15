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

lint: php.phpstan php.psalm ## Run all linters

## —— Psalm ——

php.psalm: ## Psalm static analysis; pass c= for extra args
	@$(eval c ?=)
	$(PHP_TEST) vendor/bin/psalm --config=api/tools/psalm/psalm.xml $(c)

php.psalm.baseline: ## Generate Psalm baseline
	$(PHP_TEST) vendor/bin/psalm --config=api/tools/psalm/psalm.xml --set-baseline=api/tools/psalm/psalm-baseline.xml

## —— composer-unused ——

php.composer-unused: ## Check for unused Composer packages; pass c= for extra args
	@$(eval c ?=)
	$(PHP) vendor/bin/composer-unused $(c)
