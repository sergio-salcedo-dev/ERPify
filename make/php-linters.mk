# =============================================================================
# API Linters & static analysis
# DEPRECATED
# =============================================================================
# NOTE: This file is included from the root Makefile and relies on its variables:
#   ROOT_DIR, COMPOSE_DEV, PHP_TEST, PHP, COMPOSER
# Do not call this file directly with -f; use: make php.stan

## —— PHPStan ——
#
#php.stan: ## PHPStan static analysis; pass c= for extra args
#	@$(eval c ?=)
#	$(PHP_TEST) vendor/bin/phpstan analyse --configuration tools/phpstan/phpstan.neon --memory-limit 512M $(c)
#
#php.stan.baseline: ## Generate PHPStan baseline
#	$(PHP_TEST) vendor/bin/phpstan analyse --configuration tools/phpstan/phpstan.neon --generate-baseline tools/phpstan/phpstan-baseline.neon
#
### —— Rector ——
#
#php.rector.dry-run: ## Rector dry run; pass c= for extra args
#	@$(eval c ?=)
#	$(PHP) vendor/bin/rector process --config=tools/rector/rector.php --dry-run $(c)
#
#php.rector: ## Rector apply fixes
#	$(PHP) vendor/bin/rector process --config=tools/rector/rector.php
#
### —— PHP CS Fixer ——
#
#php.cs-fixer.dry-run: ## PHP CS Fixer — check for violations (dry run)
#	@$(eval c ?=)
#	$(PHP) vendor/bin/php-cs-fixer fix --config=tools/ecs/.php-cs-fixer.dist.php --dry-run --diff $(c)
#
#php.cs-fixer: ## PHP CS Fixer — apply fixes
#	$(PHP) vendor/bin/php-cs-fixer fix --config=tools/ecs/.php-cs-fixer.dist.php --diff
#
### —— PHP Mess Detector ——
#
#php.md: ## PHPMD code smell check; pass c= for extra args (e.g. make php.phpmd c='src/ xml cleancode,codesize,unusedcode')
#	@$(eval c ?=)
##	$(PHP_TEST) php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/phpmd api/bin,api/config,api/src,api/tests,api/tools,api/public text tools/phpmd/phpmd.xml $(c)
#	$(PHP_TEST) php -d error_reporting='E_ALL & ~E_DEPRECATED' \
#		tools/phpmd/phpmd.phar \
#		bin,config,src,tests,tools,public \
#		text tools/phpmd/phpmd.xml $(c)
#
### —— PHP Code Sniffer ——
#
#php.cs.dry-run: ## PHPCS coding standard check; pass c= for extra args
#	@$(eval c ?=)
#	$(PHP_TEST) vendor/bin/phpcs --standard=tools/phpcs/phpcs.xml $(c)
#
#php.cs: ## PHPCBF automatic coding standard fix; pass c= for extra args
#	@$(eval c ?=)
#	$(PHP_TEST) vendor/bin/phpcbf --standard=tools/phpcs/phpcs.xml $(c)
#
### —— Psalm ——
#
#PSALM_CONFIG = tools/psalm/psalm.xml
#PSALM_BIN = vendor/bin/psalm
#
## Cleanup issues compatible with --alter
#CLEANUP_ISSUES = MissingOverrideAttribute,RedundantCast,RedundantCastGivenDocblockType,UnusedMethod,UnusedVariable
## Typing issues compatible with --alter (Psalm will inject types based on its inference)
#TYPE_ISSUES = MissingParamType,MissingPropertyType,MissingReturnType,MissingClosureReturnType,InvalidReturnType,InvalidNullableReturnType,InvalidFalsableReturnType,MismatchingDocblockParamType
#
#php.psalm: ## Run standard static analysis
#	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG)
#
#php.psalm.fix.cleanup: ## Fix safe redundancies and dead code
#	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --alter --issues=$(CLEANUP_ISSUES) --no-cache
#
#php.psalm.fix.types: ## Infer and inject missing types (Review changes carefully!)
#	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --alter --issues=$(TYPE_ISSUES) --no-cache
#
#php.psalm.fix.all: ## Run all supported auto-fixes
#	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --alter --issues=$(CLEANUP_ISSUES),$(TYPE_ISSUES) --no-cache
#
#php.psalm.baseline: ## Generate or update the error baseline
#	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --set-baseline=api/tools/psalm/psalm-baseline.xml
