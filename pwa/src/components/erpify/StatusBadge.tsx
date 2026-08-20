import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/components/cn";

export type StatusBadgeVariant = "success" | "warning" | "danger" | "info" | "neutral";

/**
 * Dot-first anatomy: the label is always neutral (text-muted-foreground,
 * ≥4.5:1 over white and over the selected-row tint) and the hue lives only in
 * the 6px dot. The previous tinted-text-on-tinted-bg anatomy measured
 * 2.19–3.90:1 and failed WCAG 1.4.3; success/warning dots use the darkened
 * --erpify-status-dot-* fills to clear 1.4.11 (≥3:1).
 *
 * The wrapper is a `<span>`, and that is load-bearing: `<output>` maps to role `status` in
 * HTML-AAM, so it IS a polite live region. One badge per row means a table of N rows declaring
 * N live regions that announce nothing and compete with the regions that do. `jsx-a11y` has no
 * rule for it — the wiring in `eslint.config.mjs` does not see this — so the gate is
 * `tests/live-region-surfaces.test.ts`, which refuses a new `<output>` outside its registry.
 */
const dotVariants = cva("size-1.5 flex-none rounded-full", {
  variants: {
    variant: {
      success: "bg-status-dot-success",
      warning: "bg-status-dot-warning",
      danger: "bg-danger",
      info: "bg-brand",
      neutral: "bg-text-subtle",
    },
  },
  defaultVariants: {
    variant: "neutral",
  },
});

interface StatusBadgeProps extends VariantProps<typeof dotVariants> {
  variant: StatusBadgeVariant;
  label: string;
  className?: string;
  /** Optional test id passthrough (never hardcode in shared components). */
  testId?: string;
}

export function StatusBadge({ variant, label, className, testId }: Readonly<StatusBadgeProps>) {
  return (
    <span
      className={cn(
        "status-badge bg-muted text-muted-foreground inline-flex h-5 items-center gap-1.5 rounded-full px-2 text-2xs font-medium",
        className,
      )}
      data-testid={testId}
    >
      <span className={dotVariants({ variant })} aria-hidden="true" />
      {label}
    </span>
  );
}
