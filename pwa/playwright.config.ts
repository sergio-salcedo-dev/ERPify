import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import { defineConfig, devices } from "@playwright/test";

const pwaDir = path.resolve(__dirname);

/** Parse a minimal dotenv subset (KEY=value, optional # comments). */
function parseDotenv(text: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (let line of text.split(/\r?\n/)) {
    const hash = line.indexOf("#");
    if (hash >= 0) line = line.slice(0, hash);
    line = line.trim();
    if (!line) continue;
    const eq = line.indexOf("=");
    if (eq < 1) continue;
    const key = line.slice(0, eq).trim();
    if (!/^[A-Za-z_]\w*$/.test(key)) continue;
    let val = line.slice(eq + 1).trim();
    if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
      val = val.slice(1, -1);
    }
    out[key] = val;
  }
  return out;
}

/**
 * Load `pwa/.env` without clobbering the shell, then `.env.local` (overrides shell + `.env`).
 * So `PW_WORKERS` in `.env` / `.env.local` works for `npm run e2e`.
 */
function applyPlaywrightDotenvFiles(): void {
  const envFile = path.join(pwaDir, ".env");
  if (existsSync(envFile)) {
    const parsed = parseDotenv(readFileSync(envFile, "utf8"));
    for (const [key, val] of Object.entries(parsed)) {
      if (process.env[key] === undefined) {
        process.env[key] = val;
      }
    }
  }
  const localFile = path.join(pwaDir, ".env.local");
  if (existsSync(localFile)) {
    const parsed = parseDotenv(readFileSync(localFile, "utf8"));
    for (const [key, val] of Object.entries(parsed)) {
      process.env[key] = val;
    }
  }
}

applyPlaywrightDotenvFiles();

/** Port for Playwright-spawned Next dev (unprivileged; `npm run dev` uses :80 for Docker/host parity). */
const localWebServerPort = 3000;

/** Docker / CI: https://localhost. Local + webServer: http://127.0.0.1:3000 (see dev:e2e). */
const playwrightBaseURL =
  process.env.PLAYWRIGHT_BASE_URL ??
  (process.env.CI ? "https://localhost" : `http://127.0.0.1:${localWebServerPort}`);

/**
 * Spawn `npm run dev:e2e` (Next on :3000) only when tests target HTTP. HTTPS (e.g. FrankenPHP on :443)
 * cannot be served by Next dev, so no webServer — assume Compose (or similar) is already up. Playwright
 * still skips the command if something is already listening (`reuseExistingServer`).
 * Set PLAYWRIGHT_SKIP_WEBSERVER=1 to never spawn the dev server even for http:// URLs.
 */
const useWebServer =
  !process.env.CI &&
  playwrightBaseURL.startsWith("http://") &&
  process.env.PLAYWRIGHT_SKIP_WEBSERVER !== "1";

/** Override parallel workers: `pwa/.env`, `.env.local`, or shell. Takes precedence over CI default (1). */
function playwrightWorkers(): number | undefined {
  const raw = process.env.PW_WORKERS?.trim();
  if (raw) {
    const n = Number(raw);
    if (Number.isFinite(n) && n > 0) {
      return n;
    }
  }
  return process.env.CI ? 1 : undefined;
}

export default defineConfig({
  testDir: "./tests/e2e",
  outputDir: "reports/playwright/test-results",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  //   - Shards split the test set across different processes/runners. --shard=1/3 means "this runner runs 1/3 of the tests."
  //   - Workers parallelize tests within a single process. workers: 1 means "inside this runner, run one test at a time."
  //
  //   So with matrix: [1,2,3] + workers: 1 in CI, you get 3 runners in parallel, each running its third sequentially. Total parallelism = shards × workers = 3 × 1 = 3.
  //
  //   If you could also bump workers to e.g. 2 in CI to get 6× total parallelism — but that workers: 1 was likely chosen to avoid flakiness from parallel tests sharing backend state (fixtures, DB rows, auth sessions).
  //    Sharding sidesteps that concern because each shard gets its own container stack (your docker compose … up --wait runs per-shard). So raising workers inside a shard is only safe if your tests don't mutate shared state
  //   within a single API instance.
  workers: playwrightWorkers(),
  // CI shards emit `blob` reports so a downstream job can merge them into one HTML.
  // Locally, write the HTML report directly.
  reporter: process.env.CI
    ? [["blob", { outputDir: "blob-report" }]]
    : [["html", { outputFolder: "reports/playwright/html-report" }]],
  use: {
    baseURL: playwrightBaseURL,
    trace: "on-first-retry",
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: "chromium",
      use: {
        ...devices["Desktop Chrome"],
        // Browser fetch() to https://localhost uses the stack’s dev certificate; Playwright’s
        // ignoreHTTPSErrors only applies to navigation, not in-page fetch.
        launchOptions: {
          args: ["--ignore-certificate-errors"],
        },
      },
    },
  ],
  ...(useWebServer
    ? {
        webServer: {
          command: "npm run dev:e2e",
          cwd: pwaDir,
          url: playwrightBaseURL,
          reuseExistingServer: !process.env.CI,
          env: {
            ...process.env,
            // Next dev for E2E must call the real API. These override pwa/.env.local (e.g. :8000).
            // Set PLAYWRIGHT_SYMFONY_* only if your stack differs.
            SYMFONY_INTERNAL_URL:
              process.env.PLAYWRIGHT_SYMFONY_INTERNAL_URL ?? "https://localhost",
            NEXT_PUBLIC_SYMFONY_API_BASE_URL:
              process.env.PLAYWRIGHT_SYMFONY_API_BASE_URL ?? "https://localhost",
          },
        },
      }
    : {}),
});
