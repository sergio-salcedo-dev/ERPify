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
  toastNotifier: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    dismissAll: vi.fn(),
  },
}));

import { UserRolesControl } from "@/app/backoffice/users/_components/UserRolesControl";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { useSession } from "@/context/shared/access/application/useSession";
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
  permissions: [Permission.USERS_READ, Permission.USERS_CHANGE_ROLES, Permission.USERS_GRANT_ADMIN],
};

// May re-grant roles but may not mint another administrator — the discriminating row of the delegation gate.
const DELEGATE: Identity = {
  ...ADMIN,
  email: "delegate@erpify.test",
  permissions: [Permission.USERS_READ, Permission.USERS_CHANGE_ROLES],
};

const VIEWER: Identity = { ...ADMIN, roles: [Role.VIEWER], permissions: [Permission.USERS_READ] };

const NO_SESSION = "none";

/**
 * Reports the hydrated identity. An absent ADMIN checkbox alone cannot tell "authenticated without
 * `users.grantAdmin`" apart from "session never resolved" — both hide it — so the probe pins which one the
 * assertion is observing.
 */
function SessionProbe() {
  const { session } = useSession();
  return <span data-testid="probe">{session?.user.email ?? NO_SESSION}</span>;
}

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
      <SessionProbe />
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

const ADMIN_CHECKBOX = `user-roles__role-${Role.ADMIN}`;

/**
 * Truth table of the ADMIN checkbox on the detail editor. Delegating the administrator role is a capability of
 * its own on top of editing roles at all, so the row that matters is an actor holding `users.changeRoles`
 * without `users.grantAdmin` — every other role stays offered there, which separates "the gate closed" from
 * "the roles failed to render".
 *
 * The exception is what keeps the gate from becoming a demotion: a target who already holds ADMIN keeps the
 * checkbox even for an actor who could not grant it. The form submits the COMPLETE set, so hiding a checked
 * box would silently strip a role on the next save — a privilege the actor cannot restore, taken by an edit
 * they did not make.
 */
describe("UserRolesControl ADMIN delegation gate", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("offers the ADMIN checkbox to a session holding users.grantAdmin", async () => {
    me.mockResolvedValue(ADMIN);
    renderControl(user([Role.VIEWER]));

    expect(await screen.findByText(ADMIN.email)).toBeInTheDocument();
    expect(screen.getByTestId(ADMIN_CHECKBOX)).toBeInTheDocument();
  });

  it("hides it from an actor without the capability, keeping every other role editable", async () => {
    me.mockResolvedValue(DELEGATE);
    renderControl(user([Role.VIEWER]));

    expect(await screen.findByText(DELEGATE.email)).toBeInTheDocument();
    expect(screen.queryByTestId(ADMIN_CHECKBOX)).not.toBeInTheDocument();
    for (const role of [Role.VIEWER, Role.EDITOR, Role.MANAGER, Role.AUDIT_READER]) {
      expect(screen.getByTestId(`user-roles__role-${role}`)).toBeInTheDocument();
    }
  });

  it("keeps it for an actor without the capability when the target already holds ADMIN", async () => {
    me.mockResolvedValue(DELEGATE);
    renderControl(user([Role.ADMIN, Role.VIEWER]));

    expect(await screen.findByText(DELEGATE.email)).toBeInTheDocument();
    expect(screen.getByTestId(ADMIN_CHECKBOX)).toBeChecked();
  });

  it("carries the held ADMIN through an unrelated edit by an actor who could not grant it", async () => {
    me.mockResolvedValue(DELEGATE);
    changeRun.mockResolvedValueOnce(user([Role.ADMIN, Role.MANAGER]));
    renderControl(user([Role.ADMIN]));

    fireEvent.click(await screen.findByTestId(`user-roles__role-${Role.MANAGER}`));
    fireEvent.submit(screen.getByTestId("user-roles"));

    // The complete set travels, ADMIN included: editing the rest never strips what the actor cannot re-grant.
    // Asserted as a set — the wire contract is which roles are granted, not the order the checkboxes emit.
    await waitFor(() => expect(changeRun).toHaveBeenCalled());
    const [targetId, submittedRoles] = changeRun.mock.calls[0] as [string, Role[]];
    expect(targetId).toBe(TARGET_ID);
    expect([...submittedRoles].sort()).toEqual([Role.ADMIN, Role.MANAGER].sort());
  });
});
