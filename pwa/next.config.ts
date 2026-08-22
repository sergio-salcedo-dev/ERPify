import type { NextConfig } from "next";
import { withSentryConfig } from "@sentry/nextjs";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  // Isolate the e2e webServer's build dir from the dev container's `.next/` (shared via bind mount).
  ...(process.env.NEXT_DIST_DIR ? { distDir: process.env.NEXT_DIST_DIR } : {}),
  typescript: {
    ignoreBuildErrors: false,
  },
  // Allow access to remote image placeholder.
  images: {
    formats: ["image/avif", "image/webp"], // Optimizes image loading for PWAs
    minimumCacheTTL: 60 * 60 * 24, // 1 day in seconds, to ensure images are cached effectively for offline use
    remotePatterns: [
      {
        protocol: "https",
        hostname: "picsum.photos",
        port: "",
        pathname: "/**", // This allows any path under the hostname
      },
    ],
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
    // Build a Content-Security-Policy. The dangerous directives are locked
    // down (`object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'`,
    // `form-action 'self'`) so an injected element cannot exfiltrate via
    // `<base>`, `<form action="…">`, or be embedded in a hostile iframe.
    //
    // Notes:
    // - Next.js still emits inline `<script>` tags for hydration data and
    //   inline `<style>` tags for critical CSS, so `script-src` / `style-src`
    //   keep `'unsafe-inline'`. A nonce-based CSP via `middleware.ts` is the
    //   future-state and removes both.
    // - `'unsafe-eval'` is required by Next/Turbopack in development for HMR
    //   only; it is dropped in production builds.
    // - `connect-src` allows the Symfony API origin via
    //   `NEXT_PUBLIC_API_BASE_URL`. Same-origin (`'self'`) covers the
    //   default Docker stack where FrankenPHP serves /api on the same host.
    const isProd = process.env.NODE_ENV === "production";
    const apiOrigin = (() => {
      const raw = process.env.NEXT_PUBLIC_API_BASE_URL?.trim();
      if (!raw) return "";
      try {
        const u = new URL(raw);
        return `${u.protocol}//${u.host}`;
      } catch {
        return "";
      }
    })();

    const csp = [
      "default-src 'self'",
      `script-src 'self' 'unsafe-inline'${isProd ? "" : " 'unsafe-eval'"}`,
      "style-src 'self' 'unsafe-inline'",
      "img-src 'self' data: blob: https:",
      "font-src 'self' data:",
      `connect-src 'self' ${apiOrigin} https://picsum.photos`.trim(),
      "media-src 'self' data: blob:",
      "worker-src 'self' blob:",
      "manifest-src 'self'",
      "object-src 'none'",
      "frame-ancestors 'none'",
      "form-action 'self'",
      "base-uri 'self'",
      "upgrade-insecure-requests",
    ]
      .filter(Boolean)
      .join("; ");

    return [
      {
        source: "/(.*)",
        headers: [
          {
            key: "Content-Security-Policy",
            value: csp,
          },
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

// Source-map upload credentials. All three are server-only and must NEVER take
// the `NEXT_PUBLIC_` prefix: the token grants write access to the Sentry
// project, and Next inlines every prefixed literal into the browser bundle.
//
// The project slug is derived per environment (`erpify-pwa-dev` /
// `erpify-pwa-prod`) from the same `NEXT_PUBLIC_APP_ENV` that selects the DSN,
// so one image build cannot upload its maps to the other environment's project
// while reporting events to this one. An explicit `SENTRY_PROJECT` overrides it.
const sentryAuthToken = process.env.SENTRY_AUTH_TOKEN?.trim();
const sentryOrg = process.env.SENTRY_ORG?.trim();
const sentryProject =
  process.env.SENTRY_PROJECT?.trim() ||
  `erpify-pwa-${process.env.NEXT_PUBLIC_APP_ENV?.trim() || "dev"}`;

// Upload is opt-in on the credentials being present, and its absence is not an
// error: a contributor, a fork and every CI job that only type-checks build
// without the secret, and failing there would make the token a prerequisite for
// compiling the app rather than for symbolicating it.
const uploadsSourcemaps = Boolean(sentryAuthToken && sentryOrg);

// Wrap with Sentry. Events are routed through a same-origin tunnel
// (`/monitoring`) so the locked-down CSP `connect-src 'self'` covers them with
// no widening, and ad-blockers can't drop them.
//
// Maps are uploaded, never published: SERVING them would hand the entire client
// source — every identifier and every comment — to any visitor who opens
// devtools, while uploading them is the whole point of the exercise.
//
// `deleteSourcemapsAfterUpload` already DEFAULTS to true in @sentry/nextjs
// (10.70.0), so writing it here does not switch the behaviour on — it pins it,
// so a future default flip or a casual edit is a visible change rather than a
// silent one. The thing that would genuinely republish the maps is
// `filesToDeleteAfterUpload`, which OVERRIDES this flag entirely: a narrow glob
// there deletes only what it names and leaves the rest served. Both are held by
// `tests/sentry-sourcemap-exposure.test.ts`.
export default withSentryConfig(nextConfig, {
  tunnelRoute: "/monitoring",
  silent: !process.env.CI,
  ...(uploadsSourcemaps
    ? { authToken: sentryAuthToken, org: sentryOrg, project: sentryProject }
    : {}),
  sourcemaps: {
    disable: !uploadsSourcemaps,
    deleteSourcemapsAfterUpload: true,
  },
});
