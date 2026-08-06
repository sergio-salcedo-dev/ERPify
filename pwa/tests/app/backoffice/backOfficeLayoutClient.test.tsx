import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";

const { push, logout, override, nav, auth, assign } = vi.hoisted(() => ({
  push: vi.fn(),
  logout: vi.fn(() => Promise.resolve()),
  override: vi.fn(),
  assign: vi.fn(),
  nav: { pathname: "/backoffice" },
  // `status` is the literal the guard compares against rather than `AuthStatus.AUTHENTICATED`:
  // a mock factory is hoisted above the imports, so it cannot read a value imported here.
  auth: { session: null as Session | null, status: "authenticated" as string },
}));

// One mock covers all three consumers: the layout reaches for `useSession` through the `@/` alias
// while RequireAuth and DevSessionSwitcher import it relatively, and both resolve to this module.
// RequireAuth renders nothing unless the status is `authenticated`, and DevSessionSwitcher — mounted
// because `isDevToolsAvailable()` is true outside production — reads `session.roles`/`user.status`
// and `override`, so an incomplete value here would empty the tree instead of failing loudly.
vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ ...auth, login: vi.fn(), logout, override }),
}));

// The router object is built once so RequireAuth's effect does not re-run on every render.
vi.mock("next/navigation", () => {
  const router = { push, replace: vi.fn(), refresh: vi.fn(), back: vi.fn(), prefetch: vi.fn() };
  return { useRouter: () => router, usePathname: () => nav.pathname };
});

import BackOfficeLayoutClient from "@/app/backoffice/BackOfficeLayoutClient";
import { accountMenuItem, type NavSubItem } from "@/app/backoffice/_lib/backofficeMenu";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Session } from "@/context/shared/access/domain/Session";

const SESSION: Session = {
  user: {
    id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
    email: "ada@erpify.test",
    status: UserStatus.ACTIVE,
    roles: ["ADMIN"],
    permissions: [Permission.USERS_READ],
  },
  roles: ["ADMIN"],
  permissions: [Permission.USERS_READ],
  context: AccessContext.BACKOFFICE,
};

/**
 * The sidebar collapse key, spelled out rather than imported from the layout: it is a contract with
 * browsers that already hold a value, so a rename has to fail here instead of following along.
 */
const SIDEBAR_STORAGE_KEY = "erpify:sidebar-open";

/** Opens attempted on the account menu before the failure is allowed to surface. */
const OPEN_ATTEMPTS = 3;

function required<T>(value: T | undefined, what: string): T {
  if (value === undefined) throw new Error(`The navigation model no longer declares ${what}.`);
  return value;
}

// The expectations are read from the navigation model the layout renders, so a new account entry is
// covered without editing this file — and a model that stopped declaring one fails here rather than
// leaving the assertions below true of an empty list.
const ACCOUNT_ENTRIES: readonly NavSubItem[] = accountMenuItem.subItems ?? [];
const ACCOUNT_LINKS = ACCOUNT_ENTRIES.filter((entry) => entry.path !== Routes.HOME);
const ACCOUNT_LOGOUT = required(
  ACCOUNT_ENTRIES.find((entry) => entry.path === Routes.HOME),
  "an account entry targeting HOME (the logout)",
);
// "My profile" repeats its parent's path, so an entry below it is what proves the active-state walk
// looks at the sub-items instead of only comparing the parent.
const NESTED_ACCOUNT_ENTRY = required(
  ACCOUNT_LINKS.find((entry) => entry.path !== accountMenuItem.path),
  "an account entry under a path of its own",
);

/** The account menu renders each sidebar entry under its own `--menu` QA address. */
function menuTestId(entry: NavSubItem): string {
  return `${required(entry.testId, `a test id for the account entry "${entry.name}"`)}--menu`;
}

/**
 * Opens the top-bar account menu and returns its popup.
 *
 * Shaped after `openRowDeleteItem` (`tests/app/backoffice/banks/_interactions.ts`): under jsdom a
 * just-opened Base UI popup can close again before its content mounts, so the OPEN is retried. Only
 * the open — the last attempt awaits single-shot, so a menu that genuinely never renders still fails.
 */
async function openAccountMenu(): Promise<HTMLElement> {
  for (let attempt = 1; attempt < OPEN_ATTEMPTS; attempt += 1) {
    fireEvent.click(screen.getByTestId("bo-layout__topbar-account"));
    try {
      return await screen.findByTestId("bo-layout__account-menu");
    } catch {
      // The popup never mounted — the open lost the race; re-open it.
    }
  }

  fireEvent.click(screen.getByTestId("bo-layout__topbar-account"));

  return screen.findByTestId("bo-layout__account-menu");
}

function renderLayout() {
  return render(
    <BackOfficeLayoutClient>
      <p data-testid="bo-layout-test__child">Section content</p>
    </BackOfficeLayoutClient>,
  );
}

describe("BackOfficeLayoutClient", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    // jsdom's `location` is unforgeable — its own `assign` is neither writable nor configurable — so
    // the global is replaced wholesale, keeping the fields the tree reads on the way to a render.
    vi.stubGlobal("location", {
      href: "https://localhost/backoffice",
      origin: "https://localhost",
      pathname: Routes.BACKOFFICE,
      search: "",
      assign,
    });
    nav.pathname = Routes.BACKOFFICE;
    auth.session = SESSION;
    auth.status = "authenticated";
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("signs out with a full-document navigation rather than a client-side push", async () => {
    renderLayout();
    await openAccountMenu();

    fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));

    // The revoke is awaited before leaving, so the assignment lands a microtask later.
    await waitFor(() => expect(assign).toHaveBeenCalledWith(Routes.HOME));
    expect(logout).toHaveBeenCalledTimes(1);
    // A push would keep this guarded subtree mounted, and RequireAuth would send the just-revoked
    // session to /login instead of the public landing.
    expect(push).not.toHaveBeenCalled();
  });

  it("routes the remaining account entries through the client-side router", async () => {
    const [entry] = ACCOUNT_LINKS;
    renderLayout();
    await openAccountMenu();

    fireEvent.click(screen.getByTestId(menuTestId(entry)));

    expect(push).toHaveBeenCalledWith(entry.path);
    expect(assign).not.toHaveBeenCalled();
    expect(logout).not.toHaveBeenCalled();
  });

  it("mirrors the account entries the navigation model declares", async () => {
    renderLayout();
    const menu = await openAccountMenu();

    expect(ACCOUNT_LINKS.length).toBeGreaterThan(0);
    ACCOUNT_LINKS.forEach((entry) => {
      expect(within(menu).getByTestId(menuTestId(entry))).toHaveTextContent(entry.name);
    });
    expect(within(menu).getByTestId(menuTestId(ACCOUNT_LOGOUT))).toHaveAttribute(
      "data-variant",
      "destructive",
    );
    expect(within(menu).getAllByRole("menuitem")).toHaveLength(ACCOUNT_LINKS.length + 1);
    expect(within(menu).getByText(SESSION.user.email)).toBeInTheDocument();
  });

  it("builds the account monogram from the address' local part, not the whole address", () => {
    // A one-letter local part is the only input that separates the two rules: `initials()` reads a
    // single word as a name and takes its first two characters, so the whole address yields "A@".
    auth.session = { ...SESSION, user: { ...SESSION.user, email: "a@erpify.test" } };

    renderLayout();

    const trigger = screen.getByTestId("bo-layout__topbar-account");
    expect(within(trigger).getByText("A")).toHaveAttribute("aria-hidden", "true");
    expect(within(trigger).queryByText("A@")).toBeNull();
  });

  it("restores the collapsed sidebar from storage and writes the new state back", () => {
    globalThis.localStorage.setItem(SIDEBAR_STORAGE_KEY, "0");

    renderLayout();

    const sidebar = screen.getByRole("complementary");
    expect(sidebar).toHaveAttribute("data-sidebar-open", "false");

    fireEvent.click(screen.getByTestId("bo-layout__topbar-toggle"));

    expect(sidebar).toHaveAttribute("data-sidebar-open", "true");
    expect(globalThis.localStorage.getItem(SIDEBAR_STORAGE_KEY)).toBe("1");
  });

  it("collapses the sidebar on Ctrl/Cmd+B and ignores the bare letter", () => {
    renderLayout();
    const sidebar = screen.getByRole("complementary");

    // The listener is global, so an unmodified "b" would fire while the user types into any field.
    fireEvent.keyDown(document.body, { key: "b" });
    expect(sidebar).toHaveAttribute("data-sidebar-open", "true");

    fireEvent.keyDown(document.body, { key: "b", ctrlKey: true });

    expect(sidebar).toHaveAttribute("data-sidebar-open", "false");
    expect(globalThis.localStorage.getItem(SIDEBAR_STORAGE_KEY)).toBe("0");
  });

  it("marks the account group active from a route nested under one of its entries", () => {
    nav.pathname = Routes.BACKOFFICE;
    const { unmount } = renderLayout();
    expect(screen.getByTitle(accountMenuItem.name)).not.toHaveClass("sidebar-item--active");
    unmount();

    nav.pathname = NESTED_ACCOUNT_ENTRY.path;
    renderLayout();

    expect(screen.getByTitle(accountMenuItem.name)).toHaveClass("sidebar-item--active");
    expect(screen.getByTestId("bo-layout__topbar-title")).toHaveTextContent(
      NESTED_ACCOUNT_ENTRY.name,
    );
  });

  it("renders no chrome and no children while the session is still hydrating", () => {
    auth.status = "hydrating";
    auth.session = null;

    const { container } = renderLayout();

    expect(container).toBeEmptyDOMElement();
    expect(screen.queryByTestId("bo-layout-test__child")).toBeNull();
    expect(screen.queryByTestId("bo-layout__topbar-account")).toBeNull();
  });
});
