/**
 * Real-time subscription port (Server-Sent Events / Mercure). Domain-level
 * contract: no framework, EventSource, or HTTP imports leak through here so the
 * transport can be swapped without touching consumers.
 */
export interface MercureSubscription {
  close(): void;
}

export interface MercureSubscriber {
  /**
   * Opens a subscription to the given Mercure topics. `onMessage` receives the
   * already JSON-parsed `data` field of each event. Returns a handle the caller
   * must `close()` on unmount.
   */
  subscribe(topics: readonly string[], onMessage: (data: unknown) => void): MercureSubscription;
}
