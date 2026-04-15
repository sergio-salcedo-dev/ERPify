# =============================================================================
# JS — Linters & Formatters (always host-native)
# =============================================================================

## —— ESLint ——

js.lint.eslint: ## Run ESLint
	$(call pwa_cmd,npm run lint)

js.lint.eslint.fix: ## Run ESLint --fix
	$(call pwa_cmd,npm run lint:fix)

## —— Prettier ——

js.format.prettier: ## Run Prettier check
	$(call pwa_cmd,npm run format)

js.format.prettier.fix: ## Run Prettier --write
	$(call pwa_cmd,npm run format:fix)

## —— Aggregate ——

js.lint: js.lint.eslint js.format.prettier ## Run all JS linters

.PHONY: js.lint.eslint js.lint.eslint.fix js.format.prettier js.format.prettier.fix js.lint
