"use client";

import { type ReactElement } from "react";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { DeleteBank } from "@/context/backoffice/bank/application/DeleteBank";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { DeleteResourceButton } from "@/components/erpify";
import { bankRoutes } from "../_lib/bankRoutes";

interface DeleteBankButtonProps {
  id: string;
  name: string;
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
    />
  );
}
