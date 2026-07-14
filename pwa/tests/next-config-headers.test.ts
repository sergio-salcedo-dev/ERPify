import { describe, expect, it } from "vitest";
import nextConfig from "../next.config";

/**
 * Locks the Referrer-Policy contract of `next.config.ts#headers()`: the global
 * catch-all keeps `strict-origin-when-cross-origin`, while the token-bearing
 * screens (`?token=<id>.<secret>` in the URL) suppress the Referer entirely.
 * In Next.js, when several sources match a request, the LAST value set for a
 * header key wins — so the per-route entries must come after the catch-all.
 */

const GLOBAL_SOURCE = "/(.*)";
const TOKEN_SCREEN_SOURCES = ["/accept-invitation", "/reset-password"];

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

  it.each(TOKEN_SCREEN_SOURCES)("suppresses the Referer entirely on %s", async (source) => {
    const entries = await headerEntries();
    const entry = entries.find((candidate) => candidate.source === source);

    expect(entry).toBeDefined();
    expect(referrerPolicyOf(entry?.headers ?? [])).toBe("no-referrer");
  });

  it.each(TOKEN_SCREEN_SOURCES)(
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
