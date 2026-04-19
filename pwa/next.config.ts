import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  reactStrictMode: true,
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
            value: "camera=(), microphone=(), geolocation=()",
          },
          {
            key: "Strict-Transport-Security",
            value: "max-age=63072000; includeSubDomains; preload",
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

export default nextConfig;
