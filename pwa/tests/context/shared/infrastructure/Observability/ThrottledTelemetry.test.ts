import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from "vitest";
import { ThrottledTelemetry } from "@/context/shared/infrastructure/Observability/ThrottledTelemetry";
import type { Telemetry, TelemetryContext } from "@/context/shared/domain/Observability/Telemetry";

type TelemetryFn = (message: string, context?: TelemetryContext) => void;

function fakeTelemetry(): Telemetry & { warn: Mock<TelemetryFn>; error: Mock<TelemetryFn> } {
  return { warn: vi.fn<TelemetryFn>(), error: vi.fn<TelemetryFn>() };
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(0);
});

afterEach(() => {
  vi.useRealTimers();
});

describe("ThrottledTelemetry", () => {
  it("forwards the first occurrence to the inner telemetry unchanged", () => {
    const inner = fakeTelemetry();
    const cause = new Error("x");

    new ThrottledTelemetry(inner, 1000).warn("boom", { scope: "realtime:mercure", cause });

    expect(inner.warn).toHaveBeenCalledTimes(1);
    expect(inner.warn).toHaveBeenCalledWith("boom", { scope: "realtime:mercure", cause });
  });

  it("coalesces identical messages within the window into a single emit", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner, 1000);

    t.warn("boom", { scope: "s" });
    vi.setSystemTime(500);
    t.warn("boom", { scope: "s" });
    vi.setSystemTime(999);
    t.warn("boom", { scope: "s" });

    expect(inner.warn).toHaveBeenCalledTimes(1);
  });

  it("re-emits after the window elapses and reports how many were suppressed", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner, 1000);

    t.warn("boom", { scope: "s" }); // emit
    vi.setSystemTime(500);
    t.warn("boom", { scope: "s" }); // suppressed (1)
    vi.setSystemTime(700);
    t.warn("boom", { scope: "s" }); // suppressed (2)
    vi.setSystemTime(1001);
    const cause = new Error("again");
    t.warn("boom", { scope: "s", cause }); // re-emit, carrying the latest cause

    expect(inner.warn).toHaveBeenCalledTimes(2);
    expect(inner.warn).toHaveBeenLastCalledWith("boom (+2 suppressed)", { scope: "s", cause });
  });

  it("keeps different scopes as independent keys", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner, 1000);

    t.warn("boom", { scope: "realtime:bank" });
    t.warn("boom", { scope: "realtime:invoice" });

    expect(inner.warn).toHaveBeenCalledTimes(2);
  });

  it("keeps warn and error independent even for the same scope + message", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner, 1000);

    t.warn("dup", { scope: "s" });
    t.error("dup", { scope: "s" });

    expect(inner.warn).toHaveBeenCalledTimes(1);
    expect(inner.error).toHaveBeenCalledTimes(1);
  });

  it("does not coalesce distinct messages", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner, 1000);

    t.warn("one", { scope: "s" });
    t.warn("two", { scope: "s" });

    expect(inner.warn).toHaveBeenCalledTimes(2);
  });

  it("coalesces a flood with no scope (defaults the scope segment of the key)", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner, 1000);

    t.warn("anon");
    vi.setSystemTime(500);
    t.warn("anon");

    expect(inner.warn).toHaveBeenCalledTimes(1);
  });
});
