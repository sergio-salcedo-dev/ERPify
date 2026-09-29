import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";

const { replace, auth } = vi.hoisted(() => ({
  replace: vi.fn(),
  // Literal rather than `AuthStatus.*`: a mock factory is hoisted above the imports.
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

// Built once so the guard's effect does not re-run on every render for a new router identity.
vi.mock("next/navigation", () => {
  const router = { push: vi.fn(), replace, refresh: vi.fn(), back: vi.fn(), prefetch: vi.fn() };
  return { useRouter: () => router };
});

import { RequireAuth } from "@/context/shared/access/infrastructure/ui/RequireAuth";
import { AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Routes } from "@/context/shared/routing/domain/Routes";
import {
  claimDeparture,
  releaseDeparture,
  DepartureReason,
} from "@/context/shared/navigation/application/departure";

function guarded() {
  return (
    <RequireAuth>
      <p data-testid="require-auth-test__content">protected</p>
    </RequireAuth>
  );
}

beforeEach(() => {
  auth.status = AuthStatus.HYDRATING;
  replace.mockReset();
  globalThis.history.replaceState(null, "", "/backoffice/users?page=2");
});

afterEach(() => {
  releaseDeparture();
});

describe("RequireAuth", () => {
  it("replaces the route with /maintenance when the server cannot decide, never /login", () => {
    auth.status = AuthStatus.UNAVAILABLE;

    render(guarded());

    expect(replace).toHaveBeenCalledTimes(1);
    expect(replace).toHaveBeenCalledWith(Routes.MAINTENANCE);
    expect(replace).not.toHaveBeenCalledWith(expect.stringContaining(Routes.LOGIN));
    expect(screen.queryByTestId("require-auth-test__content")).toBeNull();
  });

  it("sends an unauthenticated visitor to /login preserving the blocked target", () => {
    auth.status = AuthStatus.UNAUTHENTICATED;

    render(guarded());

    expect(replace).toHaveBeenCalledWith(
      `${Routes.LOGIN}?next=${encodeURIComponent("/backoffice/users?page=2")}`,
    );
  });

  it("neither redirects nor renders while hydrating", () => {
    render(guarded());

    expect(replace).not.toHaveBeenCalled();
    expect(screen.queryByTestId("require-auth-test__content")).toBeNull();
  });

  it("holds the maintenance redirect while a departure is claimed, and issues it once released", () => {
    auth.status = AuthStatus.UNAVAILABLE;
    expect(claimDeparture(DepartureReason.SESSION_EXPIRED)).toBe(true);

    render(guarded());

    expect(replace).not.toHaveBeenCalled();

    act(() => releaseDeparture());

    expect(replace).toHaveBeenCalledTimes(1);
    expect(replace).toHaveBeenCalledWith(Routes.MAINTENANCE);
  });

  it("redirects to /maintenance when a mounted, authenticated guard turns unavailable", () => {
    auth.status = AuthStatus.AUTHENTICATED;
    const view = render(guarded());
    expect(screen.getByTestId("require-auth-test__content")).toBeTruthy();
    expect(replace).not.toHaveBeenCalled();

    auth.status = AuthStatus.UNAVAILABLE;
    view.rerender(guarded());

    expect(replace).toHaveBeenCalledWith(Routes.MAINTENANCE);
    expect(screen.queryByTestId("require-auth-test__content")).toBeNull();
  });
});
