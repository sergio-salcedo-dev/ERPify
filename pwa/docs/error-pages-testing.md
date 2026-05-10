# Error pages — local testing guide

The PWA ships nine branded error surfaces; this doc tells you how to
exercise each one locally and how to verify that the production-mode
redaction actually hides what it must hide.

If anything below contradicts the code, fix the code: the tests in
[`pwa/tests/e2e/error-pages.spec.ts`](../tests/e2e/error-pages.spec.ts)
are the executable contract.

## TL;DR — manual smoke-test

```bash
make dev   # full Compose stack on https://localhost
```

Then open each URL in a browser:

| Surface                 | URL                                     | Trigger               |
| ----------------------- | --------------------------------------- | --------------------- |
| 404 — Page not found    | `https://localhost/this-does-not-exist` | unmatched route       |
| 401 — Sign in required  | `https://localhost/unauthenticated`     | direct navigation     |
| 403 — Access denied     | `https://localhost/unauthorized`        | direct navigation     |
| 503 — Maintenance       | `https://localhost/maintenance`         | direct navigation     |
| 429 — Too many requests | `https://localhost/rate-limited`        | direct navigation     |
| Offline (PWA)           | `https://localhost/offline`             | direct navigation     |
| 500 — Boundary          | `https://localhost/dev-throw`           | dev/test-only fixture |

For the BackOffice context (primary CTA flips to `Return to BackOffice`),
prefix the URL with `/backoffice` — e.g. `/backoffice/anything`.

## What lives where

```
src/context/shared/error/
├── domain/IconTone.ts                 ← pure domain types
└── infrastructure/ui/
    ├── ErrorScreen.tsx                ← responsive shell (no "use client")
    └── ErrorActions.tsx               ← Client Component, usePathname-aware

src/app/
├── error.tsx                          ← 500 boundary (segment-level)
├── global-error.tsx                   ← 500 boundary (RootLayout crash)
├── not-found.tsx                      ← 404
├── forbidden.tsx                      ← Next 15+ forbidden() (re-exports 403 UI)
├── unauthorized.tsx                   ← Next 15+ unauthorized() (re-exports 401 UI)
└── (errors)/
    ├── dev-throw/page.tsx               ← dev-only 500 trigger
    ├── maintenance/page.tsx           ← /maintenance
    ├── offline/page.tsx               ← /offline
    ├── rate-limited/page.tsx          ← /rate-limited
    ├── unauthenticated/page.tsx       ← /unauthenticated
    └── unauthorized/page.tsx          ← /unauthorized
```

## Inline `<ProblemDisplay>` is _not_ the error page

`/backoffice/banks` and other CRUD routes catch `HttpError` thrown by
their use cases and surface the RFC 9457 problem inline via
`<ProblemDisplay>` — see `app/backoffice/banks/page.tsx` and
[`docs/api-error-contract.md`](../../docs/api-error-contract.md).
A backend 500 from `BankSearchController.php` therefore renders the
inline panel, **not** `app/error.tsx`. That's by design. To exercise
`error.tsx` itself you need an actual uncaught render error — that's
what `/dev-throw` is for.

## Triggering the 500 boundary on demand

`src/app/(errors)/dev-throw/page.tsx` is a server component that throws
synchronously when `process.env.NODE_ENV !== NodeEnv.PRODUCTION` and
`notFound()`s otherwise. Visit it any time the dev or test stack is up:

```bash
make dev
open https://localhost/dev-throw
```

You should see:

- the destructive icon badge
- status `Error 500`, title `Something went wrong`
- the dev-only `error.message` block containing the fixture string
- a non-empty Error ID with the green-check copy button
- three buttons: `Try again` (filled), `Return home` (outline), `Go back` (outline)

`Try again` calls Next's `reset()` and re-renders the failing subtree.
Because the page always throws in dev, `Try again` lands you back on
the same boundary — that's expected.

## Triggering the global-error boundary

`app/global-error.tsx` only fires when `RootLayout` itself crashes.
There's no debug fixture for it because we don't want to ship a route
that can break the layout. To exercise it manually:

1. Edit `pwa/src/app/layout.tsx` — add `throw new Error("boom")` at
   the top of `RootLayout`.
2. Reload any page.
3. Revert the change before committing.

## Production policy: only generic messages

In `NODE_ENV=production` every error surface MUST render only generic,
hand-written copy. Concretely:

- ✅ static title / description / status / icon (`Something went wrong`,
  `Error 500`, …)
- ✅ the opaque `error.digest` correlation hash (Next-generated, no
  source content; users need it to quote support)
- ❌ the original `error.message`
- ❌ stack traces, framework / runtime class names, file paths, line
  numbers, internal route handlers
- ❌ PII, secrets, tokens, environment values, request bodies, header
  contents

This rule is locked by
[`tests/app/error-redaction.test.tsx`](../tests/app/error-redaction.test.tsx),
which renders `error.tsx` and `global-error.tsx` with
`vi.stubEnv("NODE_ENV", "production")` plus a deliberately sensitive
fixture string and asserts none of it reaches the DOM. If you need to
loosen the policy, change the test FIRST.

## Verifying production redaction end-to-end

The unit test above proves the boundary gates correctly given a
runtime `NODE_ENV`. To additionally prove the build pipeline doesn't
introduce surprises, run the boundaries against a real production
build:

```bash
# 1. Build the PWA in production mode.
cd pwa && npm run build

# 2. Serve it. The throw fixture will 404 in production, so we need a
#    different way to trigger the boundary — re-introduce a temporary
#    `throw` inside `app/error.tsx`'s sibling page, or dispatch an
#    uncaught error in any server component you control.
NODE_ENV=production npm start
```

Then re-visit the broken URL and confirm:

- ✅ status `Error 500` and the generic title / description render
- ✅ the Error ID is shown (digest is safe — it's a hash, not source)
- ❌ no `error-page__details` block (`error.message` MUST be hidden)
- ❌ no Symfony / framework details, file paths, line numbers, class
  names, or stack traces in the rendered HTML
- ❌ no PII, secrets, tokens, or environment values

If any of those fail, treat it as a security regression — see the root
[`CLAUDE.md`](../../CLAUDE.md) "Security review on every change"
checklist and `PRODUCTION_SECURITY_CHECKLIST.md`.

The same redaction applies to the API problem envelope: in
non-development environments the `debug` block (which leaks
`exception_class`, `file`, `line`, `previous_chain`) must be omitted.
Cross-check against [`docs/api-error-contract.md`](../../docs/api-error-contract.md).

## Running the automated coverage

```bash
# Full E2E suite (Docker stack required).
make pwa.test.e2e

# Just this file.
make pwa.test.e2e c='tests/e2e/error-pages.spec.ts'

# Single test inside the file.
make pwa.test.e2e c='tests/e2e/error-pages.spec.ts -g "renders the error.tsx boundary"'
```

The spec runs against the dev Compose stack
(`process.env.NODE_ENV === "development"`), so it asserts dev-mode
visibility of `error.message`. Production redaction is verified
manually following the procedure above; if you wire a prod-mode CI job
later, branch the assertion on `NODE_ENV` rather than duplicating the
spec.

## Adding a new error page

1. Pick an HTTP status / scenario.
2. Add a `data-testid` prefix that's unique across `src/`
   (the [`tests/data-testid-uniqueness.test.ts`](../tests/data-testid-uniqueness.test.ts)
   guard will catch collisions).
3. Drop a route under `app/(errors)/<slug>/page.tsx` (or a Next
   boundary file at the right layer) and render
   `<ErrorScreen testIdPrefix="…" status="…" title="…" description="…"
icon={…} iconTone={IconTone.…} actions={<ErrorActions />} />`.
4. Add a row to `STATIC_PAGES` in
   [`pwa/tests/e2e/error-pages.spec.ts`](../tests/e2e/error-pages.spec.ts)
   so coverage tracks the new surface automatically.
