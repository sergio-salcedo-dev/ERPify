# make/php-test.mk — PHP test suites (PHPUnit + Behat).
#
# Target names match CI (.github/workflows/ci.yml):
#   php.unit / php.unit.install / php.behat / php.behat.install / php.test

## —— PHP tests ——

php.unit: ## PHPUnit; pass c='…' for extra args (e.g. c='--filter SomeTest')
	@$(eval c ?=)
	@$(PHP_TEST) bin/phpunit $(c)

php.unit.install: ## Install PHPUnit tooling (api/tools/phpunit)
	@$(COMPOSER) phpunit-tools-install

php.behat: ## Behat; pass c='…' for extra args, example: php.behat c='features/backoffice/bank/get.feature'
	@$(eval c ?=)
	@$(PHP_BEHAT) php tools/behat/run.php -c tools/behat/behat.yml.dist --format=pretty $(c)

php.behat.install: ## Install Behat tooling (api/tools/behat)
	@$(COMPOSER) behat-tools-install

php.test: php.unit php.behat ## Full PHP test suite (PHPUnit + Behat)

.PHONY: php.unit php.unit.install php.behat php.behat.install php.test
