# make/ci.mk — CI pipeline aggregates.
#
# Raw per-tool targets live in their domain modules (php-lint.mk, php-test.mk,
# pwa.mk). This file only composes them.

## —— CI ——

ci.lint: php.lint pwa.lint ## All linters (PHP + PWA)

ci.test: php.test pwa.test ## All tests (PHP + PWA)

ci: ci.lint ci.test ## Full CI (lint + test)

ci.api: php.lint php.test ## API only: lint + tests

ci.pwa: pwa.lint pwa.test.unit pwa.build ## PWA only: lint + unit + build (no E2E)

.PHONY: ci ci.lint ci.test ci.api ci.pwa
