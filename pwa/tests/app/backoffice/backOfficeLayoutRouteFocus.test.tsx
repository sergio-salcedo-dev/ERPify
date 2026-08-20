import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";

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

  it("does not pull focus back on a re-render that changes no route", () => {
    const { rerender } = render(content(<button data-testid="route-focus-test__cta">Go</button>));
    const cta = screen.getByTestId("route-focus-test__cta");
    cta.focus();
    rerender(content(<button data-testid="route-focus-test__cta">Go</button>));
    expect(globalThis.document.activeElement).toBe(cta);
  });
});
