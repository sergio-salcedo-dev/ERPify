"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { BANKS_PAGE_SIZE_OPTIONS, type BanksPageSize } from "../_lib/paginate";

interface BanksPaginationProps {
  page: number;
  pageSize: BanksPageSize;
  hasPrev: boolean;
  hasNext: boolean;
  onPageChange: (next: number) => void;
  onPageSizeChange: (next: BanksPageSize) => void;
}

export function BanksPagination({
  page,
  pageSize,
  hasPrev,
  hasNext,
  onPageChange,
  onPageSizeChange,
}: BanksPaginationProps) {
  return (
    <nav
      className="banks-pagination mt-3 flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between"
      aria-label="Banks pagination"
      data-testid="banks-pagination"
    >
      <label className="banks-pagination__page-size text-muted-foreground flex items-center gap-2 text-xs">
        <span className="banks-pagination__page-size-label">Items per page</span>
        <select
          className="banks-pagination__page-size-select border-border bg-background text-foreground focus-visible:ring-ring h-7 rounded-md border px-2 text-xs focus-visible:ring-2 focus-visible:outline-none"
          value={pageSize}
          onChange={(event) => onPageSizeChange(Number(event.target.value) as BanksPageSize)}
          aria-label="Items per page"
          title="Items per page"
          data-testid="banks-pagination__page-size"
        >
          {BANKS_PAGE_SIZE_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </label>

      <div className="banks-pagination__controls flex items-center justify-end gap-2">
        {hasPrev ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="banks-pagination__prev"
            onClick={() => onPageChange(page - 1)}
            aria-label="Previous page"
            title="Previous page"
            data-testid="banks-pagination__prev"
            data-icon="inline-start"
          >
            <ChevronLeft className="size-3.5" aria-hidden="true" />
            <span className="hidden sm:inline">Prev</span>
          </Button>
        ) : null}
        <span
          className="banks-pagination__indicator text-muted-foreground min-w-[4.5rem] text-center text-xs"
          aria-live="polite"
          data-testid="banks-pagination__indicator"
        >
          Page {page}
        </span>
        {hasNext ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="banks-pagination__next"
            onClick={() => onPageChange(page + 1)}
            aria-label="Next page"
            title="Next page"
            data-testid="banks-pagination__next"
            data-icon="inline-end"
          >
            <span className="hidden sm:inline">Next</span>
            <ChevronRight className="size-3.5" aria-hidden="true" />
          </Button>
        ) : null}
      </div>
    </nav>
  );
}
