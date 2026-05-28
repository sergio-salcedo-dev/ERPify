"use client";

import { useState } from "react";
import { Check, Copy } from "lucide-react";
import { cn } from "@/lib/utils";

interface CorrelationIdChipProps {
  /** Full UUIDv7 to display and copy. Truncated visually; copied in full. */
  id: string;
  /** Optional label rendered before the ID, e.g. "Error ID:". */
  label?: string;
  /** Visual size. Defaults to "xs". */
  size?: "xs" | "sm";
  className?: string;
}

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

  return (
    <button
      type="button"
      onClick={handleCopy}
      aria-label={`Copy correlation ID ${id}`}
      title={`Copy error ID ${id}`}
      className={cn(
        "inline-flex items-center gap-1.5 rounded-[2px] border font-mono transition-colors",
        "border-border bg-muted/50 text-muted-foreground hover:bg-muted",
        "focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none",
        size === "xs" ? "h-5 px-1.5 text-2xs" : "h-6 px-2 text-xs",
        className,
      )}
    >
      {label ? <span className="font-sans text-muted-foreground">{label}</span> : null}
      <span aria-hidden="true" data-testid="correlation-id-display">
        {id}
      </span>
      <span className="sr-only" role="status" aria-live="polite">
        {copied ? "Copied" : ""}
      </span>
      {copied ? (
        <Check className="size-3" aria-hidden="true" />
      ) : (
        <Copy className="size-3" aria-hidden="true" />
      )}
    </button>
  );
}
