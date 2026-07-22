import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";

const search = vi.hoisted(() => vi.fn());
const follow = vi.hoisted(() => vi.fn());
const me = vi.hoisted(() => vi.fn());

// The list reads through `useResourceList`, which resolves its repository and navigator from the container;
// the `Can` gate hydrates the session from `/me` through that same container. Dispatch by token, and throw on
// an unknown one: both read call sites swallow exceptions, so a silently mis-stubbed token would degrade the
// view to an error state and let a "no request was issued" assertion pass for the wrong reason.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeUserRepository") return { search };
      if (token === "BackOfficeUserSearchNavigator") return { follow };
      if (token === "IdentityRepository") return { me: (...args: unknown[]) => me(...args) };
      if (token === "SessionsRepository") return { revokeCurrent: vi.fn() };
      throw new Error(`Unexpected container token: ${token}`);
    },
  },
}));

// `UsersTable` routes to the detail page on row activation.
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

import UsersListPage from "@/app/backoffice/users/page";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { useSession } from "@/context/shared/access/application/useSession";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { User } from "@/context/backoffice/user/domain/User";
import type { Identity } from "@/context/shared/access/domain/Identity";

const ADMIN: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  email: "admin@erpify.test",
  status: UserStatus.ACTIVE,
  roles: [Role.ADMIN],
  permissions: [Permission.USERS_READ],
};

// `users.read` is ADMIN-only, so every other role arrives with it absent.
const VIEWER: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
  email: "viewer@erpify.test",
  status: UserStatus.ACTIVE,
  roles: [Role.VIEWER],
  permissions: [],
};

// Holds the permission but is barred at the status level — the remaining row of the gate's truth table.
const SUSPENDED_ADMIN: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5e",
  email: "suspended@erpify.test",
  status: UserStatus.SUSPENDED,
  roles: [Role.ADMIN],
  permissions: [Permission.USERS_READ],
};

const LISTED_USER = new User(
  "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c",
  "member@erpify.test",
  UserStatus.ACTIVE,
  [Role.VIEWER],
  "2026-07-01T10:00:00+00:00",
  "2026-07-01T10:00:00+00:00",
);

const ROW = `users-table__row-${LISTED_USER.id}`;
const NO_SESSION = "none";

/**
 * Reports the hydrated identity. A denied render alone cannot tell "authenticated without the permission"
 * apart from "session never resolved" — both produce the same fallback — so the probe pins which one the
 * assertion is actually observing.
 */
function SessionProbe() {
  const { session } = useSession();
  return <span data-testid="probe">{session?.user.email ?? NO_SESSION}</span>;
}

function renderList() {
  render(
    <AuthProvider>
      <SessionProbe />
      <UsersListPage />
    </AuthProvider>,
  );
}

function onePage() {
  return {
    items: [LISTED_USER],
    hasNext: false,
    hasPrev: false,
    count: 1,
    links: { next: null, prev: null },
  };
}

describe("UsersListPage authorization gate", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    search.mockResolvedValue(onePage());
  });

  it("lists the users when the session carries the permission", async () => {
    me.mockResolvedValue(ADMIN);

    renderList();

    expect(await screen.findByTestId(ROW)).toBeInTheDocument();
    expect(search).toHaveBeenCalledTimes(1);
  });

  it("issues no request when the authenticated session lacks the permission", async () => {
    me.mockResolvedValue(VIEWER);

    renderList();

    expect(await screen.findByTestId("probe")).toHaveTextContent(VIEWER.email);
    expect(await screen.findByText("Access denied")).toBeInTheDocument();
    expect(search).not.toHaveBeenCalled();
  });

  it("issues no request when the session holds the permission but is not active", async () => {
    me.mockResolvedValue(SUSPENDED_ADMIN);

    renderList();

    expect(await screen.findByTestId("probe")).toHaveTextContent(SUSPENDED_ADMIN.email);
    expect(await screen.findByText("Access denied")).toBeInTheDocument();
    expect(search).not.toHaveBeenCalled();
  });

  it("waits for the session before reading, then reads exactly once", async () => {
    // The provider renders its children while `/me` is still in flight, so the gate evaluates against a null
    // session first. Authorization must settle before any effect runs — a premature read here is the 403 that
    // pollutes the audit trail. Asserted on the absence of the read and of the rows, not on the denial copy:
    // what this pins is "no effect before authorization", not "show Access denied while hydrating".
    let admit: (identity: Identity) => void = () => {};
    me.mockReturnValue(
      new Promise<Identity>((resolve) => {
        admit = resolve;
      }),
    );

    renderList();

    expect(await screen.findByTestId("probe")).toHaveTextContent(NO_SESSION);
    expect(search).not.toHaveBeenCalled();
    expect(screen.queryByTestId(ROW)).not.toBeInTheDocument();

    admit(ADMIN);

    // The row only paints after the read resolved and committed, so by this point a duplicate mount would
    // already have registered its own call.
    expect(await screen.findByTestId(ROW)).toBeInTheDocument();
    expect(search).toHaveBeenCalledTimes(1);
  });
});
