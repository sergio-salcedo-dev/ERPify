"use client";

import { useEffect, useId, useRef, useState } from "react";
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
  /**
   * Whether keyboard focus of the scope opens this tooltip. Exactly ONE cell
   * per row should opt in (the primary identity — usually Name); a row with
   * several truncated cells would otherwise pop several tooltips at once.
   * Hover per cell is unaffected.
   */
  openOnRowFocus?: boolean;
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
  openOnRowFocus = true,
  testId,
}: Readonly<TruncatedTextProps>) {
  const { ref, truncated } = useIsTruncated<HTMLSpanElement>(value);
  const [hoverOpen, setHoverOpen] = useState(false);
  const [focusOpen, setFocusOpen] = useState(false);
  // A CONTROLLED Base UI tooltip must know which trigger anchors it —
  // without the explicit trigger/root id pairing, hover never reaches
  // onOpenChange and the popup has no anchor to position against.
  const triggerId = useId();

  // Mirror of the open state for the native Esc listener below — a stale
  // closure over `hoverOpen`/`focusOpen` would mis-route the precedence.
  const openRef = useRef(false);
  useEffect(() => {
    openRef.current = hoverOpen || focusOpen;
  });

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
    // Esc precedence: when this tooltip is open, Esc dismisses it and stops
    // right there — an open transient layer must never leak the keypress to
    // outer handlers (e.g. the list's clear-selection).
    const handleKeyDown = (event: globalThis.KeyboardEvent): void => {
      if (event.key === KeyboardKey.ESCAPE && openRef.current) {
        event.stopPropagation();
        setFocusOpen(false);
        setHoverOpen(false);
      }
    };

    if (openOnRowFocus) {
      scope.addEventListener("focusin", handleFocusIn);
      scope.addEventListener("focusout", handleFocusOut);
    }
    scope.addEventListener("keydown", handleKeyDown);
    return () => {
      scope.removeEventListener("focusin", handleFocusIn);
      scope.removeEventListener("focusout", handleFocusOut);
      scope.removeEventListener("keydown", handleKeyDown);
    };
  }, [ref, truncated, focusScopeSelector, openOnRowFocus]);

  const text = (
    <span ref={ref} className={cn("block", CLAMP_CLASS[lines], className)} data-testid={testId}>
      {value}
    </span>
  );

  if (!truncated) return text;

  return (
    <Tooltip
      open={hoverOpen || focusOpen}
      triggerId={triggerId}
      onOpenChange={(next) => {
        setHoverOpen(next);
        if (!next) setFocusOpen(false);
      }}
    >
      <TooltipTrigger id={triggerId} render={text} />
      <TooltipContent className="max-h-28 max-w-[360px] overflow-y-auto break-words whitespace-pre-wrap">
        {value}
      </TooltipContent>
    </Tooltip>
  );
}
