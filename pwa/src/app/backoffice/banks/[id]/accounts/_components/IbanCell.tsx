"use client";

import { useEffect } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/erpify";
import { cn } from "@/context/shared/styling/infrastructure/classNames";

/** How long a revealed IBAN stays visible before it re-masks itself. */
const AUTO_HIDE_MS = 10_000;

export interface IbanCellProps {
  /** The canonical, integral IBAN (PII). Masked at this edge — never logged. */
  iban: string;
  /** Whether THIS cell currently shows the integral value. */
  revealed: boolean;
  /** Ask the owner to reveal this cell (re-masking any other revealed cell). */
  onReveal: () => void;
  /** Ask the owner to re-mask this cell. */
  onHide: () => void;
  testId?: string;
}

/**
 * Presentational IBAN mask: country code + masked middle + last four digits
 * (`ES•• ···· 1332`). The full value is never derivable from the mask; it is
 * only ever rendered when the cell is explicitly revealed.
 */
export function maskIban(iban: string): string {
  // The country-code and last-four windows only stay disjoint (so the integral
  // value can't be reconstructed from the mask) when there is a masked gap
  // between them. A short/garbage value is fully masked rather than echoed.
  if (iban.length < 7) {
    return "•••• ···· ••••";
  }
  return `${iban.slice(0, 2)}•• ···· ${iban.slice(-4)}`;
}

/**
 * A single IBAN table cell with a presentational mask, a reveal toggle, and a
 * copy affordance. Reveal is CONTROLLED by the owner (the table holds a single
 * `revealedId`, so revealing one cell re-masks the rest). A revealed cell
 * re-masks itself automatically after {@link AUTO_HIDE_MS}, or as soon as the
 * pointer or keyboard focus leaves the cell. The IBAN is financial PII: it is
 * shown only while revealed and copied integrally, but never written to
 * logs/console/storage.
 */
export function IbanCell({ iban, revealed, onReveal, onHide, testId }: Readonly<IbanCellProps>) {
  useEffect(() => {
    if (!revealed) return;
    const timer = setTimeout(onHide, AUTO_HIDE_MS);
    return () => clearTimeout(timer);
  }, [revealed, onHide]);

  return (
    <fieldset
      aria-label="IBAN"
      className="iban-cell flex items-center gap-1.5"
      data-testid={testId}
      onMouseLeave={() => {
        if (revealed) onHide();
      }}
      onBlur={(event) => {
        if (revealed && !event.currentTarget.contains(event.relatedTarget)) onHide();
      }}
    >
      <span
        className={cn(
          "iban-cell__value font-mono text-xs tabular-nums transition-opacity motion-reduce:transition-none",
        )}
        data-revealed={revealed}
      >
        {revealed ? iban : maskIban(iban)}
      </span>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="iban-cell__toggle size-10"
        aria-pressed={revealed}
        aria-label={revealed ? "Hide IBAN" : "Show IBAN"}
        title={revealed ? "Hide IBAN" : "Show IBAN"}
        onClick={() => (revealed ? onHide() : onReveal())}
      >
        {revealed ? (
          <EyeOff className="size-3.5" aria-hidden="true" />
        ) : (
          <Eye className="size-3.5" aria-hidden="true" />
        )}
        <span className="sr-only">{revealed ? "Hide IBAN" : "Show IBAN"}</span>
      </Button>
      <CopyButton
        iconOnly
        variant="ghost"
        value={iban}
        label="Copy IBAN"
        copiedLabel="IBAN copied"
        className="iban-cell__copy size-10"
        testId={testId ? `${testId}__copy` : undefined}
      />
    </fieldset>
  );
}
