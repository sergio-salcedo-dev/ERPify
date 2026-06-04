"use client";

import { useEffect, useId, useRef, useState, type ChangeEvent, type ReactNode } from "react";
import { useDebouncedValue } from "@/lib/useDebouncedValue";
import { Search, SlidersHorizontal } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { DatePickerField, FormField } from "@/components/erpify";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import {
  BANKS_SORTABLE_COLUMNS,
  countPanelFilters,
  hasActivePanelFilter,
  isDefaultSort,
  type BanksFilter,
  type BanksSort,
  type BanksSortableColumn,
} from "../_lib/banksFilterSort";

interface BanksFiltersProps {
  filter: BanksFilter;
  onFilterChange: (next: BanksFilter) => void;
  sort: BanksSort;
  onSortChange: (next: BanksSort) => void;
  onReset: () => void;
  /**
   * Initial open state. When omitted the panel starts collapsed if no panel
   * filters are active and the sort is at its default — so a user landing on
   * the page with pre-set panel filters / sort (e.g. from future URL state)
   * sees them immediately. The name search is always visible and never forces
   * the panel open.
   */
  defaultOpen?: boolean;
  /**
   * Optional control rendered on the leading edge of the toolbar (left on
   * tablet/desktop, below the full-width Filters button on mobile). The banks
   * list passes its view/density toggles here; they share the toolbar row
   * with the always-visible name search and the Filters button from `sm` up.
   */
  leading?: ReactNode;
}

const NONE_SORT_VALUE = "__none__" as const;
const FILTER_DEBOUNCE_MS = 300;

export function BanksFilters({
  filter,
  onFilterChange,
  sort,
  onSortChange,
  onReset,
  defaultOpen,
  leading,
}: Readonly<BanksFiltersProps>) {
  const panelId = useId();
  const [open, setOpen] = useState<boolean>(
    defaultOpen ?? (hasActivePanelFilter(filter) || !isDefaultSort(sort)),
  );
  const searchRef = useRef<HTMLInputElement>(null);

  // Local mirror of the text filters so typing stays instant while the
  // (expensive) parent re-filter is debounced. Synced back down when the
  // parent changes the filter externally (e.g. the Reset button).
  //
  // We use the React getDerivedState-during-render idiom: store the previous
  // prop value as state, compare it to the new prop during render, and call
  // setState inline (not in an effect) so no double-render or lint error occurs.
  const [nameInput, setNameInput] = useState(filter.name);
  const [shortNameInput, setShortNameInput] = useState(filter.shortName);
  const [prevFilterName, setPrevFilterName] = useState(filter.name);
  const [prevFilterShortName, setPrevFilterShortName] = useState(filter.shortName);

  if (prevFilterName !== filter.name) {
    setPrevFilterName(filter.name);
    setNameInput(filter.name);
  }
  if (prevFilterShortName !== filter.shortName) {
    setPrevFilterShortName(filter.shortName);
    setShortNameInput(filter.shortName);
  }

  const debouncedName = useDebouncedValue(nameInput, FILTER_DEBOUNCE_MS);
  const debouncedShortName = useDebouncedValue(shortNameInput, FILTER_DEBOUNCE_MS);

  useEffect(() => {
    if (debouncedName === filter.name && debouncedShortName === filter.shortName) {
      return;
    }
    onFilterChange({ ...filter, name: debouncedName, shortName: debouncedShortName });
    // We intentionally only react to the debounced values; `filter` is read
    // as the latest closure value each render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedName, debouncedShortName]);

  // Documented DataTable keyboard contract: `/` focuses the list search.
  // Document-level so it works wherever focus rests on the page; inert while
  // the user is typing elsewhere or interacting with a transient layer.
  useEffect(() => {
    const handleSlash = (event: globalThis.KeyboardEvent): void => {
      if (event.key !== KeyboardKey.SLASH || event.defaultPrevented) return;
      const target = event.target;
      if (
        target instanceof HTMLElement &&
        target.closest(
          "input, textarea, select, [contenteditable='true'], [role='dialog'], [role='alertdialog'], [role='menu']",
        )
      ) {
        return;
      }
      event.preventDefault();
      searchRef.current?.focus();
    };
    document.addEventListener("keydown", handleSlash);
    return () => document.removeEventListener("keydown", handleSlash);
  }, []);

  const updateText =
    (field: "name" | "shortName") =>
    (event: ChangeEvent<HTMLInputElement>): void => {
      const next = event.target.value;
      if (field === "name") {
        setNameInput(next);
      } else {
        setShortNameInput(next);
      }
    };

  const handleReset = (): void => {
    // Clear local input state too: relying on the parent→child sync alone
    // misses the case where Reset fires before the debounce propagated the
    // typed value (parent filter.name is still ""), which would otherwise
    // leave the stale text in the box and let the pending debounce re-apply
    // it. Clearing the local state here also cancels that pending timer.
    setNameInput("");
    setShortNameInput("");
    onReset();
  };

  const updateDate =
    (field: "createdFrom" | "createdTo") =>
    (next: string): void => {
      onFilterChange({ ...filter, [field]: next });
    };

  const handleSortColumnChange = (event: ChangeEvent<HTMLSelectElement>): void => {
    const value = event.target.value;
    if (value === NONE_SORT_VALUE) {
      onSortChange(null);
      return;
    }
    const direction = sort?.direction ?? SortDirection.ASC;
    onSortChange({ columnId: value as BanksSortableColumn, direction });
  };

  const handleSortDirectionChange = (event: ChangeEvent<HTMLSelectElement>): void => {
    if (!sort) return;
    onSortChange({ ...sort, direction: event.target.value as SortDirection });
  };

  const activeCount = countPanelFilters(filter);
  const hasActive = activeCount > 0;
  const sortDrift = !isDefaultSort(sort);
  const canReset = hasActive || sortDrift;
  const toggleLabel = hasActive ? `Filters, ${activeCount} active` : "Filters";

  const sortColumnValue = sort?.columnId ?? NONE_SORT_VALUE;
  const sortDirectionValue = sort?.direction ?? SortDirection.ASC;
  const sortDirectionDisabled = !sort;

  const selectClassName =
    "border-border bg-background text-foreground focus-visible:ring-ring h-9 w-full rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50";

  return (
    <section
      className="banks-filters"
      aria-label="Bank filters and sort"
      data-testid="banks-filters"
      data-open={open ? "true" : "false"}
    >
      <div className="banks-filters__toolbar flex flex-col gap-3 sm:flex-row sm:items-stretch sm:justify-end">
        {leading ? (
          // Mobile: sits below the search + Filters stack, right-aligned to
          // mirror the previous standalone toggle row. Tablet+: leads the
          // right-aligned cluster (toggles, then search, then Filters).
          <div className="banks-filters__leading order-3 flex justify-end sm:order-1 sm:items-center sm:justify-start">
            {leading}
          </div>
        ) : null}
        <div className="banks-filters__search relative order-1 w-full sm:order-2 sm:w-64 sm:self-center">
          <Search
            className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
            aria-hidden="true"
          />
          <Input
            ref={searchRef}
            type="search"
            value={nameInput}
            onChange={updateText("name")}
            placeholder="Search by name…"
            aria-label="Search banks by name"
            title="Search banks by name (press / to focus)"
            className="banks-filters__search-input min-h-7 pl-8"
            data-testid="banks-filters__name"
          />
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setOpen((prev) => !prev)}
          aria-expanded={open}
          aria-controls={panelId}
          aria-label={toggleLabel}
          title={toggleLabel}
          className="banks-filters__toggle order-2 h-auto min-h-7 w-full sm:order-3 sm:w-auto"
          data-testid="banks-filters__toggle"
          data-icon="inline-start"
        >
          <SlidersHorizontal className="size-3.5" aria-hidden="true" />
          <span>Filters</span>
          {hasActive ? (
            <span
              className="banks-filters__count bg-primary text-primary-foreground ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-medium"
              aria-hidden="true"
              data-testid="banks-filters__count"
            >
              {activeCount}
            </span>
          ) : null}
        </Button>
      </div>

      {/*
        Animated expand/collapse via the grid-rows 0fr→1fr trick. INVARIANT:
        keep all box-model (padding / border / margin) on `__panel-fields`,
        never on this outer section or `__panel-inner`. The collapsed panel
        must render at zero height so the e2e `toBeHidden()` assertions hold.
      */}
      <section
        id={panelId}
        aria-label="Bank filter fields"
        aria-hidden={!open}
        inert={open ? undefined : true}
        className="banks-filters__panel grid transition-[grid-template-rows] duration-200 ease-out"
        style={{ gridTemplateRows: open ? "1fr" : "0fr" }}
        data-testid="banks-filters__panel"
      >
        <div className="banks-filters__panel-inner overflow-hidden">
          <div className="banks-filters__panel-fields border-border bg-muted/20 mt-3 rounded-md border p-3 sm:p-4">
            <div className="banks-filters__grid grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <FormField name="banks-filters-short-name" label="Short name">
                <Input
                  type="text"
                  value={shortNameInput}
                  onChange={updateText("shortName")}
                  placeholder="e.g. ACM"
                  data-testid="banks-filters__short-name"
                />
              </FormField>
              <DatePickerField
                name="banks-filters-created-from"
                label="Created from"
                value={filter.createdFrom}
                onChange={updateDate("createdFrom")}
                max={filter.createdTo || undefined}
                testId="banks-filters__created-from"
              />
              <DatePickerField
                name="banks-filters-created-to"
                label="Created to"
                value={filter.createdTo}
                onChange={updateDate("createdTo")}
                min={filter.createdFrom || undefined}
                testId="banks-filters__created-to"
              />
            </div>

            <div
              className="banks-filters__sort mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2"
              data-testid="banks-filters__sort"
            >
              <FormField name="banks-filters-sort-by" label="Sort by">
                <select
                  className={selectClassName}
                  value={sortColumnValue}
                  onChange={handleSortColumnChange}
                  aria-label="Sort by"
                  title="Sort by"
                  data-testid="banks-filters__sort-by"
                >
                  <option value={NONE_SORT_VALUE}>None</option>
                  {BANKS_SORTABLE_COLUMNS.map((column) => (
                    <option key={column.id} value={column.id}>
                      {column.label}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField name="banks-filters-sort-direction" label="Direction">
                <select
                  className={selectClassName}
                  value={sortDirectionValue}
                  onChange={handleSortDirectionChange}
                  disabled={sortDirectionDisabled}
                  aria-label="Sort direction"
                  title="Sort direction"
                  data-testid="banks-filters__sort-direction"
                >
                  <option value={SortDirection.ASC}>Ascending</option>
                  <option value={SortDirection.DESC}>Descending</option>
                </select>
              </FormField>
            </div>

            {canReset ? (
              <div className="banks-filters__actions mt-3 flex justify-end">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={handleReset}
                  aria-label="Reset filters and sort"
                  title="Reset filters and sort"
                  className="w-full sm:w-auto"
                  data-testid="banks-filters__reset"
                >
                  Reset
                </Button>
              </div>
            ) : null}
          </div>
        </div>
      </section>
    </section>
  );
}
