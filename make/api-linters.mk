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

## —— PHP Mess Detector ——

php.phpmd: ## PHPMD code smell check; pass c= for extra args
	@$(eval c ?=)
	$(PHP_TEST) vendor/bin/phpmd api/src text api/tools/phpmd/phpmd.xml $(c)

## —— PHP CS Fixer ——

php.cs.fix: ## PHP CS Fixer — check for violations (dry run)
	@$(eval c ?=--dry-run --diff)
	$(PHP) vendor/bin/php-cs-fixer fix --config=api/tools/ecs/.php-cs-fixer.dist.php $(c)

php.cs.fix.apply: ## PHP CS Fixer — apply fixes
	$(PHP) vendor/bin/php-cs-fixer fix --config=api/tools/ecs/.php-cs-fixer.dist.php --diff

## —— Rector ——

php.rector: ## Rector dry run; pass c= for extra args
	@$(eval c ?=)
	$(PHP) vendor/bin/rector process --config=api/tools/rector/rector.php --dry-run --diff $(c)

php.rector.apply: ## Rector apply fixes
	$(PHP) vendor/bin/rector process --config=api/tools/rector/rector.php --diff

## —— Lint suite ——

lint: php.phpstan php.phpmd php.cs.fix ## Run all linters
