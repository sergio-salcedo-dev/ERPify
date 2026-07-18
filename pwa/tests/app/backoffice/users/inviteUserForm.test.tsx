import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { InviteUserForm } from "@/app/backoffice/users/_components/InviteUserForm";
import { Role } from "@/context/shared/access/domain/Role";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";

const mocks = vi.hoisted(() => ({
  inviteRun: vi.fn(),
  push: vi.fn(),
  refresh: vi.fn(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: mocks.push, refresh: mocks.refresh, back: vi.fn() }),
}));
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeInviteUser") return { run: mocks.inviteRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));
vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

function fillValidInvitation() {
  fireEvent.change(screen.getByTestId("invite-user-form__email"), {
    target: { value: "newbie@erpify.test" },
  });
  fireEvent.click(screen.getByTestId(`invite-user-form__role-${Role.EDITOR}`));
}

describe("InviteUserForm", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("invites through the use case, toasts success and navigates to the list", async () => {
    mocks.inviteRun.mockResolvedValue(undefined);
    render(<InviteUserForm />);

    fillValidInvitation();
    fireEvent.submit(screen.getByTestId("invite-user-form"));

    await waitFor(() => {
      expect(mocks.inviteRun).toHaveBeenCalledWith({
        email: "newbie@erpify.test",
        roles: [Role.EDITOR],
      });
    });
    expect(toastNotifier.success).toHaveBeenCalledWith("Invitation sent", {
      description: "newbie@erpify.test",
    });
    expect(mocks.push).toHaveBeenCalled();
  });

  it("maps a 422 email violation onto the email field and does not navigate", async () => {
    mocks.inviteRun.mockRejectedValue(
      new HttpError({
        type: "validation-failed",
        title: "Validation failed.",
        status: HttpStatus.UNPROCESSABLE_ENTITY,
        instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6c",
        violations: [{ field: "email", message: "This email is already in use." }],
      }),
    );
    render(<InviteUserForm />);

    fillValidInvitation();
    fireEvent.submit(screen.getByTestId("invite-user-form"));

    expect(await screen.findByText("This email is already in use.")).toBeInTheDocument();
    expect(mocks.push).not.toHaveBeenCalled();
  });

  it("disables the submit button while an invitation is in flight, blocking a second submit", async () => {
    let resolveInvite: () => void = () => {};
    mocks.inviteRun.mockReturnValue(
      new Promise<void>((resolve) => {
        resolveInvite = resolve;
      }),
    );
    render(<InviteUserForm />);

    fillValidInvitation();
    const submit = screen.getByTestId("invite-user-form__submit");
    fireEvent.submit(screen.getByTestId("invite-user-form"));

    // The disabled submit is the sole double-submit guard: while the first invitation is in flight the
    // button is disabled, so neither a second click nor Enter can fire another one.
    expect(await screen.findByTestId("invite-user-form__submit-spinner")).toBeInTheDocument();
    expect(submit).toBeDisabled();
    expect(mocks.inviteRun).toHaveBeenCalledTimes(1);

    resolveInvite();
    await waitFor(() => expect(submit).toBeEnabled());
    expect(mocks.inviteRun).toHaveBeenCalledTimes(1);
  });
});
