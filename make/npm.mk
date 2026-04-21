# =============================================================================
# NPM dependencies management for the PWA directory
# DEPRECATED
# =============================================================================

.PHONY: npm.install npm.update npm.upgrade npm.clean npm.dev npm.dev.e2e npm.build npm.start npm.next.clean npm.lint npm.lint.fix npm.format npm.test npm.test.watch npm.e2e npm.e2e.reports

npm.install: ## Install dependencies in the PWA directory
	cd $(PWA_DIR) && $(NPM) install

npm.update: ## Safe update dependencies (within semantic version ranges)
	cd $(PWA_DIR) && $(NPM) update

npm.upgrade: ## Force upgrade all dependencies to the latest versions
	cd $(PWA_DIR) && $(NCU) -u
	cd $(PWA_DIR) && $(NPM) install

npm.clean: ## Remove node_modules and lock files
	rm -rf $(PWA_DIR)/node_modules
	rm -f $(PWA_DIR)/package-lock.json
	@echo "Cleaned PWA directory."

# =============================================================================
# Development & Production
# =============================================================================

npm.dev: ## Start development server with Turbo on port 80
	cd $(PWA_DIR) && $(NPM) run dev

npm.dev.e2e: ## Start development server for E2E testing on port 3000
	cd $(PWA_DIR) && $(NPM) run dev:e2e

npm.build: ## Build the application for production
	cd $(PWA_DIR) && $(NPM) run build

npm.start: ## Start production server on port 80
	cd $(PWA_DIR) && $(NPM) run start

npm.next.clean: ## Clean Next.js build cache
	cd $(PWA_DIR) && $(NPM) run clean

# =============================================================================
# Quality & Testing (Linting, Formatting, Testing)
# =============================================================================

npm.lint: ## Run ESLint checks
	cd $(PWA_DIR) && $(NPM) run lint

npm.lint.fix: ## Run ESLint and fix fixable issues
	cd $(PWA_DIR) && $(NPM) run lint:fix

npm.format: ## Format code using Prettier
	cd $(PWA_DIR) && $(NPM) run format

npm.test: ## Run unit tests with Vitest
	cd $(PWA_DIR) && $(NPM) run test

npm.test.watch: ## Run unit tests in watch mode
	cd $(PWA_DIR) && $(NPM) run test:watch

npm.e2e: ## Run end-to-end tests with Playwright
	cd $(PWA_DIR) && $(NPM) run e2e

npm.e2e.reports: ## Show Playwright E2E test reports
	cd $(PWA_DIR) && $(NPM) run e2e:reports
