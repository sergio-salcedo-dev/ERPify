import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { LoginOutcomeKind, type LoginOutcome } from "@/context/backoffice/user/domain/LoginOutcome";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({
    push: (...args: unknown[]) => push(...args),
    replace: vi.fn(),
    refresh: vi.fn(),
  }),
}));

const login = vi.fn();
vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ login: (...args: unknown[]) => login(...args), status: "unauthenticated" }),
}));

// The login port outcome is driven per-test via `outcome`; the form resolves the
// repository from the (mocked) DI container.
let outcome: LoginOutcome = { kind: LoginOutcomeKind.AUTHENTICATED };
const repoLogin = vi.fn(async (): Promise<LoginOutcome> => outcome);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ login: () => repoLogin() }) },
}));

import { LoginForm } from "@/app/(auth)/_components/LoginForm";

function signIn(): void {
  fireEvent.change(screen.getByTestId("login-form__email"), { target: { value: "a@b.com" } });
  fireEvent.change(screen.getByTestId("login-form__password"), { target: { value: "secret123" } });
  fireEvent.click(screen.getByTestId("login-form__submit"));
}

beforeEach(() => {
  push.mockClear();
  login.mockClear();
  repoLogin.mockClear();
  outcome = { kind: LoginOutcomeKind.AUTHENTICATED };
  window.history.replaceState({}, "", "/login");
});

describe("LoginForm — authenticated return URL", () => {
  it.each([
    {
      case: "returns to a safe ?next= target",
      url: "/login?next=%2Fbackoffice%2Fusers",
      target: "/backoffice/users",
    },
    {
      case: "falls back to the back-office root for an off-origin ?next= (open-redirect guard)",
      url: "/login?next=https%3A%2F%2Fevil.com",
      target: "/backoffice",
    },
    {
      case: "falls back to the back-office root when no ?next= is present",
      url: "/login",
      target: "/backoffice",
    },
  ])("$case after sign-in", async ({ url, target }) => {
    window.history.replaceState({}, "", url);
    render(<LoginForm />);
    signIn();
    await waitFor(() => expect(push).toHaveBeenCalledWith(target));
    expect(login).toHaveBeenCalledTimes(1);
  });
});

describe("LoginForm — access outcomes", () => {
  it("shows a single neutral credentials error and does not navigate on invalid credentials", async () => {
    outcome = { kind: LoginOutcomeKind.INVALID_CREDENTIALS };
    render(<LoginForm />);
    signIn();

    const error = await screen.findByTestId("login-form__error");
    expect(error).toHaveTextContent("Invalid email or password.");
    expect(error).toHaveAttribute("role", "alert");
    expect(push).not.toHaveBeenCalled();
    expect(login).not.toHaveBeenCalled();
    // The form stays; no wall replaces it.
    expect(screen.getByTestId("login-form")).toBeInTheDocument();
  });

  it("renders the suspended access wall in place of the form", async () => {
    outcome = { kind: LoginOutcomeKind.SUSPENDED };
    render(<LoginForm />);
    signIn();

    await waitFor(() => expect(screen.getByTestId("access-wall--suspended")).toBeInTheDocument());
    expect(screen.queryByTestId("login-form")).not.toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
    expect(login).not.toHaveBeenCalled();
  });

  it("renders the deactivated access wall in place of the form", async () => {
    outcome = { kind: LoginOutcomeKind.DEACTIVATED };
    render(<LoginForm />);
    signIn();

    await waitFor(() => expect(screen.getByTestId("access-wall--deactivated")).toBeInTheDocument());
    expect(screen.queryByTestId("login-form")).not.toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });

  it("renders the locked access wall in place of the form", async () => {
    outcome = { kind: LoginOutcomeKind.LOCKED };
    render(<LoginForm />);
    signIn();

    await waitFor(() => expect(screen.getByTestId("access-wall--locked")).toBeInTheDocument());
    expect(screen.queryByTestId("login-form")).not.toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
    expect(login).not.toHaveBeenCalled();
  });

  it("surfaces the neutral retryable error when the server fails to finalise the session (503)", async () => {
    outcome = { kind: LoginOutcomeKind.REQUEST_FAILED };
    render(<LoginForm />);
    signIn();

    const error = await screen.findByTestId("login-form__request-error");
    expect(error).toHaveTextContent("Something went wrong. Please try again.");
    expect(push).not.toHaveBeenCalled();
    expect(login).not.toHaveBeenCalled();
    // The form stays; this is not the credentials error and no wall replaces it.
    expect(screen.getByTestId("login-form")).toBeInTheDocument();
    expect(screen.queryByTestId("login-form__error")).not.toBeInTheDocument();
  });

  it("surfaces a neutral, retryable error when the login request fails (network/transport)", async () => {
    repoLogin.mockRejectedValueOnce(new Error("network down"));
    render(<LoginForm />);
    signIn();

    const error = await screen.findByTestId("login-form__request-error");
    expect(error).toHaveTextContent("Something went wrong. Please try again.");
    expect(error).toHaveAttribute("role", "alert");
    expect(push).not.toHaveBeenCalled();
    expect(login).not.toHaveBeenCalled();
    // The form stays mounted (no wall), and this is not the credentials error.
    expect(screen.getByTestId("login-form")).toBeInTheDocument();
    expect(screen.queryByTestId("login-form__error")).not.toBeInTheDocument();
  });
});
