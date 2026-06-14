import { describe, expect, it, vi } from "vitest";
import { EventTargetDebugTokenObserver } from "@/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver";
import type { DebugToken } from "@/context/shared/domain/DebugToken/DebugToken";

const tokenA: DebugToken = { token: "aaa", profilerUrl: "/_profiler/aaa" };
const tokenB: DebugToken = { token: "bbb", profilerUrl: "/_profiler/bbb" };

describe("EventTargetDebugTokenObserver", () => {
  it("delivers a published token to a current subscriber", () => {
    const observer = new EventTargetDebugTokenObserver();
    const listener = vi.fn();
    observer.subscribe(listener);

    observer.publish(tokenA);

    expect(listener).toHaveBeenCalledWith(tokenA);
  });

  it("replays the latest token to a subscriber that attaches after publish", () => {
    const observer = new EventTargetDebugTokenObserver();
    observer.publish(tokenA);
    observer.publish(tokenB);

    const listener = vi.fn();
    observer.subscribe(listener);

    expect(listener).toHaveBeenCalledTimes(1);
    expect(listener).toHaveBeenCalledWith(tokenB);
  });

  it("stops delivering after unsubscribe", () => {
    const observer = new EventTargetDebugTokenObserver();
    const listener = vi.fn();
    const unsubscribe = observer.subscribe(listener);
    unsubscribe();

    observer.publish(tokenA);

    expect(listener).not.toHaveBeenCalled();
  });
});
