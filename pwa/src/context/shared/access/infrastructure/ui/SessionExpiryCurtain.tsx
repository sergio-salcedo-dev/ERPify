"use client";

import Link from "next/link";
import { useEffect, useRef, type ReactNode } from "react";
import { useSessionExpiring } from "../../application/useSessionExpiring";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";

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
 * the children; the toast viewport is global infrastructure mounted OUTSIDE this boundary
 * (`app/layout.tsx`) and outlives it, so two things hold it back instead: `dismissAll()`
 * below clears whatever is visible the instant the bounce starts, and the curtain's own
 * z-index (set above Sonner's `--z-index: 999999999` default) keeps anything raised
 * AFTER that instant — a toast in flight from an unrelated interaction — from painting on
 * top of it. Neither alone covers both directions; together they do.
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
    // Sonner's queue is module state and its viewport is global infrastructure that never
    // unmounts, so a toast raised by the same 401 would otherwise sit on top of this curtain,
    // pointing at error details that are no longer reachable — clearing it here is what the
    // curtain's own doc comment promises for "every surface at once".
    toastNotifier.dismissAll();
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
      className="session-expiry__curtain fixed inset-0 z-[2147483647] bg-background text-foreground flex flex-col items-center justify-center gap-3 p-6 text-center"
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
