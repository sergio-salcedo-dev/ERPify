import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { LoginOutcomeKind, type LoginOutcome } from "@/context/backoffice/user/domain/LoginOutcome";
import type { LoginCredentials } from "@/context/backoffice/user/domain/LoginCredentials";

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
// The credentials are forwarded rather than dropped: what the user typed reaching the port
// intact is the only thing that distinguishes a wired field from a merely present one.
const repoLogin = vi.fn(async (_credentials: LoginCredentials): Promise<LoginOutcome> => outcome);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ login: (credentials: LoginCredentials) => repoLogin(credentials) }) },
}));

import { LoginForm } from "@/app/(auth)/_components/LoginForm";
import { SIGNED_IN_SESSION } from "./_session";

const TOGGLE_NAME = "Show/hide password";

function signIn(): void {
  fireEvent.change(screen.getByTestId("login-form__email"), { target: { value: "a@b.com" } });
  fireEvent.change(screen.getByTestId("login-form__password"), { target: { value: "secret123" } });
  fireEvent.click(screen.getByTestId("login-form__submit"));
}

beforeEach(() => {
  push.mockClear();
  login.mockReset();
  login.mockResolvedValue(SIGNED_IN_SESSION);
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

    expect(await screen.findByTestId("access-wall--suspended")).toBeInTheDocument();
    expect(screen.queryByTestId("login-form")).not.toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
    expect(login).not.toHaveBeenCalled();
  });

  it("renders the deactivated access wall in place of the form", async () => {
    outcome = { kind: LoginOutcomeKind.DEACTIVATED };
    render(<LoginForm />);
    signIn();

    expect(await screen.findByTestId("access-wall--deactivated")).toBeInTheDocument();
    expect(screen.queryByTestId("login-form")).not.toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });

  it("renders the locked access wall in place of the form", async () => {
    outcome = { kind: LoginOutcomeKind.LOCKED };
    render(<LoginForm />);
    signIn();

    expect(await screen.findByTestId("access-wall--locked")).toBeInTheDocument();
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
describe("LoginForm — the session probe decides whether the sign-in happened", () => {
  it("does not announce or navigate on a sign-in the probe could not confirm", async () => {
    // `login()` resolves `null` both for "no live session" and for "the probe failed", and the
    // caller's move is the same either way: navigating here hands RequireAuth an unauthenticated
    // provider, which bounces the user straight back to this form with a success toast behind it.
    login.mockResolvedValue(null);
    render(<LoginForm />);
    signIn();

    const error = await screen.findByTestId("login-form__request-error");
    expect(error).toHaveTextContent("Something went wrong. Please try again.");
    expect(push).not.toHaveBeenCalled();
    expect(login).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId("login-form")).toBeInTheDocument();
  });
});

describe("LoginForm — the password field", () => {
  it("starts masked, reveals on demand, and hands the typed value to the port intact", async () => {
    render(<LoginForm />);

    const password = screen.getByTestId("login-form__password");
    const toggle = screen.getByTestId("login-form__password-toggle");
    expect(password).toHaveAttribute("type", "password");
    expect(toggle).toHaveAttribute("aria-pressed", "false");
    expect(toggle).toHaveAccessibleName(TOGGLE_NAME);

    fireEvent.change(screen.getByTestId("login-form__email"), { target: { value: "a@b.com" } });
    fireEvent.change(password, { target: { value: "secret123" } });

    fireEvent.click(toggle);
    expect(password).toHaveAttribute("type", "text");
    expect(password).toHaveValue("secret123");
    expect(toggle).toHaveAttribute("aria-pressed", "true");
    expect(toggle).toHaveAccessibleName(TOGGLE_NAME);
    // Stated rather than incidental: the toggle mutates `type` on the same node. A composition
    // that rendered two branches instead would clear the visible field while RHF kept the value,
    // and the port assertion below would stay green over it.
    expect(screen.getByTestId("login-form__password")).toBe(password);

    fireEvent.click(toggle);
    expect(password).toHaveAttribute("type", "password");
    expect(password).toHaveValue("secret123");

    fireEvent.click(screen.getByTestId("login-form__submit"));

    await waitFor(() =>
      expect(repoLogin).toHaveBeenCalledWith({ email: "a@b.com", password: "secret123" }),
    );
  });

  it("submits the value the user can see, when they leave the field revealed", async () => {
    render(<LoginForm />);

    fireEvent.change(screen.getByTestId("login-form__email"), { target: { value: "a@b.com" } });
    fireEvent.change(screen.getByTestId("login-form__password"), {
      target: { value: "secret123" },
    });
    fireEvent.click(screen.getByTestId("login-form__password-toggle"));
    expect(screen.getByTestId("login-form__password")).toHaveAttribute("type", "text");

    fireEvent.click(screen.getByTestId("login-form__submit"));

    await waitFor(() =>
      expect(repoLogin).toHaveBeenCalledWith({ email: "a@b.com", password: "secret123" }),
    );
    // The submitted secret does not stay in plain sight for the life of the tab.
    expect(screen.getByTestId("login-form__password")).toHaveAttribute("type", "password");
    expect(screen.getByTestId("login-form__password-toggle")).toHaveAttribute(
      "aria-pressed",
      "false",
    );
  });

  it("re-masks even when the credentials are rejected, so a retry does not sit in the clear", async () => {
    outcome = { kind: LoginOutcomeKind.INVALID_CREDENTIALS };
    render(<LoginForm />);

    fireEvent.change(screen.getByTestId("login-form__email"), { target: { value: "a@b.com" } });
    fireEvent.change(screen.getByTestId("login-form__password"), {
      target: { value: "secret123" },
    });
    fireEvent.click(screen.getByTestId("login-form__password-toggle"));
    fireEvent.click(screen.getByTestId("login-form__submit"));

    await screen.findByTestId("login-form__error");
    expect(screen.getByTestId("login-form__password")).toHaveAttribute("type", "password");
    expect(screen.getByTestId("login-form__password")).toHaveValue("secret123");
  });
});
