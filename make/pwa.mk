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

pwa.install: ## npm ci in pwa/
	@$(call pwa_cmd,npm ci)

pwa.dev: ## Next dev server (Turbopack) on host :80 (needs pwa/.env.local)
	@$(call pwa_cmd,npm run dev)

pwa.build: ## Next production build
	@$(call pwa_cmd,npm run build)

## —— PWA lint / format ——

pwa.lint: pwa.lint.eslint pwa.lint.prettier ## Full PWA lint (ESLint + Prettier check)

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

pwa.test.e2e: ## Playwright E2E; CI_SHARD=N CI_TOTAL_SHARDS=M for sharded runs; pass c='…' for extra args
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

pwa.clean: ## Remove node_modules, package-lock.json, .next (destructive)
	@$(call pwa_cmd,rm -rf node_modules package-lock.json .next)

.PHONY: pwa.install pwa.dev pwa.build \
        pwa.lint pwa.lint.eslint pwa.lint.eslint.fix pwa.lint.prettier pwa.format.prettier.fix \
        pwa.test pwa.test.unit pwa.test.unit.watch pwa.test.e2e pwa.test.e2e.reports \
        pwa.util.extract.testids pwa.clean
