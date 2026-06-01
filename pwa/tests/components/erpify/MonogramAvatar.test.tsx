import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { MonogramAvatar } from "@/components/erpify";

describe("MonogramAvatar", () => {
  it("renders the derived initials and merges the className", () => {
    render(<MonogramAvatar name="Santander Bank" className="size-12" testId="x-avatar" />);
    const el = screen.getByTestId("x-avatar");
    expect(el).toHaveTextContent("SB");
    expect(el).toHaveClass("size-12");
    expect(el).not.toHaveClass("size-9");
  });

  it("is decorative: hidden from assistive tech (the name is shown beside it)", () => {
    render(<MonogramAvatar name="BBVA" testId="x-avatar" />);
    expect(screen.getByTestId("x-avatar")).toHaveAttribute("aria-hidden", "true");
  });

  it("falls back to a neutral glyph when the name yields no initials", () => {
    render(<MonogramAvatar name="   " testId="x-avatar" />);
    expect(screen.getByTestId("x-avatar")).toHaveTextContent("–");
  });
});
