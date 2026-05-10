/**
 * Centralised `process.env.NODE_ENV` values for the PWA. The literal strings
 * `"development"`, `"production"` and `"test"` are spelled here once so call
 * sites compare against {@link NodeEnv.DEVELOPMENT} rather than a free-form
 * literal — typos are caught at compile time and refactors stay safe.
 *
 * The matching TypeScript type is derived via
 * `(typeof NodeEnv)[keyof typeof NodeEnv]` so adding / renaming a value
 * forces every call site to update.
 */
export const NodeEnv = {
  DEVELOPMENT: "development",
  PRODUCTION: "production",
  TEST: "test",
} as const;
export type NodeEnv = (typeof NodeEnv)[keyof typeof NodeEnv];
