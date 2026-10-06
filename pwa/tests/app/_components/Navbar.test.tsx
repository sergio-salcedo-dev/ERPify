import { describe, it, expect, beforeEach, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";

const { auth } = vi.hoisted(() => ({
  // A literal rather than `AuthStatus.*`: a mock factory is hoisted above the imports, so it
  // cannot read a value imported here.
  auth: { status: "hydrating" as string },
}));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({
    status: auth.status,
    session: null,
    login: vi.fn(),
    logout: vi.fn(),
    override: vi.fn(),
  }),
}));

import { Navbar } from "@/app/_components/Navbar";
import { AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Routes } from "@/context/shared/routing/domain/Routes";

const ACCESS_CTAS = [
  "navbar__link-login",
  "navbar__go-to-backoffice-button",
  "navbar__link-login--mobile",
  "navbar__go-to-backoffice-button--mobile",
] as const;

function renderNavbar(goToBackoffice = vi.fn()) {
  render(<Navbar goToBackoffice={goToBackoffice} />);
  return { goToBackoffice };
}

function openMobileMenu() {
  fireEvent.click(screen.getByTestId("navbar__mobile-menu-toggle"));
}

beforeEach(() => {
  auth.status = AuthStatus.HYDRATING;
});

describe("Navbar access cluster", () => {
  it("offers 'Sign in' and no back-office entry to an anonymous visitor", () => {
    auth.status = AuthStatus.UNAUTHENTICATED;

    renderNavbar();
    openMobileMenu();

    expect(screen.getByTestId("navbar__link-login")).toHaveAttribute("href", Routes.LOGIN);
    expect(screen.getByTestId("navbar__link-login--mobile")).toHaveAttribute("href", Routes.LOGIN);
    expect(screen.queryByTestId("navbar__go-to-backoffice-button")).toBeNull();
    expect(screen.queryByTestId("navbar__go-to-backoffice-button--mobile")).toBeNull();
  });

  it("offers the back-office entry and hides 'Sign in' for a signed-in visitor", () => {
    auth.status = AuthStatus.AUTHENTICATED;

    const { goToBackoffice } = renderNavbar();
    openMobileMenu();

    expect(screen.queryByTestId("navbar__link-login")).toBeNull();
    expect(screen.queryByTestId("navbar__link-login--mobile")).toBeNull();
    fireEvent.click(screen.getByTestId("navbar__go-to-backoffice-button"));
    fireEvent.click(screen.getByTestId("navbar__go-to-backoffice-button--mobile"));
    expect(goToBackoffice).toHaveBeenCalledTimes(2);
  });

  // Either CTA here would be a guess, and the server render always guesses: it never holds the
  // session. The slot stays in the layout, empty, so nothing beside it moves once the probe lands.
  it("offers neither call to action while the session probe is in flight", () => {
    auth.status = AuthStatus.HYDRATING;

    renderNavbar();
    openMobileMenu();

    for (const testId of ACCESS_CTAS) {
      expect(screen.queryByTestId(testId)).toBeNull();
    }
    const slot = screen.getByTestId("navbar__access");
    expect(slot).toBeEmptyDOMElement();
    expect(slot.className).toContain("min-w-28");
  });

  // A server that cannot answer is not a session; the sign-in form is where that visitor would go,
  // and it reports the outage itself.
  it("treats an unavailable identity service as signed out", () => {
    auth.status = AuthStatus.UNAVAILABLE;

    renderNavbar();

    const slot = screen.getByTestId("navbar__access");
    expect(within(slot).getByTestId("navbar__link-login")).toBeInTheDocument();
    expect(screen.queryByTestId("navbar__go-to-backoffice-button")).toBeNull();
  });

  it("keeps the links that do not depend on the session in every state", () => {
    for (const status of Object.values(AuthStatus)) {
      auth.status = status;
      const { unmount } = render(<Navbar goToBackoffice={vi.fn()} />);

      expect(screen.getByTestId("navbar__link-status")).toHaveAttribute("href", Routes.STATUS);
      expect(screen.getByTestId("navbar__theme")).toBeInTheDocument();
      unmount();
    }
  });
});
