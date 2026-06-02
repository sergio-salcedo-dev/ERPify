import type { Telemetry } from "@/context/shared/domain/Observability/Telemetry";
import { ConsoleTelemetry } from "./ConsoleTelemetry";
import { ThrottledTelemetry } from "./ThrottledTelemetry";

/**
 * Default {@link Telemetry} for the application. Consumers MUST type the import
 * as the `Telemetry` port, never the concrete adapter, so Sentry / Datadog can
 * be swapped in without churn (mirrors `toastNotifier` / `dateTimeProvider`).
 *
 * Wrapped in {@link ThrottledTelemetry} so a flood of identical diagnostics (a
 * misbehaving hub, a render loop) coalesces to one emit per window — protecting
 * the console today and a metered Sentry / Datadog sink tomorrow. To add that
 * sink later, swap the wrapped `ConsoleTelemetry` (or fan out via a composite);
 * the throttle and every call site stay put.
 */
export const telemetry: Telemetry = new ThrottledTelemetry(new ConsoleTelemetry());

export type { Telemetry, TelemetryContext } from "@/context/shared/domain/Observability/Telemetry";
