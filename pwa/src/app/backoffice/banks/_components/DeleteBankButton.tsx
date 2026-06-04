"use client";

import { useState, type ReactElement } from "react";
import { useRouter } from "next/navigation";
import { Trash2 } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { DeleteBank } from "@/context/backoffice/bank/application/DeleteBank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { Spinner } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
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
  open: openProp,
  onOpenChange,
}: Readonly<DeleteBankButtonProps>) {
  const router = useRouter();
  const isControlled = openProp !== undefined;
  const [internalOpen, setInternalOpen] = useState(false);
  const open = isControlled ? openProp : internalOpen;
  const [submitting, setSubmitting] = useState(false);

  function setOpen(next: boolean): void {
    if (isControlled) {
      onOpenChange?.(next);
    } else {
      setInternalOpen(next);
    }
  }

  async function handleConfirm(): Promise<void> {
    if (submitting) return;
    setSubmitting(true);
    try {
      const useCase = container.get<DeleteBank>("BackOfficeDeleteBank");
      await useCase.run(id);
      setOpen(false);
      if (onDeleted) {
        onDeleted(id);
        return;
      }
      router.push(bankRoutes.list);
      router.refresh();
    } catch (err) {
      if (err instanceof HttpError) {
        // The error never lives in the dialog: close it, hand the problem to
        // the owner's persistent surface, and point at it with a transient toast.
        setOpen(false);
        onError(err.problem);
        toastNotifier.error("Couldn't delete bank — see error details");
        return;
      }
      throw err;
    } finally {
      setSubmitting(false);
    }
  }

  const defaultTrigger = (
    <Button
      variant="destructive"
      size="sm"
      data-icon="inline-start"
      data-testid={triggerTestId}
      aria-label={`Delete bank ${name}`}
      title={`Delete bank ${name}`}
    >
      <Trash2 className="size-3.5" aria-hidden="true" />
      Delete
    </Button>
  );

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      {isControlled ? null : <DialogTrigger render={trigger ?? defaultTrigger} />}
      <DialogContent data-testid="banks-detail__delete-dialog">
        <DialogHeader>
          <DialogTitle>Delete bank</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete <span className="font-semibold">{name}</span>? This
            cannot be undone.
          </DialogDescription>
        </DialogHeader>

        <DialogFooter>
          <DialogClose
            render={
              <Button
                variant="ghost"
                size="sm"
                disabled={submitting}
                aria-label="Cancel deletion"
                title="Cancel deletion"
              >
                Cancel
              </Button>
            }
          />
          <Button
            variant="destructive"
            size="sm"
            onClick={handleConfirm}
            disabled={submitting}
            data-icon={submitting ? "inline-start" : undefined}
            aria-label={`Confirm delete of bank ${name}`}
            title={`Confirm delete of bank ${name}`}
            data-testid="banks-detail__delete-confirm"
          >
            {submitting ? (
              <>
                <Spinner className="size-3.5" testId="banks-detail__delete-spinner" />
                Deleting…
              </>
            ) : (
              "Delete"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
