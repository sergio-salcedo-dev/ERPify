# Cursor Rules

This directory contains project-specific rules for Cursor AI Agent. These rules follow Cursor's recommended best practices and use the MDC (Markdown with metadata) format.

## Overview

Rules are split into focused, composable files, each under 500 lines, following Cursor's recommendations:

### Always Applied Rules

These rules are applied to every chat session:

- **`role.mdc`** - Core role and development principles for backend software development
- **`solid-principles.mdc`** - SOLID principles application guidelines
- **`clean-code.mdc`** - Clean Code principles and best practices
- **`security.mdc`** - Security best practices and pre-commit security checks
- **`commits.mdc`** - Commit message conventions and pre-commit hooks
- **`architecture.mdc`** - Clean Architecture principles and goals

### Context-Specific Rules

These rules are applied intelligently based on file patterns or when relevant:

- **`php-standards.mdc`** - Applied to PHP files (`**/*.php`)
- **`frontend.mdc`** - Applied to frontend files (HTML, CSS, JS/TS)
- **`testing.mdc`** - Applied when working with tests
- **`database.mdc`** - Applied when working with database-related code

## Rule File Format

Each rule file uses MDC format with metadata:

- `description` - Brief description of what the rule covers
- `alwaysApply` - Whether the rule applies to every chat session
- `globs` - File patterns that trigger the rule (when `alwaysApply: false`)

## Migration

The project previously used a single `.cursorrules` file (legacy format). This has been migrated to the new `.cursor/rules` structure for better organization and maintainability.

The legacy `.cursorrules` file is kept for backward compatibility but is marked as deprecated.

## References

- [Cursor Rules Documentation](https://cursor.com/docs/context/rules)
- [MDC Format](https://cursor.com/docs/context/rules#anatomy-of-a-rule)

## Agent Instructions

The project also uses nested `AGENTS.md` files for area-specific instructions:

- **Root `AGENTS.md`** - Global project instructions
- **`app/backend/AGENTS.md`** - Backend-specific instructions (Clean Architecture, PHP conventions)
- **`app/frontend/AGENTS.md`** - Frontend-specific instructions
- **`docker/AGENTS.md`** - Docker configuration instructions

These nested `AGENTS.md` files complement the `.cursor/rules` by providing contextual, area-specific guidance.
Instructions from nested files combine with parent directories, with more specific instructions taking priority.

## Guidance

- Keep each rule file under 500 lines
- Make rules concrete and actionable
- Use file references with `@filename` syntax when needed
- Update rules when project standards change
- Use nested `AGENTS.md` files for area-specific instructions that complement global rules
