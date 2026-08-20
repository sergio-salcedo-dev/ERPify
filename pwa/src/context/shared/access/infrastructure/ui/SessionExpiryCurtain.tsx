"use client";

import type { ReactNode } from "react";
import { useSessionExpiring } from "../../application/useSessionExpiring";

/** What the user is told while the document is on its way to the sign-in screen. */
const LEAVING_MESSAGE = "Your session expired. Taking you to the sign-in screen…";

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
 * error simply has nowhere to render, on every surface at once (inline messages, toasts,
 * boundaries), including the ones nobody would have thought to audit.
 *
 * Blanking the app is only safe because the bounce is now bounded: an ignored navigation
 * releases the claim, this curtain lifts, and the user gets the application back instead of
 * an empty document with no route away.
 */
export function SessionExpiryCurtain({ children }: Readonly<{ children: ReactNode }>) {
  const expiring = useSessionExpiring();

  if (!expiring) return <>{children}</>;

  return (
    <div
      data-testid="session-expiry__curtain"
      className="session-expiry__curtain bg-background text-muted-foreground flex min-h-screen items-center justify-center p-6"
    >
      {/* `<output>` carries the `status` role natively; `aria-live` is spelled out anyway, for
          the assistive technology that reads the attribute and not the implicit mapping. The
          message is inserted rather than replaced, so it announces. */}
      <output
        aria-live="polite"
        data-testid="session-expiry__message"
        className="session-expiry__message text-sm"
      >
        {LEAVING_MESSAGE}
      </output>
    </div>
  );
}
