import type { Telemetry, TelemetryContext } from "@/context/shared/domain/Observability/Telemetry";

const DEFAULT_WINDOW_MS = 10_000;

/**
 * Severity levels, derived from the `Telemetry` port's own method names — never a
 * hand-written `"warn" | "error"` literal. The port is the single source of
 * truth: add `info` / `critical` to the interface and this type (and the dynamic
 * dispatch in `record`) pick them up with no edit here.
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
 * identical follow-ups are counted, never silently dropped — the tally surfaces
 * on the next emit after the window via a `(+N suppressed)` suffix. Keyed on
 * (level, scope, message) only; `cause` is deliberately excluded so varying
 * error objects of the same failure still coalesce. The key set is bounded by
 * design (scopes are low-cardinality, messages are static literals), so the
 * backing map never grows unbounded.
 *
 * Read the clock via `Date.now()` (same precedent as the reconnect debounce in
 * `BrowserMercureSubscriber`); fake timers make it deterministic under test.
 */
export class ThrottledTelemetry implements Telemetry {
  private readonly state = new Map<string, KeyState>();

  constructor(
    private readonly inner: Telemetry,
    private readonly windowMs: number = DEFAULT_WINDOW_MS,
  ) {}

  warn(message: string, context?: TelemetryContext): void {
    this.record("warn", message, context);
  }

  error(message: string, context?: TelemetryContext): void {
    this.record("error", message, context);
  }

  private record(level: TelemetryLevel, message: string, context?: TelemetryContext): void {
    const key = `${level}|${context?.scope ?? ""}|${message}`;
    const now = Date.now();
    const previous = this.state.get(key);

    if (previous !== undefined && now - previous.lastEmitAt < this.windowMs) {
      previous.suppressed += 1;
      return;
    }

    const suppressed = previous?.suppressed ?? 0;
    const line = suppressed > 0 ? `${message} (+${suppressed} suppressed)` : message;
    this.state.set(key, { lastEmitAt: now, suppressed: 0 });
    this.inner[level](line, context);
  }
}
