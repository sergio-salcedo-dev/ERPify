"use client";

import type { ReactNode } from "react";
import { cn } from "@/components/cn";
import { DensityToggle, type ListDensity } from "./DensityToggle";
import type { ResourceView } from "./ResourceViewToggle";

interface ListDisplayTogglesProps {
  /** Current view — the column-picker slot only mounts in table view. */
  view: ResourceView;
  /** Entity-specific view switcher (table/cards), e.g. `<BanksViewToggle …/>`. */
  viewToggle: ReactNode;
  density: ListDensity;
  onDensityChange: (next: ListDensity) => void;
  /**
   * Entity-specific column-visibility picker, e.g. `<BanksColumnPicker …/>`.
   * Column visibility is a table-only affordance — the cards render a fixed
   * layout that never reads the preference — so this slot is gated to table
   * view *by construction*. A consumer cannot forget the gate, and it cannot
   * rot back into a dead no-op control in cards view (the failure mode that
   * originally shipped on two of the three lists).
   */
  columnPicker: ReactNode;
  /**
   * BEM/testid prefix scoping this instance (e.g. "banks-list"). Drives the
   * wrapper class `${prefix}__display-toggles` and the density-toggle testid
   * `${prefix}__density-toggle`; the passed toggles own their own testids.
   */
  testIdPrefix: string;
}

/**
 * The display-toggles cluster shared by every back-office list: view toggle +
 * density toggle + (table-only) column picker. Owning the cluster here makes
 * the table-only column-picker gate structural — a single primitive enforces
 * it for all consumers instead of each list re-implementing (and one of them
 * fumbling) a `view === "table"` check.
 */
export function ListDisplayToggles({
  view,
  viewToggle,
  density,
  onDensityChange,
  columnPicker,
  testIdPrefix,
}: Readonly<ListDisplayTogglesProps>) {
  return (
    <div
      className={cn(
        "list-display-toggles",
        `${testIdPrefix}__display-toggles`,
        "flex items-center gap-2",
      )}
      data-testid={`${testIdPrefix}__display-toggles`}
    >
      {viewToggle}
      <DensityToggle
        density={density}
        onDensityChange={onDensityChange}
        testId={`${testIdPrefix}__density-toggle`}
      />
      {view === "table" ? columnPicker : null}
    </div>
  );
}
