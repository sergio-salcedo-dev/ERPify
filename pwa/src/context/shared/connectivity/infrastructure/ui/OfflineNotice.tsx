"use client";

import { WifiOff } from "lucide-react";
import { cn } from "@/components/cn";

/** Warning copy shown when the browser reports no connection. */
const OFFLINE_MESSAGE = "Sin conexión. Reintenta cuando recuperes señal.";

/**
 * In-form band, mounted above the submit button while the browser is offline.
 * It is a polite live region (`role="status"`), so assistive tech announces it
 * when it appears, and it is warning-toned (never danger/red — the user did
 * nothing wrong). Rendering it conditionally never clears the form: typed input
 * is untouched, so the user can retry the moment signal returns.
 */
export function OfflineNotice({ testId }: Readonly<{ testId?: string }>) {
  return (
    <div
      role="status"
      aria-live="polite"
      className={cn(
        "connectivity-notice border-warning/30 bg-warning/10 text-warning-strong",
        "flex items-center gap-2 rounded-md border px-3 py-2 text-sm",
      )}
      data-testid={testId}
    >
      <WifiOff className="connectivity-notice__icon size-4 shrink-0" aria-hidden="true" />
      <span>{OFFLINE_MESSAGE}</span>
    </div>
  );
}
