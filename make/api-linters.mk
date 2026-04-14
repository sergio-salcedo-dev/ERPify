# =============================================================================
# API Linters & static analysis
# =============================================================================
# NOTE: This file is included from the root Makefile and relies on its variables:
#   ROOT_DIR, COMPOSE_DEV, PHP_TEST, PHP, COMPOSER
# Do not call this file directly with -f; use: make php.stan

## —— PHPStan ——

php.stan: ## PHPStan static analysis; pass c= for extra args
	@$(eval c ?=)
	$(PHP_TEST) vendor/bin/phpstan analyse --configuration tools/phpstan/phpstan.neon --memory-limit 512M $(c)

php.stan.baseline: ## Generate PHPStan baseline
	$(PHP_TEST) vendor/bin/phpstan analyse --configuration tools/phpstan/phpstan.neon --generate-baseline tools/phpstan/phpstan-baseline.neon

## —— Rector ——

php.rector: ## Rector dry run; pass c= for extra args
	@$(eval c ?=)
	$(PHP) vendor/bin/rector process --config=api/tools/rector/rector.php --dry-run --diff $(c)

php.rector.apply: ## Rector apply fixes
	$(PHP) vendor/bin/rector process --config=api/tools/rector/rector.php --diff

## —— Lint suite ——

lint: php.phpstan php.phpmd php.cs.fix ## Run all linters

