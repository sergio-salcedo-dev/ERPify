"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { X } from "lucide-react";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { CopyButton } from "./CopyButton";
import { ProblemDisplay, isProductionEnv } from "./ProblemDisplay";

interface MutationErrorProps {
  /** RFC 9457 envelope from the failed mutation. Rendered verbatim. */
  problem: ProblemDetails;
  onDismiss: () => void;
  /** Typed recovery action resolved by the owner (e.g. "Refresh list" for a stale 404). */
  action?: ReactNode;
  /**
   * Move focus to the surface when it appears or its problem is replaced
   * (default). Disable only on showcase surfaces (e.g. the error gallery)
   * where the error is not the outcome of a user action.
   */
  focusOnMount?: boolean;
  testId: string;
  className?: string;
}

/**
 * Persistent mutation-error surface (UX contract «Errores de mutación —
 * superficie persistente», 2026-06-04). Anchored by its owner next to the
 * mutation's origin; stays until dismissed, replaced by a retry, or cleared
 * by a later success — so the user can read the full error, screenshot it,
 * and copy the message, the error code, or the whole payload.
 *
 * Composes `<ProblemDisplay>` (tones, fields, env-aware debug, `role="alert"`
 * announcement) and adds: a dismiss ×, the copy toolbar, and the focus
 * contract — the wrapper takes focus when the error appears (or is replaced)
 * so closing the confirm dialog never strands focus on `<body>`.
 */
export function MutationError({
  problem,
  onDismiss,
  action,
  focusOnMount = true,
  testId,
  className,
}: Readonly<MutationErrorProps>) {
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!focusOnMount) return;
    containerRef.current?.focus();
  }, [problem, focusOnMount]);

  return (
    <div
      ref={containerRef}
      tabIndex={-1}
      className={cn("mutation-error relative outline-none", className)}
      data-testid={testId}
    >
      <ProblemDisplay
        problem={problem}
        variant="inline"
        className="mutation-error__problem pr-12"
        action={
          <div className="mutation-error__actions flex flex-wrap items-center gap-1.5">
            <CopyToolbar problem={problem} testId={testId} />
            {action ? <span className="mutation-error__recovery">{action}</span> : null}
          </div>
        }
      />
      <Button
        type="button"
        variant="ghost"
        size="icon-sm"
        onClick={onDismiss}
        className="mutation-error__dismiss absolute top-2 right-2"
        aria-label="Dismiss"
        title="Dismiss error"
        data-testid={`${testId}__dismiss`}
      >
        <X className="size-3.5" aria-hidden="true" />
        <span className="sr-only">Dismiss</span>
      </Button>
    </div>
  );
}

function CopyToolbar({ problem, testId }: Readonly<{ problem: ProblemDetails; testId: string }>) {
  const message = [problem.title, problem.detail].filter(Boolean).join("\n");
  const code = `${problem.type} · ${problem.status}`;

  return (
    <>
      <CopyButton
        value={message}
        variant="ghost"
        label="Copy message"
        copiedLabel="Message copied"
        title="Copy the error message"
        testId={`${testId}__copy-message`}
      />
      <CopyButton
        value={code}
        variant="ghost"
        label="Copy code"
        copiedLabel="Code copied"
        title={`Copy the error code (${code})`}
        testId={`${testId}__copy-code`}
      />
      <CopyButton
        value={JSON.stringify(copyableProblem(problem), null, 2)}
        variant="ghost"
        label="Copy JSON"
        copiedLabel="JSON copied"
        title="Copy the full error payload as JSON"
        testId={`${testId}__copy-json`}
      />
    </>
  );
}

/**
 * The copied payload is the problem exactly as received from the wire, with
 * one defense-in-depth exception: in production builds the `debug` extension
 * is stripped, mirroring what `<ProblemDisplay>` renders — what you can copy
 * is exactly what you can see.
 */
function copyableProblem(problem: ProblemDetails): Record<string, unknown> {
  if (!isProductionEnv()) return problem;
  const { debug: _stripped, ...rest } = problem;
  return rest;
}
