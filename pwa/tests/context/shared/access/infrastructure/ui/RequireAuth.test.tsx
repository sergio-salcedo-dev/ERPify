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

// One router object, so the guard's effect does not re-run on a new identity every render.
vi.mock("next/navigation", () => {
  const router = { push: vi.fn(), replace: routerReplace, refresh: vi.fn(), back: vi.fn() };
  return { useRouter: () => router };
});

vi.mock("@/context/shared/navigation/application/useDeparture", () => ({
  useDeparture: () => departure.reason,
}));

import { RequireAuth } from "@/context/shared/access/infrastructure/ui/RequireAuth";
import { AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Routes } from "@/context/shared/routing/domain/Routes";

function renderGuarded() {
  return render(
    <RequireAuth>
      <p data-testid="require-auth-test__protected">protected</p>
    </RequireAuth>,
  );
}

beforeEach(() => {
  routerReplace.mockReset();
  auth.status = AuthStatus.HYDRATING;
  departure.reason = null;
  globalThis.history.replaceState(null, "", "/backoffice/users?page=2");
});

describe("RequireAuth", () => {
  it("renders nothing and redirects nowhere while the provider is hydrating", () => {
    renderGuarded();

    expect(screen.queryByTestId("require-auth-test__protected")).toBeNull();
    expect(routerReplace).not.toHaveBeenCalled();
  });

  it("renders the children for an authenticated session", () => {
    auth.status = AuthStatus.AUTHENTICATED;

    renderGuarded();

    expect(screen.getByTestId("require-auth-test__protected")).toBeInTheDocument();
    expect(routerReplace).not.toHaveBeenCalled();
  });

  it("sends an unauthenticated visitor to /login, keeping the blocked target in ?next=", () => {
    auth.status = AuthStatus.UNAUTHENTICATED;

    renderGuarded();

    expect(screen.queryByTestId("require-auth-test__protected")).toBeNull();
    expect(routerReplace).toHaveBeenCalledTimes(1);
    expect(routerReplace).toHaveBeenCalledWith(
      `${Routes.LOGIN}?next=${encodeURIComponent("/backoffice/users?page=2")}`,
    );
  });

  // The sign-in form answers the same outage, so bouncing there would only show a second failure.
  it("sends the user to /maintenance when the server cannot answer, never to /login", () => {
    auth.status = AuthStatus.UNAVAILABLE;

    renderGuarded();

    expect(screen.queryByTestId("require-auth-test__protected")).toBeNull();
    expect(routerReplace).toHaveBeenCalledTimes(1);
    expect(routerReplace).toHaveBeenCalledWith(
      `${Routes.MAINTENANCE}?next=${encodeURIComponent("/backoffice/users?page=2")}`,
    );
  });

  // The guard writes the live location, so a tampered one must not survive into either redirect.
  it.each([AuthStatus.UNAUTHENTICATED, AuthStatus.UNAVAILABLE])(
    "falls back to the back-office root when the live location is not an in-app path (%s)",
    (status) => {
      auth.status = status;
      // Same-origin URL whose PATH reads as protocol-relative once taken on its own.
      globalThis.history.replaceState(null, "", `${globalThis.location.origin}//evil.com/x`);

      renderGuarded();

      const destination = status === AuthStatus.UNAVAILABLE ? Routes.MAINTENANCE : Routes.LOGIN;
      expect(routerReplace).toHaveBeenCalledWith(
        `${destination}?next=${encodeURIComponent(Routes.BACKOFFICE)}`,
      );
    },
  );

  it("does not redirect on top of a full-document departure already in flight", () => {
    auth.status = AuthStatus.UNAVAILABLE;
    departure.reason = "session-expired";

    renderGuarded();

    expect(routerReplace).not.toHaveBeenCalled();
  });
});
