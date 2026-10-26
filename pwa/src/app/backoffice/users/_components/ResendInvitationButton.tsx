"use client";

import { useRef, useState, type RefObject } from "react";
import { Send } from "lucide-react";
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
import { ResendInvitation } from "@/context/backoffice/user/application/ResendInvitation";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

interface ResendInvitationButtonProps {
  userId: string;
  email: string;
  /** Parent-controlled: the row's `⋯` menu item opens it, so this renders no trigger of its own. */
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Runs after the new link is issued and emailed; the owner re-reads the register. */
  onResent: () => void;
  /**
   * Runs when the resend fails. The dialog closes itself and the owner surfaces the problem in a persistent
   * `<MutationError>` anchored to the mutation's origin — errors never render inside the dialog.
   */
  onError: (problem: ProblemDetails) => void;
  /**
   * Where focus goes after a successful resend. The menu item that opened the dialog lives in a popup that has
   * already closed, so the default restore has nothing to return to; the row's action cluster is where the
   * operator was acting and survives the re-read.
   */
  focusOnResent: RefObject<HTMLElement | null>;
}

/**
 * Confirmation for re-delivering a pending invitation. Not destructive — the person stays pending and the
 * register is unchanged — but not free either: the link already in the invitee's mailbox stops working the
 * moment this succeeds, so the act is confirmed and the copy says so. Same dialog contract as
 * `<RevokeInvitationButton>`, with words and a non-destructive confirm of its own.
 *
 * A 503 `invitation-email-not-sent` reaches the owner's error surface like any refusal: the new link was minted
 * and the old one is dead, and the API's title says to resend once mail works. So does a 429 `rate-limited`:
 * the per-invitee resend budget is spent, nothing was rotated, and the link the invitee holds still works. The accept token never reaches
 * this client — the API answers 204 with no body.
 */
export function ResendInvitationButton({
  userId,
  email,
  open,
  onOpenChange,
  onResent,
  onError,
  focusOnResent,
}: Readonly<ResendInvitationButtonProps>) {
  const [resending, setResending] = useState(false);
  // Read once, when the closing dialog restores focus — a ref, not state, because the value is consulted
  // after the render that closed the dialog has already committed.
  const resentRef = useRef(false);
  // Every confirm rotates the invitee's token and mails it, so a second click landing before the re-render
  // that disables the button would kill the link the first one sent. State is only read on that re-render;
  // the ref is set synchronously.
  const inFlightRef = useRef(false);

  const onConfirm = async (): Promise<void> => {
    if (inFlightRef.current) return;
    inFlightRef.current = true;
    setResending(true);
    try {
      await container.get<ResendInvitation>("BackOfficeResendInvitation").run(userId);
      resentRef.current = true;
      onOpenChange(false);
      toastNotifier.success("Invitation resent", { description: email });
      onResent();
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      resentRef.current = false;
      onOpenChange(false);
      onError(err.problem);
      toastNotifier.error("Couldn't resend the invitation — see error details");
    } finally {
      inFlightRef.current = false;
      setResending(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md"
        finalFocus={() => (resentRef.current ? focusOnResent.current : true)}
        data-testid="user-resend-invitation__dialog"
      >
        <DialogHeader>
          <div className="flex items-start gap-3">
            <span
              className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-full"
              aria-hidden="true"
            >
              <Send className="size-5" />
            </span>
            <div className="flex flex-1 flex-col gap-2">
              <DialogTitle className="text-lg">Resend invitation</DialogTitle>
              <DialogDescription className="text-base leading-relaxed">
                A new invitation link is emailed to{" "}
                <span className="text-foreground font-semibold break-words">{email}</span>. The link
                they already received stops working immediately.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <DialogFooter>
          <DialogClose
            render={
              <Button
                variant="ghost"
                disabled={resending}
                aria-label="Cancel resend"
                title="Cancel resend"
                data-testid="user-resend-invitation__cancel"
              >
                Cancel
              </Button>
            }
          />
          <Button
            onClick={() => void onConfirm()}
            disabled={resending}
            data-icon={resending ? "inline-start" : undefined}
            aria-label="Resend invitation"
            title={`Confirm resending the invitation of ${email}`}
            data-testid="user-resend-invitation__confirm"
          >
            {resending ? (
              <>
                <Spinner className="size-4" testId="user-resend-invitation__spinner" />
                Resending…
              </>
            ) : (
              "Resend invitation"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
