# make/php-lint.mk — PHP linters and static analysis (one canonical target each).
#
# Per-tool targets are flat: php.<tool>[.<mode>].
# Aggregates:
#   php.lint      — full sweep (PHPStan + Rector + CS-Fixer + PHPMD + PHPCS + Psalm fixes)
#   ci.php.lint   — CI fast path (skip PHPStan for speed; CI runs it separately if desired)

## —— PHPStan ——————————————————————————————————————————————————————————————
php.stan: ## PHPStan analyse
	@$(PHP_TEST) tools/phpstan/vendor/bin/phpstan analyse --configuration=tools/phpstan/phpstan.neon

php.stan.baseline: ## Regenerate PHPStan baseline
	@$(PHP_TEST) tools/phpstan/vendor/bin/phpstan analyse --configuration=tools/phpstan/phpstan.neon --generate-baseline

## —— Rector ———————————————————————————————————————————————————————————————
php.rector: ## Rector apply
	@$(PHP) tools/rector/vendor/bin/rector process

php.rector.dry-run: ## Rector dry-run
	@$(PHP) tools/rector/vendor/bin/rector process --dry-run

## —— PHP-CS-Fixer —————————————————————————————————————————————————————————
php.cs-fixer: ## PHP-CS-Fixer apply
	@$(PHP) tools/php-cs-fixer/vendor/bin/php-cs-fixer fix --config=tools/php-cs-fixer/.php-cs-fixer.dist.php

php.cs-fixer.dry-run: ## PHP-CS-Fixer check only
	@$(PHP) tools/php-cs-fixer/vendor/bin/php-cs-fixer fix --config=tools/php-cs-fixer/.php-cs-fixer.dist.php --dry-run --diff

## —— PHPMD ————————————————————————————————————————————————————————————————
php.md: ## PHPMD
	@$(PHP_TEST) tools/phpmd/phpmd.phar src ansi tools/phpmd/phpmd.xml

## —— PHPCS / PHPCBF ——————————————————————————————————————————————————————
php.cs: ## PHPCBF (apply fixes)
	@$(PHP_TEST) tools/phpcs/vendor/bin/phpcbf --standard=tools/phpcs/phpcs.xml src tests

php.cs.dry-run: ## PHPCS (check only)
	@$(PHP_TEST) tools/phpcs/vendor/bin/phpcs --standard=tools/phpcs/phpcs.xml src tests

## —— Psalm ————————————————————————————————————————————————————————————————
php.psalm: ## Psalm
	@$(PHP_TEST) tools/psalm/vendor/bin/psalm --config=tools/psalm/psalm.xml

php.psalm.baseline: ## Regenerate Psalm baseline
	@$(PHP_TEST) tools/psalm/vendor/bin/psalm --config=tools/psalm/psalm.xml --set-baseline=tools/psalm/psalm-baseline.xml

php.psalm.taint: ## Psalm taint analysis (SARIF)
	@$(PHP_TEST) tools/psalm/vendor/bin/psalm --config=tools/psalm/psalm.xml --taint-analysis --report=psalm-taint.sarif

php.psalm.fix.cleanup: ## Psalm --alter: cleanup (unused, redundant)
	@$(PHP_TEST) tools/psalm/vendor/bin/psalm --config=tools/psalm/psalm.xml --alter --issues=UnusedVariable,UnusedMethod,PossiblyUnusedProperty,UnnecessaryVarAnnotation

php.psalm.fix.types: ## Psalm --alter: add missing types
	@$(PHP_TEST) tools/psalm/vendor/bin/psalm --config=tools/psalm/psalm.xml --alter --issues=MissingReturnType,MissingParamType,MissingPropertyType

php.psalm.fix.all: php.psalm.fix.cleanup php.psalm.fix.types ## Psalm --alter: cleanup + types

## —— Aggregates ——————————————————————————————————————————————————————————
php.lint: php.stan php.rector php.cs-fixer php.md php.cs php.psalm.fix.all ## Full PHP lint sweep

ci.php.lint: php.rector php.cs-fixer php.md php.cs php.psalm.fix.all ## CI-fast lint (skips PHPStan)

.PHONY: php.stan php.stan.baseline \
        php.rector php.rector.dry-run \
        php.cs-fixer php.cs-fixer.dry-run \
        php.md php.cs php.cs.dry-run \
        php.psalm php.psalm.baseline php.psalm.taint \
        php.psalm.fix.cleanup php.psalm.fix.types php.psalm.fix.all \
        php.lint ci.php.lint
