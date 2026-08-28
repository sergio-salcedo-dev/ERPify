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
import { backofficeMenuGroups, type NavItem } from "@/app/backoffice/_lib/backofficeMenu";
import { permittedMenuGroups } from "@/app/backoffice/_lib/menuAccess";
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
    const forManager = itemNamed(permittedMenuGroups(backofficeMenuGroups, MANAGER), ITS_PARENT);

    expect(itemNamed(backofficeMenuGroups, ITS_PARENT).path).toBe(USERS_PATH);
    expect(forManager.path).not.toBe(USERS_PATH);
    expect(forManager.subItems?.map((sub) => sub.path)).toContain(forManager.path);
  });

  it("leaves an entry declaring no permission untouched for either session", () => {
    // Non-vacuous: a filter that dropped everything would satisfy the withholding case above.
    const admin = permittedMenuGroups(backofficeMenuGroups, ADMIN);
    const manager = permittedMenuGroups(backofficeMenuGroups, MANAGER);
    const leafNames = (groups: readonly { items: NavItem[] }[]) =>
      groups.flatMap((group) => group.items).flatMap((item) => item.subItems ?? []);

    expect(admin).toEqual(backofficeMenuGroups);
    expect(leafNames(manager).map((sub) => sub.name)).not.toContain(GATED_ENTRY);
    expect(leafNames(manager).length).toBe(leafNames(admin).length - 1);
  });

  it("offers nothing gated to a session that has not resolved yet", () => {
    // The chrome only renders behind RequireAuth, so this arm is defensive — but a null session
    // reading as "allowed" would paint the whole menu during any future unguarded render.
    expect(
      permittedMenuGroups(backofficeMenuGroups, null)
        .flatMap((group) => group.items)
        .flatMap((item) => item.subItems ?? [])
        .map((sub) => sub.name),
    ).not.toContain(GATED_ENTRY);
  });
});
