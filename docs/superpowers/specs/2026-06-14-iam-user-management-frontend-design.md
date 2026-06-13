# Design — IAM & User Management (frontend-only, mocked)

Status: ready-for-dev · 2026-06-14 · branch `feat/pwa-iam-user-management-u5xv`

Frontend-only delivery: the Identity & Access layer plus User CRUD and the public
auth pages, fully mocked (in-memory repositories, no backend, no real auth). The
Bank module (`pwa/src/context/backoffice/bank/`, `app/backoffice/banks/`) is the
reference for every CRUD and UI pattern. Bank itself is **not touched**.

Conceptual North Star: the ERPify IAM Blueprint v1 (RBAC + permissions + business
context / ABAC-lite, multi-portal). We build the *conceptual model* and a lean
*execution core*, not the v2 policy engine.

## Non-negotiable principles

- **Identity ≠ business domain.** `User` is authentication/authorization only;
  it never represents Employee/Customer/Supplier. Those are future aggregates —
  here only conceptual **type seams** exist (`BusinessContext`, status enums),
  no modules, no billing/HR logic, no automatic role inference.
- **Access-level statuses are separate from business-level statuses, even when
  the names overlap.** `UserStatus` (ACTIVE/BLOCKED/PENDING) is an *access-control
  primitive* and lives in the IAM layer (`context/shared/access/domain`); it gates
  authentication, session validity, and the global access decision. Future
  `CustomerStatus` (SUSPENDED/BLOCKED_FINANCIAL) and `EmployeeStatus`
  (TERMINATED/ON_LEAVE) are *business* states and will live in their own
  Customer/Employee domains — **never reuse `UserStatus` for them**, however
  similar they sound. A business state may *influence* access only through the
  `domainPolicyAllow` seam, never by mutating `UserStatus` directly (except the
  blueprint's one documented sync: a terminated employee ⇒ `User.status = BLOCKED`,
  which is v2 and out of scope here).

| Concept | Location |
|---|---|
| `UserStatus` (ACTIVE/BLOCKED/PENDING) | IAM — `context/shared/access/domain` |
| `CustomerStatus` (SUSPENDED/BLOCKED_FINANCIAL) | Customer domain (future) |
| `EmployeeStatus` (TERMINATED/ON_LEAVE) | Employee domain (future) |
- **Repeated logic → abstract; repeated structure → do NOT abstract yet.** Build
  an *execution core*, not an *entity framework*. (See memory
  `crud-toolkit-execution-core`.)
- **Backend-swap-safe.** Domain ports are identical to a future real backend; the
  mock fills them. Swapping to Symfony later = add an `Api*Repository` and rebind
  in the DI container — zero page/component refactor.
- **Mocked, not backend-real.** No JWT/OAuth, no real validation, no persistence
  beyond optional localStorage convenience.

## Decisions (resolved forks)

1. **Routes:** canonical `/backoffice/users` (codebase convention; sidebar already
   wired under System → Configuration → Users). The blueprint's `/administration`
   maps to `Routes.BACKOFFICE` = `/backoffice`. Login redirects to `/backoffice`.
2. **Data fidelity:** identical domain ports to Bank (cursor-only keyset envelope
   `PageEnvelope` + opaque navigation links + a search navigator). The in-memory
   repo fabricates opaque offset-encoded cursor links. **No Mercure realtime** on
   the list (no event source) — the only behavioral deviation from Bank.
3. **IAM:** session-driven mocked `AuthProvider` + `useSession`, seeded ADMIN
   session, dev-only switcher, pure ALLOW evaluator, `useCan`/`<Can>` applied
   explicitly (not a global guard framework).
4. **Reuse:** lean CRUD execution core (`useResourceList/Item/Mutations`,
   `CrudRepository`, `InMemoryCrudRepository`, `createQueryState`). UI structure
   (tables/forms/filters/columns/badges) stays per-entity and explicit.
5. **Column picker:** new list feature — Popover + checkboxes from existing
   primitives, persisted via `useStoredPreference`, `email` pinned. No new DS
   component.
6. **AccessPolicyRegistry:** an **empty seam marker** only (no logic) for v2.

## Layer 1 — Access / IAM toolkit (`context/shared/access/`)

Entity-agnostic. The IAM execution core.

```
domain/
  Role.ts            SUPER_ADMIN | ADMIN | EMPLOYEE | CUSTOMER | SUPPLIER (const map + type)
  Permission.ts      known perms (users.read|write|delete, projects.read|write,
                     invoices.read|write) + WILDCARD "*"; Permission type
  AccessContext.ts   BACKOFFICE | CUSTOMER_PORTAL | EMPLOYEE_PORTAL | SUPPLIER_PORTAL
  UserStatus.ts      ACTIVE | BLOCKED | PENDING  (shared by access + user domain)
  BusinessContext.ts conceptual seam: optional { employeeStatus?, customerStatus? }
                     + EmployeeStatus/CustomerStatus enums (types only, no modules)
  Session.ts         Session { user, roles: Role[], permissions: Permission[],
                     context: AccessContext, business?: BusinessContext }
  authorize.ts       pure ALLOW evaluator:
                       allow(session, permission) =
                         session.user.status === ACTIVE
                         && hasPermission(session, permission)   // "*" satisfies all
                         && roleContextValid(session)            // role allowed in context
                         && domainPolicyAllow(session, permission) // STUB → true (v2)
  AccessPolicyRegistry.ts  empty seam marker (exported, no entries, no logic)
application/
  useSession.ts      { session, login(mockUser), logout(), override(partial) }
  useCan.ts          useCan(permission) / useCanRole(role) → boolean (wraps authorize)
infrastructure/ui/
  AuthProvider.tsx       "use client"; seeds DEV_SESSION (ADMIN, perms ["*"],
                         status ACTIVE, context BACKOFFICE); optional localStorage
                         hydration (hydration-safe, like useStoredPreference)
  Can.tsx                <Can permission="users.write">…</Can> / <Can role>…; renders
                         null when denied (hide, never error)
  RequireAuth.tsx        guard wrapper: !session || status !== ACTIVE → redirect /login
  DevSessionSwitcher.tsx dev-only (isDevToolsAvailable): switch role / status /
                         toggle permissions to demo guards live
```

Mounts: `AuthProvider` in `app/layout.tsx` (alongside Theme/Toaster); `RequireAuth`
wraps `BackOfficeLayoutClient`; `DevSessionSwitcher` in the backoffice top bar
(dev only). `domain/` stays React/Next/Inversify-free; hooks live in `application/`
(consistent with the CRUD toolkit hooks); provider + components in `infrastructure/ui`.

### IAM behavior matrix

| Condition | Behavior |
|---|---|
| no session / `status !== ACTIVE` | `RequireAuth` redirects → `/login` |
| missing permission | `<Can>` hides the action (no error) |
| missing role/context | section hidden |
| `CUSTOMER_PORTAL` context | read-only mode (seam; portals are future modules) |

## Layer 2 — CRUD execution core (`context/shared/resource/`)

Logic only. No UI generation. Built so Bank *could* adopt later; Bank not changed now.

```
domain/
  CrudRepository.ts          generic port: search(criteria) / find(id) / create(input) /
                             update(id,input) / delete(id); ResourceSearchCriteria
                             { filters: Filter[]; sort: ResourceSort|null; limit }
                             ResourceSearchPage<T> = { items: T[] } & PageEnvelope
  ResourceSearchNavigator.ts follow(link) → ResourceSearchPage<T>
infrastructure/
  InMemoryCrudRepository.ts  generic base over an in-memory array: applies
                             filter predicate + sort comparator + limit; emits the
                             PageEnvelope with opaque offset-encoded cursor links
                             (base64 of {offset, query-hash}); subclasses/instances
                             supply: matchesFilter, compare(sortField), nextId, clock
  InMemoryResourceNavigator.ts  decodes an opaque link, re-runs the slice
application/
  useResourceList.ts         list state machine ported from banks/page.tsx logic:
                             load (criteria vs follow-link), cursor nav, query reset,
                             monotonic seq guard, selection set, optimistic single +
                             bulk delete with re-probe, peek id, derived boundary state.
                             Realtime hooks intentionally omitted (no event source).
  useResourceItem.ts         find(id) lifecycle → { item, state, problem, reload }
  useResourceMutations.ts    create/update/delete wrappers → { run, submitting, problem }
  createQueryState.ts        factory returning typed {filter,sort,pageSize} state +
                             setters + reset, parameterized by EMPTY_FILTER/DEFAULT_SORT
```

Reuses shared `Filter`/`PageEnvelope`/`buildSearchParams` and `ViewStatus` exactly
as Bank does. The hooks consume a repository + navigator resolved from the DI
container by injection key, passed in by the entity page.

## Layer 3 — User module (consumes both toolkits; structure explicit)

```
context/backoffice/user/
  domain/
    User.ts            User class + UserPrimitives { id, email, status, roles[],
                       permissions[], createdAt, updatedAt }  (no password in state ever)
    UserRepository.ts  extends CrudRepository<User, UserInput>; UserInput
                       { email; roles: Role[]; status: UserStatus;
                         permissions?: Permission[] }; UserSort
    UserProblemType.ts user-not-found, user-email-conflict (typed recovery)
  application/schemas/
    UserCreateSchema.ts  email (required, email format, max len mirrors a stated
                         API constraint), roles (≥1), status (default PENDING)
    UserEditSchema.ts    roles, status, permissions (multi-select; visibility toggle)
    auth/LoginSchema.ts            email + password
    auth/RegisterSchema.ts         email + password + confirmPassword (match refine)
    auth/ForgotPasswordSchema.ts   email
    auth/ResetPasswordSchema.ts    password + confirmPassword (match refine)
  infrastructure/
    InMemoryUserRepository.ts  extends InMemoryCrudRepository; email filter, role/
                               status filters, sort by email|status|createdAt|updatedAt
    userSeed.ts                ~25 deterministic seeded users (uuidV7, mixed roles/status)
app/backoffice/users/
  page.tsx               list — uses useResourceList + the user repo/navigator keys
  new/page.tsx           create
  [id]/page.tsx          detail (read-only: email, roles, permissions, status, created/updated)
  [id]/edit/page.tsx     edit (roles, status, permissions)
  _components/  UsersTable, UsersCards, UsersStackedList, UserForm, UserRowActions,
                DeleteUserButton, UsersFilters (email search + role + status selects),
                UsersPagination, UsersViewToggle, UsersColumnPicker (NEW), UsersListSkeleton,
                UsersEmptyFiltered, UsersBulkBar, RolesBadges, UserStatusBadge
  _lib/  userRoutes, usersFilterSort (EMPTY_FILTER/DEFAULT_SORT/predicates),
         usersSearchCriteria (filter→Filter[] + sort→ResourceSort), paginate,
         userRecency, userColumns (column-picker config + storage key)
```

List columns: `email` (pinned) · roles (multi-badge via `<StatusBadge>` / `RolesBadges`)
· status (`UserStatusBadge`: ACTIVE=success, PENDING=warning, BLOCKED=danger) ·
createdAt · actions. Toggleable (picker): roles, status, createdAt, updatedAt.

List behaviors = Bank parity minus realtime: debounced email search, role/status
filters, server-style sort, cursor pagination via links, table/cards/stacked
responsive views, density toggle, multi-select + optimistic bulk delete with
`<MutationError>` recovery, optimistic single delete, peek `<RecordSheet>`, column
picker. Row/bulk actions wrapped in `<Can permission="users.write|users.delete">`.

DI (`Container.ts`): bind `BackOfficeUserRepository` → `InMemoryUserRepository` and
`BackOfficeUserSearchNavigator` → `InMemoryResourceNavigator` (user-configured).
**No per-entity use-case classes** (`CreateUser`/`FindUser`/… ): those thin Bank-style
wrappers are exactly the repeated boilerplate the execution-core hooks replace —
`useResource{List,Item,Mutations}` resolve the repository/navigator from the container
by injection key and call them directly. (Bank keeps its own use-case classes; not
touched.)

## Public auth pages — `app/(auth)/`

```
(auth)/layout.tsx   centered card shell, Logo, token-driven (reuses @/components/erpify)
(auth)/login/page.tsx · register · forgot-password · reset-password
(auth)/_components/{LoginForm,RegisterForm,ForgotPasswordForm,ResetPasswordForm}
```

`useZodForm` + `<FormField>`. Login submit → fake check → `useSession().login(mockUser)`
→ `router.push(Routes.BACKOFFICE)`. Register/Reset → toast + redirect `/login`. Forgot
→ toast "if the email exists, a reset link was sent". `password` lives only in form
values, never in session/state/storage. Server-violation mapping
(`setError(field,{type:"server"})`) wired even though the mock won't emit it
(API-ready). Links between auth pages via `safeHref`.

## States (via existing primitives)

Loading skeletons, first-run empty (`<AsyncBoundary>`), filtered-to-zero panel,
error + Retry, persistent `<MutationError>` surface — same components/contract as Bank.

## Testing & quality

- Vitest (mirrors `tests/` structure): `InMemoryCrudRepository` pagination/filter/sort
  + opaque-link round-trip; `authorize()` evaluator truth table (status/permission/
  wildcard/context); the auth Zod schemas (match refinements).
- `make pwa.quality` (ESLint + Prettier) must pass.
- Security review per `pwa/CLAUDE.md`: `safeHref` on every dynamic href/redirect; no
  secrets/passwords in storage; static `aria-label`s + dynamic in `title`; unique
  `data-testid` literals (reusable components take a `testId` prop); no
  `dangerouslySetInnerHTML`; CSP/headers untouched.
- `next.config.ts`/proxy matchers: confirm `(auth)` routes need no special handling.

## Out of scope (explicit non-goals)

No backend / real auth / JWT / OAuth; no persistence beyond optional localStorage; no
Employee/Customer/Supplier modules (only type seams); no billing/HR logic; no auto
role inference; no v2 policy engine (registry is an empty seam); no Mercure realtime;
Bank module untouched.
