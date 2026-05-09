"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { Check, Copy } from "lucide-react";
import { Button, type buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import type { VariantProps } from "class-variance-authority";

type ButtonVariantProps = VariantProps<typeof buttonVariants>;

export type CopyButtonStatus = "idle" | "copied" | "error";

export interface CopyButtonProps {
  /** Text written to the clipboard. */
  value: string;
  /** Visible label and aria-label for the idle state. Defaults to `Copy`. */
  label?: ReactNode;
  /** Visible label and aria-label after a successful copy. Defaults to `Copied`. */
  copiedLabel?: ReactNode;
  /** Visible label and aria-label after a failed copy. Defaults to `Copy failed`. */
  errorLabel?: ReactNode;
  /** Tooltip for the idle state. Defaults to a string version of `label`. */
  title?: string;
  /** How long the copied/error feedback remains, in ms. Defaults to 2000. */
  feedbackTimeoutMs?: number;
  /** Hide the visible text and keep the button icon-only with sr-only labels. */
  iconOnly?: boolean;
  variant?: ButtonVariantProps["variant"];
  size?: ButtonVariantProps["size"];
  className?: string;
  /** Forwarded to the rendered button element. */
  testId?: string;
  /** Called after the clipboard write resolves (success or failure). */
  onCopyResult?: (status: CopyButtonStatus) => void;
}

const DEFAULT_FEEDBACK_MS = 2000;

async function writeToClipboard(value: string): Promise<void> {
  if (typeof navigator !== "undefined" && navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(value);
    return;
  }
  // Fallback for non-secure contexts and old browsers without the async API.
  if (typeof document === "undefined") {
    throw new Error("Clipboard API unavailable.");
  }
  const ta = document.createElement("textarea");
  ta.value = value;
  ta.setAttribute("readonly", "");
  ta.style.position = "absolute";
  ta.style.left = "-9999px";
  document.body.appendChild(ta);
  ta.select();
  try {
    const ok = document.execCommand("copy");
    if (!ok) throw new Error("execCommand('copy') returned false");
  } finally {
    document.body.removeChild(ta);
  }
}

export function CopyButton({
  value,
  label = "Copy",
  copiedLabel = "Copied",
  errorLabel = "Copy failed",
  title,
  feedbackTimeoutMs = DEFAULT_FEEDBACK_MS,
  iconOnly = false,
  variant = "outline",
  size = "sm",
  className,
  testId,
  onCopyResult,
}: CopyButtonProps) {
  const [status, setStatus] = useState<CopyButtonStatus>("idle");
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(
    () => () => {
      if (timeoutRef.current !== null) clearTimeout(timeoutRef.current);
    },
    [],
  );

  async function handleClick(): Promise<void> {
    let next: CopyButtonStatus;
    try {
      await writeToClipboard(value);
      next = "copied";
    } catch {
      next = "error";
    }
    setStatus(next);
    onCopyResult?.(next);
    if (timeoutRef.current !== null) clearTimeout(timeoutRef.current);
    timeoutRef.current = setTimeout(() => setStatus("idle"), feedbackTimeoutMs);
  }

  const currentLabel = status === "copied" ? copiedLabel : status === "error" ? errorLabel : label;
  const ariaLabel =
    typeof currentLabel === "string"
      ? currentLabel
      : status === "copied"
        ? "Copied"
        : status === "error"
          ? "Copy failed"
          : "Copy";
  const tooltip = title ?? (typeof label === "string" ? label : "Copy");
  const Icon = status === "copied" ? Check : Copy;

  return (
    <Button
      type="button"
      variant={variant}
      size={size}
      onClick={handleClick}
      data-icon={iconOnly ? undefined : "inline-start"}
      data-copy-status={status}
      data-testid={testId}
      aria-label={ariaLabel}
      title={tooltip}
      className={cn("copy-button", className)}
    >
      <Icon className="size-3.5" aria-hidden="true" />
      {iconOnly ? <span className="sr-only">{ariaLabel}</span> : <span>{currentLabel}</span>}
    </Button>
  );
}
