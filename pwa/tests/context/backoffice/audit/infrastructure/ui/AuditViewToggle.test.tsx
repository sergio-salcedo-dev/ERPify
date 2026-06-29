import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AuditViewToggle } from "@/context/backoffice/audit/infrastructure/ui/AuditViewToggle";
import { AuditView } from "@/app/backoffice/audit/_lib/auditFilter";

describe("AuditViewToggle", () => {
  it("switches to Timeline / Jornada when enabled", () => {
    const onViewChange = vi.fn();
    render(
      <AuditViewToggle view={AuditView.Timeline} onViewChange={onViewChange} journeyEnabled />,
    );
    fireEvent.click(screen.getByTestId("audit-view-toggle__journey"));
    expect(onViewChange).toHaveBeenCalledWith("journey");
  });

  it("gates Jornada behind a fixed actor: aria-disabled + a visible, described hint", () => {
    const onViewChange = vi.fn();
    render(
      <AuditViewToggle
        view={AuditView.Timeline}
        onViewChange={onViewChange}
        journeyEnabled={false}
      />,
    );
    const journey = screen.getByTestId("audit-view-toggle__journey");
    expect(journey).toHaveAttribute("aria-disabled", "true");
    // The "why" is visible (not hover-only) and linked for assistive tech.
    const hint = screen.getByTestId("audit-view-toggle__hint");
    expect(hint).toBeVisible();
    expect(journey).toHaveAttribute("aria-describedby", hint.id);
    // Clicking the gated control does nothing.
    fireEvent.click(journey);
    expect(onViewChange).not.toHaveBeenCalled();
  });
});
