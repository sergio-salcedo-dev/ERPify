import { injectable } from "inversify";
import type { DebugToken } from "@/context/shared/domain/DebugToken/DebugToken";
import type { DebugTokenObserver } from "@/context/shared/domain/DebugToken/DebugTokenObserver";

/**
 * Production adapter: the toolbar does not exist in prod, so both operations are
 * inert. Bound as `"DebugTokenObserver"` in production builds and used as the
 * default for `FetchHttpClient` when no observer is injected (tests).
 */
@injectable()
export class NoopDebugTokenObserver implements DebugTokenObserver {
  publish(_token: DebugToken): void {}

  subscribe(_listener: (token: DebugToken) => void): () => void {
    return () => {};
  }
}
