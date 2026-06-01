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

type BankRowActionsSurface = "table" | "cards";

interface BankRowActionsProps {
  id: string;
  name: string;
  /** Drives the testid prefix (`banks-table__…` / `banks-cards__…`). */
  surface: BankRowActionsSurface;
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
  onBankDeleted,
  className,
}: Readonly<BankRowActionsProps>) {
  const [deleteOpen, setDeleteOpen] = useState(false);
  const prefix = `banks-${surface}`;

  return (
    <div className={cn("banks-row-actions flex items-center gap-0.5", className)}>
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
