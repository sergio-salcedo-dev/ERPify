import { realtimeScope } from "@/context/shared/domain/Observability/TelemetryScope";
import type {
  MercureSubscribeOptions,
  MercureSubscriber,
  MercureSubscription,
} from "@/context/shared/domain/RealTime/MercureSubscriber";
import { RealtimeTransport } from "@/context/shared/domain/RealTime/RealtimeTransport";
import { telemetry } from "@/context/shared/infrastructure/Observability";

/**
 * Builds the same-origin Mercure hub URL. The PWA is served by FrankenPHP on the
 * same origin as the hub (`/.well-known/mercure`), so an empty API base resolves
 * to a relative URL against the current origin; an explicit
 * `NEXT_PUBLIC_API_BASE_URL` is honoured when set.
 */
function mercureUrl(topics: readonly string[]): string {
  const base = (process.env.NEXT_PUBLIC_API_BASE_URL?.trim() ?? "").replace(/\/$/, "");
  const origin = globalThis.window?.location.origin ?? "http://localhost";
  const url = new URL(`${base}/.well-known/mercure`, origin);
  for (const topic of topics) {
    url.searchParams.append("topic", topic);
  }
  return url.toString();
}

/**
 * Minimum gap between authorization refreshes triggered by stream errors.
 * EventSource retries every few seconds, so a reconnect storm (e.g. a hub
 * restart) would otherwise fire the authorize request on every attempt; this
 * still refreshes comfortably within the subscriber cookie's lifetime.
 */
const REAUTHORIZE_DEBOUNCE_MS = 30_000;

/**
 * Browser EventSource adapter. `withCredentials` sends the same-origin Mercure
 * authorization cookie minted by the back-end so private topics are delivered.
 */
export class BrowserMercureSubscriber implements MercureSubscriber {
  subscribe(
    topics: readonly string[],
    onMessage: (data: unknown) => void,
    options?: MercureSubscribeOptions,
  ): MercureSubscription {
    // EventSource is absent under SSR / jsdom; degrade to a no-op subscription
    // rather than throwing, so callers don't need to guard the environment.
    if (typeof EventSource === "undefined") {
      return { close: (): void => {} };
    }

    const source = new EventSource(mercureUrl(topics), { withCredentials: true });

    source.onmessage = (event: MessageEvent<string>): void => {
      try {
        onMessage(JSON.parse(event.data));
      } catch (error) {
        // Malformed payload — the next valid event reconciles state. Report for
        // diagnostics (never user-facing); shared across every entity's stream.
        telemetry.warn("malformed realtime payload", {
          scope: realtimeScope(RealtimeTransport.MERCURE),
          cause: error,
        });
      }
    };

    const onError = options?.onError;
    if (onError) {
      // EventSource auto-reconnects on error. If the subscriber cookie's JWT has
      // expired (long-lived tab), every reconnect is rejected by the hub and
      // updates stop silently. Refresh authorization (debounced) so the imminent
      // retry carries a valid cookie; for a transient blip the cookie is still
      // valid and this is a harmless no-op.
      let lastReauthorizeAt = 0;
      source.onerror = (): void => {
        const now = Date.now();
        if (now - lastReauthorizeAt < REAUTHORIZE_DEBOUNCE_MS) {
          return;
        }
        lastReauthorizeAt = now;
        onError();
      };
    }

    const onReconnect = options?.onReconnect;
    if (onReconnect) {
      // EventSource fires `onopen` on every successful (re)connection. The first
      // open is the initial subscribe — the caller already holds current data, so
      // skip it; a later open means the stream dropped and recovered, and updates
      // published during the gap were missed, so let the caller reconcile.
      let opened = false;
      source.onopen = (): void => {
        if (opened) {
          onReconnect();
        }
        opened = true;
      };
    }

    return { close: (): void => source.close() };
  }
}

/** Default subscriber singleton (mirrors `toastNotifier` / `dateTimeProvider`). */
export const mercureSubscriber: MercureSubscriber = new BrowserMercureSubscriber();
