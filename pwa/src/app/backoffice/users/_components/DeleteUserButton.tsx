"use client";

import { useState, type ReactElement } from "react";
import { useRouter } from "next/navigation";
import { Trash2 } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import type { UserRepository } from "@/context/backoffice/user/domain/UserRepository";
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
import { userRoutes } from "../_lib/userRoutes";

interface DeleteUserButtonProps {
  id: string;
  email: string;
  /** When provided, runs after a successful delete instead of redirecting to the list. */
  onDeleted?: (id: string) => void;
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

export function DeleteUserButton({
  id,
  email,
  onDeleted,
  onError,
  trigger,
  triggerTestId = "users-detail__delete-button",
  open: openProp,
  onOpenChange,
}: Readonly<DeleteUserButtonProps>) {
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
      await container.get<UserRepository>("BackOfficeUserRepository").delete(id);
      setOpen(false);
      if (onDeleted) {
        onDeleted(id);
        return;
      }
      router.push(userRoutes.list);
      router.refresh();
    } catch (err) {
      if (err instanceof HttpError) {
        setOpen(false);
        onError(err.problem);
        toastNotifier.error("Couldn't delete user — see error details");
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
      aria-label={`Delete user ${email}`}
      title={`Delete user ${email}`}
    >
      <Trash2 className="size-3.5" aria-hidden="true" />
      Delete
    </Button>
  );

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      {isControlled ? null : <DialogTrigger render={trigger ?? defaultTrigger} />}
      <DialogContent data-testid="users-detail__delete-dialog">
        <DialogHeader>
          <DialogTitle>Delete user</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete <span className="font-semibold">{email}</span>? This
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
            aria-label={`Confirm delete of user ${email}`}
            title={`Confirm delete of user ${email}`}
            data-testid="users-detail__delete-confirm"
          >
            {submitting ? (
              <>
                <Spinner className="size-3.5" testId="users-detail__delete-spinner" />
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
