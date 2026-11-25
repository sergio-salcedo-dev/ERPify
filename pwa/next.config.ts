import type { NextConfig } from "next";
import { withSentryConfig } from "@sentry/nextjs/config";
import { sentryUploadOptions } from "./sentry-upload-options";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  // Isolate the e2e webServer's build dir from the dev container's `.next/` (shared via bind mount).
  ...(process.env.NEXT_DIST_DIR ? { distDir: process.env.NEXT_DIST_DIR } : {}),
  typescript: {
    ignoreBuildErrors: false,
  },
  // No `remotePatterns`: every allowed remote host makes `/_next/image` an anonymous fetch-and-transcode proxy
  // for that host, paid in this server's CPU and image cache. Add one only for a host the app renders from.
  images: {
    formats: ["image/avif", "image/webp"], // Optimizes image loading for PWAs
    minimumCacheTTL: 60 * 60 * 24, // 1 day in seconds, to ensure images are cached effectively for offline use
  },
  output: "standalone",
  transpilePackages: ["motion"],
  // Playwright local baseURL uses 127.0.0.1; Next dev treats it separately from localhost.
  allowedDevOrigins: ["127.0.0.1"],
  experimental: {
    optimizePackageImports: ["react", "lodash"],
  },
  // Configuration of headers for security and PWA
  async headers() {
    // The Content-Security-Policy is NOT set here: it carries a per-request nonce, so
    // `src/proxy.ts` mints and sets it (see `buildContentSecurityPolicy`). A second CSP
    // header from this list would be intersected with the proxy's and bring back
    // whatever this one allowed.
    return [
      {
        source: "/(.*)",
        headers: [
          {
            key: "X-Content-Type-Options",
            value: "nosniff",
          },
          {
            key: "X-Frame-Options",
            value: "DENY",
          },
          {
            key: "Referrer-Policy",
            value: "strict-origin-when-cross-origin",
          },
          {
            key: "Permissions-Policy",
            value: "camera=(), microphone=(), geolocation=(), interest-cohort=()",
          },
          {
            key: "Cross-Origin-Opener-Policy",
            value: "same-origin",
          },
          {
            key: "Cross-Origin-Resource-Policy",
            value: "same-origin",
          },
          {
            key: "Strict-Transport-Security",
            value: "max-age=63072000; includeSubDomains; preload",
          },
        ],
      },
      // `public/` files are served unversioned, so they cannot be `immutable`; a day in the
      // browser plus a week of stale-while-revalidate stops a conditional request per page view.
      // Hashed `/_next/static` assets already carry `immutable` from Next itself.
      {
        source: "/:file(favicon\\.ico|robots\\.txt)",
        headers: [
          {
            key: "Cache-Control",
            value: "public, max-age=86400, stale-while-revalidate=604800",
          },
        ],
      },
      // Screens whose URL is itself sensitive send no Referer at all — not even
      // the origin. The token screens receive `?token=<id>.<secret>`; the audit
      // screen holds its filter state in the query string, so its URL names the
      // people being investigated. Under the catch-all policy a same-origin
      // request still carries the whole URL, and the API is same-origin, so the
      // referring URL reaches a log the erasure path cannot reach. These entries
      // must sit AFTER the catch-all: when several sources match, the last value
      // set for a header key wins.
      {
        source: "/accept-invitation",
        headers: [
          {
            key: "Referrer-Policy",
            value: "no-referrer",
          },
        ],
      },
      {
        source: "/reset-password",
        headers: [
          {
            key: "Referrer-Policy",
            value: "no-referrer",
          },
        ],
      },
      {
        source: "/backoffice/audit",
        headers: [
          {
            key: "Referrer-Policy",
            value: "no-referrer",
          },
        ],
      },
      // Two entries, because the exact source matches only itself: a future
      // `/backoffice/audit/<id>` would silently fall back to the catch-all,
      // which sends the full URL to any same-origin recipient.
      {
        source: "/backoffice/audit/:path*",
        headers: [
          {
            key: "Referrer-Policy",
            value: "no-referrer",
          },
        ],
      },
    ];
  },

  logging: {
    fetches: {
      fullUrl: true,
    },
  },

  devIndicators: {
    position: "bottom-right",
  },
};

// Source-map upload credentials and the upload decision: see
// `sentry-upload-options.ts`. A token that cannot upload is a misconfiguration
// the build would otherwise carry out in silence — the image builds, traces stay
// minified, and nothing says why — so it is warned about here.
const sentryUpload = sentryUploadOptions(process.env);
if (sentryUpload.warning !== null) {
  console.warn(sentryUpload.warning);
}

// Wrap with Sentry. Events are routed through a same-origin tunnel
// (`/monitoring`) so the locked-down CSP `connect-src 'self'` covers them with
// no widening, and ad-blockers can't drop them.
//
// Maps are uploaded, never published: SERVING them would hand the entire client
// source — every identifier and every comment — to any visitor who opens
// devtools, while uploading them is the whole point of the exercise.
//
// What the SDK generates and deletes is asymmetric between client and server,
// and only the client half is public:
//  - Client maps exist only while upload is on: under Turbopack the SDK turns
//    `productionBrowserSourceMaps` on unless this config sets it, and only when
//    `sourcemaps.disable` is false. They land in `.next/static`, which Next
//    serves at `/_next/static`, and `deleteSourcemapsAfterUpload` deletes them
//    after the upload — whether or not the upload succeeded — and strips their
//    `sourceMappingURL` comments. That deletion runs in the SDK's post-compile
//    hook, which a Turbopack build uses unless `useRunAfterProductionCompileHook`
//    is set to `false`; with the hook off the maps are still generated and
//    nothing deletes them. Setting the flag here pins what the SDK would
//    otherwise default to at build time, so a changed default or a casual edit
//    is a visible change rather than a silent republish.
//  - Server maps are emitted on every production build, upload or not, into
//    `.next/server`, and the SDK's deletion glob covers `static/**` only, so
//    they survive into the standalone image. Next never serves that directory:
//    its static routes are `public/` and `.next/static` alone. They are image
//    contents, readable by whoever can pull the image, not a URL.
//
// Three settings would genuinely publish client maps, and the gate refuses all
// of them: `filesToDeleteAfterUpload`, which OVERRIDES the deletion flag so a
// narrow glob serves everything it does not name; `productionBrowserSourceMaps:
// true`, which generates maps even when upload is off and nothing deletes them;
// and `useRunAfterProductionCompileHook: false`, which switches the deleting
// hook off. The gate also refuses `unstable_sentryWebpackPluginOptions`, which
// the SDK no longer reads (it only warns) and whose `sourcemaps` would replace
// the deletion glob wholesale if a release ever honoured it again.
//
// The token is used for exactly three things, all in the post-compile hook: the
// source-map upload, creating the release those maps are attached to, and
// associating that release with its commit when `SENTRY_REPOSITORY` is set.
//
// `silent` is off so a failed upload or a refused credential is printed in the
// build log: the SDK reports both as an error and carries on, and with `silent`
// on it reports nothing. Keying it to `CI` would not help, because the image
// builder never defines that variable. Gate: `tests/sentry-sourcemap-exposure.test.ts`.
export default withSentryConfig(nextConfig, {
  tunnelRoute: "/monitoring",
  silent: false,
  ...sentryUpload.options,
  sourcemaps: {
    disable: !sentryUpload.uploadsSourcemaps,
    deleteSourcemapsAfterUpload: true,
  },
});
