"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Trash2 } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { DeleteBank } from "@/context/backoffice/bank/application/DeleteBank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { ProblemDisplay } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";

interface DeleteBankButtonProps {
  id: string;
  name: string;
}

export function DeleteBankButton({ id, name }: DeleteBankButtonProps) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  async function handleConfirm(): Promise<void> {
    setSubmitting(true);
    setProblem(null);
    try {
      const useCase = container.get<DeleteBank>("BackOfficeDeleteBank");
      await useCase.run(id);
      setOpen(false);
      router.push("/backoffice/banks");
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

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger
        render={
          <Button
            variant="destructive"
            size="sm"
            data-icon="inline-start"
            data-testid="banks-detail__delete-button"
          >
            <Trash2 className="size-3.5" aria-hidden="true" />
            Delete
          </Button>
        }
      />
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
          <Button variant="ghost" size="sm" disabled={submitting} onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button
            variant="destructive"
            size="sm"
            onClick={handleConfirm}
            disabled={submitting}
            data-testid="banks-detail__delete-confirm"
          >
            {submitting ? "Deleting…" : "Delete"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
