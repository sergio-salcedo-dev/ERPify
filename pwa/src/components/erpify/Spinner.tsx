import { Loader2 } from "lucide-react";
import { cn } from "@/components/cn";

interface SpinnerProps {
  /** Extra classes; defaults to a 1rem icon. */
  className?: string;
  /** Optional test id passthrough (never hardcode in shared components). */
  testId?: string;
}

/**
 * Decorative loading spinner. Always `aria-hidden` — the control that wraps
 * it (submit button, etc.) already exposes the accessible name (e.g.
 * "Saving…"), so the spinner must not add a second name.
 */
export function Spinner({ className, testId }: Readonly<SpinnerProps>) {
  return (
    <Loader2
      className={cn("size-4 animate-spin", className)}
      aria-hidden="true"
      data-testid={testId}
    />
  );
}
