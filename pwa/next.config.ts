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
    // remotePatterns: [
    //   {
    //     protocol: "https",
    //     hostname: "*.your-cdn.com",
    //   },
    //   {
    //     protocol: "https",
    //     hostname: "api.yourdomain.com", // Symfony media proxy
    //   },
    // ],
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
    ];
  },

  // async headers() {
  //   const csp = `
  //     default-src 'self';
  //     script-src 'self' 'unsafe-inline' 'unsafe-eval';
  //     style-src 'self' 'unsafe-inline';
  //     img-src 'self' data: blob: https:;
  //     font-src 'self';
  //     connect-src 'self' https://api.yourdomain.com;
  //     frame-ancestors 'none';
  //     base-uri 'self';
  //     form-action 'self';
  //   `.replace(/\n/g, "");
  //
  //   return [
  //     {
  //       source: "/(.*)",
  //       headers: [
  //         { key: "Content-Security-Policy", value: csp },
  //
  //         { key: "X-Content-Type-Options", value: "nosniff" },
  //         { key: "X-Frame-Options", value: "DENY" },
  //         { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  //
  //         {
  //           key: "Permissions-Policy",
  //           value: "camera=(), microphone=(), geolocation=()",
  //         },
  //
  //         {
  //           key: "Strict-Transport-Security",
  //           value: "max-age=63072000; includeSubDomains; preload",
  //         },
  //       ],
  //     },
  //   ];
  // },
  //
  logging: {
    fetches: {
      fullUrl: true,
    },
  },

  devIndicators: {
    position: "bottom-right",
  },
  // async rewrites() {
  //   return [
  //     {
  //       source: "/api/:path*",
  //       destination: "https://api.yourdomain.com/:path*",
  //     },
  //     {
  //       source: "/api/:path*",
  //       destination: "https://yourdomain.com/api/v1/:path*",
  //     },
  //   ];
  // },
};

// Wrap with Sentry. Events are routed through a same-origin tunnel
// (`/monitoring`) so the locked-down CSP `connect-src 'self'` covers them with
// no widening, and ad-blockers can't drop them. Source-map upload is disabled
// (no `authToken`/`org`/`project`) — readable prod stack traces are deferred
// (see deferred-work.md); maps are never shipped to the browser.
export default withSentryConfig(nextConfig, {
  tunnelRoute: "/monitoring",
  silent: !process.env.CI,
  sourcemaps: {
    disable: true,
  },
});
