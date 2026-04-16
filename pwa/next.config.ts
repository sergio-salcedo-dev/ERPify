import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  typescript: {
    ignoreBuildErrors: false,
  },
  // Allow access to remote image placeholder.
  images: {
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
};

export default nextConfig;
