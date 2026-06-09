/**
 * Test double for `@sentry/nextjs`, aliased in `vitest.config.ts`. Unit tests
 * must not load the real SDK — it is heavy (OpenTelemetry + browser globals) and
 * its `.` export conditions don't resolve under Vitest's jsdom environment.
 *
 * This only swaps the RUNTIME module for tests; TypeScript / `next build` still
 * type-check against the real package. Per-test `vi.mock("@sentry/nextjs")`
 * takes precedence over this stub wherever a test asserts SDK interactions
 * (see `SentryTelemetry.test.ts`, `createTelemetry.test.ts`). Everything here is
 * an inert no-op so importing telemetry never touches the network.
 */
export const init = (): void => {};

export const captureRouterTransitionStart = (): void => {};

export const captureRequestError = (): void => {};

export const captureException = (): void => {};

export const captureMessage = (): void => {};

export function withScope(callback: (scope: unknown) => void): void {
  callback({ setLevel: () => {}, setTag: () => {}, setContext: () => {} });
}

export function withSentryConfig<T>(config: T): T {
  return config;
}
