# =============================================================================
# PHP linters and static analysis (one canonical target each).
# =============================================================================

# Per-tool targets are flat: php.<tool>[.<mode>].

## —— PHPStan ——————————————————————————————————————————————————————————————
php.stan: ## PHPStan analyse; pass c= for extra args
	@$(PHP_TEST) vendor/bin/phpstan analyse --configuration=tools/phpstan/phpstan.neon --memory-limit=1G $(c)

php.stan.baseline: ## Regenerate PHPStan baseline
	@$(PHP_TEST) vendor/bin/phpstan analyse --configuration=tools/phpstan/phpstan.neon --generate-baseline --memory-limit=1G

## —— Rector ———————————————————————————————————————————————————————————————
php.rector: ## Rector apply; pass c= for extra args
	@$(PHP) vendor/bin/rector process --config=tools/rector/rector.php  $(c)

php.rector.dry-run: ## Rector dry-run; pass c= for extra args
	@$(PHP) vendor/bin/rector process --config=tools/rector/rector.php --dry-run  $(c)

## —— PHP-CS-Fixer —————————————————————————————————————————————————————————

# Cache location is owned by the config (`setCacheFile`) — do not pass
# `--cache-file` here or it will override the config and scatter cache files.
php.cs-fixer: ## PHP-CS-Fixer apply; pass c= for extra args
	@$(PHP) vendor/bin/php-cs-fixer fix --config=tools/ecs/.php-cs-fixer.dist.php $(c)

php.cs-fixer.dry-run: ## PHP-CS-Fixer check only; pass c= for extra args
	@$(PHP) vendor/bin/php-cs-fixer fix --config=tools/ecs/.php-cs-fixer.dist.php --dry-run --diff $(c)

## —— PHP Mess Detector ————————————————————————————————————————————————————————————————

php.md: ## PHPMD code smell check; pass c= for extra args
	$(PHP_TEST) php -d error_reporting='E_ALL & ~E_DEPRECATED' \
		tools/phpmd/phpmd.phar \
		bin,config,src,tests,tools,public \
		ansi tools/phpmd/phpmd.xml $(c)

## —— PHP Code Sniffer (PHPCS / PHPCBF) ——————————————————————————————————————————————————————

php.cs: ## PHPCBF (apply fixes) ; pass c= for extra args
	@$(PHP_TEST) sh -c 'vendor/bin/phpcbf --standard=tools/phpcs/phpcs.xml $(c) src tests; s=$$?; [ $$s -le 2 ]'

php.cs.dry-run: ## PHPCS (check only); pass c= for extra args
	@$(PHP_TEST) vendor/bin/phpcs --standard=tools/phpcs/phpcs.xml src tests $(c)

## —— Psalm ——

PSALM_CONFIG = tools/psalm/psalm.xml
PSALM_BIN = vendor/bin/psalm

# Todo add to CLEANUP_ISSUES ,PossiblyUnusedMethod,ClassMustBeFinal,MissingParamType
# Cleanup issues compatible with --alter
#####CLEANUP_ISSUES = MissingOverrideAttribute,RedundantCast,RedundantCastGivenDocblockType,UnusedMethod,UnusedVariable,PossiblyUnusedProperty,UnnecessaryVarAnnotation
CLEANUP_ISSUES = MissingOverrideAttribute,UnusedVariable,UnusedMethod,PossiblyUnusedProperty,UnnecessaryVarAnnotation
# Typing issues compatible with --alter (Psalm will inject types based on its inference)
#####TYPE_ISSUES = MissingParamType,MissingPropertyType,MissingReturnType,MissingClosureReturnType,InvalidReturnType,InvalidNullableReturnType,InvalidFalsableReturnType,MismatchingDocblockParamType
# NB: `MissingParamType` is intentionally excluded. It is suppressed in psalm.xml,
# and forcing it via --alter narrows explicit `mixed` params to an inferred type
# (e.g. `array`), which then breaks PHPStan at call sites that legitimately pass
# scalars/objects — a tug-of-war between the two analysers on every `php.quality`
TYPE_ISSUES = MissingReturnType,MissingPropertyType

php.psalm: ## Run standard static analysis
	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG)

php.psalm.fix.cleanup: ## Fix safe redundancies and dead code
	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --alter --issues=$(CLEANUP_ISSUES) --no-cache

php.psalm.fix.types: ## Infer and inject missing types (Review changes carefully!)
	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --alter --issues=$(TYPE_ISSUES) --no-cache

php.psalm.fix.all: ## Run all supported auto-fixes (cleanup + types)
	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --alter --issues=$(CLEANUP_ISSUES),$(TYPE_ISSUES) --no-cache

php.psalm.baseline: ## Generate or update the error baseline
	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --set-baseline=api/tools/psalm/psalm-baseline.xml

php.psalm.taint: ## Psalm taint analysis (SARIF)
	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --taint-analysis --report=psalm-taint.sarif

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

# todo add php.md
#php.quality: php.stan php.rector php.cs-fixer php.md php.cs php.psalm.fix.all php.gherkin ## Full PHP lint sweep
php.quality: php.stan php.rector php.cs-fixer php.cs php.psalm.fix.all php.gherkin php.lint.doctrine php.lint.error-contract ## Full PHP lint sweep

.PHONY: php.stan php.stan.baseline \
        php.rector php.rector.dry-run \
        php.cs-fixer php.cs-fixer.dry-run \
        php.md php.cs php.cs.dry-run \
        php.psalm php.psalm.baseline php.psalm.taint \
        php.psalm.fix.cleanup php.psalm.fix.types php.psalm.fix.all \
        php.gherkin php.gherkin.rules \
        php.lint.doctrine php.lint.yaml \
        php.lint.error-contract \
        php.quality
