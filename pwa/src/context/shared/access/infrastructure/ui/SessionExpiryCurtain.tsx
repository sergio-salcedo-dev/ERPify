"use client";

import Link from "next/link";
import { useEffect, useRef, type ReactNode } from "react";
import { useSessionExpiring } from "../../application/useSessionExpiring";
import { Routes } from "@/context/shared/routing/domain/Routes";

const HEADING = "Your session expired";
const LEAVING_MESSAGE = "Taking you to the sign-in screen…";

/**
 * Replaces the application while a session-expiry bounce is in flight.
 *
 * `location.replace()` SCHEDULES a navigation; it does not stop execution. So the 401 that
 * started the bounce still travels back to its caller, still throws, and whatever error UI
 * that screen owns paints during the unload window — an "unauthorized" flash on the way to
 * a screen that explains the situation properly. The window is short but not zero, and it
 * is longest exactly when the network is slow, which is when a session is most likely to
 * have expired unnoticed.
 *
 * Suppressing it from here rather than from the transport is what keeps the HTTP contract
 * intact: a 401 still produces the same `HttpError` carrying the same RFC 9457 problem, so
 * no caller — hook, provider, resource list — has to learn a second failure shape. The
 * error simply has nowhere to render, on every surface at once.
 *
 * "Every surface" is a claim about the React tree, so it covers exactly what this component
 * is an ancestor of. Portalled dialogs and sheets keep their React parentage and go with
 * the children; the toast viewport does NOT unless it is mounted inside this boundary,
 * which is why `app/layout.tsx` puts it there — Sonner's queue is module state, so a toast
 * raised by the same 401's catch would otherwise render on top of this curtain and point
 * the user at error details that were just unmounted.
 *
 * Blanking the app is only safe because the bounce is bounded: an ignored navigation
 * releases the claim and this lifts. The sign-in link is the second half of that — a bound
 * the user holds rather than one they wait out.
 */
export function SessionExpiryCurtain({ children }: Readonly<{ children: ReactNode }>) {
  const expiring = useSessionExpiring();
  const curtain = useRef<HTMLElement>(null);

  useEffect(() => {
    if (!expiring) return;
    // The control that raised the 401 was just destroyed with the rest of the tree, so focus
    // has fallen to <body>. Without this a keyboard user's next Tab starts from the top of a
    // document whose content is gone, and a screen reader is left on a node that no longer
    // exists.
    curtain.current?.focus();
  }, [expiring]);

  if (!expiring) return <>{children}</>;

  return (
    <main
      ref={curtain}
      tabIndex={-1}
      data-testid="session-expiry__curtain"
      className="session-expiry__curtain bg-background text-foreground flex min-h-screen flex-col items-center justify-center gap-3 p-6 text-center"
    >
      {/* `role="alert"` rather than a status region: this is a system alert (DESIGN.md), and
          it is mounted ALREADY CARRYING its text. A live region born with content is the
          classic non-announcement — readers register it on insertion and speak subsequent
          mutations — while `alert` is the one role browsers fire on insertion itself. */}
      <div role="alert" className="session-expiry__alert flex flex-col gap-1">
        <h1 data-testid="session-expiry__heading" className="text-lg font-semibold">
          {HEADING}
        </h1>
        <p data-testid="session-expiry__message" className="text-muted-foreground text-sm">
          {LEAVING_MESSAGE}
        </p>
      </div>
      {/* The escape hatch. Releasing the claim is the automatic bound; this is the one the
          user can take now, and it is what keeps a navigation that never commits from
          leaving them on a screen with no controls at all. */}
      <Link
        href={Routes.LOGIN}
        data-testid="session-expiry__sign-in"
        className="text-primary text-sm font-medium underline underline-offset-4"
      >
        Go to the sign-in screen
      </Link>
    </main>
  );
}
