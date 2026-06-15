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
    coverage: {
      // SonarCloud imports the lcov report (sonar.javascript/typescript.lcov.reportPaths
      // → pwa/coverage/lcov.info). v8 is the native provider; text keeps a local summary.
      provider: "v8",
      reporter: ["text", "lcov"],
      reportsDirectory: "./coverage",
      include: ["src/**/*.{ts,tsx}"],
      exclude: ["src/**/*.d.ts"],
    },
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
