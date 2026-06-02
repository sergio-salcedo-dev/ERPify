"use client";

import { useEffect, useEffectEvent } from "react";
import { telemetry } from "@/context/shared/infrastructure/Observability";
import { mercureSubscriber } from "@/context/shared/infrastructure/RealTime/BrowserMercureSubscriber";

export interface UseMercureRealtimeOptions<E> {
  /** Mercure topic IRIs to subscribe to. No-op when empty. */
  topics: readonly string[];
  /** Entity-specific authorize endpoint that mints the subscriber cookie. */
  authorizePath: string;
  /** Parses a raw event payload into a typed event, or null when unusable. */
  parse: (data: unknown) => E | null;
  /** Invoked with each parsed event. Always sees the latest closure. */
  onEvent: (event: E) => void;
  /** Low-cardinality telemetry scope, e.g. "realtime:bank". */
  scope: string;
}

async function authorize(authorizePath: string): Promise<void> {
  // Resolve an absolute URL against the current origin so `fetch` behaves like
  // the EventSource subscription (a bare relative path is unparseable outside a
  // browser, e.g. under test/SSR).
  const base = (process.env.NEXT_PUBLIC_API_BASE_URL ?? "").replace(/\/$/, "");
  const origin = globalThis.window?.location.origin ?? "http://localhost";
  const url = new URL(`${base}${authorizePath}`, origin);
  const response = await fetch(url, { credentials: "include", cache: "no-store" });
  if (!response.ok) {
    // Reject so the caller skips opening a doomed stream (the hub never delivers
    // private topics without a valid subscriber cookie).
    throw new Error(`Mercure authorize failed: ${response.status}`);
  }
}

/**
 * Subscribes to Mercure topics, authorizes (mints the subscriber cookie) before
 * opening the stream, and dispatches typed events to `onEvent`. Re-mints the
 * cookie on stream error so the EventSource's automatic reconnect stays
 * authorized. Reusable across every entity's realtime feed; failures are routed
 * through `telemetry` (never user-facing). No-op on the server and when `topics`
 * is empty.
 */
export function useMercureRealtime<E>({
  topics,
  authorizePath,
  parse,
  onEvent,
  scope,
}: UseMercureRealtimeOptions<E>): void {
  // `topicsKey` keeps the EventSource open across unrelated re-renders; it
  // changes exactly when the (blank-filtered) topic set changes. JSON keying is
  // delimiter-safe — unlike a "|" join, a topic IRI may contain any character,
  // and blank members never open a junk subscription.
  const topicsKey = JSON.stringify(topics.map((topic) => topic.trim()).filter(Boolean));

  // Effect Events always see the latest `parse` / `onEvent` (and the `authorizePath`
  // / `scope` they close over) without being effect dependencies, so changing
  // handler identity each render never tears the stream — and `authorize(authorizePath)`
  // inside `refreshAuthorization` is never stale.
  const dispatch = useEffectEvent((data: unknown): void => {
    // A throwing `parse` / `onEvent` must never escape the EventSource message
    // handler as an unhandled error; route it through telemetry like the other
    // realtime failures (the seam is generic, so future entities may throw here).
    try {
      const event = parse(data);
      if (event !== null) {
        onEvent(event);
      }
    } catch (error) {
      telemetry.warn("event dispatch failed", { scope, cause: error });
    }
  });

  const refreshAuthorization = useEffectEvent((): void => {
    // Best-effort re-mint on reconnect; swallow + report, never throw.
    void authorize(authorizePath).catch((error) =>
      telemetry.warn("subscriber-cookie refresh failed", { scope, cause: error }),
    );
  });

  useEffect(() => {
    const topicList = JSON.parse(topicsKey) as string[];
    if (topicList.length === 0 || globalThis.window === undefined) {
      return;
    }

    let subscription: { close(): void } | undefined;
    let cancelled = false;

    void (async (): Promise<void> => {
      try {
        await authorize(authorizePath);
        if (!cancelled) {
          subscription = mercureSubscriber.subscribe(topicList, (data) => dispatch(data), {
            onError: () => refreshAuthorization(),
          });
        }
      } catch (error) {
        // Best-effort: a missing cookie, an absent EventSource (SSR/test), or a
        // transient network error must never surface as an unhandled rejection.
        telemetry.warn("subscription skipped", { scope, cause: error });
      }
    })();

    return (): void => {
      cancelled = true;
      subscription?.close();
    };
    // `authorizePath` / `scope` are stable per call site, so this preserves the
    // "topicsKey is effectively the only trigger" behavior.
  }, [topicsKey, authorizePath, scope]);
}
