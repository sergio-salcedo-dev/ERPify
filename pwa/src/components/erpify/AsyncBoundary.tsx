"use client";

import type { ReactNode } from "react";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { ApiStatus, ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { EmptyState } from "./EmptyState";
import { ProblemDisplay } from "./ProblemDisplay";

/**
 * Accepted boundary states. {@link ApiStatus.IDLE} covers the pre-fetch
 * window; the rest are UI render states from {@link ViewStatus}. We
 * deliberately forbid raw string literals at consumer sites — pass
 * `ApiStatus.IDLE` / `ViewStatus.LOADING` / etc.
 */
export type AsyncBoundaryState = typeof ApiStatus.IDLE | ViewStatus;

interface AsyncBoundaryProps<TData> {
  /**
   * The current async state. Drives which slot renders.
   * - "idle": pre-fetch (typically identical to loading)
   * - "loading": fetch in flight
   * - "empty": fetch succeeded but result is empty
   * - "error": fetch failed; an RFC 9457 problem must be supplied
   * - "ready": fetch succeeded with data; children render with data
   */
  state: AsyncBoundaryState;
  data?: TData;
  error?: ProblemDetails;
  /** Variant for the empty state. Defaults to "first-run". */
  emptyVariant?: "first-run" | "filtered-to-zero" | "permission-denied";
  emptyHeading?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  /** Optional recovery action rendered below the error panel (e.g. Retry). */
  errorAction?: ReactNode;
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
  errorAction,
  idle,
  loading,
  children,
}: Readonly<AsyncBoundaryProps<TData>>) {
  if (state === ApiStatus.IDLE || state === ViewStatus.LOADING) {
    return (
      <div role="status" aria-live="polite" aria-busy="true" data-async-state={state}>
        {state === ApiStatus.IDLE && idle ? idle : null}
        {state === ViewStatus.LOADING ? (loading ?? <DefaultLoadingSkeleton />) : null}
      </div>
    );
  }

  if (state === ViewStatus.EMPTY) {
    return (
      <EmptyState
        variant={emptyVariant}
        heading={emptyHeading}
        description={emptyDescription}
        action={emptyAction}
      />
    );
  }

  if (state === ViewStatus.ERROR && error) {
    return (
      <div className="async-boundary__error space-y-4">
        <ProblemDisplay problem={error} variant="panel" />
        {errorAction ? (
          <div className="async-boundary__error-action flex justify-center">{errorAction}</div>
        ) : null}
      </div>
    );
  }

  if (state === ViewStatus.READY && data !== undefined) {
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
