import { describe, it, expect, vi } from "vitest";
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

  // The defaults sit ahead of the spread, which is behaviour and not formatting: a caller that
  // needs the browser's help — a recovery secret typed from a printed card — must be able to win.
  it("lets a caller override every text-assist default", () => {
    render(
      <PasswordInput data-testid="pw" spellCheck autoCorrect="on" autoCapitalize="sentences" />,
    );

    const input = screen.getByTestId("pw");
    expect(input).toHaveAttribute("spellcheck", "true");
    expect(input).toHaveAttribute("autocorrect", "on");
    expect(input).toHaveAttribute("autocapitalize", "sentences");
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

  // jsdom implements no implicit submission (measured: Enter in a field fires no submit event),
  // so the button click above cannot stand for Enter. Listening on the event rather than on a
  // button is what makes every origin equivalent, and this asserts exactly that.
  it("re-masks on the form's submit event whatever caused it", () => {
    render(
      <form data-testid="pw-form" onSubmit={(event) => event.preventDefault()}>
        <PasswordInput data-testid="pw" defaultRevealed />
      </form>,
    );

    fireEvent.submit(screen.getByTestId("pw-form"));

    expect(screen.getByTestId("pw")).toHaveAttribute("type", "password");
  });

  // Symmetry, not just absence of a crash: the handler removed has to be the one added, or the
  // form keeps a listener belonging to a component that no longer exists.
  it("removes exactly the listener it added when it unmounts", () => {
    const addSpy = vi.spyOn(HTMLFormElement.prototype, "addEventListener");
    const removeSpy = vi.spyOn(HTMLFormElement.prototype, "removeEventListener");

    const { unmount } = render(
      <form data-testid="pw-form" onSubmit={(event) => event.preventDefault()}>
        <PasswordInput data-testid="pw" defaultRevealed />
      </form>,
    );
    const added = addSpy.mock.calls.filter(([type]) => type === "submit");
    expect(added).toHaveLength(1);

    unmount();

    const removed = removeSpy.mock.calls.filter(([type]) => type === "submit");
    expect(removed).toHaveLength(1);
    expect(removed[0]?.[1]).toBe(added[0]?.[1]);

    addSpy.mockRestore();
    removeSpy.mockRestore();
  });

  it("detaches from the old form and attaches to the one it is remounted in", () => {
    const { unmount } = render(
      <form data-testid="pw-form-a" onSubmit={(event) => event.preventDefault()}>
        <PasswordInput data-testid="pw-a" defaultRevealed />
      </form>,
    );
    const formA = screen.getByTestId("pw-form-a");
    unmount();
    expect(() => fireEvent.submit(formA)).not.toThrow();

    render(
      <form data-testid="pw-form-b" onSubmit={(event) => event.preventDefault()}>
        <PasswordInput data-testid="pw-b" defaultRevealed />
      </form>,
    );
    expect(screen.getByTestId("pw-b")).toHaveAttribute("type", "text");

    fireEvent.submit(screen.getByTestId("pw-form-b"));

    expect(screen.getByTestId("pw-b")).toHaveAttribute("type", "password");
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
