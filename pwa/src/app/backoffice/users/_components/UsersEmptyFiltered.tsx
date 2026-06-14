"use client";

import { Search } from "lucide-react";
import { Button } from "@/components/ui/button";

interface UsersEmptyFilteredProps {
  onReset: () => void;
}

/**
 * Shown when users exist but the active filters match none of them. Styled
 * distinctly (dashed border + muted background + search icon) so it reads as a
 * filtered-to-zero state rather than a data card or the first-run empty state.
 */
export function UsersEmptyFiltered({ onReset }: Readonly<UsersEmptyFilteredProps>) {
  return (
    <section
      className="users-list__empty-filtered border-border bg-muted/30 flex flex-col items-center gap-3 rounded-md border border-dashed p-8 text-center"
      data-testid="users-list__empty-filtered"
    >
      <Search className="text-muted-foreground size-6" aria-hidden="true" />
      <div>
        <h2
          className="text-foreground text-base font-medium"
          data-testid="users-list__empty-filtered-heading"
        >
          No users match your filters
        </h2>
        <p
          className="text-muted-foreground mt-1 text-sm"
          data-testid="users-list__empty-filtered-description"
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
        title="Clear all filters and sort"
        aria-label="Clear all filters and sort"
        data-testid="users-list__reset-filters"
      >
        Clear all
      </Button>
    </section>
  );
}
