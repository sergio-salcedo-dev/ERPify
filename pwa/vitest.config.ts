import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import path from "node:path";

export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./tests/setup.ts"],
    exclude: ["**/node_modules/**", "**/dist/**", "**/tests/e2e/**"],
    reporters: ["default", ["junit", { outputFile: "reports/vitest/junit.xml" }]],
  },
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
      // Unit tests run against a no-op stub of the Sentry SDK — the real package
      // is heavy and its export conditions don't resolve under jsdom. Production
      // / `next build` use the real package; per-test vi.mock still overrides
      // this where SDK calls are asserted. See tests/stubs/sentryNextjs.ts.
      "@sentry/nextjs": path.resolve(__dirname, "./tests/stubs/sentryNextjs.ts"),
    },
  },
});
