import {
  TelemetrySurface,
  telemetryScope,
} from "@/context/shared/Observability/domain/TelemetryScope";

/**
 * {@link TelemetryContext.scope} tags for the Next.js error boundaries, passed to
 * `telemetry.error(...)` so a caught render error / root crash is attributable in
 * the diagnostics stream.
 *
 * Lives in the error module's domain layer (no React, no telemetry adapter) —
 * spelled once here so the boundary call sites and their tests never drift on a
 * bare string literal (mirrors {@link IconTone}). Built via the shared
 * {@link telemetryScope} so the `error:` surface comes from the curated
 * {@link TelemetrySurface} enum, alongside the realtime scopes.
 */
export const ErrorTelemetryScope = {
  /** Segment-level `error.tsx` boundary (HTTP 500). */
  SEGMENT: telemetryScope(TelemetrySurface.ERROR, "segment"),
  /** Root `global-error.tsx` boundary — `RootLayout` itself crashed. */
  ROOT: telemetryScope(TelemetrySurface.ERROR, "root"),
} as const;
export type ErrorTelemetryScope = (typeof ErrorTelemetryScope)[keyof typeof ErrorTelemetryScope];
