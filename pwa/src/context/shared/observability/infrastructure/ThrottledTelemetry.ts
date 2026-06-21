import type { Telemetry, TelemetryContext } from "@/context/shared/observability/domain/Telemetry";

const DEFAULT_WINDOW_MS = 10_000;
const DEFAULT_MAX_KEYS = 1_000;
const DEFAULT_TTL_MS = 60 * 60 * 1_000; // 1 hour
const DEFAULT_SWEEP_INTERVAL_OPS = 100;

/**
 * Severity levels, derived from the `Telemetry` port's method names rather than a
 * hand-written `"warn" | "error"` literal, so the dynamic dispatch in `record`
 * stays in lockstep with the port. Adding a new level (e.g. `info`) to the port
 * still requires a matching delegating method on this class — TypeScript flags the
 * missing `implements` member — after which `record`'s dispatch handles it unchanged.
 */
type TelemetryLevel = keyof Telemetry;

interface KeyState {
  /** When the leading-edge emit happened — anchors the suppression window. */
  lastEmitAt: number;
  /** Last time the key was touched (emit OR suppress) — anchors the TTL. */
  lastSeenAt: number;
  suppressed: number;
}

/**
 * Tunables for the bounded-eviction guardrail. All optional; the defaults are
 * conservative for ERPify's expected telemetry volume and can be tightened
 * without touching the eviction design.
 */
export interface ThrottledTelemetryOptions {
  /** Hard cap on retained keys; the least-recently-seen are evicted past it. */
  maxKeys?: number;
  /** A key untouched for at least this long is dropped on the next sweep. */
  ttlMs?: number;
  /** Run one sweep per this many `record` calls (an insert or a suppression). */
  sweepIntervalOps?: number;
}

/**
 * Coalescing decorator for the {@link Telemetry} port. Collapses a flood of
 * identical diagnostics — same level + scope + message — into a single emit per
 * `windowMs`, so a misbehaving hub, a buggy publisher, or a render loop can't
 * spam the dev/staging console today nor burn Sentry / Datadog quota tomorrow.
 *
 * Leading-edge: the first occurrence in a window passes straight through;
 * identical follow-ups are counted and the tally rides the next emit after the
 * window via a `(+N suppressed)` suffix. Caveats: the tally is reported only when
 * the key emits again — a burst that stops for good leaves its trailing count
 * unflushed (there is no timer) — and only the re-emitting call's `cause` is
 * forwarded, so the suppressed occurrences' causes are not retained. Keyed on
 * (level, scope, message) only; `cause` is deliberately excluded so varying error
 * objects of the same failure still coalesce — which also means two genuinely
 * distinct failures sharing a (level, scope, message) collapse within a window.
 *
 * The key set is low-cardinality by call-site convention (scopes come from the
 * closed `TelemetrySurface` set, messages are static literals), so the backing map
 * normally stays tiny. As a guardrail against a future call site that interpolates
 * a dynamic value into `scope`/`message`, the map is bounded: a periodic sweep
 * (every `sweepIntervalOps` records) drops keys untouched for at least `ttlMs`,
 * and — as a hard backstop — evicts the least-recently-seen keys whenever the count
 * still exceeds `maxKeys`. An evicted key simply re-emits as a fresh leading edge,
 * dropping its pending suppressed tally (already best-effort). Peak retained keys
 * are bounded by `maxKeys + sweepIntervalOps`.
 *
 * Reads the clock via `Date.now()` (same precedent as the reconnect debounce in
 * `BrowserMercureSubscriber`); a backward clock step (NTP / sleep-wake) is treated
 * as a fresh window — and as "recently seen" by the TTL — so a key can never be
 * wedged silent nor prematurely evicted. Fake timers make it deterministic under test.
 */
export class ThrottledTelemetry implements Telemetry {
  private readonly state = new Map<string, KeyState>();
  private readonly maxKeys: number;
  private readonly ttlMs: number;
  private readonly sweepIntervalOps: number;
  private opsSinceSweep = 0;

  constructor(
    private readonly inner: Telemetry,
    private readonly windowMs: number = DEFAULT_WINDOW_MS,
    options: ThrottledTelemetryOptions = {},
  ) {
    if (windowMs <= 0) {
      throw new RangeError(`ThrottledTelemetry windowMs must be > 0, got ${windowMs}`);
    }
    const {
      maxKeys = DEFAULT_MAX_KEYS,
      ttlMs = DEFAULT_TTL_MS,
      sweepIntervalOps = DEFAULT_SWEEP_INTERVAL_OPS,
    } = options;
    if (maxKeys <= 0) {
      throw new RangeError(`ThrottledTelemetry maxKeys must be > 0, got ${maxKeys}`);
    }
    if (ttlMs <= 0) {
      throw new RangeError(`ThrottledTelemetry ttlMs must be > 0, got ${ttlMs}`);
    }
    if (sweepIntervalOps <= 0) {
      throw new RangeError(
        `ThrottledTelemetry sweepIntervalOps must be > 0, got ${sweepIntervalOps}`,
      );
    }
    this.maxKeys = maxKeys;
    this.ttlMs = ttlMs;
    this.sweepIntervalOps = sweepIntervalOps;
  }

  /** Distinct keys currently retained; bounded by `maxKeys + sweepIntervalOps`. */
  get size(): number {
    return this.state.size;
  }

  warn(message: string, context?: TelemetryContext): void {
    this.record("warn", message, context);
  }

  error(message: string, context?: TelemetryContext): void {
    this.record("error", message, context);
  }

  private record(level: TelemetryLevel, message: string, context?: TelemetryContext): void {
    // JSON-encode the parts so a delimiter char inside a scope or message can't
    // forge a collision between two distinct (level, scope, message) tuples.
    const key = JSON.stringify([level, context?.scope ?? "", message]);
    const now = Date.now();
    const previous = this.state.get(key);

    if (previous !== undefined && this.withinWindow(previous, now)) {
      previous.suppressed += 1;
      previous.lastSeenAt = now;
    } else {
      const suppressed = previous?.suppressed ?? 0;
      const line = suppressed > 0 ? `${message} (+${suppressed} suppressed)` : message;
      this.state.set(key, { lastEmitAt: now, lastSeenAt: now, suppressed: 0 });
      this.inner[level](line, context);
    }

    this.maybeSweep(now);
  }

  private withinWindow(state: KeyState, now: number): boolean {
    const elapsed = now - state.lastEmitAt;
    // `elapsed >= 0` skips suppression when the clock jumped backward, so a key
    // is never wedged silent by a non-monotonic `Date.now()`.
    return elapsed >= 0 && elapsed < this.windowMs;
  }

  private maybeSweep(now: number): void {
    this.opsSinceSweep += 1;
    if (this.opsSinceSweep < this.sweepIntervalOps) {
      return;
    }
    this.opsSinceSweep = 0;
    this.sweep(now);
  }

  private sweep(now: number): void {
    for (const [key, st] of this.state) {
      // A backward clock step makes `now - lastSeenAt` negative — treat that as
      // "recently seen" (never expired), mirroring the suppression window.
      if (now - st.lastSeenAt >= this.ttlMs) {
        this.state.delete(key);
      }
    }

    const overflow = this.state.size - this.maxKeys;
    if (overflow <= 0) {
      return;
    }
    // Still over the hard cap after the TTL pass: drop the least-recently-seen
    // keys until back at the cap. Map iteration is insertion order, not recency,
    // so rank by `lastSeenAt` explicitly.
    const leastRecentlySeen = [...this.state.entries()]
      .sort(([, a], [, b]) => a.lastSeenAt - b.lastSeenAt)
      .slice(0, overflow);
    for (const [key] of leastRecentlySeen) {
      this.state.delete(key);
    }
  }
}
