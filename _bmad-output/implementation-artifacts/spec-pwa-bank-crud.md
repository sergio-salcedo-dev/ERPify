---
title: 'PWA Backoffice Bank CRUD frontend'
type: 'feature'
created: '2026-05-08'
status: 'done'
baseline_commit: 'f0d2a38'
context:
  - pwa/CLAUDE.md
  - pwa/AGENTS.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The Backoffice Bank API (`GET/POST /api/v1/backoffice/banks` and `GET/PUT/DELETE /banks/{id}`) has no PWA UI; operators cannot manage banks from the app.

**Approach:** New PWA bounded context `backoffice/bank/{domain,application,infrastructure}` consuming the existing API through the shared `HttpClient` (write methods + ProblemDetails translation already merged on this branch). Dedicated App Router pages for list / new / detail / edit, a single shared `<BankForm>` for create + edit, and an AlertDialog for delete. Reuse the in-house `erpify` kit (`DataTable`, `FormField`, `EmptyState`, `ProblemDisplay`, `CorrelationIdChip`). Sidebar gains a `Catalogs` group with a `Banks` entry.

## Boundaries & Constraints

**Always:**
- DDD layering per `pwa/CLAUDE.md`: `domain` (pure types + interfaces; no Next/Inversify/HTTP/ORM imports), `application` (`@injectable` use cases), `infrastructure` (HTTP adapter).
- API errors arrive as `HttpError` carrying `ProblemDetails`; surface 422 violations via `<FormField violations>` and non-422 via `<ProblemDisplay>` + `<CorrelationIdChip>`. No parallel error type.
- Bind every new use case in `Container.ts` with the existing `BackOffice<UseCase>` symbol pattern.
- Pages live under `pwa/src/app/backoffice/banks/` using dedicated routes (list / new / [id] / [id]/edit). List + detail + edit are **server components** that resolve the container and `await` the use case; the form and delete dialog are `"use client"`.
- Strict TS (no `any` outside narrow type-narrowing); BEM class naming on JSX; Tailwind 4 + Shadcn.
- `BankForm` is one component reused for both create and edit; submit handler differs by `mode`.
- E2E spec uses Playwright `page.route(...)` to return the **legacy** `JsonApiErrorBuilder` envelope on errors (verifies the HttpClient translator end-to-end).

**Ask First:**
- Adding write-method usage to a context other than Bank in this PR.
- Introducing a third-party form/state library (RHF, Formik, etc.) — keep `useState` controlled forms.
- Changing the existing `Container.ts` binding shape (e.g., switching to symbol identifiers) beyond adding new bindings.

**Never:**
- Image / Media / StoredObject upload (PWA sends JSON `{name, shortName}` only; the API multipart fields are ignored).
- Editing any API/Symfony code or `legacyEnvelope.ts`/`HttpClient.ts` (those are the prior PR's surface).
- Vitest unit tests in this iteration (E2E-only per user).
- Cursor pagination polish — show first page only; render `[Load more]` button only when `meta.nextCursor` exists.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| List happy | `GET /banks` 200 with rows | DataTable cols: shortName, name, updatedAt; row click → detail | n/a |
| List empty | `GET /banks` 200 with `data:[]` | `<EmptyState>` "No banks yet" + `[+ New bank]` link | n/a |
| List server error | `GET /banks` 5xx legacy envelope | `<ProblemDisplay variant="panel">` | translator handles |
| Create happy | `POST` `{name, shortName}` 201 | `router.push('/backoffice/banks/' + id)` | n/a |
| Create 422 | legacy `{errors:[{source.parameter,title}],meta.requestId}` | Form keeps state; `<FormField violations>` per field | translator → `violations[]` |
| View happy | `GET /{id}` 200 | Detail page with name, shortName, timestamps + `[Edit]` + `[Delete]` | n/a |
| View 404 | legacy 404 envelope | `<EmptyState>` "Bank not found" + `<CorrelationIdChip>` | translator |
| Edit happy | `PUT /{id}` 200 | `router.push('/backoffice/banks/' + id)` | n/a |
| Edit 422 | legacy 422 envelope | Form keeps state; per-field violations | translator |
| Delete happy | `DELETE /{id}` 204 | AlertDialog confirm → `router.push('/backoffice/banks')` | n/a |
| Delete 404 | legacy 404 envelope | Stay on detail page; `<ProblemDisplay>` inline | translator |

</frozen-after-approval>

## Code Map

- `pwa/src/context/shared/infrastructure/ApiRoutes.ts` — add `backoffice.banks: { list, byId(id) }`.
- `pwa/src/context/backoffice/bank/domain/Bank.ts` — entity (id, name, shortName, createdAt, updatedAt) + `fromPrimitives`.
- `pwa/src/context/backoffice/bank/domain/BankRepository.ts` — interface (search, find, create, update, delete) + `BankSearchPage` type.
- `pwa/src/context/backoffice/bank/application/{SearchBanks,FindBank,CreateBank,UpdateBank,DeleteBank}.ts` — five `@injectable` use cases.
- `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts` — `HttpClient`-backed adapter.
- `pwa/src/context/shared/infrastructure/DependencyInjection/Container.ts` — bind `BackOfficeBankRepository` + 5 use cases.
- `pwa/src/app/backoffice/banks/page.tsx` — list (server).
- `pwa/src/app/backoffice/banks/new/page.tsx` — create page → `<BankForm mode="create">`.
- `pwa/src/app/backoffice/banks/[id]/page.tsx` — detail (server) + `<DeleteBankButton>`.
- `pwa/src/app/backoffice/banks/[id]/edit/page.tsx` — edit (server) → `<BankForm mode="edit" initial>`.
- `pwa/src/app/backoffice/banks/_components/BankForm.tsx` — `"use client"` form (name + shortName); submits via Create or Update use case.
- `pwa/src/app/backoffice/banks/_components/DeleteBankButton.tsx` — `"use client"` button + AlertDialog confirm + Delete use case + redirect.
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` — add `Catalogs` `NavGroup` above `System`; entry `{ name: "Banks", icon: Building2, path: "/backoffice/banks" }`.
- `pwa/tests/e2e/fixtures/banks-api.ts` — Playwright `page.route(...)` helpers returning the legacy envelope.
- `pwa/tests/e2e/backoffice/banks.spec.ts` — happy-path + error-state specs.

## Tasks & Acceptance

**Execution:**
- [x] `ApiRoutes.ts` — extend with `backoffice.banks: { list: '/api/v1/backoffice/banks', byId: (id) => \`/api/v1/backoffice/banks/${id}\` }`.
- [x] `bank/domain/Bank.ts` — readonly fields + `static fromPrimitives({id,name,shortName,createdAt,updatedAt})`.
- [x] `bank/domain/BankRepository.ts` — `search(): Promise<BankSearchPage>`, `find(id)`, `create({name,shortName})`, `update(id,{name,shortName})`, `delete(id)`. `BankSearchPage = { banks: Bank[]; nextCursor?: string }`.
- [x] `bank/application/SearchBanks.ts` — injects `BackOfficeBankRepository`; returns `BankSearchPage`.
- [x] `bank/application/{FindBank,CreateBank,UpdateBank,DeleteBank}.ts` — thin orchestration; let `HttpError` propagate to UI.
- [x] `bank/infrastructure/ApiBankRepository.ts` — implements interface via `@inject("HttpClient")`. Reads `data` for create/update/find; `{ data, meta?: { nextCursor? } }` for list; void for delete.
- [x] `Container.ts` — bind `BackOfficeBankRepository` to `ApiBankRepository`; bind 5 use cases (`BackOfficeSearchBanks`, `BackOfficeFindBank`, `BackOfficeCreateBank`, `BackOfficeUpdateBank`, `BackOfficeDeleteBank`).
- [x] `app/backoffice/banks/page.tsx` — server component: `await container.get<BackOfficeSearchBanks>(...).run()`. Render `<DataTable>` with view-link cells; `<EmptyState>` fallback; `[+ New bank]` `<Link>`. Catch `HttpError` → `<ProblemDisplay variant="panel">`.
- [x] `app/backoffice/banks/new/page.tsx` — server wrapper rendering `<BankForm mode="create">`.
- [x] `app/backoffice/banks/[id]/page.tsx` — server: resolve `FindBank`. On `HttpError` with `problem.status===404`, render `<EmptyState>` + `<CorrelationIdChip id={problem["correlation-id"]}>`. Otherwise show details + `<Link>` Edit + `<DeleteBankButton id={...}>`.
- [x] `app/backoffice/banks/[id]/edit/page.tsx` — server: resolve `FindBank`; pass `initial={bank}` into `<BankForm mode="edit">`. Same 404 fallback.
- [x] `app/backoffice/banks/_components/BankForm.tsx` — controlled (`useState`) form; `name` (`<FormField>` required) and `shortName` (required). Submit: `BackOfficeCreateBank` or `BackOfficeUpdateBank` based on `mode`; on `HttpError`, if `problem.status===422` set `violations` state, else set `problem` state for `<ProblemDisplay>`. On success, `router.push('/backoffice/banks/' + bank.id)`.
- [x] `app/backoffice/banks/_components/DeleteBankButton.tsx` — Shadcn `Dialog` confirm. On confirm, `BackOfficeDeleteBank.run(id)` → `router.push('/backoffice/banks')`. On `HttpError`, render `<ProblemDisplay>` inline (no redirect).
- [x] `BackOfficeLayoutClient.tsx` — insert `{ label: "Catalogs", items: [{ name: "Banks", icon: Building2, path: "/backoffice/banks" }] }` above the `System` group; same entry appears in mobile sheet.
- [x] `tests/e2e/fixtures/banks-api.ts` — `mockBanksApi(page, scenario)` registering routes via `page.route(predicate, …)` for both list and item paths with success/error fixtures (legacy envelope on errors).
- [x] `tests/e2e/backoffice/banks.spec.ts` — specs written: list happy, list empty, view happy, view 404 (EmptyState + CorrelationIdChip), create happy, create 422, edit happy, edit 422, delete happy, delete 404 (ProblemDisplay), nav. **Won't run green as-is — see Spec Change Log.**

**Acceptance Criteria:**
- Given the API returns rows on `/banks`, when the user visits `/backoffice/banks`, then the DataTable shows shortName/name/updatedAt and a `[+ New bank]` link, and clicking a row navigates to `/backoffice/banks/<id>`.
- Given the user submits create with `name=""`, when the API returns the legacy 422 envelope with `errors[].source.parameter="name"`, then the `<FormField name="name">` shows the API message and the form retains user input; `<ProblemDisplay>` is **not** shown for the same 422.
- Given the user opens `/backoffice/banks/<unknown-id>`, when the API returns the legacy 404 envelope, then the page shows `<EmptyState>` "Bank not found" plus `<CorrelationIdChip>` carrying the translated `meta.requestId`.
- Given the user clicks Delete on the detail page and confirms, when the API returns 204, then the user is redirected to `/backoffice/banks` and the row is gone (per the next list response); if the API returns 404, the dialog shows `<ProblemDisplay>` inline and the user stays on the detail page.
- Given a future API release returns RFC 9457 ProblemDetails verbatim, when the same flows run, then no UI change is needed (the translator passes through unchanged).
- "Banks" appears under a new `Catalogs` sidebar group on both desktop and mobile-sheet layouts.
- `make pwa.lint`, `make pwa.test.e2e c='backoffice/banks.spec.ts'`, and `make pwa.build` all pass.

## Spec Change Log

### 2026-05-08 — Server-component fetch is incompatible with Playwright `page.route`

**Triggering finding:** the spec mandates list / detail / edit as server components AND mocks the Bank API via `page.route(...)` in Playwright. Playwright's `page.route` only intercepts browser-level network requests. Server-component data fetching runs inside the Next.js Node process, never touching the browser, so `page.route` cannot influence those responses. The E2E suite committed in this PR therefore will not run green without a follow-up.

**What was amended:** nothing in `<frozen-after-approval>`. Implementation matches the spec exactly. Marker added in the Tasks Execution checklist that the E2E suite needs follow-up.

**Known-bad state avoided:** silently changing the architecture to "use client" pages just to satisfy the test transport. Both ProblemDetails translation and DDD layering remain intact; the fix is at the test-transport layer, not the production code.

**KEEP instructions (must survive re-derivation):**
- The translator → ProblemDetails contract that powers `<FormField violations>` and `<ProblemDisplay>` is correct and must not change.
- DI symbols `BackOffice<UseCase>` are stable; consumers in pages and forms must keep using them.
- The `Catalogs > Banks` nav placement and mobile-sheet duplication is correct.

**Follow-up options for the next iteration (pick one):**
1. Replace the server-side fetch with client-side data fetching in list/detail/edit (e.g. `useEffect` + `AsyncBoundary`); `page.route` then intercepts as written.
2. Introduce MSW with the node interceptor in the Playwright global-setup so server-side fetches route through MSW handlers.
3. Run the E2E suite against a real Bank API (Compose stack up) and treat the legacy-envelope assertions as integration tests rather than unit-mocked E2E.

## Design Notes

**Server-component container access:** Pages import `{ container }` from the shared DI module and call `container.get<UseCase>("BackOfficeXxx")` at module body / inside the page function. The DI container is already a process-wide singleton; no per-request scoping is needed for these pages.

**Auth/session:** out of scope — cookies pass through Next's default fetch behavior; no special headers added.

**E2E fixture shape (golden 422):**
```
{ errors: [{ source: { parameter: "name" }, title: "The name field is required." }],
  meta:   { requestId: "01H-correlation" } }
```

## Verification

**Commands:**
- `make pwa.lint` — expected: 0 ESLint/Prettier errors.
- `make pwa.test.e2e c='backoffice/banks.spec.ts'` — expected: all specs green.
- `make pwa.build` — expected: build succeeds.
