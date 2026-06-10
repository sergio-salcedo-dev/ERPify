"use client";

import { useEffect, useRef } from "react";
import { X } from "lucide-react";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import type { FilterChipDescriptor, FilterChipKey } from "../_lib/banksFilterSort";

interface BanksActiveFiltersProps {
  chips: ReadonlyArray<FilterChipDescriptor>;
  /** Remove a single filter by chip key. */
  onRemove: (key: FilterChipKey) => void;
  /** Clear every filter + reset sort. */
  onClearAll: () => void;
}

/**
 * Always-visible summary of the active filters/sort: one removable chip each,
 * plus a single Clear all. Purely presentational — `BanksFilters` owns the
 * filter state and passes the chip descriptors + callbacks.
 *
 * Focus after a per-chip removal stays inside the bar: the next chip's ✕, or
 * Clear all when the removed chip was the last one. The bar-emptied case (no
 * chips left) is handled by the parent, which focuses the Filters toggle.
 */
export function BanksActiveFilters({
  chips,
  onRemove,
  onClearAll,
}: Readonly<BanksActiveFiltersProps>) {
  // Keyed by chip.key (NOT index): React reconciles chips by key, so an
  // index-keyed ref array would null out the wrong slot when a middle chip
  // unmounts. We record the removed chip's position, then after re-render focus
  // whatever chip now occupies that slot (the "next" one), or Clear all.
  const removeRefs = useRef<Map<FilterChipKey, HTMLButtonElement>>(new Map());
  const clearAllRef = useRef<HTMLButtonElement>(null);
  const pendingFocusIndex = useRef<number | null>(null);

  const handleRemove = (index: number, key: FilterChipKey): void => {
    pendingFocusIndex.current = index;
    onRemove(key);
  };

  useEffect(() => {
    const index = pendingFocusIndex.current;
    if (index === null) return;
    pendingFocusIndex.current = null;
    // `chips` is the post-removal array: the chip that followed the removed one
    // now sits at `index`. Focus its ✕; if the removed chip was last, focus
    // Clear all.
    const nextChip = chips[index];
    const next = nextChip ? removeRefs.current.get(nextChip.key) : undefined;
    (next ?? clearAllRef.current)?.focus();
  }, [chips]);

  return (
    <section
      className="banks-filters__active mt-3 flex flex-wrap items-center gap-2"
      aria-label="Active filters"
      data-testid="banks-filters__active"
    >
      {chips.map((chip, index) => (
        <span
          key={chip.key}
          className="banks-filters__chip border-border bg-muted/40 text-foreground inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs"
        >
          {chip.label}
          <button
            type="button"
            ref={(el) => {
              if (el) removeRefs.current.set(chip.key, el);
              else removeRefs.current.delete(chip.key);
            }}
            onClick={() => handleRemove(index, chip.key)}
            className="banks-filters__chip-remove text-muted-foreground hover:text-foreground focus-visible:ring-ring -mr-0.5 inline-flex size-4 items-center justify-center rounded-full focus-visible:ring-2 focus-visible:outline-none"
            aria-label={`Remove filter ${chip.label}`}
            title={`Remove filter ${chip.label}`}
            data-testid={`banks-filters__chip-${chip.key}`}
          >
            <X className="size-3" aria-hidden="true" />
          </button>
        </span>
      ))}
      <button
        type="button"
        ref={clearAllRef}
        onClick={onClearAll}
        aria-label="Clear all filters and sort"
        title="Clear all filters and sort"
        className={cn(
          buttonVariants({ variant: "outline", size: "sm" }),
          "banks-filters__clear-all min-h-7",
        )}
        data-testid="banks-filters__clear-all"
      >
        Clear all
      </button>
    </section>
  );
}
