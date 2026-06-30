"use client";

import { useState } from "react";
import Link from "next/link";
import { MoreHorizontal, Pencil, Trash2 } from "lucide-react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { DeleteBankAccount } from "@/context/backoffice/bankaccount/application/DeleteBankAccount";
import type { BankAccountStatus } from "@/context/backoffice/bankaccount/domain/BankAccount";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { CopyButton, DeleteResourceButton } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { bankAccountRoutes } from "../_lib/bankAccountRoutes";

interface BankAccountRowActionsProps {
  id: string;
  bankId: string;
  /** Account holder — the human label for accessible names / confirm copy (never the IBAN, which is PII). */
  holderName: string;
  /** Drives the optimistic delete-guard: only a `CLOSED` account may be deleted. */
  status: BankAccountStatus;
  onAccountDeleted?: (id: string) => void;
  /** Failed delete → the page anchors the problem in its persistent error surface. */
  onAccountDeleteFailed: (id: string, problem: ProblemDetails) => void;
  className?: string;
}

/**
 * Per-row action cluster for the accounts table: Copy ID and Edit are kept as
 * direct controls (high-frequency, non-destructive); the destructive Delete is
 * demoted into a `⋯` overflow menu so it is never a mis-click from Edit. The
 * menu item opens a parent-controlled `<DeleteResourceButton>` whose guard
 * intercepts a non-`CLOSED` account (it never issues the `DELETE` — the backend
 * 409 `bank-account-not-closed` stays the authoritative guard for the race).
 */
export function BankAccountRowActions({
  id,
  bankId,
  holderName,
  status,
  onAccountDeleted,
  onAccountDeleteFailed,
  className,
}: Readonly<BankAccountRowActionsProps>) {
  const [deleteOpen, setDeleteOpen] = useState(false);

  return (
    <div className={cn("bank-accounts-row-actions flex items-center gap-0.5", className)}>
      <CopyButton
        value={id}
        iconOnly
        size="icon-sm"
        label="Copy ID"
        copiedLabel="ID copied"
        errorLabel="Copy failed"
        title={`Copy account ${holderName} ID`}
        testId={`bank-accounts-table__copy-${id}`}
      />
      <Link
        href={safeHref(bankAccountRoutes.edit(bankId, id))}
        className={cn(buttonVariants({ variant: "ghost", size: "icon-sm" }))}
        aria-label="Edit"
        title={`Edit account ${holderName}`}
        data-testid={`bank-accounts-table__edit-${id}`}
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
              title={`More actions for account ${holderName}`}
              data-testid={`bank-accounts-table__actions-${id}`}
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
            data-testid={`bank-accounts-table__delete-${id}`}
          >
            <Trash2 aria-hidden="true" />
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <DeleteResourceButton
        id={id}
        resourceLabel={holderName}
        entityNoun="account"
        testIdPrefix="bank-accounts-row"
        onConfirmDelete={(accountId) =>
          container.get<DeleteBankAccount>("BackOfficeDeleteBankAccount").run(accountId)
        }
        onDeleted={onAccountDeleted}
        onError={(problem) => onAccountDeleteFailed(id, problem)}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        guard={{
          active: status !== "CLOSED",
          title: "Can't delete account",
          description: (
            <>
              <span className="font-semibold">{holderName}</span> can&apos;t be deleted — only{" "}
              <span className="font-semibold">closed</span> accounts may be removed. Set its status
              to Closed first.
            </>
          ),
          action: (
            <Link
              href={safeHref(bankAccountRoutes.edit(bankId, id))}
              className={cn(buttonVariants({ size: "sm" }))}
              data-icon="inline-start"
              data-testid="bank-accounts-row__delete-guard-edit"
              aria-label="Edit account"
              title={`Edit account ${holderName}`}
            >
              Edit account
            </Link>
          ),
        }}
      />
    </div>
  );
}
