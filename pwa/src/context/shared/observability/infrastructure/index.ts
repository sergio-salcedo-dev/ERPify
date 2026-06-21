import type { Telemetry } from "@/context/shared/observability/domain/Telemetry";
import { CompositeTelemetry } from "./CompositeTelemetry";
import { ConsoleTelemetry } from "./ConsoleTelemetry";
import { SentryTelemetry } from "./SentryTelemetry";
import { ThrottledTelemetry } from "./ThrottledTelemetry";

/**
 * Builds the application's default {@link Telemetry}. Consumers MUST type the
 * import as the `Telemetry` port, never a concrete adapter, so sinks can be
 * swapped without churn (mirrors `toastNotifier` / `dateTimeProvider`).
 *
 * Composition:
 * - The console adapter is always present — it self-gates to dev/staging at
 *   call time, so per-call `vi.stubEnv` tests stay valid (this selection is at
 *   construction; the console's own env gate is left untouched).
 * - The Sentry adapter is added only when `NEXT_PUBLIC_SENTRY_DSN` is set, so
 *   tests and bare checkouts never emit to Sentry. Datadog will slot in as one
 *   more {@link CompositeTelemetry} entry behind the same kind of gate.
 * - The composite is wrapped in {@link ThrottledTelemetry} so a flood of
 *   identical diagnostics coalesces to one emit per window before reaching ANY
 *   sink — protecting the console and metered Sentry/Datadog quota alike.
 */
export function createTelemetry(): Telemetry {
  const sinks: Telemetry[] = [new ConsoleTelemetry()];

  const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN?.trim();
  // Strengthen validation: a valid Sentry DSN must be a URL starting with https://
  if (dsn?.startsWith("https://")) {
    sinks.push(new SentryTelemetry());
  }

  return new ThrottledTelemetry(new CompositeTelemetry(sinks));
}

export const telemetry: Telemetry = createTelemetry();

export type { Telemetry, TelemetryContext } from "@/context/shared/observability/domain/Telemetry";
