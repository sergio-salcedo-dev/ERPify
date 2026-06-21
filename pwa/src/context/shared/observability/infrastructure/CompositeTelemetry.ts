import type { Telemetry, TelemetryContext } from "@/context/shared/observability/domain/Telemetry";

/**
 * Fans a single diagnostic out to several {@link Telemetry} sinks — the console
 * adapter plus the Sentry adapter today, the future Datadog adapter as one more
 * entry. A sink that throws is isolated so it can never suppress the others nor
 * bubble an observability failure into application code (telemetry must never
 * crash the app).
 *
 * Order is the caller's: the local console runs first so a dev still sees the
 * diagnostic even if an external sink misbehaves.
 */
export class CompositeTelemetry implements Telemetry {
  constructor(private readonly sinks: readonly Telemetry[]) {}

  warn(message: string, context?: TelemetryContext): void {
    this.fanOut((sink) => sink.warn(message, context));
  }

  error(message: string, context?: TelemetryContext): void {
    this.fanOut((sink) => sink.error(message, context));
  }

  private fanOut(emit: (sink: Telemetry) => void): void {
    for (const sink of this.sinks) {
      try {
        emit(sink);
      } catch (error) {
        // A failing sink must not suppress the others or reach the caller.
        // We log the meta-failure to the console so it's visible during
        // development/debugging; this is a last-resort visibility channel.
        console.error("Telemetry composite: sink failed to emit diagnostic", error);
      }
    }
  }
}
