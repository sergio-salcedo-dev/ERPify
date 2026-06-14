"use client";

import { type ReactElement } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { DeleteBank } from "@/context/backoffice/bank/application/DeleteBank";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { DeleteResourceButton } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import { safeHref } from "@/lib/safeHref";
import { bankRoutes } from "../_lib/bankRoutes";

interface DeleteBankButtonProps {
  id: string;
  name: string;
  /**
   * Associated-account count for this bank (the read-model signal surfaced on
   * the list/detail). When `> 0` the destructive confirmation is replaced by a
   * neutral guard that points at the accounts surface and never issues the
   * `DELETE` — an optimistic UX guard; the backend stays the authoritative
   * guard (a stale `0` that races a `409 bank-in-use` is recovered upstream).
   */
  accountCount: number;
  /** When provided, runs after a successful delete instead of redirecting to the list. */
  onDeleted?: (id: string) => void;
  /**
   * Runs when the delete fails. The dialog closes itself and the owner surfaces
   * the problem in a persistent `<MutationError>` anchored to the mutation's
   * origin — errors never render inside the dialog (UX contract 2026-06-04).
   */
  onError: (problem: ProblemDetails) => void;
  /** Custom trigger; defaults to a destructive button with a Trash icon. */
  trigger?: ReactElement;
  triggerTestId?: string;
  /**
   * Controlled open state. When provided, the dialog is parent-controlled and
   * renders NO trigger of its own — used by the per-row `⋯` actions menu, where
   * a menu item (not a button) opens the confirmation. Omit for the standalone
   * uncontrolled button (detail page).
   */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
}

export function DeleteBankButton({
  id,
  name,
  accountCount,
  onDeleted,
  onError,
  trigger,
  triggerTestId = "banks-detail__delete-button",
  open,
  onOpenChange,
}: Readonly<DeleteBankButtonProps>) {
  const router = useRouter();

  return (
    <DeleteResourceButton
      id={id}
      resourceLabel={name}
      entityNoun="bank"
      testIdPrefix="banks-detail"
      onConfirmDelete={(bankId) => container.get<DeleteBank>("BackOfficeDeleteBank").run(bankId)}
      onDeleted={onDeleted}
      onSuccess={() => {
        router.push(bankRoutes.list);
        router.refresh();
      }}
      onError={onError}
      trigger={trigger}
      triggerTestId={triggerTestId}
      open={open}
      onOpenChange={onOpenChange}
      guard={{
        active: accountCount > 0,
        title: "Can't delete bank",
        description: (
          <>
            <span className="font-semibold">{name}</span> can&apos;t be deleted — {accountCount}{" "}
            associated {accountCount === 1 ? "account" : "accounts"}. Remove or reassign them first.
          </>
        ),
        action: (
          <Link
            href={safeHref(bankRoutes.accounts(id))}
            className={cn(buttonVariants({ size: "sm" }))}
            data-icon="inline-start"
            data-testid="banks-detail__delete-guard-view-accounts"
            aria-label="View accounts"
            title={`View accounts of bank ${name}`}
          >
            View accounts
          </Link>
        ),
      }}
    />
  );
}
