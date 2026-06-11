/**
 * The outcome of a single rate-limit check.
 */
export interface RateLimitDecision {
  /** Whether this call falls within the allowance. */
  readonly allowed: boolean;
  /**
   * Whole seconds the caller should wait before retrying. `0` when allowed;
   * always `>= 1` when denied, so a derived `Retry-After` header is never `0`.
   */
  readonly retryAfterSeconds: number;
}

interface WindowState {
  count: number;
  windowStart: number;
}

const ALLOWED: RateLimitDecision = { allowed: true, retryAfterSeconds: 0 };

/**
 * In-memory fixed-window rate limiter. Pure domain logic — no framework, no
 * ambient clock, no transport: the caller passes a bucket `key` and the current
 * epoch-millis `now`, so the algorithm is deterministic and unit-testable
 * without fake timers.
 *
 * Semantics: each `key` may make up to `limit` calls within any `windowMs`
 * window. The window is anchored to the first call of a fresh window (fixed,
 * not sliding) — the cheapest scheme that bounds burst volume per key, which is
 * all the public Sentry tunnel needs (see
 * `infrastructure/RateLimit/monitoringTunnelRateLimiter`). The `limit + 1`-th
 * call in a window is denied, reporting the seconds left until the window rolls.
 *
 * Memory is bounded to `maxTrackedKeys` distinct keys: a brand-new key that
 * would overflow the cap first sweeps windows that have already expired, then —
 * if the map is still full under a wide key spray (rotating / spoofed sources) —
 * evicts the entry whose window expires soonest. Dropping a near-expiry entry is
 * harmless: it was about to reset to a fresh allowance anyway. This keeps the
 * map from growing without bound under a distributed flood.
 *
 * A backward clock step (NTP correction, sleep/wake) is treated as a fresh
 * window, so a key can never be wedged permanently denied by a non-monotonic
 * `now`.
 */
export class FixedWindowRateLimiter {
  private readonly windows = new Map<string, WindowState>();

  constructor(
    private readonly limit: number,
    private readonly windowMs: number,
    private readonly maxTrackedKeys: number,
  ) {
    if (!Number.isInteger(limit) || limit <= 0) {
      throw new RangeError(`FixedWindowRateLimiter limit must be a positive integer, got ${limit}`);
    }
    if (windowMs <= 0) {
      throw new RangeError(`FixedWindowRateLimiter windowMs must be > 0, got ${windowMs}`);
    }
    if (!Number.isInteger(maxTrackedKeys) || maxTrackedKeys <= 0) {
      throw new RangeError(
        `FixedWindowRateLimiter maxTrackedKeys must be a positive integer, got ${maxTrackedKeys}`,
      );
    }
  }

  check(key: string, now: number): RateLimitDecision {
    const current = this.windows.get(key);

    if (current === undefined || !this.isWithinWindow(current, now)) {
      // A new key may grow the map; an expired key reuses its slot in place.
      if (current === undefined) {
        this.evictForNewKey(now);
      }
      this.windows.set(key, { count: 1, windowStart: now });
      return ALLOWED;
    }

    current.count += 1;
    if (current.count <= this.limit) {
      return ALLOWED;
    }

    const remainingMs = this.windowMs - (now - current.windowStart);
    return { allowed: false, retryAfterSeconds: Math.max(1, Math.ceil(remainingMs / 1000)) };
  }

  private isWithinWindow(state: WindowState, now: number): boolean {
    const elapsed = now - state.windowStart;
    // `elapsed >= 0` treats a backward clock step as a fresh window.
    return elapsed >= 0 && elapsed < this.windowMs;
  }

  private evictForNewKey(now: number): void {
    if (this.windows.size < this.maxTrackedKeys) {
      return;
    }
    for (const [key, state] of this.windows) {
      if (!this.isWithinWindow(state, now)) {
        this.windows.delete(key);
      }
    }
    if (this.windows.size < this.maxTrackedKeys) {
      return;
    }
    let soonestKey: string | undefined;
    let soonestStart = Number.POSITIVE_INFINITY;
    for (const [key, state] of this.windows) {
      if (state.windowStart < soonestStart) {
        soonestStart = state.windowStart;
        soonestKey = key;
      }
    }
    if (soonestKey !== undefined) {
      this.windows.delete(soonestKey);
    }
  }
}
