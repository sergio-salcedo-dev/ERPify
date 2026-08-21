import { describe, it, expect, beforeEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

const eraseRun = vi.hoisted(() => vi.fn());
const me = vi.hoisted(() => vi.fn());
const push = vi.hoisted(() => vi.fn());

// The control resolves its use case from the container; the AuthProvider hydrates the session from `/me`
// through the same container. Dispatch by token so the gate sees a real permission set while the erase path
// hits the mocked use case — never the real Inversify wiring.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeFulfilIdentityErasure") return { run: eraseRun };
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

vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

import { UserEraseControl } from "@/app/backoffice/users/_components/UserEraseControl";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { User } from "@/context/backoffice/user/domain/User";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { Identity } from "@/context/shared/access/domain/Identity";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const TARGET_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c";
const TARGET_EMAIL = "mallory@erpify.test";

const ADMIN: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  email: "admin@erpify.test",
  status: UserStatus.ACTIVE,
  roles: [Role.ADMIN],
  permissions: [Permission.USERS_READ, Permission.USERS_ERASE],
};

const VIEWER: Identity = { ...ADMIN, roles: [Role.VIEWER], permissions: [Permission.USERS_READ] };

function user(): User {
  return User.fromPrimitives({
    id: TARGET_ID,
    email: TARGET_EMAIL,
    status: UserStatus.ACTIVE,
    roles: [Role.VIEWER],
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-02T00:00:00+00:00",
  });
}

function problem(): ProblemDetails {
  return {
    type: "self-erasure-forbidden",
    title: "An administrator cannot erase their own identity.",
    status: 409,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b60",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b61",
  };
}

function renderControl() {
  return render(
    <AuthProvider>
      <UserEraseControl user={user()} />
    </AuthProvider>,
  );
}

describe("UserEraseControl", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    me.mockResolvedValue(ADMIN);
  });

  it("shows the erase control for an admin holding users.erase", async () => {
    renderControl();

    expect(await screen.findByTestId("user-erase")).toBeInTheDocument();
    expect(screen.getByTestId("user-erase__trigger")).toBeInTheDocument();
  });

  it("hides the control when the session lacks users.erase", async () => {
    me.mockResolvedValue(VIEWER);
    renderControl();

    await waitFor(() => expect(me).toHaveBeenCalled());
    expect(screen.queryByTestId("user-erase")).not.toBeInTheDocument();
  });

  it("keeps confirm disabled until the email is typed, then erases and redirects to the list", async () => {
    eraseRun.mockResolvedValueOnce(undefined);
    renderControl();

    fireEvent.click(await screen.findByTestId("user-erase__trigger"));

    const confirm = await screen.findByTestId("user-erase__confirm");
    expect(confirm).toBeDisabled();

    fireEvent.change(screen.getByTestId("user-erase__confirm-input"), {
      target: { value: "nope" },
    });
    expect(confirm).toBeDisabled();

    fireEvent.change(screen.getByTestId("user-erase__confirm-input"), {
      target: { value: TARGET_EMAIL },
    });
    expect(confirm).toBeEnabled();

    fireEvent.click(confirm);

    await waitFor(() => expect(eraseRun).toHaveBeenCalledWith(TARGET_ID));
    await waitFor(() => expect(push).toHaveBeenCalled());
  });

  it("surfaces a server problem in the persistent error surface and does not navigate", async () => {
    eraseRun.mockRejectedValueOnce(new HttpError(problem()));
    renderControl();

    fireEvent.click(await screen.findByTestId("user-erase__trigger"));
    fireEvent.change(await screen.findByTestId("user-erase__confirm-input"), {
      target: { value: TARGET_EMAIL },
    });
    fireEvent.click(screen.getByTestId("user-erase__confirm"));

    expect(await screen.findByTestId("user-erase__error")).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });

  it("dismisses the persistent error surface", async () => {
    eraseRun.mockRejectedValueOnce(new HttpError(problem()));
    renderControl();

    fireEvent.click(await screen.findByTestId("user-erase__trigger"));
    fireEvent.change(await screen.findByTestId("user-erase__confirm-input"), {
      target: { value: TARGET_EMAIL },
    });
    fireEvent.click(screen.getByTestId("user-erase__confirm"));

    fireEvent.click(await screen.findByTestId("user-erase__error__dismiss"));

    await waitFor(() => expect(screen.queryByTestId("user-erase__error")).not.toBeInTheDocument());
  });

  it("resets the typed confirmation when the dialog is cancelled", async () => {
    renderControl();

    fireEvent.click(await screen.findByTestId("user-erase__trigger"));
    fireEvent.change(await screen.findByTestId("user-erase__confirm-input"), {
      target: { value: TARGET_EMAIL },
    });
    expect(screen.getByTestId("user-erase__confirm")).toBeEnabled();

    fireEvent.click(screen.getByTestId("user-erase__cancel"));

    // Re-opening shows an empty, disabled confirm — the typed phrase was cleared on close.
    fireEvent.click(await screen.findByTestId("user-erase__trigger"));
    expect(await screen.findByTestId("user-erase__confirm")).toBeDisabled();
  });
});
