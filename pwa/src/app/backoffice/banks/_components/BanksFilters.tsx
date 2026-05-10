"use client";

import { useId, useState, type ChangeEvent } from "react";
import { SlidersHorizontal } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { DatePickerField, FormField } from "@/components/erpify";
import { countActiveFilters, hasActiveFilter, type BanksFilter } from "../_lib/banksFilterSort";

interface BanksFiltersProps {
  filter: BanksFilter;
  onFilterChange: (next: BanksFilter) => void;
  onReset: () => void;
  /**
   * Initial open state. When omitted the panel starts collapsed if no filters
   * are active and expanded otherwise — so a user landing on the page with
   * pre-set filters (e.g. from future URL state) sees them immediately.
   */
  defaultOpen?: boolean;
}

export function BanksFilters({ filter, onFilterChange, onReset, defaultOpen }: BanksFiltersProps) {
  const panelId = useId();
  const [open, setOpen] = useState<boolean>(defaultOpen ?? hasActiveFilter(filter));

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

  const activeCount = countActiveFilters(filter);
  const hasActive = activeCount > 0;
  const toggleLabel = hasActive ? `Filters, ${activeCount} active` : "Filters";

  return (
    <section
      className="banks-filters"
      aria-label="Bank filters"
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

      <div
        id={panelId}
        role="region"
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
        {hasActive ? (
          <div className="banks-filters__actions mt-3 flex justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onReset}
              aria-label="Reset filters"
              title="Reset filters"
              className="w-full sm:w-auto"
              data-testid="banks-filters__reset"
            >
              Reset
            </Button>
          </div>
        ) : null}
      </div>
    </section>
  );
}
