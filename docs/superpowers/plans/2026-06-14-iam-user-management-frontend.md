# IAM & User Management (frontend-only) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the mocked frontend for ERPify Identity & Access (session + RBAC/permission guards), a reusable CRUD execution core, the User management CRUD, and the public auth pages — architecturally consistent with the Bank module and backend-swap-safe.

**Architecture:** Three layers. (1) `context/shared/access/` — IAM execution core: types, pure `authorize()`, mocked `AuthProvider`/`useSession`, `useCan`/`<Can>`/`RequireAuth`/dev switcher. (2) `context/shared/resource/` — CRUD execution core: generic `CrudRepository` port, `InMemoryCrudRepository` + opaque-link navigator, `useResourceList/Item/Mutations`, `createQueryState`. (3) `context/backoffice/user/` + `app/backoffice/users/` + `app/(auth)/` — the User module and auth pages, which **own their UI structure explicitly** (tables/forms/columns/badges) and wire it into the shared hooks. Bank is the reference and is NOT modified.

**Tech Stack:** Next.js 16 App Router, TypeScript (strict), Inversify DI, Zod + `useZodForm`, Tailwind 4 + Shadcn (`@/components/ui`) + `@/components/erpify`, Vitest. Mirrors `src/context/backoffice/bank/` and `src/app/backoffice/banks/`.

**Reference files to read before starting (do not modify):**
- `src/context/backoffice/bank/{domain,application,infrastructure}/*` — DDD layering, ports, navigator.
- `src/app/backoffice/banks/page.tsx` — the list state machine to generalize (minus Mercure realtime).
- `src/app/backoffice/banks/{[id],new,[id]/edit}/page.tsx` — detail/create/edit page shells.
- `src/app/backoffice/banks/_components/{BankForm,BanksTable,BanksFilters,BanksPagination,BanksViewToggle,BankRowActions,DeleteBankButton,BanksCards,BanksStackedList,BanksBulkBar,BanksListSkeleton,BanksEmptyFiltered}.tsx`.
- `src/app/backoffice/banks/_lib/*` and `src/context/shared/domain/Search/*`, `src/context/shared/infrastructure/Search/buildSearchParams.ts`.
- `src/context/shared/infrastructure/DependencyInjection/Container.ts`, `src/components/erpify/index.ts`.
- Spec: `docs/superpowers/specs/2026-06-14-iam-user-management-frontend-design.md`.

**Conventions (enforced):** `make pwa.quality` (ESLint+Prettier) at the end; unique `data-testid` literals (reusable components take a `testId` prop); `safeHref` on every dynamic href/redirect; static `aria-label` + dynamic detail in `title`; no `maxLength` (limits in Zod `.max()`); `uuidV7()` for ids; `NodeEnv`/`Routes` constants, never string literals; `domain/` imports no React/Next/Inversify/HTTP. Commit after each task with Conventional Commits (`<type>(<scope>): <subject>`).

---

## File Structure

**Layer 1 — `src/context/shared/access/`**
- `domain/UserStatus.ts` — ACTIVE/BLOCKED/PENDING const + type (access-level; see spec status-boundary rule).
- `domain/Role.ts` — SUPER_ADMIN/ADMIN/EMPLOYEE/CUSTOMER/SUPPLIER.
- `domain/Permission.ts` — known permission strings + `"*"` wildcard.
- `domain/AccessContext.ts` — BACKOFFICE/CUSTOMER_PORTAL/EMPLOYEE_PORTAL/SUPPLIER_PORTAL.
- `domain/BusinessContext.ts` — conceptual seam types only (EmployeeStatus/CustomerStatus, BusinessContext).
- `domain/Identity.ts` — `Identity` interface (the session's user shape: id, email, status, roles, permissions).
- `domain/Session.ts` — `Session` type.
- `domain/authorize.ts` — pure `authorize(session, permission)` + helpers.
- `domain/AccessPolicyRegistry.ts` — empty seam (`export const AccessPolicyRegistry = {} as const`).
- `application/useSession.ts` — hook over the React context.
- `application/useCan.ts` — `useCan(permission)` / `useCanRole(role)`.
- `infrastructure/ui/AuthProvider.tsx` — provider + seeded session + localStorage.
- `infrastructure/ui/Can.tsx` — `<Can>`.
- `infrastructure/ui/RequireAuth.tsx` — redirect guard.
- `infrastructure/ui/DevSessionSwitcher.tsx` — dev-only switcher.
- `infrastructure/ui/index.ts` — barrel.

**Layer 2 — `src/context/shared/resource/`**
- `domain/ResourceSort.ts` — `{ field: string; direction: SortDirection }`.
- `domain/CrudRepository.ts` — generic port + `ResourceSearchCriteria`, `ResourceSearchPage<T>`, `ResourceInput`.
- `domain/ResourceSearchNavigator.ts` — `follow(link)` port.
- `infrastructure/cursorLink.ts` — opaque link encode/decode (base64 of `{offset}`).
- `infrastructure/InMemoryCrudRepository.ts` — generic base.
- `infrastructure/InMemoryResourceNavigator.ts` — generic navigator over a repo.
- `application/createQueryState.ts` — query-state factory (returns nothing stateful itself; exposes helpers + a `useQueryState` hook).
- `application/useResourceList.ts` — list state machine.
- `application/useResourceItem.ts` — detail fetch.
- `application/useResourceMutations.ts` — create/update/delete.

**Layer 3 — User module**
- `src/context/backoffice/user/domain/{User,UserRepository,UserProblemType}.ts`.
- `src/context/backoffice/user/application/schemas/{UserCreateSchema,UserEditSchema}.ts`.
- `src/context/backoffice/user/application/schemas/auth/{LoginSchema,RegisterSchema,ForgotPasswordSchema,ResetPasswordSchema}.ts`.
- `src/context/backoffice/user/infrastructure/{InMemoryUserRepository,userSeed}.ts`.
- `src/app/backoffice/users/{page,new/page,[id]/page,[id]/edit/page}.tsx`.
- `src/app/backoffice/users/_components/*` and `_lib/*`.
- `src/app/(auth)/{layout,login/page,register/page,forgot-password/page,reset-password/page}.tsx` + `_components/*`.

**Modified:**
- `src/context/shared/infrastructure/DependencyInjection/Container.ts` — bind user repo + navigator.
- `src/app/layout.tsx` — mount `AuthProvider`.
- `src/app/backoffice/BackOfficeLayoutClient.tsx` — wrap content in `RequireAuth`; add `DevSessionSwitcher` to the top bar.
- `src/components/erpify/index.ts` — export `UsersColumnPicker` only if generalized (it stays in users/_components; no erpify change unless noted).

---

## Phase 1 — Access / IAM toolkit

### Task 1: Access domain primitive types

**Files:**
- Create: `src/context/shared/access/domain/UserStatus.ts`
- Create: `src/context/shared/access/domain/Role.ts`
- Create: `src/context/shared/access/domain/Permission.ts`
- Create: `src/context/shared/access/domain/AccessContext.ts`
- Create: `src/context/shared/access/domain/BusinessContext.ts`

- [ ] **Step 1: Write the types**

`UserStatus.ts`:
```ts
/**
 * Access-level user status — an authentication/authorization primitive, NOT a
 * business attribute. Gates the auth layer (BLOCKED ⇒ hard stop). Business
 * statuses (CustomerStatus/EmployeeStatus) are separate even when names overlap;
 * never reuse this enum for them. See the IAM design spec.
 */
export const UserStatus = {
  ACTIVE: "ACTIVE",
  BLOCKED: "BLOCKED",
  PENDING: "PENDING",
} as const;
export type UserStatus = (typeof UserStatus)[keyof typeof UserStatus];
```

`Role.ts`:
```ts
/** Coarse-grained organizational roles (RBAC). Not business state. */
export const Role = {
  SUPER_ADMIN: "SUPER_ADMIN",
  ADMIN: "ADMIN",
  EMPLOYEE: "EMPLOYEE",
  CUSTOMER: "CUSTOMER",
  SUPPLIER: "SUPPLIER",
} as const;
export type Role = (typeof Role)[keyof typeof Role];
export const ALL_ROLES: readonly Role[] = Object.values(Role);
```

`Permission.ts`:
```ts
/** Wildcard: a session holding it satisfies every permission check. */
export const PERMISSION_WILDCARD = "*" as const;

/** Fine-grained, additive permissions. Extend as modules land. */
export const Permission = {
  USERS_READ: "users.read",
  USERS_WRITE: "users.write",
  USERS_DELETE: "users.delete",
  PROJECTS_READ: "projects.read",
  PROJECTS_WRITE: "projects.write",
  INVOICES_READ: "invoices.read",
  INVOICES_WRITE: "invoices.write",
} as const;
export type Permission = (typeof Permission)[keyof typeof Permission];
/** A held permission may be a concrete permission or the wildcard. */
export type HeldPermission = Permission | typeof PERMISSION_WILDCARD;
export const ALL_PERMISSIONS: readonly Permission[] = Object.values(Permission);
```

`AccessContext.ts`:
```ts
/** Which surface the session is operating in (multi-portal future). */
export const AccessContext = {
  BACKOFFICE: "BACKOFFICE",
  CUSTOMER_PORTAL: "CUSTOMER_PORTAL",
  EMPLOYEE_PORTAL: "EMPLOYEE_PORTAL",
  SUPPLIER_PORTAL: "SUPPLIER_PORTAL",
} as const;
export type AccessContext = (typeof AccessContext)[keyof typeof AccessContext];
```

`BusinessContext.ts` (types only — seam, no module):
```ts
/** Business-level states — declared as a v2 seam, NOT used this iteration. */
export const EmployeeStatus = {
  ACTIVE: "ACTIVE",
  ON_LEAVE: "ON_LEAVE",
  TERMINATED: "TERMINATED",
} as const;
export type EmployeeStatus = (typeof EmployeeStatus)[keyof typeof EmployeeStatus];

export const CustomerStatus = {
  ACTIVE: "ACTIVE",
  SUSPENDED: "SUSPENDED",
  BLOCKED_FINANCIAL: "BLOCKED_FINANCIAL",
} as const;
export type CustomerStatus = (typeof CustomerStatus)[keyof typeof CustomerStatus];

/** Optional business context attached to a session for future ABAC policies. */
export interface BusinessContext {
  employeeStatus?: EmployeeStatus;
  customerStatus?: CustomerStatus;
}
```

- [ ] **Step 2: Typecheck**

Run: `cd pwa && npx tsc --noEmit`
Expected: PASS (no references yet).

- [ ] **Step 3: Commit**

```bash
git add pwa/src/context/shared/access/domain
git commit -m "feat(pwa): access-layer primitive types (status, role, permission, context)"
```

---

### Task 2: Session/Identity types + AccessPolicyRegistry seam

**Files:**
- Create: `src/context/shared/access/domain/Identity.ts`
- Create: `src/context/shared/access/domain/Session.ts`
- Create: `src/context/shared/access/domain/AccessPolicyRegistry.ts`

- [ ] **Step 1: Write the types**

`Identity.ts`:
```ts
import type { UserStatus } from "./UserStatus";
import type { Role } from "./Role";
import type { HeldPermission } from "./Permission";

/**
 * The identity carried in a session. A projection of the User aggregate limited
 * to what the access layer needs — never the password, never audit fields a
 * client may not see.
 */
export interface Identity {
  id: string;
  email: string;
  status: UserStatus;
  roles: Role[];
  permissions: HeldPermission[];
}
```

`Session.ts`:
```ts
import type { Identity } from "./Identity";
import type { Role } from "./Role";
import type { HeldPermission } from "./Permission";
import type { AccessContext } from "./AccessContext";
import type { BusinessContext } from "./BusinessContext";

/**
 * The frontend session model (blueprint §5). Mocked this iteration; an
 * `AuthProvider` seeds it and the dev switcher mutates it. A real API session
 * later fills the same shape with no consumer change.
 */
export interface Session {
  user: Identity;
  roles: Role[];
  permissions: HeldPermission[];
  context: AccessContext;
  business?: BusinessContext;
}
```

`AccessPolicyRegistry.ts`:
```ts
/**
 * Named architectural placeholder for the future ABAC policy engine (blueprint
 * §4/§9). Intentionally EMPTY — no entries, no logic, no DI, no rule engine.
 * Its only job is to mark the plug-in point so v2 policies attach here instead
 * of leaking into `authorize()`. Do not add behavior without a design change.
 */
export const AccessPolicyRegistry = {} as const;
```

- [ ] **Step 2: Typecheck**

Run: `cd pwa && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add pwa/src/context/shared/access/domain
git commit -m "feat(pwa): session/identity types + empty AccessPolicyRegistry seam"
```

---

### Task 3: `authorize()` pure evaluator (TDD)

**Files:**
- Create: `src/context/shared/access/domain/authorize.ts`
- Test: `tests/context/shared/access/domain/authorize.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from "vitest";
import { authorize, hasPermission } from "@/context/shared/access/domain/authorize";
import type { Session } from "@/context/shared/access/domain/Session";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Role } from "@/context/shared/access/domain/Role";
import { Permission, PERMISSION_WILDCARD } from "@/context/shared/access/domain/Permission";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";

function session(overrides: Partial<Session> = {}): Session {
  const base: Session = {
    user: {
      id: "u1",
      email: "a@b.com",
      status: UserStatus.ACTIVE,
      roles: [Role.ADMIN],
      permissions: [PERMISSION_WILDCARD],
    },
    roles: [Role.ADMIN],
    permissions: [PERMISSION_WILDCARD],
    context: AccessContext.BACKOFFICE,
  };
  return { ...base, ...overrides };
}

describe("authorize", () => {
  it("allows when ACTIVE and holds the wildcard", () => {
    expect(authorize(session(), Permission.USERS_WRITE)).toBe(true);
  });

  it("denies when status is not ACTIVE, even with the wildcard", () => {
    expect(authorize(session({ user: { ...session().user, status: UserStatus.BLOCKED } }), Permission.USERS_READ)).toBe(false);
  });

  it("allows a concrete permission and denies a missing one", () => {
    const s = session({ permissions: [Permission.USERS_READ] });
    expect(authorize(s, Permission.USERS_READ)).toBe(true);
    expect(authorize(s, Permission.USERS_DELETE)).toBe(false);
  });

  it("hasPermission honors the wildcard", () => {
    expect(hasPermission([PERMISSION_WILDCARD], Permission.INVOICES_WRITE)).toBe(true);
    expect(hasPermission([Permission.INVOICES_READ], Permission.INVOICES_WRITE)).toBe(false);
  });
});
```

- [ ] **Step 2: Run test, verify it fails**

Run: `cd pwa && make pwa.test.unit c='tests/context/shared/access/domain/authorize.test.ts'` (or `npx vitest run tests/context/shared/access/domain/authorize.test.ts`)
Expected: FAIL — `authorize` not exported.

- [ ] **Step 3: Implement**

```ts
import type { Session } from "./Session";
import type { Permission, HeldPermission } from "./Permission";
import { PERMISSION_WILDCARD } from "./Permission";
import { UserStatus } from "./UserStatus";

/** True if the held set satisfies `permission` (the wildcard satisfies all). */
export function hasPermission(held: readonly HeldPermission[], permission: Permission): boolean {
  return held.includes(PERMISSION_WILDCARD) || held.includes(permission);
}

/**
 * Role↔context validity. v1: every role is valid in BACKOFFICE; the seam exists
 * so v2 can restrict (e.g. CUSTOMER only in CUSTOMER_PORTAL). Always true now.
 */
export function roleContextValid(_session: Session): boolean {
  return true;
}

/**
 * Domain-policy layer (ABAC-lite) — the v2 plug-in point (see
 * AccessPolicyRegistry). Stubbed to allow this iteration.
 */
export function domainPolicyAllow(_session: Session, _permission: Permission): boolean {
  return true;
}

/**
 * The blueprint Authorization Equation:
 *   ALLOW = status===ACTIVE AND hasPermission AND roleContextValid AND domainPolicyAllow
 */
export function authorize(session: Session, permission: Permission): boolean {
  return (
    session.user.status === UserStatus.ACTIVE &&
    hasPermission(session.permissions, permission) &&
    roleContextValid(session) &&
    domainPolicyAllow(session, permission)
  );
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `cd pwa && npx vitest run tests/context/shared/access/domain/authorize.test.ts`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/context/shared/access/domain/authorize.ts pwa/tests/context/shared/access/domain/authorize.test.ts
git commit -m "feat(pwa): pure authorize() evaluator (blueprint ALLOW equation)"
```

---

### Task 4: `AuthProvider` + `useSession`

**Files:**
- Create: `src/context/shared/access/infrastructure/ui/AuthProvider.tsx`
- Create: `src/context/shared/access/application/useSession.ts`

Read first: `src/lib/useStoredPreference.ts` (hydration-safe localStorage pattern), `src/lib/ThemeProvider.tsx` (provider-wrapper pattern), `src/context/shared/domain/types/nodeEnv.ts`.

- [ ] **Step 1: Implement the context + provider**

`AuthProvider.tsx`:
```tsx
"use client";

import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import type { Session } from "../../domain/Session";
import type { Identity } from "../../domain/Identity";
import { UserStatus } from "../../domain/UserStatus";
import { Role } from "../../domain/Role";
import { PERMISSION_WILDCARD } from "../../domain/Permission";
import { AccessContext } from "../../domain/AccessContext";

const STORAGE_KEY = "erpify:session";

/** Seeded default: an ADMIN with the wildcard, so backoffice CRUD is usable out of the box. */
const SEED_SESSION: Session = {
  user: {
    id: "00000000-0000-7000-8000-000000000001",
    email: "admin@erpify.dev",
    status: UserStatus.ACTIVE,
    roles: [Role.ADMIN],
    permissions: [PERMISSION_WILDCARD],
  },
  roles: [Role.ADMIN],
  permissions: [PERMISSION_WILDCARD],
  context: AccessContext.BACKOFFICE,
};

export interface AuthContextValue {
  session: Session | null;
  /** Mocked login: replaces the session with the supplied identity (no validation). */
  login: (user: Identity, context?: AccessContext) => void;
  /** Clears the session (logout). */
  logout: () => void;
  /** Dev-only partial override (role/status/permissions), used by the switcher. */
  override: (patch: Partial<Session> & { user?: Partial<Identity> }) => void;
}

export const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: Readonly<{ children: ReactNode }>) {
  // Hydration-safe: SSR + first paint render the seed; a stored session applies
  // after mount (reading localStorage during render causes hydration mismatch).
  const [session, setSession] = useState<Session | null>(SEED_SESSION);

  useEffect(() => {
    try {
      const raw = globalThis.localStorage?.getItem(STORAGE_KEY);
      if (raw) setSession(JSON.parse(raw) as Session);
    } catch {
      // Corrupt/blocked storage → keep the seed.
    }
  }, []);

  const persist = useCallback((next: Session | null): void => {
    setSession(next);
    try {
      if (next) globalThis.localStorage?.setItem(STORAGE_KEY, JSON.stringify(next));
      else globalThis.localStorage?.removeItem(STORAGE_KEY);
    } catch {
      // best-effort convenience only
    }
  }, []);

  const login = useCallback(
    (user: Identity, context: AccessContext = AccessContext.BACKOFFICE): void => {
      persist({ user, roles: user.roles, permissions: user.permissions, context });
    },
    [persist],
  );

  const logout = useCallback((): void => persist(null), [persist]);

  const override = useCallback(
    (patch: Partial<Session> & { user?: Partial<Identity> }): void => {
      setSession((prev) => {
        const base = prev ?? SEED_SESSION;
        const user: Identity = { ...base.user, ...(patch.user ?? {}) };
        const next: Session = {
          ...base,
          ...patch,
          user,
          roles: patch.roles ?? user.roles,
          permissions: patch.permissions ?? user.permissions,
        };
        try {
          globalThis.localStorage?.setItem(STORAGE_KEY, JSON.stringify(next));
        } catch {
          /* ignore */
        }
        return next;
      });
    },
    [],
  );

  const value = useMemo<AuthContextValue>(
    () => ({ session, login, logout, override }),
    [session, login, logout, override],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
```

`useSession.ts`:
```ts
"use client";

import { useContext } from "react";
import { AuthContext, type AuthContextValue } from "../infrastructure/ui/AuthProvider";

/** Access the mocked session. Throws if used outside <AuthProvider>. */
export function useSession(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useSession must be used within <AuthProvider>.");
  return ctx;
}
```

- [ ] **Step 2: Typecheck**

Run: `cd pwa && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx pwa/src/context/shared/access/application/useSession.ts
git commit -m "feat(pwa): mocked AuthProvider + useSession (seeded admin session)"
```

---

### Task 5: `useCan` + `<Can>` + `RequireAuth`

**Files:**
- Create: `src/context/shared/access/application/useCan.ts`
- Create: `src/context/shared/access/infrastructure/ui/Can.tsx`
- Create: `src/context/shared/access/infrastructure/ui/RequireAuth.tsx`
- Test: `tests/context/shared/access/useCan.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Can } from "@/context/shared/access/infrastructure/ui/Can";
import { Permission } from "@/context/shared/access/domain/Permission";

describe("<Can>", () => {
  it("renders children when the seeded admin holds the permission", () => {
    render(
      <AuthProvider>
        <Can permission={Permission.USERS_WRITE}>
          <button>create</button>
        </Can>
      </AuthProvider>,
    );
    expect(screen.getByRole("button", { name: "create" })).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pwa && npx vitest run tests/context/shared/access/useCan.test.tsx`
Expected: FAIL — `Can` not found.

- [ ] **Step 3: Implement**

`useCan.ts`:
```ts
"use client";

import { useSession } from "./useSession";
import { authorize } from "../domain/authorize";
import type { Permission } from "../domain/Permission";
import type { Role } from "../domain/Role";

/** True when the current session is allowed `permission` (false if no session). */
export function useCan(permission: Permission): boolean {
  const { session } = useSession();
  return session ? authorize(session, permission) : false;
}

/** True when the current ACTIVE session holds `role`. */
export function useCanRole(role: Role): boolean {
  const { session } = useSession();
  return Boolean(session && session.user.status === "ACTIVE" && session.roles.includes(role));
}
```

`Can.tsx`:
```tsx
"use client";

import type { ReactNode } from "react";
import { useCan, useCanRole } from "../../application/useCan";
import type { Permission } from "../../domain/Permission";
import type { Role } from "../../domain/Role";

interface CanProps {
  permission?: Permission;
  role?: Role;
  /** Optional element shown when denied (default: nothing — hide, never error). */
  fallback?: ReactNode;
  children: ReactNode;
}

/**
 * UI guard: renders children only when the session passes the permission/role
 * check; otherwise renders `fallback` (default null). Per blueprint §8: missing
 * permission HIDES the action — it never shows an error.
 */
export function Can({ permission, role, fallback = null, children }: Readonly<CanProps>) {
  const allowedByPermission = permission ? useCan(permission) : true;
  const allowedByRole = role ? useCanRole(role) : true;
  return allowedByPermission && allowedByRole ? <>{children}</> : <>{fallback}</>;
}
```
> Note: the two hooks are called unconditionally each render (the `permission`/`role` props are stable per usage site), so this does not violate the rules-of-hooks. If ESLint flags it, split into `<CanPermission>`/`<CanRole>` — but the conditional-arg call is safe here because the prop identity never changes across renders of a given `<Can>`.

`RequireAuth.tsx`:
```tsx
"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useSession } from "../../application/useSession";
import { UserStatus } from "../../domain/UserStatus";
import { Routes } from "@/context/shared/domain/types/routes";

/**
 * Route protection (blueprint §8): no session or a non-ACTIVE user (e.g. BLOCKED
 * via the dev switcher) is redirected to /login. While redirecting it renders
 * nothing so protected content never flashes.
 */
export function RequireAuth({ children }: Readonly<{ children: ReactNode }>) {
  const router = useRouter();
  const { session } = useSession();
  const denied = !session || session.user.status !== UserStatus.ACTIVE;

  useEffect(() => {
    if (denied) router.replace(Routes.LOGIN);
  }, [denied, router]);

  if (denied) return null;
  return <>{children}</>;
}
```

> `Routes.LOGIN` is added in Task 6.

- [ ] **Step 4: Run, verify pass**

Run: `cd pwa && npx vitest run tests/context/shared/access/useCan.test.tsx`
Expected: PASS. (If `Routes.LOGIN` is missing, do Task 6 Step 1 first, then return.)

- [ ] **Step 5: Commit**

```bash
git add pwa/src/context/shared/access/application/useCan.ts pwa/src/context/shared/access/infrastructure/ui/Can.tsx pwa/src/context/shared/access/infrastructure/ui/RequireAuth.tsx pwa/tests/context/shared/access/useCan.test.tsx
git commit -m "feat(pwa): useCan, <Can> hide-guard, RequireAuth redirect guard"
```

---

### Task 6: Add auth routes to `Routes` + mount `AuthProvider`/`RequireAuth`/barrel

**Files:**
- Modify: `src/context/shared/domain/types/routes.ts`
- Modify: `src/app/layout.tsx`
- Modify: `src/app/backoffice/BackOfficeLayoutClient.tsx`
- Create: `src/context/shared/access/infrastructure/ui/index.ts`
- Create: `src/app/(auth)/_lib/authRoutes.ts`

- [ ] **Step 1: Add routes**

In `routes.ts`, add inside the `Routes` object (after `HOME`):
```ts
  /** Public login page. */
  LOGIN: "/login",
  /** Public registration page. */
  REGISTER: "/register",
  /** Public forgot-password page. */
  FORGOT_PASSWORD: "/forgot-password",
  /** Public reset-password page. */
  RESET_PASSWORD: "/reset-password",
```

- [ ] **Step 2: Barrel for access UI**

`src/context/shared/access/infrastructure/ui/index.ts`:
```ts
export { AuthProvider } from "./AuthProvider";
export { Can } from "./Can";
export { RequireAuth } from "./RequireAuth";
export { DevSessionSwitcher } from "./DevSessionSwitcher";
```
> `DevSessionSwitcher` is created in Task 7; add its export then, or create a stub now. To keep this task green, create the stub file `DevSessionSwitcher.tsx` exporting `export function DevSessionSwitcher() { return null; }` and replace it in Task 7.

- [ ] **Step 3: Mount AuthProvider in root layout**

In `src/app/layout.tsx`, wrap the existing provider tree (next to `ThemeProvider`) with `AuthProvider` from `@/context/shared/access/infrastructure/ui`. Place `AuthProvider` OUTSIDE `ThemeProvider` is fine; keep `Toaster` mounted as-is. (Read the file first; insert `<AuthProvider>…</AuthProvider>` around `{children}` within the body.)

- [ ] **Step 4: Wrap backoffice content in RequireAuth + reserve switcher slot**

In `src/app/backoffice/BackOfficeLayoutClient.tsx`, import `RequireAuth` and `DevSessionSwitcher` from `@/context/shared/access/infrastructure/ui` and `isDevToolsAvailable` from `@/context/shared/dev-tools/domain/isDevToolsAvailable`. Wrap the `<main>`/content region in `<RequireAuth>…</RequireAuth>`. In the top bar, next to the existing `<ThemeToggle testId="bo-layout__topbar-theme" />`, add: `{isDevToolsAvailable() ? <DevSessionSwitcher /> : null}`.

- [ ] **Step 5: Typecheck + quality**

Run: `cd pwa && npx tsc --noEmit && make pwa.quality`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add pwa/src/context/shared/domain/types/routes.ts pwa/src/app/layout.tsx pwa/src/app/backoffice/BackOfficeLayoutClient.tsx pwa/src/context/shared/access/infrastructure/ui/index.ts
git commit -m "feat(pwa): mount AuthProvider + RequireAuth guard; add auth routes"
```

---

### Task 7: Dev-only session switcher

**Files:**
- Modify (replace stub): `src/context/shared/access/infrastructure/ui/DevSessionSwitcher.tsx`

Read first: `src/components/ui/{dropdown-menu,select,button}.tsx` to reuse the existing primitives; `src/app/backoffice/dev-tools/*` for the dev-only styling idiom.

- [ ] **Step 1: Implement**

A compact popover/dropdown (built from `@/components/ui/dropdown-menu` or `select`) exposing:
- Role select → `override({ roles: [role], user: { roles: [role] } })`.
- Status select (ACTIVE/BLOCKED/PENDING) → `override({ user: { status } })`.
- Permission toggle: wildcard vs a curated subset → `override({ permissions, user: { permissions } })`.

```tsx
"use client";

import { useSession } from "../../application/useSession";
import { ALL_ROLES, type Role } from "../../domain/Role";
import { UserStatus } from "../../domain/UserStatus";
import { ALL_PERMISSIONS, PERMISSION_WILDCARD, type HeldPermission } from "../../domain/Permission";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuCheckboxItem,
} from "@/components/ui/dropdown-menu";
import { Button } from "@/components/ui/button";
import { ShieldHalf } from "lucide-react";

/** Dev/QA only (mounted behind isDevToolsAvailable). Mutates the mocked session
 *  so IAM guards are demonstrable: switch role, status, and permission breadth. */
export function DevSessionSwitcher() {
  const { session, override } = useSession();
  if (!session) return null;
  const role = session.roles[0];
  const wildcard = session.permissions.includes(PERMISSION_WILDCARD);

  const setRole = (next: Role): void => override({ roles: [next], user: { roles: [next] } });
  const setStatus = (next: string): void =>
    override({ user: { status: next as (typeof UserStatus)[keyof typeof UserStatus] } });
  const setWildcard = (on: boolean): void => {
    const permissions: HeldPermission[] = on ? [PERMISSION_WILDCARD] : [...ALL_PERMISSIONS];
    override({ permissions, user: { permissions } });
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          aria-label="Dev session switcher"
          title="Switch mocked role / status / permissions (dev only)"
          data-testid="dev-session-switcher"
        >
          <ShieldHalf className="size-4" aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel>Role</DropdownMenuLabel>
        <DropdownMenuRadioGroup value={role} onValueChange={(v) => setRole(v as Role)}>
          {ALL_ROLES.map((r) => (
            <DropdownMenuRadioItem key={r} value={r}>{r}</DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuLabel>Status</DropdownMenuLabel>
        <DropdownMenuRadioGroup value={session.user.status} onValueChange={setStatus}>
          {Object.values(UserStatus).map((s) => (
            <DropdownMenuRadioItem key={s} value={s}>{s}</DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuCheckboxItem checked={wildcard} onCheckedChange={setWildcard}>
          All permissions (*)
        </DropdownMenuCheckboxItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
```
> If `dropdown-menu` lacks `RadioGroup`/`CheckboxItem` exports, read the actual file and adapt to what exists (e.g. plain `DropdownMenuItem` toggles). Do not add a new primitive.

- [ ] **Step 2: Quality + manual check**

Run: `cd pwa && npx tsc --noEmit && make pwa.quality`
Expected: PASS. (Manual: switching Status→BLOCKED in the running app must redirect to `/login`.)

- [ ] **Step 3: Commit**

```bash
git add pwa/src/context/shared/access/infrastructure/ui/DevSessionSwitcher.tsx
git commit -m "feat(pwa): dev-only session switcher (role/status/permission)"
```

---

## Phase 2 — CRUD execution core

### Task 8: Generic CRUD ports

**Files:**
- Create: `src/context/shared/resource/domain/ResourceSort.ts`
- Create: `src/context/shared/resource/domain/CrudRepository.ts`
- Create: `src/context/shared/resource/domain/ResourceSearchNavigator.ts`

- [ ] **Step 1: Write the ports**

`ResourceSort.ts`:
```ts
import type { SortDirection } from "@/context/shared/domain/types/sorting";
/** Server-side sort: a public field name + direction. */
export interface ResourceSort {
  field: string;
  direction: SortDirection;
}
```

`CrudRepository.ts`:
```ts
import type { Filter, PageEnvelope } from "@/context/shared/domain/Search";
import type { ResourceSort } from "./ResourceSort";

/** Cursor-only search request: filters + optional sort + page size. No cursor here. */
export interface ResourceSearchCriteria {
  filters: Filter[];
  sort: ResourceSort | null;
  limit: number;
}

/** One page of `T` plus the cursor-only envelope (items + hasNext/hasPrev/count/links). */
export type ResourceSearchPage<T> = { items: T[] } & PageEnvelope;

/**
 * Generic CRUD port (the execution-core contract). Entity repositories extend
 * this with their concrete entity + input. A real backend later implements the
 * same shape — swapping the in-memory adapter requires no consumer change.
 */
export interface CrudRepository<T, TInput> {
  search(criteria: ResourceSearchCriteria): Promise<ResourceSearchPage<T>>;
  find(id: string): Promise<T>;
  create(input: TInput): Promise<T>;
  update(id: string, input: TInput): Promise<T>;
  delete(id: string): Promise<void>;
}
```

`ResourceSearchNavigator.ts`:
```ts
import type { ResourceSearchPage } from "./CrudRepository";
/** Cursor-only navigation: follow a server-issued link verbatim. */
export interface ResourceSearchNavigator<T> {
  follow(link: string): Promise<ResourceSearchPage<T>>;
}
```

- [ ] **Step 2: Typecheck + commit**

```bash
cd pwa && npx tsc --noEmit
git add pwa/src/context/shared/resource/domain
git commit -m "feat(pwa): generic CrudRepository + search navigator ports"
```

---

### Task 9: Opaque cursor links + `InMemoryCrudRepository` (TDD)

**Files:**
- Create: `src/context/shared/resource/infrastructure/cursorLink.ts`
- Create: `src/context/shared/resource/infrastructure/InMemoryCrudRepository.ts`
- Create: `src/context/shared/resource/infrastructure/InMemoryResourceNavigator.ts`
- Test: `tests/context/shared/resource/InMemoryCrudRepository.test.ts`

The in-memory repo applies filters/sort/limit over an array and emits the real
`PageEnvelope`. Links are opaque: base64 of the offset of the next/prev page. The
navigator decodes a link back into a slice. Subclasses/config supply how to match a
filter and how to compare for a sort field.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, beforeEach } from "vitest";
import { InMemoryCrudRepository, type InMemoryEntityConfig } from "@/context/shared/resource/infrastructure/InMemoryCrudRepository";
import { InMemoryResourceNavigator } from "@/context/shared/resource/infrastructure/InMemoryResourceNavigator";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import { FilterOperator, type Filter } from "@/context/shared/domain/Search";

interface Row { id: string; name: string; n: number; }
type RowInput = { name: string; n: number };

const config: InMemoryEntityConfig<Row, RowInput> = {
  matchesFilter: (row, filter) =>
    filter.field === "name" && filter.operator === FilterOperator.Contains
      ? row.name.toLowerCase().includes(String(filter.value).toLowerCase())
      : true,
  compare: (field) => (a, b) => field === "n" ? a.n - b.n : a.name.localeCompare(b.name),
  fromInput: (input, id, nowIso) => ({ id, name: input.name, n: input.n }),
  applyInput: (row, input) => ({ ...row, name: input.name, n: input.n }),
};

function repo() {
  const seed: Row[] = Array.from({ length: 7 }, (_, i) => ({ id: `id-${i}`, name: `n${i}`, n: i }));
  return new InMemoryCrudRepository<Row, RowInput>(seed, config, () => "new-id", () => "2026-01-01T00:00:00.000Z");
}

describe("InMemoryCrudRepository", () => {
  it("returns the first page with hasNext and a next link, no prev", async () => {
    const page = await repo().search({ filters: [], sort: null, limit: 3 });
    expect(page.items).toHaveLength(3);
    expect(page.hasNext).toBe(true);
    expect(page.hasPrev).toBe(false);
    expect(page.links.next).toMatch(/^\//);
    expect(page.links.prev).toBeNull();
  });

  it("navigates next via the opaque link", async () => {
    const r = repo();
    const nav = new InMemoryResourceNavigator<Row, RowInput>(r);
    const first = await r.search({ filters: [], sort: null, limit: 3 });
    const second = await nav.follow(first.links.next!);
    expect(second.items.map((x) => x.id)).toEqual(["id-3", "id-4", "id-5"]);
    expect(second.hasPrev).toBe(true);
    expect(second.hasNext).toBe(true);
  });

  it("filters by name contains", async () => {
    const page = await repo().search({
      filters: [{ field: "name", operator: FilterOperator.Contains, value: "n5" } as Filter],
      sort: null,
      limit: 25,
    });
    expect(page.items.map((x) => x.id)).toEqual(["id-5"]);
  });

  it("sorts descending by n", async () => {
    const page = await repo().search({
      filters: [],
      sort: { field: "n", direction: SortDirection.DESC },
      limit: 2,
    });
    expect(page.items.map((x) => x.n)).toEqual([6, 5]);
  });

  it("create/find/update/delete round-trip", async () => {
    const r = repo();
    const created = await r.create({ name: "zz", n: 99 });
    expect(created.id).toBe("new-id");
    expect((await r.find("new-id")).name).toBe("zz");
    await r.update("new-id", { name: "yy", n: 1 });
    expect((await r.find("new-id")).name).toBe("yy");
    await r.delete("new-id");
    await expect(r.find("new-id")).rejects.toThrow();
  });
});
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pwa && npx vitest run tests/context/shared/resource/InMemoryCrudRepository.test.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement `cursorLink.ts`**

```ts
/**
 * Opaque pagination link encode/decode for the in-memory repository. Mirrors the
 * real backend contract: the CLIENT treats links as opaque transport tokens and
 * never decodes them — only this repository/navigator (the "server") does. The
 * link is a same-origin relative URL carrying a base64 offset so it passes the
 * navigator's same-origin guard exactly like a real API link.
 */
const PATH = "/__mock__/resource";

export function encodeCursorLink(offset: number): string {
  const token = globalThis.btoa(JSON.stringify({ offset }));
  return `${PATH}?cursor=${encodeURIComponent(token)}`;
}

export function decodeCursorOffset(link: string): number {
  const url = new URL(link, "https://mock.invalid");
  const token = url.searchParams.get("cursor");
  if (!token) return 0;
  try {
    const parsed = JSON.parse(globalThis.atob(token)) as { offset?: number };
    return typeof parsed.offset === "number" && parsed.offset >= 0 ? parsed.offset : 0;
  } catch {
    return 0;
  }
}
```

- [ ] **Step 4: Implement `InMemoryCrudRepository.ts`**

```ts
import type { Filter } from "@/context/shared/domain/Search";
import type {
  CrudRepository,
  ResourceSearchCriteria,
  ResourceSearchPage,
} from "../domain/CrudRepository";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import { encodeCursorLink, decodeCursorOffset } from "./cursorLink";

/** Per-entity hooks the generic base needs (the only entity-specific logic). */
export interface InMemoryEntityConfig<T extends { id: string }, TInput> {
  /** True if `row` satisfies one filter. Called once per active filter (AND). */
  matchesFilter: (row: T, filter: Filter) => boolean;
  /** Comparator factory for a sort field (ascending order). */
  compare: (field: string) => (a: T, b: T) => number;
  /** Build a new entity from input (id + timestamps supplied). */
  fromInput: (input: TInput, id: string, nowIso: string) => T;
  /** Apply input to an existing entity (preserve id/createdAt; bump updatedAt outside). */
  applyInput: (existing: T, input: TInput, nowIso: string) => T;
}

/**
 * Generic in-memory CRUD adapter. Holds rows in memory, applies filter→sort→limit,
 * and emits the cursor-only PageEnvelope with opaque offset-encoded links. This is
 * the mock that fills the real domain port; an Api*Repository later replaces it
 * with zero consumer change.
 */
export class InMemoryCrudRepository<T extends { id: string }, TInput>
  implements CrudRepository<T, TInput>
{
  constructor(
    private rows: T[],
    private readonly config: InMemoryEntityConfig<T, TInput>,
    private readonly nextId: () => string,
    private readonly nowIso: () => string,
    /** Simulated latency (ms) so loading states are visible; default 0 in tests. */
    private readonly latencyMs = 0,
  ) {}

  async search(criteria: ResourceSearchCriteria): Promise<ResourceSearchPage<T>> {
    await this.delay();
    return this.slice(criteria, 0);
  }

  /** Used by the navigator to continue from an opaque link. */
  async searchAt(criteria: ResourceSearchCriteria, offset: number): Promise<ResourceSearchPage<T>> {
    await this.delay();
    return this.slice(criteria, offset);
  }

  async find(id: string): Promise<T> {
    await this.delay();
    const row = this.rows.find((r) => r.id === id);
    if (!row) throw new Error(`Not found: ${id}`);
    return row;
  }

  async create(input: TInput): Promise<T> {
    await this.delay();
    const row = this.config.fromInput(input, this.nextId(), this.nowIso());
    this.rows = [row, ...this.rows];
    return row;
  }

  async update(id: string, input: TInput): Promise<T> {
    await this.delay();
    const index = this.rows.findIndex((r) => r.id === id);
    if (index === -1) throw new Error(`Not found: ${id}`);
    const updated = this.config.applyInput(this.rows[index], input, this.nowIso());
    this.rows = this.rows.map((r, i) => (i === index ? updated : r));
    return updated;
  }

  async delete(id: string): Promise<void> {
    await this.delay();
    const exists = this.rows.some((r) => r.id === id);
    if (!exists) throw new Error(`Not found: ${id}`);
    this.rows = this.rows.filter((r) => r.id !== id);
  }

  private slice(criteria: ResourceSearchCriteria, offset: number): ResourceSearchPage<T> {
    const filtered = this.rows.filter((row) =>
      criteria.filters.every((f) => this.config.matchesFilter(row, f)),
    );
    const sorted = this.sortRows(filtered, criteria);
    const limit = Math.max(1, criteria.limit);
    const items = sorted.slice(offset, offset + limit);
    const hasNext = offset + limit < sorted.length;
    const hasPrev = offset > 0;
    return {
      items,
      hasNext,
      hasPrev,
      count: null, // LIGHT pagination parity with Bank (no total shown).
      links: {
        next: hasNext ? encodeCursorLink(offset + limit) : null,
        prev: hasPrev ? encodeCursorLink(Math.max(0, offset - limit)) : null,
      },
    };
  }

  private sortRows(rows: T[], criteria: ResourceSearchCriteria): T[] {
    if (!criteria.sort) return rows;
    const cmp = this.config.compare(criteria.sort.field);
    const dir = criteria.sort.direction === SortDirection.DESC ? -1 : 1;
    return [...rows].sort((a, b) => cmp(a, b) * dir);
  }

  private async delay(): Promise<void> {
    if (this.latencyMs > 0) await new Promise((res) => setTimeout(res, this.latencyMs));
  }
}

export { decodeCursorOffset };
```

- [ ] **Step 5: Implement `InMemoryResourceNavigator.ts`**

```ts
import type { ResourceSearchNavigator } from "../domain/ResourceSearchNavigator";
import type { ResourceSearchCriteria, ResourceSearchPage } from "../domain/CrudRepository";
import type { InMemoryCrudRepository } from "./InMemoryCrudRepository";
import { decodeCursorOffset } from "./cursorLink";

/**
 * Generic navigator over an in-memory repository. Decodes the opaque link's
 * offset and re-runs the slice. The criteria are remembered from the last
 * search so a follow continues the same query (mirrors how a real link encodes
 * the full query server-side).
 */
export class InMemoryResourceNavigator<T extends { id: string }, TInput>
  implements ResourceSearchNavigator<T>
{
  private lastCriteria: ResourceSearchCriteria = { filters: [], sort: null, limit: 25 };

  constructor(private readonly repository: InMemoryCrudRepository<T, TInput>) {}

  /** Called by useResourceList before navigation so follow() reuses the live query. */
  remember(criteria: ResourceSearchCriteria): void {
    this.lastCriteria = criteria;
  }

  async follow(link: string): Promise<ResourceSearchPage<T>> {
    const offset = decodeCursorOffset(link);
    return this.repository.searchAt(this.lastCriteria, offset);
  }
}
```
> Design note: the in-memory navigator needs the live criteria, so `useResourceList` calls `navigator.remember(criteria)` on each criteria search before following links. A real `Api*Navigator` ignores this (the link carries the query). The hook calls `remember` only when the method exists (duck-typed) so it stays backend-agnostic.

- [ ] **Step 6: Run, verify pass**

Run: `cd pwa && npx vitest run tests/context/shared/resource/InMemoryCrudRepository.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add pwa/src/context/shared/resource/infrastructure pwa/tests/context/shared/resource/InMemoryCrudRepository.test.ts
git commit -m "feat(pwa): in-memory CRUD repository + opaque-link navigator"
```

---

### Task 10: `createQueryState` + `useResourceMutations` + `useResourceItem`

**Files:**
- Create: `src/context/shared/resource/application/createQueryState.ts`
- Create: `src/context/shared/resource/application/useResourceMutations.ts`
- Create: `src/context/shared/resource/application/useResourceItem.ts`

Read first: `src/context/shared/domain/types/status.ts` (`ViewStatus`, `PersistenceAction`), `src/context/shared/infrastructure/HttpClient/HttpError.ts`, `src/context/shared/domain/ProblemDetails.ts`.

- [ ] **Step 1: `createQueryState.ts`**

```ts
"use client";

import { useState } from "react";
import type { ResourceSort } from "../domain/ResourceSort";

/** Generic, entity-agnostic query state (filter object + sort + page size). */
export interface QueryState<F> {
  filter: F;
  setFilter: (f: F) => void;
  sort: ResourceSort | null;
  setSort: (s: ResourceSort | null) => void;
  pageSize: number;
  setPageSize: (n: number) => void;
  reset: () => void;
}

export interface QueryStateConfig<F> {
  emptyFilter: F;
  defaultSort: ResourceSort | null;
  defaultPageSize: number;
}

/** Hook factory: owns filter/sort/pageSize state with a single reset. */
export function useQueryState<F>({ emptyFilter, defaultSort, defaultPageSize }: QueryStateConfig<F>): QueryState<F> {
  const [filter, setFilter] = useState<F>(emptyFilter);
  const [sort, setSort] = useState<ResourceSort | null>(defaultSort);
  const [pageSize, setPageSize] = useState<number>(defaultPageSize);
  const reset = (): void => {
    setFilter(emptyFilter);
    setSort(defaultSort);
  };
  return { filter, setFilter, sort, setSort, pageSize, setPageSize, reset };
}
```

- [ ] **Step 2: `useResourceMutations.ts`**

```ts
"use client";

import { useState } from "react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import type { CrudRepository } from "../domain/CrudRepository";

/** create/update/delete lifecycle over a container-resolved repository. */
export function useResourceMutations<T, TInput>(repositoryKey: string) {
  const [submitting, setSubmitting] = useState(false);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const repo = (): CrudRepository<T, TInput> => container.get<CrudRepository<T, TInput>>(repositoryKey);

  const create = async (input: TInput): Promise<T> => repo().create(input);
  const update = async (id: string, input: TInput): Promise<T> => repo().update(id, input);
  const remove = async (id: string): Promise<void> => repo().delete(id);

  return { create, update, remove, submitting, setSubmitting, problem, setProblem, HttpError };
}
```
> Forms keep their own RHF submit/error handling (mirroring `BankForm`); this hook just resolves the repo and exposes thin calls. Keep it minimal — do not duplicate the form's violation-mapping here.

- [ ] **Step 3: `useResourceItem.ts`**

```ts
"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { ViewStatus } from "@/context/shared/domain/types/status";
import type { CrudRepository } from "../domain/CrudRepository";

/** Single-item fetch lifecycle for detail/edit pages. */
export function useResourceItem<T, TInput>(repositoryKey: string, id: string) {
  const [item, setItem] = useState<T | null>(null);
  const [state, setState] = useState<ViewStatus>(ViewStatus.LOADING);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);

  const load = useCallback(async () => {
    setState(ViewStatus.LOADING);
    setProblem(null);
    try {
      const result = await container.get<CrudRepository<T, TInput>>(repositoryKey).find(id);
      if (!mounted.current) return;
      setItem(result);
      setState(ViewStatus.READY);
    } catch (err) {
      if (!mounted.current) return;
      setProblem(err instanceof HttpError ? err.problem : null);
      setState(ViewStatus.ERROR);
    }
  }, [repositoryKey, id]);

  useEffect(() => { load(); }, [load]); // eslint-disable-line react-hooks/set-state-in-effect

  return { item, state, problem, reload: load };
}
```

- [ ] **Step 4: Typecheck + commit**

```bash
cd pwa && npx tsc --noEmit
git add pwa/src/context/shared/resource/application/createQueryState.ts pwa/src/context/shared/resource/application/useResourceMutations.ts pwa/src/context/shared/resource/application/useResourceItem.ts
git commit -m "feat(pwa): resource query-state, mutations, and item hooks"
```

---

### Task 11: `useResourceList` — list state machine

**Files:**
- Create: `src/context/shared/resource/application/useResourceList.ts`

This generalizes the proven state machine in `src/app/backoffice/banks/page.tsx`
(lines ~95–581): load (criteria-search vs follow-link), monotonic `seq` guard,
query-reset-during-render, derived `boundaryState`, selection set, optimistic single
delete, optimistic bulk delete with re-probe, peek id, empty-page recovery. **Omit
the Mercure realtime block entirely** (no event source) — keep the `silentReload`
helper exposed for callers that want manual refresh.

- [ ] **Step 1: Implement the hook**

Port `banks/page.tsx` logic with these substitutions:
- Generic over `<T extends { id: string }, F>`; items field is `items` (not `banks`).
- Inputs (config object): `repositoryKey`, `navigatorKey`, `query: QueryState<F>` (from `useQueryState`), `toCriteria(filter, sort, limit) => ResourceSearchCriteria`, `hasActiveFilter(filter) => boolean`, `getId(item)=>string` (default `item.id`), `getLabel(item)=>string` (for delete toasts), `entityNameSingular`/`Plural` (toast copy), `deletedToast?`.
- Resolve `SearchX`/`Navigator` by key from `container`; before a criteria search, if the resolved navigator exposes `remember`, call `navigator.remember(criteria)` (duck-typed, for the in-memory mock).
- Replace `container.get<SearchBanks>(...)` with `container.get<CrudRepository<T,TInput>>(repositoryKey).search(criteria)` and `container.get<...Navigator>(navigatorKey).follow(link)`.
- Replace `FindBank`/`DeleteBank` use-case calls with `repository.find`/`repository.delete`.
- Return everything the page needs: `{ state: boundaryState, rawState, items, pagination, problem, selectedIds, setSelectedIds, toggleSelect, clearSelection, navigateTo, reload: loadItems, silentReload, deleteItem: handleItemDeleted-equivalent, deleteFailed, deleteError, setDeleteError, handleDeleteErrorRefresh, peekId, setPeekId, bulkDelete, listContainerRef, announcements... }`.

Skeleton (fill the ported bodies — the Bank file is the line-by-line source):
```ts
"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { HttpStatus } from "@/context/shared/domain/types/http";
import { ViewStatus } from "@/context/shared/domain/types/status";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { uuidV7 } from "@/lib/uuidV7";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import type { PageEnvelope } from "@/context/shared/domain/Search";
import type { CrudRepository, ResourceSearchCriteria, ResourceSearchPage } from "../domain/CrudRepository";
import type { ResourceSearchNavigator } from "../domain/ResourceSearchNavigator";
import type { QueryState } from "./createQueryState";

export interface DeleteErrorState {
  problem: ProblemDetails;
  itemId: string;
  scope: "single" | "bulk";
}

export interface UseResourceListConfig<T extends { id: string }, F, TInput> {
  repositoryKey: string;
  navigatorKey: string;
  query: QueryState<F>;
  toCriteria: (filter: F, sort: QueryState<F>["sort"], limit: number) => ResourceSearchCriteria;
  hasActiveFilter: (filter: F) => boolean;
  getLabel: (item: T) => string;
  entitySingular: string; // "user"
  entityPlural: string; // "users"
}

export function useResourceList<T extends { id: string }, F, TInput>(
  config: UseResourceListConfig<T, F, TInput>,
) {
  // ... PORT banks/page.tsx state machine here, swapping `banks`→`items`,
  // SearchBanks→repository.search, BankSearchNavigator→navigator.follow,
  // FindBank→repository.find, DeleteBank→repository.delete. OMIT useBankRealtime.
  // Use config.getLabel for delete toasts; config.entitySingular/Plural for copy.
  // Return the object listed in Step 1's description.
  throw new Error("port from banks/page.tsx");
}
```

- [ ] **Step 2: Typecheck**

Run: `cd pwa && npx tsc --noEmit`
Expected: PASS once the body is ported and all returns are typed.

- [ ] **Step 3: Smoke test (optional but recommended)**

Add `tests/context/shared/resource/useResourceList.test.tsx` rendering a trivial harness that wires the in-memory repo + navigator (bound under test keys) and asserts: initial LOADING→READY, items length == pageSize, `navigateTo(next)` advances. Run `npx vitest run` on it; expected PASS.

- [ ] **Step 4: Commit**

```bash
git add pwa/src/context/shared/resource/application/useResourceList.ts pwa/tests/context/shared/resource/useResourceList.test.tsx
git commit -m "feat(pwa): useResourceList state machine (generalized from banks list, no realtime)"
```

---

## Phase 3 — User domain, schemas, mock infra, DI

### Task 12: User domain

**Files:**
- Create: `src/context/backoffice/user/domain/User.ts`
- Create: `src/context/backoffice/user/domain/UserRepository.ts`
- Create: `src/context/backoffice/user/domain/UserProblemType.ts`

- [ ] **Step 1: Implement**

`User.ts`:
```ts
import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Role } from "@/context/shared/access/domain/Role";
import type { Permission } from "@/context/shared/access/domain/Permission";

export interface UserPrimitives {
  id: string;
  email: string;
  status: UserStatus;
  roles: Role[];
  permissions: Permission[];
  createdAt: string;
  updatedAt: string;
}

/** Identity aggregate (auth/authorization only — never a business entity). */
export class User {
  constructor(
    public readonly id: string,
    public readonly email: string,
    public readonly status: UserStatus,
    public readonly roles: Role[],
    public readonly permissions: Permission[],
    public readonly createdAt: string,
    public readonly updatedAt: string,
  ) {}

  static fromPrimitives(d: UserPrimitives): User {
    return new User(d.id, d.email, d.status, d.roles, d.permissions, d.createdAt, d.updatedAt);
  }
}
```

`UserRepository.ts`:
```ts
import type { CrudRepository } from "@/context/shared/resource/domain/CrudRepository";
import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Role } from "@/context/shared/access/domain/Role";
import type { Permission } from "@/context/shared/access/domain/Permission";
import type { User } from "./User";

export interface UserInput {
  email: string;
  roles: Role[];
  status: UserStatus;
  permissions?: Permission[];
}

export type UserRepository = CrudRepository<User, UserInput>;
```

`UserProblemType.ts`:
```ts
export const UserProblemType = {
  NOT_FOUND: "user-not-found",
  EMAIL_CONFLICT: "user-email-conflict",
} as const;
export type UserProblemType = (typeof UserProblemType)[keyof typeof UserProblemType];
```

- [ ] **Step 2: Typecheck + commit**

```bash
cd pwa && npx tsc --noEmit
git add pwa/src/context/backoffice/user/domain
git commit -m "feat(pwa): user domain (User, UserRepository port, problem types)"
```

---

### Task 13: User + auth Zod schemas (TDD on refinements)

**Files:**
- Create: `src/context/backoffice/user/application/schemas/UserCreateSchema.ts`
- Create: `src/context/backoffice/user/application/schemas/UserEditSchema.ts`
- Create: `src/context/backoffice/user/application/schemas/auth/LoginSchema.ts`
- Create: `src/context/backoffice/user/application/schemas/auth/RegisterSchema.ts`
- Create: `src/context/backoffice/user/application/schemas/auth/ForgotPasswordSchema.ts`
- Create: `src/context/backoffice/user/application/schemas/auth/ResetPasswordSchema.ts`
- Test: `tests/context/backoffice/user/schemas.test.ts`

Read `src/context/backoffice/bank/application/schemas/BankSchema.ts` for the exact
style (messages mirror API 422 strings; export schema + inferred `*FormValues`).

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from "vitest";
import { RegisterSchema } from "@/context/backoffice/user/application/schemas/auth/RegisterSchema";
import { ResetPasswordSchema } from "@/context/backoffice/user/application/schemas/auth/ResetPasswordSchema";
import { UserCreateSchema } from "@/context/backoffice/user/application/schemas/UserCreateSchema";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

describe("auth schemas", () => {
  it("RegisterSchema rejects mismatched passwords on confirmPassword", () => {
    const r = RegisterSchema.safeParse({ email: "a@b.com", password: "password1", confirmPassword: "password2" });
    expect(r.success).toBe(false);
    if (!r.success) expect(r.error.issues.some((i) => i.path.includes("confirmPassword"))).toBe(true);
  });
  it("ResetPasswordSchema accepts matching passwords", () => {
    expect(ResetPasswordSchema.safeParse({ password: "password1", confirmPassword: "password1" }).success).toBe(true);
  });
  it("UserCreateSchema requires at least one role", () => {
    const bad = UserCreateSchema.safeParse({ email: "a@b.com", roles: [], status: UserStatus.PENDING });
    expect(bad.success).toBe(false);
    const ok = UserCreateSchema.safeParse({ email: "a@b.com", roles: [Role.ADMIN], status: UserStatus.PENDING });
    expect(ok.success).toBe(true);
  });
});
```

- [ ] **Step 2: Run, verify fail** — `npx vitest run tests/context/backoffice/user/schemas.test.ts` → FAIL (modules missing).

- [ ] **Step 3: Implement schemas**

`UserCreateSchema.ts`:
```ts
import { z } from "zod";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

export const USER_EMAIL_MAX_LENGTH = 255;

export const UserCreateSchema = z.object({
  email: z
    .string({ error: "The email field is required." })
    .trim()
    .min(1, "The email field is required.")
    .max(USER_EMAIL_MAX_LENGTH, "The email must not exceed 255 characters.")
    .email("Enter a valid email address."),
  roles: z.array(z.nativeEnum(Role)).min(1, "Select at least one role."),
  status: z.nativeEnum(UserStatus),
});
export type UserCreateFormValues = z.infer<typeof UserCreateSchema>;
```

`UserEditSchema.ts`:
```ts
import { z } from "zod";
import { Role } from "@/context/shared/access/domain/Role";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

export const UserEditSchema = z.object({
  roles: z.array(z.nativeEnum(Role)).min(1, "Select at least one role."),
  status: z.nativeEnum(UserStatus),
  permissions: z.array(z.nativeEnum(Permission)).default([]),
});
export type UserEditFormValues = z.infer<typeof UserEditSchema>;
```

`auth/LoginSchema.ts`:
```ts
import { z } from "zod";
export const LoginSchema = z.object({
  email: z.string().trim().min(1, "The email field is required.").email("Enter a valid email address."),
  password: z.string().min(1, "The password field is required."),
});
export type LoginFormValues = z.infer<typeof LoginSchema>;
```

`auth/RegisterSchema.ts`:
```ts
import { z } from "zod";
export const RegisterSchema = z
  .object({
    email: z.string().trim().min(1, "The email field is required.").email("Enter a valid email address."),
    password: z.string().min(8, "The password must be at least 8 characters."),
    confirmPassword: z.string().min(1, "Confirm your password."),
  })
  .refine((v) => v.password === v.confirmPassword, {
    message: "Passwords do not match.",
    path: ["confirmPassword"],
  });
export type RegisterFormValues = z.infer<typeof RegisterSchema>;
```

`auth/ForgotPasswordSchema.ts`:
```ts
import { z } from "zod";
export const ForgotPasswordSchema = z.object({
  email: z.string().trim().min(1, "The email field is required.").email("Enter a valid email address."),
});
export type ForgotPasswordFormValues = z.infer<typeof ForgotPasswordSchema>;
```

`auth/ResetPasswordSchema.ts`:
```ts
import { z } from "zod";
export const ResetPasswordSchema = z
  .object({
    password: z.string().min(8, "The password must be at least 8 characters."),
    confirmPassword: z.string().min(1, "Confirm your password."),
  })
  .refine((v) => v.password === v.confirmPassword, {
    message: "Passwords do not match.",
    path: ["confirmPassword"],
  });
export type ResetPasswordFormValues = z.infer<typeof ResetPasswordSchema>;
```
> If `z.nativeEnum` is unavailable in the installed Zod version, read `BankSchema.ts`/`package.json` for the Zod version and use `z.enum([...])` over the const-map values instead.

- [ ] **Step 4: Run, verify pass** — `npx vitest run tests/context/backoffice/user/schemas.test.ts` → PASS.

- [ ] **Step 5: Commit**

```bash
git add pwa/src/context/backoffice/user/application/schemas pwa/tests/context/backoffice/user/schemas.test.ts
git commit -m "feat(pwa): user + auth zod schemas with validation"
```

---

### Task 14: `InMemoryUserRepository` + seed + DI binding

**Files:**
- Create: `src/context/backoffice/user/infrastructure/userSeed.ts`
- Create: `src/context/backoffice/user/infrastructure/InMemoryUserRepository.ts`
- Modify: `src/context/shared/infrastructure/DependencyInjection/Container.ts`

- [ ] **Step 1: `userSeed.ts`** — ~25 deterministic users.

```ts
import type { UserPrimitives } from "../domain/User";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Role } from "@/context/shared/access/domain/Role";
import { Permission } from "@/context/shared/access/domain/Permission";

const STATUSES = [UserStatus.ACTIVE, UserStatus.PENDING, UserStatus.BLOCKED];
const ROLES = [Role.ADMIN, Role.EMPLOYEE, Role.CUSTOMER, Role.SUPPLIER, Role.SUPER_ADMIN];

/** Fixed-id deterministic seed (uuid v7-shaped strings) so tests/snapshots are stable. */
export const userSeed: UserPrimitives[] = Array.from({ length: 25 }, (_, i) => {
  const n = String(i + 1).padStart(3, "0");
  const role = ROLES[i % ROLES.length];
  const status = STATUSES[i % STATUSES.length];
  const created = `2026-0${(i % 6) + 1}-1${i % 9}T09:0${i % 6}:00.000Z`;
  return {
    id: `00000000-0000-7000-8000-0000000${n}00`,
    email: `user${n}@erpify.dev`,
    status,
    roles: [role],
    permissions: role === Role.ADMIN ? [Permission.USERS_READ, Permission.USERS_WRITE] : [Permission.USERS_READ],
    createdAt: created,
    updatedAt: created,
  };
});
```

- [ ] **Step 2: `InMemoryUserRepository.ts`**

```ts
import { injectable } from "inversify";
import { FilterOperator, type Filter } from "@/context/shared/domain/Search";
import { InMemoryCrudRepository, type InMemoryEntityConfig } from "@/context/shared/resource/infrastructure/InMemoryCrudRepository";
import { uuidV7 } from "@/lib/uuidV7";
import { User } from "../domain/User";
import type { UserInput } from "../domain/UserRepository";
import { userSeed } from "./userSeed";

const config: InMemoryEntityConfig<User, UserInput> = {
  matchesFilter: (user, filter: Filter): boolean => {
    if (filter.field === "email" && filter.operator === FilterOperator.Contains) {
      return user.email.toLowerCase().includes(String(filter.value).toLowerCase());
    }
    if (filter.field === "status" && filter.operator === FilterOperator.Eq) {
      return user.status === filter.value;
    }
    if (filter.field === "role" && filter.operator === FilterOperator.Eq) {
      return user.roles.includes(filter.value as User["roles"][number]);
    }
    return true;
  },
  compare: (field) => (a, b) => {
    if (field === "status") return a.status.localeCompare(b.status);
    if (field === "createdAt") return a.createdAt.localeCompare(b.createdAt);
    if (field === "updatedAt") return a.updatedAt.localeCompare(b.updatedAt);
    return a.email.localeCompare(b.email);
  },
  fromInput: (input, id, nowIso) =>
    new User(id, input.email, input.status, input.roles, input.permissions ?? [], nowIso, nowIso),
  applyInput: (existing, input, nowIso) =>
    new User(existing.id, existing.email, input.status, input.roles, input.permissions ?? existing.permissions, existing.createdAt, nowIso),
};

/**
 * Mocked user repository over the generic in-memory base. ~250ms latency makes
 * the loading skeletons visible. Swap for an ApiUserRepository to go live.
 */
@injectable()
export class InMemoryUserRepository extends InMemoryCrudRepository<User, UserInput> {
  constructor() {
    super(
      userSeed.map(User.fromPrimitives),
      config,
      () => uuidV7(),
      () => new Date().toISOString(),
      250,
    );
  }
}
```
> `new Date().toISOString()` is allowed in a runtime adapter (not a workflow script); the `uuidV7`/clock injection in the base keeps tests deterministic via the test config.

- [ ] **Step 3: DI binding** — in `Container.ts`, after the Bank block, add:

```ts
import { InMemoryUserRepository } from "../../../backoffice/user/infrastructure/InMemoryUserRepository";
import { InMemoryResourceNavigator } from "../../../shared/resource/infrastructure/InMemoryResourceNavigator";
```
and in the bindings:
```ts
const userRepository = new InMemoryUserRepository();
container.bind("BackOfficeUserRepository").toConstantValue(userRepository);
container
  .bind("BackOfficeUserSearchNavigator")
  .toConstantValue(new InMemoryResourceNavigator(userRepository));
```
> Bind the SAME repository instance into both keys so the navigator shares the in-memory store (a singleton class binding would create two stores). Mirror the existing import style/paths in the file.

- [ ] **Step 4: Typecheck + quality + commit**

```bash
cd pwa && npx tsc --noEmit && make pwa.quality
git add pwa/src/context/backoffice/user/infrastructure pwa/src/context/shared/infrastructure/DependencyInjection/Container.ts
git commit -m "feat(pwa): in-memory user repository + seed + DI bindings"
```

---

## Phase 4 — User backoffice UI

### Task 15: User `_lib` (routes, filter/sort, criteria, columns, recency, paginate)

**Files:**
- Create: `src/app/backoffice/users/_lib/userRoutes.ts`
- Create: `src/app/backoffice/users/_lib/usersFilterSort.ts`
- Create: `src/app/backoffice/users/_lib/usersSearchCriteria.ts`
- Create: `src/app/backoffice/users/_lib/paginate.ts`
- Create: `src/app/backoffice/users/_lib/userRecency.ts`
- Create: `src/app/backoffice/users/_lib/userColumns.ts`

- [ ] **Step 1: `userRoutes.ts`** (mirror `bankRoutes.ts`)
```ts
import { Routes } from "@/context/shared/domain/types/routes";
const USERS_BASE = `${Routes.BACKOFFICE}/users` as const;
export const userRoutes = {
  list: USERS_BASE,
  new: `${USERS_BASE}/new`,
  detail: (id: string): string => `${USERS_BASE}/${encodeURIComponent(id)}`,
  edit: (id: string): string => `${USERS_BASE}/${encodeURIComponent(id)}/edit`,
} as const;
```

- [ ] **Step 2: `usersFilterSort.ts`** — mirror `banksFilterSort.ts` shape with the user fields:
```ts
import type { DataTableSort } from "@/components/erpify";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import type { Role } from "@/context/shared/access/domain/Role";
import type { UserStatus } from "@/context/shared/access/domain/UserStatus";

export interface UsersFilter {
  email: string;
  role: Role | "";
  status: UserStatus | "";
}
export type UsersSort = DataTableSort | null;
export const DEFAULT_SORT: UsersSort = { columnId: "email", direction: SortDirection.ASC };
export const EMPTY_FILTER: UsersFilter = { email: "", role: "", status: "" };

export function isDefaultSort(sort: UsersSort): boolean {
  if (!sort || !DEFAULT_SORT) return sort === DEFAULT_SORT;
  return sort.columnId === DEFAULT_SORT.columnId && sort.direction === DEFAULT_SORT.direction;
}
export function hasActiveFilter(f: UsersFilter): boolean {
  return Boolean(f.email.trim()) || f.role !== "" || f.status !== "";
}
export const USERS_SORTABLE_COLUMNS = [
  { id: "email", label: "Email" },
  { id: "status", label: "Status" },
  { id: "createdAt", label: "Created" },
  { id: "updatedAt", label: "Updated" },
] as const;
```

- [ ] **Step 3: `usersSearchCriteria.ts`** — map UI filter → `Filter[]` and sort:
```ts
import { FilterOperator, type Filter } from "@/context/shared/domain/Search";
import type { ResourceSort } from "@/context/shared/resource/domain/ResourceSort";
import type { UsersFilter, UsersSort } from "./usersFilterSort";

export function toUserFilters(filter: UsersFilter): Filter[] {
  const filters: Filter[] = [];
  const email = filter.email.trim();
  if (email) filters.push({ field: "email", operator: FilterOperator.Contains, value: email });
  if (filter.role) filters.push({ field: "role", operator: FilterOperator.Eq, value: filter.role });
  if (filter.status) filters.push({ field: "status", operator: FilterOperator.Eq, value: filter.status });
  return filters;
}
export function toUserSort(sort: UsersSort): ResourceSort | null {
  return sort ? { field: sort.columnId, direction: sort.direction } : null;
}
```

- [ ] **Step 4: `paginate.ts`** (mirror Bank):
```ts
export const USERS_PAGE_SIZE_OPTIONS = [25, 50, 100] as const;
export type UsersPageSize = (typeof USERS_PAGE_SIZE_OPTIONS)[number];
export const USERS_PAGE_SIZE_DEFAULT: UsersPageSize = 25;
```

- [ ] **Step 5: `userRecency.ts`** — copy `bankRecency.ts` verbatim, rename the constant to `USER_NEW_WINDOW_DAYS`.

- [ ] **Step 6: `userColumns.ts`** — column-picker config:
```ts
export const USER_COLUMN_KEYS = ["email", "roles", "status", "createdAt", "updatedAt"] as const;
export type UserColumnKey = (typeof USER_COLUMN_KEYS)[number];
/** `email` is pinned (always visible); the rest are toggleable. */
export const PINNED_COLUMNS: readonly UserColumnKey[] = ["email"];
export const TOGGLEABLE_COLUMNS: readonly UserColumnKey[] = ["roles", "status", "createdAt", "updatedAt"];
export const DEFAULT_VISIBLE_COLUMNS: readonly UserColumnKey[] = ["email", "roles", "status", "createdAt"];
export const USERS_COLUMNS_STORAGE_KEY = "erpify:users-columns";
export function isUserColumnKeyArray(v: unknown): v is UserColumnKey[] {
  return Array.isArray(v) && v.every((x) => (USER_COLUMN_KEYS as readonly string[]).includes(x));
}
```

- [ ] **Step 7: Typecheck + commit**

```bash
cd pwa && npx tsc --noEmit
git add pwa/src/app/backoffice/users/_lib
git commit -m "feat(pwa): users _lib (routes, filter/sort, criteria, columns)"
```

---

### Task 16: Status/role badges + row actions + delete button

**Files:**
- Create: `src/app/backoffice/users/_components/UserStatusBadge.tsx`
- Create: `src/app/backoffice/users/_components/RolesBadges.tsx`
- Create: `src/app/backoffice/users/_components/DeleteUserButton.tsx`
- Create: `src/app/backoffice/users/_components/UserRowActions.tsx`

- [ ] **Step 1: `UserStatusBadge.tsx`**
```tsx
import { StatusBadge } from "@/components/erpify";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

const VARIANT: Record<UserStatus, "success" | "warning" | "danger"> = {
  [UserStatus.ACTIVE]: "success",
  [UserStatus.PENDING]: "warning",
  [UserStatus.BLOCKED]: "danger",
};
const LABEL: Record<UserStatus, string> = {
  [UserStatus.ACTIVE]: "Active",
  [UserStatus.PENDING]: "Pending",
  [UserStatus.BLOCKED]: "Blocked",
};
export function UserStatusBadge({ status, testId }: Readonly<{ status: UserStatus; testId?: string }>) {
  return <StatusBadge variant={VARIANT[status]} label={LABEL[status]} testId={testId} />;
}
```

- [ ] **Step 2: `RolesBadges.tsx`** — render each role as a neutral `<StatusBadge variant="neutral">`, wrapped in a flex-wrap container; if >2 roles show first two + `+N`.
```tsx
import { StatusBadge } from "@/components/erpify";
import type { Role } from "@/context/shared/access/domain/Role";

const ROLE_LABEL: Record<string, string> = {
  SUPER_ADMIN: "Super Admin", ADMIN: "Admin", EMPLOYEE: "Employee", CUSTOMER: "Customer", SUPPLIER: "Supplier",
};
export function RolesBadges({ roles, testId }: Readonly<{ roles: Role[]; testId?: string }>) {
  const shown = roles.slice(0, 2);
  const extra = roles.length - shown.length;
  return (
    <div className="flex flex-wrap items-center gap-1" data-testid={testId}>
      {shown.map((r) => <StatusBadge key={r} variant="neutral" label={ROLE_LABEL[r] ?? r} />)}
      {extra > 0 ? <StatusBadge variant="info" label={`+${extra}`} /> : null}
    </div>
  );
}
```

- [ ] **Step 3: `DeleteUserButton.tsx` + `UserRowActions.tsx`** — port `DeleteBankButton.tsx` and `BankRowActions.tsx` verbatim, swapping: `bank`→`user`, `DeleteBank` use-case → `container.get<UserRepository>("BackOfficeUserRepository").delete(id)`, `BankProblemType`→`UserProblemType`, `bankRoutes`→`userRoutes`, testids `banks-*`→`users-*`. Wrap the Edit/Delete controls in `<Can permission={Permission.USERS_WRITE}>` / `<Can permission={Permission.USERS_DELETE}>` from `@/context/shared/access/infrastructure/ui`.

- [ ] **Step 4: Typecheck + quality + commit**

```bash
cd pwa && npx tsc --noEmit && make pwa.quality
git add pwa/src/app/backoffice/users/_components/{UserStatusBadge,RolesBadges,DeleteUserButton,UserRowActions}.tsx
git commit -m "feat(pwa): user badges + permission-gated row actions"
```

---

### Task 17: Column picker + filters + pagination + view toggle + skeleton + empty

**Files:**
- Create: `src/app/backoffice/users/_components/UsersColumnPicker.tsx`
- Create: `src/app/backoffice/users/_components/UsersFilters.tsx`
- Create: `src/app/backoffice/users/_components/UsersPagination.tsx`
- Create: `src/app/backoffice/users/_components/UsersViewToggle.tsx`
- Create: `src/app/backoffice/users/_components/UsersListSkeleton.tsx`
- Create: `src/app/backoffice/users/_components/UsersEmptyFiltered.tsx`

- [ ] **Step 1: `UsersColumnPicker.tsx`** (NEW feature) — read `src/components/ui/{popover,dropdown-menu}.tsx` and the existing `DensityToggle` for the trigger idiom. A button (`Columns3` icon) opening a popover with a checkbox per `TOGGLEABLE_COLUMNS` (email shown disabled/checked as pinned). Props: `visible: UserColumnKey[]`, `onChange(next)`, `testId`. Toggling updates the array; email cannot be removed.
```tsx
"use client";
import { Columns3 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { TOGGLEABLE_COLUMNS, PINNED_COLUMNS, type UserColumnKey } from "../_lib/userColumns";

const LABEL: Record<UserColumnKey, string> = {
  email: "Email", roles: "Roles", status: "Status", createdAt: "Created", updatedAt: "Updated",
};
export function UsersColumnPicker({
  visible, onChange, testId,
}: Readonly<{ visible: UserColumnKey[]; onChange: (n: UserColumnKey[]) => void; testId?: string }>) {
  const toggle = (key: UserColumnKey): void =>
    onChange(visible.includes(key) ? visible.filter((k) => k !== key) : [...visible, key]);
  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" size="sm" aria-label="Choose columns" title="Choose visible columns" data-testid={testId} data-icon="inline-start">
          <Columns3 className="size-3.5" aria-hidden="true" /> Columns
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-44">
        <fieldset className="space-y-2">
          <legend className="sr-only">Visible columns</legend>
          {PINNED_COLUMNS.map((k) => (
            <label key={k} className="flex items-center gap-2 text-sm opacity-60">
              <input type="checkbox" checked readOnly aria-label={`${LABEL[k]} (always shown)`} /> {LABEL[k]}
            </label>
          ))}
          {TOGGLEABLE_COLUMNS.map((k) => (
            <label key={k} className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={visible.includes(k)} onChange={() => toggle(k)} data-testid={`users-columns__${k}`} /> {LABEL[k]}
            </label>
          ))}
        </fieldset>
      </PopoverContent>
    </Popover>
  );
}
```
> If `@/components/ui/popover` doesn't exist, read `components/ui/` and use `dropdown-menu` with `DropdownMenuCheckboxItem` instead. No new DS primitive.

- [ ] **Step 2: `UsersFilters.tsx`** — port `BanksFilters.tsx`, replacing the field set with: an always-visible email search input + a Role `<select>` + a Status `<select>` (use the existing select primitive Bank's filters use). Accept the same `leading` slot used by the list to host view/density/column-picker. Keep the debounce pattern Bank uses for the search box.

- [ ] **Step 3: `UsersPagination.tsx`, `UsersViewToggle.tsx`, `UsersListSkeleton.tsx`, `UsersEmptyFiltered.tsx`** — port the Bank equivalents verbatim, swapping `banks-*`→`users-*` testids, `BANKS_PAGE_SIZE_*`→`USERS_PAGE_SIZE_*`, and the skeleton column count to match the user columns. `UsersViewToggle` keeps `"table" | "cards"`.

- [ ] **Step 4: Typecheck + quality + commit**

```bash
cd pwa && npx tsc --noEmit && make pwa.quality
git add pwa/src/app/backoffice/users/_components
git commit -m "feat(pwa): users filters, pagination, view toggle, column picker, skeleton, empty"
```

---

### Task 18: `UsersTable` / `UsersCards` / `UsersStackedList` / `UsersBulkBar`

**Files:**
- Create: `src/app/backoffice/users/_components/UsersTable.tsx`
- Create: `src/app/backoffice/users/_components/UsersCards.tsx`
- Create: `src/app/backoffice/users/_components/UsersStackedList.tsx`
- Create: `src/app/backoffice/users/_components/UsersBulkBar.tsx`

- [ ] **Step 1: `UsersTable.tsx`** — port `BanksTable.tsx`. Build columns from the `visible: UserColumnKey[]` prop (passed down from the page). Columns: `email` (`<TruncatedText>`, pinned, sortable), `roles` (`<RolesBadges>`, not sortable), `status` (`<UserStatusBadge>`, sortable), `createdAt`/`updatedAt` (relative cell w/ `title`, sortable), `actions` (`<UserRowActions>`). Only include a column when its key is in `visible` (email always). `onRowActivate` → `router.push(safeHref(userRoutes.detail(row.id)))`. `rowTestId={(row)=>`users-table__row-${row.id}`}`.

- [ ] **Step 2: `UsersCards.tsx`, `UsersStackedList.tsx`, `UsersBulkBar.tsx`** — port the Bank equivalents, swapping entity/fields/testids. Cards/stacked show email (title), roles badges, status badge, created relative. Bulk bar copy: "user"/"users".

- [ ] **Step 3: Typecheck + quality + commit**

```bash
cd pwa && npx tsc --noEmit && make pwa.quality
git add pwa/src/app/backoffice/users/_components/{UsersTable,UsersCards,UsersStackedList,UsersBulkBar}.tsx
git commit -m "feat(pwa): users table, cards, stacked list, bulk bar"
```

---

### Task 19: User list page (wires `useResourceList`)

**Files:**
- Modify (replace placeholder): `src/app/backoffice/users/page.tsx`

- [ ] **Step 1: Implement** — model on `banks/page.tsx` but thin: state comes from `useQueryState` + `useResourceList`. The page owns: the `view`/`density`/`columns` persisted prefs (`useStoredPreference`), the header + New button (wrapped in `<Can permission={Permission.USERS_WRITE}>`), `UsersFilters` (with `UsersViewToggle`/`DensityToggle`/`UsersColumnPicker` in the `leading` slot), the `AsyncBoundary`, the table/cards/stacked switch (passing `visible` columns), `UsersPagination`, `UsersBulkBar`, the peek `RecordSheet`, and the `MutationError` from `deleteError`. Use `userRoutes`, `toUserFilters`/`toUserSort`, `EMPTY_FILTER`/`DEFAULT_SORT`/`hasActiveFilter` from `_lib`. Repository/navigator keys: `"BackOfficeUserRepository"` / `"BackOfficeUserSearchNavigator"`. NO realtime hook.

Key wiring snippet:
```tsx
const query = useQueryState<UsersFilter>({ emptyFilter: EMPTY_FILTER, defaultSort: toUserSort(DEFAULT_SORT), defaultPageSize: USERS_PAGE_SIZE_DEFAULT });
const list = useResourceList<User, UsersFilter, UserInput>({
  repositoryKey: "BackOfficeUserRepository",
  navigatorKey: "BackOfficeUserSearchNavigator",
  query,
  toCriteria: (filter, sort, limit) => ({ filters: toUserFilters(filter), sort, limit }),
  hasActiveFilter,
  getLabel: (u) => u.email,
  entitySingular: "user",
  entityPlural: "users",
});
const [columns, setColumns] = useStoredPreference<UserColumnKey[]>(USERS_COLUMNS_STORAGE_KEY, [...DEFAULT_VISIBLE_COLUMNS], isUserColumnKeyArray);
```
> `query.sort` is a `ResourceSort | null`, but the table's `DataTableSort` uses `{columnId,direction}`. Reuse Bank's approach: keep the UI `UsersSort` (`DataTableSort`) in the page for the table, and map to `ResourceSort` via `toUserSort` when building criteria. Adjust `useQueryState`'s generic to hold the UI sort if simpler — match whatever keeps the table's `sort`/`onSortChange` types intact (Bank passes `DataTableSort` to `BanksTable`).

- [ ] **Step 2: Typecheck + quality**

Run: `cd pwa && npx tsc --noEmit && make pwa.quality`
Expected: PASS.

- [ ] **Step 3: Manual verification (stack running)** — `/backoffice/users` lists seeded users, search/role/status filters work, sort works, pagination advances, column picker hides/shows columns and persists across reload, delete shows toast + optimistic removal, bulk select + delete works.

- [ ] **Step 4: Commit**

```bash
git add pwa/src/app/backoffice/users/page.tsx
git commit -m "feat(pwa): users list page wired to resource toolkit"
```

---

### Task 20: User create / detail / edit pages + `UserForm`

**Files:**
- Create: `src/app/backoffice/users/_components/UserForm.tsx`
- Create: `src/app/backoffice/users/new/page.tsx`
- Create: `src/app/backoffice/users/[id]/page.tsx`
- Create: `src/app/backoffice/users/[id]/edit/page.tsx`

- [ ] **Step 1: `UserForm.tsx`** — port `BankForm.tsx`. Two modes (CREATE/EDIT via `PersistenceAction`). Create fields: email (`Input`, email type), roles (multi-select — build from `ALL_ROLES` using the existing select/checkbox primitive; reuse the same control pattern as `UsersFilters` role select but multi), status (select, default PENDING). Edit fields: roles, status, permissions (multi-select over `ALL_PERMISSIONS`; visible by default — `email` shown read-only). Submit calls `container.get<UserRepository>("BackOfficeUserRepository").create/update`. On success: toast + `router.push(safeHref(userRoutes.detail(id)))` + `router.refresh()`. Keep the server-violation→`setError` mapping (mock won't emit, but stays API-ready). Map `UserProblemType.EMAIL_CONFLICT`/`NOT_FOUND` to the `MutationError` recovery action like Bank.

- [ ] **Step 2: `new/page.tsx`** — `"use client"`; render breadcrumb/header "New user" + `<UserForm mode={PersistenceAction.CREATING} />`, the whole page guarded by `<Can permission={Permission.USERS_WRITE} fallback={<AccessDenied/>}>` (or redirect). Mirror `banks/new/page.tsx` structure.

- [ ] **Step 3: `[id]/page.tsx`** (detail, read-only) — `"use client"`; use `useResourceItem<User,UserInput>("BackOfficeUserRepository", id)` to load. Render `AsyncBoundary` → a definition list: email, roles (`RolesBadges`), permissions (badge list or "—"), status (`UserStatusBadge`), createdAt/updatedAt (`dateTimeProvider.formatIsoToLocalDateTime`). Header actions: Edit link wrapped in `<Can permission={Permission.USERS_WRITE}>`, Delete via `DeleteUserButton`. Mirror `banks/[id]/page.tsx`.

- [ ] **Step 4: `[id]/edit/page.tsx`** — `"use client"`; load via `useResourceItem`, then render `<UserForm mode={PersistenceAction.UPDATING} initial={...} onStaleUser={reload} />`. Guarded by `<Can permission={Permission.USERS_WRITE}>`. Mirror `banks/[id]/edit/page.tsx`.

- [ ] **Step 5: Typecheck + quality + commit**

```bash
cd pwa && npx tsc --noEmit && make pwa.quality
git add pwa/src/app/backoffice/users/_components/UserForm.tsx pwa/src/app/backoffice/users/new pwa/src/app/backoffice/users/[id]
git commit -m "feat(pwa): user create/detail/edit pages + UserForm"
```

---

## Phase 5 — Public auth pages

### Task 21: `(auth)` layout + auth route helper

**Files:**
- Create: `src/app/(auth)/layout.tsx`
- Create: `src/app/(auth)/_lib/authRoutes.ts` (if not added in Task 6 Step — confirm; else extend)

- [ ] **Step 1: `(auth)/layout.tsx`** — a centered card shell (token-driven, reuse `Logo` from `@/components/erpify`). `metadata` with `robots: { index: false }` is fine. No backoffice chrome.
```tsx
import type { ReactNode } from "react";
import { Logo } from "@/components/erpify";

export default function AuthLayout({ children }: Readonly<{ children: ReactNode }>) {
  return (
    <div className="bg-background flex min-h-dvh items-center justify-center px-4 py-10">
      <div className="w-full max-w-sm space-y-6">
        <div className="flex justify-center"><Logo /></div>
        <div className="border-border bg-card rounded-lg border p-6 shadow-sm">{children}</div>
      </div>
    </div>
  );
}
```
> Confirm `Logo` is exported from `@/components/erpify`; if it needs props, read its file.

- [ ] **Step 2: Typecheck + commit**

```bash
cd pwa && npx tsc --noEmit
git add "pwa/src/app/(auth)/layout.tsx"
git commit -m "feat(pwa): public auth route-group layout"
```

---

### Task 22: Auth forms + pages

**Files:**
- Create: `src/app/(auth)/_components/{LoginForm,RegisterForm,ForgotPasswordForm,ResetPasswordForm}.tsx`
- Create: `src/app/(auth)/{login,register,forgot-password,reset-password}/page.tsx`

Read `BankForm.tsx` for the `useZodForm` + `FormField` + submit pattern.

- [ ] **Step 1: `LoginForm.tsx`**
```tsx
"use client";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useZodForm } from "@/context/shared/infrastructure/Validation";
import { LoginSchema, type LoginFormValues } from "@/context/backoffice/user/application/schemas/auth/LoginSchema";
import { FormField } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useSession } from "@/context/shared/access/application/useSession";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Role } from "@/context/shared/access/domain/Role";
import { PERMISSION_WILDCARD } from "@/context/shared/access/domain/Permission";
import { Routes } from "@/context/shared/domain/types/routes";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { uuidV7 } from "@/lib/uuidV7";
import { safeHref } from "@/lib/safeHref";

export function LoginForm() {
  const router = useRouter();
  const { login } = useSession();
  const { register, handleSubmit, formState: { errors, isSubmitting } } =
    useZodForm<LoginFormValues>(LoginSchema, { defaultValues: { email: "", password: "" } });

  const onSubmit = handleSubmit((values) => {
    // Mocked: no validation, seed an ADMIN identity from the typed email.
    login({
      id: uuidV7(), email: values.email, status: UserStatus.ACTIVE,
      roles: [Role.ADMIN], permissions: [PERMISSION_WILDCARD],
    });
    toastNotifier.success("Signed in");
    router.push(safeHref(Routes.BACKOFFICE));
  });

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate data-testid="login-form">
      <h1 className="text-foreground text-xl font-semibold">Sign in</h1>
      <FormField name="email" label="Email" required error={errors.email?.message}>
        <Input type="email" autoComplete="email" {...register("email")} data-testid="login-form__email" />
      </FormField>
      <FormField name="password" label="Password" required error={errors.password?.message}>
        <Input type="password" autoComplete="current-password" {...register("password")} data-testid="login-form__password" />
      </FormField>
      <Button type="submit" disabled={isSubmitting} className="w-full" data-testid="login-form__submit">Sign in</Button>
      <div className="flex justify-between text-sm">
        <Link href={Routes.FORGOT_PASSWORD} className="text-brand">Forgot password?</Link>
        <Link href={Routes.REGISTER} className="text-brand">Create account</Link>
      </div>
    </form>
  );
}
```

- [ ] **Step 2: `RegisterForm.tsx`** — email/password/confirmPassword via `RegisterSchema`; submit → `toastNotifier.success("Account created — please sign in")` → `router.push(Routes.LOGIN)`. Link back to login. `password` never stored.

- [ ] **Step 3: `ForgotPasswordForm.tsx`** — email via `ForgotPasswordSchema`; submit → `toastNotifier.success("If that email exists, a reset link was sent")` → stay or push `/login`. Link back to login.

- [ ] **Step 4: `ResetPasswordForm.tsx`** — password/confirmPassword via `ResetPasswordSchema`; submit → toast + `router.push(Routes.LOGIN)`.

- [ ] **Step 5: The four `page.tsx`** — each is a thin client page rendering its form:
```tsx
import { LoginForm } from "../_components/LoginForm";
export default function LoginPage() { return <LoginForm />; }
```
(and analogously for register/forgot-password/reset-password).

- [ ] **Step 6: Typecheck + quality + commit**

```bash
cd pwa && npx tsc --noEmit && make pwa.quality
git add "pwa/src/app/(auth)"
git commit -m "feat(pwa): public auth pages (login/register/forgot/reset, mocked)"
```

---

## Phase 6 — Verification & polish

### Task 23: Full quality gate + targeted tests + security self-review

**Files:** none new (fixes only).

- [ ] **Step 1: Run the unit suite**

Run: `cd pwa && make pwa.test.unit`
Expected: PASS, including `authorize.test.ts`, `useCan.test.tsx`, `InMemoryCrudRepository.test.ts`, `schemas.test.ts`, the `next-public-env-allowlist` and `data-testid-uniqueness` guards.
Fix any `data-testid` collisions (every `users-*` literal unique; reusable components use `testId` props).

- [ ] **Step 2: Lint/format**

Run: `cd pwa && make pwa.quality`
Expected: PASS. Fix ESLint (rules-of-hooks, `no-restricted-syntax` maxLength ban, BEM) and Prettier.

- [ ] **Step 3: Security self-review (per `pwa/CLAUDE.md`)** — verify in the diff:
  - every dynamic `href`/`router.push` wrapped in `safeHref` (detail/edit/peek links, auth redirects);
  - no password/secret written to `localStorage`/`sessionStorage` (session stores identity only — confirm `password` never leaves form values);
  - no `dangerouslySetInnerHTML`/`innerHTML`/`eval`;
  - static `aria-label`s, dynamic detail only in `title`;
  - `next.config.ts` headers/CSP and `proxy.ts` matchers unchanged (no new `NEXT_PUBLIC_*`).

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "test(pwa): green unit suite + quality gate for IAM/user module"
```

---

### Task 24: Docs touch-ups + spec cleanup

**Files:**
- Modify: `docs/architecture-pwa.md` (note the `context/shared/access` + `context/shared/resource` toolkits and the User module as their first consumer).
- Modify: `pwa/docs/` index/module-boundaries doc if it enumerates modules.
- Delete (when status flips to done): `docs/superpowers/specs/2026-06-14-iam-user-management-frontend-design.md` is a durable design doc, NOT a bmad transient spec — keep it. (No `_bmad-output` spec was created here.)

- [ ] **Step 1: Update `docs/architecture-pwa.md`** with a short subsection: the two shared toolkits (purpose, that they abstract *logic* not *structure*), the access layer (session/guards mocked), and that Bank is untouched. Link the design spec.

- [ ] **Step 2: Quality on markdown** (link style: only concrete files, no glob/dir hrefs).

- [ ] **Step 3: Commit**

```bash
git add docs pwa/docs
git commit -m "docs(pwa): document access + resource toolkits and user module"
```

---

## Self-Review (completed against the spec)

- **Spec coverage:** routes `/backoffice/users` (T15/T19/T20) ✓; cursor envelope + opaque links + navigator (T8/T9) ✓ with no realtime (T11) ✓; IAM session-driven + evaluator + `useCan`/`<Can>`/`RequireAuth` + dev switcher (T1–T7) ✓; lean CRUD core hooks (T8–T11) ✓; column picker (T17) ✓; AccessPolicyRegistry empty seam (T2) ✓; access-vs-business status boundary (T1 doc + `UserStatus` in access domain) ✓; auth pages forms + mocked login establishing session (T21/T22) ✓; states via existing primitives (T16–T20) ✓; tests + quality + security (T23) ✓; docs (T24) ✓. No uncovered spec section.
- **Placeholder scan:** the only "port from reference" directives point at concrete, readable repo files (`banks/page.tsx`, `BankForm.tsx`, etc.) with explicit substitution lists — not vague TODOs. `useResourceList` body (T11) is the one large port; its required return surface is enumerated.
- **Type consistency:** `ResourceSearchPage<T>` uses `items`; `CrudRepository<T,TInput>`; `UserInput` matches the schema fields; repo/navigator DI keys `"BackOfficeUserRepository"`/`"BackOfficeUserSearchNavigator"` consistent across T14/T16/T19/T20; `UserStatus`/`Role`/`Permission` imported from `context/shared/access/domain` everywhere.

---

## Review Findings — Group 1: shared/access + shared/resource cores (2026-06-14)

> Adversarial review (Blind Hunter + Edge Case Hunter + Acceptance Auditor), DDD/Codely lens. Scope: the two reusable cores only. Layer-3 consumers (user module, pages, auth) reviewed in later passes.

### Decision needed — RESOLVED & APPLIED

- [x] [Review][Decision] Stateful navigator + leaky `remember()` abstraction → **Resolved: Option A (stateless cursor-encoded navigation).** The opaque link now carries `{filters, sort, limit, offset}`; `InMemoryResourceNavigator.follow(link)` decodes both with no remembered state, `remember()`/`lastCriteria` removed, and the `(nav as { remember? })` cast dropped from `useResourceList`. Matches the stateless `ApiBankSearchNavigator`.
- [x] [Review][Decision] SSR/hydration seed-admin flash → **Resolved: Option 2 (hydration gate now).** `AuthProvider` is a 3-state machine (`hydrating | authenticated | unauthenticated`); first paint renders no protected content. Seed-admin dev default preserved (applied only after storage is read, when none is stored), so the dev switcher still works without flashing.

### Patch — APPLIED

- [x] [Review][Patch] Repository returns live row references — `find`/`create`/`update`/`slice` now `structuredClone` on the way out [InMemoryCrudRepository.ts].
- [x] [Review][Patch] Unvalidated session deserialization — `parseStoredSession()` validates shape (known `status`, array `roles`/`permissions`, string `id`/`email`/`context`) before `setSession`; failure degrades to the seed [AuthProvider.tsx].
- [x] [Review][Patch] Impure `setState` updater in `override()` — merge now reads a `sessionRef` and routes through `persist()`, no side effect inside the updater [AuthProvider.tsx].
- [x] [Review][Patch] `useResourceItem` request-sequence guard — added `seqRef`; stale out-of-order results are discarded [useResourceItem.ts].
- [x] [Review][Patch] Cursor offset validation — `decodeCursor` requires `Number.isInteger(offset) && offset >= 0` and validates the criteria shape [cursorLink.ts].
- [x] [Review][Patch] Change-relative comments swept [useResourceList.ts, InMemoryCrudRepository.ts].

### Deferred

- [x] [Review][Defer] Empty-page recovery can issue redundant follows on a tail-emptied page [useResourceList.ts:178-183] — bounded (terminates at offset 0; mock-only deterministic data). Harden later with a visited-link/attempt guard + clamp `searchAt` offset to result length.
- [x] [Review][Defer] `useQueryState.reset()` omits `setPageSize` [createQueryState.ts:32-35] though the doc says "single reset" over filter/sort/pageSize — defensible (page size is a viewing preference), but align code or doc.

---

## Review Findings — Group 2: context/backoffice/user (2026-06-14)

> Adversarial review (Blind Hunter + Edge Case Hunter + Acceptance Auditor), DDD/Codely lens. Scope: the User module only (domain, application schemas incl. auth, infrastructure repo + seed, schemas test). Auditor found 0 Critical/High spec violations; `domain/` purity, no-password-in-state, synthetic-PII-free seed, generic-CrudRepository reuse and `uuidV7` all confirmed compliant.

### Decision needed — RESOLVED & APPLIED

- [x] [Review][Decision] User aggregate unique email key → **Resolved: Option A (enforce in the mock).** `InMemoryUserRepository.create` now pre-checks the email (case-insensitive) and throws an `HttpError` with a `user-email-conflict` ProblemDetails (409); `rows` is `protected` in the base so the entity repo can guard its invariant. Update needs no guard — email is immutable post-create (read-only field + `applyInput` preserves `existing.email`). New tests exercise the previously-dormant conflict path.

### Patch — APPLIED

- [x] [Review][Patch] Password fields bounded — added `PASSWORD_MIN_LENGTH`/`PASSWORD_MAX_LENGTH` (8/128) in `passwordPolicy.ts`; `RegisterSchema`/`ResetPasswordSchema` password now `.min().max()`.
- [x] [Review][Patch] Auth email fields capped — `.max(USER_EMAIL_MAX_LENGTH)` added to `RegisterSchema`/`LoginSchema`/`ForgotPasswordSchema` email (parity with `UserCreateSchema`).

### Deferred

- [x] [Review][Defer] `UserEditSchema` is unused — referenced only in a `UserFormSchema` comment; the form validates with `UserFormSchema` [grep]. It documents the intended API edit contract but is dead per "nothing speculative." Decide later: wire edit-mode validation to it, or remove it.

### Dismissed (verified false-positive or by-design)

- Email mutation on edit / mass-assignment — email is `readOnly` in edit mode (UserForm.tsx:190) and `applyInput` ignores `input.email`; no mutation path exists.
- `status` default PENDING — present in the form's `defaultValues` (UserForm.tsx:88), satisfying the spec; schema-level default is a placement choice.
- `matchesFilter` fail-open / role cast — `toCriteria` only emits known filter combos; theoretical.
- `localeCompare` on ISO dates / sort tie nondeterminism — fixed-width UTC seed + stable `Array.sort`; deterministic for the mock.
- 250ms latency, mock-as-prod, seed `users.read` on all roles — intentional mock/dev-seed; DI documents the shared instance.
- Password complexity, `confirmPassword.min(1)` — backend concern / matched via `refine`.
