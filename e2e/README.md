# `e2e/` — Playwright tests

This directory will hold **Playwright** end-to-end tests: automated checks that run a real browser (or API-only flows) against running services—usually the Next.js app in [`../pwa/`](../pwa/) and/or the Symfony API in [`../api/`](../api/).

Use this suite for journeys that span more than one package or that need a full stack (login, navigation, critical paths). PHPUnit and Behat under `api/` remain the place for API-focused tests inside the backend.

The Playwright project is not initialized here yet (`package.json`, `playwright.config.ts`, and specs will land when the suite is bootstrapped).

See the [root README](../README.md) for how the monorepo fits together.
