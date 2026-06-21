import { describe, expect, it } from "vitest";
import { FixedWindowRateLimiter } from "@/context/shared/rate-limit/domain/FixedWindowRateLimiter";

describe("FixedWindowRateLimiter", () => {
  describe("construction guards", () => {
    it("rejects a non-positive or non-integer limit", () => {
      expect(() => new FixedWindowRateLimiter(0, 1000, 10)).toThrow(RangeError);
      expect(() => new FixedWindowRateLimiter(-1, 1000, 10)).toThrow(RangeError);
      expect(() => new FixedWindowRateLimiter(1.5, 1000, 10)).toThrow(RangeError);
    });

    it("rejects a non-positive window", () => {
      expect(() => new FixedWindowRateLimiter(5, 0, 10)).toThrow(RangeError);
      expect(() => new FixedWindowRateLimiter(5, -1, 10)).toThrow(RangeError);
    });

    it("rejects a non-positive or non-integer key cap", () => {
      expect(() => new FixedWindowRateLimiter(5, 1000, 0)).toThrow(RangeError);
      expect(() => new FixedWindowRateLimiter(5, 1000, 2.5)).toThrow(RangeError);
    });
  });

  it("allows up to `limit` calls in a window and denies the next", () => {
    const limiter = new FixedWindowRateLimiter(3, 1000, 10);
    expect(limiter.check("a", 0).allowed).toBe(true);
    expect(limiter.check("a", 100).allowed).toBe(true);
    expect(limiter.check("a", 200).allowed).toBe(true);
    expect(limiter.check("a", 300).allowed).toBe(false);
  });

  it("reports whole seconds until the window rolls when denied", () => {
    const limiter = new FixedWindowRateLimiter(1, 10_000, 10);
    expect(limiter.check("a", 0).allowed).toBe(true);
    expect(limiter.check("a", 2_500)).toEqual({ allowed: false, retryAfterSeconds: 8 });
  });

  it("never reports a zero Retry-After at the tail of a window", () => {
    const limiter = new FixedWindowRateLimiter(1, 1000, 10);
    limiter.check("a", 0);
    expect(limiter.check("a", 999).retryAfterSeconds).toBe(1);
  });

  it("starts a fresh allowance once the window elapses", () => {
    const limiter = new FixedWindowRateLimiter(2, 1000, 10);
    expect(limiter.check("a", 0).allowed).toBe(true);
    expect(limiter.check("a", 500).allowed).toBe(true);
    expect(limiter.check("a", 600).allowed).toBe(false);
    expect(limiter.check("a", 1000).allowed).toBe(true);
  });

  it("tracks keys independently", () => {
    const limiter = new FixedWindowRateLimiter(1, 1000, 10);
    expect(limiter.check("a", 0).allowed).toBe(true);
    expect(limiter.check("b", 0).allowed).toBe(true);
    expect(limiter.check("a", 0).allowed).toBe(false);
    expect(limiter.check("b", 0).allowed).toBe(false);
  });

  it("treats a backward clock step as a fresh window", () => {
    const limiter = new FixedWindowRateLimiter(1, 1000, 10);
    expect(limiter.check("a", 5000).allowed).toBe(true);
    expect(limiter.check("a", 4000).allowed).toBe(true);
  });

  it("bounds tracked keys, sweeping expired windows before admitting new ones", () => {
    const limiter = new FixedWindowRateLimiter(1, 1000, 2);
    expect(limiter.check("a", 0).allowed).toBe(true);
    expect(limiter.check("b", 0).allowed).toBe(true);
    // At t=2000 the a/b windows have expired; admitting c sweeps them rather
    // than evicting a live entry.
    expect(limiter.check("c", 2000).allowed).toBe(true);
    // a's old window was swept, so it is a brand-new key again.
    expect(limiter.check("a", 2000).allowed).toBe(true);
  });

  it("evicts the soonest-to-expire key when the cap is reached with all windows live", () => {
    const limiter = new FixedWindowRateLimiter(1, 10_000, 2);
    expect(limiter.check("a", 0).allowed).toBe(true);
    expect(limiter.check("b", 100).allowed).toBe(true);
    // b is still inside its window and already at the limit — a repeat is denied.
    expect(limiter.check("b", 150).allowed).toBe(false);
    // Cap reached and nothing has expired at t=200 → admitting c evicts the
    // soonest-to-expire key (a, windowStart 0).
    expect(limiter.check("c", 200).allowed).toBe(true);
    // a's prior allowance was forgotten by the eviction → a fresh call is
    // allowed (had it survived, a second call within its window would deny).
    expect(limiter.check("a", 250).allowed).toBe(true);
  });
});
