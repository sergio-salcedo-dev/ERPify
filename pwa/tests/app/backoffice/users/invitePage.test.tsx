import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";

// The provider hydrates the session from `/me` via the DI container; mock that boundary so the page
// gates on exactly the permissions the endpoint derived (the form is imported transitively, so the
// mock also keeps its own container lookups off the real Inversify wiring).
const me = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ me: (...args: unknown[]) => me(...args) }) },
}));

import InviteUserPage from "@/app/backoffice/users/invite/page";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Identity } from "@/context/shared/access/domain/Identity";

const VIEWER: Identity = {
  id: "0190ffff-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
  email: "viewer@erpify.dev",
  status: UserStatus.ACTIVE,
  roles: [Role.VIEWER],
  permissions: [Permission.USERS_READ],
};

describe("InviteUserPage access gate", () => {
  beforeEach(() => {
    me.mockReset();
    me.mockResolvedValue(VIEWER);
  });

  it("shows the access-denied fallback and not the form when the invite permission is absent", async () => {
    render(
      <AuthProvider>
        <InviteUserPage />
      </AuthProvider>,
    );

    expect(await screen.findByText("Access denied")).toBeInTheDocument();
    expect(screen.queryByTestId("invite-user-form")).not.toBeInTheDocument();
  });
});
