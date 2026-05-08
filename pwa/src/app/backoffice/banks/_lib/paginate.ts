export const BANKS_PAGE_SIZE = 10;

export interface PaginatedSlice<T> {
  rows: T[];
  page: number;
  totalPages: number;
  totalRows: number;
}

export function paginate<T>(
  items: readonly T[],
  page: number,
  pageSize: number,
): PaginatedSlice<T> {
  const safePageSize = Number.isFinite(pageSize) ? Math.max(1, Math.floor(pageSize)) : 1;
  const safePage = Number.isFinite(page) ? Math.floor(page) : 1;
  const totalRows = items.length;
  const totalPages = Math.max(1, Math.ceil(totalRows / safePageSize));
  const clamped = Math.min(Math.max(1, safePage), totalPages);
  const start = (clamped - 1) * safePageSize;
  return {
    rows: items.slice(start, start + safePageSize),
    page: clamped,
    totalPages,
    totalRows,
  };
}
