import { injectable } from "inversify";
import type { DebugToken } from "@/context/shared/debug-token/domain/DebugToken";
import type { DebugTokenObserver } from "@/context/shared/debug-token/domain/DebugTokenObserver";

/**
 * Production adapter: the toolbar does not exist in prod, so both operations are
 * inert. Bound as `"DebugTokenObserver"` in production builds and used as the
 * default for `FetchHttpClient` when no observer is injected (tests).
 */
@injectable()
export class NoopDebugTokenObserver implements DebugTokenObserver {
  publish(_token: DebugToken): void {
    // Inert by design: production has no debug toolbar to notify.
  }

  subscribe(_listener: (token: DebugToken) => void): () => void {
    return () => {};
  }
}
