import { describe, it, expect, beforeEach, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";

const { push, nav, auth } = vi.hoisted(() => ({
  push: vi.fn(),
  nav: { pathname: "/backoffice" },
  auth: { session: null as Session | null, status: "authenticated" as string },
}));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ ...auth, login: vi.fn(), logout: vi.fn(), override: vi.fn() }),
}));

vi.mock("@/context/shared/access/application/useRecoveryReadiness", () => ({
  useRecoveryReadiness: () => ({ readiness: { kind: "unknown" }, publish: vi.fn() }),
  useRecoveryReadinessPublisher: () => vi.fn(),
}));
vi.mock("@/context/shared/access/infrastructure/ui/RecoveryReadinessProvider", () => ({
  RecoveryReadinessProvider: ({ children }: { children: React.ReactNode }) => children,
}));

vi.mock("next/navigation", () => {
  const router = { push, replace: vi.fn(), refresh: vi.fn(), back: vi.fn(), prefetch: vi.fn() };
  return { useRouter: () => router, usePathname: () => nav.pathname };
});

import BackOfficeLayoutClient from "@/app/backoffice/BackOfficeLayoutClient";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Session } from "@/context/shared/access/domain/Session";
import { releaseDeparture } from "@/context/shared/navigation/application/departure";

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

const BANK_DETAIL = `${Routes.BACKOFFICE}/banks/0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d`;

function renderLayout() {
  return render(
    <BackOfficeLayoutClient>
      <p>Section content</p>
    </BackOfficeLayoutClient>,
  );
}

function sidebar(): HTMLElement {
  return screen.getByTestId("bo-layout__sidebar");
}

describe("BackOfficeLayoutClient wayfinding", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    nav.pathname = Routes.BACKOFFICE;
    auth.session = SESSION;
    auth.status = "authenticated";
    releaseDeparture();
  });

  it("opens the section holding the route on first render, with its leaf marked current", () => {
    // A deep link or a reload lands straight on the detail page: the section must already be open,
    // or the operator sees a collapsed parent and no trace of where they are.
    nav.pathname = BANK_DETAIL;
    renderLayout();

    const treasury = within(sidebar()).getByRole("button", { name: "Treasury" });
    expect(treasury).toHaveClass("sidebar-item--active");
    expect(treasury).toHaveAttribute("aria-expanded", "true");

    const banks = within(sidebar()).getByRole("link", { name: "Banks" });
    expect(banks).toHaveAttribute("aria-current", "page");
    expect(within(sidebar()).getByRole("link", { name: "Bank Accounts" })).not.toHaveAttribute(
      "aria-current",
    );
  });

  it("states the collapsed state of a section it does not hold", () => {
    renderLayout();

    const sales = within(sidebar()).getByRole("button", { name: /^Sales/ });
    expect(sales).toHaveAttribute("aria-expanded", "false");

    fireEvent.click(sales);

    expect(sales).toHaveAttribute("aria-expanded", "true");
    expect(sales).toHaveAttribute("aria-controls");
  });

  it("marks entries whose module is not built yet, and leaves live ones unmarked", () => {
    renderLayout();

    expect(within(sidebar()).getByRole("link", { name: "Invoicing, coming soon" })).toHaveAttribute(
      "title",
      "Invoicing (coming soon)",
    );
    expect(within(sidebar()).getByRole("button", { name: "Treasury" })).toHaveAttribute(
      "title",
      "Treasury",
    );
  });

  it("lights the same leaf in the mobile drawer", async () => {
    nav.pathname = BANK_DETAIL;
    renderLayout();

    fireEvent.click(screen.getByLabelText("Open navigation menu"));
    const drawer = await screen.findByRole("dialog", { name: "Navigation menu" });

    expect(within(drawer).getByRole("link", { name: "Banks" })).toHaveAttribute(
      "aria-current",
      "page",
    );
    expect(
      within(drawer).getByRole("link", { name: "Invoicing, coming soon" }),
    ).toBeInTheDocument();
  });

  it("keeps the drawer short by not listing the leaves of a parent that is entirely planned", async () => {
    renderLayout();

    fireEvent.click(screen.getByLabelText("Open navigation menu"));
    const drawer = await screen.findByRole("dialog", { name: "Navigation menu" });

    expect(
      within(drawer).getByRole("link", { name: "Customers, coming soon" }),
    ).toBeInTheDocument();
    expect(within(drawer).queryByRole("link", { name: /^Clients/ })).toBeNull();
    // A parent with one live leaf still lists all of them, planned ones included.
    expect(within(drawer).getByRole("link", { name: "Banks" })).toBeInTheDocument();
    expect(
      within(drawer).getByRole("link", { name: "Cash Flow, coming soon" }),
    ).toBeInTheDocument();
  });
});

describe("BackOfficeLayoutClient go-to palette", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    nav.pathname = Routes.BACKOFFICE;
    auth.session = SESSION;
    auth.status = "authenticated";
    releaseDeparture();
  });

  async function openWithShortcut(): Promise<HTMLElement> {
    fireEvent.keyDown(document.body, { key: "k", ctrlKey: true });
    return screen.findByRole("combobox", { name: "Go to page" });
  }

  it("opens on Ctrl/Cmd+K with the focus in the field, and ignores the bare letter", async () => {
    renderLayout();

    fireEvent.keyDown(document.body, { key: "k" });
    expect(screen.queryByTestId("go-to-palette")).toBeNull();

    const input = await openWithShortcut();
    await vi.waitFor(() => expect(input).toHaveFocus());
  });

  it("opens from the top-bar control, which no longer pretends to be a dead search button", async () => {
    renderLayout();

    fireEvent.click(screen.getByTestId("bo-layout__topbar-search"));

    expect(await screen.findByRole("combobox", { name: "Go to page" })).toBeInTheDocument();
  });

  it("filters, moves with the arrow keys and opens the active page on Enter", async () => {
    renderLayout();
    const input = await openWithShortcut();

    fireEvent.change(input, { target: { value: "bank" } });
    const options = screen.getAllByRole("option");
    expect(options[0]).toHaveAttribute("aria-selected", "true");
    expect(input).toHaveAttribute("aria-activedescendant", options[0].id);

    fireEvent.keyDown(input, { key: "ArrowDown" });
    expect(screen.getAllByRole("option")[1]).toHaveAttribute("aria-selected", "true");

    fireEvent.keyDown(input, { key: "Enter" });

    expect(push).toHaveBeenCalledWith(`${Routes.BACKOFFICE}/bank-accounts`);
    await vi.waitFor(() => expect(screen.queryByTestId("go-to-palette")).toBeNull());
  });

  it("offers planned modules as coming soon rather than hiding them", async () => {
    renderLayout();
    const input = await openWithShortcut();

    fireEvent.change(input, { target: { value: "invoicing" } });

    expect(screen.getByRole("option", { name: /Invoicing, coming soon/ })).toBeInTheDocument();
  });

  it("says so when nothing matches", async () => {
    renderLayout();
    const input = await openWithShortcut();

    fireEvent.change(input, { target: { value: "zzz-nothing" } });

    expect(screen.queryAllByRole("option")).toHaveLength(0);
    expect(screen.getByTestId("go-to-palette__empty")).toHaveTextContent("No page matches");
    expect(input).not.toHaveAttribute("aria-activedescendant");
  });

  it("tells a screen reader how many pages match, and when none does", async () => {
    renderLayout();
    const input = await openWithShortcut();
    const status = screen.getByTestId("go-to-palette__status");

    expect(screen.getAllByRole("status")).toContain(status);
    expect(status).toHaveAttribute("aria-live", "polite");
    expect(status).toBeEmptyDOMElement();

    fireEvent.change(input, { target: { value: "banks" } });
    expect(status).toHaveTextContent("1 page found.");

    fireEvent.change(input, { target: { value: "bank" } });
    expect(status).toHaveTextContent(`${screen.getAllByRole("option").length} pages found.`);

    fireEvent.change(input, { target: { value: "zzz-nothing" } });
    expect(status).toHaveTextContent("No page matches “zzz-nothing”.");
  });

  it("does not open on top of another dialog", async () => {
    renderLayout();
    fireEvent.click(screen.getByLabelText("Open navigation menu"));
    await screen.findByRole("dialog", { name: "Navigation menu" });

    fireEvent.keyDown(document.body, { key: "k", ctrlKey: true });

    expect(screen.queryByTestId("go-to-palette")).toBeNull();
  });

  it("never offers a page the session's sidebar hides", async () => {
    auth.session = { ...SESSION, permissions: [], user: { ...SESSION.user, permissions: [] } };
    renderLayout();
    const input = await openWithShortcut();

    fireEvent.change(input, { target: { value: "users" } });

    expect(screen.queryByRole("option", { name: /^Users/ })).toBeNull();
  });
});

describe("BackOfficeLayoutClient top-bar notifications", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    nav.pathname = Routes.BACKOFFICE;
    auth.session = SESSION;
    auth.status = "authenticated";
    releaseDeparture();
  });

  it("leads to the notifications page and claims no unread state it does not have", () => {
    renderLayout();
    const bell = screen.getByTestId("bo-layout__topbar-notifications");

    // The control used to wear a permanent "unread" dot with no notification behind it.
    expect(bell.querySelector(".bo-layout__topbar-badge")).toBeNull();

    fireEvent.click(bell);

    expect(push).toHaveBeenCalledWith(`${Routes.PROFILE}/notifications`);
  });
});
