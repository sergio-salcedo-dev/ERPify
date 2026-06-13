"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { BANK_ACCOUNTS_PAGE_SIZE_OPTIONS, type BankAccountsPageSize } from "../_lib/paginate";

interface BankAccountsPaginationProps {
  pageSize: BankAccountsPageSize;
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  onPageSizeChange: (next: BankAccountsPageSize) => void;
}

export function BankAccountsPagination({
  pageSize,
  hasPrev,
  hasNext,
  onPrev,
  onNext,
  onPageSizeChange,
}: Readonly<BankAccountsPaginationProps>) {
  return (
    <nav
      className="bank-accounts-pagination mt-3 flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between"
      aria-label="Bank accounts pagination"
      data-testid="bank-accounts-pagination"
    >
      <label className="bank-accounts-pagination__page-size text-muted-foreground flex items-center gap-2 text-xs">
        <span className="bank-accounts-pagination__page-size-label">Items per page</span>
        <select
          className="bank-accounts-pagination__page-size-select border-border bg-background text-foreground focus-visible:ring-ring h-7 rounded-md border px-2 text-xs focus-visible:ring-2 focus-visible:outline-none"
          value={pageSize}
          onChange={(event) => onPageSizeChange(Number(event.target.value) as BankAccountsPageSize)}
          aria-label="Items per page"
          title="Items per page"
          data-testid="bank-accounts-pagination__page-size"
        >
          {BANK_ACCOUNTS_PAGE_SIZE_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </label>

      {/* Cursor-only navigation: prev/next are ALWAYS rendered and disabled when
          there is no target (the envelope link is null), never hidden — the
          documented D-A11y exception to the hide-when-no-target rule (the keyset
          ADR governs this scope). */}
      <div className="bank-accounts-pagination__controls flex items-center justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="bank-accounts-pagination__prev"
          onClick={onPrev}
          disabled={!hasPrev}
          aria-label="Previous page"
          title="Previous page"
          data-testid="bank-accounts-pagination__prev"
          data-icon="inline-start"
        >
          <ChevronLeft className="size-3.5" aria-hidden="true" />
          <span className="hidden sm:inline">Prev</span>
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="bank-accounts-pagination__next"
          onClick={onNext}
          disabled={!hasNext}
          aria-label="Next page"
          title="Next page"
          data-testid="bank-accounts-pagination__next"
          data-icon="inline-end"
        >
          <span className="hidden sm:inline">Next</span>
          <ChevronRight className="size-3.5" aria-hidden="true" />
        </Button>
      </div>
    </nav>
  );
}
