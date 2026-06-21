import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from "vitest";
import { ThrottledTelemetry } from "@/context/shared/Observability/infrastructure/ThrottledTelemetry";
import type { Telemetry, TelemetryContext } from "@/context/shared/Observability/domain/Telemetry";

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

  it("re-emits at exactly windowMs (the suppression window is half-open: [0, windowMs))", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner, 1000);

    t.warn("boom", { scope: "s" }); // emit at t=0
    vi.setSystemTime(1000); // exactly windowMs later
    t.warn("boom", { scope: "s" });

    expect(inner.warn).toHaveBeenCalledTimes(2);
  });

  it("uses a ~10s default window when none is supplied", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner); // default 10_000ms

    t.warn("boom", { scope: "s" });
    vi.setSystemTime(9_999);
    t.warn("boom", { scope: "s" }); // still inside the default window
    expect(inner.warn).toHaveBeenCalledTimes(1);

    vi.setSystemTime(10_000);
    t.warn("boom", { scope: "s" }); // window elapsed
    expect(inner.warn).toHaveBeenCalledTimes(2);
  });

  it("re-emits when the clock jumps backward instead of wedging the key silent", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner, 1000);

    vi.setSystemTime(10_000);
    t.warn("boom", { scope: "s" }); // emit at t=10s
    vi.setSystemTime(5_000); // NTP / sleep-wake steps the clock backward
    t.warn("boom", { scope: "s" });

    expect(inner.warn).toHaveBeenCalledTimes(2);
  });

  it("rejects a non-positive windowMs at construction", () => {
    const inner = fakeTelemetry();

    expect(() => new ThrottledTelemetry(inner, 0)).toThrow(RangeError);
    expect(() => new ThrottledTelemetry(inner, -1)).toThrow(RangeError);
  });

  it("rejects a non-positive maxKeys / ttlMs / sweepIntervalOps at construction", () => {
    const inner = fakeTelemetry();

    expect(() => new ThrottledTelemetry(inner, 1000, { maxKeys: 0 })).toThrow(RangeError);
    expect(() => new ThrottledTelemetry(inner, 1000, { ttlMs: 0 })).toThrow(RangeError);
    expect(() => new ThrottledTelemetry(inner, 1000, { sweepIntervalOps: 0 })).toThrow(RangeError);
  });

  it("does not collide distinct (scope, message) tuples that would share a delimiter-joined key", () => {
    const inner = fakeTelemetry();
    const t = new ThrottledTelemetry(inner, 1000);

    // Under a naive `${level}|${scope}|${message}` key both collapse to "warn|x|y|z".
    t.warn("y|z", { scope: "x" });
    t.warn("z", { scope: "x|y" });

    expect(inner.warn).toHaveBeenCalledTimes(2);
  });

  describe("bounded eviction", () => {
    it("never retains more than maxKeys keys when distinct keys keep arriving", () => {
      const inner = fakeTelemetry();
      // ttlMs huge so only the size cap (not the TTL) drives eviction here.
      const t = new ThrottledTelemetry(inner, 1000, {
        maxKeys: 3,
        ttlMs: 1_000_000,
        sweepIntervalOps: 5,
      });

      for (let i = 0; i < 10; i++) {
        t.warn(`msg-${i}`, { scope: "s" });
      }

      expect(t.size).toBe(3);
    });

    it("evicts by least-recently-seen, not by insertion order", () => {
      const inner = fakeTelemetry();
      // maxKeys 1 so exactly one key survives the sweep. "early" is inserted
      // first but touched again last, so it is the most-recently-seen; a naive
      // insertion-order (FIFO) eviction would drop it instead of "late".
      const t = new ThrottledTelemetry(inner, 1000, {
        maxKeys: 1,
        ttlMs: 1_000_000,
        sweepIntervalOps: 3,
      });

      t.warn("early", { scope: "s" }); // t=0, inserted first
      vi.setSystemTime(100);
      t.warn("late", { scope: "s" }); // inserted second
      vi.setSystemTime(200);
      t.warn("early", { scope: "s" }); // within window → suppressed, lastSeenAt bumped; 3rd op → sweep

      // "early" is the most-recently-seen → survives with its suppressed tally;
      // "late" is the least-recently-seen → evicted.
      vi.setSystemTime(1300); // past "early"'s window → it re-emits, flushing the tally
      t.warn("early", { scope: "s" });

      expect(t.size).toBe(1);
      expect(inner.warn).toHaveBeenLastCalledWith("early (+1 suppressed)", { scope: "s" });
    });

    it("evicts a key left untouched for longer than ttlMs", () => {
      const inner = fakeTelemetry();
      const t = new ThrottledTelemetry(inner, 1000, {
        maxKeys: 1000,
        ttlMs: 5000,
        sweepIntervalOps: 1,
      });

      t.warn("idle", { scope: "s" }); // t=0, lastSeenAt=0
      expect(t.size).toBe(1);

      vi.setSystemTime(6000); // 6000 - 0 >= ttl
      t.warn("other", { scope: "s" }); // any op runs a sweep → "idle" expires

      expect(t.size).toBe(1); // only "other" survives
    });

    it("keeps a key alive across the ttl while it keeps being touched", () => {
      const inner = fakeTelemetry();
      const t = new ThrottledTelemetry(inner, 1000, {
        maxKeys: 1000,
        ttlMs: 5000,
        sweepIntervalOps: 1,
      });

      t.warn("hot", { scope: "s" }); // t=0
      for (let ts = 4000; ts <= 20000; ts += 4000) {
        vi.setSystemTime(ts); // each touch is < ttl after the previous one
        t.warn("hot", { scope: "s" });
      }

      expect(t.size).toBe(1); // never evicted despite total elapsed ≫ ttl
    });

    it("does not evict anything while key cardinality stays under the cap", () => {
      const inner = fakeTelemetry();
      const t = new ThrottledTelemetry(inner, 1000, {
        maxKeys: 1000,
        ttlMs: 1_000_000,
        sweepIntervalOps: 1,
      });

      t.warn("a", { scope: "s" });
      t.warn("b", { scope: "s" });
      t.warn("c", { scope: "s" });

      expect(t.size).toBe(3);
    });
  });
});
