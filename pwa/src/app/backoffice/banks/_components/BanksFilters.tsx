"use client";

import type { ChangeEvent } from "react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { DatePickerField, FormField } from "@/components/erpify";
import type { BanksFilter } from "../_lib/banksFilterSort";

interface BanksFiltersProps {
  filter: BanksFilter;
  onFilterChange: (next: BanksFilter) => void;
  onReset: () => void;
  /** Disables the Reset button. The parent owns "is anything resettable?" because sort lives outside this component. */
  resetDisabled?: boolean;
}

export function BanksFilters({
  filter,
  onFilterChange,
  onReset,
  resetDisabled,
}: BanksFiltersProps) {
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

  return (
    <section
      className="banks-filters border-border bg-muted/20 rounded-md border p-3 sm:p-4"
      aria-label="Bank filters"
      data-testid="banks-filters"
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
      <div className="banks-filters__actions mt-3 flex justify-end">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={onReset}
          disabled={resetDisabled}
          aria-label="Reset filters"
          title="Reset filters"
          className="w-full sm:w-auto"
          data-testid="banks-filters__reset"
        >
          Reset
        </Button>
      </div>
    </section>
  );
}
