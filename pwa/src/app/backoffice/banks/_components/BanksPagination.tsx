"use client";

import { Button } from "@/components/ui/button";

interface BanksPaginationProps {
  page: number;
  totalPages: number;
  onPageChange: (next: number) => void;
}

export function BanksPagination({ page, totalPages, onPageChange }: BanksPaginationProps) {
  const onPrev = (): void => onPageChange(Math.max(1, page - 1));
  const onNext = (): void => onPageChange(Math.min(totalPages, page + 1));

  return (
    <nav
      className="banks-pagination mt-3 flex items-center justify-end gap-3"
      aria-label="Banks pagination"
    >
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="banks-pagination__prev"
        onClick={onPrev}
        disabled={page <= 1}
        aria-label="Previous page"
        data-testid="banks-pagination__prev"
      >
        Prev
      </Button>
      <span
        className="banks-pagination__indicator text-muted-foreground text-xs"
        aria-live="polite"
        data-testid="banks-pagination__indicator"
      >
        Page {page} of {totalPages}
      </span>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="banks-pagination__next"
        onClick={onNext}
        disabled={page >= totalPages}
        aria-label="Next page"
        data-testid="banks-pagination__next"
      >
        Next
      </Button>
    </nav>
  );
}
