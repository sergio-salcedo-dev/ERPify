/**
 * Real-time subscription port (Server-Sent Events / Mercure). Domain-level
 * contract: no framework, EventSource, or HTTP imports leak through here so the
 * transport can be swapped without touching consumers.
 */
export interface MercureSubscription {
  close(): void;
}

export interface MercureSubscribeOptions {
  /**
   * Invoked (debounced by the adapter) when the underlying stream errors. The
   * transport reconnects on its own; this hook lets the caller refresh the
   * subscriber authorization (re-mint the cookie) *before* that retry, so a
   * long-lived tab whose authorization JWT has expired re-authorizes and keeps
   * receiving updates instead of silently going stale.
   */
  onError?: () => void;
}

export interface MercureSubscriber {
  /**
   * Opens a subscription to the given Mercure topics. `onMessage` receives the
   * already JSON-parsed `data` field of each event. Returns a handle the caller
   * must `close()` on unmount.
   */
  subscribe(
    topics: readonly string[],
    onMessage: (data: unknown) => void,
    options?: MercureSubscribeOptions,
  ): MercureSubscription;
}
