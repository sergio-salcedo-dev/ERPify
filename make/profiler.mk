# =============================================================================
# Symfony Profiler dev tooling (dev/test only).
# =============================================================================

## —— Profiler ——

profiler.open: ## Open the Symfony Profiler UI (/_profiler/latest) in the host browser
	@port=$$($(DOCKER_COMPOSE) port --protocol tcp $(PHP_SERVICE) 443 2>/dev/null | cut -d: -f2); \
	url="https://localhost:$${port:-443}/_profiler/latest"; \
	printf 'Opening %s\n' "$$url"; \
	xdg-open "$$url" >/dev/null 2>&1 || open "$$url" >/dev/null 2>&1 || printf 'Open it manually: %s\n' "$$url"

profiler.dump-server: ## Start the var-dumper server (collects dump() out-of-band; Ctrl-C to stop)
	@$(SYMFONY) server:dump

.PHONY: profiler.open profiler.dump-server
