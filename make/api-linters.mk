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

php.rector.dry-run: ## Rector dry run; pass c= for extra args
	@$(eval c ?=)
	$(PHP) vendor/bin/rector process --config=tools/rector/rector.php --dry-run $(c)

php.rector.apply: ## Rector apply fixes
	$(PHP) vendor/bin/rector process --config=tools/rector/rector.php

## —— PHP CS Fixer ——

php.csfixer.dry-run: ## PHP CS Fixer — check for violations (dry run)
	@$(eval c ?=--dry-run --diff)
	$(PHP) vendor/bin/php-cs-fixer fix --config=api/tools/ecs/.php-cs-fixer.dist.php $(c)

php.csfixer.apply: ## PHP CS Fixer — apply fixes
	$(PHP) vendor/bin/php-cs-fixer fix --config=api/tools/ecs/.php-cs-fixer.dist.php --diff

## —— Lint suite ——

lint: php.stan php.rector.apply php.csfixer.apply ## Run all linters
