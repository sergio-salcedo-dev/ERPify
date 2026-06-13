"use client";

import { LayoutGrid, Rows3 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export type UsersView = "table" | "cards";

interface UsersViewToggleProps {
  view: UsersView;
  onViewChange: (next: UsersView) => void;
}

interface ViewOption {
  value: UsersView;
  label: string;
  Icon: typeof Rows3;
}

const OPTIONS: readonly ViewOption[] = [
  { value: "table", label: "Table view", Icon: Rows3 },
  { value: "cards", label: "Cards view", Icon: LayoutGrid },
];

export function UsersViewToggle({ view, onViewChange }: Readonly<UsersViewToggleProps>) {
  return (
    <fieldset
      aria-label="Users view"
      className="users-view-toggle inline-flex items-center gap-1 rounded-md border border-border bg-card p-0.5"
      data-testid="users-list__view-toggle"
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
              "users-view-toggle__option",
              !active && "text-muted-foreground hover:text-foreground",
            )}
            data-testid={`users-list__view-toggle-${value}`}
          >
            <Icon className="size-3.5" aria-hidden="true" />
            <span className="sr-only">{label}</span>
          </Button>
        );
      })}
    </fieldset>
  );
}
