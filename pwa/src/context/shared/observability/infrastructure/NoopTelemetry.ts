import type { Telemetry, TelemetryContext } from "@/context/shared/observability/domain/Telemetry";

/**
 * Null-object {@link Telemetry}: every diagnostic is swallowed. It is the
 * constructor default for injected telemetry (mirrors `NoopDebugTokenObserver`),
 * so direct `new` call sites — the unit suite's `new FetchHttpClient()` — stay
 * silent unless they pass a real or fake adapter.
 */
export class NoopTelemetry implements Telemetry {
  warn(_message: string, _context?: TelemetryContext): void {
    // Inert by design: the null sink discards diagnostics.
  }

  error(_message: string, _context?: TelemetryContext): void {
    // Inert by design: the null sink discards diagnostics.
  }
}
