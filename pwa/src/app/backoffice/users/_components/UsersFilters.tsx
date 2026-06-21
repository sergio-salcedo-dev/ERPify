"use client";

import { useEffect, useId, useRef, useState, type ChangeEvent, type ReactNode } from "react";
import { useDebouncedValue } from "@/context/shared/search/infrastructure/useDebouncedValue";
import { useSlashFocus } from "@/context/shared/keyboard/infrastructure/useSlashFocus";
import { Search, SlidersHorizontal } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormField } from "@/components/erpify";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import { ALL_ROLES, type Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import {
  USERS_SORTABLE_COLUMNS,
  hasActiveFilter,
  isDefaultSort,
  type UsersFilter,
  type UsersSort,
} from "../_lib/usersFilterSort";
import { ROLE_LABEL, STATUS_LABEL } from "../_lib/userLabels";

interface UsersFiltersProps {
  filter: UsersFilter;
  onFilterChange: (next: UsersFilter) => void;
  sort: UsersSort;
  onSortChange: (next: UsersSort) => void;
  onReset: () => void;
  defaultOpen?: boolean;
  /** Optional leading control (view/density/column-picker). */
  leading?: ReactNode;
}

const NONE_SORT_VALUE = "__none__" as const;
const FILTER_DEBOUNCE_MS = 300;

function countPanelFilters(filter: UsersFilter): number {
  return (filter.role === "" ? 0 : 1) + (filter.status === "" ? 0 : 1);
}

export function UsersFilters({
  filter,
  onFilterChange,
  sort,
  onSortChange,
  onReset,
  defaultOpen,
  leading,
}: Readonly<UsersFiltersProps>) {
  const panelId = useId();
  const [open, setOpen] = useState<boolean>(
    defaultOpen ?? (countPanelFilters(filter) > 0 || !isDefaultSort(sort)),
  );
  const searchRef = useRef<HTMLInputElement>(null);

  // Local mirror of the email search so typing stays instant while the parent
  // re-filter is debounced; synced back when the parent changes it (Reset).
  const [emailInput, setEmailInput] = useState(filter.email);
  const [prevFilterEmail, setPrevFilterEmail] = useState(filter.email);
  if (prevFilterEmail !== filter.email) {
    setPrevFilterEmail(filter.email);
    setEmailInput(filter.email);
  }
  const debouncedEmail = useDebouncedValue(emailInput, FILTER_DEBOUNCE_MS);

  useEffect(() => {
    if (debouncedEmail === filter.email) return;
    onFilterChange({ ...filter, email: debouncedEmail });
    // Only react to the debounced value; `filter` is read fresh each render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedEmail]);

  useSlashFocus(searchRef);

  const handleReset = (): void => {
    setEmailInput("");
    onReset();
  };

  const handleRoleChange = (event: ChangeEvent<HTMLSelectElement>): void => {
    onFilterChange({ ...filter, role: event.target.value as Role | "" });
  };
  const handleStatusChange = (event: ChangeEvent<HTMLSelectElement>): void => {
    onFilterChange({ ...filter, status: event.target.value as UserStatus | "" });
  };

  const handleSortColumnChange = (event: ChangeEvent<HTMLSelectElement>): void => {
    const value = event.target.value;
    if (value === NONE_SORT_VALUE) {
      onSortChange(null);
      return;
    }
    const direction = sort?.direction ?? SortDirection.ASC;
    onSortChange({ columnId: value, direction });
  };
  const handleSortDirectionChange = (event: ChangeEvent<HTMLSelectElement>): void => {
    if (!sort) return;
    onSortChange({ ...sort, direction: event.target.value as SortDirection });
  };

  const activeCount = countPanelFilters(filter);
  const hasActive = activeCount > 0;
  const canReset = hasActiveFilter(filter) || !isDefaultSort(sort);
  const toggleLabel = hasActive ? `Filters, ${activeCount} in this panel` : "Filters";

  const sortColumnValue = sort?.columnId ?? NONE_SORT_VALUE;
  const sortDirectionValue = sort?.direction ?? SortDirection.ASC;

  const selectClassName =
    "border-border bg-background text-foreground focus-visible:ring-ring h-9 w-full rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50";

  return (
    <section
      className="users-filters"
      aria-label="User filters and sort"
      data-testid="users-filters"
      data-open={open ? "true" : "false"}
    >
      <div className="users-filters__toolbar flex flex-col gap-3 sm:flex-row sm:items-stretch sm:justify-end">
        {leading ? (
          <div className="users-filters__leading order-3 flex justify-end sm:order-1 sm:items-center sm:justify-start">
            {leading}
          </div>
        ) : null}
        <div className="users-filters__search relative order-1 w-full sm:order-2 sm:w-64 sm:self-center">
          <Search
            className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
            aria-hidden="true"
          />
          <Input
            ref={searchRef}
            type="search"
            value={emailInput}
            onChange={(event) => setEmailInput(event.target.value)}
            placeholder="Search by email…"
            aria-label="Search users by email"
            title="Search users by email (press / to focus)"
            className="users-filters__search-input min-h-7 pl-8"
            data-testid="users-filters__email"
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
          className="users-filters__toggle order-2 h-auto min-h-7 w-full sm:order-3 sm:w-auto"
          data-testid="users-filters__toggle"
          data-icon="inline-start"
        >
          <SlidersHorizontal className="size-3.5" aria-hidden="true" />
          <span>Filters</span>
          {hasActive ? (
            <span
              className="users-filters__count bg-primary text-primary-foreground ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-medium"
              aria-hidden="true"
              data-testid="users-filters__count"
            >
              {activeCount}
            </span>
          ) : null}
        </Button>
      </div>

      <section
        id={panelId}
        aria-label="User filter fields"
        aria-hidden={!open}
        inert={open ? undefined : true}
        className="users-filters__panel grid transition-[grid-template-rows] duration-200 ease-out"
        style={{ gridTemplateRows: open ? "1fr" : "0fr" }}
        data-testid="users-filters__panel"
      >
        <div className="users-filters__panel-inner overflow-hidden">
          <div className="users-filters__panel-fields border-border bg-muted/20 mt-3 rounded-md border p-3 sm:p-4">
            <div className="users-filters__grid grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <FormField name="users-filters-role" label="Role">
                <select
                  className={selectClassName}
                  value={filter.role}
                  onChange={handleRoleChange}
                  aria-label="Filter by role"
                  title="Filter by role"
                  data-testid="users-filters__role"
                >
                  <option value="">All roles</option>
                  {ALL_ROLES.map((role) => (
                    <option key={role} value={role}>
                      {ROLE_LABEL[role]}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField name="users-filters-status" label="Status">
                <select
                  className={selectClassName}
                  value={filter.status}
                  onChange={handleStatusChange}
                  aria-label="Filter by status"
                  title="Filter by status"
                  data-testid="users-filters__status"
                >
                  <option value="">All statuses</option>
                  {Object.values(UserStatus).map((status) => (
                    <option key={status} value={status}>
                      {STATUS_LABEL[status]}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField name="users-filters-sort-by" label="Sort by">
                <select
                  className={selectClassName}
                  value={sortColumnValue}
                  onChange={handleSortColumnChange}
                  aria-label="Sort by"
                  title="Sort by"
                  data-testid="users-filters__sort-by"
                >
                  <option value={NONE_SORT_VALUE}>None</option>
                  {USERS_SORTABLE_COLUMNS.map((column) => (
                    <option key={column.id} value={column.id}>
                      {column.label}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField name="users-filters-sort-direction" label="Direction">
                <select
                  className={selectClassName}
                  value={sortDirectionValue}
                  onChange={handleSortDirectionChange}
                  disabled={!sort}
                  aria-label="Sort direction"
                  title="Sort direction"
                  data-testid="users-filters__sort-direction"
                >
                  <option value={SortDirection.ASC}>Ascending</option>
                  <option value={SortDirection.DESC}>Descending</option>
                </select>
              </FormField>
            </div>

            {canReset ? (
              <div className="users-filters__actions mt-3 flex justify-end">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={handleReset}
                  title="Clear all filters and sort"
                  aria-label="Clear all filters and sort"
                  data-testid="users-filters__reset"
                >
                  Clear all
                </Button>
              </div>
            ) : null}
          </div>
        </div>
      </section>
    </section>
  );
}
