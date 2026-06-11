"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { BANKS_PAGE_SIZE_OPTIONS, type BanksPageSize } from "../_lib/paginate";

interface BanksPaginationProps {
  pageSize: BanksPageSize;
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  onPageSizeChange: (next: BanksPageSize) => void;
}

export function BanksPagination({
  pageSize,
  hasPrev,
  hasNext,
  onPrev,
  onNext,
  onPageSizeChange,
}: Readonly<BanksPaginationProps>) {
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

      {/* Cursor-only navigation: prev/next are ALWAYS rendered and disabled when
          there is no target (the envelope link is null), never hidden. A
          persistent, predictable pair is discoverable and keeps the control's
          DOM stable — the documented D-A11y exception to pwa/CLAUDE.md's
          hide-when-no-target rule (the ADR governs this scope). There is no page
          number to show, so the numbered indicator is gone. */}
      <div className="banks-pagination__controls flex items-center justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="banks-pagination__prev"
          onClick={onPrev}
          disabled={!hasPrev}
          aria-label="Previous page"
          title="Previous page"
          data-testid="banks-pagination__prev"
          data-icon="inline-start"
        >
          <ChevronLeft className="size-3.5" aria-hidden="true" />
          <span className="hidden sm:inline">Prev</span>
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="banks-pagination__next"
          onClick={onNext}
          disabled={!hasNext}
          aria-label="Next page"
          title="Next page"
          data-testid="banks-pagination__next"
          data-icon="inline-end"
        >
          <span className="hidden sm:inline">Next</span>
          <ChevronRight className="size-3.5" aria-hidden="true" />
        </Button>
      </div>
    </nav>
  );
}
