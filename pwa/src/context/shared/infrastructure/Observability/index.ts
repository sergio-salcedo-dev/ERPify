import type { Telemetry } from "@/context/shared/domain/Observability/Telemetry";
import { ConsoleTelemetry } from "./ConsoleTelemetry";

/**
 * Default {@link Telemetry} for the application. Consumers MUST type the import
 * as the `Telemetry` port, never the concrete adapter, so Sentry / Datadog can
 * be swapped in without churn (mirrors `toastNotifier` / `dateTimeProvider`).
 */
export const telemetry: Telemetry = new ConsoleTelemetry();

export type { Telemetry, TelemetryContext } from "@/context/shared/domain/Observability/Telemetry";
