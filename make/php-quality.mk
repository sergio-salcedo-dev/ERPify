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
# or if any marker exception at any depth under `api/src/Shared/ErrorContract/Domain/Exception/`
# is not cited in `docs/api-error-contract.md`. The second half is a state invariant over the
# directory tree, not a diff, so it needs no git context inside the container — the doc reaches
# it through the read-only `docs/` bind mount declared in `compose.dev.yaml`.
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

## —— Composer stability gate ———————————————————————————————————————————————

# Fails CI when anything in composer.json `require` admits a non-stable version — a branch
# constraint (`dev-main`, `0.4.x-dev`) or an @-stability flag. `minimum-stability: stable` does
# not enforce this: a per-package flag overrides it silently and Composer records the override in
# composer.lock's `stability-flags`, where nobody looks. `require-dev` is exempt by design (a tool
# that never ships cannot put unreviewed code in front of a customer). Rule: api/CLAUDE.md.
php.lint.composer-stability: ## Composer stability gate (nothing shipped tracks a branch)
	@$(PHP_TEST) bin/phpunit --filter=ComposerStabilityGateTest

## —— Event-dispatch boundary gate ——————————————————————————————————————————

# Fails CI when a file under */Application/ imports Symfony\Component\Messenger\MessageBusInterface
# directly instead of publishing domain events through the Erpify\Shared\Event\Domain\EventBus
# port (skipping api/.event-dispatch-allowlist). ADR: docs/adr/event-driven-architecture.md.
php.lint.event-bus: ## Event-dispatch boundary gate
	@$(PHP_TEST) bin/phpunit --filter=EventDispatchGateTest

## —— Person-resource erasure gate ——————————————————————————————————————————

# Fails CI when an audit `resource_type` reaches the code without being classified in
# api/.audit-resource-types as person-denoting or not, when a type declared `person` names an erasure
# use case that does not wire AuditResourceAnonymiser, or when it names no acceptance scenario that
# seeds a row of the type and asserts none survives. GDPR erasure of the resource axis is the owning
# context's job (docs/adr/audit-activity-log.md D4), so the classification must be a declared decision
# rather than something a new type can skip silently.
#
# The filter is a COMMON PREFIX, not a class name: the gate is three classes (the assertions over the real
# tree, plus the falsifiability of the registry rules and of the witness rule), and a filter naming one of
# them would leave the others outside the named boundary — reporting a broken rule as "PHPUnit" instead of
# as this gate. A fourth class has to keep the prefix or nothing selects it.
php.lint.audit-resource: ## Person-resource erasure classification gate
	@$(PHP_TEST) bin/phpunit --filter='PersonResourceErasure.*GateTest'

## —— Audit erasure-evidence action gate —————————————————————————————————————

# Fails CI when an audit action declared as a constant on an AuditLogger collaborator carries no line in
# api/.audit-evidence-actions, when a classified token no longer exists in src, or when the registry's
# `evidence` half disagrees in either direction with AuditErasureEvidence::ACTIONS — the closed set the
# retention prune binds. Evidence may not expire before the thing it attests: an erasure-evidence row taken
# by the prune turns every correctly-shredded subject into a permanent reported divergence.
#
# Centralising the two literals already made a DRIFTED token impossible. This is the other direction — a
# MISSING member, which fails toward deletion and which nothing detected.
#
# Two classes — the assertions over the real tree, and the falsifiability of the rules against synthetic
# input — and each is selected BY NAME in its own run. A common prefix would keep both under this target
# while going green on a strict subset: rename or delete one and the other still matches, the suite is
# non-empty, and the target reports success with half the boundary gone. One filter per class makes a
# vanished class an empty suite, which `failOnEmptyTestSuite` turns into exit 1.
php.lint.audit-evidence: ## Audit erasure-evidence action classification gate
	@$(PHP_TEST) bin/phpunit --filter=AuditEvidenceActionRegistryGateTest
	@$(PHP_TEST) bin/phpunit --filter=AuditEvidenceActionRulesGateTest

## —— Persistent-transport policy gate ——————————————————————————————————————

# Fails CI when a domain event whose AGGREGATE is a natural person is reachable from a
# framework.messenger.routing key — or an #[AsMessage] attribute — on any transport but `sync`, and
# when an aggregate type declared in src has no line in api/.persistent-transport-policy. `async` and
# `failed` are Doctrine tables no erasure path touches — `async` unbounded, `failed` swept at 30 days —
# so a queued person aggregate id outlives the erasure the application confirmed to the subject.
#
# The gate is two classes and each is selected BY NAME, in its own run. A common prefix keeps both under
# this target but goes green on a strict subset: rename or delete one class and the other still matches, the
# suite is non-empty, and the target reports success with half the boundary gone. One filter per class makes
# a vanished class an empty suite, which `failOnEmptyTestSuite` turns into exit 1 — so the count is pinned by
# construction rather than by a number somebody has to remember to update.
php.lint.persistent-transport: ## Persistent-transport person-aggregate policy gate
	@$(PHP_TEST) bin/phpunit --filter=PersistentTransportPolicyGateTest
	@$(PHP_TEST) bin/phpunit --filter=PersistentTransportRoutingShapeGateTest

## —— Person-reference erasure gate —————————————————————————————————————————

# Fails CI when an entity declares a Types::GUID column that api/.person-reference-policy does not
# classify, when a line there matches no column any more, when a column classified as a person
# reference names a file that does not erase it, or when a #[PersonSubjectReference] on a property
# disagrees with that line. Cross-context references are by id with NO physical foreign key, so
# nothing removes a person's id from a foreign table when their identity is deleted — the obligation
# is distributed, and every context that comes to touch a person mints another one of these columns.
#
# It also fails when a person reference has no source in the detective control that verifies the row is
# GONE (and when a source covers a key the registry does not carry, or two sources cover one key): the
# checks above prove the erasure is WRITTEN, never that it happened.
#
# The gate is four classes — for each half, the assertions over the real tree plus the falsifiability of the
# rules they assert — and each is selected BY NAME, in its own run, for the reason spelled out at the sibling
# target above: a common prefix cannot tell "all four ran" from "one of them is gone". Note the names nest
# (`PersonReference…` is a prefix of `PersonReferenceSource…`), so the filters are anchored by being exact
# class names and each one's selection is verified with `--list-tests`, never assumed.
php.lint.person-reference: ## Person-reference erasure-owner + detective-coverage gate
	@$(PHP_TEST) bin/phpunit --filter=PersonReferenceGateTest
	@$(PHP_TEST) bin/phpunit --filter=PersonReferenceRulesGateTest
	@$(PHP_TEST) bin/phpunit --filter=PersonReferenceSourceGateTest
	@$(PHP_TEST) bin/phpunit --filter=PersonReferenceSourceRulesGateTest

## —— Schedule-consumption gate —————————————————————————————————————————————

# Fails CI when an #[AsSchedule] in api/src has no `messenger:consume` command naming its derived
# `scheduler_<name>` transport in compose.yaml AND compose.prod.yaml, or when such a command names a
# scheduler transport no schedule produces. Symfony's AddScheduleMessengerPass creates the transport from
# the attribute alone and messenger.yaml never declares it, so a schedule whose transport nobody consumes
# compiles, registers and ships DEAD with every other gate green — the failure mode that makes "this control
# is scheduled" unfalsifiable. The stale direction is not cosmetic either: Messenger cannot resolve an
# unknown receiver, so a leftover argument fails the worker's next boot instead of degrading.
#
# It reads the ROOT compose files, which the ./api build context does not contain — they arrive through the
# read-only `./` bind mount at /app/repo declared in compose.dev.yaml (the same mechanism the error-contract
# gate uses for docs/). With the mount gone the gate FAILS rather than skipping; a check that quietly does
# nothing reports the same green as a real pass.
#
# Four classes, each selected BY NAME in its own run, for the reason spelled out at the sibling targets: a
# common prefix goes green on a strict subset, so a deleted class would leave the others matching and the
# target reporting success with part of the boundary gone. Each filter's selection is verified with
# `--list-tests`, never assumed — and adding a class to the boundary means adding its run HERE, or its
# falsification silently leaves the gate while every local run still reports green.
#
# The four cover the three rule halves plus the tree: what the sweep sees declared in PHP
# (ScheduleDeclarationRulesGateTest), what it reads as consumed in the compose files
# (ScheduleConsumptionRulesGateTest), how many replicas those files give the consuming service
# (ScheduleReplicaRulesGateTest), and the real tree pairing all of it (ScheduleConsumptionGateTest).
#
# A green proves a transport NAME appears in a consume command and that no root compose file gives its
# consumer more than one replica. It does not prove the worker runs, that the service is deployed, that
# --time-limit is sane, or that any tick ever reached a log — nor that the DEPLOY runs one clock:
# `docker compose up -d --scale svc=2` was measured to leave TWO CONTAINERS RUNNING for a service declaring
# `deploy.replicas: 1`, so the pin binds the repository, not the host.
php.lint.schedule-consumption: ## Schedule transport-consumption gate
	@$(PHP_TEST) bin/phpunit --filter=ScheduleConsumptionGateTest
	@$(PHP_TEST) bin/phpunit --filter=ScheduleConsumptionRulesGateTest
	@$(PHP_TEST) bin/phpunit --filter=ScheduleReplicaRulesGateTest
	@$(PHP_TEST) bin/phpunit --filter=ScheduleDeclarationRulesGateTest

## —— Prod-container compile gate ———————————————————————————————————————————

# Compiles the service container in the prod environment and fails if it cannot be built.
#
# It exists because a class can declare itself out of production and nothing checks that the
# declaration is true. `WebDebugToolbarLoaderController` autowires `Twig\Environment`, and TwigBundle
# is registered only under dev/test — so the class is correct only for as long as its
# `#[When(env: 'dev')]` / `#[When(env: 'test')]` attributes survive. Strip them and the prod container
# stops compiling ("Cannot autowire ... references class Twig\Environment but no such service exists"),
# while PHPStan, deptrac, PHPUnit, Behat, composer.check.all and the whole of CI stay green: the only
# thing that reads the prod container is `composer run-script post-install-cmd` inside the
# `frankenphp_prod` image build, and no workflow builds that image — CI bakes `compose.yaml` +
# `compose.dev.yaml` only. The first reader was therefore the deploy.
#
# A green proves the prod container COMPILES against the vendor tree that is installed here, which is
# the dev one. It does NOT prove the prod image builds: `composer install --no-dev` prunes require-dev,
# so a src class importing a require-dev package still compiles under this gate and dies in the image.
# That direction belongs to composer.check.missing-deps, which is why the two are wired in together.
# It also proves nothing about runtime — only that every service definition can be resolved.
#
# Not strictly read-only, unlike its neighbours in php.quality.dry-run: it rewrites var/cache/prod.
# Nothing else in the sweep touches that directory (the rest run under APP_ENV=test, PHPStan owns
# var/cache/phpstan), so it stays safe under the -j4 fan-out CI uses.
php.lint.prod-container: ## Prod service-container compile gate
	@$(PHP_PROD) php bin/console cache:clear --env=prod --no-warmup
	@$(PHP_PROD) php bin/console cache:warmup --env=prod

## —— Behat step-vocabulary gate ————————————————————————————————————————————

# Fails CI when the Behat step vocabulary drifts from api/.behat-step-vocabulary: a declared pattern
# classified nowhere, a classification whose pattern is gone, a `used` pattern no scenario reaches, an
# `idle` one a scenario now reaches, a scenario calling a `manual` escape hatch or a `refused` phrasing,
# or a step written in a feature that resolves to no pattern at all.
#
# It exists because the rule it mechanises ("the vocabulary is an asset to spend, never delete a step for
# being unused, search before writing a near-duplicate") lived only in api/CLAUDE.md prose, and prose
# drifts with every gate green: that paragraph counted 205 patterns against 209, 47 features against 49,
# and named a context as wholly idle that thirteen scenarios were reaching, eighteen times.
#
# A green proves each pattern's CLASSIFICATION matches what the features do. It does not prove the
# assertion behind a reached pattern can fail, it does not detect two patterns that say the same thing
# in different words, and its placeholder token is wider than Behat's — the one direction where it
# fails open, and where --strict rather than this gate catches the undispatchable step. The registry
# header enumerates the rest of the blind spots.
php.lint.step-vocabulary: ## Behat step-vocabulary classification gate
	@$(PHP_TEST) bin/phpunit --filter=BehatStepVocabularyGateTest

## —— project-context.md version gate ———————————————————————————————————————

# Fails CI when docs/project-context.md and the manifests it describes disagree, in either direction, or
# when the page claims a version api/.project-context-versions does not bind at all.
#
# That page is not the lightly-read note its history suggests: 60 of the 90 installed skills declare it in
# `persistent_facts`, so it is loaded as foundational context at the start of an agent session rather than
# consulted on demand. A stale line is a false premise handed to the agent before it reads any code,
# asserted with exactly the confidence of a true one.
#
# The versions are gated because they are the part a cheap check can falsify. Measured over that page's
# history, fourteen second-column version numbers have been corrected — twelve of them in one commit
# (#746), immediately before this gate existed. Its normative prose drifted alongside them and remains
# ungated, so a green here is silent about that half; the registry header enumerates the rest of the
# blind spots, including versions glued to their subject by `:` rather than whitespace.
#
# Four classes — the assertions over the real tree, plus falsifiability of the rules for each subject the
# gate reads (the manifest, the page, the registry) — each selected by exact name in its own run, so a
# vanished one is an empty suite rather than a green subset.
php.lint.project-context: ## docs/project-context.md version-claim gate
	@$(PHP_TEST) bin/phpunit --filter=ProjectContextVersionGateTest
	@$(PHP_TEST) bin/phpunit --filter=ProjectContextManifestRulesGateTest
	@$(PHP_TEST) bin/phpunit --filter=ProjectContextPageRulesGateTest
	@$(PHP_TEST) bin/phpunit --filter=ProjectContextRegistryRulesGateTest

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
php.quality: php.stan php.rector php.cs-fixer php.md php.cs php.gherkin php.lint.doctrine php.lint.error-contract php.lint.bounded-context php.lint.event-bus php.lint.audit-resource php.lint.audit-evidence php.lint.persistent-transport php.lint.person-reference php.lint.schedule-consumption php.lint.step-vocabulary php.lint.project-context php.lint.composer-stability php.lint.prod-container composer.check.missing-deps php.deptrac php.cs.dry-run ## Full PHP lint sweep

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
php.quality.dry-run: php.stan php.rector.dry-run php.cs-fixer.dry-run php.md php.cs.dry-run php.gherkin php.lint.doctrine php.lint.error-contract php.lint.bounded-context php.lint.event-bus php.lint.audit-resource php.lint.audit-evidence php.lint.persistent-transport php.lint.person-reference php.lint.schedule-consumption php.lint.step-vocabulary php.lint.project-context php.lint.composer-stability php.lint.prod-container composer.check.missing-deps php.deptrac ## Check-only PHP lint sweep (CI; read-only, parallel-safe)

.PHONY: php.stan php.stan.baseline \
        php.rector php.rector.dry-run \
        php.cs-fixer php.cs-fixer.dry-run \
        php.md php.cs php.cs.dry-run \
        php.gherkin php.gherkin.rules \
        php.lint.doctrine php.lint.yaml \
        php.lint.error-contract php.lint.bounded-context php.lint.event-bus php.lint.audit-resource php.lint.audit-evidence \
        php.lint.persistent-transport php.lint.person-reference php.lint.schedule-consumption php.lint.step-vocabulary \
        php.lint.composer-stability php.lint.prod-container php.lint.project-context \
        php.deptrac php.deptrac.baseline \
        php.quality php.quality.dry-run
