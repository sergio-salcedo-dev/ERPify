import { afterEach, describe, expect, it, vi } from "vitest";
import nextConfig from "../next.config";

/**
 * Locks the Referrer-Policy contract of `next.config.ts#headers()`: the global
 * catch-all keeps `strict-origin-when-cross-origin`, while every screen whose
 * URL is itself sensitive suppresses the Referer entirely — the token-bearing
 * ones (`?token=<id>.<secret>`) and the audit screen, whose query string names
 * the people under investigation. In Next.js, when several sources match a
 * request, the LAST value set for a header key wins — so the per-route entries
 * must come after the catch-all.
 *
 * The audit screen is pinned at BOTH its exact source and its subtree: an exact
 * source matches only itself, so a future `/backoffice/audit/<id>` would fall
 * back to the catch-all without a single test noticing.
 */

const GLOBAL_SOURCE = "/(.*)";
const SENSITIVE_URL_SOURCES = [
  "/accept-invitation",
  "/reset-password",
  "/backoffice/audit",
  "/backoffice/audit/:path*",
];

async function headerEntries() {
  if (!nextConfig.headers) throw new Error("next.config.ts must declare headers()");
  return nextConfig.headers();
}

function referrerPolicyOf(headers: readonly { key: string; value: string }[]): string | undefined {
  return headers.find((header) => header.key === "Referrer-Policy")?.value;
}

describe("next.config headers — Referrer-Policy", () => {
  it("keeps the global catch-all on strict-origin-when-cross-origin", async () => {
    const entries = await headerEntries();
    const global = entries.find((entry) => entry.source === GLOBAL_SOURCE);

    expect(global).toBeDefined();
    expect(referrerPolicyOf(global?.headers ?? [])).toBe("strict-origin-when-cross-origin");
  });

  it.each(SENSITIVE_URL_SOURCES)("suppresses the Referer entirely on %s", async (source) => {
    const entries = await headerEntries();
    const entry = entries.find((candidate) => candidate.source === source);

    expect(entry).toBeDefined();
    expect(referrerPolicyOf(entry?.headers ?? [])).toBe("no-referrer");
  });

  it.each(SENSITIVE_URL_SOURCES)(
    "declares %s after the catch-all so its Referrer-Policy wins",
    async (source) => {
      const entries = await headerEntries();
      const globalIndex = entries.findIndex((entry) => entry.source === GLOBAL_SOURCE);
      const entryIndex = entries.findIndex((entry) => entry.source === source);

      expect(globalIndex).toBeGreaterThanOrEqual(0);
      expect(entryIndex).toBeGreaterThan(globalIndex);
    },
  );
});

function directive(csp: string, name: string): string | undefined {
  return csp
    .split(";")
    .map((part) => part.trim())
    .find((part) => part === name || part.startsWith(`${name} `));
}

async function cspFor(env: { nodeEnv: string; apiBase?: string }): Promise<string> {
  vi.stubEnv("NODE_ENV", env.nodeEnv);
  vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", env.apiBase ?? "");
  const entries = await headerEntries();
  const global = entries.find((entry) => entry.source === GLOBAL_SOURCE);
  const value = global?.headers.find((header) => header.key === "Content-Security-Policy")?.value;
  if (value === undefined) throw new Error("catch-all entry must carry a CSP");
  return value;
}

describe("next.config headers — Content-Security-Policy", () => {
  afterEach(() => vi.unstubAllEnvs());

  it("never admits a blanket https: origin in img-src", async () => {
    for (const nodeEnv of ["production", "development"]) {
      const img = directive(await cspFor({ nodeEnv }), "img-src");
      expect(img).toBe("img-src 'self' data: blob:");
    }
  });

  it("limits img-src to self plus the API origin when one is configured", async () => {
    const csp = await cspFor({ nodeEnv: "production", apiBase: "https://api.example.test/v1" });
    expect(directive(csp, "img-src")).toBe("img-src 'self' data: blob: https://api.example.test");
  });

  it("keeps 'unsafe-eval' out of production script-src", async () => {
    expect(directive(await cspFor({ nodeEnv: "production" }), "script-src")).not.toContain(
      "'unsafe-eval'",
    );
  });

  // DW-75 open item: production script-src still carries 'unsafe-inline' until the per-request
  // nonce lands in src/proxy.ts. Flip this to a plain `it` (and delete the pin below) then.
  it.fails("production script-src has no 'unsafe-inline'", async () => {
    expect(directive(await cspFor({ nodeEnv: "production" }), "script-src")).not.toContain(
      "'unsafe-inline'",
    );
  });
});
