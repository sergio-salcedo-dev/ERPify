import type { ReactNode } from "react";
import { AlertCircle } from "lucide-react";
import { cva, type VariantProps } from "class-variance-authority";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { cn } from "@/lib/utils";
import { CorrelationIdChip } from "./CorrelationIdChip";

const problemVariants = cva(
  "border-destructive/30 bg-destructive/5 text-foreground rounded-md border",
  {
    variants: {
      variant: {
        inline: "p-3",
        panel: "p-4",
        compact: "p-2 text-sm",
      },
    },
    defaultVariants: {
      variant: "inline",
    },
  },
);

interface ProblemDisplayProps extends VariantProps<typeof problemVariants> {
  /** RFC 9457 envelope from the API. Rendered verbatim — never paraphrased. */
  problem: ProblemDetails;
  /** Optional recovery action (e.g. retry button). */
  action?: ReactNode;
  className?: string;
}

export function ProblemDisplay({
  problem,
  variant = "inline",
  action,
  className,
}: ProblemDisplayProps) {
  const isUrgent = problem.status >= 500;
  const violations = problem.violations ?? [];

  return (
    <section
      role="alert"
      aria-live={isUrgent ? "assertive" : "polite"}
      data-problem-type={problem.type}
      className={cn(problemVariants({ variant }), className)}
    >
      <header className="flex items-start gap-2">
        <AlertCircle className="text-destructive mt-0.5 size-4" aria-hidden="true" />
        <div className="flex-1">
          <h3 className="text-foreground leading-tight font-semibold">{problem.title}</h3>
          {problem.detail ? (
            <p className="text-muted-foreground mt-1 text-sm">{problem.detail}</p>
          ) : null}
        </div>
      </header>

      {violations.length > 0 ? (
        <ul className="text-muted-foreground mt-3 ml-6 list-disc space-y-0.5 text-sm">
          {violations.map((v, i) => (
            <li key={`${v.field}-${i}`}>
              <span className="text-foreground font-medium">{v.field}</span>: {v.message}
            </li>
          ))}
        </ul>
      ) : null}

      <footer className="mt-3 flex items-center justify-between gap-2">
        <CorrelationIdChip id={problem["correlation-id"]} label="Error ID:" />
        {action ? <div>{action}</div> : null}
      </footer>
    </section>
  );
}
