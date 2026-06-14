"use client";

import { Columns3 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

interface ResourceColumnPickerProps<K extends string> {
  /** Currently visible column keys. */
  visible: K[];
  onChange: (next: K[]) => void;
  /** Display label per column key. */
  labels: Record<K, string>;
  /** Always-visible columns (rendered checked + disabled). */
  pinned: readonly K[];
  /** Columns the user can show/hide. */
  toggleable: readonly K[];
  /** testid prefix for per-column items, e.g. "banks-columns" → `banks-columns__status`. */
  itemTestIdPrefix: string;
  /** testid for the trigger button. */
  testId?: string;
}

/**
 * Entity-agnostic column visibility picker. Pinned columns render checked +
 * disabled; the rest toggle. Toggling keeps the menu open (`onSelect`
 * preventDefault) so the user can adjust several columns at once.
 */
export function ResourceColumnPicker<K extends string>({
  visible,
  onChange,
  labels,
  pinned,
  toggleable,
  itemTestIdPrefix,
  testId,
}: Readonly<ResourceColumnPickerProps<K>>) {
  const toggle = (key: K): void =>
    onChange(visible.includes(key) ? visible.filter((k) => k !== key) : [...visible, key]);

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button
            variant="outline"
            size="sm"
            aria-label="Choose columns"
            title="Choose visible columns"
            data-testid={testId}
            data-icon="inline-start"
          >
            <Columns3 className="size-3.5" aria-hidden="true" />
            Columns
          </Button>
        }
      />
      <DropdownMenuContent align="end" className="w-44">
        <DropdownMenuGroup>
          <DropdownMenuLabel>Visible columns</DropdownMenuLabel>
          <DropdownMenuSeparator />
          {pinned.map((k) => (
            <DropdownMenuCheckboxItem
              key={k}
              checked
              disabled
              aria-label={`${labels[k]} (always shown)`}
            >
              {labels[k]}
            </DropdownMenuCheckboxItem>
          ))}
          {toggleable.map((k) => (
            <DropdownMenuCheckboxItem
              key={k}
              checked={visible.includes(k)}
              onCheckedChange={() => toggle(k)}
              onSelect={(event) => event.preventDefault()}
              data-testid={`${itemTestIdPrefix}__${k}`}
            >
              {labels[k]}
            </DropdownMenuCheckboxItem>
          ))}
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
