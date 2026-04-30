"use client";

import { useCallback, useId, useRef, useState, type KeyboardEvent, type ReactNode } from "react";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import { cn } from "@/lib/utils";

export interface DataTableColumn<T> {
  id: string;
  header: string;
  cell: (row: T) => ReactNode;
  sortable?: boolean;
  align?: "left" | "right" | "center";
  /** Render this column in monospace (typical for IDs). */
  mono?: boolean;
  /** Apply tabular-nums (typical for numeric columns). */
  numeric?: boolean;
  className?: string;
}

export interface DataTableSort {
  columnId: string;
  direction: "asc" | "desc";
}

export interface DataTableSelection {
  mode: "none" | "single" | "multi";
  selected: ReadonlySet<string>;
  onChange: (next: Set<string>) => void;
}

interface DataTableProps<T> {
  columns: DataTableColumn<T>[];
  data: readonly T[];
  rowKey: (row: T) => string;
  density?: "compact" | "comfortable";
  sort?: DataTableSort;
  onSortChange?: (sort: DataTableSort | null) => void;
  selection?: DataTableSelection;
  onRowActivate?: (row: T) => void;
  /** Caption announced by screen readers; required for a11y. */
  caption: string;
  /** Rendered when data is empty. */
  emptyState?: ReactNode;
  className?: string;
}

const ROW_HEIGHTS = {
  compact: "h-9",
  comfortable: "h-11",
} as const;

const HEADER_HEIGHTS = {
  compact: "h-9",
  comfortable: "h-10",
} as const;

export function DataTable<T>({
  columns,
  data,
  rowKey,
  density = "compact",
  sort,
  onSortChange,
  selection,
  onRowActivate,
  caption,
  emptyState,
  className,
}: DataTableProps<T>) {
  const tableId = useId();
  const [focusedRow, setFocusedRow] = useState(0);
  const rowRefs = useRef<Array<HTMLTableRowElement | null>>([]);

  const handleHeaderSort = useCallback(
    (columnId: string) => {
      if (!onSortChange) return;
      if (sort?.columnId !== columnId) {
        onSortChange({ columnId, direction: "asc" });
        return;
      }
      if (sort.direction === "asc") {
        onSortChange({ columnId, direction: "desc" });
        return;
      }
      onSortChange(null);
    },
    [onSortChange, sort],
  );

  const handleSelectAll = useCallback(() => {
    if (!selection || selection.mode !== "multi") return;
    if (selection.selected.size === data.length) {
      selection.onChange(new Set());
    } else {
      selection.onChange(new Set(data.map(rowKey)));
    }
  }, [selection, data, rowKey]);

  const handleRowSelect = useCallback(
    (id: string) => {
      if (!selection || selection.mode === "none") return;
      if (selection.mode === "single") {
        selection.onChange(new Set([id]));
        return;
      }
      const next = new Set(selection.selected);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      selection.onChange(next);
    },
    [selection],
  );

  const handleRowKeyDown = useCallback(
    (event: KeyboardEvent<HTMLTableRowElement>, row: T, index: number) => {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        const next = Math.min(index + 1, data.length - 1);
        setFocusedRow(next);
        rowRefs.current[next]?.focus();
        return;
      }
      if (event.key === "ArrowUp") {
        event.preventDefault();
        const next = Math.max(index - 1, 0);
        setFocusedRow(next);
        rowRefs.current[next]?.focus();
        return;
      }
      if (event.key === "Enter") {
        event.preventDefault();
        onRowActivate?.(row);
        return;
      }
      if (event.key === " " && selection && selection.mode !== "none") {
        event.preventDefault();
        handleRowSelect(rowKey(row));
      }
    },
    [data.length, onRowActivate, selection, handleRowSelect, rowKey],
  );

  if (data.length === 0 && emptyState) {
    return <>{emptyState}</>;
  }

  const showSelectionColumn = selection && selection.mode !== "none";
  const allSelected =
    selection?.mode === "multi" && data.length > 0 && selection.selected.size === data.length;

  return (
    <div className={cn("border-border overflow-hidden rounded-md border", className)}>
      <table
        id={tableId}
        role="table"
        className="w-full border-collapse text-sm"
        data-density={density}
      >
        <caption className="sr-only">{caption}</caption>
        <thead className="bg-muted/40 sticky top-0">
          <tr className={cn("border-border border-b", HEADER_HEIGHTS[density])}>
            {showSelectionColumn ? (
              <th scope="col" className="w-10 px-3 text-left">
                {selection.mode === "multi" ? (
                  <input
                    type="checkbox"
                    aria-label="Select all rows"
                    checked={allSelected}
                    onChange={handleSelectAll}
                    className="border-border accent-primary size-4 cursor-pointer rounded"
                  />
                ) : null}
              </th>
            ) : null}
            {columns.map((col) => {
              const isSorted = sort?.columnId === col.id;
              const ariaSort = !col.sortable
                ? undefined
                : !isSorted
                  ? "none"
                  : sort.direction === "asc"
                    ? "ascending"
                    : "descending";
              return (
                <th
                  key={col.id}
                  scope="col"
                  aria-sort={ariaSort}
                  className={cn(
                    "text-muted-foreground px-3 text-xs font-medium",
                    col.align === "right" && "text-right",
                    col.align === "center" && "text-center",
                    !col.align && "text-left",
                  )}
                >
                  {col.sortable ? (
                    <button
                      type="button"
                      onClick={() => handleHeaderSort(col.id)}
                      className="hover:text-foreground inline-flex items-center gap-1 transition-colors"
                    >
                      {col.header}
                      <SortIcon
                        sorted={isSorted}
                        direction={isSorted ? sort.direction : undefined}
                      />
                    </button>
                  ) : (
                    col.header
                  )}
                </th>
              );
            })}
          </tr>
        </thead>
        <tbody>
          {data.map((row, index) => {
            const id = rowKey(row);
            const isSelected = selection?.selected.has(id) ?? false;
            const interactive = Boolean(onRowActivate || selection);
            return (
              <tr
                key={id}
                ref={(el) => {
                  rowRefs.current[index] = el;
                }}
                tabIndex={interactive ? (focusedRow === index ? 0 : -1) : undefined}
                aria-selected={selection ? isSelected : undefined}
                onKeyDown={interactive ? (e) => handleRowKeyDown(e, row, index) : undefined}
                onClick={onRowActivate ? () => onRowActivate(row) : undefined}
                onFocus={() => setFocusedRow(index)}
                className={cn(
                  "border-border focus-visible:ring-ring border-b transition-colors focus-visible:ring-2 focus-visible:outline-none",
                  ROW_HEIGHTS[density],
                  interactive && "hover:bg-muted/30 cursor-pointer",
                  isSelected && "bg-accent/40",
                )}
              >
                {showSelectionColumn ? (
                  <td className="px-3">
                    <input
                      type={selection.mode === "single" ? "radio" : "checkbox"}
                      aria-label={`Select row ${id}`}
                      checked={isSelected}
                      onChange={() => handleRowSelect(id)}
                      onClick={(e) => e.stopPropagation()}
                      className="border-border accent-primary size-4 cursor-pointer rounded"
                    />
                  </td>
                ) : null}
                {columns.map((col) => (
                  <td
                    key={col.id}
                    className={cn(
                      "text-foreground px-3",
                      col.align === "right" && "text-right",
                      col.align === "center" && "text-center",
                      col.mono && "font-mono text-xs",
                      col.numeric && "tabular-nums text-right",
                      col.className,
                    )}
                  >
                    {col.cell(row)}
                  </td>
                ))}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function SortIcon({ sorted, direction }: { sorted: boolean; direction?: "asc" | "desc" }) {
  if (!sorted) return <ArrowUpDown className="size-3" aria-hidden="true" />;
  return direction === "asc" ? (
    <ArrowUp className="size-3" aria-hidden="true" />
  ) : (
    <ArrowDown className="size-3" aria-hidden="true" />
  );
}
