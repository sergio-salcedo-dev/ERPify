import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { SecuritySignal } from "@/context/shared/access/infrastructure/ui/SecuritySignal";

describe("SecuritySignal", () => {
  it("confirms the accepted invitation and moves focus to the single h1", () => {
    render(<SecuritySignal />);

    const headings = screen.getAllByRole("heading", { level: 1 });
    expect(headings).toHaveLength(1);
    expect(headings[0]).toHaveTextContent("Invitation accepted. You're ready to get started.");
    expect(headings[0]).toHaveFocus();
  });

  it("offers a single in-app primary action into the ERP", () => {
    render(<SecuritySignal testId="sig" />);

    const enter = screen.getByRole("link", { name: "Enter" });
    expect(enter).toHaveAttribute("href", "/backoffice");
    expect(enter).toHaveAttribute("data-testid", "sig__enter");
  });
});
