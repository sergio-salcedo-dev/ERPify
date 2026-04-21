# =============================================================================
# JS — Next.js Development & Build (always host-native)
# DEPRECATED
# =============================================================================

## —— Core commands ——

js.install: ## npm ci in PWA directory
	$(call pwa_cmd,npm ci)

js.dev: ## Next dev server (Turbopack)
	$(call pwa_cmd,npm run dev -- --turbo)

js.build: ## Next production build
	$(call pwa_cmd,npm run build)

## —— Aggregate ——

js: js.install js.build ## Install + build

.PHONY: js.install js.dev js.build js
