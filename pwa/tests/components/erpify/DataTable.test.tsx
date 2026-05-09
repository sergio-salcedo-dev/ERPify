import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { DataTable, type DataTableColumn } from "@/components/erpify/DataTable";

interface Bank {
  id: string;
  name: string;
  iban: string;
}

const banks: Bank[] = [
  { id: "1", name: "Acme Bank", iban: "AB00 0001" },
  { id: "2", name: "Beta Bank", iban: "BB00 0002" },
];

const columns: DataTableColumn<Bank>[] = [
  { id: "name", header: "Name", cell: (r) => r.name, sortable: true },
  { id: "iban", header: "IBAN", cell: (r) => r.iban, mono: true },
];

describe("DataTable", () => {
  it("renders columns and rows with the provided cells", () => {
    render(<DataTable columns={columns} data={banks} rowKey={(r) => r.id} caption="Banks" />);
    expect(screen.getByText("Name")).toBeInTheDocument();
    expect(screen.getByText("IBAN")).toBeInTheDocument();
    expect(screen.getByText("Acme Bank")).toBeInTheDocument();
    expect(screen.getByText("BB00 0002")).toBeInTheDocument();
  });

  it("announces the caption for assistive tech", () => {
    render(<DataTable columns={columns} data={banks} rowKey={(r) => r.id} caption="Bank list" />);
    expect(screen.getByRole("table", { name: "Bank list" })).toBeInTheDocument();
  });

  it("forwards `testId` to the wrapper and `rowTestId` to each <tr>", () => {
    render(
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(r) => r.id}
        caption="Banks"
        testId="banks-table__inner"
        rowTestId={(r) => `banks-table__row-${r.id}`}
      />,
    );
    expect(screen.getByTestId("banks-table__inner")).toBeInTheDocument();
    expect(screen.getByTestId("banks-table__row-1")).toHaveAttribute("data-row-id", "1");
    expect(screen.getByTestId("banks-table__row-2")).toHaveAttribute("data-row-id", "2");
  });

  it("toggles aria-sort on sortable header click via onSortChange", () => {
    const onSortChange = vi.fn();
    render(
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(r) => r.id}
        caption="Banks"
        onSortChange={onSortChange}
      />,
    );
    fireEvent.click(screen.getByRole("button", { name: /Name/ }));
    expect(onSortChange).toHaveBeenCalledWith({ columnId: "name", direction: "asc" });
  });

  it("cycles asc → desc → null on repeated sort clicks of the same column", () => {
    const onSortChange = vi.fn();
    const { rerender } = render(
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(r) => r.id}
        caption="Banks"
        sort={{ columnId: "name", direction: "asc" }}
        onSortChange={onSortChange}
      />,
    );
    fireEvent.click(screen.getByRole("button", { name: /Name/ }));
    expect(onSortChange).toHaveBeenLastCalledWith({ columnId: "name", direction: "desc" });

    rerender(
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(r) => r.id}
        caption="Banks"
        sort={{ columnId: "name", direction: "desc" }}
        onSortChange={onSortChange}
      />,
    );
    fireEvent.click(screen.getByRole("button", { name: /Name/ }));
    expect(onSortChange).toHaveBeenLastCalledWith(null);
  });

  it("renders the empty state when data is empty", () => {
    render(
      <DataTable
        columns={columns}
        data={[]}
        rowKey={(r) => r.id}
        caption="Empty"
        emptyState={<div data-testid="empty-slot">no banks</div>}
      />,
    );
    expect(screen.getByTestId("empty-slot")).toHaveTextContent("no banks");
  });

  it("invokes onRowActivate when a row is clicked", () => {
    const onRowActivate = vi.fn();
    render(
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(r) => r.id}
        caption="Banks"
        onRowActivate={onRowActivate}
      />,
    );
    fireEvent.click(screen.getByText("Acme Bank"));
    expect(onRowActivate).toHaveBeenCalledWith(banks[0]);
  });

  it("supports multi-selection via checkbox", () => {
    const onChange = vi.fn();
    render(
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(r) => r.id}
        caption="Banks"
        selection={{ mode: "multi", selected: new Set(), onChange }}
      />,
    );
    fireEvent.click(screen.getByLabelText("Select row 1"));
    expect(onChange).toHaveBeenCalledWith(new Set(["1"]));
  });

  it("supports keyboard activation via Enter", () => {
    const onRowActivate = vi.fn();
    render(
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(r) => r.id}
        caption="Banks"
        onRowActivate={onRowActivate}
      />,
    );
    const firstRow = screen.getByText("Acme Bank").closest("tr") as HTMLTableRowElement;
    firstRow.focus();
    fireEvent.keyDown(firstRow, { key: "Enter" });
    expect(onRowActivate).toHaveBeenCalledWith(banks[0]);
  });
});
