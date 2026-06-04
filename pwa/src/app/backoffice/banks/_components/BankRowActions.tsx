"use client";

import { useState } from "react";
import Link from "next/link";
import { MoreHorizontal, Pencil, Trash2 } from "lucide-react";
import { CopyButton } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";
import { safeHref } from "@/lib/safeHref";
import { bankRoutes } from "../_lib/bankRoutes";
import { DeleteBankButton } from "./DeleteBankButton";

type BankRowActionsSurface = "table" | "cards" | "stacked";

/**
 * Static class map per reveal scope — Tailwind cannot build variants from
 * template literals. Copy/Edit stay hidden at rest and reveal on hover or
 * focus-within of the enclosing row/card; coarse pointers always see them
 * (a hover affordance does not exist on touch).
 */
const REVEAL_CLASS = {
  row: "flex items-center gap-0.5 opacity-0 transition-opacity group-hover/row:opacity-100 group-focus-within/row:opacity-100 motion-reduce:transition-none [@media(hover:none)]:opacity-100",
  card: "flex items-center gap-0.5 opacity-0 transition-opacity group-hover/card:opacity-100 group-focus-within/card:opacity-100 motion-reduce:transition-none [@media(hover:none)]:opacity-100",
  none: "flex items-center gap-0.5",
} as const;

type BankRowActionsReveal = keyof typeof REVEAL_CLASS;

interface BankRowActionsProps {
  id: string;
  name: string;
  /** Drives the testid prefix (`banks-table__…` / `banks-cards__…`). */
  surface: BankRowActionsSurface;
  /** Hover/focus reveal scope for Copy/Edit; `⋯` is always visible. */
  reveal?: BankRowActionsReveal;
  onBankDeleted?: (id: string) => void;
  className?: string;
}

/**
 * Per-row action cluster shared by the banks table and cards. Copy ID and Edit
 * are kept as direct controls (high-frequency, non-destructive); the
 * destructive Delete is demoted into a `⋯` overflow menu so it is never a
 * mis-click away from Edit. The menu item opens a parent-controlled
 * `<DeleteBankButton>` confirmation dialog (a menu item cannot itself be a
 * dialog trigger without focus conflicts, hence the controlled-open handoff).
 */
export function BankRowActions({
  id,
  name,
  surface,
  reveal = "none",
  onBankDeleted,
  className,
}: Readonly<BankRowActionsProps>) {
  const [deleteOpen, setDeleteOpen] = useState(false);
  const prefix = `banks-${surface}`;

  return (
    <div className={cn("banks-row-actions flex items-center gap-0.5", className)}>
      <span className={cn("banks-row-actions__reveal", REVEAL_CLASS[reveal])}>
        <CopyButton
          value={id}
          iconOnly
          size="icon-sm"
          label="Copy ID"
          copiedLabel="ID copied"
          errorLabel="Copy failed"
          title={`Copy bank ${name} ID`}
          testId={`${prefix}__copy-${id}`}
        />
        <Link
          href={safeHref(bankRoutes.edit(id))}
          className={cn(buttonVariants({ variant: "ghost", size: "icon-sm" }))}
          aria-label="Edit"
          title={`Edit bank ${name}`}
          data-testid={`${prefix}__edit-${id}`}
        >
          <Pencil className="size-3.5" aria-hidden="true" />
          <span className="sr-only">Edit</span>
        </Link>
      </span>
      <DropdownMenu>
        <DropdownMenuTrigger
          render={
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label="More actions"
              title={`More actions for bank ${name}`}
              data-testid={`${prefix}__actions-${id}`}
            >
              <MoreHorizontal className="size-3.5" aria-hidden="true" />
              <span className="sr-only">More actions</span>
            </Button>
          }
        />
        <DropdownMenuContent align="end" className="min-w-36">
          <DropdownMenuItem
            variant="destructive"
            onClick={() => setDeleteOpen(true)}
            data-testid={`${prefix}__delete-${id}`}
          >
            <Trash2 aria-hidden="true" />
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <DeleteBankButton
        id={id}
        name={name}
        onDeleted={onBankDeleted}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
      />
    </div>
  );
}
