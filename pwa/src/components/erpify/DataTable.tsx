"use client";

import {
  useCallback,
  useEffect,
  useId,
  useRef,
  useState,
  type KeyboardEvent,
  type MouseEvent,
  type ReactNode,
} from "react";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import { cn } from "@/lib/utils";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";
import type { ListDensity } from "./DensityToggle";

export const SelectionMode = {
  NONE: "none",
  SINGLE: "single",
  MULTI: "multi",
} as const;
export type SelectionMode = (typeof SelectionMode)[keyof typeof SelectionMode];

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
  /** Width budget applied to the matching `<col>` when the table uses fixed layout. */
  colClassName?: string;
}

export interface DataTableSort {
  columnId: string;
  direction: SortDirection;
}

export interface DataTableSelection {
  mode: SelectionMode;
  selected: ReadonlySet<string>;
  onChange: (next: Set<string>) => void;
}

interface DataTableProps<T> {
  columns: DataTableColumn<T>[];
  data: readonly T[];
  rowKey: (row: T) => string;
  density?: ListDensity;
  sort?: DataTableSort;
  onSortChange?: (sort: DataTableSort | null) => void;
  selection?: DataTableSelection;
  onRowActivate?: (row: T) => void;
  /**
   * Fired when `o` is pressed on a focused row — opens a lightweight peek of
   * the row's in-memory fields without navigating. Only wired when provided.
   */
  onRowPeek?: (rowId: string) => void;
  /** Caption announced by screen readers; required for a11y. */
  caption: string;
  /** Rendered when data is empty. */
  emptyState?: ReactNode;
  className?: string;
  /**
   * Required per-row data-testid (forwarded to the `<tr>`). Build it
   * from the entity id from the backend (e.g. `\`banks-table__row-${row.id}\``)
   * so each row is uniquely targetable from QA scripts. Returning a
   * non-unique value will trip Playwright's strict-mode locators.
   */
  rowTestId?: (row: T) => string;
  /** Optional data-testid on the surrounding wrapper (forwarded to the bordered `<div>`). */
  testId?: string;
  /**
   * Opt into `table-layout: fixed`. When set, a `<colgroup>` is emitted so each
   * column's `colClassName` width budget governs layout deterministically (and
   * cell `truncate` finally takes effect). Omit it for the byte-identical
   * auto-layout default.
   */
  layout?: "fixed";
}

const ROW_HEIGHTS: Record<ListDensity, string> = {
  compact: "h-9",
  comfortable: "h-11",
};

const HEADER_HEIGHTS: Record<ListDensity, string> = {
  compact: "h-9",
  comfortable: "h-10",
};

type AriaSort = "ascending" | "descending" | "none" | undefined;

function computeAriaSort(
  isSortable: boolean | undefined,
  isSorted: boolean,
  direction: SortDirection | undefined,
): AriaSort {
  if (!isSortable) return undefined;
  if (!isSorted) return "none";
  return direction === SortDirection.ASC ? "ascending" : "descending";
}

function computeTabIndex(interactive: boolean, isFocused: boolean): number | undefined {
  if (!interactive) return undefined;
  return isFocused ? 0 : -1;
}

/** Focus target for ArrowDown / ArrowUp row navigation, clamped to the page. */
function arrowTargetIndex(key: string, index: number, lastIndex: number): number {
  return key === KeyboardKey.ARROW_DOWN ? Math.min(index + 1, lastIndex) : Math.max(index - 1, 0);
}

function alignClass(align: DataTableColumn<unknown>["align"]): string | undefined {
  if (align === "right") return "text-right";
  if (align === "center") return "text-center";
  return "text-left";
}

/**
 * Controls inside a row (action buttons, links, the selection checkbox) are
 * themselves interactive. Selector for those descendants so a click / Enter
 * landing on one is NOT also interpreted as a row activation — this replaces
 * the per-cell `stopPropagation()` wrappers consumers would otherwise need,
 * which trip the "interactive handler on a non-interactive element" lint rule.
 */
const INTERACTIVE_DESCENDANT_SELECTOR =
  "a[href], button, input, select, textarea, [role='button'], [role='link'], [role='menuitem'], [role='checkbox']";

function isFromInteractiveControl(target: EventTarget | null, row: Element): boolean {
  if (!(target instanceof Element) || target === row) return false;
  const control = target.closest(INTERACTIVE_DESCENDANT_SELECTOR);
  // A control reached the row's handler because the event bubbled to it —
  // either through the DOM (a control nested in the row) or through the React
  // tree (a control inside a dialog / menu / popover that the row renders into
  // a portal on document.body). Both are "in-row" controls and must suppress
  // row activation. Testing `!control.contains(row)` covers both cases while
  // still activating when the only interactive ancestor wraps the whole table
  // (e.g. a DataTable nested inside a link), where `row.contains(control)`
  // would wrongly miss the portal case.
  return control != null && !control.contains(row);
}

interface DataTableHeadCellProps<T> {
  column: DataTableColumn<T>;
  sort?: DataTableSort;
  onHeaderSort: (columnId: string) => void;
}

function DataTableHeadCell<T>({ column, sort, onHeaderSort }: Readonly<DataTableHeadCellProps<T>>) {
  const isSorted = sort?.columnId === column.id;
  const ariaSort = computeAriaSort(column.sortable, isSorted, sort?.direction);

  return (
    <th
      scope="col"
      aria-sort={ariaSort}
      className={cn(
        "text-muted-foreground px-3 text-xs font-medium",
        alignClass(column.align),
        column.className,
      )}
    >
      {column.sortable ? (
        <button
          type="button"
          onClick={() => onHeaderSort(column.id)}
          className="hover:text-foreground inline-flex items-center gap-1 transition-colors"
        >
          {column.header}
          <SortIcon sorted={isSorted} direction={isSorted ? sort?.direction : undefined} />
        </button>
      ) : (
        column.header
      )}
    </th>
  );
}

interface DataTableBodyCellProps<T> {
  column: DataTableColumn<T>;
  row: T;
}

function DataTableBodyCell<T>({ column, row }: Readonly<DataTableBodyCellProps<T>>) {
  return (
    <td
      className={cn(
        "text-foreground px-3",
        column.align === "right" && "text-right",
        column.align === "center" && "text-center",
        column.mono && "font-mono text-xs",
        column.numeric && "tabular-nums text-right",
        column.className,
      )}
    >
      {column.cell(row)}
    </td>
  );
}

interface SelectionCellProps {
  selectionMode: typeof SelectionMode.SINGLE | typeof SelectionMode.MULTI;
  rowId: string;
  isSelected: boolean;
  onRowSelect: (id: string) => void;
}

function SelectionCell({
  selectionMode,
  rowId,
  isSelected,
  onRowSelect,
}: Readonly<SelectionCellProps>) {
  return (
    <td className="px-3">
      <input
        type={selectionMode === SelectionMode.SINGLE ? "radio" : "checkbox"}
        aria-label={`Select row ${rowId}`}
        checked={isSelected}
        onChange={() => onRowSelect(rowId)}
        onClick={(e) => e.stopPropagation()}
        className="border-border accent-primary size-4 cursor-pointer rounded"
      />
    </td>
  );
}

interface DataTableRowProps<T> {
  row: T;
  rowId: string;
  index: number;
  density: ListDensity;
  columns: DataTableColumn<T>[];
  selection?: DataTableSelection;
  isSelected: boolean;
  showSelectionColumn: boolean;
  interactive: boolean;
  focusedRow: number;
  setFocusedRow: (index: number) => void;
  rowTestId?: (row: T) => string;
  registerRowRef: (index: number, el: HTMLTableRowElement | null) => void;
  onClickRow?: (event: MouseEvent<HTMLTableRowElement>) => void;
  onKeyDownRow?: (e: KeyboardEvent<HTMLTableRowElement>) => void;
  onRowSelect: (id: string) => void;
}

function DataTableRow<T>({
  row,
  rowId,
  index,
  density,
  columns,
  selection,
  isSelected,
  showSelectionColumn,
  interactive,
  focusedRow,
  setFocusedRow,
  rowTestId,
  registerRowRef,
  onClickRow,
  onKeyDownRow,
  onRowSelect,
}: Readonly<DataTableRowProps<T>>) {
  const tabIndex = computeTabIndex(interactive, focusedRow === index);
  const ariaSelected = selection ? isSelected : undefined;

  return (
    <tr
      ref={(el) => registerRowRef(index, el)}
      tabIndex={tabIndex}
      aria-selected={ariaSelected}
      onKeyDown={onKeyDownRow}
      onClick={onClickRow}
      onFocus={() => setFocusedRow(index)}
      data-testid={rowTestId?.(row)}
      className={cn(
        "group/row border-border focus-visible:ring-ring border-b transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:outline-none motion-reduce:transition-none",
        ROW_HEIGHTS[density],
        interactive && "hover:bg-muted cursor-pointer",
        isSelected && "bg-row-selected",
      )}
    >
      {showSelectionColumn && selection ? (
        <SelectionCell
          selectionMode={
            selection.mode === SelectionMode.SINGLE ? SelectionMode.SINGLE : SelectionMode.MULTI
          }
          rowId={rowId}
          isSelected={isSelected}
          onRowSelect={onRowSelect}
        />
      ) : null}
      {columns.map((column) => (
        <DataTableBodyCell key={column.id} column={column} row={row} />
      ))}
    </tr>
  );
}

export function DataTable<T>({
  columns,
  data,
  rowKey,
  density = "compact",
  sort,
  onSortChange,
  selection,
  onRowActivate,
  onRowPeek,
  caption,
  emptyState,
  className,
  rowTestId,
  testId,
  layout,
}: Readonly<DataTableProps<T>>) {
  const tableId = useId();
  const [focusedRow, setFocusedRow] = useState(0);
  const rowRefs = useRef<Array<HTMLTableRowElement | null>>([]);
  // Explorer-style range selection (Shift+Arrow): `anchor` is the row the range
  // pivots on and `baseline` is the selection snapshot taken at the first
  // Shift+Arrow press. Each subsequent press recomputes `baseline ∪ range`, so
  // contracting only ever deselects what the range itself added — never the
  // pre-existing baseline. Both reset to null on any non-shift navigation or
  // toggle, re-armed lazily on the next Shift+Arrow.
  const rangeRef = useRef<{ anchor: number; baseline: ReadonlySet<string> } | null>(null);
  const rangeEmittedRef = useRef<ReadonlySet<string> | null>(null);
  const resetRange = useCallback(() => {
    rangeRef.current = null;
  }, []);

  // The cached anchor indexes into `data`: any external mutation of the row
  // slice (pagination, filtering, realtime deletes) invalidates it. Reset so
  // the next Shift+Arrow re-arms on fresh indices instead of selecting the
  // wrong rows — or walking past the end of a shrunk list.
  useEffect(() => {
    rangeRef.current = null;
  }, [data]);

  // An external selection change (bulk-bar Clear, remote pruning) also ends
  // the range; only the set extendRange itself emitted keeps the anchor alive.
  useEffect(() => {
    if (selection?.selected !== rangeEmittedRef.current) {
      rangeRef.current = null;
    }
  }, [selection?.selected]);

  const handleHeaderSort = useCallback(
    (columnId: string) => {
      if (!onSortChange) return;
      if (sort?.columnId !== columnId) {
        onSortChange({ columnId, direction: SortDirection.ASC });
        return;
      }
      if (sort.direction === SortDirection.ASC) {
        onSortChange({ columnId, direction: SortDirection.DESC });
        return;
      }
      onSortChange(null);
    },
    [onSortChange, sort],
  );

  const handleSelectAll = useCallback(() => {
    if (selection?.mode !== SelectionMode.MULTI) return;
    rangeRef.current = null;
    // Page-scoped toggle: a selection that survives pagination keeps its
    // off-page ids either way.
    const pageIds = data.map(rowKey);
    const next = new Set(selection.selected);
    const allOnPage = pageIds.length > 0 && pageIds.every((id) => next.has(id));
    if (allOnPage) {
      for (const id of pageIds) next.delete(id);
    } else {
      for (const id of pageIds) next.add(id);
    }
    selection.onChange(next);
  }, [selection, data, rowKey]);

  const handleRowSelect = useCallback(
    (id: string) => {
      if (!selection || selection.mode === SelectionMode.NONE) return;
      // A direct toggle (checkbox click / Space) ends any in-progress range.
      rangeRef.current = null;
      if (selection.mode === SelectionMode.SINGLE) {
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

  // Multi-select only: extend the range from the anchor to `toIndex`, applying
  // `baseline ∪ range`. Arming the anchor/baseline lazily on the first press
  // keeps the snapshot honest — it captures whatever was selected right before
  // the range started.
  const extendRange = useCallback(
    (fromIndex: number, toIndex: number) => {
      if (selection?.mode !== SelectionMode.MULTI) return;
      if (rangeRef.current === null) {
        rangeRef.current = { anchor: fromIndex, baseline: new Set(selection.selected) };
      }
      const { anchor, baseline } = rangeRef.current;
      const lo = Math.min(anchor, toIndex);
      const hi = Math.max(anchor, toIndex);
      const next = new Set(baseline);
      // Resolve ids from `data`, not the DOM refs, so the range is correct even
      // before the focus move repaints.
      for (let i = lo; i <= hi; i++) {
        next.add(rowKey(data[i]));
      }
      rangeEmittedRef.current = next;
      selection.onChange(next);
    },
    [selection, rowKey, data],
  );

  const handleRowKeyDown = useCallback(
    (event: KeyboardEvent<HTMLTableRowElement>, row: T, index: number) => {
      // A key pressed while an in-row control (action button, link, checkbox)
      // is focused belongs to that control, not the row.
      if (isFromInteractiveControl(event.target, event.currentTarget)) return;
      if (event.key === KeyboardKey.ARROW_DOWN || event.key === KeyboardKey.ARROW_UP) {
        event.preventDefault();
        const next = arrowTargetIndex(event.key, index, data.length - 1);
        // Shift extends the range (multi-select only); a plain move ends it.
        if (event.shiftKey && selection?.mode === SelectionMode.MULTI) {
          extendRange(index, next);
        } else {
          resetRange();
        }
        setFocusedRow(next);
        rowRefs.current[next]?.focus();
        return;
      }
      // Case-insensitive so CapsLock can't disable the peek; Shift stays
      // reserved for range selection.
      if (event.key.toLowerCase() === KeyboardKey.O && !event.shiftKey && onRowPeek) {
        // Prevent native typeahead / scroll side effects before peeking.
        event.preventDefault();
        resetRange();
        onRowPeek(rowKey(row));
        return;
      }
      if (event.key === KeyboardKey.ENTER) {
        event.preventDefault();
        resetRange();
        onRowActivate?.(row);
        return;
      }
      if (
        event.key === KeyboardKey.SPACE &&
        selection != null &&
        selection.mode !== SelectionMode.NONE
      ) {
        event.preventDefault();
        resetRange();
        handleRowSelect(rowKey(row));
      }
    },
    [data, onRowActivate, onRowPeek, selection, handleRowSelect, rowKey, extendRange, resetRange],
  );

  const registerRowRef = useCallback((index: number, el: HTMLTableRowElement | null) => {
    rowRefs.current[index] = el;
  }, []);

  if (data.length === 0 && emptyState) {
    return <>{emptyState}</>;
  }

  const showSelectionColumn = selection != null && selection.mode !== SelectionMode.NONE;
  // Count against the rows on THIS page: a selection that survives pagination
  // may hold ids that are not currently rendered.
  const selectedOnPage =
    selection?.mode === SelectionMode.MULTI
      ? data.filter((row) => selection.selected.has(rowKey(row))).length
      : 0;
  const allSelected = data.length > 0 && selectedOnPage === data.length;
  const someSelected = selectedOnPage > 0 && !allSelected;
  const interactive = Boolean(onRowActivate || selection);

  return (
    <div className={cn("border-border rounded-md border", className)} data-testid={testId}>
      <table
        id={tableId}
        role="table"
        className={cn("w-full border-collapse text-sm", layout === "fixed" && "table-fixed")}
        data-density={density}
      >
        <caption className="sr-only">{caption}</caption>
        {layout === "fixed" ? (
          <colgroup>
            {showSelectionColumn ? <col className="w-10" /> : null}
            {columns.map((col) => (
              <col key={col.id} className={col.colClassName} />
            ))}
          </colgroup>
        ) : null}
        <thead className="erpify-table__head bg-background sticky top-0 z-10">
          <tr className={cn("border-border border-b", HEADER_HEIGHTS[density])}>
            {showSelectionColumn && selection ? (
              <th scope="col" className="w-10 px-3 text-left">
                {selection.mode === SelectionMode.MULTI ? (
                  <SelectAllCheckbox
                    allSelected={allSelected}
                    someSelected={someSelected}
                    onChange={handleSelectAll}
                  />
                ) : null}
              </th>
            ) : null}
            {columns.map((col) => (
              <DataTableHeadCell
                key={col.id}
                column={col}
                sort={sort}
                onHeaderSort={handleHeaderSort}
              />
            ))}
          </tr>
        </thead>
        <tbody>
          {data.map((row, index) => {
            const id = rowKey(row);
            const isSelected = selection?.selected.has(id) ?? false;
            const onKeyDownRow = interactive
              ? (e: KeyboardEvent<HTMLTableRowElement>) => handleRowKeyDown(e, row, index)
              : undefined;
            const onClickRow = onRowActivate
              ? (event: MouseEvent<HTMLTableRowElement>) => {
                  // A click landing on an in-row control (action button, link,
                  // checkbox) activates that control, not the row.
                  if (isFromInteractiveControl(event.target, event.currentTarget)) return;
                  onRowActivate(row);
                }
              : undefined;
            return (
              <DataTableRow
                key={id}
                row={row}
                rowId={id}
                index={index}
                density={density}
                columns={columns}
                selection={selection}
                isSelected={isSelected}
                showSelectionColumn={showSelectionColumn}
                interactive={interactive}
                focusedRow={focusedRow}
                setFocusedRow={setFocusedRow}
                rowTestId={rowTestId}
                registerRowRef={registerRowRef}
                onClickRow={onClickRow}
                onKeyDownRow={onKeyDownRow}
                onRowSelect={handleRowSelect}
              />
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

interface SelectAllCheckboxProps {
  allSelected: boolean;
  someSelected: boolean;
  onChange: () => void;
}

/**
 * Header select-all with a real tri-state: the native checkbox only exposes
 * `aria-checked="mixed"` when the DOM `indeterminate` property is set — it is
 * not derivable from `checked`, hence the ref + effect.
 */
function SelectAllCheckbox({
  allSelected,
  someSelected,
  onChange,
}: Readonly<SelectAllCheckboxProps>) {
  const ref = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (ref.current) {
      ref.current.indeterminate = someSelected;
    }
  }, [someSelected]);

  return (
    <input
      ref={ref}
      type="checkbox"
      aria-label="Select all rows"
      checked={allSelected}
      onChange={onChange}
      className="border-border accent-primary size-4 cursor-pointer rounded"
    />
  );
}

function SortIcon({ sorted, direction }: Readonly<{ sorted: boolean; direction?: SortDirection }>) {
  if (!sorted) return <ArrowUpDown className="size-3" aria-hidden="true" />;
  return direction === SortDirection.ASC ? (
    <ArrowUp className="size-3" aria-hidden="true" />
  ) : (
    <ArrowDown className="size-3" aria-hidden="true" />
  );
}
