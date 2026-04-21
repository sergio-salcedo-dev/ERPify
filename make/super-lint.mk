# make/super-lint.mk — GitHub SuperLinter (run locally via Docker).

## —— SuperLinter ——

super-lint: ## Run SuperLinter over the whole repo (requires GITHUB_TOKEN)
	@if [ -z "$(GITHUB_TOKEN)" ]; then echo 'GITHUB_TOKEN is required' >&2; exit 1; fi
	docker run --rm \
		-e RUN_LOCAL=true \
		-e DEFAULT_BRANCH=main \
		-e VALIDATE_ALL_CODEBASE=$(SUPERLINTER_VALIDATE_ALL_CODEBASE) \
		-e FILTER_REGEX_EXCLUDE='$(SUPERLINTER_EXCLUDES)' \
		-e GITHUB_TOKEN=$(GITHUB_TOKEN) \
		-v $(PROJECT_ROOT):/tmp/lint \
		$(SUPERLINTER_IMAGE)

super-lint.quick: ## SuperLinter on changed files only (slim image)
	@if [ -z "$(GITHUB_TOKEN)" ]; then echo 'GITHUB_TOKEN is required' >&2; exit 1; fi
	docker run --rm \
		-e RUN_LOCAL=true \
		-e DEFAULT_BRANCH=main \
		-e VALIDATE_ALL_CODEBASE=false \
		-e FILTER_REGEX_EXCLUDE='$(SUPERLINTER_EXCLUDES)' \
		-e GITHUB_TOKEN=$(GITHUB_TOKEN) \
		-v $(PROJECT_ROOT):/tmp/lint \
		$(SUPERLINTER_SLIM_IMAGE)

super-lint.pull: ## Pre-pull the SuperLinter image
	docker pull $(SUPERLINTER_IMAGE)

.PHONY: super-lint super-lint.quick super-lint.pull
