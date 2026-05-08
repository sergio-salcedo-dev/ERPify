"use client";

import type { ChangeEvent } from "react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormField } from "@/components/erpify";
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
  const update =
    (field: keyof BanksFilter) =>
    (event: ChangeEvent<HTMLInputElement>): void => {
      onFilterChange({ ...filter, [field]: event.target.value });
    };

  return (
    <section
      className="banks-filters border-border bg-muted/20 rounded-md border p-4"
      aria-label="Bank filters"
    >
      <div className="banks-filters__grid grid grid-cols-1 gap-3 md:grid-cols-4">
        <FormField name="banks-filters-name" label="Name">
          <Input
            type="text"
            value={filter.name}
            onChange={update("name")}
            placeholder="e.g. acme"
            data-testid="banks-filters__name"
          />
        </FormField>
        <FormField name="banks-filters-short-name" label="Short name">
          <Input
            type="text"
            value={filter.shortName}
            onChange={update("shortName")}
            placeholder="e.g. ACM"
            data-testid="banks-filters__short-name"
          />
        </FormField>
        <FormField name="banks-filters-created-from" label="Created from">
          <Input
            type="date"
            value={filter.createdFrom}
            onChange={update("createdFrom")}
            data-testid="banks-filters__created-from"
          />
        </FormField>
        <FormField name="banks-filters-created-to" label="Created to">
          <Input
            type="date"
            value={filter.createdTo}
            onChange={update("createdTo")}
            data-testid="banks-filters__created-to"
          />
        </FormField>
      </div>
      <div className="banks-filters__actions mt-3 flex justify-end">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={onReset}
          disabled={resetDisabled}
          data-testid="banks-filters__reset"
        >
          Reset
        </Button>
      </div>
    </section>
  );
}
