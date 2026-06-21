import {
  FixedWindowRateLimiter,
  type RateLimitDecision,
} from "@/context/shared/rate-limit/domain/FixedWindowRateLimiter";

/**
 * Per-IP allowance for the public `/monitoring` Sentry tunnel (`tunnelRoute` in
 * `next.config.ts`). The tunnel relays anonymous browser POSTs to Sentry
 * ingest, so without a local cap a single source could spray events and burn
 * the project's Sentry quota — Sentry's own server-side limits are the second
 * line of defence, not the first. 60 POSTs / 60s sits far above any legitimate
 * browser session (the SDK batches and self-throttles) while bounding a
 * flooder's rate.
 *
 * State is per-process and in-memory: it resets on deploy and is not shared
 * across replicas. Production runs a single PWA instance, so that is sufficient;
 * revisit (shared store) if the PWA is ever scaled horizontally.
 */
export const MONITORING_TUNNEL_RATE_LIMIT = {
  limit: 60,
  windowMs: 60_000,
  /** Caps the per-IP map under a distributed / rotating-source flood. */
  maxTrackedKeys: 10_000,
} as const;

/** Shared bucket for requests arriving without a usable client IP. */
const UNKNOWN_CLIENT_KEY = "unknown";

const limiter = new FixedWindowRateLimiter(
  MONITORING_TUNNEL_RATE_LIMIT.limit,
  MONITORING_TUNNEL_RATE_LIMIT.windowMs,
  MONITORING_TUNNEL_RATE_LIMIT.maxTrackedKeys,
);

/**
 * Resolves the rate-limit bucket key from an `X-Forwarded-For` chain.
 *
 * The PWA always sits behind our Caddy edge (FrankenPHP), which appends the real
 * peer address as the RIGHTMOST entry. Leading entries are client-supplied and
 * therefore spoofable, so we key on the rightmost value — an attacker cannot
 * forge it to escape their own bucket by rotating a fake leading IP. Falls back
 * to a single shared bucket when the header is absent or empty, so a request is
 * never silently exempted from the limit. Assumes one trusted proxy hop; revisit
 * if a CDN or additional proxy is placed in front of Caddy.
 */
export function resolveClientKey(forwardedFor: string | null | undefined): string {
  if (!forwardedFor) {
    return UNKNOWN_CLIENT_KEY;
  }
  const hops = forwardedFor.split(",");
  const rightmost = hops.at(-1)?.trim();
  if (rightmost) {
    return rightmost;
  }
  return UNKNOWN_CLIENT_KEY;
}

/**
 * Decides whether a `/monitoring` tunnel POST from `forwardedFor` is within the
 * per-IP allowance at `now` (epoch millis; defaults to the wall clock — the lone
 * clock-adapter seam, kept out of the pure limiter).
 */
export function evaluateMonitoringTunnelRequest(
  forwardedFor: string | null | undefined,
  now: number = Date.now(),
): RateLimitDecision {
  return limiter.check(resolveClientKey(forwardedFor), now);
}
