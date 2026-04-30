# make/pwa.mk — Next.js install/dev/build/test/lint (host execution only).
#
# All targets run on the host via $(pwa_cmd) (defined in config.mk), not in
# the pwa container — see make/CONVENTIONS.md §7.1 and §8 for the rationale.
#
# Target names match CI (.github/workflows/ci.yml):
#   pwa.install / pwa.dev / pwa.build
#   pwa.lint / pwa.lint.eslint[.fix] / pwa.lint.prettier / pwa.format.prettier.fix
#   pwa.test / pwa.test.unit[.watch] / pwa.test.e2e[.reports]
#   pwa.clean (destructive)

## —— PWA install / dev / build ——

pwa.install: ## npm ci in pwa/ (auto-cleans empty root-owned node_modules left by the dev compose volume)
	@if [ -d $(PWA_ROOT)/node_modules ] && [ ! -w $(PWA_ROOT)/node_modules ] && [ -z "$$(ls -A $(PWA_ROOT)/node_modules 2>/dev/null)" ]; then \
		echo "Removing empty root-owned pwa/node_modules (Docker mount artifact)…"; \
		$(DC) stop $(PWA_SERVICE) 2>/dev/null || true; \
		docker run --rm -v "$(PWA_ROOT):/work" -w /work alpine rmdir node_modules || true; \
	fi
	@$(call pwa_cmd,npm ci)

pwa.install.if-missing: ## Run pwa.install only if pwa/node_modules is missing or unhealthy
	@if [ -d $(PWA_ROOT)/node_modules ] && [ -w $(PWA_ROOT)/node_modules ] && [ -n "$$(ls -A $(PWA_ROOT)/node_modules 2>/dev/null)" ]; then \
		: ; \
	else \
		$(MAKE) --no-print-directory pwa.install; \
	fi

pwa.dev: ## Next dev server (Turbopack) on host :80 (needs pwa/.env.local)
	@$(call pwa_cmd,npm run dev)

pwa.build: ## Next production build
	@$(call pwa_cmd,npm run build)

## —— PWA lint / format ——

pwa.lint: pwa.lint.eslint pwa.lint.prettier ## Full PWA lint (ESLint + Prettier check)

pwa.lint.fix: pwa.lint.eslint.fix pwa.format.prettier.fix ## Full PWA lint (ESLint + Prettier check)

pwa.lint.eslint: ## ESLint (check only); pass c='…' for extra args
	@$(eval c ?=)
	@$(call pwa_cmd,npm run lint -- $(c))

pwa.lint.eslint.fix: ## ESLint --fix
	@$(call pwa_cmd,npm run lint:fix)

pwa.lint.prettier: ## Prettier check (no writes)
	@$(call pwa_cmd,npx prettier --check .)

pwa.format.prettier.fix: ## Prettier --write
	@$(call pwa_cmd,npm run format)

## —— PWA tests ——

pwa.test: pwa.test.unit pwa.test.e2e ## Full PWA test suite (Vitest + Playwright)

pwa.test.unit: ## Vitest (run once); pass c='…' for extra args (e.g. c='path/to/file.test.ts')
	@$(eval c ?=)
	@$(call pwa_cmd,npm run test -- $(c))

pwa.test.unit.watch: ## Vitest watch mode
	@$(call pwa_cmd,npm run test:watch)

pwa.test.e2e: pwa.install.if-missing ## Playwright E2E; CI_SHARD=N CI_TOTAL_SHARDS=M for sharded runs; pass c='…' for extra args
	@$(eval c ?=)
	@if [ -n "$(CI_SHARD)" ] && [ -n "$(CI_TOTAL_SHARDS)" ]; then \
		$(call pwa_cmd,npm run e2e -- --shard=$(CI_SHARD)/$(CI_TOTAL_SHARDS) $(c)); \
	else \
		$(call pwa_cmd,npm run e2e -- $(c)); \
	fi

pwa.test.e2e.reports: ## Open the Playwright HTML report
	@$(call pwa_cmd,npm run e2e:reports)

## —— PWA utilities ——

pwa.util.extract.testids: ## Extract data-testid attributes
	@./scripts/extract-testids.sh pwa/reports/data-testid/testids.txt pwa/src

## —— PWA clean ——

pwa.clean: ## Remove node_modules, package-lock.json, .next, .next-e2e (destructive)
	@$(call pwa_cmd,rm -rf node_modules package-lock.json .next .next-e2e)

.PHONY: pwa.install pwa.install.if-missing pwa.dev pwa.build \
        pwa.lint pwa.lint.eslint pwa.lint.eslint.fix pwa.lint.prettier pwa.format.prettier.fix \
        pwa.test pwa.test.unit pwa.test.unit.watch pwa.test.e2e pwa.test.e2e.reports \
        pwa.util.extract.testids pwa.clean
