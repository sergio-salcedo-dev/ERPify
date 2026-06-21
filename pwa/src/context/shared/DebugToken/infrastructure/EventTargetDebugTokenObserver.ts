import { injectable } from "inversify";
import type { DebugToken } from "@/context/shared/DebugToken/domain/DebugToken";
import type { DebugTokenObserver } from "@/context/shared/DebugToken/domain/DebugTokenObserver";

const EVENT_NAME = "erpify:debug-token";

/**
 * Dev adapter backed by an {@link EventTarget}. Retains the latest token so a
 * subscriber attaching after the first `/api/*` response (the common case — the
 * toolbar mounts on first paint, the response lands shortly after, or a route
 * change replays) is delivered the current value immediately.
 */
@injectable()
export class EventTargetDebugTokenObserver implements DebugTokenObserver {
  private readonly bus = new EventTarget();
  private latest: DebugToken | null = null;

  publish(token: DebugToken): void {
    this.latest = token;
    this.bus.dispatchEvent(new CustomEvent<DebugToken>(EVENT_NAME, { detail: token }));
  }

  subscribe(listener: (token: DebugToken) => void): () => void {
    const handler = (event: Event) => {
      listener((event as CustomEvent<DebugToken>).detail);
    };
    this.bus.addEventListener(EVENT_NAME, handler);

    if (this.latest !== null) {
      listener(this.latest);
    }

    return () => this.bus.removeEventListener(EVENT_NAME, handler);
  }
}
