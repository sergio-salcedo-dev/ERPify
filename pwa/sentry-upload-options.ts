import type { SentryBuildOptions } from "@sentry/nextjs";

/**
 * The Sentry credentials handed to `withSentryConfig`, and whether source maps
 * are uploaded at all. A pure function of the environment so every branch is
 * asserted (`tests/sentry-upload-options.test.ts`) rather than read.
 *
 * All three variables are server-only and must NEVER take the `NEXT_PUBLIC_`
 * prefix: the token grants write access to the Sentry project, and Next
 * inlines every prefixed literal into the browser bundle.
 *
 * The project slug is derived per environment (`erpify-pwa-dev` /
 * `erpify-pwa-prod`) from the same `NEXT_PUBLIC_APP_ENV` that selects the DSN,
 * so one image build cannot upload its maps to the other environment's project
 * while reporting events to this one. An explicit `SENTRY_PROJECT` overrides it.
 *
 * Upload is opt-in on the credentials being present, and its absence is not an
 * error: a contributor, a fork and every CI job that only type-checks build
 * without the secret, and failing there would make the token a prerequisite for
 * compiling the app rather than for symbolicating it. An org-scoped token
 * (`sntrys_…`) carries its organisation, so it needs no SENTRY_ORG; a user
 * token does.
 *
 * With upload off the options carry an EMPTY `authToken` rather than none: an
 * absent one makes the upload plugin fall back to the `SENTRY_AUTH_TOKEN`
 * environment variable and still create a release and associate commits with
 * it. The return type admits the three credential keys and nothing else, so
 * this fragment cannot carry a setting the source-map gate refuses.
 */
export type SentryUploadOptions = {
  readonly options: Pick<SentryBuildOptions, "authToken" | "org" | "project">;
  readonly uploadsSourcemaps: boolean;
  /** Set when a token is present that cannot upload; it names the missing piece, never the token. */
  readonly warning: string | null;
};

/** `process.env`, or any map of the same shape; the function reads four of its keys. */
type SentryEnvironment = Readonly<Record<string, string | undefined>>;

const ORG_SCOPED_TOKEN_PREFIX = "sntrys_";

export function sentryUploadOptions(env: SentryEnvironment): SentryUploadOptions {
  const authToken = env.SENTRY_AUTH_TOKEN?.trim();
  const org = env.SENTRY_ORG?.trim();
  const project =
    env.SENTRY_PROJECT?.trim() || `erpify-pwa-${env.NEXT_PUBLIC_APP_ENV?.trim() || "dev"}`;

  if (authToken && (org || authToken.startsWith(ORG_SCOPED_TOKEN_PREFIX))) {
    return {
      options: { authToken, project, ...(org ? { org } : {}) },
      uploadsSourcemaps: true,
      warning: null,
    };
  }
  return {
    options: { authToken: "" },
    uploadsSourcemaps: false,
    warning: authToken
      ? "sentry: SENTRY_AUTH_TOKEN is set but SENTRY_ORG is empty and the token is not an " +
        `organisation token (${ORG_SCOPED_TOKEN_PREFIX}…), so source-map upload is off.`
      : null,
  };
}
