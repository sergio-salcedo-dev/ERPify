# =============================================================================
# CI
# =============================================================================

ci.quality: php.quality pwa.quality ## All linters (PHP + PWA)

ci.test: php.test pwa.test ## All tests (PHP + PWA)

ci: ci.quality ci.test ## Full CI (lint + test)

ci.api: php.quality php.test ## API only: lint + tests

ci.pwa: pwa.lint pwa.test.unit pwa.production.build ## PWA only: lint + unit + build (no E2E)

.PHONY: ci ci.quality ci.test ci.api ci.pwa
