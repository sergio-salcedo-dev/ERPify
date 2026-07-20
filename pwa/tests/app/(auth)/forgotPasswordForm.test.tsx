import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";

vi.mock("@/context/shared/connectivity/infrastructure/useOnlineStatus", () => ({
  useOnlineStatus: () => true,
}));

// The forgot port resolves for a uniform 202 and rejects only on a transport
// fault; the screen resolves the repository from the (mocked) DI container.
const repoRequest = vi.fn(async (_command: unknown): Promise<void> => undefined);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ request: (command: unknown) => repoRequest(command) }) },
}));

import { ForgotPasswordForm } from "@/app/(auth)/_components/ForgotPasswordForm";

const CONFIRMATION =
  "If that address matches an account, we've sent you a link to reset your password.";

function submitWithEmail(email: string): void {
  fireEvent.change(screen.getByTestId("forgot-password-form__email"), {
    target: { value: email },
  });
  fireEvent.click(screen.getByTestId("forgot-password-form__submit"));
}

beforeEach(() => {
  repoRequest.mockClear();
  repoRequest.mockResolvedValue(undefined);
});

describe("ForgotPasswordForm", () => {
  it("renders the email form initially", () => {
    render(<ForgotPasswordForm />);

    expect(screen.getByTestId("forgot-password-form")).toBeInTheDocument();
    expect(screen.queryByTestId("forgot-password__confirmation")).not.toBeInTheDocument();
  });

  it("shows the uniform confirmation for a known-looking address", async () => {
    render(<ForgotPasswordForm />);
    submitWithEmail("known@example.com");

    expect(await screen.findByTestId("forgot-password__confirmation")).toBeInTheDocument();
    expect(repoRequest).toHaveBeenCalledWith({ email: "known@example.com" });
    expect(screen.getByText(CONFIRMATION)).toBeInTheDocument();
    expect(screen.queryByTestId("forgot-password-form")).not.toBeInTheDocument();
  });

  it("shows the identical confirmation for an unknown address (no enumeration)", async () => {
    render(<ForgotPasswordForm />);
    submitWithEmail("nobody@example.com");

    expect(await screen.findByTestId("forgot-password__confirmation")).toBeInTheDocument();
    // The copy carries no signal about whether the address matches an account.
    expect(screen.getByText(CONFIRMATION)).toBeInTheDocument();
  });

  it("surfaces a neutral retryable error when the request throws (transport failure)", async () => {
    repoRequest.mockRejectedValueOnce(new Error("network down"));
    render(<ForgotPasswordForm />);
    submitWithEmail("known@example.com");

    expect(await screen.findByTestId("forgot-password-form__error")).toBeInTheDocument();
    expect(screen.getByTestId("forgot-password-form")).toBeInTheDocument();
    expect(screen.queryByTestId("forgot-password__confirmation")).not.toBeInTheDocument();
  });

  it("does not call the port when the email is invalid (client validation gate)", async () => {
    render(<ForgotPasswordForm />);
    submitWithEmail("not-an-email");

    expect(await screen.findByText("Enter a valid email address.")).toBeInTheDocument();
    expect(repoRequest).not.toHaveBeenCalled();
  });
});
