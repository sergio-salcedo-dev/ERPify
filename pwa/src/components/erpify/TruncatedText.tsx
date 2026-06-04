"use client";

import { useEffect, useState } from "react";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { useIsTruncated } from "@/lib/useIsTruncated";
import { cn } from "@/lib/utils";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";

// Static class map — Tailwind cannot generate classes from template literals.
const CLAMP_CLASS = { 1: "truncate", 2: "line-clamp-2" } as const;

export type TruncatedTextLines = keyof typeof CLAMP_CLASS;

export interface TruncatedTextProps {
  value: string;
  /** Visual budget: 1 line (table cells) or 2 lines (card titles). */
  lines?: TruncatedTextLines;
  className?: string;
  /**
   * CSS selector for the focusable scope (row / card) whose keyboard focus
   * opens the tooltip. The span itself NEVER receives tabIndex — the row is
   * the only tab stop, so the full value stays one focus away without adding
   * viewport-dependent tab stops.
   */
  focusScopeSelector?: string;
  testId?: string;
}

/**
 * Single visual contract for externally-sourced text in list surfaces:
 * CSS-only truncation (the full string always stays in the DOM and the
 * accessibility tree) plus a full-value tooltip rendered ONLY when the text
 * is actually truncated — no truncation, no tooltip, zero noise.
 *
 * Triggers: pointer hover on the text, and keyboard focus of the enclosing
 * row/card scope. Esc dismisses while focus stays put (WCAG 1.4.13). On
 * touch the tooltip intentionally does not exist; the declared route to the
 * full value is the detail page, one tap away.
 */
export function TruncatedText({
  value,
  lines = 1,
  className,
  focusScopeSelector = "tr",
  testId,
}: Readonly<TruncatedTextProps>) {
  const { ref, truncated } = useIsTruncated<HTMLSpanElement>(value);
  const [hoverOpen, setHoverOpen] = useState(false);
  const [focusOpen, setFocusOpen] = useState(false);

  useEffect(() => {
    const el = ref.current;
    if (!el || !truncated) return;
    const scope = el.closest<HTMLElement>(focusScopeSelector);
    if (!scope) return;

    const handleFocusIn = (event: FocusEvent): void => {
      // Only the scope itself (the row as tab stop), not inner controls.
      setFocusOpen(event.target === scope);
    };
    const handleFocusOut = (event: FocusEvent): void => {
      const next = event.relatedTarget;
      if (!(next instanceof Node) || !scope.contains(next)) {
        setFocusOpen(false);
      }
    };
    const handleKeyDown = (event: globalThis.KeyboardEvent): void => {
      if (event.key === KeyboardKey.ESCAPE) {
        setFocusOpen(false);
      }
    };

    scope.addEventListener("focusin", handleFocusIn);
    scope.addEventListener("focusout", handleFocusOut);
    scope.addEventListener("keydown", handleKeyDown);
    return () => {
      scope.removeEventListener("focusin", handleFocusIn);
      scope.removeEventListener("focusout", handleFocusOut);
      scope.removeEventListener("keydown", handleKeyDown);
    };
  }, [ref, truncated, focusScopeSelector]);

  const text = (
    <span ref={ref} className={cn("block", CLAMP_CLASS[lines], className)} data-testid={testId}>
      {value}
    </span>
  );

  if (!truncated) return text;

  return (
    <Tooltip
      open={hoverOpen || focusOpen}
      onOpenChange={(next) => {
        setHoverOpen(next);
        if (!next) setFocusOpen(false);
      }}
    >
      <TooltipTrigger render={text} />
      <TooltipContent className="max-h-28 max-w-[360px] overflow-y-auto break-words whitespace-pre-wrap">
        {value}
      </TooltipContent>
    </Tooltip>
  );
}
