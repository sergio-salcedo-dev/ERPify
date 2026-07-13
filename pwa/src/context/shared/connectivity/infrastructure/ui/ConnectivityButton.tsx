"use client";

import type { ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/erpify";
import { cn } from "@/components/cn";

/** Label shown while a submission is in flight. */
const LOADING_LABEL = "Enviando…";

interface ConnectivityButtonProps {
  /** In-flight flag (typically RHF `isSubmitting`): shows the spinner + label. */
  loading: boolean;
  /** Extra gate (e.g. offline) that blocks submission without the loading state. */
  disabled?: boolean;
  type?: "submit" | "button";
  className?: string;
  testId?: string;
  children: ReactNode;
}

/**
 * Submit button with a connectivity-aware state machine:
 * `idle → loading (spinner + «Enviando…», aria-busy) → disabled-in-flight`.
 * It stays mounted across the transition, so keyboard focus is preserved, and it
 * is `disabled` while loading (and while the caller's `disabled` gate holds), so
 * a double submit cannot fire. Retrying is idempotent — the same click path runs
 * again once loading clears. It never raises a success toast; the outcome is
 * surfaced by the caller.
 */
export function ConnectivityButton({
  loading,
  disabled = false,
  type = "submit",
  className,
  testId,
  children,
}: Readonly<ConnectivityButtonProps>) {
  return (
    <Button
      type={type}
      disabled={loading || disabled}
      aria-busy={loading || undefined}
      className={cn("w-full", className)}
      data-testid={testId}
    >
      {loading ? (
        <>
          <Spinner className="size-4" />
          <span>{LOADING_LABEL}</span>
        </>
      ) : (
        children
      )}
    </Button>
  );
}
