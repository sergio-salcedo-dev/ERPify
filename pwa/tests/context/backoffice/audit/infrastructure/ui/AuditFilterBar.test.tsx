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

  it("offers Limpiar filtros only when a filter is active, and fires onReset", () => {
    const { onReset } = renderBar({ level: "activity" });
    fireEvent.click(screen.getByTestId("audit-filter-bar__reset"));
    expect(onReset).toHaveBeenCalledTimes(1);
  });

  it("hides Limpiar filtros when no filter is active", () => {
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
});
