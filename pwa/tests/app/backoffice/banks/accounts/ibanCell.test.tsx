import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { IbanCell, maskIban } from "@/app/backoffice/banks/[id]/accounts/_components/IbanCell";

const IBAN = "ES9121000418450200051332";

describe("maskIban", () => {
  it("masks the middle, keeping the country code and the last four digits", () => {
    expect(maskIban(IBAN)).toBe("ES•• ···· 1332");
  });

  it("fully masks a short/garbage value so the integral string is never derivable", () => {
    for (const short of ["ES12", "12", "ES", "", "ES1234"]) {
      const masked = maskIban(short);
      if (short.length > 0) {
        expect(masked).not.toContain(short);
      }
      expect(masked).toBe("•••• ···· ••••");
    }
  });
});

describe("IbanCell", () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("shows the mask by default and never the full IBAN", () => {
    render(<IbanCell iban={IBAN} revealed={false} onReveal={() => {}} onHide={() => {}} />);
    expect(screen.getByText("ES•• ···· 1332")).toBeInTheDocument();
    expect(screen.queryByText(IBAN)).toBeNull();
    const toggle = screen.getByRole("button", { name: "Show IBAN" });
    expect(toggle).toHaveAttribute("aria-pressed", "false");
  });

  it("reveals the full IBAN when controlled to revealed and flips the toggle", () => {
    render(<IbanCell iban={IBAN} revealed onReveal={() => {}} onHide={() => {}} />);
    expect(screen.getByText(IBAN)).toBeInTheDocument();
    const toggle = screen.getByRole("button", { name: "Hide IBAN" });
    expect(toggle).toHaveAttribute("aria-pressed", "true");
  });

  it("calls onReveal from masked state and onHide from revealed state", () => {
    const onReveal = vi.fn();
    const onHide = vi.fn();
    const { rerender } = render(
      <IbanCell iban={IBAN} revealed={false} onReveal={onReveal} onHide={onHide} />,
    );
    fireEvent.click(screen.getByRole("button", { name: "Show IBAN" }));
    expect(onReveal).toHaveBeenCalledTimes(1);

    rerender(<IbanCell iban={IBAN} revealed onReveal={onReveal} onHide={onHide} />);
    fireEvent.click(screen.getByRole("button", { name: "Hide IBAN" }));
    expect(onHide).toHaveBeenCalledTimes(1);
  });

  it("auto-hides after ~10s while revealed", () => {
    vi.useFakeTimers();
    try {
      const onHide = vi.fn();
      render(<IbanCell iban={IBAN} revealed onReveal={() => {}} onHide={onHide} />);
      expect(onHide).not.toHaveBeenCalled();
      vi.advanceTimersByTime(10_000);
      expect(onHide).toHaveBeenCalledTimes(1);
    } finally {
      vi.useRealTimers();
    }
  });

  it("re-hides when the pointer leaves the cell while revealed", () => {
    const onHide = vi.fn();
    render(
      <IbanCell iban={IBAN} revealed onReveal={() => {}} onHide={onHide} testId="iban-cell" />,
    );
    fireEvent.mouseLeave(screen.getByTestId("iban-cell"));
    expect(onHide).toHaveBeenCalledTimes(1);
  });

  it("re-hides when focus leaves the cell while revealed", () => {
    const onHide = vi.fn();
    render(
      <IbanCell iban={IBAN} revealed onReveal={() => {}} onHide={onHide} testId="iban-cell" />,
    );
    // Focus leaves to an element outside the cell.
    fireEvent.blur(screen.getByRole("button", { name: "Hide IBAN" }), { relatedTarget: null });
    expect(onHide).toHaveBeenCalledTimes(1);
  });

  it("exposes the reveal toggle as a focusable native button (Enter/Space operable)", () => {
    render(<IbanCell iban={IBAN} revealed={false} onReveal={() => {}} onHide={() => {}} />);
    const toggle = screen.getByRole("button", { name: "Show IBAN" });
    // A native <button type="button"> is keyboard-activatable via Enter/Space by
    // construction (the spec's "Enter/Space nativo"); assert that contract rather
    // than re-testing the browser's intrinsic behaviour.
    expect(toggle.tagName).toBe("BUTTON");
    expect(toggle).toHaveAttribute("type", "button");
    expect(toggle).not.toBeDisabled();
    expect(toggle).not.toHaveAttribute("tabindex", "-1");
  });

  it("never writes the IBAN to localStorage or sessionStorage in either reveal state", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    const setItem = vi.spyOn(Storage.prototype, "setItem");

    const { rerender } = render(
      <IbanCell
        iban={IBAN}
        revealed={false}
        onReveal={() => {}}
        onHide={() => {}}
        testId="iban-cell"
      />,
    );
    fireEvent.click(screen.getByTestId("iban-cell__copy"));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith(IBAN));
    rerender(
      <IbanCell iban={IBAN} revealed onReveal={() => {}} onHide={() => {}} testId="iban-cell" />,
    );
    fireEvent.click(screen.getByTestId("iban-cell__copy"));
    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(2));

    for (const call of setItem.mock.calls) {
      expect(JSON.stringify(call)).not.toContain(IBAN);
    }
  });

  it("copies the full IBAN regardless of reveal state and never leaks it to the console", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    const consoleSpies = (["log", "info", "warn", "error", "debug"] as const).map((m) =>
      vi.spyOn(console, m).mockImplementation(() => {}),
    );

    const { rerender } = render(
      <IbanCell
        iban={IBAN}
        revealed={false}
        onReveal={() => {}}
        onHide={() => {}}
        testId="iban-cell"
      />,
    );
    fireEvent.click(screen.getByTestId("iban-cell__copy"));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith(IBAN));

    rerender(
      <IbanCell iban={IBAN} revealed onReveal={() => {}} onHide={() => {}} testId="iban-cell" />,
    );
    fireEvent.click(screen.getByTestId("iban-cell__copy"));
    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(2));

    for (const spy of consoleSpies) {
      for (const call of spy.mock.calls) {
        expect(JSON.stringify(call)).not.toContain(IBAN);
      }
    }
  });
});
