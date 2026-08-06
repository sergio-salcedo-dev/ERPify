"use client";

import { useRef, useState, type RefObject } from "react";
import { TriangleAlert } from "lucide-react";
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
} from "@/components/ui/dialog";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { RevokeInvitation } from "@/context/backoffice/user/application/RevokeInvitation";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

interface RevokeInvitationButtonProps {
  userId: string;
  email: string;
  /** Parent-controlled: the row's `⋯` menu item opens it, so this renders no trigger of its own. */
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Runs after the invitation is withdrawn; the owner re-reads the register. */
  onRevoked: () => void;
  /**
   * Runs when the withdrawal fails. The dialog closes itself and the owner surfaces the problem in a
   * persistent `<MutationError>` anchored to the mutation's origin — errors never render inside the dialog.
   */
  onError: (problem: ProblemDetails) => void;
  /**
   * Where focus goes after a successful revocation, because the control that opened the dialog does not
   * survive it — the row's status changes and the menu is gated on the old one. Only on success: a cancel
   * still returns to its trigger, and a failure belongs to the `<MutationError>` that takes focus itself.
   */
  focusOnRevoked: RefObject<HTMLElement | null>;
}

/**
 * Confirmation for withdrawing a pending invitation, following the destructive-action contract of
 * `<DeleteResourceButton>` without reusing it: that primitive speaks deletion in its title, its confirm label
 * and its failure toast, and a revocation deletes nobody — it retires a live accept token and moves the
 * identity to the terminal REVOKED state, where it stays visible in the register. Copy that says otherwise on
 * an irreversible control is the defect, so the shape is shared and the words are its own.
 */
export function RevokeInvitationButton({
  userId,
  email,
  open,
  onOpenChange,
  onRevoked,
  onError,
  focusOnRevoked,
}: Readonly<RevokeInvitationButtonProps>) {
  const [revoking, setRevoking] = useState(false);
  // Read once, when the closing dialog restores focus — a ref, not state, because the value is consulted
  // after the render that closed the dialog has already committed.
  const revokedRef = useRef(false);

  const onConfirm = async (): Promise<void> => {
    if (revoking) return;
    setRevoking(true);
    try {
      await container.get<RevokeInvitation>("BackOfficeRevokeInvitation").run(userId);
      revokedRef.current = true;
      onOpenChange(false);
      toastNotifier.success("Invitation revoked", { description: email });
      onRevoked();
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      revokedRef.current = false;
      onOpenChange(false);
      onError(err.problem);
      toastNotifier.error("Couldn't revoke the invitation — see error details");
    } finally {
      setRevoking(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md"
        finalFocus={() => (revokedRef.current ? focusOnRevoked.current : true)}
        data-testid="user-revoke-invitation__dialog"
      >
        <DialogHeader>
          <div className="flex items-start gap-3">
            <span
              className="bg-destructive/10 text-destructive flex size-10 shrink-0 items-center justify-center rounded-full"
              aria-hidden="true"
            >
              <TriangleAlert className="size-5" />
            </span>
            <div className="flex flex-1 flex-col gap-2">
              <DialogTitle className="text-lg">Revoke invitation</DialogTitle>
              <DialogDescription className="text-base leading-relaxed">
                The emailed link stops working immediately and{" "}
                <span className="text-foreground font-semibold break-words">{email}</span> is
                withdrawn as Revoked — they can never sign in with it. This cannot be undone.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <DialogFooter>
          <DialogClose
            render={
              <Button
                variant="ghost"
                disabled={revoking}
                aria-label="Cancel revocation"
                title="Cancel revocation"
                data-testid="user-revoke-invitation__cancel"
              >
                Cancel
              </Button>
            }
          />
          <Button
            variant="destructive"
            onClick={() => void onConfirm()}
            disabled={revoking}
            data-icon={revoking ? "inline-start" : undefined}
            aria-label="Confirm revocation"
            title={`Confirm revocation of the invitation of ${email}`}
            data-testid="user-revoke-invitation__confirm"
          >
            {revoking ? (
              <>
                <Spinner className="size-4" testId="user-revoke-invitation__spinner" />
                Revoking…
              </>
            ) : (
              "Revoke invitation"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
