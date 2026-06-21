import * as Sentry from "@sentry/nextjs";
import { sentryInitOptions } from "@/context/shared/observability/infrastructure/sentryInitOptions";

// Client-side Sentry init. Inert when no DSN is configured (see sentryInitOptions).
// Browser performance tracing is on by default once tracesSampleRate is set.
const options = sentryInitOptions();
if (options) {
  Sentry.init(options);
}

// Required by Next.js App Router so client-side navigations are traced.
export const onRouterTransitionStart = Sentry.captureRouterTransitionStart;
