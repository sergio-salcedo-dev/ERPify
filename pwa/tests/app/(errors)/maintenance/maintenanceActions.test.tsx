import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";

const { routerReplace, auth, departure } = vi.hoisted(() => ({
  routerReplace: vi.fn(),
  // Literals rather than `AuthStatus.*`: a mock factory is hoisted above the imports, so it
  // cannot read a value imported here.
  auth: { status: "hydrating" as string },
  departure: { reason: null as string | null },
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

// One router object, so the page's effect does not re-run on a new identity every render.
vi.mock("next/navigation", () => {
  const router = { push: vi.fn(), replace: routerReplace, refresh: vi.fn(), back: vi.fn() };
  return { useRouter: () => router, usePathname: () => "/maintenance" };
});

vi.mock("@/context/shared/navigation/application/useDeparture", () => ({
  useDeparture: () => departure.reason,
}));

import {
  MaintenanceActions,
  maintenanceWayBack,
} from "@/app/(errors)/maintenance/_components/MaintenanceActions";
import { AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Routes } from "@/context/shared/routing/domain/Routes";

const BLOCKED = "/backoffice/users?page=2";

function visitWithNext(next: string | null): void {
  const query = next === null ? "" : `?next=${encodeURIComponent(next)}`;
  globalThis.history.replaceState(null, "", `${Routes.MAINTENANCE}${query}`);
}

function loginWithNext(target: string): string {
  return `${Routes.LOGIN}?next=${encodeURIComponent(target)}`;
}

beforeEach(() => {
  routerReplace.mockReset();
  auth.status = AuthStatus.UNAVAILABLE;
  departure.reason = null;
  visitWithNext(BLOCKED);
});

describe("maintenanceWayBack", () => {
  it("returns to the blocked page once a session is confirmed", () => {
    expect(maintenanceWayBack(AuthStatus.AUTHENTICATED, BLOCKED)).toBe(BLOCKED);
  });

  it("goes through the sign-in form, carrying the target, when there is no live session", () => {
    expect(maintenanceWayBack(AuthStatus.UNAUTHENTICATED, BLOCKED)).toBe(loginWithNext(BLOCKED));
  });

  it.each([AuthStatus.HYDRATING, AuthStatus.UNAVAILABLE])(
    "waits while the status is %s",
    (status) => {
      expect(maintenanceWayBack(status, BLOCKED)).toBeNull();
    },
  );

  it.each([null, ""])("does nothing for a visit the guard did not send (next=%j)", (next) => {
    expect(maintenanceWayBack(AuthStatus.AUTHENTICATED, next)).toBeNull();
    expect(maintenanceWayBack(AuthStatus.UNAUTHENTICATED, next)).toBeNull();
  });

  it.each([
    "//evil.com",
    "//evil.com/backoffice",
    "/\\evil.com",
    "/\t/evil.com",
    "https://evil.com",
    "javascript:alert(1)",
    "JaVaScRiPt:alert(1)",
    "data:text/html,<script>alert(1)</script>",
    "backoffice",
  ])("refuses a target that is not an in-app path (%j)", (next) => {
    expect(maintenanceWayBack(AuthStatus.AUTHENTICATED, next)).toBe(Routes.BACKOFFICE);
    expect(maintenanceWayBack(AuthStatus.UNAUTHENTICATED, next)).toBe(
      loginWithNext(Routes.BACKOFFICE),
    );
  });
});

describe("MaintenanceActions", () => {
  it("offers only the standard actions and moves nobody while the server cannot answer", () => {
    render(<MaintenanceActions />);

    expect(screen.queryByTestId("maintenance__way-back-link")).toBeNull();
    expect(screen.getByTestId("error-actions__home-link")).toBeInTheDocument();
    expect(routerReplace).not.toHaveBeenCalled();
  });

  it("returns a signed-in visitor to the blocked page once the server answers again", () => {
    const { rerender } = render(<MaintenanceActions />);
    expect(routerReplace).not.toHaveBeenCalled();

    auth.status = AuthStatus.AUTHENTICATED;
    rerender(<MaintenanceActions />);

    expect(routerReplace).toHaveBeenCalledTimes(1);
    expect(routerReplace).toHaveBeenCalledWith(BLOCKED);
    const link = screen.getByTestId("maintenance__way-back-link");
    expect(link).toHaveAttribute("href", BLOCKED);
    expect(link).toHaveTextContent("Continue");
  });

  it("sends a visitor with no live session to the sign-in form, keeping the target", () => {
    auth.status = AuthStatus.UNAUTHENTICATED;

    render(<MaintenanceActions />);

    expect(routerReplace).toHaveBeenCalledWith(loginWithNext(BLOCKED));
    const link = screen.getByTestId("maintenance__way-back-link");
    expect(link).toHaveAttribute("href", loginWithNext(BLOCKED));
    expect(link).toHaveTextContent("Sign in");
  });

  it.each(["//evil.com", "javascript:alert(1)"])(
    "never navigates off-origin on a tampered next (%j)",
    (next) => {
      visitWithNext(next);
      auth.status = AuthStatus.AUTHENTICATED;

      render(<MaintenanceActions />);

      expect(routerReplace).toHaveBeenCalledWith(Routes.BACKOFFICE);
      expect(screen.getByTestId("maintenance__way-back-link")).toHaveAttribute(
        "href",
        Routes.BACKOFFICE,
      );
    },
  );

  // A direct visit, or the error gallery's preview, carries no `next`: nobody is waiting to go back.
  it.each([AuthStatus.AUTHENTICATED, AuthStatus.UNAUTHENTICATED])(
    "leaves a visit without next where it is (%s)",
    (status) => {
      visitWithNext(null);
      auth.status = status;

      render(<MaintenanceActions />);

      expect(routerReplace).not.toHaveBeenCalled();
      expect(screen.queryByTestId("maintenance__way-back-link")).toBeNull();
      expect(screen.getByTestId("error-actions__home-link")).toBeInTheDocument();
    },
  );

  it("does not navigate on top of a full-document departure already in flight", () => {
    auth.status = AuthStatus.AUTHENTICATED;
    departure.reason = "session-expired";

    render(<MaintenanceActions />);

    expect(routerReplace).not.toHaveBeenCalled();
    expect(screen.getByTestId("maintenance__way-back-link")).toHaveAttribute("href", BLOCKED);
  });
});
