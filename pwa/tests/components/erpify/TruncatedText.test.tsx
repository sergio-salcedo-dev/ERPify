import { afterEach, describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { TruncatedText } from "@/components/erpify/TruncatedText";
import { TooltipProvider } from "@/components/ui/tooltip";

const LONG = "Construcciones y Promociones del Mediterráneo Oriental, Sociedad Anónima";

function forceTruncation(): void {
  // jsdom has no layout: emulate a span whose content overflows its box.
  Object.defineProperty(HTMLElement.prototype, "scrollWidth", {
    configurable: true,
    get: () => 200,
  });
  Object.defineProperty(HTMLElement.prototype, "clientWidth", {
    configurable: true,
    get: () => 100,
  });
}

function resetDimensions(): void {
  delete (HTMLElement.prototype as Record<string, unknown>)["scrollWidth" as never];
  delete (HTMLElement.prototype as Record<string, unknown>)["clientWidth" as never];
}

afterEach(resetDimensions);

describe("TruncatedText", () => {
  it("renders a plain span (no tooltip) when the text is not truncated", () => {
    render(
      <TooltipProvider>
        <TruncatedText value={LONG} testId="tt" />
      </TooltipProvider>,
    );
    const span = screen.getByTestId("tt");
    expect(span).toHaveTextContent(LONG);
    expect(span).toHaveClass("truncate");
    expect(span).not.toHaveAttribute("title");
  });

  it("opens the full-value tooltip when the enclosing row receives keyboard focus, and Esc dismisses it", () => {
    forceTruncation();
    render(
      <TooltipProvider>
        <table>
          <tbody>
            <tr tabIndex={0} data-testid="row">
              <td>
                <TruncatedText value={LONG} testId="tt" />
              </td>
            </tr>
          </tbody>
        </table>
      </TooltipProvider>,
    );
    const row = screen.getByTestId("row");

    fireEvent.focusIn(row, { target: row });
    expect(screen.getAllByText(LONG).length).toBeGreaterThan(1);

    fireEvent.keyDown(row, { key: "Escape" });
    expect(screen.getAllByText(LONG)).toHaveLength(1);
  });

  it("never adds a tab stop to the truncated span (the row is the only stop)", () => {
    forceTruncation();
    render(
      <TooltipProvider>
        <table>
          <tbody>
            <tr tabIndex={0}>
              <td>
                <TruncatedText value={LONG} testId="tt" />
              </td>
            </tr>
          </tbody>
        </table>
      </TooltipProvider>,
    );
    expect(screen.getByTestId("tt")).not.toHaveAttribute("tabindex");
  });
});
