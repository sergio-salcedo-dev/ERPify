/**
 * `Telemetry` is the domain port for client-side observability (diagnostics
 * that are never user-facing). It hides every adapter detail so the
 * implementation — `console` today, Sentry / Datadog tomorrow — can be swapped
 * without touching call sites (mirrors `ToastNotifier` / `DateTimeProvider`).
 */
export interface TelemetryContext {
  /**
   * Low-cardinality scope tag, e.g. "realtime:bank". Build it with
   * `telemetryScope(...)` / `realtimeScope(...)` from `./TelemetryScope` so the
   * `<surface>:<detail>` convention stays consistent. Kept as a plain `string`
   * here so this port stays a transport-agnostic seam (Sentry/Datadog accept
   * arbitrary tags); the convention is enforced at construction, not on the port.
   */
  scope?: string;
  /** Triggering error / cause. Adapters serialize + scrub it; never assume PII-free. */
  cause?: unknown;
}

export interface Telemetry {
  warn(message: string, context?: TelemetryContext): void;
  error(message: string, context?: TelemetryContext): void;
}
