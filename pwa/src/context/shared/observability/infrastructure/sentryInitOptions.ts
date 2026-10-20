import type { init } from "@sentry/nextjs";
import { AppEnv } from "@/context/shared/environment/domain/AppEnv";
import { scrubSentryEvent, scrubSentrySpan } from "./scrubSentryEvent";

/** What `Sentry.init` accepts in any of the three runtimes. */
type SentryInitOptions = NonNullable<Parameters<typeof init>[0]>;

/**
 * Every collection category the SDK knows, each set explicitly. Leaving `dataCollection` unset is
 * NOT the restrictive choice: the SDK then collects cookies, user info, every request and response
 * body, database query data and queue payloads, so an absent key is a grant. This is the baseline
 * the SDK documents as equivalent to the old `sendDefaultPii: false`, with the header and query
 * denylists it applied by default; {@link scrubSentryEvent} and {@link scrubSentrySpan} still run
 * over whatever survives, since a key denylist cannot see an identifier spelled under another name.
 */
const IP_AND_IDENTITY_HEADERS = ["forwarded", "-ip", "remote-", "via", "-user"];
const DATA_COLLECTION = {
  userInfo: false,
  cookies: false,
  httpHeaders: {
    request: { deny: IP_AND_IDENTITY_HEADERS },
    response: { deny: IP_AND_IDENTITY_HEADERS },
  },
  httpBodies: [],
  urlQueryParams: { deny: IP_AND_IDENTITY_HEADERS },
  graphQL: { document: false, variables: false },
  genAI: { inputs: false, outputs: false },
  databaseQueryData: false,
  queues: false,
} satisfies SentryInitOptions["dataCollection"];

/** Full tracing in dev (only the developer hits `erpify-pwa-dev`) for local verification. */
const DEV_TRACES_SAMPLE_RATE = 1;
/** Sampled tracing in staging/prod — parity with the API's prod `SENTRY_TRACES_SAMPLE_RATE`. */
const PROD_TRACES_SAMPLE_RATE = 0.2;

/**
 * Single source of truth for the Sentry SDK options shared by all three Next
 * runtimes (client / node / edge), so DSN gating, environment tagging, the data
 * collection policy, the scrub hooks, and the tracing sample rate live in one place.
 *
 * Returns `null` when `NEXT_PUBLIC_SENTRY_DSN` is unset/empty — the caller then
 * skips `Sentry.init` entirely, keeping the SDK fully inert (no init, no
 * network) in tests and bare checkouts, mirroring the API's empty-DSN behaviour.
 * The DSN selects the project (`erpify-pwa-dev` vs `erpify-pwa-prod`), baked per
 * build; `environment` further tags dev / staging / prod.
 */
export function sentryInitOptions(): SentryInitOptions | null {
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
    dataCollection: DATA_COLLECTION,
    tracesSampleRate:
      appEnv === AppEnv.DEVELOPMENT ? DEV_TRACES_SAMPLE_RATE : PROD_TRACES_SAMPLE_RATE,
    // Scrub both error events and spans (tracing is on in every env), so neither a
    // denylisted key nor a request URL can ride out on a traced payload. Spans are
    // streamed one at a time and never pass through `beforeSend`, so a traced fetch's
    // URL attributes are reachable only from `beforeSendSpan`.
    beforeSend: scrubSentryEvent,
    beforeSendSpan: scrubSentrySpan,
  };
}
