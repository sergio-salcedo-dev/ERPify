import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import {
  ResetPasswordOutcomeKind,
  type ResetPasswordOutcome,
} from "@/context/backoffice/user/domain/ResetPasswordOutcome";
import type { ProblemViolation } from "@/context/shared/error/domain/ProblemDetails";

// The mock reads the live jsdom URL so the on-mount query-string strip is
// observable: after it runs, useSearchParams no longer serves the token and
// only the screen's captured copy can drive the submit.
vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(window.location.search),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn() }),
}));

const login = vi.fn();
vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ login: (...args: unknown[]) => login(...args), status: "unauthenticated" }),
}));

vi.mock("@/context/shared/connectivity/infrastructure/useOnlineStatus", () => ({
  useOnlineStatus: () => true,
}));

// The reset port outcome is driven per-test via `outcome`; the screen resolves
// the repository from the (mocked) DI container.
let outcome: ResetPasswordOutcome = { kind: ResetPasswordOutcomeKind.RESET };
const repoReset = vi.fn(async (_command: unknown): Promise<ResetPasswordOutcome> => outcome);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ reset: (command: unknown) => repoReset(command) }) },
}));

import { ResetPasswordForm } from "@/app/(auth)/_components/ResetPasswordForm";

const TOKEN = "0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b60.super-secret-verifier";

function submitWithPassword(password: string): void {
  fireEvent.change(screen.getByTestId("reset-password-form__password"), {
    target: { value: password },
  });
  fireEvent.click(screen.getByTestId("reset-password-form__submit"));
}

beforeEach(() => {
  login.mockClear();
  repoReset.mockClear();
  outcome = { kind: ResetPasswordOutcomeKind.RESET };
  window.history.replaceState(null, "", `/reset-password?token=${TOKEN}`);
});

describe("ResetPasswordForm — token gate", () => {
  it("shows the neutral invalid-link wall and no form when no token is present", () => {
    window.history.replaceState(null, "", "/reset-password");
    render(<ResetPasswordForm />);

    expect(screen.getByTestId("access-wall--invalid-link")).toBeInTheDocument();
    expect(screen.queryByTestId("reset-password-form")).not.toBeInTheDocument();
  });

  it("renders the form for a present token without ever rendering the token itself", () => {
    render(<ResetPasswordForm />);

    expect(screen.getByTestId("reset-password-form")).toBeInTheDocument();
    expect(screen.queryByDisplayValue(TOKEN)).not.toBeInTheDocument();
    expect(document.body.textContent ?? "").not.toContain(TOKEN);
  });

  it("strips the token from the URL on mount while the submit keeps sending it", async () => {
    render(<ResetPasswordForm />);

    expect(window.location.search).toBe("");
    expect(window.location.pathname).toBe("/reset-password");
    expect(screen.getByTestId("reset-password-form")).toBeInTheDocument();

    submitWithPassword("a-strong-password");
    await waitFor(() =>
      expect(repoReset).toHaveBeenCalledWith({ token: TOKEN, password: "a-strong-password" }),
    );
  });

  it("keeps the captured token across re-renders after the URL strip", async () => {
    repoReset.mockRejectedValueOnce(new Error("network down"));
    render(<ResetPasswordForm />);
    expect(window.location.search).toBe("");

    submitWithPassword("a-strong-password");
    expect(await screen.findByTestId("reset-password-form__error")).toBeInTheDocument();

    submitWithPassword("a-strong-password");
    await waitFor(() => expect(repoReset).toHaveBeenCalledTimes(2));
    expect(repoReset).toHaveBeenLastCalledWith({ token: TOKEN, password: "a-strong-password" });
  });
});

describe("ResetPasswordForm — reset outcomes", () => {
  it("logs in and shows the security signal on a 204 reset", async () => {
    render(<ResetPasswordForm />);
    submitWithPassword("a-strong-password");

    await waitFor(() => expect(screen.getByTestId("reset-password__success")).toBeInTheDocument());
    expect(repoReset).toHaveBeenCalledWith({ token: TOKEN, password: "a-strong-password" });
    expect(login).toHaveBeenCalledTimes(1);
    expect(
      screen.getByText("Contraseña actualizada. Hemos cerrado tus otras sesiones abiertas."),
    ).toBeInTheDocument();
    expect(screen.queryByTestId("reset-password-form")).not.toBeInTheDocument();
  });

  it("replaces the form with the opaque invalid-link wall on a dead token", async () => {
    outcome = { kind: ResetPasswordOutcomeKind.INVALID_LINK };
    render(<ResetPasswordForm />);
    submitWithPassword("a-strong-password");

    await waitFor(() =>
      expect(screen.getByTestId("access-wall--invalid-link")).toBeInTheDocument(),
    );
    // The wall collapses every dead-token reason (invalid, expired, consumed)
    // into one opaque message and always offers the sign-in exit.
    expect(
      screen.getByRole("heading", { name: "Este enlace ya no es válido" }),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Iniciar sesión" })).toBeInTheDocument();
    expect(screen.queryByTestId("reset-password-form")).not.toBeInTheDocument();
    expect(login).not.toHaveBeenCalled();
  });

  it("shows the suspended wall on a 403 suspended account", async () => {
    outcome = { kind: ResetPasswordOutcomeKind.SUSPENDED };
    render(<ResetPasswordForm />);
    submitWithPassword("a-strong-password");

    await waitFor(() => expect(screen.getByTestId("access-wall--suspended")).toBeInTheDocument());
    expect(login).not.toHaveBeenCalled();
  });

  it("shows the deactivated wall on a deactivated account", async () => {
    outcome = { kind: ResetPasswordOutcomeKind.DEACTIVATED };
    render(<ResetPasswordForm />);
    submitWithPassword("a-strong-password");

    await waitFor(() => expect(screen.getByTestId("access-wall--deactivated")).toBeInTheDocument());
    expect(login).not.toHaveBeenCalled();
  });

  it("swallows a second concurrent submit while the reset is in flight", async () => {
    let resolveReset: (result: ResetPasswordOutcome) => void = () => {};
    repoReset.mockImplementationOnce(
      () =>
        new Promise<ResetPasswordOutcome>((resolve) => {
          resolveReset = resolve;
        }),
    );
    render(<ResetPasswordForm />);

    submitWithPassword("a-strong-password");
    await waitFor(() => expect(repoReset).toHaveBeenCalledTimes(1));

    // Enter submits the form even though ConnectivityButton is disabled in
    // flight; the in-flight latch must swallow the re-fire so the same token is
    // never reset twice (the loser's 400 would tap the wall over the success).
    fireEvent.submit(screen.getByTestId("reset-password-form"));
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(repoReset).toHaveBeenCalledTimes(1);

    resolveReset({ kind: ResetPasswordOutcomeKind.RESET });
    await waitFor(() => expect(screen.getByTestId("reset-password__success")).toBeInTheDocument());
    expect(repoReset).toHaveBeenCalledTimes(1);
  });

  it("maps a server password violation onto the password field", async () => {
    const violations: ProblemViolation[] = [
      { field: "password", message: "La contraseña es demasiado débil.", code: "weak" },
    ];
    outcome = { kind: ResetPasswordOutcomeKind.VALIDATION_ERROR, violations };
    render(<ResetPasswordForm />);
    submitWithPassword("a-strong-password");

    expect(await screen.findByText("La contraseña es demasiado débil.")).toBeInTheDocument();
    expect(screen.getByTestId("reset-password-form")).toBeInTheDocument();
    expect(login).not.toHaveBeenCalled();
  });

  it("surfaces a neutral retryable error when the request throws (transport failure)", async () => {
    repoReset.mockRejectedValueOnce(new Error("network down"));
    render(<ResetPasswordForm />);
    submitWithPassword("a-strong-password");

    expect(await screen.findByTestId("reset-password-form__error")).toBeInTheDocument();
    expect(login).not.toHaveBeenCalled();
    expect(screen.getByTestId("reset-password-form")).toBeInTheDocument();
  });
});
