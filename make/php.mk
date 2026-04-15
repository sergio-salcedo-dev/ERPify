# =============================================================================
# PHP
# =============================================================================

.PHONY: php.check.modules symfony.about

php.check.modules: ## Check PHP modules
	$(PHP_CONT) php -m

symfony.about: ## Show Symfony about
	$(PHP_CONT) php bin/console about
