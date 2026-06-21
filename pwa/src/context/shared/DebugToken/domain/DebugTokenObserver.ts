import type { DebugToken } from "./DebugToken";

/**
 * Publish/subscribe seam carrying the latest {@link DebugToken} from the HTTP
 * layer (`FetchHttpClient`) to the dev-only toolbar UI. A domain port — never a
 * `window` global — so the HTTP client stays unit-testable and production can
 * bind an inert adapter. Bound under the Inversify key `"DebugTokenObserver"`.
 */
export interface DebugTokenObserver {
  /** Record the most recent token and notify current subscribers. */
  publish(token: DebugToken): void;
  /**
   * Register a listener. If a token was already published, the listener is
   * invoked immediately with the latest value (late-subscriber replay).
   * Returns an unsubscribe function.
   */
  subscribe(listener: (token: DebugToken) => void): () => void;
}
