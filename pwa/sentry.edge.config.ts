import * as Sentry from "@sentry/nextjs";
import { sentryInitOptions } from "@/context/shared/infrastructure/Observability/sentryInitOptions";

// Edge runtime Sentry init (proxy.ts / edge route handlers). Imported by
// instrumentation.ts#register(). Inert when no DSN is configured.
const options = sentryInitOptions();
if (options) {
  Sentry.init(options);
}
