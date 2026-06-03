# Backoffice App Shell — chrome hardening — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden the backoffice chrome — add a desktop top bar (collapse toggle + route-derived section title + deliberately-fake search/notifications/account controls), persist the sidebar collapse state with a `Cmd/Ctrl+B` shortcut, add a skip-to-content link, and fix the `font-bold`/`rounded-xl` token violations — by enhancing the existing bespoke `BackOfficeLayoutClient` in place.

**Architecture:** The `/backoffice` chrome is the client component `BackOfficeLayoutClient` (a desktop `<aside>` + a mobile header/`Sheet`). We keep that bespoke layout (we do **not** adopt the unused `@/components/erpify/AppShell` primitive) and add the missing affordances directly. A small pure helper maps the current route to a section title. Collapse state persists under the `erpify:sidebar-open` localStorage key (same key `AppShell` uses, for forward-compat). The two old in-sidebar collapse chevrons are removed in favour of one top-bar toggle.

**Tech Stack:** Next.js 16 (App Router, client component), TypeScript (strict), Tailwind 4 (semantic design-system tokens), `@/components/ui/button` (Shadcn `Button`), lucide-react icons, Vitest (unit), Playwright (e2e).

**Spec:** `docs/superpowers/specs/2026-06-03-backoffice-app-shell-design.md`

**Branch:** `feat/backoffice-app-shell` (already created off `main`; the spec is already committed there).

---

## Design-system override on record

The top bar's **search / notifications / account** controls are **deliberately fake** (active-looking, wired to nothing). This is a conscious, owner-approved, scoped override of DESIGN.md's "no optimistic theater" principle, limited to shell chrome. See the spec's *Recorded design-system override* section. Do **not** "fix" these into honest placeholders — that decision is intentional and documented.

---

## File Structure

- **Create** `pwa/src/app/backoffice/_lib/sectionTitle.ts` — pure `sectionTitleFor(pathname)` helper (route → section title). No React, no framework imports.
- **Create** `pwa/tests/app/backoffice/sectionTitle.test.ts` — Vitest unit tests for the helper (mirrors the existing `tests/app/backoffice/banks/*.test.ts` placement convention).
- **Modify** `pwa/src/components/erpify/SidebarItem.tsx` — two radius fixes only (`rounded-xl` → `rounded-md` on the primary row; `rounded-lg` → `rounded-md` on the sub-item row). Consumed only by the backoffice, so contained.
- **Modify** `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` — full, surgical rewrite: add the desktop top bar; persist collapse + `Cmd/Ctrl+B`; remove the two in-sidebar chevron toggles; add `data-sidebar-open` to the aside; add the skip link + `id="main-content"`; apply `font-bold` → `font-semibold` (8×) and the mobile `rounded-xl`/`rounded-lg` → `rounded-md` fixes.
- **Create** `pwa/tests/e2e/backoffice/app-shell.spec.ts` — Playwright e2e for the new chrome (top bar, section title, fake controls, persistence, `Ctrl/Cmd+B`, skip link).

No other files change. The existing `tests/e2e/backoffice/sidebar.spec.ts` and `dashboard.spec.ts` must keep passing **unchanged**. `AppShell` is intentionally left untouched/unused.

---

## Prerequisite for e2e / browser verification

The stack must be running so the container can serve the app and CI/e2e can reach it. From the repo root, once before Task 3's verification:

```bash
make docker.up
```

**Local e2e caveat (project-known):** `make pwa.test.e2e` runs on the **host** and can fail with `EACCES` because the Docker dev stack leaves `pwa/.next` / `pwa/.next-e2e` owned by `root` (no passwordless sudo). If that happens, **do not** force it. Verify the rendered markup from inside the container instead, and let the e2e assertions run in CI:

```bash
docker compose exec -T pwa sh -c 'curl -s http://localhost:3000/backoffice' | grep -o 'data-testid="bo-layout__topbar-[a-z]*"'
```

(Hitting `https://localhost/backoffice` from the host returns a Symfony 404 in this setup — that is not how e2e reaches the app.) `make pwa.test.unit` (Vitest, host) and `make pwa.quality` are **not** affected by the blocker and must pass locally.

---

## Task 1: `sectionTitleFor` route → section-title helper (TDD)

**Files:**
- Create: `pwa/tests/app/backoffice/sectionTitle.test.ts`
- Create: `pwa/src/app/backoffice/_lib/sectionTitle.ts`

- [ ] **Step 1: Write the failing unit test**

Create `pwa/tests/app/backoffice/sectionTitle.test.ts` with exactly:

```ts
import { describe, expect, it } from "vitest";
import { sectionTitleFor } from "@/app/backoffice/_lib/sectionTitle";

describe("sectionTitleFor", () => {
  it("maps the backoffice root to Dashboard", () => {
    expect(sectionTitleFor("/backoffice")).toBe("Dashboard");
  });

  it("maps banks routes (list, detail, edit) to Banks", () => {
    expect(sectionTitleFor("/backoffice/banks")).toBe("Banks");
    expect(sectionTitleFor("/backoffice/banks/123")).toBe("Banks");
    expect(sectionTitleFor("/backoffice/banks/123/edit")).toBe("Banks");
  });

  it("maps the health route to Service Health", () => {
    expect(sectionTitleFor("/backoffice/health")).toBe("Service Health");
  });

  it("maps administration to Administration", () => {
    expect(sectionTitleFor("/backoffice/administration")).toBe("Administration");
  });

  it("maps profile sub-routes before the profile root", () => {
    expect(sectionTitleFor("/backoffice/profile/notifications")).toBe("Notifications");
    expect(sectionTitleFor("/backoffice/profile/settings")).toBe("Settings");
    expect(sectionTitleFor("/backoffice/profile")).toBe("User Profile");
  });

  it("falls back to Backoffice for unknown paths", () => {
    expect(sectionTitleFor("/backoffice/unknown")).toBe("Backoffice");
    expect(sectionTitleFor("/something-else")).toBe("Backoffice");
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
make pwa.test.unit c='tests/app/backoffice/sectionTitle.test.ts'
```

Expected: FAIL — the import `@/app/backoffice/_lib/sectionTitle` cannot be resolved (module does not exist yet).

- [ ] **Step 3: Implement the helper**

Create `pwa/src/app/backoffice/_lib/sectionTitle.ts` with exactly:

```ts
import { Routes } from "@/context/shared/domain/types/routes";
import { bankRoutes } from "@/app/backoffice/banks/_lib/bankRoutes";

/**
 * Maps a backoffice pathname to the section title shown in the top bar.
 * Ordered longest-match-first so profile sub-routes win over the profile root.
 * Composes paths from `Routes.BACKOFFICE` / `bankRoutes` rather than hard-coding
 * the `/backoffice` prefix.
 */
const SECTION_RULES: ReadonlyArray<readonly [string, string]> = [
  [bankRoutes.list, "Banks"],
  [`${Routes.BACKOFFICE}/health`, "Service Health"],
  [`${Routes.BACKOFFICE}/administration`, "Administration"],
  [`${Routes.BACKOFFICE}/profile/notifications`, "Notifications"],
  [`${Routes.BACKOFFICE}/profile/settings`, "Settings"],
  [`${Routes.BACKOFFICE}/profile`, "User Profile"],
];

export function sectionTitleFor(pathname: string): string {
  if (pathname === Routes.BACKOFFICE) return "Dashboard";
  const match = SECTION_RULES.find(
    ([prefix]) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
  return match ? match[1] : "Backoffice";
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
make pwa.test.unit c='tests/app/backoffice/sectionTitle.test.ts'
```

Expected: PASS — all assertions green.

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/backoffice/_lib/sectionTitle.ts pwa/tests/app/backoffice/sectionTitle.test.ts
git commit -m "feat(backoffice): add sectionTitleFor route->title helper

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Fix oversized nav radii in the shared `SidebarItem`

**Files:**
- Modify: `pwa/src/components/erpify/SidebarItem.tsx` (the primary-row `className` at ~line 67 and the sub-item `className` at ~line 105)

`SidebarItem` is consumed only by `BackOfficeLayoutClient`, so this is contained. DESIGN.md classes nav rows as functional/list elements (`--radius-md` 6 px); `rounded-xl` (12 px) is a panel radius and `rounded-lg` (8 px) a card/popover radius — both oversized for nav rows.

- [ ] **Step 1: Fix the primary-row radius**

In `pwa/src/components/erpify/SidebarItem.tsx`, in the primary `<button>` `className` template literal, change `rounded-xl` to `rounded-md`. The leading classes become:

```
sidebar-item w-full flex items-center justify-between p-2.5 rounded-md font-semibold transition-all group
```

- [ ] **Step 2: Fix the sub-item-row radius**

In the same file, in the sub-item `<button>` `className` template literal, change `rounded-lg` to `rounded-md`. The leading classes become:

```
sidebar-item__sub-item w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-medium transition-all
```

- [ ] **Step 3: Verify no `rounded-xl`/`rounded-lg` remain in the file**

```bash
grep -nE "rounded-(xl|lg)" pwa/src/components/erpify/SidebarItem.tsx || echo "OK: none remain"
```

Expected: `OK: none remain`.

- [ ] **Step 4: Run the erpify unit suite + quality**

```bash
make pwa.test.unit c='tests/components/erpify'
make pwa.quality
```

Expected: PASS — there is no `SidebarItem` unit test to update; nothing else references these classes. Prettier/ESLint clean.

- [ ] **Step 5: Commit**

```bash
git add pwa/src/components/erpify/SidebarItem.tsx
git commit -m "fix(backoffice): use rounded-md for sidebar nav rows (DESIGN.md radii)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Enhance `BackOfficeLayoutClient` — top bar, persisted collapse, Cmd/Ctrl+B, skip link, token fixes (TDD)

**Files:**
- Create: `pwa/tests/e2e/backoffice/app-shell.spec.ts`
- Modify: `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` (full rewrite below)

- [ ] **Step 1: Write the failing e2e spec**

Create `pwa/tests/e2e/backoffice/app-shell.spec.ts` with exactly:

```ts
import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";

test.describe("BackOffice - App Shell", () => {
  test.describe.configure({ mode: "parallel" });
  test.use({ viewport: VIEWPORT_DESKTOP });

  test.beforeEach(async ({ page }) => {
    await page.goto("/backoffice");
  });

  test("renders the desktop top bar with the route's section title", async ({ page }) => {
    await expect(page.getByTestId("bo-layout__topbar-title")).toHaveText("Dashboard");
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("bo-layout__topbar-title")).toHaveText("Banks");
  });

  test("shows the (placeholder) search, notifications and account controls", async ({ page }) => {
    await expect(page.getByTestId("bo-layout__topbar-search")).toBeEnabled();
    await expect(page.getByTestId("bo-layout__topbar-notifications")).toBeEnabled();
    await expect(page.getByTestId("bo-layout__topbar-account")).toBeEnabled();
  });

  test("persists the sidebar collapse state across reload", async ({ page }) => {
    const aside = page.locator("aside");
    await expect(aside).toHaveAttribute("data-sidebar-open", "true");

    await page.getByTestId("bo-layout__topbar-toggle").click();
    await expect(aside).toHaveAttribute("data-sidebar-open", "false");

    await page.reload();
    await expect(aside).toHaveAttribute("data-sidebar-open", "false");
  });

  test("toggles the sidebar with Ctrl/Cmd+B", async ({ page }) => {
    const aside = page.locator("aside");
    await expect(aside).toHaveAttribute("data-sidebar-open", "true");

    await page.keyboard.press("Control+b");
    await expect(aside).toHaveAttribute("data-sidebar-open", "false");

    await page.keyboard.press("Control+b");
    await expect(aside).toHaveAttribute("data-sidebar-open", "true");
  });

  test("exposes a skip-to-content link as the first focusable element", async ({ page }) => {
    const skip = page.getByRole("link", { name: "Skip to main content" });
    await expect(skip).toHaveAttribute("href", "#main-content");
    await page.keyboard.press("Tab");
    await expect(skip).toBeFocused();
    await expect(skip).toBeVisible();
    await expect(page.locator("#main-content")).toBeAttached();
  });
});
```

- [ ] **Step 2: Run the e2e spec to verify it fails**

```bash
make pwa.test.e2e c='tests/e2e/backoffice/app-shell.spec.ts'
```

Expected: FAIL — the `bo-layout__topbar-*` testids, the `data-sidebar-open` attribute, and the skip link do not exist yet.

> If this errors with `EACCES` (root-owned `.next` artifacts — see *Prerequisite*), the host runner is blocked. That is acceptable: the assertions reference markup that does not exist yet, so they would fail. Proceed to Step 3 and verify the produced markup via the container-curl command after Step 4; the e2e runs in CI.

- [ ] **Step 3: Replace `BackOfficeLayoutClient.tsx` with the enhanced layout**

Replace the **entire** contents of `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` with:

```tsx
"use client";

import { useEffect, useState } from "react";
import { useRouter, usePathname } from "next/navigation";
import {
  LucideIcon,
  LayoutDashboard,
  User,
  LogOut,
  Menu,
  Settings as SettingsIcon,
  Bell,
  Activity,
  Building2,
  PanelLeftClose,
  PanelLeftOpen,
  Search,
  Wrench,
} from "lucide-react";
import { Logo, SidebarItem } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { Routes } from "@/context/shared/domain/types/routes";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { bankRoutes } from "./banks/_lib/bankRoutes";
import { sectionTitleFor } from "./_lib/sectionTitle";

const SIDEBAR_STORAGE_KEY = "erpify:sidebar-open";

interface NavSubItem {
  name: string;
  path: string;
  icon?: LucideIcon;
  testId?: string;
}

interface NavItem {
  name: string;
  icon: LucideIcon;
  path: string;
  subItems?: NavSubItem[];
  testId?: string;
}

interface NavGroup {
  label: string;
  items: NavItem[];
}

export default function BackOfficeLayoutClient({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [isCompact, setIsCompact] = useState(() => {
    if (globalThis.window === undefined) return false;
    const stored = globalThis.localStorage.getItem(SIDEBAR_STORAGE_KEY);
    return stored === null ? false : stored !== "1";
  });
  const router = useRouter();
  const pathname = usePathname();
  const sectionTitle = sectionTitleFor(pathname);

  useEffect(() => {
    if (globalThis.window === undefined) return;
    globalThis.localStorage.setItem(SIDEBAR_STORAGE_KEY, isCompact ? "0" : "1");
  }, [isCompact]);

  useEffect(() => {
    function handleKey(event: KeyboardEvent): void {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "b") {
        event.preventDefault();
        setIsCompact((compact) => !compact);
      }
    }
    globalThis.addEventListener("keydown", handleKey);
    return () => globalThis.removeEventListener("keydown", handleKey);
  }, []);

  const menuGroups: NavGroup[] = [
    {
      label: "General",
      items: [{ name: "Dashboard", icon: LayoutDashboard, path: "/backoffice" }],
    },
    {
      label: "Banking",
      items: [{ name: "Banks", icon: Building2, path: bankRoutes.list }],
    },
    {
      label: "System",
      items: [
        {
          name: "Administration",
          icon: SettingsIcon,
          path: "/backoffice/administration",
          subItems: [{ name: "Service Health", path: "/backoffice/health", icon: Activity }],
        },
      ],
    },
    // Conditional dev/test-only category. Disappears entirely from the
    // sidebar in production via the `isDevToolsAvailable()` check, which
    // mirrors the gating model of the route at `app/dev-tools/page.tsx`.
    ...(isDevToolsAvailable()
      ? [
          {
            label: "Development",
            items: [
              {
                name: "Dev Tools",
                icon: Wrench,
                path: Routes.DEV_TOOLS,
                testId: "bo-layout__sidebar-dev-tools",
              },
            ],
          },
        ]
      : []),
  ];

  const userProfileItem: NavItem = {
    name: "User Profile",
    icon: User,
    path: "/backoffice/profile",
    subItems: [
      { name: "Notifications", path: "/backoffice/profile/notifications", icon: Bell },
      { name: "Settings", path: "/backoffice/profile/settings", icon: SettingsIcon },
      { name: "Logout", path: "/", icon: LogOut },
    ],
  };

  const handleNavigation = (path: string) => {
    if (path === "/") {
      // Simulate logout
      setTimeout(() => router.push("/"), 500);
    } else {
      router.push(path);
    }
    setIsSidebarOpen(false);
  };

  const navigateTo = (path: string) => () => handleNavigation(path);

  const isItemActive = (item: NavItem) => {
    if (pathname === item.path) return true;
    if (item.subItems) {
      return item.subItems.some((sub) => pathname === sub.path);
    }
    return false;
  };

  return (
    <div className="bo-layout min-h-screen bg-background flex font-sans">
      <a
        href="#main-content"
        className="bo-layout__skip-link bg-primary text-primary-foreground sr-only z-50 px-3 py-2 focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded-md"
      >
        Skip to main content
      </a>

      {/* Sidebar Desktop */}
      <aside
        data-sidebar-open={!isCompact}
        className={`bo-layout__sidebar hidden md:flex flex-col bg-card border-r border-border sticky top-0 h-screen shadow-sm transition-all duration-300 ${
          isCompact ? "w-20" : "w-64"
        }`}
      >
        <div className="bo-layout__sidebar-header flex items-center border-b border-border h-16 px-4">
          {!isCompact && (
            <Logo
              href="/backoffice"
              variant="badge"
              size="md"
              className="bo-layout__logo"
              iconClassName="bo-layout__logo-icon"
              textClassName="bo-layout__logo-text"
            />
          )}
          {isCompact && (
            <Logo
              href="/backoffice"
              variant="badge"
              size="md"
              iconOnly
              className="bo-layout__logo bo-layout__logo--compact mx-auto"
              iconClassName="bo-layout__logo-icon"
            />
          )}
        </div>

        <nav className="bo-layout__sidebar-nav flex-grow p-3 space-y-6 overflow-y-auto">
          {menuGroups.map((group) => (
            <div key={group.label} className="bo-layout__nav-group space-y-1">
              {!isCompact && (
                <p className="bo-layout__nav-label text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-3 mb-2">
                  {group.label}
                </p>
              )}
              {group.items.map((item) => (
                <SidebarItem
                  key={item.name}
                  {...item}
                  isActive={isItemActive(item)}
                  onClick={handleNavigation}
                  isCompact={isCompact}
                />
              ))}
            </div>
          ))}
        </nav>

        {/* User Profile at bottom */}
        <div className="bo-layout__footer p-3 border-t border-border">
          {!isCompact && (
            <p className="bo-layout__nav-label text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-3 mb-2">
              Account
            </p>
          )}
          <SidebarItem
            {...userProfileItem}
            isActive={isItemActive(userProfileItem)}
            onClick={handleNavigation}
            isCompact={isCompact}
          />
        </div>
      </aside>

      {/* Mobile Header */}
      <div className="bo-layout__header-mobile md:hidden fixed top-0 left-0 right-0 bg-card border-b border-border h-14 flex items-center justify-between px-4 z-50">
        <Logo
          href="/backoffice"
          variant="badge"
          size="md"
          className="bo-layout__logo-mobile"
          iconClassName="bo-layout__logo-icon"
          textClassName="bo-layout__logo-text"
        />
        <Sheet open={isSidebarOpen} onOpenChange={setIsSidebarOpen}>
          <SheetTrigger
            render={
              <button
                type="button"
                aria-label="Open navigation menu"
                className="bo-layout__toggle-mobile p-2 text-foreground"
              >
                <Menu className="w-6 h-6" aria-hidden />
              </button>
            }
          />
          <SheetContent side="left" className="bo-layout__sidebar-mobile p-0 w-72">
            <SheetHeader className="bo-layout__sidebar-mobile-header p-4 flex flex-row items-center justify-between border-b border-border">
              <SheetTitle className="hidden">Navigation Menu</SheetTitle>
              <Logo
                href="/backoffice"
                variant="badge"
                size="md"
                className="bo-layout__logo-mobile"
                iconClassName="bo-layout__logo-icon"
                textClassName="bo-layout__logo-text"
              />
            </SheetHeader>
            <nav className="bo-layout__sidebar-mobile-nav p-4 space-y-6 overflow-y-auto h-[calc(100vh-64px)]">
              {menuGroups.map((group) => (
                <div key={group.label} className="bo-layout__mobile-group space-y-1">
                  <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-4 mb-2">
                    {group.label}
                  </p>
                  {group.items.map((item) => (
                    <div key={item.name} className="bo-layout__sidebar-mobile-item-wrapper">
                      <button
                        onClick={() => handleNavigation(item.path)}
                        title={item.name}
                        data-testid={item.testId ? `${item.testId}--mobile` : undefined}
                        className={`bo-layout__sidebar-mobile-link w-full flex items-center gap-3 p-3 rounded-md font-semibold transition-all ${
                          pathname === item.path
                            ? "bg-primary/15 text-primary"
                            : "text-muted-foreground hover:bg-accent"
                        }`}
                      >
                        <item.icon className="w-5 h-5" />
                        <span className="text-sm">{item.name}</span>
                      </button>
                      {item.subItems && (
                        <div className="ml-8 mt-1 space-y-1">
                          {item.subItems.map((subItem) => (
                            <button
                              key={subItem.name}
                              onClick={navigateTo(subItem.path)}
                              title={subItem.name}
                              data-testid={subItem.testId ? `${subItem.testId}--mobile` : undefined}
                              className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                                pathname === subItem.path
                                  ? "text-primary bg-primary/10"
                                  : "text-muted-foreground hover:bg-accent"
                              }`}
                            >
                              {subItem.icon && <subItem.icon className="w-3.5 h-3.5" />}
                              {subItem.name}
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              ))}

              <div className="bo-layout__mobile-group space-y-1 pt-4 border-t border-border">
                <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-4 mb-2">
                  Account
                </p>
                <div className="bo-layout__sidebar-mobile-item-wrapper">
                  <button
                    onClick={() => handleNavigation(userProfileItem.path)}
                    title={userProfileItem.name}
                    className={`bo-layout__sidebar-mobile-link w-full flex items-center gap-3 p-3 rounded-md font-semibold transition-all ${
                      pathname === userProfileItem.path
                        ? "bg-primary/15 text-primary"
                        : "text-muted-foreground hover:bg-accent"
                    }`}
                  >
                    <userProfileItem.icon className="w-5 h-5" />
                    <span className="text-sm">{userProfileItem.name}</span>
                  </button>
                  <div className="ml-8 mt-1 space-y-1">
                    {userProfileItem.subItems?.map((subItem) => (
                      <button
                        key={subItem.name}
                        onClick={() => handleNavigation(subItem.path)}
                        title={subItem.name}
                        className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                          pathname === subItem.path
                            ? "text-primary bg-primary/10"
                            : "text-muted-foreground hover:bg-accent"
                        }`}
                      >
                        {subItem.icon && <subItem.icon className="w-3.5 h-3.5" />}
                        {subItem.name}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
            </nav>
          </SheetContent>
        </Sheet>
      </div>

      {/* Main column: desktop top bar + scrollable content */}
      <div className="bo-layout__column flex min-w-0 flex-1 flex-col">
        <header className="bo-layout__topbar hidden md:flex items-center gap-3 bg-card border-b border-border h-16 px-4">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={isCompact ? "Expand sidebar" : "Collapse sidebar"}
            aria-expanded={!isCompact}
            title={isCompact ? "Expand sidebar (Ctrl/Cmd+B)" : "Collapse sidebar (Ctrl/Cmd+B)"}
            data-testid="bo-layout__topbar-toggle"
            onClick={() => setIsCompact((compact) => !compact)}
            className="bo-layout__topbar-toggle"
          >
            {isCompact ? (
              <PanelLeftOpen className="size-4" aria-hidden />
            ) : (
              <PanelLeftClose className="size-4" aria-hidden />
            )}
            <span className="sr-only">{isCompact ? "Expand sidebar" : "Collapse sidebar"}</span>
          </Button>

          <span
            data-testid="bo-layout__topbar-title"
            className="bo-layout__topbar-title text-foreground text-sm font-semibold truncate"
          >
            {sectionTitle}
          </span>

          <div className="bo-layout__topbar-actions ml-auto flex items-center gap-1">
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label="Search"
              title="Search"
              data-testid="bo-layout__topbar-search"
              className="bo-layout__topbar-search"
            >
              <Search className="size-4" aria-hidden />
              <span className="sr-only">Search</span>
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label="Notifications"
              title="Notifications"
              data-testid="bo-layout__topbar-notifications"
              className="bo-layout__topbar-notifications relative"
            >
              <Bell className="size-4" aria-hidden />
              <span
                className="bo-layout__topbar-badge absolute right-1.5 top-1.5 size-1.5 rounded-full bg-primary"
                aria-hidden
              />
              <span className="sr-only">Notifications</span>
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label="Account"
              title="Account"
              data-testid="bo-layout__topbar-account"
              className="bo-layout__topbar-account"
            >
              <User className="size-4" aria-hidden />
              <span className="sr-only">Account</span>
            </Button>
          </div>
        </header>

        <main id="main-content" className="bo-layout__main flex-grow pt-14 md:pt-0 overflow-auto">
          <div className="bo-layout__content max-w-6xl mx-auto p-4 md:p-8">{children}</div>
        </main>
      </div>
    </div>
  );
}
```

Notes for the engineer:
- **Removed** the two old in-sidebar collapse chevrons (the header `ChevronLeft` button and the floating `ChevronRight` expand button). The top-bar toggle (plus `Ctrl/Cmd+B`) is now the only collapse control. `ChevronLeft` / `ChevronRight` are therefore **no longer imported** (their removal from the lucide import is already reflected above; `PanelLeftClose` / `PanelLeftOpen` / `Search` are added).
- **`data-sidebar-open={!isCompact}`** on the `<aside>` is what the e2e asserts (React serializes the boolean to `"true"`/`"false"`).
- **`erpify:sidebar-open`** semantics: `"1"` = expanded, `"0"` = compact — matches `AppShell`'s key for forward-compat.
- The five new `data-testid` literals (`bo-layout__topbar-toggle`, `-title`, `-search`, `-notifications`, `-account`) are unique across `src/` (the `data-testid-uniqueness` guard requires this).
- The section title is a **`<span>`, not a heading** — it must not add to the page's heading outline (each page keeps its single `<h1>`).
- The fake search/notifications/account `Button`s have **no `onClick`** by design (see *Design-system override on record*).

- [ ] **Step 4: Run the e2e spec to verify it passes**

```bash
make pwa.test.e2e c='tests/e2e/backoffice/app-shell.spec.ts'
```

Expected: PASS — all five tests green.

> **If the host runner is `EACCES`-blocked**, verify the rendered markup from the container instead (stack must be up — see *Prerequisite*):
>
> ```bash
> docker compose exec -T pwa sh -c 'curl -s http://localhost:3000/backoffice' \
>   | grep -oE 'data-testid="bo-layout__topbar-(toggle|title|search|notifications|account)"|data-sidebar-open="true"|id="main-content"|Skip to main content'
> ```
>
> Expected: lines for all four/five top-bar testids, `data-sidebar-open="true"`, `id="main-content"`, and `Skip to main content`. The behavioural assertions (`Ctrl/Cmd+B`, reload-persistence) then run in CI.

- [ ] **Step 5: Run the existing backoffice e2e to confirm no regression**

```bash
make pwa.test.e2e c='tests/e2e/backoffice/sidebar.spec.ts'
make pwa.test.e2e c='tests/e2e/backoffice/dashboard.spec.ts'
```

Expected: PASS, **unchanged**. (Desktop nav buttons, User Profile sub-item expand/collapse, the mobile sheet, the dashboard `<h1>` + "No metrics to show yet" copy are all untouched by this change.)

> If `EACCES`-blocked, rely on CI for these; they are not modified by this task, so the risk is low. The desktop `sidebar.spec` scopes its locators to `page.locator("aside")`, so the new top-bar `Account` button (outside `aside`) cannot collide with the sidebar's `User Profile` button.

- [ ] **Step 6: Run the full PWA quality gate**

```bash
make pwa.quality
```

Expected: PASS — no unused imports (`ChevronLeft`/`ChevronRight` are gone; `PanelLeftClose`/`PanelLeftOpen`/`Search`/`Button`/`sectionTitleFor` are all used), no `font-bold` (weight 700) left in the file, Prettier clean. Fix anything reported before continuing.

Sanity greps (optional):

```bash
grep -n "font-bold" pwa/src/app/backoffice/BackOfficeLayoutClient.tsx || echo "OK: no font-bold"
grep -nE "rounded-(xl|lg)" pwa/src/app/backoffice/BackOfficeLayoutClient.tsx || echo "OK: no oversized radii"
```

Expected: both print the `OK:` line.

- [ ] **Step 7: Commit**

```bash
git add pwa/src/app/backoffice/BackOfficeLayoutClient.tsx pwa/tests/e2e/backoffice/app-shell.spec.ts
git commit -m "feat(backoffice): app-shell top bar, persisted collapse, skip link

Add a desktop top bar (single collapse toggle + route-derived section
title + deliberately-fake search/notifications/account controls). Persist
the sidebar collapse state under erpify:sidebar-open and toggle it with
Cmd/Ctrl+B. Add a skip-to-content link + id=main-content. Remove the two
redundant in-sidebar chevron toggles. Fix font-bold (700) -> font-semibold
and oversized nav radii. AppShell left unused (reconciled separately).

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Manual verification (after Task 3)

With the stack up (`make docker.up`), open `https://localhost/backoffice`:

- [ ] **Top bar** shows the collapse toggle on the left, the section title (`Dashboard`), and the three icon buttons (search, bell-with-dot, user) on the right. Navigate to Banks → the title changes to `Banks`.
- [ ] **Collapse** via the top-bar toggle (and via `Ctrl/Cmd+B`): the sidebar narrows to the icon rail; reload the page → it **stays** collapsed. Expand again → reload → stays expanded.
- [ ] **Skip link:** press `Tab` from a fresh load → a "Skip to main content" pill appears top-left; activating it moves focus into the content.
- [ ] **Tokens:** nav rows have the smaller `rounded-md` corners; no text in the chrome is heavier than semibold (600).
- [ ] **Dark mode:** toggle the OS/app theme — top bar, sidebar, and the fake controls use token colours and stay legible (contrast ≥ 4.5:1); focus rings are visible on the toggle and icon buttons.
- [ ] **Mobile width:** the desktop top bar is hidden; the existing mobile header + sheet still open, navigate, and show sub-items (unchanged), now with `rounded-md` rows.

## Acceptance criteria (from spec)

- [ ] `/backoffice` shows a desktop top bar with a working collapse toggle, the route-derived section title, and three active-looking (fake) search / notifications / user controls.
- [ ] Sidebar collapse state persists across reloads and toggles with `Cmd/Ctrl+B`; only one collapse control remains.
- [ ] A skip-to-content link is the first focusable element and targets `#main-content`.
- [ ] No `font-bold` (weight 700) remains in the backoffice chrome; nav rows use `rounded-md`.
- [ ] Existing `sidebar.spec.ts` and `dashboard.spec.ts` pass unchanged; the new e2e + unit assertions pass (in CI if the host e2e runner is `EACCES`-blocked).
- [ ] `make pwa.quality` passes.
- [ ] Light and dark modes both render correctly, desktop and mobile.

## Wrap-up notes (not code)

- **PR description** must state the *Recorded design-system override* (fake top-bar controls) explicitly, per the repo's security/UX review process, and note that `AppShell` is intentionally left unused for a separate reconciliation.
- If `docs/source-tree-analysis.md` enumerates route-local folders, add `pwa/src/app/backoffice/_lib/` (new). No other docs need updating (no new Make targets, endpoints, or module boundaries).
- After all tasks pass, use **superpowers:finishing-a-development-branch** to decide merge/PR.
```

