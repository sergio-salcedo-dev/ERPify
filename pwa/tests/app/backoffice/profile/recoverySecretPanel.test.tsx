import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

// The panel and its mint form both resolve the RecoverySecretRepository port from the DI
// container; mock that boundary so the surface drives a controllable fake port (no network).
const read = vi.fn();
const mint = vi.fn();
const revoke = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: () => ({
      read: (...args: unknown[]) => read(...args),
      mint: (...args: unknown[]) => mint(...args),
      revoke: (...args: unknown[]) => revoke(...args),
    }),
  },
}));

import { RecoverySecretPanel } from "@/app/backoffice/profile/_components/RecoverySecretPanel";

const PASSWORD = "correct-horse-battery";

function problem(status: number, type: string, detail: string): ProblemDetails {
  return {
    type,
    title: type,
    status,
    detail,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
  };
}

/** Opens the confirmation and returns once its password field is on screen. */
async function openRevokeDialog(): Promise<HTMLElement> {
  expect(await screen.findByTestId("recovery-secret__existing")).toBeInTheDocument();
  fireEvent.click(screen.getByTestId("recovery-secret__revoke"));
  return screen.findByTestId("recovery-secret__revoke-password");
}

function typePassword(field: HTMLElement, value: string): void {
  fireEvent.change(field, { target: { value } });
}

beforeEach(() => {
  read.mockReset();
  mint.mockReset();
  revoke.mockReset();
  read.mockResolvedValue({
    exists: true,
    mintedAt: "2026-08-01T09:00:00.000Z",
    expiresAt: "2027-08-01T09:00:00.000Z",
  });
  revoke.mockResolvedValue(undefined);
});

describe("<RecoverySecretPanel> — revoking asks for the current password", () => {
  it("labels the password field, so it is reachable by its name and not only by its test id", async () => {
    render(<RecoverySecretPanel />);
    await openRevokeDialog();

    expect(screen.getByLabelText(/current password/i)).toBe(
      screen.getByTestId("recovery-secret__revoke-password"),
    );
  });

  it("hands the typed password to the port and closes the confirmation", async () => {
    render(<RecoverySecretPanel />);
    typePassword(await openRevokeDialog(), PASSWORD);

    fireEvent.click(screen.getByTestId("recovery-secret__revoke-confirm"));

    await waitFor(() => expect(revoke).toHaveBeenCalledWith(PASSWORD));
    await waitFor(() =>
      expect(screen.queryByTestId("recovery-secret__revoke-dialog")).not.toBeInTheDocument(),
    );
  });

  it("refuses to reach the port with no password, and keeps the confirmation open to say so", async () => {
    render(<RecoverySecretPanel />);
    await openRevokeDialog();

    fireEvent.click(screen.getByTestId("recovery-secret__revoke-confirm"));

    expect(await screen.findByText("Enter your current password.")).toBeInTheDocument();
    expect(revoke).not.toHaveBeenCalled();
    // A refusal this surface made itself belongs on the field, so the dialog stays put rather
    // than dumping the user back on the panel with a banner about their own empty input.
    expect(screen.getByTestId("recovery-secret__revoke-dialog")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-secret__revoke-error")).not.toBeInTheDocument();
  });

  it("surfaces a rejected password on the panel's persistent banner, secret intact", async () => {
    revoke.mockRejectedValue(
      new HttpError(
        problem(
          HttpStatus.FORBIDDEN,
          "invalid-current-password",
          "The current password is not correct.",
        ),
      ),
    );

    render(<RecoverySecretPanel />);
    typePassword(await openRevokeDialog(), "wrong-password");
    fireEvent.click(screen.getByTestId("recovery-secret__revoke-confirm"));

    const banner = await screen.findByTestId("recovery-secret__revoke-error");
    expect(banner).toHaveTextContent("The current password is not correct.");
    expect(screen.queryByTestId("recovery-secret__revoke-dialog")).not.toBeInTheDocument();
    // The secret survives a refused revoke: the panel must still offer the revoke rather than
    // flipping to the mint form as though the credential were gone.
    expect(screen.getByTestId("recovery-secret__existing")).toBeInTheDocument();
  });

  it("starts the next attempt empty, so a refused credential does not sit in the field", async () => {
    revoke.mockRejectedValue(
      new HttpError(
        problem(HttpStatus.TOO_MANY_REQUESTS, "rate-limited", "Too many attempts. Try later."),
      ),
    );

    render(<RecoverySecretPanel />);
    typePassword(await openRevokeDialog(), "wrong-password");
    fireEvent.click(screen.getByTestId("recovery-secret__revoke-confirm"));
    await screen.findByTestId("recovery-secret__revoke-error");

    expect(await openRevokeDialog()).toHaveValue("");
  });

  /**
   * A `<button>` inside a form submits it unless it says otherwise, so the cancel control is one
   * missing attribute away from being a second confirm — on the dialog guarding the account's
   * last way back in.
   */
  it("cancels without revoking, and drops the credential the user had typed", async () => {
    render(<RecoverySecretPanel />);
    typePassword(await openRevokeDialog(), PASSWORD);

    fireEvent.click(screen.getByRole("button", { name: "Keep the recovery secret" }));

    await waitFor(() =>
      expect(screen.queryByTestId("recovery-secret__revoke-dialog")).not.toBeInTheDocument(),
    );
    expect(revoke).not.toHaveBeenCalled();
    expect(await openRevokeDialog()).toHaveValue("");
  });

  it("keeps the typed password masked until the reveal toggle is pressed", async () => {
    render(<RecoverySecretPanel />);
    const field = await openRevokeDialog();

    expect(field).toHaveAttribute("type", "password");
    fireEvent.click(screen.getByTestId("recovery-secret__revoke-password-toggle"));
    expect(screen.getByTestId("recovery-secret__revoke-password")).toHaveAttribute("type", "text");
  });
});
