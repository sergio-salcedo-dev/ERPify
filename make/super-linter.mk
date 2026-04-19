# =============================================================================
# SuperLinter (Docker)
# =============================================================================

.PHONY: lint.super.run lint.super.run.fast lint.super.pull

GITHUB_TOKEN ?=

# Use the 'slim' version for faster downloads and GHCR for reliability
SUPERLINTER_SLIM_IMAGE ?= ghcr.io/super-linter/super-linter:slim-latest

SUPERLINTER_IMAGE ?= ghcr.io/super-linter/super-linter:latest
SUPERLINTER_VALIDATE_ALL_CODEBASE ?= true
SUPERLINTER_EXCLUDES := .*vendor/.*|.*node_modules/.*|.*\.next/.*|.*out/.*|\.git/.*|.*dist/.*|.*build/.*

lint.super.run: ## Run SuperLinter on entire codebase via Docker (all linters enabled). Pass GITHUB_TOKEN=xxx
	docker run --rm \
		-e RUN_LOCAL=true \
		-e DEFAULT_BRANCH=main \
		-e VALIDATE_ALL_CODEBASE=$(SUPERLINTER_VALIDATE_ALL_CODEBASE) \
		-e FILTER_REGEX_EXCLUDE='$(SUPERLINTER_EXCLUDES)' \
		-e IGNORE_GITIGNORED_FILES=true \
		-e COMPOSER_INSTALL=false \
		$(if $(GITHUB_TOKEN),-e GITHUB_TOKEN='$(GITHUB_TOKEN)') \
		-e YAML_CONFIG_FILE=.yamllint.yml \
		-e VALIDATE_BASH=true \
		-e VALIDATE_CSS=true \
		-e VALIDATE_DOCKERFILE_HADOLINT=true \
		-e VALIDATE_EDITORCONFIG=true \
		-e VALIDATE_ENV=true \
		-e VALIDATE_GITHUB_ACTIONS=true \
		-e VALIDATE_HTML=true \
		-e VALIDATE_JAVASCRIPT_ES=true \
		-e VALIDATE_JSON=true \
		-e VALIDATE_MARKDOWN=true \
		-e VALIDATE_PYTHON=true \
		-e VALIDATE_SQLFLUFF=true \
		-e VALIDATE_TYPESCRIPT_ES=true \
		-e VALIDATE_XML=true \
		-e VALIDATE_YAML=true \
		--env "SKIP_COMPOSER_INSTALL=true" \
		-v $(ROOT_DIR):/tmp/lint \
		-v /tmp/lint/api/vendor \
		-v /tmp/lint/api/tools/behat/vendor \
		-v /tmp/lint/node_modules \
		$(SUPERLINTER_IMAGE)

lint.super.run.fast: ## Run SuperLinter on changed files only (faster)
	$(MAKE) superlint SUPERLINTER_VALIDATE_ALL_CODEBASE=false

lint.super.pull: ## Pull latest SuperLinter image
	docker pull $(SUPERLINTER_IMAGE)
