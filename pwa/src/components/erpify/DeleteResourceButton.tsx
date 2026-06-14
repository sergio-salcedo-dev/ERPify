"use client";

import { useState, type ReactElement, type ReactNode } from "react";
import { Ban, TriangleAlert, Trash2 } from "lucide-react";
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

/**
 * Optional precondition that blocks deletion. When `active`, the destructive
 * confirmation is replaced by a neutral, non-destructive guard view that never
 * issues the delete — an optimistic UX guard surfaced to the user; the backend
 * stays the authoritative guard. The caller owns the entity-specific copy and
 * recovery (`action`); this component stays entity-agnostic.
 */
export interface ResourceDeleteGuard {
  /** When true, render the guard view instead of the confirm-delete. */
  active: boolean;
  /** Dialog title for the guard view (e.g. "Can't delete bank"). */
  title: ReactNode;
  /** Why deletion is blocked; rendered in the dialog description. */
  description: ReactNode;
  /** Recovery action rendered beside Close (e.g. a "View accounts" link). */
  action?: ReactElement;
}

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
  /**
   * When provided and `active`, replaces the confirm-delete with a neutral
   * guard view that never issues the delete (e.g. a bank with associated
   * accounts).
   */
  guard?: ResourceDeleteGuard;
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
  guard,
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
      <DialogContent className="sm:max-w-md" data-testid={`${testIdPrefix}__delete-dialog`}>
        {guard?.active ? (
          <>
            <DialogHeader>
              <div className="flex items-start gap-3">
                <span
                  className="bg-muted text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-full"
                  aria-hidden="true"
                >
                  <Ban className="size-5" />
                </span>
                <div className="flex flex-1 flex-col gap-2">
                  <DialogTitle className="text-lg">{guard.title}</DialogTitle>
                  <DialogDescription
                    className="text-base leading-relaxed"
                    data-testid={`${testIdPrefix}__delete-guard-message`}
                  >
                    {guard.description}
                  </DialogDescription>
                </div>
              </div>
            </DialogHeader>

            <DialogFooter>
              <DialogClose
                render={
                  <Button variant="ghost" aria-label="Close" title="Close">
                    Close
                  </Button>
                }
              />
              {guard.action}
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <div className="flex items-start gap-3">
                <span
                  className="bg-destructive/10 text-destructive flex size-10 shrink-0 items-center justify-center rounded-full"
                  aria-hidden="true"
                >
                  <TriangleAlert className="size-5" />
                </span>
                <div className="flex flex-1 flex-col gap-2">
                  <DialogTitle className="text-lg">Delete {entityNoun}</DialogTitle>
                  <DialogDescription className="text-base leading-relaxed">
                    Are you sure you want to delete{" "}
                    <span className="text-foreground font-semibold break-words">
                      {resourceLabel}
                    </span>
                    ? This cannot be undone.
                  </DialogDescription>
                </div>
              </div>
            </DialogHeader>

            <DialogFooter>
              <DialogClose
                render={
                  <Button
                    variant="ghost"
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
                onClick={handleConfirm}
                disabled={submitting}
                data-icon={submitting ? "inline-start" : undefined}
                aria-label={`Confirm delete of ${entityNoun} ${resourceLabel}`}
                title={`Confirm delete of ${entityNoun} ${resourceLabel}`}
                data-testid={`${testIdPrefix}__delete-confirm`}
              >
                {submitting ? (
                  <>
                    <Spinner className="size-4" testId={`${testIdPrefix}__delete-spinner`} />
                    Deleting…
                  </>
                ) : (
                  "Delete"
                )}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
