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
  /**
   * Triggering error / cause. Never assume it is PII-free. The contract is
   * adapter-dependent: a *local* adapter (the `console` one) may forward it
   * as-is — the browser console is the developer's own machine, not a 3rd
   * party — but any *external / network* adapter (Sentry / Datadog) MUST
   * serialize and scrub it before transmission. That scrub is owned by the
   * network adapter when it lands (see the deferred sink-adapter work), not by
   * this port or the console.
   */
  cause?: unknown;
}

export interface Telemetry {
  warn(message: string, context?: TelemetryContext): void;
  error(message: string, context?: TelemetryContext): void;
}
