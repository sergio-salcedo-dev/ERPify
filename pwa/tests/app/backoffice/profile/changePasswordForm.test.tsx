import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type {
  ProblemDetails,
  ProblemViolation,
} from "@/context/shared/error/domain/ProblemDetails";

// The form resolves the IdentityRepository port from the DI container; mock that
// boundary so it drives a controllable fake port (no network).
const changePassword = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: () => ({ changePassword: (...args: unknown[]) => changePassword(...args) }),
  },
}));

import { ChangePasswordForm } from "@/app/backoffice/profile/_components/ChangePasswordForm";

function problem(
  status: number,
  type: string,
  extra: Partial<ProblemDetails> = {},
): ProblemDetails {
  return {
    type,
    title: type,
    status,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
    ...extra,
  };
}

function violations(...entries: ProblemViolation[]): ProblemViolation[] {
  return entries;
}

function fillAndSubmit(current = "old-password", next = "new-password-1"): void {
  fireEvent.change(screen.getByTestId("change-password-form__current-password"), {
    target: { value: current },
  });
  fireEvent.change(screen.getByTestId("change-password-form__new-password"), {
    target: { value: next },
  });
  fireEvent.click(screen.getByTestId("change-password-form__submit"));
}

beforeEach(() => {
  changePassword.mockReset();
});

describe("<ChangePasswordForm>", () => {
  it("posts both passwords and swaps the form for the success signal", async () => {
    changePassword.mockResolvedValue(undefined);

    render(<ChangePasswordForm />);
    fillAndSubmit();

    await waitFor(() =>
      expect(changePassword).toHaveBeenCalledWith({
        currentPassword: "old-password",
        newPassword: "new-password-1",
      }),
    );
    expect(await screen.findByTestId("change-password-form__success")).toBeInTheDocument();
    expect(screen.queryByTestId("change-password-form")).not.toBeInTheDocument();
  });

  it("rejects a too-short new password before reaching the port", async () => {
    render(<ChangePasswordForm />);
    fillAndSubmit("old-password", "short");

    expect(
      await screen.findByText("The password must be at least 8 characters."),
    ).toBeInTheDocument();
    expect(changePassword).not.toHaveBeenCalled();
  });

  it("requires the current password before reaching the port", async () => {
    render(<ChangePasswordForm />);
    fillAndSubmit("", "new-password-1");

    expect(await screen.findByText("Enter your current password.")).toBeInTheDocument();
    expect(changePassword).not.toHaveBeenCalled();
  });

  // A 403 keeps the session alive: it must land on the field the user mistyped, never in
  // the banner and never as a sign-out.
  it("hangs a 403 invalid-current-password on the current-password field", async () => {
    changePassword.mockRejectedValue(
      new HttpError(
        problem(HttpStatus.FORBIDDEN, "invalid-current-password", {
          detail: "The current password is not correct.",
        }),
      ),
    );

    render(<ChangePasswordForm />);
    fillAndSubmit();

    expect(await screen.findByText("The current password is not correct.")).toBeInTheDocument();
    expect(screen.queryByTestId("change-password-form__mutation-error")).not.toBeInTheDocument();
    expect(screen.queryByTestId("change-password-form__success")).not.toBeInTheDocument();
  });

  it("hangs a 422 new-password-must-differ on the new-password field", async () => {
    changePassword.mockRejectedValue(
      new HttpError(
        problem(HttpStatus.UNPROCESSABLE_ENTITY, "new-password-must-differ", {
          detail: "The new password must differ from the current one.",
        }),
      ),
    );

    render(<ChangePasswordForm />);
    fillAndSubmit();

    expect(
      await screen.findByText("The new password must differ from the current one."),
    ).toBeInTheDocument();
    expect(screen.queryByTestId("change-password-form__mutation-error")).not.toBeInTheDocument();
  });

  it("maps 422 violations onto the fields they name", async () => {
    changePassword.mockRejectedValue(
      new HttpError(
        problem(HttpStatus.UNPROCESSABLE_ENTITY, "validation-failed", {
          violations: violations({
            field: "newPassword",
            message: "This value is too long.",
          }),
        }),
      ),
    );

    render(<ChangePasswordForm />);
    fillAndSubmit();

    expect(await screen.findByText("This value is too long.")).toBeInTheDocument();
    expect(screen.queryByTestId("change-password-form__mutation-error")).not.toBeInTheDocument();
  });

  it("falls back to the persistent banner for a violation naming an unknown field", async () => {
    changePassword.mockRejectedValue(
      new HttpError(
        problem(HttpStatus.UNPROCESSABLE_ENTITY, "validation-failed", {
          violations: violations({ field: "roles", message: "Unexpected member." }),
        }),
      ),
    );

    render(<ChangePasswordForm />);
    fillAndSubmit();

    expect(await screen.findByTestId("change-password-form__mutation-error")).toBeInTheDocument();
  });

  /**
   * An account-level refusal is nobody's field. It reaches the persistent banner through the generic path,
   * which renders `detail ?? title` — so the API answering `account-suspended` rather than the aggregate's
   * `invalid-identity-transition` is the whole fix, and teaching the form the two types would add branches
   * that do exactly what this already does.
   */
  it("surfaces an account-level refusal in the persistent banner, in the account's own words", async () => {
    changePassword.mockRejectedValue(
      new HttpError(
        problem(HttpStatus.FORBIDDEN, "account-suspended", {
          detail: "Your account has been suspended. Contact an administrator to restore access.",
        }),
      ),
    );

    render(<ChangePasswordForm />);
    fillAndSubmit();

    expect(await screen.findByTestId("change-password-form__mutation-error")).toBeInTheDocument();
    expect(
      screen.getByText(
        "Your account has been suspended. Contact an administrator to restore access.",
      ),
    ).toBeInTheDocument();
    expect(screen.getByTestId("change-password-form")).toBeInTheDocument();
    expect(screen.queryByTestId("change-password-form__success")).not.toBeInTheDocument();
  });

  it("surfaces any other failure in the persistent banner, keeping the form mounted", async () => {
    changePassword.mockRejectedValue(
      new HttpError(problem(HttpStatus.INTERNAL_SERVER_ERROR, "server-error")),
    );

    render(<ChangePasswordForm />);
    fillAndSubmit();

    expect(await screen.findByTestId("change-password-form__mutation-error")).toBeInTheDocument();
    expect(screen.getByTestId("change-password-form")).toBeInTheDocument();
    expect(screen.queryByTestId("change-password-form__success")).not.toBeInTheDocument();
  });
});
