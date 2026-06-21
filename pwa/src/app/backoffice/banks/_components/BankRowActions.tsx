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
import { cn } from "@/context/shared/styling/infrastructure/classNames";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { bankRoutes } from "../_lib/bankRoutes";
import { DeleteBankButton } from "./DeleteBankButton";

type BankRowActionsSurface = "table" | "cards" | "stacked";

/**
 * Static class map per reveal scope — Tailwind cannot build variants from
 * template literals. Copy/Edit stay hidden at rest and reveal on hover or
 * keyboard focus within the enclosing row/card; coarse pointers always see
 * them (a hover affordance does not exist on touch).
 *
 * Reveal keys off `:focus-visible`, not `:focus-within`: the overflow (⋯) menu
 * restores focus to its trigger — which lives inside this row/card — when it
 * closes, so a `:focus-within` rule kept the cluster shown after a mouse
 * dismissal even though the pointer had left. `:focus-visible` matches only
 * keyboard focus, so the cluster reveals for keyboard users yet hides again
 * after a click-away. `group-focus-visible` covers a focused row `<tr>`;
 * `group-has-[:focus-visible]` covers a focused control (Copy/Edit/⋯) within.
 */
type BankRowActionsReveal = "row" | "card" | "none";

const REVEAL_CLASS: Record<BankRowActionsReveal, string> = {
  row: "flex items-center gap-0.5 opacity-0 transition-opacity group-hover/row:opacity-100 group-focus-visible/row:opacity-100 group-has-[:focus-visible]/row:opacity-100 motion-reduce:transition-none [@media(hover:none)]:opacity-100",
  card: "flex items-center gap-0.5 opacity-0 transition-opacity group-hover/card:opacity-100 group-focus-visible/card:opacity-100 group-has-[:focus-visible]/card:opacity-100 motion-reduce:transition-none [@media(hover:none)]:opacity-100",
  none: "flex items-center gap-0.5",
};

interface BankRowActionsProps {
  id: string;
  name: string;
  /** Drives the testid prefix (`banks-table__…` / `banks-cards__…`). */
  surface: BankRowActionsSurface;
  /** Associated-account count — drives the optimistic delete-guard (`> 0`). */
  accountCount: number;
  /** Hover/focus reveal scope for Copy/Edit; `⋯` is always visible. */
  reveal?: BankRowActionsReveal;
  onBankDeleted?: (id: string) => void;
  /** Failed delete → the page anchors the problem in its persistent error surface. */
  onBankDeleteFailed: (id: string, problem: ProblemDetails) => void;
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
  accountCount,
  reveal = "none",
  onBankDeleted,
  onBankDeleteFailed,
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
        accountCount={accountCount}
        onDeleted={onBankDeleted}
        onError={(problem) => onBankDeleteFailed(id, problem)}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
      />
    </div>
  );
}
