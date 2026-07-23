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

## —— Bounded-context isolation gate ————————————————————————————————————————

# Fails CI (Level 1) when a business context imports another context's
# `Domain\`/`Application\` namespace — injecting a foreign repository or knowing
# its domain internals (skipping `api/.bounded-context-allowlist`). Cross-context
# Doctrine FKs (Level 2) are printed to STDERR as warnings, never blocking.
# Rule: docs/rules/database.md#bounded-context-data-isolation-modular-monolith.
php.lint.bounded-context: ## Bounded-context isolation gate
	@$(PHP_TEST) bin/phpunit --filter=BoundedContextGateTest

## —— Event-dispatch boundary gate ——————————————————————————————————————————

# Fails CI when a file under */Application/ imports Symfony\Component\Messenger\MessageBusInterface
# directly instead of publishing domain events through the Erpify\Shared\Event\Domain\EventBus
# port (skipping api/.event-dispatch-allowlist). ADR: docs/adr/event-driven-architecture.md.
php.lint.event-bus: ## Event-dispatch boundary gate
	@$(PHP_TEST) bin/phpunit --filter=EventDispatchGateTest

## —— Person-resource erasure gate ——————————————————————————————————————————

# Fails CI when an audit `resource_type` reaches the code without being classified in
# api/.audit-resource-types as person-denoting or not, or when a type declared `person` names an
# erasure use case that does not wire AuditResourceAnonymiser. GDPR erasure of the resource axis is
# the owning context's job (docs/adr/audit-activity-log.md D4), so the classification must be a
# declared decision rather than something a new type can skip silently.
php.lint.audit-resource: ## Person-resource erasure classification gate
	@$(PHP_TEST) bin/phpunit --filter=PersonResourceErasureGateTest

## —— Deptrac (architectural boundaries) ————————————————————————————————————

# Static, AST-aware gate over api/src enforcing three concerns in one ruleset
# (tools/deptrac/deptrac.yaml): hexagonal layering (Infrastructure -> Application
# -> Domain), bounded-context isolation (defence-in-depth alongside the
# auto-discovering php.lint.bounded-context), and the Domain/Application external-
# dependency allowlist (issue #301 / ADR external-dependencies-in-domain). Analyse
# is read-only, so it is safe in the parallel php.quality.dry-run fan-out. The cache
# lives under var/cache so it never litters the api root.
#
# --fail-on-uncovered: a dependency whose target matches no layer (a vendor not in
# the allowlist) fails the gate instead of passing as a silent "uncovered" count.
# Every framework an inner/Infrastructure layer touches must be modelled as a
# Vendor.* layer and explicitly permitted — so adding a new third-party dependency
# is a conscious, reviewable change, not an invisible leak.
DEPTRAC = vendor/bin/deptrac --config-file=tools/deptrac/deptrac.yaml --cache-file=var/cache/.deptrac.cache --no-progress

php.deptrac: ## Deptrac architecture gate (layering + bounded-context + dep allowlist); pass c= for extra args
	@$(PHP_TEST) $(DEPTRAC) analyse --fail-on-uncovered $(c)

# Regenerates the grandfathered inner-layer dependency baseline. The wrapper script
# strips the published cross-context seams that deptrac's baseline formatter re-dumps
# (they stay single-sourced in skip_violations in deptrac.yaml) and re-prepends the
# header — see tools/deptrac/regen-baseline.sh.
php.deptrac.baseline: ## Regenerate the deptrac baseline (grandfathered inner-layer deps; seams stripped)
	@$(PHP_TEST) sh tools/deptrac/regen-baseline.sh

## —— Aggregates ——————————————————————————————————————————————————————————

# `php.cs.dry-run` is appended LAST (after every mutating fixer) on purpose:
# `php.cs` runs phpcbf in apply mode with an `exit ≤2` tolerance, and phpcbf has
# NO fixer for the line-length sniff — so an over-120/over-160 line is silently
# masked here and only fails later in CI's `php.quality.dry-run`. Re-running the
# strict, read-only `php.cs.dry-run` at the end makes `make php.quality` FAIL on
# that drift locally, so it is caught before commit/push instead of on CI. History: long-line drift slipped through on the keyset PR.
php.quality: php.stan php.rector php.cs-fixer php.md php.cs php.gherkin php.lint.doctrine php.lint.error-contract php.lint.bounded-context php.lint.event-bus php.lint.audit-resource php.deptrac php.cs.dry-run ## Full PHP lint sweep

# Check-only sweep for CI / pre-push: the read-only subset of php.quality that is
# currently green, fanned out in parallel. Two wins over php.quality:
#   1. Gating — php.quality runs the fixers in APPLY mode (rector process,
#      cs-fixer fix, phpcbf), so in an ephemeral CI container it
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
# PHPStan `level: max` is the sole type-checking gate — there is no second
# analyser to reconcile it with.
php.quality.dry-run: php.stan php.rector.dry-run php.cs-fixer.dry-run php.md php.cs.dry-run php.gherkin php.lint.doctrine php.lint.error-contract php.lint.bounded-context php.lint.event-bus php.lint.audit-resource php.deptrac ## Check-only PHP lint sweep (CI; read-only, parallel-safe)

.PHONY: php.stan php.stan.baseline \
        php.rector php.rector.dry-run \
        php.cs-fixer php.cs-fixer.dry-run \
        php.md php.cs php.cs.dry-run \
        php.gherkin php.gherkin.rules \
        php.lint.doctrine php.lint.yaml \
        php.lint.error-contract php.lint.bounded-context php.lint.event-bus php.lint.audit-resource \
        php.deptrac php.deptrac.baseline \
        php.quality php.quality.dry-run
