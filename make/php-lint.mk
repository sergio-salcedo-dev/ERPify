# make/php-lint.mk — PHP linters and static analysis (one canonical target each).
#
# Per-tool targets are flat: php.<tool>[.<mode>].
# Aggregates:
#   php.lint      — full sweep (PHPStan + Rector + CS-Fixer + PHPMD + PHPCS + Psalm fixes)

## —— PHPStan ——————————————————————————————————————————————————————————————
php.stan: ## PHPStan analyse
	@$(PHP_TEST) vendor/bin/phpstan analyse --configuration=tools/phpstan/phpstan.neon --memory-limit=1G

php.stan.baseline: ## Regenerate PHPStan baseline
	@$(PHP_TEST) vendor/bin/phpstan analyse --configuration=tools/phpstan/phpstan.neon --generate-baseline --memory-limit=1G

## —— Rector ———————————————————————————————————————————————————————————————
php.rector: ## Rector apply
	@$(PHP) vendor/bin/rector process --config=tools/rector/rector.php

php.rector.dry-run: ## Rector dry-run
	@$(PHP) vendor/bin/rector process --config=tools/rector/rector.php --dry-run

## —— PHP-CS-Fixer —————————————————————————————————————————————————————————
# Cache location is owned by the config (`setCacheFile`) — do not pass
# `--cache-file` here or it will override the config and scatter cache files.
php.cs-fixer: ## PHP-CS-Fixer apply
	@$(PHP) vendor/bin/php-cs-fixer fix --config=tools/ecs/.php-cs-fixer.dist.php

php.cs-fixer.dry-run: ## PHP-CS-Fixer check only
	@$(PHP) vendor/bin/php-cs-fixer fix --config=tools/ecs/.php-cs-fixer.dist.php --dry-run --diff

## —— PHPMD ————————————————————————————————————————————————————————————————
php.md: ## PHPMD code smell check
	$(PHP_TEST) php -d error_reporting='E_ALL & ~E_DEPRECATED' \
		tools/phpmd/phpmd.phar \
		bin,config,src,tests,tools,public \
		ansi tools/phpmd/phpmd.xml

## —— PHPCS / PHPCBF ——————————————————————————————————————————————————————
php.cs: ## PHPCBF (apply fixes)
	@$(PHP_TEST) sh -c 'vendor/bin/phpcbf --standard=tools/phpcs/phpcs.xml src tests; s=$$?; [ $$s -le 2 ]'

php.cs.dry-run: ## PHPCS (check only)
	@$(PHP_TEST) vendor/bin/phpcs --standard=tools/phpcs/phpcs.xml src tests

## —— Psalm ————————————————————————————————————————————————————————————————
php.psalm: ## Psalm
	@$(PHP_TEST) vendor/bin/psalm --config=tools/psalm/psalm.xml

php.psalm.baseline: ## Regenerate Psalm baseline
	@$(PHP_TEST) vendor/bin/psalm --config=tools/psalm/psalm.xml --set-baseline=tools/psalm/psalm-baseline.xml

php.psalm.taint: ## Psalm taint analysis (SARIF)
	@$(PHP_TEST) vendor/bin/psalm --config=tools/psalm/psalm.xml --taint-analysis --report=psalm-taint.sarif

php.psalm.fix.cleanup: ## Psalm --alter: cleanup (unused, redundant)
	@$(PHP_TEST) vendor/bin/psalm --config=tools/psalm/psalm.xml --alter --issues=UnusedVariable,UnusedMethod,PossiblyUnusedProperty,UnnecessaryVarAnnotation

php.psalm.fix.types: ## Psalm --alter: add missing types
	@$(PHP_TEST) vendor/bin/psalm --config=tools/psalm/psalm.xml --alter --issues=MissingReturnType,MissingPropertyType

php.psalm.fix.all: php.psalm.fix.cleanup php.psalm.fix.types ## Psalm --alter: cleanup + types

## —— Gherkinlint ——————————————————————————————————————————————————————————
GHERKINLINT := cd tools/gherkinlint && php -d error_reporting='E_ALL & ~E_DEPRECATED' ../../vendor/bin/gherkinlint

php.gherkin: ## Gherkinlint
	@$(PHP_TEST) sh -c "$(GHERKINLINT) --ansi lint ../../features/"

php.gherkin.rules: ## Gherkinlint rules
	@$(PHP_TEST) sh -c "$(GHERKINLINT) rules"

## —— yaml-lint ——————————————————————————————————————————————————————————
php.lint.yaml: ## yaml-lint
	@$(PHP_TEST) bin/console lint:yaml config

## —— Doctrine schema validation ——————————————————————————————————————————
# Catches drift between the ORM mapping (entities) and the database schema:
#   - missing / extra columns and indexes
#   - wrong column types
#   - association mappings that reference unmapped classes (e.g. an interface
#     accidentally passed as `repositoryClass`)
# `--skip-sync` skips the "schema is in sync" half so a clean dev DB with no
# pending migrations still surfaces mapping-only issues; the migrations themselves
# are the source of truth for schema sync, gated by `make db.status`.
php.lint.doctrine: ## Doctrine ORM mapping validation
	@$(PHP_TEST) bin/console doctrine:schema:validate --skip-sync --no-interaction

## —— Error-contract drift gate ————————————————————————————————————————————
# Fails CI if a controller catches and
# responds with `new JsonResponse(...)` (skipping `api/.error-contract-allowlist`),
# or if a new file under `api/src/Shared/Domain/Exception/` is added without
# updating `docs/api-error-contract.md` in the same diff.
php.lint.error-contract: ## Error-contract drift gate
	@$(PHP_TEST) bin/phpunit --filter=ErrorContractGateTest

## —— Aggregates ——————————————————————————————————————————————————————————
#php.lint: php.stan php.rector php.cs-fixer php.md php.cs php.psalm.fix.all php.gherkin ## Full PHP lint sweep
php.lint: php.stan php.rector php.cs-fixer php.cs php.psalm.fix.all php.gherkin php.lint.doctrine php.lint.error-contract ## Full PHP lint sweep

.PHONY: php.stan php.stan.baseline \
        php.rector php.rector.dry-run \
        php.cs-fixer php.cs-fixer.dry-run \
        php.md php.cs php.cs.dry-run \
        php.psalm php.psalm.baseline php.psalm.taint \
        php.psalm.fix.cleanup php.psalm.fix.types php.psalm.fix.all \
        php.gherkin \
        php.lint.doctrine \
        php.lint.error-contract \
        php.lint
