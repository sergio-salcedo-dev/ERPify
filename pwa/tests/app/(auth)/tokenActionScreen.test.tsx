import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import {
  AcceptInvitationOutcomeKind,
  type AcceptInvitationOutcome,
} from "@/context/backoffice/user/domain/AcceptInvitationOutcome";
import type { ProblemViolation } from "@/context/shared/error/domain/ProblemDetails";

const params = { search: "" };
vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(params.search),
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

const TOKEN = "0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b60.super-secret-verifier";

function submitWithPassword(password: string): void {
  fireEvent.change(screen.getByTestId("accept-invitation-form__password"), {
    target: { value: password },
  });
  fireEvent.click(screen.getByTestId("accept-invitation-form__submit"));
}

beforeEach(() => {
  login.mockClear();
  repoAccept.mockClear();
  outcome = { kind: AcceptInvitationOutcomeKind.ACCEPTED };
  params.search = `token=${TOKEN}`;
});

describe("TokenActionScreen — token gate", () => {
  it("shows the neutral invalid-link wall and no form when no token is present", () => {
    params.search = "";
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
});

describe("TokenActionScreen — accept outcomes", () => {
  it("logs in and shows the success surface on a 204 accept", async () => {
    render(<TokenActionScreen />);
    submitWithPassword("a-strong-password");

    await waitFor(() =>
      expect(screen.getByTestId("accept-invitation__success")).toBeInTheDocument(),
    );
    expect(repoAccept).toHaveBeenCalledWith({ token: TOKEN, password: "a-strong-password" });
    expect(login).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId("accept-invitation-form")).not.toBeInTheDocument();
  });

  it("replaces the form with the invalid-link wall on a dead token", async () => {
    outcome = { kind: AcceptInvitationOutcomeKind.INVALID_LINK };
    render(<TokenActionScreen />);
    submitWithPassword("a-strong-password");

    await waitFor(() =>
      expect(screen.getByTestId("access-wall--invalid-link")).toBeInTheDocument(),
    );
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
