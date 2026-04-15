# =============================================================================
# PHP — Linters & Static Analysis
# =============================================================================

## —— PHPStan ——

php.lint.phpstan: ## Run PHPStan; pass c= for extra args
	@$(eval c ?=)
	$(DOCKER_EXEC_PHP_TEST) vendor/bin/phpstan analyse --configuration api/tools/phpstan/phpstan.neon $(c)

php.lint.phpstan.baseline: ## Generate PHPStan baseline
	$(DOCKER_EXEC_PHP_TEST) vendor/bin/phpstan analyse \
		--configuration api/tools/phpstan/phpstan.neon \
		--generate-baseline api/tools/phpstan/phpstan-baseline.neon

## —— Psalm ——

php.lint.psalm: ## Run Psalm; pass c= for extra args
	@$(eval c ?=)
	$(DOCKER_EXEC_PHP_TEST) vendor/bin/psalm --config=api/tools/psalm/psalm.xml $(c)

php.lint.psalm.baseline: ## Generate Psalm baseline
	$(DOCKER_EXEC_PHP_TEST) vendor/bin/psalm \
		--config=api/tools/psalm/psalm.xml \
		--set-baseline=api/tools/psalm/psalm-baseline.xml

## —— composer-unused ——

php.lint.composer-unused: ## Check for unused Composer packages
	$(DOCKER_EXEC_PHP) vendor/bin/composer-unused

## —— Aggregate ——

php.lint: php.lint.phpstan php.lint.psalm ## Run all PHP linters

.PHONY: php.lint.phpstan php.lint.phpstan.baseline php.lint.psalm php.lint.psalm.baseline \
        php.lint.composer-unused php.lint
