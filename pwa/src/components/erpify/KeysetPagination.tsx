"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";

/** Localizable text for a {@link KeysetPagination}; the visible button text and its (longer) accessible name are kept distinct on purpose. */
export interface KeysetPaginationLabels {
  /** Accessible name of the `<nav>` landmark. */
  nav: string;
  /** Visible label, `aria-label` and `title` of the page-size select. */
  pageSize: string;
  /** Previous control: short visible `text`, descriptive accessible `label`. */
  prev: { text: string; label: string };
  /** Next control: short visible `text`, descriptive accessible `label`. */
  next: { text: string; label: string };
}

interface KeysetPaginationProps<T extends number> {
  /** BEM block and `data-testid` prefix; each consuming surface passes its own (e.g. `banks-pagination`). */
  testId: string;
  labels: KeysetPaginationLabels;
  pageSize: T;
  pageSizeOptions: readonly T[];
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  onPageSizeChange: (next: T) => void;
}

/**
 * Cursor-only keyset pagination control shared by every server-driven list (banks, bank accounts,
 * audit). The prev/next pair is ALWAYS rendered and `disabled` when its envelope link is null, never
 * hidden — the documented D-A11y exception to the hide-when-no-target rule, governed by the keyset ADR.
 * There is no page number: keyset navigation is purely directional. Surfaces differ only in their
 * `testId` prefix, `labels` (language) and `pageSizeOptions`, so the markup and the a11y contract live
 * here once and each list consumes it through a thin typed wrapper.
 */
export function KeysetPagination<T extends number>({
  testId,
  labels,
  pageSize,
  pageSizeOptions,
  hasPrev,
  hasNext,
  onPrev,
  onNext,
  onPageSizeChange,
}: Readonly<KeysetPaginationProps<T>>) {
  return (
    <nav
      className={`${testId} mt-3 flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between`}
      aria-label={labels.nav}
      data-testid={testId}
    >
      <label
        className={`${testId}__page-size text-muted-foreground flex items-center gap-2 text-xs`}
      >
        <span className={`${testId}__page-size-label`}>{labels.pageSize}</span>
        <select
          className={`${testId}__page-size-select border-border bg-background text-foreground focus-visible:ring-ring h-7 rounded-md border px-2 text-xs focus-visible:ring-2 focus-visible:outline-none`}
          value={pageSize}
          onChange={(event) => onPageSizeChange(Number(event.target.value) as T)}
          aria-label={labels.pageSize}
          title={labels.pageSize}
          data-testid={`${testId}__page-size`}
        >
          {pageSizeOptions.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </label>

      <div className={`${testId}__controls flex items-center justify-end gap-2`}>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={`${testId}__prev`}
          onClick={onPrev}
          disabled={!hasPrev}
          aria-label={labels.prev.label}
          title={labels.prev.label}
          data-testid={`${testId}__prev`}
          data-icon="inline-start"
        >
          <ChevronLeft className="size-3.5" aria-hidden="true" />
          <span className="hidden sm:inline">{labels.prev.text}</span>
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={`${testId}__next`}
          onClick={onNext}
          disabled={!hasNext}
          aria-label={labels.next.label}
          title={labels.next.label}
          data-testid={`${testId}__next`}
          data-icon="inline-end"
        >
          <span className="hidden sm:inline">{labels.next.text}</span>
          <ChevronRight className="size-3.5" aria-hidden="true" />
        </Button>
      </div>
    </nav>
  );
}
