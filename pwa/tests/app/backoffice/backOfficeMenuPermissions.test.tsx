import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, it, expect, beforeEach, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";

const { push, logout, override, nav, auth } = vi.hoisted(() => ({
  push: vi.fn(),
  logout: vi.fn(() => Promise.resolve()),
  override: vi.fn(),
  nav: { pathname: "/backoffice" },
  auth: { session: null as Session | null, status: "authenticated" as string },
}));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ ...auth, login: vi.fn(), logout, override }),
}));

vi.mock("next/navigation", () => {
  const router = { push, replace: vi.fn(), refresh: vi.fn(), back: vi.fn(), prefetch: vi.fn() };
  return { useRouter: () => router, usePathname: () => nav.pathname };
});

import BackOfficeLayoutClient from "@/app/backoffice/BackOfficeLayoutClient";
import {
  accountMenuItem,
  backofficeMenuGroups,
  type NavItem,
} from "@/app/backoffice/_lib/backofficeMenu";
import { permittedAccountEntries, permittedMenuGroups } from "@/app/backoffice/_lib/menuAccess";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Session } from "@/context/shared/access/domain/Session";
import { releaseDeparture } from "@/context/shared/navigation/application/departure";

const USERS_PATH = `${Routes.BACKOFFICE}/users`;
const GATED_ENTRY = "Users";
const ITS_PARENT = "Configuration";

function sessionHolding(permissions: Session["permissions"], role: Role): Session {
  return {
    user: {
      id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
      email: "ada@erpify.test",
      status: UserStatus.ACTIVE,
      roles: [role],
      permissions,
    },
    roles: [role],
    permissions,
    context: AccessContext.BACKOFFICE,
  };
}

const ADMIN = sessionHolding([Permission.USERS_READ], Role.ADMIN);
const MANAGER = sessionHolding([], Role.MANAGER);

// The same permission the sidebar cases gate on, so both surfaces are read against one fact.
const GATED_PERMISSION = Permission.USERS_READ;

/**
 * Expands the sidebar group that owns the gated entry and returns it. The desktop sidebar keeps a
 * parent's sub-items collapsed until it is clicked, so the leaf is only reachable through that
 * toggle — and asserting on the collapsed parent would pass for a session that never had the leaf.
 */
function openGatedGroup(): HTMLElement {
  const sidebar = screen.getByRole("complementary");
  fireEvent.click(within(sidebar).getByTitle(ITS_PARENT));
  return sidebar;
}

function itemNamed(groups: readonly { items: NavItem[] }[], name: string): NavItem {
  const item = groups.flatMap((group) => group.items).find((entry) => entry.name === name);
  if (item === undefined) throw new Error(`The navigation model no longer declares "${name}".`);
  return item;
}

/**
 * The sidebar paints doors, and a door the role cannot open is an invitation to an "Access denied"
 * page. `users.read` is ADMIN-only, so four of five roles were being offered it.
 *
 * This is navigation UX and nothing else: the page behind every route keeps its own gate, because
 * the URL can be typed. A green here says the menu stopped advertising the dead end — never that
 * the surface is protected.
 */
describe("the back-office sidebar and the permission each entry declares", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    nav.pathname = Routes.BACKOFFICE;
    auth.status = "authenticated";
    releaseDeparture();
  });

  it("offers the gated entry to a session that holds its permission", () => {
    auth.session = ADMIN;
    render(<BackOfficeLayoutClient>{null}</BackOfficeLayoutClient>);

    expect(within(openGatedGroup()).getByTitle(GATED_ENTRY)).toBeInTheDocument();
  });

  it("withholds it from a session that does not", () => {
    auth.session = MANAGER;
    render(<BackOfficeLayoutClient>{null}</BackOfficeLayoutClient>);

    const group = openGatedGroup();
    expect(within(group).queryByTitle(GATED_ENTRY)).toBeNull();
    // The parent survives: its other leaves are ungated, so withholding the whole group would take
    // three working destinations away with the one that does not work.
    expect(within(group).getByTitle("Audit Logs")).toBeInTheDocument();
  });

  it("re-points a parent whose own destination was the withheld leaf", () => {
    // Every parent here repeats one of its leaves as its `path`, and a parent click NAVIGATES on the
    // two surfaces that cannot expand a sub-list (the compact sidebar, the mobile drawer). Leaving
    // the parent aimed at the withheld leaf would keep the dead end one click away from the entry
    // this filter just removed.
    const forManager = itemNamed(permittedMenuGroups(MANAGER, backofficeMenuGroups), ITS_PARENT);

    expect(itemNamed(backofficeMenuGroups, ITS_PARENT).path).toBe(USERS_PATH);
    expect(forManager.path).not.toBe(USERS_PATH);
    expect(forManager.subItems?.map((sub) => sub.path)).toContain(forManager.path);
  });

  it("leaves an entry declaring no permission untouched for either session", () => {
    // Non-vacuous: a filter that dropped everything would satisfy the withholding case above.
    const admin = permittedMenuGroups(ADMIN, backofficeMenuGroups);
    const manager = permittedMenuGroups(MANAGER, backofficeMenuGroups);
    const leafNames = (groups: readonly { items: NavItem[] }[]) =>
      groups.flatMap((group) => group.items).flatMap((item) => item.subItems ?? []);

    expect(admin).toEqual(backofficeMenuGroups);
    expect(leafNames(manager).map((sub) => sub.name)).not.toContain(GATED_ENTRY);
    expect(leafNames(manager).length).toBe(leafNames(admin).length - 1);
  });

  // The avatar menu renders from `accountMenuItem` and not from the groups, so it is a second surface
  // the permission model has to reach. `NavPermission` is declared on `NavSubItem` too, so the field is
  // offerable here; these two cases are what make it mean the same thing on both menus.
  it("withholds a gated account entry from a session that does not hold its permission", () => {
    auth.session = MANAGER;

    expect(
      permittedAccountEntries(auth.session, [
        { name: "Ungated", path: "/a", icon: accountMenuItem.icon, testId: "t-a" },
        {
          name: "Gated",
          path: "/b",
          icon: accountMenuItem.icon,
          testId: "t-b",
          permission: GATED_PERMISSION,
        },
      ]).map((entry) => entry.name),
    ).toEqual(["Ungated"]);
  });

  it("offers a gated account entry to a session that holds its permission", () => {
    auth.session = ADMIN;

    expect(
      permittedAccountEntries(auth.session, [
        { name: "Ungated", path: "/a", icon: accountMenuItem.icon, testId: "t-a" },
        {
          name: "Gated",
          path: "/b",
          icon: accountMenuItem.icon,
          testId: "t-b",
          permission: GATED_PERMISSION,
        },
      ]).map((entry) => entry.name),
    ).toEqual(["Ungated", "Gated"]);
  });

  // The account item itself is the chrome's own affordance and is always rendered, so a `permission`
  // declared at that level would be honoured by nothing. Refusing it here is what keeps the filtering
  // above from reading as complete coverage of a type that also allows the parent shape.
  it("refuses a permission declared on the account item itself, which no surface honours", () => {
    expect(accountMenuItem.permission).toBeUndefined();
  });

  // The two cases above exercise the FUNCTION, and a green on them says nothing about whether the
  // layout calls it — measured: reverting `accountEntries` to the raw `accountMenuItem.subItems`
  // leaves both of them passing. Nothing behavioural can see the difference either, because no
  // account entry declares a permission today, so the filtered and unfiltered lists are equal. The
  // wiring is therefore read from the source, which is the only instrument that reds on that revert.
  // A green here proves the call site exists, never that the filter is correct — that is the two
  // cases above.
  it("derives the rendered account entries through the permission filter", () => {
    const source = readFileSync(
      resolve(process.cwd(), "src/app/backoffice/BackOfficeLayoutClient.tsx"),
      "utf8",
    );

    expect(source).toMatch(/const accountEntries = permittedAccountEntries\(\s*session,/);
  });

  // Deriving the filtered list is half the wiring; the other half is that EVERY account surface reads
  // it. Three render it through three different JSX blocks — the top-bar dropdown, the sidebar footer
  // and the mobile drawer — so a filter consumed by one of them is not a filter, it is a filter on one
  // menu. The check is containment rather than an enumeration of the three: the raw `subItems` may be
  // read exactly once, at the derivation, so a FOURTH surface added later cannot reach the unfiltered
  // list either. Source-read for the same reason as the case above — no account entry declares a
  // permission today, so the filtered and unfiltered lists are equal and nothing behavioural can tell
  // them apart.
  it("hands the filtered account item to every surface, never the raw model", () => {
    const source = readFileSync(
      resolve(process.cwd(), "src/app/backoffice/BackOfficeLayoutClient.tsx"),
      "utf8",
    );

    const rawEntryReads = source.match(/accountMenuItem\.subItems/g) ?? [];

    expect(rawEntryReads).toHaveLength(1);
    expect(source).toMatch(
      /const accountEntries = permittedAccountEntries\(\s*session,\s*accountMenuItem\.subItems/,
    );
    expect(source).not.toMatch(/withEntryState\(accountMenuItem\)/);
  });

  it("offers nothing gated to a session that has not resolved yet", () => {
    // The chrome only renders behind RequireAuth, so this arm is defensive — but a null session
    // reading as "allowed" would paint the whole menu during any future unguarded render.
    expect(
      permittedMenuGroups(null, backofficeMenuGroups)
        .flatMap((group) => group.items)
        .flatMap((item) => item.subItems ?? [])
        .map((sub) => sub.name),
    ).not.toContain(GATED_ENTRY);
  });
});
