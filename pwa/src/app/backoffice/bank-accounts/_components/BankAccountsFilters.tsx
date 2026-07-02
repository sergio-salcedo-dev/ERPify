"use client";

import { useEffect, useId, useRef, useState, type ChangeEvent, type ReactNode } from "react";
import { Search, SlidersHorizontal } from "lucide-react";
import { useDebouncedValue } from "@/context/shared/search/infrastructure/useDebouncedValue";
import { useSlashFocus } from "@/context/shared/keyboard/infrastructure/useSlashFocus";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormField } from "@/components/erpify";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import {
  BANK_ACCOUNTS_SORTABLE_COLUMNS,
  DEFAULT_SORT,
  buildActiveFilterChips,
  countPanelFilters,
  hasActiveFilter,
  hasActivePanelFilter,
  isDefaultSort,
  type BankAccountsFilter,
  type BankAccountsSort,
  type BankAccountsSortableColumn,
  type FilterChipKey,
} from "../_lib/bankAccountsFilterSort";
import { BankAccountsActiveFilters } from "./BankAccountsActiveFilters";

interface BankAccountsFiltersProps {
  filter: BankAccountsFilter;
  onFilterChange: (next: BankAccountsFilter) => void;
  sort: BankAccountsSort;
  onSortChange: (next: BankAccountsSort) => void;
  onReset: () => void;
  /**
   * Initial open state. When omitted the panel starts collapsed unless a panel
   * filter is active or the sort drifted from its default — so a user landing
   * with pre-set panel filters / sort sees them immediately. The holder search
   * is always visible and never forces the panel open.
   */
  defaultOpen?: boolean;
  /**
   * Optional control rendered on the leading edge of the toolbar. The list
   * passes its view/density/column toggles here; they share the toolbar row
   * with the always-visible holder search and the Filters button from `sm` up.
   */
  leading?: ReactNode;
}

type PanelField = "alias" | "bankId";

const NONE_SORT_VALUE = "__none__" as const;
const FILTER_DEBOUNCE_MS = 300;

export function BankAccountsFilters({
  filter,
  onFilterChange,
  sort,
  onSortChange,
  onReset,
  defaultOpen,
  leading,
}: Readonly<BankAccountsFiltersProps>) {
  const panelId = useId();
  const [open, setOpen] = useState<boolean>(
    defaultOpen ?? (hasActivePanelFilter(filter) || !isDefaultSort(sort)),
  );
  const searchRef = useRef<HTMLInputElement>(null);
  const toggleRef = useRef<HTMLButtonElement>(null);
  const pendingToggleFocus = useRef(false);

  // Local mirror of the text filters so typing stays instant while the parent
  // re-filter is debounced. Synced back down when the parent changes the filter
  // externally (e.g. Reset), via the getDerivedState-during-render idiom.
  const [holderInput, setHolderInput] = useState(filter.holderName);
  const [aliasInput, setAliasInput] = useState(filter.alias);
  const [bankIdInput, setBankIdInput] = useState(filter.bankId);
  const [prevFilter, setPrevFilter] = useState(filter);

  if (prevFilter !== filter) {
    setPrevFilter(filter);
    setHolderInput(filter.holderName);
    setAliasInput(filter.alias);
    setBankIdInput(filter.bankId);
  }

  const debouncedHolder = useDebouncedValue(holderInput, FILTER_DEBOUNCE_MS);
  const debouncedAlias = useDebouncedValue(aliasInput, FILTER_DEBOUNCE_MS);
  const debouncedBankId = useDebouncedValue(bankIdInput, FILTER_DEBOUNCE_MS);

  useEffect(() => {
    if (
      debouncedHolder === filter.holderName &&
      debouncedAlias === filter.alias &&
      debouncedBankId === filter.bankId
    ) {
      return;
    }
    onFilterChange({
      holderName: debouncedHolder,
      alias: debouncedAlias,
      bankId: debouncedBankId,
    });
    // Only react to the debounced values; `filter` is read as the latest closure
    // value each render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedHolder, debouncedAlias, debouncedBankId]);

  useSlashFocus(searchRef);

  const setPanelInput = (field: PanelField, value: string): void => {
    if (field === "alias") setAliasInput(value);
    else setBankIdInput(value);
  };

  const clearLocalInputs = (): void => {
    setHolderInput("");
    setAliasInput("");
    setBankIdInput("");
  };

  const handleReset = (): void => {
    // Clear local input state too: relying on the parent→child sync alone misses
    // the case where Reset fires before the debounce propagated the typed value,
    // which would otherwise leave stale text and let the pending debounce
    // re-apply it. Clearing here also cancels that pending timer.
    clearLocalInputs();
    onReset();
  };

  const chips = buildActiveFilterChips(filter, sort);

  const willEmptyAfter = (nextFilter: BankAccountsFilter, nextSort: BankAccountsSort): boolean =>
    !hasActiveFilter(nextFilter) && isDefaultSort(nextSort);

  const handleClearAll = (): void => {
    pendingToggleFocus.current = true;
    handleReset();
  };

  const handleRemoveChip = (key: FilterChipKey): void => {
    let nextFilter = filter;
    let nextSort = sort;
    switch (key) {
      case "holderName":
        nextFilter = { ...filter, holderName: "" };
        setHolderInput("");
        break;
      case "alias":
        nextFilter = { ...filter, alias: "" };
        setAliasInput("");
        break;
      case "bankId":
        nextFilter = { ...filter, bankId: "" };
        setBankIdInput("");
        break;
      case "sort":
        nextSort = DEFAULT_SORT;
        break;
    }
    if (willEmptyAfter(nextFilter, nextSort)) {
      pendingToggleFocus.current = true;
    }
    if (nextSort !== sort) onSortChange(nextSort);
    if (nextFilter !== filter) onFilterChange(nextFilter);
  };

  const handleSortColumnChange = (event: ChangeEvent<HTMLSelectElement>): void => {
    const value = event.target.value;
    if (value === NONE_SORT_VALUE) {
      onSortChange(null);
      return;
    }
    const direction = sort?.direction ?? SortDirection.ASC;
    onSortChange({ columnId: value as BankAccountsSortableColumn, direction });
  };

  const handleSortDirectionChange = (event: ChangeEvent<HTMLSelectElement>): void => {
    if (!sort) return;
    onSortChange({ ...sort, direction: event.target.value as SortDirection });
  };

  const activeCount = countPanelFilters(filter);
  const hasActive = activeCount > 0;
  const sortDrift = !isDefaultSort(sort);
  // Reset clears the toolbar holder search too, so its visibility must track ALL
  // filters — not just the panel-only count behind the badge.
  const canReset = hasActiveFilter(filter) || sortDrift;
  const toggleLabel = hasActive ? `Filters, ${activeCount} in this panel` : "Filters";

  useEffect(() => {
    if (!canReset && pendingToggleFocus.current) {
      pendingToggleFocus.current = false;
      toggleRef.current?.focus();
    }
  }, [canReset]);

  const sortColumnValue = sort?.columnId ?? NONE_SORT_VALUE;
  const sortDirectionValue = sort?.direction ?? SortDirection.ASC;
  const sortDirectionDisabled = !sort;

  const selectClassName =
    "border-border bg-background text-foreground focus-visible:ring-ring h-9 w-full rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50";

  return (
    <section
      className="bank-accounts-filters"
      aria-label="Bank account filters and sort"
      data-testid="bank-accounts-filters"
      data-open={open ? "true" : "false"}
    >
      <div className="bank-accounts-filters__toolbar flex flex-col gap-3 sm:flex-row sm:items-stretch sm:justify-end">
        {leading ? (
          <div className="bank-accounts-filters__leading order-3 flex justify-end sm:order-1 sm:items-center sm:justify-start">
            {leading}
          </div>
        ) : null}
        <div className="bank-accounts-filters__search relative order-1 w-full sm:order-2 sm:w-64 sm:self-center">
          <Search
            className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
            aria-hidden="true"
          />
          <Input
            ref={searchRef}
            type="search"
            value={holderInput}
            onChange={(event) => setHolderInput(event.target.value)}
            placeholder="Search by holder…"
            aria-label="Search accounts by holder"
            title="Search accounts by holder (press / to focus)"
            className="bank-accounts-filters__search-input min-h-7 pl-8"
            data-testid="bank-accounts-filters__holder"
          />
        </div>
        <Button
          type="button"
          ref={toggleRef}
          variant="outline"
          size="sm"
          onClick={() => setOpen((prev) => !prev)}
          aria-expanded={open}
          aria-controls={panelId}
          aria-label={toggleLabel}
          title={toggleLabel}
          className="bank-accounts-filters__toggle order-2 h-auto min-h-7 w-full sm:order-3 sm:w-auto"
          data-testid="bank-accounts-filters__toggle"
          data-icon="inline-start"
        >
          <SlidersHorizontal className="size-3.5" aria-hidden="true" />
          <span>Filters</span>
          {hasActive ? (
            <span
              className="bank-accounts-filters__count bg-primary text-primary-foreground ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-medium"
              aria-hidden="true"
              data-testid="bank-accounts-filters__count"
            >
              {activeCount}
            </span>
          ) : null}
        </Button>
      </div>

      {canReset ? (
        <BankAccountsActiveFilters
          chips={chips}
          onRemove={handleRemoveChip}
          onClearAll={handleClearAll}
        />
      ) : null}

      {/*
        Animated expand/collapse via the grid-rows 0fr→1fr trick. INVARIANT:
        keep all box-model (padding / border / margin) on `__panel-fields`, never
        on this outer section or `__panel-inner`, so the collapsed panel renders
        at zero height.
      */}
      <section
        id={panelId}
        aria-label="Bank account filter fields"
        aria-hidden={!open}
        inert={open ? undefined : true}
        className="bank-accounts-filters__panel grid transition-[grid-template-rows] duration-200 ease-out"
        style={{ gridTemplateRows: open ? "1fr" : "0fr" }}
        data-testid="bank-accounts-filters__panel"
      >
        <div className="bank-accounts-filters__panel-inner overflow-hidden">
          <div className="bank-accounts-filters__panel-fields border-border bg-muted/20 mt-3 rounded-md border p-3 sm:p-4">
            <div className="bank-accounts-filters__grid grid grid-cols-1 gap-3 sm:grid-cols-2">
              <FormField name="bank-accounts-filters-alias" label="Alias">
                <Input
                  type="text"
                  value={aliasInput}
                  onChange={(event) => setPanelInput("alias", event.target.value)}
                  placeholder="e.g. Payroll"
                  data-testid="bank-accounts-filters__alias"
                />
              </FormField>
              <FormField name="bank-accounts-filters-bank-id" label="Bank ID">
                <Input
                  type="text"
                  value={bankIdInput}
                  onChange={(event) => setPanelInput("bankId", event.target.value)}
                  placeholder="Owning bank id"
                  autoComplete="off"
                  data-testid="bank-accounts-filters__bank-id"
                />
              </FormField>
            </div>

            <div
              className="bank-accounts-filters__sort mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2"
              data-testid="bank-accounts-filters__sort"
            >
              <FormField name="bank-accounts-filters-sort-by" label="Sort by">
                <select
                  className={selectClassName}
                  value={sortColumnValue}
                  onChange={handleSortColumnChange}
                  aria-label="Sort by"
                  title="Sort by"
                  data-testid="bank-accounts-filters__sort-by"
                >
                  <option value={NONE_SORT_VALUE}>None</option>
                  {BANK_ACCOUNTS_SORTABLE_COLUMNS.map((column) => (
                    <option key={column.id} value={column.id}>
                      {column.label}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField name="bank-accounts-filters-sort-direction" label="Direction">
                <select
                  className={selectClassName}
                  value={sortDirectionValue}
                  onChange={handleSortDirectionChange}
                  disabled={sortDirectionDisabled}
                  aria-label="Sort direction"
                  title="Sort direction"
                  data-testid="bank-accounts-filters__sort-direction"
                >
                  <option value={SortDirection.ASC}>Ascending</option>
                  <option value={SortDirection.DESC}>Descending</option>
                </select>
              </FormField>
            </div>
          </div>
        </div>
      </section>
    </section>
  );
}
