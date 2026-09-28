"use client";

import { Check, Copy } from "lucide-react";
import { cn } from "@/components/cn";
import { useCopyToClipboard, type CopyStatus } from "./useCopyToClipboard";

interface CorrelationIdChipProps {
  /**
   * Full UUIDv7 to display and copy. Shown in full — on a narrow surface it
   * wraps at the hyphens and the token grows to fit, never clipped — and is
   * copied in full so it can be quoted verbatim in a support ticket.
   */
  id: string;
  /** Optional label rendered before the ID, e.g. "Error ID:". */
  label?: string;
  /** Visual size. Defaults to "xs". */
  size?: "xs" | "sm";
  className?: string;
}

const ANNOUNCEMENT_BY_STATUS: Record<CopyStatus, string> = {
  idle: "",
  copied: "Copied",
  error: "Copy failed",
};

/**
 * A copyable correlation/error identifier. The label (when supplied) reads as
 * muted prose; the id itself is a self-contained, click-to-copy code token so
 * the copy affordance and the value the user sees are one and the same.
 */
export function CorrelationIdChip({
  id,
  label,
  size = "xs",
  className,
}: Readonly<CorrelationIdChipProps>) {
  const { status, copy } = useCopyToClipboard(id);
  const isSm = size === "sm";

  return (
    <span
      className={cn(
        "correlation-id-chip inline-flex max-w-full min-w-0 flex-wrap items-center gap-x-2 gap-y-1",
        isSm ? "text-xs" : "text-2xs",
        className,
      )}
    >
      {label ? (
        <span className="correlation-id-chip__label text-muted-foreground shrink-0 font-sans font-medium">
          {label}
        </span>
      ) : null}
      <button
        type="button"
        onClick={copy}
        aria-label={`Copy correlation ID ${id}`}
        title={status === "idle" ? `Copy error ID ${id}` : ANNOUNCEMENT_BY_STATUS[status]}
        data-copy-status={status}
        className={cn(
          "correlation-id-chip__token group inline-flex max-w-full min-w-0 items-center gap-1.5 rounded font-mono transition-colors",
          "border-border bg-muted/40 text-muted-foreground border hover:bg-muted hover:text-foreground",
          "focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:outline-none",
          isSm ? "px-2 py-1" : "px-2 py-0.5",
        )}
      >
        <span className="min-w-0 text-left break-words" data-testid="correlation-id-display">
          {id}
        </span>
        {status === "copied" ? (
          <Check className="text-success size-3 shrink-0" aria-hidden="true" />
        ) : (
          <Copy className="size-3 shrink-0 opacity-70 group-hover:opacity-100" aria-hidden="true" />
        )}
        <span className="sr-only" role="status" aria-live="polite">
          {ANNOUNCEMENT_BY_STATUS[status]}
        </span>
      </button>
    </span>
  );
}
