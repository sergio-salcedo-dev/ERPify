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

/** Origin no real deployment can hold, used only to read a resolved target's pathname. */
const RESOLVE_ORIGIN = "https://retry-after-outage.invalid";

/**
 * The route the retry returns to. A target that is this page itself is refused as well as a
 * hostile one: returning here re-probes nothing, so the button would appear to do nothing.
 */
function retryTarget(next: string | null): string {
  const target = safeInternalPath(next, Routes.BACKOFFICE);
  const { pathname } = new URL(target, RESOLVE_ORIGIN);
  const isThisPage = pathname.replace(/\/+$/, "") === Routes.MAINTENANCE;
  return isThisPage ? Routes.BACKOFFICE : target;
}

/**
 * Returns the visitor to the route the outage interrupted. Navigating there is the retry:
 * the session provider re-probes `/me` on a route other than the one its 502/503/504 was seen on,
 * so the guard there decides afresh instead of this page polling on a timer.
 *
 * `?next=` is untrusted — anyone can link here with one — so it passes `safeInternalPath`
 * and falls back to the back-office root, as it does when it names this page. It is read
 * from the live location when clicked, which keeps `useSearchParams` and its Suspense
 * boundary out of a static error page.
 */
export function RetryAfterOutageButton() {
  const router = useRouter();

  function handleRetry(): void {
    const next = new URLSearchParams(globalThis.location.search).get(RETRY_TARGET_PARAM);
    router.replace(safeHref(retryTarget(next)));
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
