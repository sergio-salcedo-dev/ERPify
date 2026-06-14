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
import { TOGGLEABLE_COLUMNS, PINNED_COLUMNS, type BankColumnKey } from "../_lib/bankColumns";

const LABEL: Record<BankColumnKey, string> = {
  shortName: "Code",
  name: "Name",
  status: "Status",
  accounts: "Accounts",
  updatedAt: "Updated",
  createdAt: "Created",
};

/**
 * Column visibility picker. `shortName` and `name` are pinned (rendered checked +
 * disabled); the rest toggle. Toggling keeps the menu open (`onSelect`
 * preventDefault) so the user can adjust several columns at once.
 */
export function BanksColumnPicker({
  visible,
  onChange,
  testId,
}: Readonly<{
  visible: BankColumnKey[];
  onChange: (n: BankColumnKey[]) => void;
  testId?: string;
}>) {
  const toggle = (key: BankColumnKey): void =>
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
          {PINNED_COLUMNS.map((k) => (
            <DropdownMenuCheckboxItem
              key={k}
              checked
              disabled
              aria-label={`${LABEL[k]} (always shown)`}
            >
              {LABEL[k]}
            </DropdownMenuCheckboxItem>
          ))}
          {TOGGLEABLE_COLUMNS.map((k) => (
            <DropdownMenuCheckboxItem
              key={k}
              checked={visible.includes(k)}
              onCheckedChange={() => toggle(k)}
              onSelect={(event) => event.preventDefault()}
              data-testid={`banks-columns__${k}`}
            >
              {LABEL[k]}
            </DropdownMenuCheckboxItem>
          ))}
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
