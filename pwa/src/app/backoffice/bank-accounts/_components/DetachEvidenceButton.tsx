"use client";

import { useState } from "react";
import { Unlink } from "lucide-react";
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

interface DetachEvidenceButtonProps {
  evidenceId: string;
  /** The document's name as listed, for the confirmation; `null` when it does not open. */
  name: string | null;
  disabled: boolean;
  /** Runs the detach; a rejection closes the dialog and is reported by the caller. */
  onConfirm: () => Promise<void>;
}

/**
 * The detach of one piece of evidence, behind a confirmation. The document is not deleted: it stays
 * filed and can be attached to the account it belongs to — which is why the dialog says "detach"
 * and never "delete".
 */
export function DetachEvidenceButton({
  evidenceId,
  name,
  disabled,
  onConfirm,
}: Readonly<DetachEvidenceButtonProps>) {
  const [open, setOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const label = name ?? "this document";

  async function confirm(): Promise<void> {
    if (submitting) return;
    setSubmitting(true);
    try {
      await onConfirm();
    } finally {
      setSubmitting(false);
      setOpen(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger
        render={
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={disabled}
            aria-label="Detach"
            title={`Detach ${label} from this account`}
            data-testid={`bank-account-evidence__detach-${evidenceId}`}
          >
            <Unlink className="size-4" aria-hidden="true" />
            Detach
          </Button>
        }
      />
      <DialogContent
        className="sm:max-w-md"
        data-testid={`bank-account-evidence__detach-dialog-${evidenceId}`}
      >
        <DialogHeader>
          <DialogTitle className="text-lg">Detach evidence</DialogTitle>
          <DialogDescription className="text-base leading-relaxed">
            <span className="text-foreground font-semibold break-words">{label}</span>
            {
              " will no longer be evidence of this account. The document itself is kept, and can be attached to the account it belongs to."
            }
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <DialogClose
            render={
              <Button
                variant="ghost"
                disabled={submitting}
                aria-label="Cancel"
                title="Keep the evidence attached"
              >
                Cancel
              </Button>
            }
          />
          <Button
            variant="destructive"
            onClick={() => void confirm()}
            disabled={submitting}
            aria-label="Confirm detach"
            title={`Detach ${label}`}
            data-testid={`bank-account-evidence__detach-confirm-${evidenceId}`}
          >
            {submitting ? <Spinner className="size-4" /> : null}
            Detach
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
