# ERPify — Monorepo Makefile (entry point only)
#
# Usage:
#   make docker.up                  — dev stack (default)
#   make docker.up ENV=ci           — CI stack
#   make docker.up ENV=staging      — staging (prod topology)
#   make docker.up ENV=prod         — production
#   make help                       — all commands
#
# Environments: dev | ci | staging | prod

ENV ?= dev
IN_CONTAINER ?= $(if $(filter ci,$(ENV)),false,true)

# —— Include modules (config first) ——
-include make/config.mk
-include make/docker.mk
-include make/php.mk
-include make/php-test.mk
-include make/php-lint.mk
-include make/js.mk
-include make/js-test.mk
-include make/js-lint.mk
-include make/ci.mk
-include make/utils.mk

.DEFAULT_GOAL := help

# —— Aggregate targets ——

lint: php.lint js.lint ## Run all linters (PHP + JS)

test: php.test js.test ## Run all tests (PHP + JS)

ci: ci.lint ci.test ## Full CI pipeline

.PHONY: lint test ci
