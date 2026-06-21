import { AppEnv } from "@/context/shared/domain/types/appEnv";
import type { Telemetry, TelemetryContext } from "@/context/shared/observability/domain/Telemetry";

/**
 * Console adapter for the {@link Telemetry} port. Emits to the browser console
 * only in `dev` / `staging` (read from the public `NEXT_PUBLIC_APP_ENV`); stays
 * silent in `prod` and for any unknown value, so real users never see
 * diagnostics. Prod observability arrives later via Sentry / Datadog adapters
 * behind the same port. Read at call time so the env can be stubbed in tests.
 */
function consoleEnabled(): boolean {
  const env = process.env.NEXT_PUBLIC_APP_ENV;
  return env === AppEnv.DEVELOPMENT || env === AppEnv.STAGING;
}

function format(scope: string | undefined, message: string): string {
  return `[${scope ?? "telemetry"}] ${message}`;
}

export class ConsoleTelemetry implements Telemetry {
  warn(message: string, context?: TelemetryContext): void {
    if (!consoleEnabled()) {
      return;
    }
    const line = format(context?.scope, message);
    if (context?.cause === undefined) {
      console.warn(line);
    } else {
      console.warn(line, context.cause);
    }
  }

  error(message: string, context?: TelemetryContext): void {
    if (!consoleEnabled()) {
      return;
    }
    const line = format(context?.scope, message);
    if (context?.cause === undefined) {
      console.error(line);
    } else {
      console.error(line, context.cause);
    }
  }
}
