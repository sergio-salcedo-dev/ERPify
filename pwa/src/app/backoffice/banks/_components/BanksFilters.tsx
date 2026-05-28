"use client";

import { useId, useState, type ChangeEvent } from "react";
import { SlidersHorizontal } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { DatePickerField, FormField } from "@/components/erpify";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import {
  BANKS_SORTABLE_COLUMNS,
  countActiveFilters,
  hasActiveFilter,
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
   * Initial open state. When omitted the panel starts collapsed if no filters
   * are active and the sort is at its default — so a user landing on the page
   * with pre-set filters / sort (e.g. from future URL state) sees them
   * immediately.
   */
  defaultOpen?: boolean;
}

const NONE_SORT_VALUE = "__none__" as const;

export function BanksFilters({
  filter,
  onFilterChange,
  sort,
  onSortChange,
  onReset,
  defaultOpen,
}: Readonly<BanksFiltersProps>) {
  const panelId = useId();
  const [open, setOpen] = useState<boolean>(
    defaultOpen ?? (hasActiveFilter(filter) || !isDefaultSort(sort)),
  );

  const updateText =
    (field: "name" | "shortName") =>
    (event: ChangeEvent<HTMLInputElement>): void => {
      onFilterChange({ ...filter, [field]: event.target.value });
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

  const activeCount = countActiveFilters(filter);
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
      <div className="banks-filters__toolbar flex justify-end">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setOpen((prev) => !prev)}
          aria-expanded={open}
          aria-controls={panelId}
          aria-label={toggleLabel}
          title={toggleLabel}
          className="banks-filters__toggle w-full sm:w-auto"
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

      <section
        id={panelId}
        aria-label="Bank filter fields"
        hidden={!open}
        className="banks-filters__panel border-border bg-muted/20 mt-3 rounded-md border p-3 sm:p-4"
        data-testid="banks-filters__panel"
      >
        <div className="banks-filters__grid grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <FormField name="banks-filters-name" label="Name">
            <Input
              type="text"
              value={filter.name}
              onChange={updateText("name")}
              placeholder="e.g. acme"
              data-testid="banks-filters__name"
            />
          </FormField>
          <FormField name="banks-filters-short-name" label="Short name">
            <Input
              type="text"
              value={filter.shortName}
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
              onClick={onReset}
              aria-label="Reset filters and sort"
              title="Reset filters and sort"
              className="w-full sm:w-auto"
              data-testid="banks-filters__reset"
            >
              Reset
            </Button>
          </div>
        ) : null}
      </section>
    </section>
  );
}
