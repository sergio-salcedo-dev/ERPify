import { describe, expect, it } from "vitest";
import { NoopDebugTokenObserver } from "@/context/shared/infrastructure/DebugToken/NoopDebugTokenObserver";

describe("NoopDebugTokenObserver", () => {
  it("never notifies a subscriber and publish is inert", () => {
    const observer = new NoopDebugTokenObserver();
    let called = false;
    const unsubscribe = observer.subscribe(() => {
      called = true;
    });

    observer.publish({ token: "abc", profilerUrl: "/_profiler/abc" });

    expect(called).toBe(false);
    expect(typeof unsubscribe).toBe("function");
    expect(() => unsubscribe()).not.toThrow();
  });
});
