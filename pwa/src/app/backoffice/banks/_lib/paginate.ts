export const BANKS_PAGE_SIZE_OPTIONS = [25, 50, 100, 500, 1000] as const;
export type BanksPageSize = (typeof BANKS_PAGE_SIZE_OPTIONS)[number];
export const BANKS_PAGE_SIZE_DEFAULT: BanksPageSize = 25;

export interface PaginatedSlice<T> {
  rows: T[];
  page: number;
  hasPrev: boolean;
  hasNext: boolean;
}

export function paginate<T>(
  items: readonly T[],
  page: number,
  pageSize: number,
): PaginatedSlice<T> {
  const safePageSize = Number.isFinite(pageSize) ? Math.max(1, Math.floor(pageSize)) : 1;
  const safePage = Number.isFinite(page) ? Math.max(1, Math.floor(page)) : 1;
  const totalRows = items.length;
  const maxPage = Math.max(1, Math.ceil(totalRows / safePageSize));
  const clamped = Math.min(safePage, maxPage);
  const start = (clamped - 1) * safePageSize;
  const end = start + safePageSize;
  return {
    rows: items.slice(start, end),
    page: clamped,
    hasPrev: clamped > 1,
    hasNext: end < totalRows,
  };
}
