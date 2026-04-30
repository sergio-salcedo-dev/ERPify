"use client";

import type { ReactNode } from "react";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { EmptyState } from "./EmptyState";
import { ProblemDisplay } from "./ProblemDisplay";

type AsyncState = "idle" | "loading" | "empty" | "error" | "ready";

interface AsyncBoundaryProps<TData> {
  /**
   * The current async state. Drives which slot renders.
   * - "idle": pre-fetch (typically identical to loading)
   * - "loading": fetch in flight
   * - "empty": fetch succeeded but result is empty
   * - "error": fetch failed; an RFC 9457 problem must be supplied
   * - "ready": fetch succeeded with data; children render with data
   */
  state: AsyncState;
  data?: TData;
  error?: ProblemDetails;
  /** Variant for the empty state. Defaults to "first-run". */
  emptyVariant?: "first-run" | "filtered-to-zero" | "permission-denied";
  emptyHeading?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  /** Custom slots override the default rendering for each state. */
  idle?: ReactNode;
  loading?: ReactNode;
  children: (data: TData) => ReactNode;
}

export function AsyncBoundary<TData>({
  state,
  data,
  error,
  emptyVariant = "first-run",
  emptyHeading = "Nothing here yet",
  emptyDescription = "There is nothing to show.",
  emptyAction,
  idle,
  loading,
  children,
}: AsyncBoundaryProps<TData>) {
  if (state === "idle" || state === "loading") {
    return (
      <div role="status" aria-live="polite" aria-busy="true" data-async-state={state}>
        {state === "idle" && idle ? idle : null}
        {state === "loading" ? (loading ?? <DefaultLoadingSkeleton />) : null}
      </div>
    );
  }

  if (state === "empty") {
    return (
      <EmptyState
        variant={emptyVariant}
        heading={emptyHeading}
        description={emptyDescription}
        action={emptyAction}
      />
    );
  }

  if (state === "error" && error) {
    return <ProblemDisplay problem={error} variant="panel" />;
  }

  if (state === "ready" && data !== undefined) {
    return <>{children(data)}</>;
  }

  return null;
}

function DefaultLoadingSkeleton() {
  return (
    <div className="animate-pulse space-y-2" aria-hidden="true">
      <div className="bg-muted h-4 w-3/4 rounded" />
      <div className="bg-muted h-4 w-1/2 rounded" />
      <div className="bg-muted h-4 w-2/3 rounded" />
    </div>
  );
}
