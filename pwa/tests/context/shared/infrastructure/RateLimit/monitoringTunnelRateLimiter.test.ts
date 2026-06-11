import { describe, expect, it } from "vitest";
import {
  MONITORING_TUNNEL_RATE_LIMIT,
  evaluateMonitoringTunnelRequest,
  resolveClientKey,
} from "@/context/shared/infrastructure/RateLimit/monitoringTunnelRateLimiter";

describe("resolveClientKey", () => {
  it("keys on the rightmost X-Forwarded-For entry (proxy-appended, unspoofable)", () => {
    expect(resolveClientKey("203.0.113.7")).toBe("203.0.113.7");
    expect(resolveClientKey("9.9.9.9, 203.0.113.7")).toBe("203.0.113.7");
    expect(resolveClientKey("  spoofed , 203.0.113.7  ")).toBe("203.0.113.7");
  });

  it("falls back to a shared bucket when no usable IP is present", () => {
    expect(resolveClientKey(null)).toBe("unknown");
    expect(resolveClientKey(undefined)).toBe("unknown");
    expect(resolveClientKey("")).toBe("unknown");
    expect(resolveClientKey("  ,  ")).toBe("unknown");
  });
});

describe("evaluateMonitoringTunnelRequest", () => {
  it("allows traffic within the per-IP allowance and denies the overflow", () => {
    const forwardedFor = "spoofed, 198.51.100.42";
    const now = 1_000_000;
    for (let i = 0; i < MONITORING_TUNNEL_RATE_LIMIT.limit; i++) {
      expect(evaluateMonitoringTunnelRequest(forwardedFor, now + i).allowed).toBe(true);
    }
    const denied = evaluateMonitoringTunnelRequest(
      forwardedFor,
      now + MONITORING_TUNNEL_RATE_LIMIT.limit,
    );
    expect(denied.allowed).toBe(false);
    expect(denied.retryAfterSeconds).toBeGreaterThan(0);
  });

  it("isolates the allowance per resolved IP", () => {
    const now = 2_000_000;
    expect(evaluateMonitoringTunnelRequest("203.0.113.80", now).allowed).toBe(true);
    expect(evaluateMonitoringTunnelRequest("203.0.113.81", now).allowed).toBe(true);
  });

  it("defaults `now` to the wall clock for a fresh IP", () => {
    expect(evaluateMonitoringTunnelRequest("203.0.113.250").allowed).toBe(true);
  });
});
