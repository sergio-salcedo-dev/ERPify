import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";

const params = { search: "" };
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn() }),
  useSearchParams: () => new URLSearchParams(params.search),
}));

import { ResetPasswordForm } from "@/app/(auth)/_components/ResetPasswordForm";

describe("ResetPasswordForm — token gate", () => {
  it("shows the invalid-link boundary when no token is present", () => {
    params.search = "";
    render(<ResetPasswordForm />);
    expect(screen.getByTestId("reset-password-form__invalid")).toBeInTheDocument();
    expect(screen.queryByTestId("reset-password-form")).not.toBeInTheDocument();
  });

  it("renders the form when a token is present", () => {
    params.search = "token=opaque-token";
    render(<ResetPasswordForm />);
    expect(screen.getByTestId("reset-password-form")).toBeInTheDocument();
    expect(screen.queryByTestId("reset-password-form__invalid")).not.toBeInTheDocument();
  });
});
