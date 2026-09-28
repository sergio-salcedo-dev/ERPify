"use client";

import { type ReactNode } from "react";
import { Check, Copy } from "lucide-react";
import { Button } from "@/components/ui/button";
import { type ButtonVariantProps } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";
import { useCopyToClipboard, type CopyStatus } from "./useCopyToClipboard";

export type CopyButtonStatus = CopyStatus;

export interface CopyButtonProps {
  /** Text written to the clipboard. */
  value: string;
  /** Visible label for the idle state, and the button's accessible name in every state. Defaults to `Copy`. */
  label?: ReactNode;
  /** Visible label after a successful copy, and what the shared announcer speaks when it is a string. Defaults to `Copied`. */
  copiedLabel?: ReactNode;
  /** Visible label after a failed copy, and what the shared announcer speaks when it is a string. Defaults to `Copy failed`. */
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

export function CopyButton({
  value,
  label = "Copy",
  copiedLabel = "Copied",
  errorLabel = "Copy failed",
  title,
  feedbackTimeoutMs,
  iconOnly = false,
  variant = "outline",
  size = "sm",
  className,
  testId,
  onCopyResult,
}: Readonly<CopyButtonProps>) {
  const labelByStatus: Record<CopyButtonStatus, ReactNode> = {
    copied: copiedLabel,
    error: errorLabel,
    idle: label,
  };
  const fallbackTextByStatus: Record<CopyButtonStatus, string> = {
    copied: "Copied",
    error: "Copy failed",
    idle: "Copy",
  };
  const textFor = (of: CopyButtonStatus): string => {
    const labelled = labelByStatus[of];
    return typeof labelled === "string" ? labelled : fallbackTextByStatus[of];
  };
  const { status, copy } = useCopyToClipboard(value, {
    feedbackTimeoutMs,
    onCopyResult,
    announcements: { copied: textFor("copied"), error: textFor("error") },
  });
  const currentLabel = labelByStatus[status];
  // The name stays the action: the outcome is spoken once, by the shared announcer, instead of
  // a second time as a change to the name of the control that holds focus. The cost is that for
  // the feedback window the visible text says the outcome while the name says the action — which
  // is still the word a voice-control user says to press it again.
  const ariaLabel = textFor("idle");
  const tooltip = title ?? (typeof label === "string" ? label : "Copy");
  const Icon = status === "copied" ? Check : Copy;

  return (
    <Button
      type="button"
      variant={variant}
      size={size}
      onClick={copy}
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
