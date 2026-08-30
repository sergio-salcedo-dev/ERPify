import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import {
  AcceptInvitationOutcomeKind,
  type AcceptInvitationOutcome,
} from "@/context/backoffice/user/domain/AcceptInvitationOutcome";
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

// The accept port outcome is driven per-test via `outcome`; the screen resolves
// the repository from the (mocked) DI container.
let outcome: AcceptInvitationOutcome = { kind: AcceptInvitationOutcomeKind.ACCEPTED };
const repoAccept = vi.fn(async (_command: unknown): Promise<AcceptInvitationOutcome> => outcome);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ accept: (command: unknown) => repoAccept(command) }) },
}));

import { TokenActionScreen } from "@/app/(auth)/_components/TokenActionScreen";
import { SIGNED_IN_SESSION } from "./_session";

const TOKEN = "0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b60.super-secret-verifier";

function submitWithPassword(password: string): void {
  fireEvent.change(screen.getByTestId("accept-invitation-form__password"), {
    target: { value: password },
  });
  fireEvent.click(screen.getByTestId("accept-invitation-form__submit"));
}

beforeEach(() => {
  login.mockReset();
  login.mockResolvedValue(SIGNED_IN_SESSION);
  repoAccept.mockClear();
  outcome = { kind: AcceptInvitationOutcomeKind.ACCEPTED };
  window.history.replaceState(null, "", `/accept-invitation?token=${TOKEN}`);
});

describe("TokenActionScreen — token gate", () => {
  it("shows the neutral invalid-link wall and no form when no token is present", () => {
    window.history.replaceState(null, "", "/accept-invitation");
    render(<TokenActionScreen />);

    expect(screen.getByTestId("access-wall--invalid-link")).toBeInTheDocument();
    expect(screen.queryByTestId("accept-invitation-form")).not.toBeInTheDocument();
  });

  it("renders the form for a present token without ever rendering the token itself", () => {
    render(<TokenActionScreen />);

    expect(screen.getByTestId("accept-invitation-form")).toBeInTheDocument();
    expect(screen.queryByDisplayValue(TOKEN)).not.toBeInTheDocument();
    expect(document.body.textContent ?? "").not.toContain(TOKEN);
  });

  it("strips the token from the URL on mount while the submit keeps sending it", async () => {
    render(<TokenActionScreen />);

    expect(window.location.search).toBe("");
    expect(window.location.pathname).toBe("/accept-invitation");
    expect(screen.getByTestId("accept-invitation-form")).toBeInTheDocument();

    submitWithPassword("a-strong-password");
    await waitFor(() =>
      expect(repoAccept).toHaveBeenCalledWith({ token: TOKEN, password: "a-strong-password" }),
    );
  });

  it("keeps the captured token across re-renders after the URL strip", async () => {
    repoAccept.mockRejectedValueOnce(new Error("network down"));
    render(<TokenActionScreen />);
    expect(window.location.search).toBe("");

    submitWithPassword("a-strong-password");
    expect(await screen.findByTestId("accept-invitation-form__error")).toBeInTheDocument();

    submitWithPassword("a-strong-password");
    await waitFor(() => expect(repoAccept).toHaveBeenCalledTimes(2));
    expect(repoAccept).toHaveBeenLastCalledWith({ token: TOKEN, password: "a-strong-password" });
  });
});

describe("TokenActionScreen — accept outcomes", () => {
  it("logs in and shows the success surface on a 204 accept", async () => {
    render(<TokenActionScreen />);
    submitWithPassword("a-strong-password");

    expect(await screen.findByTestId("accept-invitation__success")).toBeInTheDocument();
    expect(repoAccept).toHaveBeenCalledWith({ token: TOKEN, password: "a-strong-password" });
    expect(login).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId("accept-invitation-form")).not.toBeInTheDocument();
  });

  it("replaces the form with the invalid-link wall on a dead token", async () => {
    outcome = { kind: AcceptInvitationOutcomeKind.INVALID_LINK };
    render(<TokenActionScreen />);
    submitWithPassword("a-strong-password");

    expect(await screen.findByTestId("access-wall--invalid-link")).toBeInTheDocument();
    expect(screen.queryByTestId("accept-invitation-form")).not.toBeInTheDocument();
    expect(login).not.toHaveBeenCalled();
  });

  it("maps a server password violation onto the password field", async () => {
    const violations: ProblemViolation[] = [
      { field: "password", message: "La contraseña es demasiado débil.", code: "weak" },
    ];
    outcome = { kind: AcceptInvitationOutcomeKind.VALIDATION_ERROR, violations };
    render(<TokenActionScreen />);
    submitWithPassword("a-strong-password");

    expect(await screen.findByText("La contraseña es demasiado débil.")).toBeInTheDocument();
    expect(screen.getByTestId("accept-invitation-form")).toBeInTheDocument();
    expect(login).not.toHaveBeenCalled();
  });

  it("surfaces a neutral retryable error when the request throws (transport failure)", async () => {
    repoAccept.mockRejectedValueOnce(new Error("network down"));
    render(<TokenActionScreen />);
    submitWithPassword("a-strong-password");

    expect(await screen.findByTestId("accept-invitation-form__error")).toBeInTheDocument();
    expect(login).not.toHaveBeenCalled();
    expect(screen.getByTestId("accept-invitation-form")).toBeInTheDocument();
  });
});
describe("TokenActionScreen — the session probe decides whether the sign-in happened", () => {
  it("keeps the form and its retryable error when the probe cannot confirm the session", async () => {
    // The success surface offers a CTA into the ERP. Showing it on an unconfirmed probe hands
    // the user a door that bounces them, and hides the retry that would have worked.
    login.mockResolvedValue(null);
    render(<TokenActionScreen />);
    submitWithPassword("a-strong-password");

    expect(await screen.findByTestId("accept-invitation-form__error")).toBeInTheDocument();
    expect(screen.queryByTestId("accept-invitation__success")).not.toBeInTheDocument();
    expect(screen.getByTestId("accept-invitation-form")).toBeInTheDocument();
  });
});
