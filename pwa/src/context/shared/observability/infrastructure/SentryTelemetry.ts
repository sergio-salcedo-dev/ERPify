import * as Sentry from "@sentry/nextjs";
import type { Telemetry, TelemetryContext } from "@/context/shared/observability/domain/Telemetry";
import { serializeCause } from "@/context/shared/observability/domain/serializeCause";

type SentryLevel = "warning" | "error";

/**
 * Sentry adapter for the {@link Telemetry} port. Routes deliberate
 * `telemetry.warn` / `.error` calls to Sentry — crucially the diagnostics the
 * SDK's global handlers can't see: React error boundaries SWALLOW the render
 * errors they catch (`SegmentErrorBoundary` / `RootErrorBoundary` already call
 * `telemetry.error`), so without this adapter those never reach Sentry.
 *
 * An `Error` `cause` is captured as an exception (Sentry keeps its stack); any
 * other cause is serialized + scrubbed via {@link serializeCause} and attached
 * as context to a captured message. The scope tag rides along as a Sentry tag.
 * Inert when no DSN is configured — `Sentry.init` was never called, so every
 * `capture*` is a no-op.
 */
export class SentryTelemetry implements Telemetry {
  warn(message: string, context?: TelemetryContext): void {
    this.capture("warning", message, context);
  }

  error(message: string, context?: TelemetryContext): void {
    this.capture("error", message, context);
  }

  private capture(level: SentryLevel, message: string, context?: TelemetryContext): void {
    const { scope, cause } = context ?? {};

    Sentry.withScope((sentryScope) => {
      sentryScope.setLevel(level);
      if (scope !== undefined) {
        sentryScope.setTag("telemetry.scope", scope);
      }

      if (cause instanceof Error) {
        // The human label rides as context; the Error itself carries the stack.
        sentryScope.setContext("telemetry", { message });
        Sentry.captureException(cause);
        return;
      }

      if (cause !== undefined) {
        // serializeCause already returns a plain record ({ value } for non-Error
        // causes), so attach it directly — no extra wrapping layer.
        const serialized = serializeCause(cause);
        if (serialized !== undefined) {
          sentryScope.setContext("cause", serialized);
        }
      }
      Sentry.captureMessage(message, level);
    });
  }
}
