"use client";

import { Columns3 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { TOGGLEABLE_COLUMNS, PINNED_COLUMNS, type UserColumnKey } from "../_lib/userColumns";

const LABEL: Record<UserColumnKey, string> = {
  email: "Email",
  roles: "Roles",
  status: "Status",
  createdAt: "Created",
  updatedAt: "Updated",
};

/**
 * Column visibility picker. `email` is pinned (rendered checked + disabled);
 * the rest toggle. Toggling keeps the menu open (`onSelect` preventDefault) so
 * the user can adjust several columns at once.
 */
export function UsersColumnPicker({
  visible,
  onChange,
  testId,
}: Readonly<{
  visible: UserColumnKey[];
  onChange: (n: UserColumnKey[]) => void;
  testId?: string;
}>) {
  const toggle = (key: UserColumnKey): void =>
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
            data-testid={`users-columns__${k}`}
          >
            {LABEL[k]}
          </DropdownMenuCheckboxItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
