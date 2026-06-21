"use client";

import { useState } from "react";
import { Check, Copy } from "lucide-react";
import { cn } from "@/components/cn";

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
  const [copied, setCopied] = useState(false);

  async function handleCopy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(id);
      setCopied(true);
      globalThis.setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard denied or unavailable — leave the visible chip alone.
    }
  }

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
        onClick={handleCopy}
        aria-label={`Copy correlation ID ${id}`}
        title={copied ? "Copied" : `Copy error ID ${id}`}
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
        {copied ? (
          <Check className="text-success size-3 shrink-0" aria-hidden="true" />
        ) : (
          <Copy className="size-3 shrink-0 opacity-70 group-hover:opacity-100" aria-hidden="true" />
        )}
        <span className="sr-only" role="status" aria-live="polite">
          {copied ? "Copied" : ""}
        </span>
      </button>
    </span>
  );
}
