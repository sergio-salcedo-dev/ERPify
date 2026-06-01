import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { Spinner } from "@/components/erpify";

describe("Spinner", () => {
  it("renders a decorative animated spinner with the merged className", () => {
    render(<Spinner className="size-3.5" testId="x-spinner" />);
    const el = screen.getByTestId("x-spinner");
    expect(el).toHaveClass("animate-spin");
    expect(el).toHaveClass("size-3.5");
    // Decorative: hidden from assistive tech because the surrounding
    // control (button) already carries the accessible name.
    expect(el).toHaveAttribute("aria-hidden", "true");
  });
});
