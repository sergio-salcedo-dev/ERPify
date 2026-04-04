# —— PWA (Next.js) 🌐 ————————————————————————————————————————————————————————
PWA_DIR := .

pwa.install: ## Install npm dependencies
	@npm ci

pwa.dev: ## Start Next.js dev server (Turbopack)
	@npm run dev -- --turbo

pwa.build: ## Build Next.js production bundle
	@npm run build

pwa.test: ## Run Vitest unit tests; pass c= for CLI options, e.g. make pwa.test c="--watch"
	@$(eval c ?=)
	@npm test -- $(c)

pwa.e2e: ## Run Playwright E2E tests
	@npm run e2e

pwa.lint: ## Run ESLint + Next.js lint
	@npm run lint

pwa.lint.fix: ## Run ESLint fix
	@npm run lint:fix

pwa.format: ## Run Prettier format
	@npm run format

# —— CI 🚀 ————————————————————————————————————————————————————————————————————
ci: pwa.lint pwa.test pwa.build ## Run all CI checks (lint, test, build)
