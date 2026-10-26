"use client";

import { useContext } from "react";
import {
  RecoveryReadinessContext,
  type RecoveryReadinessContextValue,
} from "../infrastructure/ui/RecoveryReadinessProvider";
import type { RecoverySecretStatus } from "../domain/RecoverySecret";

/** The shell's verdict on the caller's recovery secret. Throws outside <RecoveryReadinessProvider>. */
export function useRecoveryReadiness(): RecoveryReadinessContextValue {
  const ctx = useContext(RecoveryReadinessContext);
  if (!ctx)
    throw new Error("useRecoveryReadiness must be used within <RecoveryReadinessProvider>.");
  return ctx;
}

const NO_PROVIDER = (): void => {};

/**
 * Where a surface that learned the caller's recovery-secret status publishes it. A no-op outside
 * the provider, so a surface rendered on its own — a test, a page outside the back office —
 * keeps working without one.
 */
export function useRecoveryReadinessPublisher(): (status: RecoverySecretStatus) => void {
  return useContext(RecoveryReadinessContext)?.publish ?? NO_PROVIDER;
}
