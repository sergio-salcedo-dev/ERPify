# =============================================================================
# PWA - Next.js install/dev/build/test/lint (host execution only).
# =============================================================================

# All targets run on the host via $(pwa_cmd) (defined in config.mk), not in
# the pwa container — see make/CONVENTIONS.md §7.1 and §8 for the rationale.
#
# Target names match CI (.github/workflows/ci.yml):
#   pwa.install / pwa.update / pwa.upgrade / pwa.dev
#   pwa.production.build / pwa.production.start
#   pwa.quality.dry-run / pwa.quality
#   pwa.lint[.dry-run] / pwa.format[.dry-run]
#   pwa.test / pwa.test.unit[.watch] / pwa.test.e2e[.reports]
#   pwa.clean.soft / pwa.clean.all (destructive)

.PHONY: pwa.install pwa.install.if-missing pwa.update pwa.upgrade pwa.dev \
        pwa.production.build pwa.production.start \
        pwa.quality.dry-run pwa.quality \
        pwa.lint.dry-run pwa.lint pwa.format.dry-run pwa.format \
        pwa.test pwa.test.unit pwa.test.unit.watch pwa.test.e2e pwa.test.e2e.reports npm.dev.e2e \
        pwa.util.extract.testids pwa.clean.soft pwa.clean.all pwa.clean.sudo

## —— PWA install / dev / build ——

pwa.install: ## Install dependencies in the PWA directory (auto-cleans empty root-owned node_modules left by the dev compose volume)
	@if [ -d $(PWA_ROOT)/node_modules ] && [ ! -w $(PWA_ROOT)/node_modules ] && [ -z "$$(ls -A $(PWA_ROOT)/node_modules 2>/dev/null)" ]; then \
		echo "Removing empty root-owned pwa/node_modules (Docker mount artifact)…"; \
		$(DOCKER_COMPOSE) stop $(PWA_SERVICE) 2>/dev/null || true; \
		docker run --rm -v "$(PWA_ROOT):/work" -w /work alpine rmdir node_modules || true; \
	fi
	@$(call pwa_cmd,npm ci)

pwa.install.if-missing: ## Run pwa.install only if pwa/node_modules is missing or unhealthy
	@if [ -d $(PWA_ROOT)/node_modules ] && [ -w $(PWA_ROOT)/node_modules ] && [ -n "$$(ls -A $(PWA_ROOT)/node_modules 2>/dev/null)" ]; then \
		: ; \
	else \
		$(MAKE) --no-print-directory pwa.install; \
	fi

pwa.update: ## Safe update dependencies (within semantic version ranges)
	@$(call pwa_cmd,npm update)

pwa.upgrade: ## Force upgrade all dependencies to the latest versions (npm-check-updates). Uses --legacy-peer-deps because cross-major bumps routinely trip npm's strict peer resolver on transitive ranges (e.g. eslint-config-next pinning @typescript-eslint/*).
	@$(call pwa_cmd,npx npm-check-updates -u && npm install --legacy-peer-deps)

pwa.dev: ## Next dev server (Turbopack) on host :80 (needs pwa/.env.local)
	@$(call pwa_cmd,npm run dev)


## —— PWA production ——

pwa.production.build: ## Next production build
	@$(call pwa_cmd,npm run build)

pwa.production.start: ## Start production server on port 80
	@$(call pwa_cmd,npm run start)

## —— PWA quality ——

pwa.quality.dry-run: pwa.lint.dry-run pwa.format.dry-run ## Full PWA lint (ESLint + Prettier check)

pwa.quality: pwa.lint pwa.format ## Full PWA lint (ESLint + Prettier check)

## —— PWA lint (ESLint) ——

pwa.lint.dry-run: pwa.install.if-missing ## ESLint (check only); pass c='…' for extra args
	@$(call pwa_cmd,npm run lint -- $(c))

pwa.lint: pwa.install.if-missing ## ESLint --fix
	@$(call pwa_cmd,npm run lint:fix)

## —— PWA format (Prettier) ——

pwa.format.dry-run: pwa.install.if-missing ## Prettier check (no writes)
	@$(call pwa_cmd,npm run format)

pwa.format: pwa.install.if-missing ## Prettier --write
	@$(call pwa_cmd,npm run format:fix)

## —— Unit Tests (Vitest) ——

pwa.test.unit: pwa.install.if-missing ## Run unit tests with Vitest (run once); pass c='…' for extra args (e.g. c='path/to/file.test.ts')
	@$(call pwa_cmd,npm run test:unit -- $(c))

pwa.test.unit.watch: pwa.install.if-missing ## Run unit tests (Vitest) watch mode
	@$(call pwa_cmd,npm run test:watch)

## —— E2E Tests (Playwright) ——

pwa.test.e2e: pwa.install.if-missing ## Run end-to-end tests with Playwright; CI_SHARD=N CI_TOTAL_SHARDS=M for sharded runs; pass c='…' for extra args
	@if [ -n "$(CI_SHARD)" ] && [ -n "$(CI_TOTAL_SHARDS)" ]; then \
		$(call pwa_cmd,npm run test:e2e -- --shard=$(CI_SHARD)/$(CI_TOTAL_SHARDS) $(c)); \
	else \
		$(call pwa_cmd,npm run test:e2e -- $(c)); \
	fi

pwa.test.e2e.reports: ## Open the Playwright test HTML report
	@$(call pwa_cmd,npm run test:e2e:reports)

npm.dev.e2e: ## Start development server for E2E testing on port 3000
	@$(call pwa_cmd,npm run dev:e2e)

## —— PWA tests ——

pwa.test: pwa.test.unit pwa.test.e2e ## Full PWA test suite (Vitest + Playwright)

## —— PWA utilities ——

pwa.util.extract.testids: ## Extract data-testid attributes
	@./scripts/extract-testids.sh pwa/reports/data-testid/testids.txt pwa/src

## —— PWA clean ——

pwa.clean.soft: ## Remove .next, .next-e2e
	@$(call pwa_cmd,npm run clean)

pwa.clean.all: pwa.clean.soft ## Remove node_modules, .next, .next-e2e (destructive)
	@$(call pwa_cmd,rm -rf node_modules)

# Host-side sudo wipe. `.next` / `.next-e2e` are written by the container as root,
# so the host user can't remove them without sudo — this target can. Mirrors
# pwa.clean.all's target set (node_modules, .next, .next-e2e).
pwa.clean.sudo: ## Wipe pwa node_modules/.next/.next-e2e host-side (requires sudo; dev/test only)
	$(call guard_var_writable,pwa.clean.sudo)
	@sudo rm -rf $(PWA_ROOT)/node_modules $(PWA_ROOT)/.next $(PWA_ROOT)/.next-e2e
