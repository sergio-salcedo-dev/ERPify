/**
 * Test double for `@sentry/nextjs/config`, aliased in `vitest.config.ts`, so a test that imports
 * `next.config.ts` (`next-config-headers.test.ts`) reads the Next config itself rather than the
 * real build wrapper, which pulls in the bundler plugins.
 */
export function withSentryConfig<T>(config: T): T {
  return config;
}
