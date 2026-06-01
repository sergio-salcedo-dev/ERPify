"use client";

import { Search } from "lucide-react";
import { Button } from "@/components/ui/button";

interface BanksEmptyFilteredProps {
  onReset: () => void;
}

/**
 * Shown when banks exist but the active filters match none of them. Styled
 * distinctly (dashed border + muted background + search icon) so it reads as
 * a filtered-to-zero state rather than a data card or the first-run empty
 * state.
 */
export function BanksEmptyFiltered({ onReset }: Readonly<BanksEmptyFilteredProps>) {
  return (
    <section
      className="banks-list__empty-filtered border-border bg-muted/30 flex flex-col items-center gap-3 rounded-md border border-dashed p-8 text-center"
      data-testid="banks-list__empty-filtered"
    >
      <Search className="text-muted-foreground size-6" aria-hidden="true" />
      <div>
        <h2
          className="text-foreground text-base font-medium"
          data-testid="banks-list__empty-filtered-heading"
        >
          No banks match your filters
        </h2>
        <p
          className="text-muted-foreground mt-1 text-sm"
          data-testid="banks-list__empty-filtered-description"
        >
          Adjust the filters or clear them to see the full list.
        </p>
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="mt-1"
        onClick={onReset}
        title="Clear all bank filters"
        aria-label="Clear all bank filters"
        data-testid="banks-list__reset-filters"
      >
        Reset filters
      </Button>
    </section>
  );
}
