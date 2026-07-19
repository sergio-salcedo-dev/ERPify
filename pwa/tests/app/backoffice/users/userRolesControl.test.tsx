import { describe, it, expect, beforeEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

const changeRun = vi.hoisted(() => vi.fn());
const me = vi.hoisted(() => vi.fn());

// The control resolves its use case from the container; the AuthProvider hydrates the session from `/me`
// through the same container. Dispatch by token so the gate sees a real permission set while the save path
// hits the mocked use case — never the real Inversify wiring.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeChangeUserRoles") return { run: changeRun };
      return { me: (...args: unknown[]) => me(...args) };
    },
  },
}));

vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

import { UserRolesControl } from "@/app/backoffice/users/_components/UserRolesControl";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { User } from "@/context/backoffice/user/domain/User";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import type { Identity } from "@/context/shared/access/domain/Identity";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const TARGET_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c";

const ADMIN: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  email: "admin@erpify.test",
  status: UserStatus.ACTIVE,
  roles: [Role.ADMIN],
  permissions: [Permission.USERS_READ, Permission.USERS_CHANGE_ROLES],
};

const VIEWER: Identity = { ...ADMIN, roles: [Role.VIEWER], permissions: [Permission.USERS_READ] };

function user(roles: Role[] = [Role.VIEWER], status: UserStatus = UserStatus.ACTIVE): User {
  return User.fromPrimitives({
    id: TARGET_ID,
    email: "mallory@erpify.test",
    status,
    roles,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-02T00:00:00+00:00",
  });
}

function problem(): ProblemDetails {
  return {
    type: "last-active-administrator-protected",
    title: "Cannot demote the last active administrator.",
    status: 409,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b60",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b61",
  };
}

function renderControl(target: User, onChanged: (u: User) => void = vi.fn()) {
  return render(
    <AuthProvider>
      <UserRolesControl user={target} onChanged={onChanged} />
    </AuthProvider>,
  );
}

describe("UserRolesControl", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    me.mockResolvedValue(ADMIN);
  });

  it("pre-checks exactly the roles the identity currently holds", async () => {
    renderControl(user([Role.EDITOR, Role.AUDIT_READER]));

    expect(await screen.findByTestId(`user-roles__role-${Role.EDITOR}`)).toBeChecked();
    expect(screen.getByTestId(`user-roles__role-${Role.AUDIT_READER}`)).toBeChecked();
    expect(screen.getByTestId(`user-roles__role-${Role.VIEWER}`)).not.toBeChecked();
    expect(screen.getByTestId(`user-roles__role-${Role.MANAGER}`)).not.toBeChecked();
    expect(screen.getByTestId(`user-roles__role-${Role.ADMIN}`)).not.toBeChecked();
  });

  it("sends the complete resulting set and hands the re-granted user back", async () => {
    const onChanged = vi.fn();
    changeRun.mockResolvedValueOnce(user([Role.VIEWER, Role.MANAGER]));
    renderControl(user([Role.VIEWER]), onChanged);

    fireEvent.click(await screen.findByTestId(`user-roles__role-${Role.MANAGER}`));
    fireEvent.submit(screen.getByTestId("user-roles"));

    await waitFor(() =>
      expect(changeRun).toHaveBeenCalledWith(TARGET_ID, [Role.VIEWER, Role.MANAGER]),
    );
    expect(onChanged).toHaveBeenCalledWith(
      expect.objectContaining({ roles: [Role.VIEWER, Role.MANAGER] }),
    );
    expect(toastNotifier.success).toHaveBeenCalled();
  });

  it("carries a role it cannot render through the save instead of dropping it", async () => {
    // An API ahead of this build grants a role with no checkbox here. Submitting the whole set must not
    // strip it.
    const unknown = "LEGACY_AUDITOR" as Role;
    changeRun.mockResolvedValueOnce(user([Role.VIEWER]));
    renderControl(user([Role.VIEWER, unknown]));

    expect(screen.queryByTestId(`user-roles__role-${unknown}`)).not.toBeInTheDocument();
    fireEvent.submit(await screen.findByTestId("user-roles"));

    await waitFor(() => expect(changeRun).toHaveBeenCalledWith(TARGET_ID, [Role.VIEWER, unknown]));
  });

  it("surfaces a server problem in the persistent error surface and does not report success", async () => {
    const onChanged = vi.fn();
    changeRun.mockRejectedValueOnce(new HttpError(problem()));
    renderControl(user([Role.ADMIN]), onChanged);

    fireEvent.submit(await screen.findByTestId("user-roles"));

    expect(await screen.findByTestId("user-roles__error")).toBeInTheDocument();
    expect(onChanged).not.toHaveBeenCalled();
  });

  it("blocks an empty set client-side before any request leaves", async () => {
    renderControl(user([Role.VIEWER]));

    fireEvent.click(await screen.findByTestId(`user-roles__role-${Role.VIEWER}`));
    fireEvent.submit(screen.getByTestId("user-roles"));

    expect(await screen.findByTestId("user-roles__field-error")).toBeInTheDocument();
    expect(changeRun).not.toHaveBeenCalled();
  });

  it("renders for a non-active identity — authorization is orthogonal to the lifecycle", async () => {
    renderControl(user([Role.VIEWER], UserStatus.SUSPENDED));

    expect(await screen.findByTestId("user-roles")).toBeInTheDocument();
  });

  it("hides the control when the session lacks users.changeRoles", async () => {
    me.mockResolvedValue(VIEWER);
    renderControl(user([Role.VIEWER]));

    await waitFor(() => expect(me).toHaveBeenCalled());
    expect(screen.queryByTestId("user-roles")).not.toBeInTheDocument();
  });
});
