"use client";

import { useState } from "react";
import { Trash2 } from "lucide-react";
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

interface BanksBulkBarProps {
  count: number;
  onClear: () => void;
  onConfirmDelete: () => void;
}

/**
 * Selection action bar shown above the list once one or more banks are
 * selected. Hosts the bulk-delete confirmation (irreversible action → dialog,
 * never a bare button) and a Clear affordance. Bulk delete is optimistic with
 * rollback (handled by the page); the confirmation here is the deliberate
 * friction before that.
 */
export function BanksBulkBar({ count, onClear, onConfirmDelete }: Readonly<BanksBulkBarProps>) {
  const [open, setOpen] = useState(false);
  if (count === 0) return null;
  const noun = count === 1 ? "bank" : "banks";

  function handleConfirm(): void {
    setOpen(false);
    onConfirmDelete();
  }

  return (
    <div
      className="banks-list__bulk-bar border-border bg-card flex flex-wrap items-center gap-3 rounded-lg border p-2 sm:flex-nowrap"
      role="region"
      aria-label="Bulk actions"
      data-testid="banks-list__bulk-bar"
    >
      <span
        className="text-foreground text-sm font-medium"
        aria-live="polite"
        data-testid="banks-list__bulk-count"
      >
        {count} selected
      </span>
      <div className="ml-auto flex items-center gap-2">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={onClear}
          aria-label="Clear selection"
          title="Clear selection"
          data-testid="banks-list__bulk-clear"
        >
          Clear
        </Button>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger
            render={
              <Button
                type="button"
                variant="destructive"
                size="sm"
                data-icon="inline-start"
                aria-label={`Delete ${count} ${noun}`}
                title={`Delete ${count} ${noun}`}
                data-testid="banks-list__bulk-delete"
              >
                <Trash2 className="size-3.5" aria-hidden="true" />
                Delete
              </Button>
            }
          />
          <DialogContent data-testid="banks-list__bulk-delete-dialog">
            <DialogHeader>
              <DialogTitle>
                Delete {count} {noun}
              </DialogTitle>
              <DialogDescription>
                Are you sure you want to delete {count} selected {noun}? This cannot be undone.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <DialogClose
                render={
                  <Button
                    variant="ghost"
                    size="sm"
                    aria-label="Cancel bulk delete"
                    title="Cancel bulk delete"
                  >
                    Cancel
                  </Button>
                }
              />
              <Button
                type="button"
                variant="destructive"
                size="sm"
                onClick={handleConfirm}
                aria-label={`Confirm delete of ${count} ${noun}`}
                title={`Confirm delete of ${count} ${noun}`}
                data-testid="banks-list__bulk-delete-confirm"
              >
                Delete
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  );
}
