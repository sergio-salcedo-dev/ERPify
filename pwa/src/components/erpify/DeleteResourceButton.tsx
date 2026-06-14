"use client";

import { useState, type ReactElement } from "react";
import { Trash2 } from "lucide-react";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { Spinner } from "./Spinner";
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

export interface DeleteResourceButtonProps {
  id: string;
  /** Human label shown in the confirm copy and accessible names (e.g. a bank name or user email). */
  resourceLabel: string;
  /** Singular noun for the dialog title and toast (e.g. "bank"). */
  entityNoun: string;
  /** BEM/testid prefix scoping this instance (e.g. "banks-detail"). */
  testIdPrefix: string;
  /** Performs the actual delete; rejects with an HttpError on a mapped failure. */
  onConfirmDelete: (id: string) => Promise<void>;
  /** When provided, runs after a successful delete instead of `onSuccess`. */
  onDeleted?: (id: string) => void;
  /** Runs after a successful delete when `onDeleted` is absent (e.g. redirect to the list). */
  onSuccess?: () => void;
  /**
   * Runs when the delete fails. The dialog closes itself and the owner surfaces
   * the problem in a persistent `<MutationError>` anchored to the mutation's
   * origin — errors never render inside the dialog.
   */
  onError: (problem: ProblemDetails) => void;
  /** Custom trigger; defaults to a destructive button with a Trash icon. */
  trigger?: ReactElement;
  triggerTestId?: string;
  /** Controlled open state (used by the per-row actions menu). */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
}

/**
 * Entity-agnostic confirm-delete dialog. The owner supplies the delete
 * operation (`onConfirmDelete`) and the post-success behaviour
 * (`onDeleted`/`onSuccess`); on a mapped `HttpError` the dialog closes and the
 * problem is handed to `onError` for a persistent surface, never shown inline.
 */
export function DeleteResourceButton({
  id,
  resourceLabel,
  entityNoun,
  testIdPrefix,
  onConfirmDelete,
  onDeleted,
  onSuccess,
  onError,
  trigger,
  triggerTestId = `${testIdPrefix}__delete-button`,
  open: openProp,
  onOpenChange,
}: Readonly<DeleteResourceButtonProps>) {
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
      await onConfirmDelete(id);
      setOpen(false);
      if (onDeleted) {
        onDeleted(id);
        return;
      }
      onSuccess?.();
    } catch (err) {
      if (err instanceof HttpError) {
        setOpen(false);
        onError(err.problem);
        toastNotifier.error(`Couldn't delete ${entityNoun} — see error details`);
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
      aria-label={`Delete ${entityNoun} ${resourceLabel}`}
      title={`Delete ${entityNoun} ${resourceLabel}`}
    >
      <Trash2 className="size-3.5" aria-hidden="true" />
      Delete
    </Button>
  );

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      {isControlled ? null : <DialogTrigger render={trigger ?? defaultTrigger} />}
      <DialogContent data-testid={`${testIdPrefix}__delete-dialog`}>
        <DialogHeader>
          <DialogTitle>Delete {entityNoun}</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete <span className="font-semibold">{resourceLabel}</span>?
            This cannot be undone.
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
            aria-label={`Confirm delete of ${entityNoun} ${resourceLabel}`}
            title={`Confirm delete of ${entityNoun} ${resourceLabel}`}
            data-testid={`${testIdPrefix}__delete-confirm`}
          >
            {submitting ? (
              <>
                <Spinner className="size-3.5" testId={`${testIdPrefix}__delete-spinner`} />
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
