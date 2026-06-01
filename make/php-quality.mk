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
PSALM_TAINT_CONFIG = tools/psalm/psalm-taint.xml
PSALM_BASELINE = tools/psalm/psalm-baseline.xml
PSALM_BIN = vendor/bin/psalm

# Todo add to CLEANUP_ISSUES ,PossiblyUnusedMethod,ClassMustBeFinal,MissingParamType
# Cleanup issues compatible with --alter
#####CLEANUP_ISSUES = MissingOverrideAttribute,RedundantCast,RedundantCastGivenDocblockType,UnusedMethod,UnusedVariable,PossiblyUnusedProperty,UnnecessaryVarAnnotation
# NB: `MissingOverrideAttribute` is intentionally excluded. Rector's
# `NoSetupWithParentCallOverrideRector` (phpunitCodeQuality set) STRIPS `#[Override]`
# from test setUp()/tearDown() that call parent::, while psalm --alter would re-ADD it
# — a tug-of-war between the two analysers (same class of conflict as MissingParamType
# below). Excluding it lets rector win; the residual `MissingOverrideAttribute` psalm
# still reports on those methods is frozen in psalm-baseline.xml.
CLEANUP_ISSUES = UnusedVariable,UnusedMethod,PossiblyUnusedProperty,UnnecessaryVarAnnotation
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

# --config resolves from cwd (api/), so PSALM_* configs carry the tools/psalm/ prefix.
# --set-baseline, however, resolves relative to the config-file dir (resolveFromConfigFile=true),
# i.e. tools/psalm/ — so it needs the bare filename. We keep PSALM_BASELINE in the same
# tools/psalm/ form as the configs and strip the dir with $(notdir) only here, matching
# errorBaseline in psalm.xml. (Passing the full path would write tools/psalm/tools/psalm/….)
php.psalm.baseline: ## Generate or update the error baseline (run after fixing psalm issues)
	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_CONFIG) --set-baseline=$(notdir $(PSALM_BASELINE))

# Uses a baseline-free config (PSALM_TAINT_CONFIG) so taint mode does not flag every
# regular baseline entry as UnusedBaselineEntry — see tools/psalm/psalm-taint.xml.
php.psalm.taint: ## Psalm taint analysis (SARIF)
	$(PHP_TEST) $(PSALM_BIN) --config=$(PSALM_TAINT_CONFIG) --taint-analysis --report=psalm-taint.sarif

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

php.quality: php.stan php.rector php.cs-fixer php.md php.cs php.psalm.fix.all php.gherkin php.lint.doctrine php.lint.error-contract ## Full PHP lint sweep

# Check-only sweep for CI / pre-push: the read-only subset of php.quality that is
# currently green, fanned out in parallel. Two wins over php.quality:
#   1. Gating — php.quality runs the fixers in APPLY mode (rector process,
#      cs-fixer fix, phpcbf, psalm --alter), so in an ephemeral CI container it
#      auto-fixes drift and exits 0; these check variants FAIL on drift instead.
#   2. Parallel-safe — every prerequisite here is read-only (no src/ writes), so
#      CI can fan them out with `make -j --output-sync=target` without racing.
# php.lint.doctrine + php.lint.error-contract still need the running stack
# (DB + console), which CI already has up from `docker.up.wait.no-build.api`.
#
# phpcs IS gated here via php.cs.dry-run: its line-length backlog was cleaned up
# (all lines ≤120), so plain `phpcs` now FAILS on any new >120 (warning) / >160
# (error) line instead of being masked by phpcbf's `exit ≤2` tolerance.
#
# psalm IS gated here too via php.psalm: its ~492-issue backlog at errorLevel=3
# (findUnusedCode=true) is frozen in tools/psalm/psalm-baseline.xml, wired through
# `errorBaseline` in psalm.xml. Plain `psalm` reports 0 errors today and FAILS on
# any NEW regression — no longer masked by `psalm --alter` (auto-fix-and-discard).
# Because findUnusedBaselineEntry=true, FIXING a baselined issue turns the gate red
# until you regenerate (`make php.psalm.baseline`) and commit the smaller baseline —
# by design, so the backlog only ever shrinks. History: issue #97.
php.quality.dry-run: php.stan php.psalm php.rector.dry-run php.cs-fixer.dry-run php.md php.cs.dry-run php.gherkin php.lint.doctrine php.lint.error-contract ## Check-only PHP lint sweep (CI; read-only, parallel-safe)

.PHONY: php.stan php.stan.baseline \
        php.rector php.rector.dry-run \
        php.cs-fixer php.cs-fixer.dry-run \
        php.md php.cs php.cs.dry-run \
        php.psalm php.psalm.baseline php.psalm.taint \
        php.psalm.fix.cleanup php.psalm.fix.types php.psalm.fix.all \
        php.gherkin php.gherkin.rules \
        php.lint.doctrine php.lint.yaml \
        php.lint.error-contract \
        php.quality php.quality.dry-run
