import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { DataTable, SelectionMode } from "@/components/erpify/DataTable";
import type { DataTableColumn } from "@/components/erpify/DataTable";

interface Row {
  id: string;
  name: string;
}

const COLUMNS: DataTableColumn<Row>[] = [{ id: "name", header: "Name", cell: (row) => row.name }];

const ROWS: Row[] = [
  { id: "a", name: "Alpha" },
  { id: "b", name: "Beta" },
];

function renderTable(selected: Set<string>, onChange = vi.fn()) {
  render(
    <DataTable
      columns={COLUMNS}
      data={ROWS}
      rowKey={(row) => row.id}
      caption="Tri-state"
      selection={{ mode: SelectionMode.MULTI, selected, onChange }}
    />,
  );
  return onChange;
}

describe("DataTable — header tri-state checkbox", () => {
  it("is unchecked with no selection", () => {
    renderTable(new Set());
    expect(screen.getByLabelText("Select all rows")).not.toBeChecked();
    expect(screen.getByLabelText("Select all rows")).not.toBePartiallyChecked();
  });

  it("exposes mixed state when some but not all page rows are selected", () => {
    renderTable(new Set(["a"]));
    expect(screen.getByLabelText("Select all rows")).toBePartiallyChecked();
  });

  it("is fully checked when every page row is selected", () => {
    renderTable(new Set(["a", "b"]));
    expect(screen.getByLabelText("Select all rows")).toBeChecked();
  });

  it("toggles page rows only, preserving off-page ids in the selection", () => {
    const onChange = renderTable(new Set(["a", "b", "off-page-id"]));
    screen.getByLabelText("Select all rows").click();
    expect(onChange).toHaveBeenCalledWith(new Set(["off-page-id"]));
  });
});
