import { describe, it, expect } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { PasswordInput } from "@/components/ui/PasswordInput";

const TOGGLE_NAME = "Show/hide password";

describe("PasswordInput", () => {
  it("defaults to MASKED (type=password) with an unpressed toggle carrying a static name", () => {
    render(<PasswordInput data-testid="pw" toggleTestId="pw-toggle" />);

    expect(screen.getByTestId("pw")).toHaveAttribute("type", "password");
    const toggle = screen.getByRole("button", { name: TOGGLE_NAME });
    expect(toggle).toHaveAttribute("aria-pressed", "false");
  });

  it("toggles to revealed (type=text) without changing the accessible name", () => {
    render(<PasswordInput data-testid="pw" toggleTestId="pw-toggle" />);

    fireEvent.click(screen.getByRole("button", { name: TOGGLE_NAME }));

    expect(screen.getByTestId("pw")).toHaveAttribute("type", "text");
    expect(screen.getByRole("button", { name: TOGGLE_NAME })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
  });

  it("returns to masked on a second click, with aria-pressed following in both directions", () => {
    render(<PasswordInput data-testid="pw" toggleTestId="pw-toggle" />);
    const toggle = () => screen.getByRole("button", { name: TOGGLE_NAME });

    fireEvent.click(toggle());
    fireEvent.click(toggle());

    expect(screen.getByTestId("pw")).toHaveAttribute("type", "password");
    expect(toggle()).toHaveAttribute("aria-pressed", "false");
  });

  // The icon is asserted through lucide's own `lucide-<name>` class, which coupling is accepted
  // here: it is the only handle the rendered SVG offers, and a library rename reds a test rather
  // than production. What matters is that the two states differ without relying on colour.
  it("swaps the icon with the state", () => {
    render(<PasswordInput data-testid="pw" />);
    const iconClass = () =>
      screen
        .getByRole("button", { name: TOGGLE_NAME })
        .querySelector("svg")
        ?.getAttribute("class") ?? "";

    const masked = iconClass();
    expect(masked).toContain("eye");
    expect(masked).not.toContain("off");

    fireEvent.click(screen.getByRole("button", { name: TOGGLE_NAME }));

    expect(iconClass()).toContain("off");
    expect(iconClass()).not.toBe(masked);
  });

  it("keeps the text-assist attributes off, so a revealed secret is not treated as prose", () => {
    render(<PasswordInput data-testid="pw" />);

    const input = screen.getByTestId("pw");
    expect(input).toHaveAttribute("spellcheck", "false");
    expect(input).toHaveAttribute("autocorrect", "off");
    expect(input).toHaveAttribute("autocapitalize", "none");
  });

  it("lets a caller override the text-assist defaults", () => {
    render(<PasswordInput data-testid="pw" spellCheck />);
    expect(screen.getByTestId("pw")).toHaveAttribute("spellcheck", "true");
  });

  it("honours defaultRevealed (starts revealed for the flows that ask for it)", () => {
    render(<PasswordInput data-testid="pw" defaultRevealed />);
    expect(screen.getByTestId("pw")).toHaveAttribute("type", "text");
  });

  it("re-masks when the enclosing form is submitted", () => {
    render(
      <form onSubmit={(event) => event.preventDefault()}>
        <PasswordInput data-testid="pw" defaultRevealed />
        <button type="submit" data-testid="pw-submit">
          Send
        </button>
      </form>,
    );
    expect(screen.getByTestId("pw")).toHaveAttribute("type", "text");

    fireEvent.click(screen.getByTestId("pw-submit"));

    expect(screen.getByTestId("pw")).toHaveAttribute("type", "password");
  });

  it("stays masked across a submit it was already masked for", () => {
    render(
      <form onSubmit={(event) => event.preventDefault()}>
        <PasswordInput data-testid="pw" />
        <button type="submit" data-testid="pw-submit">
          Send
        </button>
      </form>,
    );

    fireEvent.click(screen.getByTestId("pw-submit"));

    expect(screen.getByTestId("pw")).toHaveAttribute("type", "password");
  });

  // The re-mask needs an internal ref; the caller's must still reach the real input, because
  // that is how RHF registers the field.
  it("hands the real input to a caller's ref object", () => {
    const ref = { current: null as HTMLInputElement | null };
    render(<PasswordInput data-testid="pw" ref={ref} />);

    expect(ref.current).toBe(screen.getByTestId("pw"));
  });

  it("hands the real input to a caller's ref callback", () => {
    let received: HTMLInputElement | null = null;
    render(
      <PasswordInput
        data-testid="pw"
        ref={(node) => {
          received = node;
        }}
      />,
    );

    expect(received).toBe(screen.getByTestId("pw"));
  });

  it("forwards typed input through the spread props to the real input", () => {
    render(<PasswordInput data-testid="pw" />);

    const input = screen.getByTestId("pw");
    fireEvent.change(input, { target: { value: "hunter2" } });
    expect(input).toHaveValue("hunter2");
  });
});
