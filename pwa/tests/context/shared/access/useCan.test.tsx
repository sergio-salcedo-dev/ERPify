import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";

// The provider hydrates from `/me` via the DI container; mock that boundary so
// the session is a real (de-mocked) ADMIN with no permissions granted yet.
const me = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ me: (...args: unknown[]) => me(...args) }) },
}));

import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Can } from "@/context/shared/access/infrastructure/ui/Can";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Identity } from "@/context/shared/access/domain/Identity";

const ADMIN: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  email: "admin@erpify.dev",
  status: UserStatus.ACTIVE,
  roles: [Role.ADMIN],
  permissions: [],
};

beforeEach(() => {
  me.mockReset();
  me.mockResolvedValue(ADMIN);
});

describe("<Can>", () => {
  it("renders children for a role the hydrated session holds", async () => {
    render(
      <AuthProvider>
        <Can role={Role.ADMIN}>
          <button>manage</button>
        </Can>
      </AuthProvider>,
    );

    expect(await screen.findByRole("button", { name: "manage" })).toBeInTheDocument();
  });

  it("hides permission-gated children while /me grants no permissions (empty set, no wildcard)", async () => {
    render(
      <AuthProvider>
        <Can role={Role.ADMIN}>
          <span>shell</span>
        </Can>
        <Can permission={Permission.USERS_WRITE}>
          <button>create</button>
        </Can>
      </AuthProvider>,
    );

    // Wait for hydration to settle (the role-gated shell appears)…
    await screen.findByText("shell");
    // …then confirm the permission-gated action stays hidden.
    expect(screen.queryByRole("button", { name: "create" })).not.toBeInTheDocument();
  });
});
