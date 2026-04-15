# =============================================================================
# CI — Pipeline Aggregates & SuperLinter
# =============================================================================

## —— Aggregates ——

ci.lint: php.lint js.lint ## Run all linters (CI-friendly)

ci.test: php.test js.test ## Run all tests (CI-friendly)

ci: ci.lint ci.test ## Full CI pipeline

## —— PWA CI subset (lint + test + build) ——

ci.pwa: js.lint js.test.unit js.build ## PWA: lint + unit tests + build

## —— SuperLinter (Docker) ——

ci.superlint: ## Run SuperLinter via Docker; pass GITHUB_TOKEN=xxx
	docker run --rm \
		-e RUN_LOCAL=true \
		-e VALIDATE_ALL_CODEBASE=true \
		-e FILTER_REGEX_EXCLUDE='(vendor/|node_modules/|\.git/)' \
		$(if $(GITHUB_TOKEN),-e GITHUB_TOKEN=$(GITHUB_TOKEN)) \
		-e VALIDATE_BASH=true \
		-e VALIDATE_BASH_EXEC=true \
		-e VALIDATE_CSS=true \
		-e VALIDATE_DOCKERFILE_HADOLINT=true \
		-e VALIDATE_EDITORCONFIG=true \
		-e VALIDATE_ENV=true \
		-e VALIDATE_GITHUB_ACTIONS=true \
		-e VALIDATE_HTML=true \
		-e VALIDATE_JAVASCRIPT_ES=true \
		-e VALIDATE_JSON=true \
		-e VALIDATE_KUBERNETES_KUBECONFORM=true \
		-e VALIDATE_MARKDOWN=true \
		-e VALIDATE_NATURAL_LANGUAGE=true \
		-e VALIDATE_PHP=true \
		-e VALIDATE_PHP_PHPCS=true \
		-e VALIDATE_PHP_PHPSTAN=true \
		-e VALIDATE_PHP_PSALM=true \
		-e VALIDATE_PYTHON=true \
		-e VALIDATE_SHELL_SHFMT=true \
		-e VALIDATE_SQL=true \
		-e VALIDATE_TYPESCRIPT_ES=true \
		-e VALIDATE_XML=true \
		-e VALIDATE_YAML=true \
		-v $(PROJECT_ROOT):/tmp/lint \
		$(SUPERLINTER_IMAGE)

ci.superlint.quick: ## Run SuperLinter on changed files only
	$(MAKE) ci.superlint SUPERLINTER_VALIDATE_ALL_CODEBASE=false

ci.superlint.pull: ## Pull latest SuperLinter image
	docker pull $(SUPERLINTER_IMAGE)

.PHONY: ci.lint ci.test ci ci.pwa ci.superlint ci.superlint.quick ci.superlint.pull
