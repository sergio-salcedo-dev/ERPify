"use client";

import { Rows2, Rows4 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/context/shared/styling/infrastructure/classNames";

export type ListDensity = "compact" | "comfortable";

/** Shared localStorage key — one density preference across all entity lists. */
export const LIST_DENSITY_STORAGE_KEY = "erpify.list.density";

export function isListDensity(value: unknown): value is ListDensity {
  return value === "compact" || value === "comfortable";
}

interface DensityToggleProps {
  density: ListDensity;
  onDensityChange: (next: ListDensity) => void;
  /** Test id prefix; options render as `${testId}-compact` / `${testId}-comfortable`. */
  testId?: string;
}

interface DensityOption {
  value: ListDensity;
  label: string;
  Icon: typeof Rows4;
}

const OPTIONS: readonly DensityOption[] = [
  { value: "compact", label: "Compact density", Icon: Rows4 },
  { value: "comfortable", label: "Comfortable density", Icon: Rows2 },
];

/**
 * Density switch for entity lists (table and cards): padding changes, the
 * type size never does. The chosen mode persists per user via
 * `useStoredPreference(LIST_DENSITY_STORAGE_KEY, …)`.
 */
export function DensityToggle({ density, onDensityChange, testId }: Readonly<DensityToggleProps>) {
  return (
    // <fieldset> groups the option buttons natively (implicit group role) —
    // no ARIA role needed.
    <fieldset
      aria-label="List density"
      className="density-toggle border-border bg-card inline-flex items-center gap-1 rounded-md border p-0.5"
      data-testid={testId}
    >
      {OPTIONS.map(({ value, label, Icon }) => {
        const active = density === value;
        return (
          <Button
            key={value}
            type="button"
            variant={active ? "default" : "ghost"}
            size="icon-sm"
            aria-pressed={active}
            aria-label={label}
            title={label}
            onClick={() => onDensityChange(value)}
            className={cn(
              "density-toggle__option",
              !active && "text-muted-foreground hover:text-foreground",
            )}
            data-testid={testId ? `${testId}-${value}` : undefined}
          >
            <Icon className="size-3.5" aria-hidden="true" />
            <span className="sr-only">{label}</span>
          </Button>
        );
      })}
    </fieldset>
  );
}
