import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, act } from "@testing-library/react";
import { AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";

let status: AuthStatus = AuthStatus.UNAUTHENTICATED;
const refresh = vi.fn();
vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ status, refresh }),
}));

import { Navbar } from "@/app/_components/Navbar";

const DESKTOP_SIGN_IN = "navbar__link-login";
const MOBILE_SIGN_IN = "navbar__link-login--mobile";

function renderNavbar(goToBackoffice: () => void = vi.fn()): void {
  render(<Navbar goToBackoffice={goToBackoffice} />);
}

function openMobileMenu(): void {
  fireEvent.click(screen.getByTestId("navbar__mobile-menu-toggle"));
}

beforeEach(() => {
  status = AuthStatus.UNAUTHENTICATED;
  refresh.mockReset();
  refresh.mockResolvedValue(null);
});

describe("Navbar — Sign in follows the session", () => {
  it.each([AuthStatus.AUTHENTICATED, AuthStatus.HYDRATING])(
    "offers no Sign in when the session is %s, on desktop or mobile",
    (current) => {
      status = current;
      renderNavbar();
      openMobileMenu();

      expect(screen.queryByTestId(DESKTOP_SIGN_IN)).toBeNull();
      expect(screen.queryByTestId(MOBILE_SIGN_IN)).toBeNull();
      expect(screen.getByTestId("navbar__go-to-backoffice-button")).toBeTruthy();
      expect(screen.getByTestId("navbar__go-to-backoffice-button--mobile")).toBeTruthy();
    },
  );

  it("offers Sign in to /login when the session is unauthenticated, on desktop and mobile", () => {
    renderNavbar();
    openMobileMenu();

    expect(screen.getByTestId(DESKTOP_SIGN_IN).getAttribute("href")).toBe("/login");
    expect(screen.getByTestId(MOBILE_SIGN_IN).getAttribute("href")).toBe("/login");
    expect(screen.getByTestId("navbar__go-to-backoffice-button")).toBeTruthy();
    expect(screen.getByTestId("navbar__go-to-backoffice-button--mobile")).toBeTruthy();
  });

  it("offers neither Sign in nor the Backoffice entry while the session is unavailable, on desktop or mobile", () => {
    status = AuthStatus.UNAVAILABLE;
    renderNavbar();
    openMobileMenu();

    expect(screen.queryByTestId(DESKTOP_SIGN_IN)).toBeNull();
    expect(screen.queryByTestId(MOBILE_SIGN_IN)).toBeNull();
    expect(screen.queryByTestId("navbar__go-to-backoffice-button")).toBeNull();
    expect(screen.queryByTestId("navbar__go-to-backoffice-button--mobile")).toBeNull();
    // The rest of the navbar stays usable: the outage is the session's, not the page's.
    expect(screen.getByTestId("navbar__link-status")).toBeTruthy();
    expect(screen.getByTestId("navbar__link-status--mobile")).toBeTruthy();
  });
});

describe("Navbar — session unavailable notice", () => {
  const CLUSTERS = [
    ["desktop", "navbar__session-unavailable", "navbar__session-unavailable-retry"],
    ["mobile", "navbar__session-unavailable--mobile", "navbar__session-unavailable-retry--mobile"],
  ] as const;

  it.each(CLUSTERS)(
    "tells the visitor sign-in is unavailable, on %s",
    (_cluster, noticeId, retryId) => {
      status = AuthStatus.UNAVAILABLE;
      renderNavbar();
      openMobileMenu();

      const notice = screen.getByTestId(noticeId);
      expect(notice.getAttribute("role")).toBe("status");
      expect(notice.textContent).toContain("Sign-in temporarily unavailable");
      const retry = screen.getByTestId(retryId);
      expect(retry.getAttribute("aria-label")).toBe("Try again");
      expect(retry.getAttribute("type")).toBe("button");
    },
  );

  it.each(CLUSTERS)(
    "asks the session provider again on this route when Try again is pressed, on %s",
    async (_cluster, _noticeId, retryId) => {
      status = AuthStatus.UNAVAILABLE;
      let settle!: () => void;
      refresh.mockReturnValue(
        new Promise<null>((resolve) => {
          settle = () => resolve(null);
        }),
      );
      renderNavbar();
      openMobileMenu();

      const retry = screen.getByTestId(retryId);
      fireEvent.click(retry);

      expect(refresh).toHaveBeenCalledTimes(1);
      expect((retry as HTMLButtonElement).disabled).toBe(true);
      await act(async () => {
        settle();
      });
      expect((retry as HTMLButtonElement).disabled).toBe(false);
    },
  );

  it.each([AuthStatus.AUTHENTICATED, AuthStatus.UNAUTHENTICATED, AuthStatus.HYDRATING])(
    "shows no notice when the session is %s",
    (current) => {
      status = current;
      renderNavbar();
      openMobileMenu();

      expect(screen.queryByTestId("navbar__session-unavailable")).toBeNull();
      expect(screen.queryByTestId("navbar__session-unavailable--mobile")).toBeNull();
    },
  );
});

describe("Navbar — Backoffice entry", () => {
  it.each([AuthStatus.AUTHENTICATED, AuthStatus.UNAUTHENTICATED, AuthStatus.HYDRATING])(
    "invokes goToBackoffice from both clusters when the session is %s",
    (current) => {
      status = current;
      const goToBackoffice = vi.fn();
      renderNavbar(goToBackoffice);
      openMobileMenu();

      fireEvent.click(screen.getByTestId("navbar__go-to-backoffice-button"));
      fireEvent.click(screen.getByTestId("navbar__go-to-backoffice-button--mobile"));

      expect(goToBackoffice).toHaveBeenCalledTimes(2);
    },
  );
});

describe("Navbar — resolved session status", () => {
  it.each(Object.values(AuthStatus))("exposes %s on the nav root", (current) => {
    status = current;
    renderNavbar();

    expect(screen.getByRole("navigation").getAttribute("data-session-status")).toBe(current);
  });

  it("reserves the desktop Sign in slot while hydrating with an inert placeholder", () => {
    status = AuthStatus.HYDRATING;
    const { container } = render(<Navbar goToBackoffice={vi.fn()} />);

    const placeholder = container.querySelector(".navbar__menu .navbar__link--login");
    expect(placeholder).not.toBeNull();
    expect(placeholder?.tagName).toBe("SPAN");
    expect(placeholder?.getAttribute("aria-hidden")).toBe("true");
    expect(placeholder?.hasAttribute("href")).toBe(false);
    expect(placeholder?.hasAttribute("tabindex")).toBe(false);
    expect(placeholder?.hasAttribute("data-testid")).toBe(false);
    expect(screen.queryByRole("link", { name: "Sign in" })).toBeNull();
  });

  it.each([AuthStatus.AUTHENTICATED, AuthStatus.UNAUTHENTICATED, AuthStatus.UNAVAILABLE])(
    "renders no placeholder once the session is %s",
    (current) => {
      status = current;
      const { container } = render(<Navbar goToBackoffice={vi.fn()} />);

      expect(container.querySelector(".navbar__menu span.navbar__link--login")).toBeNull();
    },
  );
});
