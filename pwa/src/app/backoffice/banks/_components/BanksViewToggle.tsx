"use client";

import { LayoutGrid, Rows3 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export type BanksView = "table" | "cards";

interface BanksViewToggleProps {
  view: BanksView;
  onViewChange: (next: BanksView) => void;
}

interface ViewOption {
  value: BanksView;
  label: string;
  Icon: typeof Rows3;
}

const OPTIONS: readonly ViewOption[] = [
  { value: "table", label: "Table view", Icon: Rows3 },
  { value: "cards", label: "Cards view", Icon: LayoutGrid },
];

export function BanksViewToggle({ view, onViewChange }: Readonly<BanksViewToggleProps>) {
  return (
    // This is a presentational toggle of icon buttons (not form controls), so
    // <fieldset> would be inappropriate. role="group" + aria-label gives
    // assistive tech the correct semantics here.
    <div
      role="group"
      aria-label="Banks view"
      className="banks-view-toggle inline-flex items-center gap-1 rounded-md border border-border bg-card p-0.5"
      data-testid="banks-list__view-toggle"
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
              "banks-view-toggle__option",
              !active && "text-muted-foreground hover:text-foreground",
            )}
            data-testid={`banks-list__view-toggle-${value}`}
          >
            <Icon className="size-3.5" aria-hidden="true" />
            <span className="sr-only">{label}</span>
          </Button>
        );
      })}
    </div>
  );
}
