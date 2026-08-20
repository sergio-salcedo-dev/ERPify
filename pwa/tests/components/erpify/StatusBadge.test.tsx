import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { StatusBadge } from "@/components/erpify/StatusBadge";

describe("StatusBadge", () => {
  it("renders the supplied label without claiming a live region", () => {
    render(<StatusBadge variant="success" label="Active" testId="badge" />);
    expect(screen.getByTestId("badge")).toHaveTextContent("Active");
    // A badge is rendered once per row and never updates in place, so it must not be a live
    // region: `<output>` maps to role `status` in HTML-AAM, and N rows would declare N polite
    // regions competing with the ones that do announce.
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("renders an aria-hidden status dot alongside the label", () => {
    const { container } = render(<StatusBadge variant="warning" label="Pending" />);
    const dot = container.querySelector("span[aria-hidden='true']");
    expect(dot).toBeTruthy();
    expect(dot).toHaveClass("bg-status-dot-warning");
  });

  it("keeps the label neutral — hue lives only in the dot", () => {
    render(<StatusBadge variant="success" label="New" testId="badge" />);
    const badge = screen.getByTestId("badge");
    expect(badge).toHaveClass("text-muted-foreground");
    expect(badge.className).not.toContain("text-success");
  });

  it("never relies on color alone — the label is always rendered", () => {
    render(<StatusBadge variant="danger" label="Failed" />);
    expect(screen.getByText("Failed")).toBeInTheDocument();
  });

  it("supports neutral variant for non-semantic statuses", () => {
    render(<StatusBadge variant="neutral" label="Draft" testId="draft-badge" />);
    expect(screen.getByTestId("draft-badge")).toHaveTextContent("Draft");
  });

  it("forwards the testId prop to the rendered element", () => {
    render(<StatusBadge variant="info" label="New" testId="x-badge" />);
    expect(screen.getByTestId("x-badge")).toHaveTextContent("New");
  });
});
