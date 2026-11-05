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
      if (token === "BackOfficeChangeUserStatus") return { run: changeRun };
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

import { UserStatusControl } from "@/app/backoffice/users/_components/UserStatusControl";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { User } from "@/context/backoffice/user/domain/User";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { Identity } from "@/context/shared/access/domain/Identity";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const TARGET_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c";

const ADMIN: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  email: "admin@erpify.test",
  status: UserStatus.ACTIVE,
  roles: [Role.ADMIN],
  permissions: [Permission.USERS_READ, Permission.USERS_CHANGE_STATUS],
};

const VIEWER: Identity = { ...ADMIN, roles: [Role.VIEWER], permissions: [Permission.USERS_READ] };

function user(status: UserStatus = UserStatus.ACTIVE): User {
  return User.fromPrimitives({
    id: TARGET_ID,
    email: "mallory@erpify.test",
    status,
    roles: [Role.VIEWER],
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-02T00:00:00+00:00",
  });
}

function problem(): ProblemDetails {
  return {
    type: "last-active-administrator-protected",
    title: "Cannot suspend or deactivate the last active administrator.",
    status: 409,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b60",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b61",
  };
}

const PASSWORD = "callerPassword1";

function wrongPassword(): HttpError {
  return new HttpError({
    type: "invalid-current-password",
    title: "The current password is incorrect.",
    status: HttpStatus.FORBIDDEN,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b62",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b63",
  });
}

function budgetSpent(): HttpError {
  return new HttpError({
    type: "too-many-requests",
    title: "Too many attempts.",
    status: HttpStatus.TOO_MANY_REQUESTS,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b64",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b65",
  });
}

/** The button only opens the dialog; the password typed there is what lets the write leave. */
async function openAndConfirm(password: string = PASSWORD): Promise<void> {
  fireEvent.click(await screen.findByTestId("user-status__save"));
  fireEvent.change(await screen.findByTestId("user-status__password"), {
    target: { value: password },
  });
  fireEvent.click(screen.getByTestId("user-status__confirm"));
}

function renderControl(target: User, onChanged: (u: User) => void = vi.fn()) {
  return render(
    <AuthProvider>
      <UserStatusControl user={target} onChanged={onChanged} />
    </AuthProvider>,
  );
}

describe("UserStatusControl", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    me.mockResolvedValue(ADMIN);
  });

  it("offers no transition on the operator's own identity, whatever case the id is spelled in", async () => {
    // The API refuses a self-targeted transition; a select that can only fail is not offered.
    me.mockResolvedValue({ ...ADMIN, id: TARGET_ID.toUpperCase() });
    renderControl(user(UserStatus.ACTIVE));

    expect(await screen.findByTestId("user-status__self")).toHaveTextContent(
      "You cannot suspend or deactivate your own account.",
    );
    expect(screen.queryByTestId("user-status__save")).not.toBeInTheDocument();
    expect(screen.queryByTestId("user-status__select")).not.toBeInTheDocument();
  });

  it("suspends an active user and hands the transitioned user back", async () => {
    const onChanged = vi.fn();
    changeRun.mockResolvedValueOnce(user(UserStatus.SUSPENDED));
    renderControl(user(UserStatus.ACTIVE), onChanged);

    await openAndConfirm();

    await waitFor(() =>
      expect(changeRun).toHaveBeenCalledWith(TARGET_ID, UserStatus.SUSPENDED, PASSWORD),
    );
    expect(onChanged).toHaveBeenCalledWith(
      expect.objectContaining({ status: UserStatus.SUSPENDED }),
    );
  });

  it("targets DEACTIVATED when picked from the select", async () => {
    changeRun.mockResolvedValueOnce(user(UserStatus.DEACTIVATED));
    renderControl(user(UserStatus.ACTIVE));

    fireEvent.change(await screen.findByTestId("user-status__select"), {
      target: { value: UserStatus.DEACTIVATED },
    });
    await openAndConfirm();

    await waitFor(() =>
      expect(changeRun).toHaveBeenCalledWith(TARGET_ID, UserStatus.DEACTIVATED, PASSWORD),
    );
  });

  it("surfaces a server problem in the persistent error surface and does not report success", async () => {
    const onChanged = vi.fn();
    changeRun.mockRejectedValueOnce(new HttpError(problem()));
    renderControl(user(UserStatus.ACTIVE), onChanged);

    await openAndConfirm();

    expect(await screen.findByTestId("user-status__error")).toBeInTheDocument();
    expect(onChanged).not.toHaveBeenCalled();
    // The failure belongs to the persistent surface, so the dialog closes behind it.
    expect(screen.queryByTestId("user-status__dialog")).not.toBeInTheDocument();
  });

  it("asks for the operator's password before any request leaves, and Cancel sends nothing", async () => {
    renderControl(user(UserStatus.ACTIVE));

    fireEvent.click(await screen.findByTestId("user-status__save"));

    expect(await screen.findByTestId("user-status__dialog")).toBeInTheDocument();
    expect(changeRun).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("user-status__cancel"));
    await waitFor(() =>
      expect(screen.queryByTestId("user-status__dialog")).not.toBeInTheDocument(),
    );
    expect(changeRun).not.toHaveBeenCalled();
  });

  it("never sends a blank password", async () => {
    renderControl(user(UserStatus.ACTIVE));

    fireEvent.click(await screen.findByTestId("user-status__save"));
    fireEvent.click(await screen.findByTestId("user-status__confirm"));

    expect(await screen.findByText("Enter your current password.")).toBeInTheDocument();
    expect(changeRun).not.toHaveBeenCalled();
  });

  it("answers a wrong password on the field, keeps the dialog open and lets the next try through", async () => {
    const onChanged = vi.fn();
    changeRun
      .mockRejectedValueOnce(wrongPassword())
      .mockResolvedValueOnce(user(UserStatus.SUSPENDED));
    renderControl(user(UserStatus.ACTIVE), onChanged);

    await openAndConfirm("wrongPassword1");

    expect(await screen.findByText("The current password is incorrect.")).toBeInTheDocument();
    // Not a denial: no persistent "you may not do this" surface, and the dialog is still there.
    expect(screen.queryByTestId("user-status__error")).not.toBeInTheDocument();
    expect(screen.getByTestId("user-status__dialog")).toBeInTheDocument();
    expect(onChanged).not.toHaveBeenCalled();

    fireEvent.change(screen.getByTestId("user-status__password"), {
      target: { value: PASSWORD },
    });
    fireEvent.click(screen.getByTestId("user-status__confirm"));

    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    expect(changeRun).toHaveBeenNthCalledWith(2, TARGET_ID, UserStatus.SUSPENDED, PASSWORD);
  });

  it("closes the dialog on a spent proof budget and shows it where the other failures go", async () => {
    changeRun.mockRejectedValueOnce(budgetSpent());
    renderControl(user(UserStatus.ACTIVE));

    await openAndConfirm();

    expect(await screen.findByTestId("user-status__error")).toBeInTheDocument();
    expect(screen.queryByTestId("user-status__dialog")).not.toBeInTheDocument();
  });

  it("masks the password and declares it a current password", async () => {
    renderControl(user(UserStatus.ACTIVE));

    fireEvent.click(await screen.findByTestId("user-status__save"));
    const field = await screen.findByTestId("user-status__password");

    expect(field).toHaveAttribute("type", "password");
    expect(field).toHaveAttribute("autocomplete", "current-password");
    expect(globalThis.location.href).not.toContain("password");
  });

  it("renders nothing for a non-active user — the lifecycle is unidirectional (no reinstate)", async () => {
    renderControl(user(UserStatus.SUSPENDED));

    await waitFor(() => expect(me).toHaveBeenCalled());
    expect(screen.queryByTestId("user-status")).not.toBeInTheDocument();
  });

  it("hides the control when the session lacks users.changeStatus", async () => {
    me.mockResolvedValue(VIEWER);
    renderControl(user(UserStatus.ACTIVE));

    await waitFor(() => expect(me).toHaveBeenCalled());
    expect(screen.queryByTestId("user-status")).not.toBeInTheDocument();
  });
});
