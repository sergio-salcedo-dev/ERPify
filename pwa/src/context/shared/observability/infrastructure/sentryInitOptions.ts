import type { init } from "@sentry/nextjs";
import { AppEnv } from "@/context/shared/environment/domain/AppEnv";
import { IDENTITY_AXES } from "@/context/shared/observability/domain/redaction";
import { scrubSentryEvent, scrubSentrySpan } from "./scrubSentryEvent";

/** What `Sentry.init` accepts in any of the three runtimes. */
type SentryInitOptions = NonNullable<Parameters<typeof init>[0]>;

/** Header-name fragments that carry a client address or an authenticated user. */
const IP_AND_IDENTITY_HEADERS = ["forwarded", "-ip", "remote-", "via", "-user"];

/**
 * Query-parameter names the SDK filters at the source, before any hook runs, on every surface it
 * writes a URL to. The SDK matches each entry as a case-insensitive substring of the decoded
 * name, so `[value]` covers the search grammar's `filters[N][value]` in every spelling the wire
 * uses. {@link scrubSentryEvent} and {@link scrubSentrySpan} still run afterwards, because a name
 * list cannot see an identifier carried inside another URL (`?next=<the audit URL>`).
 */
const SENSITIVE_QUERY_PARAMS = [...IP_AND_IDENTITY_HEADERS, ...IDENTITY_AXES, "[value]"];

/**
 * Every collection category, each set explicitly. Leaving `dataCollection` unset, or a category
 * out of it, is NOT the restrictive choice: the SDK then collects cookies, user info, every
 * request and response body, database query data, queue payloads and stack-frame variables, so
 * an absent key is a grant. The test pins the key set, so a category a later SDK adds has to be
 * decided here rather than inherited.
 */
const DATA_COLLECTION = {
  userInfo: false,
  cookies: false,
  httpHeaders: {
    request: { deny: IP_AND_IDENTITY_HEADERS },
    response: { deny: IP_AND_IDENTITY_HEADERS },
  },
  httpBodies: [],
  urlQueryParams: { deny: SENSITIVE_QUERY_PARAMS },
  graphQL: { document: false, variables: false },
  genAI: { inputs: false, outputs: false },
  databaseQueryData: false,
  queues: false,
  stackFrameVariables: false,
  frameContextLines: 5,
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
 * build; `environment` further tags dev / staging / prod, or the `NEXT_PUBLIC_SENTRY_ENVIRONMENT`
 * override (`rehearsal` for the local prod rehearsal).
 */
export function sentryInitOptions(): SentryInitOptions | null {
  // Trim so a whitespace-only value doesn't pass the gate and init the SDK with
  // an invalid DSN (and stays consistent with createTelemetry's sink gate).
  const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN?.trim();
  if (!dsn) {
    return null;
  }

  const appEnv = process.env.NEXT_PUBLIC_APP_ENV;
  // The local prod rehearsal builds with APP_ENV=prod against the prod project; this override is
  // what keeps its events from reading as the real deployment's. Empty falls back to the app env.
  const environment = process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT?.trim() || appEnv;

  return {
    dsn,
    environment,
    dataCollection: DATA_COLLECTION,
    tracesSampleRate:
      appEnv === AppEnv.DEVELOPMENT ? DEV_TRACES_SAMPLE_RATE : PROD_TRACES_SAMPLE_RATE,
    // Scrub both error events and spans (tracing is on in every env), so neither a
    // denylisted key nor a request URL can ride out on a traced payload. Spans are
    // streamed one at a time and never pass through `beforeSend`, so a traced fetch's
    // URL attributes are reachable only from `beforeSendSpan`.
    beforeSend: scrubSentryEvent,
    beforeSendSpan: scrubSentrySpan,
    // Pinned rather than defaulted: with the option absent, the node and edge SDKs read
    // `SENTRY_TRACE_LIFECYCLE` from the environment, and `static` there would make the SDK skip
    // `beforeSendSpan` — every server span would leave unscrubbed with no hook to stop it.
    traceLifecycle: "stream",
  };
}
