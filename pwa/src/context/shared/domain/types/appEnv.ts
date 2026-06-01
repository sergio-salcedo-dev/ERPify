/**
 * Deployment environment values for the PWA, surfaced to the browser via the
 * public build-time `NEXT_PUBLIC_APP_ENV` var. Distinct from {@link NodeEnv}
 * (which collapses staging into "production"): `NODE_ENV` is always
 * `production` in the built image, so it cannot tell staging from prod. Spelled
 * once here so call sites compare against the const, never a raw literal.
 */
export const AppEnv = {
  DEVELOPMENT: "dev",
  STAGING: "staging",
  PRODUCTION: "prod",
} as const;

export type AppEnv = (typeof AppEnv)[keyof typeof AppEnv];
