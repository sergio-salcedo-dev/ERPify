"use client";

import { RefreshCw } from "lucide-react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { cn } from "@/components/cn";
import { ERROR_ACTION_BTN_CLASSES } from "@/context/shared/error/infrastructure/ui";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { safeInternalPath } from "@/context/shared/navigation/domain/safeInternalPath";
import { Routes } from "@/context/shared/routing/domain/Routes";

/** Query parameter the auth guard writes the interrupted route into. */
const RETRY_TARGET_PARAM = "next";

/**
 * Returns the visitor to the route the outage interrupted. Navigating there is the retry:
 * the session provider re-probes `/me` on a route other than the one its 503 was seen on,
 * so the guard there decides afresh instead of this page polling on a timer.
 *
 * `?next=` is untrusted — anyone can link here with one — so it passes `safeInternalPath`
 * and falls back to the back-office root. It is read from the live location when clicked,
 * which keeps `useSearchParams` and its Suspense boundary out of a static error page.
 */
export function RetryAfterOutageButton() {
  const router = useRouter();

  function handleRetry(): void {
    const next = new URLSearchParams(globalThis.location.search).get(RETRY_TARGET_PARAM);
    router.replace(safeHref(safeInternalPath(next, Routes.BACKOFFICE)));
  }

  return (
    <Button
      type="button"
      size="lg"
      onClick={handleRetry}
      title="Try again: return to the page you were on"
      aria-label="Try again"
      data-testid="maintenance__retry-button"
      className={cn(ERROR_ACTION_BTN_CLASSES, "maintenance__retry-button")}
    >
      <RefreshCw className="size-4" aria-hidden="true" />
      Try again
    </Button>
  );
}
