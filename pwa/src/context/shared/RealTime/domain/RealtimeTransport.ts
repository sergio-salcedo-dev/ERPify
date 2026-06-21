/**
 * Transports a real-time feed can run over. Today only Mercure (SSE) is wired
 * (see {@link MercureSubscriber});.
 *
 * Used to tag transport-adapter telemetry — `realtimeScope(RealtimeTransport.MERCURE)`
 * → `"realtime:mercure"` — distinct from a per-entity feed scope
 * (`realtimeScope("bank")`), which stays transport-agnostic.
 *
 * The matching TypeScript type is derived via
 * `(typeof RealtimeTransport)[keyof typeof RealtimeTransport]` so adding /
 * renaming a transport forces every call site to update.
 */
export const RealtimeTransport = {
  /** Server-Sent Events over a Mercure hub. */
  MERCURE: "mercure",
} as const;
export type RealtimeTransport = (typeof RealtimeTransport)[keyof typeof RealtimeTransport];
