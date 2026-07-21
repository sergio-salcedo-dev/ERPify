import { createElement, type ReactNode } from "react";
import {
  AlertCircle,
  AlertTriangle,
  FileQuestion,
  Hourglass,
  ListChecks,
  Lock,
  LogIn,
  type LucideIcon,
} from "lucide-react";
import { cva, type VariantProps } from "class-variance-authority";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { NodeEnv } from "@/context/shared/environment/domain/NodeEnv";
import { cn } from "@/components/cn";
import { CorrelationIdChip } from "./CorrelationIdChip";

/**
 * Visual tone driven by the HTTP status code. 5xx (and the synthetic 0 we
 * use for "no response / network blip") gets the destructive ramp; every
 * other client-side problem gets the warning ramp because it's typically
 * recoverable with a user action (re-auth, fix input, retry later).
 */
type ProblemTone = "destructive" | "warning";

interface ToneClasses {
  surface: string;
  icon: string;
  badge: string;
  rule: string;
}

const TONE_CLASSES: Record<ProblemTone, ToneClasses> = {
  destructive: {
    surface: "border-destructive/30 bg-destructive/5",
    icon: "text-destructive",
    badge: "bg-destructive/10 text-destructive",
    rule: "border-destructive/15",
  },
  warning: {
    surface: "border-warning/30 bg-warning/5",
    icon: "text-warning",
    badge: "bg-warning/10 text-warning-strong",
    rule: "border-warning/15",
  },
};

function toneForStatus(status: number): ProblemTone {
  if (status === 0 || status >= HttpStatus.INTERNAL_SERVER_ERROR) return "destructive";
  return "warning";
}

function iconForStatus(status: number, hasViolations: boolean): LucideIcon {
  if (status === 0 || status >= HttpStatus.INTERNAL_SERVER_ERROR) return AlertTriangle;
  if (hasViolations || status === HttpStatus.UNPROCESSABLE_ENTITY) return ListChecks;
  if (status === HttpStatus.UNAUTHORIZED) return LogIn;
  if (status === HttpStatus.FORBIDDEN) return Lock;
  if (status === HttpStatus.NOT_FOUND) return FileQuestion;
  if (status === HttpStatus.TOO_MANY_REQUESTS) return Hourglass;
  return AlertCircle;
}

const problemVariants = cva("text-foreground rounded-md border", {
  variants: {
    variant: {
      // Used inside forms / list pages — sits in the page flow.
      inline: "p-3 sm:p-4",
      // Used by `<AsyncBoundary>` when a whole panel surface failed.
      panel: "p-4 sm:p-5 lg:p-6",
      // Used for very dense surfaces (toasts, sidecars).
      compact: "p-2 text-sm",
    },
  },
  defaultVariants: { variant: "inline" },
});

interface ProblemDisplayProps extends VariantProps<typeof problemVariants> {
  /** RFC 9457 envelope from the API. Rendered verbatim — never paraphrased. */
  problem: ProblemDetails;
  /** Optional recovery action (e.g. retry button). */
  action?: ReactNode;
  className?: string;
}

/**
 * Optional `debug` extension member of the RFC 9457 envelope. Populated by
 * the API in non-production environments to help engineers triage; redacted
 * server-side in production. Even so, this component **also** gates the
 * render client-side: if a server ever leaks the block by mistake, the
 * browser still wouldn't show it.
 *
 * See `docs/api-error-contract.md` for the producer-side contract.
 */
interface ProblemDebug {
  exception_class?: string;
  message?: string;
  file?: string;
  line?: number;
  previous_chain?: ProblemDebug[];
}

function isProblemDebug(value: unknown): value is ProblemDebug {
  if (typeof value !== "object" || value === null) return false;
  const v = value as Record<string, unknown>;
  return (
    "exception_class" in v || "message" in v || "file" in v || "line" in v || "previous_chain" in v
  );
}

export const isProductionEnv = (): boolean => process.env.NODE_ENV === NodeEnv.PRODUCTION;

interface DebugSectionProps {
  debug: ProblemDebug;
  rule: string;
  /** Recursion depth — used to label `previous_chain` entries. */
  depth?: number;
}

function DebugSection({ debug, rule, depth = 0 }: Readonly<DebugSectionProps>) {
  const heading = depth === 0 ? "Debug details" : `Caused by (chain depth ${depth})`;
  const previous = debug.previous_chain ?? [];

  const content = (
    <div className="problem-display__debug-grid mt-2 grid gap-x-3 gap-y-1 font-mono text-[11px] sm:grid-cols-[max-content_minmax(0,1fr)] sm:text-xs">
      {debug.exception_class ? (
        <>
          <span className="text-muted-foreground">exception</span>
          <span className="text-foreground break-all">{debug.exception_class}</span>
        </>
      ) : null}
      {debug.message ? (
        <>
          <span className="text-muted-foreground">message</span>
          <span className="text-foreground break-all whitespace-pre-wrap">{debug.message}</span>
        </>
      ) : null}
      {debug.file ? (
        <>
          <span className="text-muted-foreground">file</span>
          <span className="text-foreground break-all">
            {debug.file}
            {typeof debug.line === "number" ? `:${debug.line}` : null}
          </span>
        </>
      ) : null}
      {previous.length > 0 ? (
        <>
          <span className="text-muted-foreground">chain</span>
          <div className="space-y-2">
            {previous.map((entry, i) => (
              <DebugSection
                key={`${entry.exception_class ?? "exception"}@${entry.file ?? "unknown"}:${entry.line ?? i}`}
                debug={entry}
                rule={rule}
                depth={depth + 1}
              />
            ))}
          </div>
        </>
      ) : null}
    </div>
  );

  if (depth > 0) {
    return (
      <div className={cn("mt-1 border-l pl-3", rule)}>
        <p className="text-muted-foreground text-[11px] font-medium tracking-wider uppercase sm:text-xs">
          {heading}
        </p>
        {content}
      </div>
    );
  }

  return (
    <details
      className={cn("problem-display__debug border-t pt-3", rule)}
      data-testid="problem-display__debug"
    >
      <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-xs font-medium tracking-wider uppercase select-none">
        {heading}
      </summary>
      {content}
    </details>
  );
}

export function ProblemDisplay({
  problem,
  variant = "inline",
  action,
  className,
}: Readonly<ProblemDisplayProps>) {
  const violations = problem.violations ?? [];
  const tone = TONE_CLASSES[toneForStatus(problem.status)];
  const statusIcon = iconForStatus(problem.status, violations.length > 0);
  const isUrgent = problem.status === 0 || problem.status >= HttpStatus.INTERNAL_SERVER_ERROR;
  const debugCandidate = (problem as { debug?: unknown }).debug;
  const debug = !isProductionEnv() && isProblemDebug(debugCandidate) ? debugCandidate : undefined;
  // The status pill renders a non-zero numeric code; status === 0 is our
  // synthetic "no response" sentinel and reads better as text.
  const statusLabel = problem.status > 0 ? `HTTP ${problem.status}` : "No response";

  return (
    <section
      role="alert"
      aria-live={isUrgent ? "assertive" : "polite"}
      data-problem-type={problem.type}
      data-problem-status={problem.status}
      className={cn(
        "problem-display flex flex-col gap-3",
        problemVariants({ variant }),
        tone.surface,
        className,
      )}
    >
      <header className="problem-display__header flex items-start gap-3">
        <span
          className={cn(
            "problem-display__icon-wrap mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full sm:size-8",
            tone.badge,
          )}
        >
          {createElement(statusIcon, {
            className: cn("size-4 sm:size-4.5", tone.icon),
            "aria-hidden": "true",
          })}
        </span>
        <div className="problem-display__heading min-w-0 flex-1">
          <div className="problem-display__meta flex flex-wrap items-center gap-x-2 gap-y-1">
            <span
              className={cn(
                "problem-display__status rounded px-2 py-0.5 font-mono text-[11px] font-semibold tracking-wider sm:text-xs",
                tone.badge,
              )}
              data-testid="problem-display__status"
            >
              {statusLabel}
            </span>
            <span
              className="problem-display__type text-muted-foreground truncate font-mono text-[11px] sm:text-xs"
              title={problem.type}
              data-testid="problem-display__type"
            >
              {problem.type}
            </span>
          </div>
          <h3
            className="problem-display__title text-foreground mt-1.5 text-sm leading-tight font-semibold break-words sm:text-base"
            data-testid="problem-display__title"
          >
            {problem.title}
          </h3>
          {problem.detail ? (
            <p
              className="problem-display__detail text-muted-foreground mt-1 text-xs break-words sm:text-sm"
              data-testid="problem-display__detail"
            >
              {problem.detail}
            </p>
          ) : null}
        </div>
      </header>

      {violations.length > 0 ? (
        <ul
          className="problem-display__violations text-muted-foreground ml-10 list-disc space-y-0.5 text-xs sm:text-sm"
          data-testid="problem-display__violations"
        >
          {violations.map((v, i) => (
            <li key={`${v.field}-${i}`} className="break-words">
              <span className="text-foreground font-medium">{v.field}</span>: {v.message}
            </li>
          ))}
        </ul>
      ) : null}

      {debug ? <DebugSection debug={debug} rule={tone.rule} /> : null}

      <footer className="problem-display__footer flex flex-col items-start justify-between gap-x-4 gap-y-2 sm:flex-row sm:items-center">
        <CorrelationIdChip id={problem["correlation-id"]} label="Error ID:" className="min-w-0" />
        {action ? <div className="problem-display__action shrink-0">{action}</div> : null}
      </footer>
    </section>
  );
}
