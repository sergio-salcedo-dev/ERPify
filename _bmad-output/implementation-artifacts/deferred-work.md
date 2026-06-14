# Deferred work

## Deferred from: code review of iam-user-management-frontend plan — group 1 cores (2026-06-14)

- Empty-page recovery in `useResourceList` (useResourceList.ts:178-183) can issue redundant `follow()` calls on a tail-emptied page. Bounded (terminates at offset 0; mock-only deterministic data). Harden with a visited-link/attempt guard and clamp `searchAt` offset to the result length.
- `useQueryState.reset()` (createQueryState.ts:32-35) does not reset `pageSize` although its doc describes a "single reset" over filter/sort/pageSize. Either reset page size or adjust the doc — low priority, page size is arguably a viewing preference.

## Deferred from: code review of iam-user-management-frontend plan — group 2 user module (2026-06-14)

- `UserEditSchema` (pwa/src/context/backoffice/user/application/schemas/UserEditSchema.ts) is unused — referenced only in a `UserFormSchema` comment; the form validates with `UserFormSchema`. It documents the intended API edit contract but is dead per "minimum code, nothing speculative." Decide later: wire edit-mode validation to it, or remove it.

## Deferred from: code review of iam-user-management-frontend plan — group 3 users UI (2026-06-14)

- Stale `focusedRow` after an optimistic delete in `UsersStackedList`/`BanksStackedList` (roving tabindex can land past the shrunk array, losing keyboard focus). Pre-existing pattern shared with the Bank reference — clamp `focusedRow` on `users.length` change in both as a cross-cutting a11y fix.
- `query.pageSize as UsersPageSize` cast in the users list page launders the type; safe today (page size only set from the constrained dropdown). Replace with a `USERS_PAGE_SIZE_OPTIONS` membership guard if/when page size becomes URL/storage-hydrated.

## Deferred from: code review of iam-user-management-frontend plan — group 4 auth + wiring (2026-06-14)

- Auth forms (login/register/forgot/reset) lack the server-violation `setError(field,{type:"server"})` API-ready seam the spec lists; pure mocks emit no violations, so it would be dead scaffolding now. Wire it when the real auth API lands (UserForm has the pattern).
- No `?next=`/return-URL after login: RequireAuth redirects to /login without preserving the target and LoginForm always pushes Routes.BACKOFFICE; deep-links are lost. When added, validate the target same-origin/relative (no open redirect).
- Reset-password flow ignores any token: needs useSearchParams under a Suspense boundary (Next 16) + opaque-token validation + missing/expired-token boundary when wired to the real API.
