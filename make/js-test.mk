# =============================================================================
# JS — Tests (always host-native)
# =============================================================================

## —— Unit Tests (Vitest) ——

js.test.unit: ## Run Vitest unit tests; pass c= for extra args
	@$(eval c ?=)
	$(call pwa_cmd,npm test -- $(c))

## —— E2E Tests (Playwright) ——

js.test.e2e: ## Run Playwright E2E tests
	$(call pwa_cmd,npm run e2e)

## —— Aggregate ——

js.test: js.test.unit js.test.e2e ## Run all JS tests

.PHONY: js.test.unit js.test.e2e js.test
