"use client";

import { useEffect, useEffectEvent } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { TelemetryScope } from "@/context/shared/observability/domain/TelemetryScope";
import { telemetry } from "@/context/shared/observability/infrastructure";
import { mercureSubscriber } from "@/context/shared/real-time/infrastructure/BrowserMercureSubscriber";

export interface UseMercureRealtimeOptions<E> {
  /** Mercure topic IRIs to subscribe to. No-op when empty. */
  topics: readonly string[];
  /** Entity-specific authorize endpoint that mints the subscriber cookie. */
  authorizePath: string;
  /** Parses a raw event payload into a typed event, or null when unusable. */
  parse: (data: unknown) => E | null;
  /** Invoked with each parsed event. Always sees the latest closure. */
  onEvent: (event: E) => void;
  /**
   * Invoked when the stream re-opens after a drop (never on the initial connect).
   * Refetch here to reconcile updates missed during the reconnect gap.
   */
  onReconnect?: () => void;
  /**
   * Low-cardinality telemetry scope for this entity's feed, built with
   * `realtimeScope(entity)` (e.g. `realtimeScope("bank")` → "realtime:bank").
   */
  scope: TelemetryScope;
}

/**
 * Mints the subscriber cookie. Goes through the injected `HttpClient` so a refusal arrives
 * as a typed `HttpError` whose `problem.status` the classification below can read, and so a
 * session-expiry 401 takes the same bounce to the login screen as every other call. Rejects
 * so the caller skips opening a doomed stream (the hub never delivers private topics without
 * a valid cookie).
 *
 * The client sends cookies under fetch's `same-origin` default rather than forcing `include`:
 * the hub, the API and the document share an origin by construction (`BrowserMercureSubscriber`
 * builds the stream URL off the same base), and every other authenticated call in the PWA
 * already relies on that. A genuinely cross-origin API would break the whole session, not just
 * this handshake.
 */
async function authorize(authorizePath: string): Promise<void> {
  await container.get<HttpClient>("HttpClient").get<void>(authorizePath);
}

/**
 * A 401/403 is a stable authorization decision — an expired session or a revoked
 * permission — that no retry can change, so the stream must be abandoned rather than
 * re-authorized on every reconnect. Everything else (5xx, transport blip) is transient
 * and worth the debounced retry the subscriber already performs.
 */
function isTerminalDenial(error: unknown): boolean {
  if (!(error instanceof HttpError)) {
    return false;
  }

  return (
    error.problem.status === HttpStatus.UNAUTHORIZED ||
    error.problem.status === HttpStatus.FORBIDDEN
  );
}

/**
 * Subscribes to Mercure topics, authorizes (mints the subscriber cookie) before
 * opening the stream, and dispatches typed events to `onEvent`. Re-mints the
 * cookie on stream error so the EventSource's automatic reconnect stays
 * authorized, and abandons the stream when that re-mint is refused for good.
 * Reusable across every entity's realtime feed; failures are routed through
 * `telemetry` (never user-facing). No-op on the server and when `topics` is empty.
 */
export function useMercureRealtime<E>({
  topics,
  authorizePath,
  parse,
  onEvent,
  onReconnect,
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
      if (event === null) {
        // A well-formed message the entity parser doesn't recognise (unknown
        // type or a drifted payload shape) is unusable. Surface it for
        // diagnostics instead of dropping it without a trace, so a producer/
        // consumer contract drift is visible rather than silently stopping live
        // updates. (Unparseable JSON is reported one layer down in
        // BrowserMercureSubscriber.)
        telemetry.warn("unrecognized realtime payload", { scope });
        return;
      }
      onEvent(event);
    } catch (error) {
      telemetry.warn("event dispatch failed", { scope, cause: error });
    }
  });

  // Best-effort re-mint on reconnect; swallow + report, never throw. Answers whether the
  // refusal is terminal, so the caller can drop a stream that will never be authorized again.
  // The two outcomes carry distinct messages: telemetry coalesces on (level, scope, message),
  // so sharing one would let a preceding transient failure swallow the report of a feed that
  // just went dead for good — the single diagnostic that matters here.
  const refreshAuthorization = useEffectEvent(async (): Promise<boolean> => {
    try {
      await authorize(authorizePath);
      return false;
    } catch (error) {
      const terminal = isTerminalDenial(error);
      const message = terminal
        ? "realtime authorization revoked"
        : "subscriber-cookie refresh failed";
      telemetry.warn(message, { scope, cause: error });

      return terminal;
    }
  });

  const handleReconnect = useEffectEvent((): void => {
    // Fires on stream re-open after a drop (never the first connect); the caller
    // reconciles missed updates. Optional — a no-op when no `onReconnect` given.
    onReconnect?.();
  });

  useEffect(() => {
    const topicList = JSON.parse(topicsKey) as string[];
    if (topicList.length === 0 || globalThis.window === undefined) {
      return;
    }

    let subscription: { close(): void } | undefined;
    let cancelled = false;
    let refreshing = false;

    // The transport reconnects on its own, so a refusal that stands would be re-authorized on
    // every retry, forever — a silent request loop against an endpoint that will keep saying no,
    // and an audited access denial each time. Closing the stream is the only way to stop that
    // reconnect: while the EventSource lives, its errors keep arriving. Dropping the handle
    // afterwards makes the close exactly-once.
    //
    // The guards, in order: a stream error can still arrive after cleanup (the handle is closed
    // but the callback is already queued), and authorizing then would both cost a doomed request
    // and let a 401 navigate a page the user has already left. `refreshing` keeps a re-mint that
    // outlives the transport's own error debounce from stacking a second one — and it is released
    // in `finally`, since a latched guard would freeze the feed in exactly the silent limbo this
    // whole path exists to end. `subscription` being unset means the stream is not open yet.
    const handleStreamError = (): void => {
      if (cancelled || refreshing || subscription === undefined) {
        return;
      }

      refreshing = true;
      void (async (): Promise<void> => {
        try {
          if ((await refreshAuthorization()) && !cancelled) {
            subscription?.close();
            subscription = undefined;
          }
        } catch {
          // Reporting the refusal is itself fallible (the telemetry singleton fans out to real
          // sinks). A throwing sink must not escape as an unhandled rejection, and it leaves the
          // refusal unclassified — so keep the stream and let the next error retry.
        } finally {
          refreshing = false;
        }
      })();
    };

    void (async (): Promise<void> => {
      try {
        await authorize(authorizePath);
        if (!cancelled) {
          subscription = mercureSubscriber.subscribe(topicList, (data) => dispatch(data), {
            onError: handleStreamError,
            onReconnect: () => handleReconnect(),
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
