# =============================================================================
# SuperLinter — GitHub SuperLinter (run locally via Docker).
# =============================================================================

.PHONY: super-lint.full super-lint.slim super-lint.fast super-lint.pull

GITHUB_TOKEN                      ?=
SUPERLINTER_IMAGE                 ?= ghcr.io/super-linter/super-linter:latest
# Use the 'slim' version for faster downloads and GHCR for reliability
SUPERLINTER_SLIM_IMAGE            ?= ghcr.io/super-linter/super-linter:slim-latest
SUPERLINTER_VALIDATE_ALL_CODEBASE ?= true
# Gitignored paths (the project-root tmp/ scratch dir, build output, …) are skipped via
# IGNORE_GITIGNORED_FILES below, not this regex: the repo mounts at /tmp/lint, so a bare `tmp`
# here would match the mount prefix and exclude the whole workspace.
SUPERLINTER_EXCLUDES              ?= (^|/)(vendor|node_modules|var|public/bundles|\.next)/
#SUPERLINTER_EXCLUDES := .*vendor/.*|.*node_modules/.*|.*\.next/.*|.*out/.*|\.git/.*|.*dist/.*|.*build/.*|.*_bmad-output/.*|api/config/reference\.php|api/migrations/.*|api/tools/.*

super-lint.full: ## Run SuperLinter over the whole repo (requires GITHUB_TOKEN)
	@if [ -z "$(GITHUB_TOKEN)" ]; then echo 'GITHUB_TOKEN is required' >&2; exit 1; fi
	docker run --rm \
		-e RUN_LOCAL=true \
		-e DEFAULT_BRANCH=main \
		-e VALIDATE_ALL_CODEBASE=$(SUPERLINTER_VALIDATE_ALL_CODEBASE) \
		-e FILTER_REGEX_EXCLUDE='$(SUPERLINTER_EXCLUDES)' \
		-e IGNORE_GITIGNORED_FILES=true \
		-e GITHUB_TOKEN=$(GITHUB_TOKEN) \
		-v $(PROJECT_ROOT):/tmp/lint \
		$(SUPERLINTER_IMAGE)

super-lint.slim: ## SuperLinter on changed files only (slim image)
	@if [ -z "$(GITHUB_TOKEN)" ]; then echo 'GITHUB_TOKEN is required' >&2; exit 1; fi
	docker run --rm \
		-e RUN_LOCAL=true \
		-e DEFAULT_BRANCH=main \
		-e VALIDATE_ALL_CODEBASE=false \
		-e FILTER_REGEX_EXCLUDE='$(SUPERLINTER_EXCLUDES)' \
		-e IGNORE_GITIGNORED_FILES=true \
		-e GITHUB_TOKEN=$(GITHUB_TOKEN) \
		-v $(PROJECT_ROOT):/tmp/lint \
		$(SUPERLINTER_SLIM_IMAGE)

super-lint.fast: ## Run SuperLinter on changed files only (faster)
	$(MAKE) SUPERLINTER_VALIDATE_ALL_CODEBASE=false super-lint.full

super-lint.pull: ## Pre-pull the SuperLinter image
	docker pull $(SUPERLINTER_IMAGE)

#lint.super.run: ## Run SuperLinter on entire codebase via Docker (all linters enabled). Pass GITHUB_TOKEN=xxx
#	docker run --rm \
#		-e RUN_LOCAL=true \
#		-e DEFAULT_BRANCH=main \
#		-e VALIDATE_ALL_CODEBASE=$(SUPERLINTER_VALIDATE_ALL_CODEBASE) \
#		-e FILTER_REGEX_EXCLUDE='$(SUPERLINTER_EXCLUDES)' \
#		-e IGNORE_GITIGNORED_FILES=true \
#		-e COMPOSER_INSTALL=false \
#		$(if $(GITHUB_TOKEN),-e GITHUB_TOKEN='$(GITHUB_TOKEN)') \
#		-e YAML_CONFIG_FILE=.yamllint.yml \
#		-e MARKDOWN_CONFIG_FILE=.markdownlint.json \
#		-e VALIDATE_BASH=true \
#		-e VALIDATE_DOCKERFILE_HADOLINT=true \
#		-e VALIDATE_EDITORCONFIG=true \
#		-e VALIDATE_ENV=true \
#		-e VALIDATE_GITHUB_ACTIONS=true \
#		-e VALIDATE_HTML=true \
#		-e VALIDATE_JSON=true \
#		-e VALIDATE_MARKDOWN=true \
#		-e VALIDATE_PYTHON=true \
#		-e VALIDATE_SQLFLUFF=true \
#		-e VALIDATE_XML=true \
#		-e VALIDATE_YAML=true \
#		--env "SKIP_COMPOSER_INSTALL=true" \
#		-v $(ROOT_DIR):/tmp/lint \
#		-v /tmp/lint/api/vendor \
#		-v /tmp/lint/api/tools/behat/vendor \
#		-v /tmp/lint/node_modules \
#		$(SUPERLINTER_IMAGE)
