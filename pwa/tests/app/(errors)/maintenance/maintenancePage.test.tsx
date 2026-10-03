import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";

/**
 * The maintenance ROUTE, rendered whole. `maintenanceActions.test.tsx` pins the action row in
 * isolation, which stays green if the page stops mounting it — the page rendering the standard
 * `<ErrorActions />` instead would strand every visitor the guard parked here. This file is what
 * notices that.
 */

const { routerReplace, auth } = vi.hoisted(() => ({
  routerReplace: vi.fn(),
  // Literals rather than `AuthStatus.*`: a mock factory is hoisted above the imports, so it
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

// One router object, so the page's effect does not re-run on a new identity every render.
vi.mock("next/navigation", () => {
  const router = { push: vi.fn(), replace: routerReplace, refresh: vi.fn(), back: vi.fn() };
  return { useRouter: () => router, usePathname: () => "/maintenance" };
});

import MaintenancePage from "@/app/(errors)/maintenance/page";
import { AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Routes } from "@/context/shared/routing/domain/Routes";

const LOGIN_WITH_NEXT = `${Routes.LOGIN}?next=${encodeURIComponent(Routes.BACKOFFICE)}`;

beforeEach(() => {
  routerReplace.mockReset();
  auth.status = AuthStatus.UNAVAILABLE;
  globalThis.history.replaceState(
    null,
    "",
    `${Routes.MAINTENANCE}?next=${encodeURIComponent(Routes.BACKOFFICE)}`,
  );
});

describe("MaintenancePage", () => {
  it("holds a parked visitor on the page while the server still cannot answer", () => {
    render(<MaintenancePage />);

    expect(screen.getByTestId("maintenance__title")).toHaveTextContent("Scheduled maintenance");
    expect(screen.queryByTestId("maintenance__way-back-link")).toBeNull();
    expect(routerReplace).not.toHaveBeenCalled();
  });

  it("sends a visitor with no live session to the sign-in form, carrying next", () => {
    auth.status = AuthStatus.UNAUTHENTICATED;

    render(<MaintenancePage />);

    expect(routerReplace).toHaveBeenCalledWith(LOGIN_WITH_NEXT);
    expect(screen.getByTestId("maintenance__way-back-link")).toHaveAttribute(
      "href",
      LOGIN_WITH_NEXT,
    );
  });

  it("returns a signed-in visitor to the blocked page", () => {
    auth.status = AuthStatus.AUTHENTICATED;

    render(<MaintenancePage />);

    expect(routerReplace).toHaveBeenCalledWith(Routes.BACKOFFICE);
    expect(screen.getByTestId("maintenance__way-back-link")).toHaveAttribute(
      "href",
      Routes.BACKOFFICE,
    );
  });
});
