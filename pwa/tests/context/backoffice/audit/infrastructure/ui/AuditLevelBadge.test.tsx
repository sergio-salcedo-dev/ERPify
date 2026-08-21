import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AuditLevelBadge } from "@/context/backoffice/audit/infrastructure/ui/AuditLevelBadge";

describe("AuditLevelBadge", () => {
  it("labels and styles the activity level with the neutral subtle dot", () => {
    const { container } = render(<AuditLevelBadge level="activity" />);
    expect(screen.getByText("Activity")).toBeInTheDocument();
    expect(container.querySelector(".bg-text-subtle")).not.toBeNull();
    expect(container.querySelector(".bg-security")).toBeNull();
  });

  it("styles the security level with the security dot (never danger)", () => {
    const { container } = render(<AuditLevelBadge level="security" />);
    expect(screen.getByText("Security")).toBeInTheDocument();
    expect(container.querySelector(".bg-security")).not.toBeNull();
    expect(container.querySelector(".bg-danger")).toBeNull();
  });

  it("is not a live region — one badge per timeline row would be one region per row", () => {
    render(<AuditLevelBadge level="security" />);
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("renders an unknown level verbatim with the neutral dot (forensic faithfulness)", () => {
    const { container } = render(<AuditLevelBadge level="mystery" />);
    expect(screen.getByText("mystery")).toBeInTheDocument();
    expect(container.querySelector(".bg-text-subtle")).not.toBeNull();
  });
});
