import type {
  MercureSubscriber,
  MercureSubscription,
} from "@/context/shared/domain/RealTime/MercureSubscriber";

/**
 * Builds the same-origin Mercure hub URL. The PWA is served by FrankenPHP on the
 * same origin as the hub (`/.well-known/mercure`), so an empty API base resolves
 * to a relative URL against the current origin; an explicit
 * `NEXT_PUBLIC_SYMFONY_API_BASE_URL` is honoured when set.
 */
function mercureUrl(topics: readonly string[]): string {
  const base = (process.env.NEXT_PUBLIC_SYMFONY_API_BASE_URL ?? "").replace(/\/$/, "");
  const origin = globalThis.window?.location.origin ?? "http://localhost";
  const url = new URL(`${base}/.well-known/mercure`, origin);
  for (const topic of topics) {
    url.searchParams.append("topic", topic);
  }
  return url.toString();
}

/**
 * Browser EventSource adapter. `withCredentials` sends the same-origin Mercure
 * authorization cookie minted by the back-end so private topics are delivered.
 */
export class BrowserMercureSubscriber implements MercureSubscriber {
  subscribe(topics: readonly string[], onMessage: (data: unknown) => void): MercureSubscription {
    // EventSource is absent under SSR / jsdom; degrade to a no-op subscription
    // rather than throwing, so callers don't need to guard the environment.
    if (typeof EventSource === "undefined") {
      return { close: (): void => {} };
    }

    const source = new EventSource(mercureUrl(topics), { withCredentials: true });

    source.onmessage = (event: MessageEvent<string>): void => {
      try {
        onMessage(JSON.parse(event.data));
      } catch {
        // Ignore malformed payloads; the next valid event reconciles state.
      }
    };

    return { close: (): void => source.close() };
  }
}

/** Default subscriber singleton (mirrors `toastNotifier` / `dateTimeProvider`). */
export const mercureSubscriber: MercureSubscriber = new BrowserMercureSubscriber();
