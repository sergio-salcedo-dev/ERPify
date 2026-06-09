import * as Sentry from "@sentry/nextjs";

/**
 * Next.js instrumentation hook: loads the runtime-specific Sentry config once,
 * at server boot, for each Next runtime. Both configs are inert without a DSN.
 */
export async function register() {
  if (process.env.NEXT_RUNTIME === "nodejs") {
    await import("./sentry.server.config");
  }

  if (process.env.NEXT_RUNTIME === "edge") {
    await import("./sentry.edge.config");
  }
}

// Captures errors thrown in Server Components, route handlers, and the proxy.
export const onRequestError = Sentry.captureRequestError;
