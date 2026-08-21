import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";

const { push, logout, override, nav, auth, assign, replace, routerReplace, toastError } =
  vi.hoisted(() => ({
    push: vi.fn(),
    logout: vi.fn(() => Promise.resolve()),
    override: vi.fn(),
    assign: vi.fn(),
    replace: vi.fn(),
    // Distinct from `replace` above: this is next/navigation's router.replace, the one
    // RequireAuth calls — kept separate so a test can assert on ONE of the two navigations
    // without the other's calls polluting the count.
    routerReplace: vi.fn(),
    toastError: vi.fn(),
    nav: { pathname: "/backoffice" },
    // `status` is the literal the guard compares against rather than `AuthStatus.AUTHENTICATED`:
    // a mock factory is hoisted above the imports, so it cannot read a value imported here.
    auth: {
      session: null as Session | null,
      status: "authenticated" as string,
      isSigningOut: false,
    },
  }));

// One mock covers all three consumers: the layout reaches for `useSession` through the `@/` alias
// while RequireAuth and DevSessionSwitcher import it relatively, and both resolve to this module.
// RequireAuth renders nothing unless the status is `authenticated`, and DevSessionSwitcher — mounted
// because `isDevToolsAvailable()` is true outside production — reads `session.roles`/`user.status`
// and `override`, so an incomplete value here would empty the tree instead of failing loudly.
vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({
    ...auth,
    login: vi.fn(),
    logout,
    override,
    // A plain mutation, not React state: the component's OWN setSignOut call in the same
    // handler is what triggers the re-render that then reads this back through useSession().
    setIsSigningOut: (value: boolean) => {
      auth.isSigningOut = value;
    },
  }),
}));

// The router object is built once so RequireAuth's effect does not re-run on every render.
vi.mock("next/navigation", () => {
  const router = {
    push,
    replace: routerReplace,
    refresh: vi.fn(),
    back: vi.fn(),
    prefetch: vi.fn(),
  };
  return { useRouter: () => router, usePathname: () => nav.pathname };
});

vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: { error: toastError, success: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

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

const SIGN_OUT = "sign-out";

/** The label the sign-out entry wears while its revoke is outstanding. */
const LEAVING_LABEL = "Signing out…";

function required<T>(value: T | undefined, what: string): T {
  if (value === undefined) throw new Error(`The navigation model no longer declares ${what}.`);
  return value;
}

// The expectations are read from the navigation model the layout renders, so a new account entry is
// covered without editing this file — and a model that stopped declaring one fails here rather than
// leaving the assertions below true of an empty list.
const ACCOUNT_ENTRIES: readonly NavSubItem[] = accountMenuItem.subItems ?? [];
// Mirrors the component's own predicate rather than restating it: an entry carrying some other
// intent is a plain link there, so it has to be one here too or this grid stops covering it.
const ACCOUNT_LINKS = ACCOUNT_ENTRIES.filter((entry) => entry.action !== SIGN_OUT);
const ACCOUNT_LOGOUT = required(
  ACCOUNT_ENTRIES.find((entry) => entry.action === SIGN_OUT),
  "an account entry carrying the sign-out intent",
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

/**
 * Expands the sidebar's Account group and returns the aside. The desktop sidebar renders its
 * sub-items through `SidebarItem`, which keeps them collapsed until the parent is clicked — so
 * this leaf is reachable only through that toggle, and it is the surface that stays on screen for
 * the whole sign-out window.
 */
function openSidebarAccountGroup(): HTMLElement {
  const sidebar = screen.getByRole("complementary");
  // Idempotent, because the parent button TOGGLES. Unlike the two menus this group does not
  // close when a leaf is activated, so a caller reopening every surface after a click would
  // close this one instead.
  const leaf = ACCOUNT_LOGOUT.testId;
  if (leaf === undefined || within(sidebar).queryByTestId(leaf) === null) {
    fireEvent.click(within(sidebar).getByTitle(accountMenuItem.name));
  }
  return sidebar;
}

/**
 * The three surfaces that render the account entries, each with its own QA address. Sign-out has to
 * work identically through all of them: the discriminator travels from the model through whichever
 * component renders the leaf, and the desktop sidebar's goes through a design-system component
 * whose callback signature is the only thing carrying it across that boundary.
 */
const SIGN_OUT_SURFACES: ReadonlyArray<
  readonly [string, () => Promise<void>, (entry: NavSubItem) => string]
> = [
  [
    "the desktop sidebar",
    async () => {
      openSidebarAccountGroup();
    },
    (entry) => required(entry.testId, "a test id for the sign-out entry"),
  ],
  [
    "the mobile drawer",
    async () => {
      fireEvent.click(screen.getByLabelText("Open navigation menu"));
      await screen.findByRole("dialog");
    },
    (entry) => `${required(entry.testId, "a test id for the sign-out entry")}--mobile`,
  ],
  [
    "the top-bar menu",
    async () => {
      await openAccountMenu();
    },
    menuTestId,
  ],
];

// Mirrors SIGN_OUT_BUDGET_MS in BackOfficeLayoutClient: the wait is a contract with the user
// (leave within this budget), so the test states it rather than reaching for the module's copy.
const SIGN_OUT_BUDGET_MS = 3_000;

// Mirrors NAVIGATION_COMMIT_BUDGET_MS in hardNavigate, and stated here for the same reason: how
// long the user waits before the affordance comes back is a contract with them, not an
// implementation detail to import.
const NAVIGATION_COMMIT_BUDGET_MS = 10_000;

/** What the status region says once the document turns out not to be leaving after all. */
const REFUSED_MESSAGE = "Sign-out did not complete. Please try again.";
const STALLED_MESSAGE = "Sign-out is taking longer than expected. You can try again.";

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
      replace,
    });
    nav.pathname = Routes.BACKOFFICE;
    auth.session = SESSION;
    auth.status = "authenticated";
    auth.isSigningOut = false;
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("signs out with a full-document navigation rather than a client-side push", async () => {
    renderLayout();
    await openAccountMenu();

    fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));

    // The revoke is awaited before leaving, so the navigation lands a microtask later.
    await waitFor(() => expect(replace).toHaveBeenCalledWith(Routes.HOME));
    // replace, not assign: the authenticated page must not stay one Back press away, where
    // a bfcache restore would put the previous user's data back on a shared machine.
    expect(assign).not.toHaveBeenCalled();
    expect(logout).toHaveBeenCalledTimes(1);
    // A push would keep this guarded subtree mounted, and RequireAuth would send the just-revoked
    // session to /login instead of the public landing.
    expect(push).not.toHaveBeenCalled();
  });

  it.each(SIGN_OUT_SURFACES)("signs out from %s", async (_label, open, addressOf) => {
    // One case per surface, because only the top-bar leaf is rendered by the layout itself. The
    // sidebar's goes through SidebarItem, whose callback is what carries the intent across the
    // design-system boundary — and a second optional parameter stays assignable to
    // `(path: string) => void`, so tsc cannot tell whether it is forwarded. Only these can.
    renderLayout();
    await open();

    fireEvent.click(screen.getByTestId(addressOf(ACCOUNT_LOGOUT)));

    await waitFor(() => expect(replace).toHaveBeenCalledWith(Routes.HOME));
    expect(logout).toHaveBeenCalledTimes(1);
    expect(push).not.toHaveBeenCalled();
  });

  it.each(SIGN_OUT_SURFACES)("says it is leaving, on %s", async (_label, open, addressOf) => {
    // Once per surface, because the derivation is shared but the rendering is hand-copied into
    // three JSX sites: deleting the attribute from any one of them left the whole suite green.
    // The menus close on activation, so the surface is reopened before asserting.
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      renderLayout();
      await open();

      const address = addressOf(ACCOUNT_LOGOUT);
      fireEvent.click(screen.getByTestId(address));
      await open();

      // aria-disabled, never `disabled`: the click has to keep reaching the handler so the
      // in-flight guard stays the single point that drops it, and stays falsifiable.
      await waitFor(() =>
        expect(screen.getByTestId(address)).toHaveAttribute("aria-disabled", "true"),
      );
      expect(screen.getByTestId(address)).toHaveTextContent(LEAVING_LABEL);
    } finally {
      vi.useRealTimers();
    }
  });

  it("announces that it is leaving on the paths where the menu closes over the entry", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      renderLayout();
      await openAccountMenu();

      const status = screen.getByTestId("bo-layout__leaving-status");
      expect(status).toHaveTextContent("");

      fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));

      // The dropdown closes on activation, so the entry that carries the relabel is gone. Without
      // this region the window is silent on the most ordinary desktop path, while every other
      // navigation is being dropped.
      await waitFor(() => expect(status).toHaveTextContent(LEAVING_LABEL));
    } finally {
      vi.useRealTimers();
    }
  });

  it("keeps the very button it relabels, rather than replacing it", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      renderLayout();
      openSidebarAccountGroup();

      const address = required(ACCOUNT_LOGOUT.testId, "a test id for the sign-out entry");
      const before = screen.getByTestId(address);
      fireEvent.click(before);
      await waitFor(() => expect(screen.getByTestId(address)).toHaveTextContent(LEAVING_LABEL));

      // Same DOM node, not merely the same test id. A React key derived from the label would
      // change with it, so the button the user just activated would be destroyed and rebuilt —
      // focus falls to <body>, and the new state lands on a node no assistive technology is
      // watching. A testid-addressed assertion cannot see that; identity can.
      expect(screen.getByTestId(address)).toBe(before);
    } finally {
      vi.useRealTimers();
    }
  });

  it("does not race RequireAuth's own redirect when the guard tears the guarded subtree down", async () => {
    // The fast path, which no other case here renders. logout() clears the session inside its own
    // finally, so RequireAuth returns null for the subtree it guards — status region, menu and
    // entry all go with it — before the sign-out's own navigation is even attempted. isSigningOut
    // is still true at that point: without it, RequireAuth's own redirect effect would fire a
    // SECOND navigation (to /login) on top of the one hardNavigate is about to perform.
    logout.mockImplementationOnce(() => {
      auth.session = null;
      auth.status = "unauthenticated";
      return Promise.resolve();
    });
    const { rerender } = renderLayout();
    await openAccountMenu();

    fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));
    await waitFor(() => expect(logout).toHaveBeenCalledTimes(1));
    // The real re-render comes from the provider's own setState; the port is mocked here and has
    // none, so the cleared session is pushed through by hand.
    rerender(
      <BackOfficeLayoutClient>
        <p data-testid="bo-layout-test__child">Section content</p>
      </BackOfficeLayoutClient>,
    );

    // The guarded subtree — status region included — is gone.
    expect(screen.queryByTestId("bo-layout-test__child")).toBeNull();
    expect(screen.queryByTestId("bo-layout__leaving-status")).toBeNull();
    // RequireAuth's own redirect did not fire on top of the sign-out's navigation.
    expect(routerReplace).not.toHaveBeenCalled();
    // hardNavigate's own navigation still lands — it is scheduled on the promise, not on the
    // tree, so it fires regardless of what is left mounted.
    await waitFor(() => expect(replace).toHaveBeenCalledWith(Routes.HOME));
    expect(push).not.toHaveBeenCalled();
  });

  it("still announces a refusal by toast when the status region is already gone", async () => {
    // The realistic timing the two cases above don't cover: in production logout()'s own
    // finally clears the session well before an outcome is known (it's bound by
    // SIGN_OUT_BUDGET_MS, the same budget racing it here), so by the time hardNavigate's
    // failure callback runs, RequireAuth has usually already torn the status region down.
    // The toast is the channel that does NOT depend on that region still being mounted.
    logout.mockImplementationOnce(() => {
      auth.session = null;
      auth.status = "unauthenticated";
      return Promise.resolve();
    });
    replace.mockImplementationOnce(() => {
      throw new Error("navigation refused");
    });
    const { rerender } = renderLayout();
    await openAccountMenu();

    fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));
    await waitFor(() => expect(logout).toHaveBeenCalledTimes(1));
    rerender(
      <BackOfficeLayoutClient>
        <p data-testid="bo-layout-test__child">Section content</p>
      </BackOfficeLayoutClient>,
    );

    // The guarded subtree, status region included, is already gone by the time the refusal
    // is known.
    expect(screen.queryByTestId("bo-layout__leaving-status")).toBeNull();
    await waitFor(() => expect(toastError).toHaveBeenCalledWith(REFUSED_MESSAGE));
  });

  it("lets RequireAuth redirect once isSigningOut clears, even though session is already unauthenticated", async () => {
    // The other half of the race guard, isolated from the sign-out click: RequireAuth must not
    // redirect WHILE another interaction still owns the outcome, and must redirect the moment
    // it no longer does — this is what re-enables the ordinary unauthenticated-route guard once
    // hardNavigate's own failure callback has cleared isSigningOut.
    auth.status = "unauthenticated";
    auth.session = null;
    auth.isSigningOut = true;
    const { rerender } = renderLayout();

    expect(screen.queryByTestId("bo-layout-test__child")).toBeNull();
    expect(routerReplace).not.toHaveBeenCalled();

    auth.isSigningOut = false;
    rerender(
      <BackOfficeLayoutClient>
        <p data-testid="bo-layout-test__child">Section content</p>
      </BackOfficeLayoutClient>,
    );

    await waitFor(() =>
      expect(routerReplace).toHaveBeenCalledWith(expect.stringContaining(`${Routes.LOGIN}?next=`)),
    );
  });

  it("drops a second sign-out while the first is still in flight", async () => {
    // Fake timers so the budget this test deliberately never resolves cannot outlive the
    // test and fire its navigation into a later one.
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      // The click closes the menu, so a second one needs it reopened — reachable for as long
      // as the revoke is outstanding, which is exactly the window REVOKE_BUDGET_MS bounds.
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      renderLayout();
      await openAccountMenu();
      fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));

      await openAccountMenu();
      fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));

      // A second revoke would POST against a session the first one already revoked, and
      // schedule a second navigation behind it.
      expect(logout).toHaveBeenCalledTimes(1);
      expect(replace).not.toHaveBeenCalled();
    } finally {
      vi.useRealTimers();
    }
  });

  it("hands the sign-out budget to the transport instead of racing a timer here", async () => {
    renderLayout();
    await openAccountMenu();

    fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));

    await waitFor(() => expect(replace).toHaveBeenCalledWith(Routes.HOME));
    // The bound travels WITH the request. While it was a timer owned by this component, a
    // revoke that never settled was survivable on exactly one call out of every call the app
    // makes — "no request hangs forever" held for sign-out and nowhere else. Passing the number
    // down is what moves the invariant to the only layer that can hold it for all of them.
    expect(logout).toHaveBeenCalledWith(SIGN_OUT_BUDGET_MS);
  });

  it("recovers the menu when the navigation is refused", async () => {
    // The revoke is held open so the BUDGET wins the race. That ordering is not a convenience:
    // it is the only one in which a recovery exists to be seen. When logout() settles first it
    // clears the session, RequireAuth returns null, and menu, entry and status region are gone
    // before the navigation is even attempted — so a case whose logout resolves immediately
    // *without* clearing the session proves the recovery works in a world the app never enters.
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      replace.mockImplementationOnce(() => {
        throw new Error("navigation refused");
      });
      renderLayout();
      await openAccountMenu();
      fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));

      await act(() => vi.advanceTimersByTimeAsync(SIGN_OUT_BUDGET_MS));

      // The document stayed, so sign-out has to be attemptable again rather than wedged — and
      // the region has to SAY so. Emptying it announces nothing: a `role="status"` speaks on
      // insertion, so the old `: ""` left a screen-reader user with "Signing out…", then silence,
      // then a menu that quietly worked again, with no statement that it had not happened.
      const status = screen.getByTestId("bo-layout__leaving-status");
      await waitFor(() => expect(status).toHaveTextContent(REFUSED_MESSAGE));
      // An action error is `assertive` and VISIBLE (DESIGN.md): every surface but the expanded
      // sidebar closes over the entry on activation, and a reverted label cannot distinguish
      // "failed" from "never started".
      expect(status).toHaveAttribute("aria-live", "assertive");
      expect(status).not.toHaveClass("sr-only");
      // The outcome is announced this way too, reaching the user regardless of whether the
      // guarded subtree this region lives in is still mounted by the time it is known.
      expect(toastError).toHaveBeenCalledWith(REFUSED_MESSAGE);

      await openAccountMenu();
      fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LINKS[0])));
      expect(push).toHaveBeenCalledWith(ACCOUNT_LINKS[0].path);
      // And the message describes one interaction, not the rest of the document's life.
      await waitFor(() => expect(status).toHaveTextContent(""));
    } finally {
      vi.useRealTimers();
    }
  });

  it("recovers the menu when the navigation is ignored rather than refused", async () => {
    // The case the `catch` structurally could not see. A sandboxed navigable DROPS a navigation
    // without raising, so nothing threw, nothing unloaded, and the in-flight latch stayed set
    // for the life of the document — every menu-driven navigation dropped from then on.
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      renderLayout();
      await openAccountMenu();
      fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));

      await act(() => vi.advanceTimersByTimeAsync(SIGN_OUT_BUDGET_MS));
      await waitFor(() => expect(replace).toHaveBeenCalledWith(Routes.HOME));

      const status = screen.getByTestId("bo-layout__leaving-status");
      expect(status).toHaveTextContent(LEAVING_LABEL);

      await act(() => vi.advanceTimersByTimeAsync(NAVIGATION_COMMIT_BUDGET_MS));

      await waitFor(() => expect(status).toHaveTextContent(STALLED_MESSAGE));
      expect(toastError).toHaveBeenCalledWith(STALLED_MESSAGE);
      await openAccountMenu();
      fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LINKS[0])));
      expect(push).toHaveBeenCalledWith(ACCOUNT_LINKS[0].path);
    } finally {
      vi.useRealTimers();
    }
  });

  it("says nothing about a navigation that committed", async () => {
    // The other direction, and the one that keeps the recovery honest: a document that IS
    // leaving is discarded, and "sign-out did not complete" announced into it would be a false
    // statement made to the one user who cannot see the page change under them.
    //
    // `persisted: false` is load-bearing. `pagehide` states two different facts, and the other
    // one — bfcache entry or a freeze, which iOS Safari fires whenever the app is backgrounded —
    // leaves a document that can come back; treating it as a commit disarms the only bound the
    // caller has. A bare Event would assert nothing about that distinction.
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      renderLayout();
      await openAccountMenu();
      fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));
      await act(() => vi.advanceTimersByTimeAsync(SIGN_OUT_BUDGET_MS));
      await waitFor(() => expect(replace).toHaveBeenCalledWith(Routes.HOME));

      globalThis.dispatchEvent(new PageTransitionEvent("pagehide", { persisted: false }));
      await act(() => vi.advanceTimersByTimeAsync(NAVIGATION_COMMIT_BUDGET_MS * 2));

      const status = screen.getByTestId("bo-layout__leaving-status");
      expect(status).toHaveTextContent(LEAVING_LABEL);
    } finally {
      vi.useRealTimers();
    }
  });

  it("says only what is true when a real navigation outruns its own budget", async () => {
    // The oracle is one-sided by construction — it reads "the document is still here" — so a
    // genuine navigation slower than the budget reports `not-committed` too. That is why the
    // message is a statement about the WAIT, never about the outcome: announcing a failure here
    // would be a lie told to the one user who cannot see the page change under them. This pins
    // the ordering the case above cannot: the budget elapses FIRST, pagehide arrives after.
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      renderLayout();
      await openAccountMenu();
      fireEvent.click(screen.getByTestId(menuTestId(ACCOUNT_LOGOUT)));
      await act(() => vi.advanceTimersByTimeAsync(SIGN_OUT_BUDGET_MS));

      await act(() => vi.advanceTimersByTimeAsync(NAVIGATION_COMMIT_BUDGET_MS));
      const status = screen.getByTestId("bo-layout__leaving-status");
      await waitFor(() => expect(status).toHaveTextContent(STALLED_MESSAGE));

      globalThis.dispatchEvent(new PageTransitionEvent("pagehide", { persisted: false }));

      expect(status).not.toHaveTextContent("did not complete");
    } finally {
      vi.useRealTimers();
    }
  });

  // The invariant, not the cosmetic: EVERY branch of handleNavigation closes the mobile drawer,
  // including the one that returns early. Leaving it open would leave an authenticated drawer on
  // screen for as long as REVOKE_BUDGET_MS. Asserted through the Sheet, the only consumer of
  // `isSidebarOpen`; `data-sidebar-open` is bound to `isCompact`, a different state entirely.
  it.each([
    ["a plain navigation entry", `${ACCOUNT_LINKS[0].testId}--mobile`, false],
    ["the sign-out entry", `${ACCOUNT_LOGOUT.testId}--mobile`, true],
  ])("closes the mobile drawer on %s", async (_label, testId, isSignOut) => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      if (isSignOut) logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      renderLayout();
      fireEvent.click(screen.getByLabelText("Open navigation menu"));

      const entry = await screen.findByTestId(testId);
      fireEvent.click(entry);

      await waitFor(() => expect(screen.queryByTestId(testId)).toBeNull());
      // The drawer closes on both branches — `setIsSidebarOpen(false)` runs before either — so
      // without these the sign-out case would stay green with the intent never forwarded.
      if (isSignOut) {
        expect(logout).toHaveBeenCalledTimes(1);
        expect(push).not.toHaveBeenCalled();
      } else {
        expect(logout).not.toHaveBeenCalled();
        expect(push).toHaveBeenCalledWith(ACCOUNT_LINKS[0].path);
      }
    } finally {
      vi.useRealTimers();
    }
  });

  it("routes the remaining account entries through the client-side router", async () => {
    const [entry] = ACCOUNT_LINKS;
    renderLayout();
    await openAccountMenu();

    fireEvent.click(screen.getByTestId(menuTestId(entry)));

    expect(push).toHaveBeenCalledWith(entry.path);
    expect(replace).not.toHaveBeenCalled();
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

  it("renders nothing at all while the session is still hydrating", () => {
    auth.status = "hydrating";
    auth.session = null;

    renderLayout();

    // The status region is a child of RequireAuth, so it shares its render gate: nothing renders
    // — not even an idle, empty status region — until the session resolves one way or the other.
    expect(screen.queryByTestId("bo-layout__leaving-status")).toBeNull();
    expect(screen.queryByTestId("bo-layout-test__child")).toBeNull();
    expect(screen.queryByTestId("bo-layout__topbar-account")).toBeNull();
  });
});
