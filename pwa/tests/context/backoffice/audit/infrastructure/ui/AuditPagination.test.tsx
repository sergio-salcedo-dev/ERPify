import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AuditPagination } from "@/context/backoffice/audit/infrastructure/ui/AuditPagination";

function renderPagination(overrides: Partial<Parameters<typeof AuditPagination>[0]> = {}) {
  const props = {
    pageSize: 25 as const,
    hasPrev: true,
    hasNext: true,
    onPrev: vi.fn(),
    onNext: vi.fn(),
    onPageSizeChange: vi.fn(),
    ...overrides,
  };
  render(<AuditPagination {...props} />);
  return props;
}

describe("AuditPagination", () => {
  it("always renders the prev/next pair and disables a control with no target", () => {
    renderPagination({ hasPrev: false, hasNext: true });
    expect(screen.getByTestId("audit-pagination__prev")).toBeDisabled();
    expect(screen.getByTestId("audit-pagination__next")).toBeEnabled();
  });

  it("fires the navigation callbacks", () => {
    const props = renderPagination();
    fireEvent.click(screen.getByTestId("audit-pagination__prev"));
    fireEvent.click(screen.getByTestId("audit-pagination__next"));
    expect(props.onPrev).toHaveBeenCalledTimes(1);
    expect(props.onNext).toHaveBeenCalledTimes(1);
  });

  it("reports a chosen page size as a number", () => {
    const props = renderPagination();
    fireEvent.change(screen.getByTestId("audit-pagination__page-size"), {
      target: { value: "50" },
    });
    expect(props.onPageSizeChange).toHaveBeenCalledWith(50);
  });
});
