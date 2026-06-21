import { act, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { useLatestDebugToken } from "@/context/shared/dev-tools/infrastructure/ui/useLatestDebugToken";
import { EventTargetDebugTokenObserver } from "@/context/shared/DebugToken/infrastructure/EventTargetDebugTokenObserver";
import type { DebugToken } from "@/context/shared/DebugToken/domain/DebugToken";

const token: DebugToken = { token: "ccc", profilerUrl: "/_profiler/ccc" };

describe("useLatestDebugToken", () => {
  it("returns null before any publish, then the latest token after one", () => {
    const observer = new EventTargetDebugTokenObserver();
    const { result } = renderHook(() => useLatestDebugToken(observer));

    expect(result.current).toBeNull();

    act(() => observer.publish(token));

    expect(result.current).toEqual(token);
  });

  it("unsubscribes on unmount (no throw on later publish)", () => {
    const observer = new EventTargetDebugTokenObserver();
    const { unmount } = renderHook(() => useLatestDebugToken(observer));
    unmount();
    expect(() => observer.publish(token)).not.toThrow();
  });
});
