import type { DataTableSort } from "@/components/erpify";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import type { DateTimeProvider } from "@/context/shared/date-time-provider/domain/DateTimeProvider";

export interface BanksFilter {
  name: string;
  shortName: string;
  /** ISO `yyyy-mm-dd` (native date-picker format). Empty string means "no lower bound". */
  createdFrom: string;
  /** ISO `yyyy-mm-dd` (native date-picker format). Empty string means "no upper bound". */
  createdTo: string;
}

export type BanksSort = DataTableSort | null;

/** Default sort: alphabetical by name, A → Z. */
export const DEFAULT_SORT: BanksSort = { columnId: "name", direction: SortDirection.ASC };

export type BanksSortableColumn = "shortName" | "name" | "createdAt" | "updatedAt";

/** The set of columns the user can sort by, paired with the labels shown in the filters panel. */
export const BANKS_SORTABLE_COLUMNS: ReadonlyArray<{ id: BanksSortableColumn; label: string }> = [
  { id: "shortName", label: "Code" },
  { id: "name", label: "Name" },
  { id: "createdAt", label: "Created" },
  { id: "updatedAt", label: "Updated" },
];

export function isDefaultSort(sort: BanksSort): boolean {
  if (!sort || !DEFAULT_SORT) return sort === DEFAULT_SORT;
  return sort.columnId === DEFAULT_SORT.columnId && sort.direction === DEFAULT_SORT.direction;
}

export const EMPTY_FILTER: BanksFilter = {
  name: "",
  shortName: "",
  createdFrom: "",
  createdTo: "",
};

/**
 * Number of populated fields among the panel-hosted filters (short name +
 * created range). The name search lives in the always-visible toolbar, so the
 * "Filters (n)" badge and the auto-open heuristic only count what a collapsed
 * panel would otherwise hide. Whitespace-only values count as inactive.
 */
export function countPanelFilters(filter: BanksFilter): number {
  let count = 0;
  if (filter.shortName.trim()) count += 1;
  if (filter.createdFrom.trim()) count += 1;
  if (filter.createdTo.trim()) count += 1;
  return count;
}

export function hasActivePanelFilter(filter: BanksFilter): boolean {
  return countPanelFilters(filter) > 0;
}

/**
 * True when ANY filter is populated — the toolbar name search included.
 * Drives the Reset button: Reset clears `name` too (state is shared, only
 * its placement moved to the toolbar), so it must be reachable when the
 * search is the only active filter. The badge keeps using the panel-only
 * count above.
 */
export function hasActiveFilter(filter: BanksFilter): boolean {
  return Boolean(filter.name.trim()) || hasActivePanelFilter(filter);
}

export type FilterChipKey = "name" | "shortName" | "created" | "sort";

export interface FilterChipDescriptor {
  key: FilterChipKey;
  /** Human label rendered inside the chip, e.g. `Code: ACM`. */
  label: string;
}

const SORT_ARROW: Record<SortDirection, string> = {
  [SortDirection.ASC]: "↑",
  [SortDirection.DESC]: "↓",
};

function formatCreatedChipLabel(filter: BanksFilter, dateTime: DateTimeProvider): string | null {
  // A bound earns a place in the label only when it resolves to a real date, so
  // a malformed value (reachable via external/URL state, never the min/max-
  // constrained pickers) is dropped instead of being echoed raw and breaking
  // the dd/mm/yyyy formatting of its siblings.
  const from = dateTime.parseISO(filter.createdFrom.trim());
  const to = dateTime.parseISO(filter.createdTo.trim());
  if (from && to) {
    // Present the span chronologically so an inverted pair never reads
    // "later – earlier".
    const inverted = dateTime.isAfter(from, to);
    const earlier = inverted ? to : from;
    const later = inverted ? from : to;
    return `Created: ${dateTime.formatToDate(earlier)} – ${dateTime.formatToDate(later)}`;
  }
  if (from) return `Created after: ${dateTime.formatToDate(from)}`;
  if (to) return `Created before: ${dateTime.formatToDate(to)}`;
  return null;
}

/**
 * Descriptors for the always-visible active-filter chip bar. Mirrors the same
 * "active" rules as the badge/Reset helpers above; the name search lives in the
 * toolbar but still earns a chip so the bar is a complete summary. The sort
 * chip appears for any drift from {@link DEFAULT_SORT}, including the user's
 * explicit "None" (`null`), which reads as "Unsorted".
 */
export function buildActiveFilterChips(
  filter: BanksFilter,
  sort: BanksSort,
  dateTime: DateTimeProvider,
): FilterChipDescriptor[] {
  const chips: FilterChipDescriptor[] = [];
  if (filter.name.trim()) chips.push({ key: "name", label: `Name: ${filter.name.trim()}` });
  if (filter.shortName.trim())
    chips.push({ key: "shortName", label: `Code: ${filter.shortName.trim()}` });

  const created = formatCreatedChipLabel(filter, dateTime);
  if (created) chips.push({ key: "created", label: created });

  if (!isDefaultSort(sort)) {
    if (sort) {
      const column = BANKS_SORTABLE_COLUMNS.find((c) => c.id === sort.columnId);
      chips.push({
        key: "sort",
        label: `Sorted: ${column?.label ?? sort.columnId} ${SORT_ARROW[sort.direction]}`,
      });
    } else {
      chips.push({ key: "sort", label: "Unsorted" });
    }
  }
  return chips;
}
