import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";

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

import { LoginForm } from "@/app/(auth)/_components/LoginForm";

function signIn(): void {
  fireEvent.change(screen.getByTestId("login-form__email"), { target: { value: "a@b.com" } });
  fireEvent.change(screen.getByTestId("login-form__password"), { target: { value: "secret123" } });
  fireEvent.click(screen.getByTestId("login-form__submit"));
}

describe("LoginForm — return URL", () => {
  beforeEach(() => {
    push.mockClear();
    login.mockClear();
  });

  it("returns to a safe ?next= target after sign-in", async () => {
    window.history.replaceState({}, "", "/login?next=%2Fbackoffice%2Fusers");
    render(<LoginForm />);
    signIn();
    await waitFor(() => expect(push).toHaveBeenCalledWith("/backoffice/users"));
  });

  it("falls back to the back-office root for an off-origin ?next= (open-redirect guard)", async () => {
    window.history.replaceState({}, "", "/login?next=https%3A%2F%2Fevil.com");
    render(<LoginForm />);
    signIn();
    await waitFor(() => expect(push).toHaveBeenCalledWith("/backoffice"));
  });

  it("falls back to the back-office root when no ?next= is present", async () => {
    window.history.replaceState({}, "", "/login");
    render(<LoginForm />);
    signIn();
    await waitFor(() => expect(push).toHaveBeenCalledWith("/backoffice"));
  });
});
