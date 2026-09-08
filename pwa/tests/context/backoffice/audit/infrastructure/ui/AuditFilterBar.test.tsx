import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AuditFilterBar } from "@/context/backoffice/audit/infrastructure/ui/AuditFilterBar";
import { EMPTY_AUDIT_FILTER, type AuditFilter } from "@/app/backoffice/audit/_lib/auditFilter";

function renderBar(filterPatch: Partial<AuditFilter> = {}) {
  const onPatch = vi.fn();
  const onReset = vi.fn();
  render(
    <AuditFilterBar
      filter={{ ...EMPTY_AUDIT_FILTER, ...filterPatch }}
      onPatch={onPatch}
      onReset={onReset}
    />,
  );
  return { onPatch, onReset };
}

describe("AuditFilterBar", () => {
  it("commits a level segment immediately", () => {
    const { onPatch } = renderBar();
    fireEvent.click(screen.getByTestId("audit-filter-bar__level-security"));
    expect(onPatch).toHaveBeenCalledWith({ level: "security" });
  });

  it("reflects the selected level via aria-checked on the radiogroup", () => {
    renderBar({ level: "security" });
    expect(screen.getByTestId("audit-filter-bar__level-security")).toHaveAttribute(
      "aria-checked",
      "true",
    );
    expect(screen.getByTestId("audit-filter-bar__level-all")).toHaveAttribute(
      "aria-checked",
      "false",
    );
  });

  it("shows the panel badge counting only panel-hosted filters", () => {
    renderBar({ actorType: "user", action: "BANK" });
    expect(screen.getByTestId("audit-filter-bar__count")).toHaveTextContent("2");
  });

  it("offers Clear filters only when a filter is active, and fires onReset", () => {
    const { onReset } = renderBar({ level: "activity" });
    fireEvent.click(screen.getByTestId("audit-filter-bar__reset"));
    expect(onReset).toHaveBeenCalledTimes(1);
  });

  it("hides Clear filters when no filter is active", () => {
    renderBar();
    expect(screen.queryByTestId("audit-filter-bar__reset")).toBeNull();
  });

  it("toggles the advanced panel open", () => {
    renderBar();
    const toggle = screen.getByTestId("audit-filter-bar__toggle");
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(toggle);
    expect(toggle).toHaveAttribute("aria-expanded", "true");
  });

  it("commits actor type immediately on select", () => {
    const { onPatch } = renderBar();
    fireEvent.change(screen.getByTestId("audit-filter-bar__actor-type"), {
      target: { value: "api_key" },
    });
    expect(onPatch).toHaveBeenCalledWith({ actorType: "api_key" });
  });

  it("names the active correlation on screen and offers to clear just that axis", () => {
    // The row pivot is the only entry point for this axis — there is no panel control — so without
    // this the list filters with nothing on screen saying what filtered it.
    const { onPatch, onReset } = renderBar({
      correlationId: "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
    });

    expect(screen.getByTestId("audit-filter-bar__correlation")).toBeInTheDocument();
    expect(screen.getByText(/1c9666c9ab90$/)).toBeInTheDocument();

    fireEvent.click(screen.getByTestId("audit-filter-bar__correlation-clear"));
    expect(onPatch).toHaveBeenCalledWith({ correlationId: "" });
    expect(onReset).not.toHaveBeenCalled();
  });

  it("hides the correlation chip when the axis is empty", () => {
    renderBar();
    expect(screen.queryByTestId("audit-filter-bar__correlation")).toBeNull();
  });

  it("announces that filtering is active for an axis the panel count cannot see", () => {
    // `countPanelFilters` deliberately excludes `correlationId`, so the badge stays 0 here. A label
    // tied to that count alone announced a filtered list as a plain "Filters".
    renderBar({ correlationId: "019f0691-2aeb-7377-ba2d-1c9666c9ab90" });
    expect(screen.getByTestId("audit-filter-bar__toggle")).toHaveAttribute(
      "aria-label",
      "Filters, active",
    );
    expect(screen.queryByTestId("audit-filter-bar__count")).toBeNull();
  });

  it("keeps the panel count in the label when the panel itself holds the filters", () => {
    renderBar({ actorType: "api_key" });
    expect(screen.getByTestId("audit-filter-bar__toggle")).toHaveAttribute(
      "aria-label",
      "Filters, 1 active",
    );
  });
});
