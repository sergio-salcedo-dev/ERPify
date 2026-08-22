import { AppEnv } from "@/context/shared/environment/domain/AppEnv";
import { scrubSentryEvent } from "./scrubSentryEvent";

/** Full tracing in dev (only the developer hits `erpify-pwa-dev`) for local verification. */
const DEV_TRACES_SAMPLE_RATE = 1;
/** Sampled tracing in staging/prod — parity with the API's prod `SENTRY_TRACES_SAMPLE_RATE`. */
const PROD_TRACES_SAMPLE_RATE = 0.2;

/**
 * Single source of truth for the Sentry SDK options shared by all three Next
 * runtimes (client / node / edge), so DSN gating, environment tagging, the PII
 * policy, the scrub hook, and the tracing sample rate live in one place.
 *
 * Returns `null` when `NEXT_PUBLIC_SENTRY_DSN` is unset/empty — the caller then
 * skips `Sentry.init` entirely, keeping the SDK fully inert (no init, no
 * network) in tests and bare checkouts, mirroring the API's empty-DSN behaviour.
 * The DSN selects the project (`erpify-pwa-dev` vs `erpify-pwa-prod`), baked per
 * build; `environment` further tags dev / staging / prod.
 */
export function sentryInitOptions() {
  // Trim so a whitespace-only value doesn't pass the gate and init the SDK with
  // an invalid DSN (and stays consistent with createTelemetry's sink gate).
  const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN?.trim();
  if (!dsn) {
    return null;
  }

  const appEnv = process.env.NEXT_PUBLIC_APP_ENV;

  return {
    dsn,
    environment: appEnv,
    sendDefaultPii: false,
    tracesSampleRate:
      appEnv === AppEnv.DEVELOPMENT ? DEV_TRACES_SAMPLE_RATE : PROD_TRACES_SAMPLE_RATE,
    // Scrub both error and performance/transaction events (tracing is on in every
    // env), so neither a denylisted key nor a request URL can ride out on a traced
    // payload — a transaction's `spans` carry the URL of every traced fetch, which
    // is the half of this that only `beforeSendTransaction` ever sees.
    beforeSend: scrubSentryEvent,
    beforeSendTransaction: scrubSentryEvent,
  };
}
