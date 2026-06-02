import type { Telemetry, TelemetryContext } from "@/context/shared/domain/Observability/Telemetry";

const DEFAULT_WINDOW_MS = 10_000;

/**
 * Severity levels, derived from the `Telemetry` port's method names rather than a
 * hand-written `"warn" | "error"` literal, so the dynamic dispatch in `record`
 * stays in lockstep with the port. Adding a new level (e.g. `info`) to the port
 * still requires a matching delegating method on this class — TypeScript flags the
 * missing `implements` member — after which `record`'s dispatch handles it unchanged.
 */
type TelemetryLevel = keyof Telemetry;

interface KeyState {
  lastEmitAt: number;
  suppressed: number;
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
 * stays small; it is not actively evicted (eviction is deferred — see
 * `deferred-work.md`).
 *
 * Reads the clock via `Date.now()` (same precedent as the reconnect debounce in
 * `BrowserMercureSubscriber`); a backward clock step (NTP / sleep-wake) is treated
 * as a fresh window so a key can never be wedged silent. Fake timers make it
 * deterministic under test.
 */
export class ThrottledTelemetry implements Telemetry {
  private readonly state = new Map<string, KeyState>();

  constructor(
    private readonly inner: Telemetry,
    private readonly windowMs: number = DEFAULT_WINDOW_MS,
  ) {
    if (windowMs <= 0) {
      throw new RangeError(`ThrottledTelemetry windowMs must be > 0, got ${windowMs}`);
    }
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

    if (previous !== undefined) {
      const elapsed = now - previous.lastEmitAt;
      // `elapsed >= 0` skips suppression when the clock jumped backward, so a key
      // is never wedged silent by a non-monotonic `Date.now()`.
      if (elapsed >= 0 && elapsed < this.windowMs) {
        previous.suppressed += 1;
        return;
      }
    }

    const suppressed = previous?.suppressed ?? 0;
    const line = suppressed > 0 ? `${message} (+${suppressed} suppressed)` : message;
    this.state.set(key, { lastEmitAt: now, suppressed: 0 });
    this.inner[level](line, context);
  }
}
