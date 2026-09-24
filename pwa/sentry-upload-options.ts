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
 * environment variable and still create a release. The return type admits the
 * three credential keys and `release` and nothing else, so this fragment cannot
 * carry a setting the source-map gate refuses.
 *
 * The release is named by `SENTRY_RELEASE`, the commit SHA `make` exports for a
 * prod/staging build, and the name is injected into both bundles whether or not
 * upload is on, so the API and the PWA report the same release. It is created in
 * Sentry only when upload is on, because creating it needs the token. With no
 * name and upload off, `release` is left out entirely, so the SDK keeps its own
 * fallback (`GITHUB_SHA` and other CI variables, then the git revision).
 *
 * Commits are associated by naming the commit and the repository, which
 * happens only when `SENTRY_REPOSITORY` is set (as Sentry's GitHub integration
 * lists it) and the release is a full SHA. Otherwise `setCommits` is left
 * unset, and the plugin falls back to its `auto` mode. That mode reads the local
 * `.git`, which is excluded from the image build context, so it associates
 * nothing and logs the failure only at debug level. It cannot be pinned off from
 * here, because `SentryBuildOptions` does not type `false`, even though the
 * plugin underneath accepts it. A repository that cannot take effect (upload
 * off, or no SHA to pin) is a misconfiguration and is warned about.
 * `ignoreMissing` lets the first release, which has no predecessor to diff
 * against, associate its own commit instead of failing.
 */
export type SentryUploadOptions = {
  readonly options: Pick<SentryBuildOptions, "authToken" | "org" | "project" | "release">;
  readonly uploadsSourcemaps: boolean;
  /** Set when a setting cannot take effect; it names the missing piece, never the token. */
  readonly warning: string | null;
};

/** `process.env`, or any map of the same shape; the function reads six of its keys. */
type SentryEnvironment = Readonly<Record<string, string | undefined>>;

const ORG_SCOPED_TOKEN_PREFIX = "sntrys_";
/** A full object id: 40 hex digits for SHA-1, 64 for a SHA-256 repository. */
const COMMIT_SHA = /^(?:[0-9a-f]{40}|[0-9a-f]{64})$/;

export function sentryUploadOptions(env: SentryEnvironment): SentryUploadOptions {
  const authToken = env.SENTRY_AUTH_TOKEN?.trim();
  const org = env.SENTRY_ORG?.trim();
  const project =
    env.SENTRY_PROJECT?.trim() || `erpify-pwa-${env.NEXT_PUBLIC_APP_ENV?.trim() || "dev"}`;
  const releaseName = env.SENTRY_RELEASE?.trim() || undefined;
  const name = releaseName === undefined ? {} : { name: releaseName };

  if (authToken && (org || authToken.startsWith(ORG_SCOPED_TOKEN_PREFIX))) {
    const commits = commitAssociation(env.SENTRY_REPOSITORY?.trim(), releaseName);
    return {
      options: {
        authToken,
        project,
        ...(org ? { org } : {}),
        release: {
          ...name,
          create: true,
          ...(commits.setCommits === undefined ? {} : { setCommits: commits.setCommits }),
        },
      },
      uploadsSourcemaps: true,
      warning: commits.warning,
    };
  }
  const warnings = [
    authToken
      ? "sentry: SENTRY_AUTH_TOKEN is set but SENTRY_ORG is empty and the token is not an " +
        `organisation token (${ORG_SCOPED_TOKEN_PREFIX}…), so source-map upload is off.`
      : null,
    env.SENTRY_REPOSITORY?.trim()
      ? "sentry: SENTRY_REPOSITORY is set but source-map upload is off, so no commits are " +
        "associated with the release."
      : null,
  ].filter((warning) => warning !== null);
  return {
    options: {
      authToken: "",
      ...(releaseName === undefined ? {} : { release: { name: releaseName, create: false } }),
    },
    uploadsSourcemaps: false,
    warning: warnings.length > 0 ? warnings.join(" ") : null,
  };
}

type ReleaseOptions = NonNullable<SentryBuildOptions["release"]>;

function commitAssociation(
  repository: string | undefined,
  releaseName: string | undefined,
): { setCommits: ReleaseOptions["setCommits"]; warning: string | null } {
  if (!repository) {
    return { setCommits: undefined, warning: null };
  }
  if (releaseName === undefined || !COMMIT_SHA.test(releaseName)) {
    return {
      setCommits: undefined,
      warning:
        "sentry: SENTRY_REPOSITORY is set but SENTRY_RELEASE is not a full commit SHA, " +
        "so no commits are associated with the release.",
    };
  }
  return {
    setCommits: { repo: repository, commit: releaseName, ignoreMissing: true },
    warning: null,
  };
}
