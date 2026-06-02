/**
 * Transports a real-time feed can run over. Today only Mercure (SSE) is wired
 * (see {@link MercureSubscriber}); Kafka / WebSocket are declared ahead of their
 * adapters so the transport dimension is a curated, one-line-to-extend set
 * rather than scattered string literals.
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
  /** Apache Kafka topic stream (future). */
  KAFKA: "kafka",
  /** Raw WebSocket channel (future). */
  WEBSOCKET: "websocket",
} as const;
export type RealtimeTransport = (typeof RealtimeTransport)[keyof typeof RealtimeTransport];
