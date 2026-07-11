# =============================================================================
# PHP test suites (PHPUnit + Behat).
# =============================================================================

# Target names match CI (.github/workflows/ci.yml):
#   php.unit / php.behat / php.behat.install / php.test

.PHONY: php.unit php.unit.coverage \
		php.behat php.behat.install \
		php.test \
		php.bench

## —— PHP Unit ——

# PHPUnit runs from the app vendor (bin/phpunit) — no separate tools tree to
# install (api/tools/phpunit holds only bootstrap.php + phpunit.dist.xml).
php.unit: ## PHPUnit; pass c='…' for extra args (e.g. c='--filter SomeTest')
	@$(PHP_TEST) bin/phpunit $(c)

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
# whole run and exhausts the image's default 128M near the end of the suite.
# Raised only here (report-only); the `php.unit` gate keeps the default limit.
COVERAGE_CLOVER := var/coverage/clover.xml
php.unit.coverage: ## PHPUnit with clover coverage → api/var/coverage/clover.xml (Xdebug coverage mode; report only — php.unit is the gate)
	@$(PHP_TEST_COVERAGE) php -d memory_limit=1G bin/phpunit --coverage-clover $(COVERAGE_CLOVER) --do-not-fail-on-phpunit-warning $(c)
	@sed -i 's#name="[^"]*/api/src/#name="api/src/#g' $(API_ROOT)/$(COVERAGE_CLOVER)

## —— Behat ——

php.behat: ## Behat; pass c='…' for extra args, example: php.behat c='features/backoffice/bank/get.feature'
	@$(PHP_BEHAT) php tools/behat/run.php -c tools/behat/behat.yml.dist --format=pretty $(c)

php.behat.install: ## Install Behat tooling (api/tools/behat)
	@$(COMPOSER) behat-tools-install

## —— benchmarks ——

php.bench: ## Run listener performance-budget benchmarks (opt-in, default php.unit skips)
ifeq ($(IN_CONTAINER),false)
	@cd $(API_ROOT) && APP_ENV=test RUN_BENCHMARKS=1 bin/phpunit --group benchmark $(c)
else
	@$(DOCKER_COMPOSE_EXEC) -e APP_ENV=test -e RUN_BENCHMARKS=1 $(PHP_SERVICE) bin/phpunit --group benchmark $(c)
endif


## —— All PHP tests ——

php.test: php.unit php.behat ## Full PHP test suite (PHPUnit + Behat)
