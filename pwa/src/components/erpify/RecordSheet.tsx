"use client";

import type { ReactNode } from "react";
import { Dialog as BaseDialog } from "@base-ui/react/dialog";
import { XIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/context/shared/styling/infrastructure/classNames";

interface RecordSheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  subtitle?: ReactNode;
  /** drawer (default, slides from right) or dialog (centered modal). */
  variant?: "drawer" | "dialog";
  children: ReactNode;
  footer?: ReactNode;
  /** When true, prompts for confirmation before allowing close. */
  dirty?: boolean;
  /** Confirmation message — shown when dirty && attempting to close. */
  confirmCloseMessage?: string;
  /** Consumer-supplied test id for the popup — never hardcoded here. */
  testId?: string;
  className?: string;
}

export function RecordSheet({
  open,
  onOpenChange,
  title,
  subtitle,
  variant = "drawer",
  children,
  footer,
  dirty = false,
  confirmCloseMessage = "Discard unsaved changes?",
  testId,
  className,
}: Readonly<RecordSheetProps>) {
  function handleOpenChange(next: boolean): void {
    if (!next && dirty) {
      const ok =
        globalThis.window === undefined ? true : globalThis.window.confirm(confirmCloseMessage);
      if (!ok) return;
    }
    onOpenChange(next);
  }

  const popupClasses =
    variant === "drawer"
      ? "data-[side=right]:inset-y-0 data-[side=right]:right-0 data-[side=right]:h-full data-[side=right]:w-full sm:data-[side=right]:max-w-md fixed flex flex-col bg-popover text-popover-foreground border-l border-border shadow-lg z-50"
      : "fixed top-1/2 left-1/2 z-50 -translate-x-1/2 -translate-y-1/2 w-full max-w-[calc(100%-2rem)] sm:max-w-md flex flex-col bg-popover text-popover-foreground rounded-xl border border-border shadow-lg p-0";

  return (
    <BaseDialog.Root open={open} onOpenChange={handleOpenChange}>
      <BaseDialog.Portal>
        <BaseDialog.Backdrop className="fixed inset-0 z-40 bg-black/40 supports-backdrop-filter:backdrop-blur-xs" />
        <BaseDialog.Popup
          data-slot="record-sheet"
          data-variant={variant}
          data-side={variant === "drawer" ? "right" : undefined}
          data-testid={testId}
          className={cn(popupClasses, className)}
        >
          <header className="border-border flex shrink-0 items-start gap-3 border-b p-4">
            <div className="min-w-0 flex-1">
              <BaseDialog.Title className="text-foreground truncate text-base font-semibold">
                {title}
              </BaseDialog.Title>
              {subtitle ? (
                <BaseDialog.Description className="text-muted-foreground mt-0.5 truncate text-sm">
                  {subtitle}
                </BaseDialog.Description>
              ) : null}
            </div>
            <BaseDialog.Close
              render={
                <Button variant="ghost" size="icon-sm" aria-label="Close">
                  <XIcon className="size-4" />
                </Button>
              }
            />
          </header>
          <div className="flex-1 overflow-y-auto p-4">{children}</div>
          {footer ? (
            <footer className="border-border bg-muted/30 flex shrink-0 items-center justify-end gap-2 border-t p-3">
              {footer}
            </footer>
          ) : null}
        </BaseDialog.Popup>
      </BaseDialog.Portal>
    </BaseDialog.Root>
  );
}
