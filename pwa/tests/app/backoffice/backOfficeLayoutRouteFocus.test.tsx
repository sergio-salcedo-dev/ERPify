import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";

const { push, logout, override, nav, auth } = vi.hoisted(() => ({
  push: vi.fn(),
  logout: vi.fn(() => Promise.resolve()),
  override: vi.fn(),
  nav: { pathname: "/backoffice" },
  auth: { session: null as Session | null, status: "authenticated" as string },
}));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ ...auth, login: vi.fn(), logout, override, setIsSigningOut: vi.fn() }),
}));

vi.mock("next/navigation", () => {
  const router = { push, replace: vi.fn(), refresh: vi.fn(), back: vi.fn(), prefetch: vi.fn() };
  return { useRouter: () => router, usePathname: () => nav.pathname };
});

import BackOfficeLayoutClient from "@/app/backoffice/BackOfficeLayoutClient";
import { accountMenuItem } from "@/app/backoffice/_lib/backofficeMenu";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Session } from "@/context/shared/access/domain/Session";

function required<T>(value: T | undefined, what: string): T {
  if (value === undefined) throw new Error(`The navigation model no longer declares ${what}.`);
  return value;
}

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

const BANKS = `${Routes.BACKOFFICE}/banks`;

function content(node: React.ReactNode = <p data-testid="route-focus-test__child">Section</p>) {
  return <BackOfficeLayoutClient>{node}</BackOfficeLayoutClient>;
}

function main(): HTMLElement {
  const element = globalThis.document.querySelector("main");
  if (element === null) throw new Error("The layout no longer renders a <main> landmark.");
  return element;
}

/**
 * After a client navigation Next calls `.focus()` on the changed segment's first host node, which
 * is a page root carrying no `tabIndex` — a no-op that leaves focus on `<body>`, so the next Tab
 * restarts at the skip link. These cases pin the correction and, as importantly, its two limits:
 * it never fires on the first paint, and it never takes focus from a page that placed it.
 */
describe("BackOfficeLayoutClient route focus", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    nav.pathname = Routes.BACKOFFICE;
    auth.session = SESSION;
    auth.status = "authenticated";
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("exposes <main> as a focus target so the skip link and the route focus can land", () => {
    render(content());
    expect(main()).toHaveAttribute("tabindex", "-1");
    expect(main()).toHaveAttribute("id", "main-content");
  });

  it("leaves the first paint alone — the browser owns focus on arrival", () => {
    render(content());
    expect(globalThis.document.activeElement).toBe(globalThis.document.body);
  });

  it("moves focus to <main> when a navigation strands it on <body>", () => {
    const { rerender } = render(content());
    nav.pathname = BANKS;
    rerender(content());
    expect(globalThis.document.activeElement).toBe(main());
  });

  it("yields to a page that claimed the focus itself", () => {
    const { rerender } = render(content());
    nav.pathname = BANKS;
    // eslint-disable-next-line jsx-a11y/no-autofocus
    rerender(content(<input data-testid="route-focus-test__field" autoFocus />));
    expect(globalThis.document.activeElement).toBe(screen.getByTestId("route-focus-test__field"));
  });

  it("leaves the focus on the chrome control that triggered the navigation", () => {
    // Not stranded: the control survives the navigation, so the next Tab continues from it. This
    // is what makes a sidebar parent keep its just-expanded sub-items next in the tab order.
    const { rerender } = render(content());
    const toggle = screen.getByTestId("bo-layout__topbar-toggle");
    toggle.focus();
    nav.pathname = BANKS;
    rerender(content());
    expect(globalThis.document.activeElement).toBe(toggle);
  });

  it("does not pull focus back on a re-render that changes no route", () => {
    const { rerender } = render(content(<button data-testid="route-focus-test__cta">Go</button>));
    const cta = screen.getByTestId("route-focus-test__cta");
    cta.focus();
    rerender(content(<button data-testid="route-focus-test__cta">Go</button>));
    expect(globalThis.document.activeElement).toBe(cta);
  });

  /**
   * The mobile Sheet never strands `<body>`: the clicked item keeps focus until Base UI's own
   * modal-dialog close hands it back to the trigger, so the correction above never fires for it
   * — measured live, that hand-back landed focus on the hamburger button, not the new page. The
   * fix reads a closing-via-navigation flag through the Sheet's own `finalFocus`, which is timed
   * to when it actually unmounts rather than to the route change.
   */
  describe("mobile Sheet close", () => {
    it("moves focus to <main> when the Sheet closes by navigating", async () => {
      render(content());
      fireEvent.click(screen.getByLabelText("Open navigation menu"));
      const dialog = await screen.findByRole("dialog");
      fireEvent.click(within(dialog).getByTitle("Dashboard"));

      await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
      expect(globalThis.document.activeElement).toBe(main());
    });

    it("still returns focus to the trigger when the Sheet closes without navigating", async () => {
      render(content());
      const trigger = screen.getByLabelText("Open navigation menu");
      fireEvent.click(trigger);
      const dialog = await screen.findByRole("dialog");
      fireEvent.keyDown(dialog, { key: "Escape" });

      await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
      expect(globalThis.document.activeElement).toBe(trigger);
    });

    it("also returns focus to the trigger when the Sheet closes on a backdrop press", async () => {
      render(content());
      const trigger = screen.getByLabelText("Open navigation menu");
      fireEvent.click(trigger);
      await screen.findByRole("dialog");
      const backdrop = globalThis.document.querySelector('[data-slot="sheet-overlay"]');
      if (backdrop === null) throw new Error("The Sheet no longer renders a backdrop to press.");
      fireEvent.pointerDown(backdrop);
      fireEvent.mouseDown(backdrop);
      fireEvent.click(backdrop);

      await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
      expect(globalThis.document.activeElement).toBe(trigger);
    });

    it("does not steal focus for a click the sign-out window blocks from navigating", async () => {
      const signOutEntry = required(
        accountMenuItem.subItems?.find((entry) => entry.action === "sign-out"),
        "an account entry carrying the sign-out intent",
      );
      const mobileTestId = `${required(signOutEntry.testId, "a test id for the sign-out entry")}--mobile`;

      render(content());
      const trigger = screen.getByLabelText("Open navigation menu");

      // First click is real: it starts sign-out (and is itself a navigating close — logout()
      // resolves eventually into a full-document navigation), which is not what this test pins.
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      fireEvent.click(trigger);
      let dialog = await screen.findByRole("dialog");
      fireEvent.click(within(dialog).getByTestId(mobileTestId));
      await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());

      // Reopen while sign-out is still in flight (logout() never resolves) and click again: the
      // component's own `isLeaving` guard blocks this second click from navigating anywhere, so
      // the Sheet closing here must behave like any other non-navigating close.
      fireEvent.click(trigger);
      dialog = await screen.findByRole("dialog");
      fireEvent.click(within(dialog).getByTestId(mobileTestId));

      await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
      expect(globalThis.document.activeElement).toBe(trigger);
    });

    it("does not leave a stale flag from a desktop click to misdirect the next unrelated Sheet close", async () => {
      render(content());
      // A desktop-sidebar navigation routes through the very same handleNavigation the mobile
      // Sheet uses (and never opens the Sheet) — it must not arm the mobile-close flag.
      const sidebar = screen.getByRole("complementary");
      fireEvent.click(within(sidebar).getByTitle("Dashboard"));

      const trigger = screen.getByLabelText("Open navigation menu");
      fireEvent.click(trigger);
      const dialog = await screen.findByRole("dialog");
      fireEvent.keyDown(dialog, { key: "Escape" });

      await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
      expect(globalThis.document.activeElement).toBe(trigger);
    });
  });
});
