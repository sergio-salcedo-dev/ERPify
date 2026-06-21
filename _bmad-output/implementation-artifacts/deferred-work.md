# Deferred work

## Deferred from: code review of spec-pwa-components-boundary-remediation (2026-06-21)

- PWA component-layer gate (`pwa/eslint.config.mjs`) — latent coverage gaps, hardening only (no current break): (a) `components/ui/**` can still import arbitrary top-level `@/components/<X>` siblings since only the `erpify` subtree is banned, and `cn.ts` is matched by no `files` glob so no rule guards it; (b) the `ui → @/context` ban has no `allowTypeImports` carve-out, so a future `ui` primitive needing a `@/context/shared/**` *type* would be blocked. Both purely hypothetical with today's `cn`/`ui`/`erpify`-only `@/components` layer.

- Revive the commented-out `api/features/backoffice/bank/create_with_logo.feature` and `create_with_stored_object.feature`. Needs **new** Behat PHP steps (multipart upload, remember-JSON-field + `{placeholder}` substitution, async-messenger processing, transport-empty, notification-email assertion, GET-stored-URL + `Content-Type`/`ETag`/`304`). Own spec/PR — out of scope of the bank Behat test-hardening work.
