import { describe, it, expect } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { PasswordInput } from "@/components/ui/PasswordInput";

const TOGGLE_NAME = "Show/hide password";

describe("PasswordInput", () => {
  it("defaults to REVEALED (type=text) with a pressed toggle carrying a static name", () => {
    render(<PasswordInput data-testid="pw" toggleTestId="pw-toggle" />);

    expect(screen.getByTestId("pw")).toHaveAttribute("type", "text");
    const toggle = screen.getByRole("button", { name: TOGGLE_NAME });
    expect(toggle).toHaveAttribute("aria-pressed", "true");
  });

  it("toggles to masked (type=password) without changing the accessible name", () => {
    render(<PasswordInput data-testid="pw" toggleTestId="pw-toggle" />);

    fireEvent.click(screen.getByRole("button", { name: TOGGLE_NAME }));

    expect(screen.getByTestId("pw")).toHaveAttribute("type", "password");
    expect(screen.getByRole("button", { name: TOGGLE_NAME })).toHaveAttribute(
      "aria-pressed",
      "false",
    );
  });

  it("honours defaultRevealed=false (starts masked)", () => {
    render(<PasswordInput data-testid="pw" defaultRevealed={false} />);
    expect(screen.getByTestId("pw")).toHaveAttribute("type", "password");
  });

  it("forwards typed input through the spread props to the real input", () => {
    render(<PasswordInput data-testid="pw" />);

    const input = screen.getByTestId("pw");
    fireEvent.change(input, { target: { value: "hunter2" } });
    expect(input).toHaveValue("hunter2");
  });
});
