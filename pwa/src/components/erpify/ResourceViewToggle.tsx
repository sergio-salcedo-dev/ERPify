"use client";

import { LayoutGrid, Rows3 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export type ResourceView = "table" | "cards";

interface ResourceViewToggleProps {
  view: ResourceView;
  onViewChange: (next: ResourceView) => void;
  /** Accessible name for the toggle group (e.g. "Banks view"). */
  ariaLabel: string;
  /** BEM/testid prefix scoping this instance (e.g. "banks-list"). */
  testIdPrefix: string;
}

interface ViewOption {
  value: ResourceView;
  label: string;
  Icon: typeof Rows3;
}

const OPTIONS: readonly ViewOption[] = [
  { value: "table", label: "Table view", Icon: Rows3 },
  { value: "cards", label: "Cards view", Icon: LayoutGrid },
];

/**
 * Entity-agnostic table/cards switcher. A `<fieldset>` groups the option
 * buttons natively (implicit group role), so no ARIA role is needed; the
 * caller supplies the accessible name and a testid prefix.
 */
export function ResourceViewToggle({
  view,
  onViewChange,
  ariaLabel,
  testIdPrefix,
}: Readonly<ResourceViewToggleProps>) {
  return (
    <fieldset
      aria-label={ariaLabel}
      className="resource-view-toggle inline-flex items-center gap-1 rounded-md border border-border bg-card p-0.5"
      data-testid={`${testIdPrefix}__view-toggle`}
    >
      {OPTIONS.map(({ value, label, Icon }) => {
        const active = view === value;
        return (
          <Button
            key={value}
            type="button"
            variant={active ? "default" : "ghost"}
            size="icon-sm"
            aria-pressed={active}
            aria-label={label}
            title={label}
            onClick={() => onViewChange(value)}
            className={cn(
              "resource-view-toggle__option",
              !active && "text-muted-foreground hover:text-foreground",
            )}
            data-testid={`${testIdPrefix}__view-toggle-${value}`}
          >
            <Icon className="size-3.5" aria-hidden="true" />
            <span className="sr-only">{label}</span>
          </Button>
        );
      })}
    </fieldset>
  );
}
