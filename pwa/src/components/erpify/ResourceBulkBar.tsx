"use client";

import { useState } from "react";
import { TriangleAlert, Trash2 } from "lucide-react";
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

interface ResourceBulkBarProps {
  count: number;
  /** Display labels of the selected rows, used to scope the confirm phrase. */
  names?: readonly string[];
  onClear: () => void;
  onConfirmDelete: () => void;
  /** Singular noun for the confirm copy (e.g. "bank"). */
  entitySingular: string;
  /** Plural noun for the confirm copy (e.g. "banks"). */
  entityPlural: string;
  /** BEM/testid prefix scoping this instance (e.g. "banks-list"). */
  testIdPrefix: string;
  /** Testid of the Delete trigger — the page focuses it after a bulk-error refresh. */
  deleteTestId: string;
}

/** How many selected labels the confirm dialog spells out before "+N more". */
const CONFIRM_NAMES_SHOWN = 3;

/**
 * Floating selection bar pinned to the bottom of the content column while rows
 * are selected. Hosts the bulk-delete confirmation (irreversible → dialog,
 * never a bare button) and a Clear affordance. Bulk delete is optimistic with
 * rollback (handled by the page); the confirmation here is the deliberate
 * friction before that. The polite live region announcing selection changes
 * lives in the page (always mounted, announcements coalesced) — a region inside
 * this conditionally-rendered bar would miss its own first render.
 */
export function ResourceBulkBar({
  count,
  names = [],
  onClear,
  onConfirmDelete,
  entitySingular,
  entityPlural,
  testIdPrefix,
  deleteTestId,
}: Readonly<ResourceBulkBarProps>) {
  const [open, setOpen] = useState(false);
  if (count === 0) return null;
  const noun = count === 1 ? entitySingular : entityPlural;
  const shownNames = names.slice(0, CONFIRM_NAMES_SHOWN);
  const remaining = count - shownNames.length;

  function handleConfirm(): void {
    setOpen(false);
    onConfirmDelete();
  }

  return (
    <section
      className="resource-bulk-bar border-border bg-card shadow-elevation-4 sticky bottom-6 z-30 mx-auto flex w-max max-w-[calc(100vw-2rem)] flex-wrap items-center gap-3 rounded-xl border px-4 py-2 sm:flex-nowrap"
      aria-label="Bulk actions"
      data-testid={`${testIdPrefix}__bulk-bar`}
    >
      <span
        className="text-foreground text-sm font-medium"
        data-testid={`${testIdPrefix}__bulk-count`}
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
          data-testid={`${testIdPrefix}__bulk-clear`}
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
                data-testid={deleteTestId}
              >
                <Trash2 className="size-3.5" aria-hidden="true" />
                Delete
              </Button>
            }
          />
          <DialogContent
            className="sm:max-w-md"
            data-testid={`${testIdPrefix}__bulk-delete-dialog`}
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
                  <DialogTitle className="text-lg">
                    Delete {count} {noun}
                  </DialogTitle>
                  <DialogDescription className="text-base leading-relaxed">
                    Are you sure you want to delete {count} selected {noun}? This cannot be undone.
                  </DialogDescription>
                </div>
              </div>
            </DialogHeader>
            {shownNames.length > 0 ? (
              <ul
                className="resource-bulk-bar__delete-names text-muted-foreground list-none space-y-1 p-0 text-sm sm:pl-13"
                data-testid={`${testIdPrefix}__bulk-delete-names`}
              >
                {shownNames.map((name) => (
                  <li key={name} className="line-clamp-1 break-words">
                    {name}
                  </li>
                ))}
                {remaining > 0 ? <li className="text-xs">+{remaining} more</li> : null}
              </ul>
            ) : null}
            <DialogFooter>
              <DialogClose
                render={
                  <Button
                    variant="ghost"
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
                onClick={handleConfirm}
                aria-label={`Confirm delete of ${count} ${noun}`}
                title={`Confirm delete of ${count} ${noun}`}
                data-testid={`${testIdPrefix}__bulk-delete-confirm`}
              >
                Delete
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </section>
  );
}
