# =============================================================================
# PHP test suites (PHPUnit + Behat).
# =============================================================================

# Target names match CI (.github/workflows/ci.yml):
#   php.unit / php.behat / php.test

.PHONY: php.unit php.unit.coverage \
		php.behat \
		php.test \
		php.bench

## —— PHP Unit ——

# PHPUnit runs from the app vendor (bin/phpunit) — no separate tools tree to
# install (api/tools/phpunit holds only bootstrap.php + phpunit.dist.xml).
#
# PHPUNIT_MEMORY_LIMIT (make/config.mk, default 512M): the whole suite runs in
# one process and peaks at 127–145 MB — PHPUnit's own `Memory:` line over a full
# run, which is only observable under an already-raised limit because the run
# dies first otherwise. That figure drifts up as the suite and its dependencies
# grow — a doctrine-bundle minor moved it several MB — so read it as a floor for
# the ceiling, not a fixed number. The peak falls in the functional phase and
# lands on the image's 128M default, so whichever test makes the next allocation
# is the one that dies, a different one each run. Filtered runs stay below it (a
# `--filter` gate peaks ~55 MB), which is why php.bench and the php.lint.* gates
# need no flag. Raised on this invocation only: the image default keeps bounding
# real HTTP requests. 512M is margin over the measured peak, not a measured need.
php.unit: ## PHPUnit; pass c='…' for extra args (e.g. c='--filter SomeTest')
	@$(PHP_TEST) php -d memory_limit=$(PHPUNIT_MEMORY_LIMIT) bin/phpunit $(c)

# Clover report for SonarCloud (sonar.php.coverage.reportPaths). Runs with
# XDEBUG_MODE=coverage (the image ships Xdebug off). PHPUnit writes absolute
# in-container file names (/app/api/src/…); rewrite them to repo-root-relative
# (api/src/…) so SonarCloud's projectBaseDir resolves them in its own checkout.
#
# --do-not-fail-on-phpunit-warning: coverage mode validates every #[CoversClass]
# target against the coverage source scope (src/), so tests covering test-tree
# support utilities raise PHPUnit warnings ("not a valid target for code
# coverage") that the no-coverage `php.unit` gate never sees. This is a
# report-generation target, not the gate — `php.unit` keeps failOnWarning strict.
# Warnings still print, only the exit code is relaxed.
#
# memory_limit=1G: Xdebug coverage instrumentation holds per-line maps for the
# whole run, on top of the suite's own footprint, and exhausts the image's
# default 128M near the end of the suite.
COVERAGE_CLOVER := var/coverage/clover.xml
php.unit.coverage: ## PHPUnit with clover coverage → api/var/coverage/clover.xml (Xdebug coverage mode; report only — php.unit is the gate)
	@$(PHP_TEST_COVERAGE) php -d memory_limit=1G bin/phpunit --coverage-clover $(COVERAGE_CLOVER) --do-not-fail-on-phpunit-warning $(c)
	@sed -i 's#name="[^"]*/api/src/#name="api/src/#g' $(API_ROOT)/$(COVERAGE_CLOVER)

## —— Behat ——

# Behat runs from the app vendor, like PHPUnit — no separate tools tree to install.
# The config file is discovered from the cwd (api/behat.dist.php), so no -c is needed.
#
# --strict, because Behat's default is not. `strict` defaults to false, and SoftInterpretation counts
# only FAILED (99) and above as a failure — UNDEFINED is 30, so a step nothing defines leaves the run
# at exit 0 with the scenario reported as passed-but-for-that-step. That is the failure mode this
# suite is least able to notice: a context that stops being registered, or an assertion whose phrase
# was renamed, removes the checking and leaves the scenario looking like it ran.
php.behat: ## Behat (strict: an undefined step fails); pass c='…', example: php.behat c='features/backoffice/bank/get.feature'
	@$(PHP_BEHAT) vendor/bin/behat --strict --format=pretty $(c)

## —— benchmarks ——

php.bench: ## Run listener performance-budget benchmarks (opt-in, default php.unit skips)
ifeq ($(IN_CONTAINER),false)
	@cd $(API_ROOT) && APP_ENV=test RUN_BENCHMARKS=1 bin/phpunit --group benchmark $(c)
else
	@$(DOCKER_COMPOSE_EXEC) -e APP_ENV=test -e RUN_BENCHMARKS=1 $(PHP_SERVICE) bin/phpunit --group benchmark $(c)
endif


## —— All PHP tests ——

php.test: php.unit php.behat ## Full PHP test suite (PHPUnit + Behat)
