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
        pwa.lint.dry-run pwa.lint pwa.format.dry-run pwa.format pwa.typecheck \
        pwa.test pwa.test.unit pwa.test.unit.coverage pwa.test.unit.watch pwa.test.e2e pwa.test.e2e.reports npm.dev.e2e \
        pwa.util.extract.testids pwa.chown.next pwa.clean.soft pwa.clean.all pwa.clean.sudo

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

pwa.quality.dry-run: pwa.lint.dry-run pwa.format.dry-run pwa.typecheck ## Full PWA lint (ESLint + Prettier + Typecheck)

pwa.quality: pwa.lint pwa.format pwa.typecheck ## Full PWA lint (ESLint + Prettier + Typecheck)

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

## —— PWA typecheck (tsc) ——

pwa.typecheck: pwa.install.if-missing ## Typecheck PWA (tsc --noEmit)
	@$(call pwa_cmd,npm run typecheck -- --incremental false)

## —— Unit Tests (Vitest) ——

pwa.test.unit: pwa.install.if-missing ## Run unit tests with Vitest (run once); pass c='…' for extra args (e.g. c='path/to/file.test.ts')
	@$(call pwa_cmd,npm run test:unit -- $(c))

pwa.test.unit.coverage: pwa.install.if-missing ## Vitest with coverage → pwa/coverage/lcov.info (SonarCloud import)
	@$(call pwa_cmd,npm run test:unit -- --coverage $(c))
	@# Vitest writes SF: paths relative to pwa/; rewrite to repo-root-relative
	@# (pwa/src/…) so SonarCloud's projectBaseDir resolves them against pwa/src.
	@sed -i 's#^SF:src/#SF:pwa/src/#' $(PWA_ROOT)/coverage/lcov.info

pwa.test.unit.watch: pwa.install.if-missing ## Run unit tests (Vitest) watch mode
	@$(call pwa_cmd,npm run test:watch)

## —— E2E Tests (Playwright) ——

# E2E fixture user — seeded into the running stack before the Playwright run so the `setup` project can log in
# against the default-deny /api (there is no login UI yet). The org is provisioned first, then the user is
# created as the installation administrator (identity + ADMIN membership) via the CLI bootstrap path — there is
# no generic user-create command. Idempotent: a second provision / duplicate email just fails the CLI, swallowed.
# Keep the values in sync with E2E_USER_* in pwa/tests/e2e/constants.ts.
E2E_USER_EMAIL ?= e2e@erpify.test
E2E_USER_PASSWORD ?= e2ePassword123
E2E_ORG_NAME ?= E2E-Test-Organization

pwa.test.e2e: pwa.install.if-missing ## Run end-to-end tests with Playwright; CI_SHARD=N CI_TOTAL_SHARDS=M for sharded runs; pass c='…' for extra args
	@echo "[e2e] seeding org + admin $(E2E_USER_EMAIL) (idempotent)…"
	@$(MAKE) --no-print-directory sf c='organization:provision $(E2E_ORG_NAME)' >/dev/null 2>&1 || true
	@$(MAKE) --no-print-directory sf c='organization:administrator:create $(E2E_USER_EMAIL) $(E2E_USER_PASSWORD)' >/dev/null 2>&1 \
		&& echo "[e2e] org + admin seeded." \
		|| echo "[e2e] seed returned non-zero — user likely already exists (fine) or the stack is unreachable."
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

## —— PWA ownership ——

# The host `pwa.dev` (Turbopack on :80) and `pwa.test.e2e` (its Next webServer)
# regenerate several build artifacts on the host tree: .next/trace, the
# .next-e2e dir, plus the root-level next-env.d.ts and tsconfig.tsbuildinfo. When
# a container/worktree stack wrote any of them as root onto the bind mount, the
# host process fails with EACCES (mkdir .next-e2e/dev, open next-env.d.ts, …).
# This reclaims ownership of every such regenerable artifact for the host user
# WITHOUT deleting the build cache (unlike pwa.clean.sudo). Mirrors sf.chown.var.
# Missing paths are skipped; all listed paths are Next/TS-regenerated, so a chown
# (not a wipe) is always safe.
PWA_CHOWN_TARGETS := .next .next-e2e next-env.d.ts tsconfig.tsbuildinfo
pwa.chown.next: ## Reclaim ownership of root-owned pwa Next/TS build artifacts (.next, .next-e2e, next-env.d.ts, tsconfig.tsbuildinfo); fixes host EACCES (requires sudo; dev/test only)
	$(call guard_var_writable,pwa.chown.next)
	@for d in $(addprefix $(PWA_ROOT)/,$(PWA_CHOWN_TARGETS)); do \
		[ -e "$$d" ] && sudo chown -R $(shell id -u):$(shell id -g) "$$d" || true; \
	done
	@echo "✓ pwa Next/TS build artifacts now owned by $(shell id -un)"

## —— PWA clean ——

pwa.clean.soft: ## Remove .next, .next-e2e
	@$(call pwa_cmd,npm run clean)

pwa.clean.all: pwa.clean.soft ## Remove node_modules, .next, .next-e2e (destructive)
	@$(call pwa_cmd,rm -rf node_modules)

# Host-side sudo wipe. The dev container now keeps .next / .next-e2e in named
# volumes (pwa_next / pwa_next_e2e), so a fresh stack no longer writes them
# root-owned onto the host tree — wipe those with `docker compose down -v` (or
# remove the volumes). This target stays as an escape hatch for root-owned
# leftovers from pre-volume checkouts (and host node_modules). Mirrors
# pwa.clean.all's target set (node_modules, .next, .next-e2e).
pwa.clean.sudo: ## Wipe pwa node_modules/.next/.next-e2e host-side (requires sudo; dev/test only)
	$(call guard_var_writable,pwa.clean.sudo)
	@sudo rm -rf $(PWA_ROOT)/node_modules $(PWA_ROOT)/.next $(PWA_ROOT)/.next-e2e
