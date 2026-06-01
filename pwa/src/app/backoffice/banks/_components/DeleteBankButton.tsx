"use client";

import { useState, type ReactElement } from "react";
import { useRouter } from "next/navigation";
import { Trash2 } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { DeleteBank } from "@/context/backoffice/bank/application/DeleteBank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { ProblemDisplay, Spinner } from "@/components/erpify";
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
  /** Custom trigger; defaults to a destructive button with a Trash icon. */
  trigger?: ReactElement;
  triggerTestId?: string;
}

export function DeleteBankButton({
  id,
  name,
  onDeleted,
  trigger,
  triggerTestId = "banks-detail__delete-button",
}: Readonly<DeleteBankButtonProps>) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  function handleOpenChange(next: boolean): void {
    if (next) {
      setProblem(null);
    }
    setOpen(next);
  }

  async function handleConfirm(): Promise<void> {
    if (submitting) return;
    setSubmitting(true);
    setProblem(null);
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
        setProblem(err.problem);
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
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogTrigger render={trigger ?? defaultTrigger} />
      <DialogContent data-testid="banks-detail__delete-dialog">
        <DialogHeader>
          <DialogTitle>Delete bank</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete <span className="font-semibold">{name}</span>? This
            cannot be undone.
          </DialogDescription>
        </DialogHeader>

        {problem ? <ProblemDisplay problem={problem} variant="inline" /> : null}

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
