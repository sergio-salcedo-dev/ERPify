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
  /**
   * Invoked when the stream re-opens after a drop — i.e. on every successful
   * (re)connection *except the first*. The transport reconnects on its own and
   * any updates published during the gap are lost, so this lets the caller
   * refetch to reconcile the view with the source of truth. It never fires on the
   * initial connection (the caller already holds fresh data then).
   */
  onReconnect?: () => void;
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
