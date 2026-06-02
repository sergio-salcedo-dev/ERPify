/**
 * Telemetry scope tags follow a `<surface>:<detail>` convention so diagnostics
 * group by where they came from (passed as {@link TelemetryContext.scope}).
 *
 *  - `surface` is a small **closed** set, curated here — adding one is a
 *    deliberate edit to {@link TelemetrySurface}, which keeps scopes
 *    low-cardinality.
 *  - `detail` is **open**, owned by the caller: an entity name for a per-entity
 *    feed (`realtime:bank`, `realtime:invoice`) or a transport for a transport
 *    adapter (`realtime:mercure`). New entities therefore never touch this file.
 *
 * Build scopes with {@link telemetryScope} / {@link realtimeScope} instead of a
 * bare string literal so the `:` join and the surface enum live in one place
 * (mirrors how `NodeEnv` / `IconTone` centralise their literals). The generic
 * `Telemetry` port stays `scope?: string` on purpose — it's a transport-agnostic
 * seam (Sentry/Datadog accept arbitrary tags); the convention is enforced here,
 * at construction, not baked into the port.
 */
export const TelemetrySurface = {
  /** Live updates over a real-time transport (Mercure / Kafka / WebSocket). */
  REALTIME: "realtime",
  /** Next.js error boundaries (`error.tsx` / `global-error.tsx`). */
  ERROR: "error",
} as const;
export type TelemetrySurface = (typeof TelemetrySurface)[keyof typeof TelemetrySurface];

/** A `<surface>:<detail>` telemetry scope tag. */
export type TelemetryScope = `${TelemetrySurface}:${string}`;

/**
 * Builds a conventionally-formatted scope. The single place the `<surface>:<detail>`
 * shape is spelled, so every surface/detail pair stays consistent.
 */
export function telemetryScope(surface: TelemetrySurface, detail: string): TelemetryScope {
  return `${surface}:${detail}`;
}

/**
 * Convenience builder for the realtime surface, e.g. `realtimeScope("bank")` →
 * `"realtime:bank"` for a per-entity feed, or `realtimeScope(RealtimeTransport.MERCURE)`
 * → `"realtime:mercure"` for a transport adapter.
 */
export function realtimeScope(detail: string): TelemetryScope {
  return telemetryScope(TelemetrySurface.REALTIME, detail);
}
