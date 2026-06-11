import { describe, it, expect } from "vitest";
import * as fs from "node:fs";
import * as path from "node:path";
import * as SentryStub from "./sentryNextjs";

/**
 * Parity guard for the Sentry stub (Issue #206).
 *
 * This test ensures that all runtime symbols from `@sentry/nextjs` used in the
 * source code are also exported by the `sentryNextjs.ts` stub. This prevents
 * runtime `undefined` errors in unit tests when new Sentry features are used.
 */
describe("Sentry Stub Parity", () => {
  const rootDir = path.resolve(__dirname, "../../");
  const stubExports = Object.keys(SentryStub);

  // We only check these directories/files for Sentry usages.
  const searchPaths = [
    path.join(rootDir, "src"),
    path.join(rootDir, "instrumentation.ts"),
    path.join(rootDir, "instrumentation-client.ts"),
    path.join(rootDir, "sentry.edge.config.ts"),
    path.join(rootDir, "sentry.server.config.ts"),
    path.join(rootDir, "next.config.ts"),
  ];

  const getFiles = (dir: string): string[] => {
    if (!fs.existsSync(dir)) return [];
    if (fs.statSync(dir).isFile()) return [dir];

    const files: string[] = [];
    const entries = fs.readdirSync(dir, { withFileTypes: true });

    for (const entry of entries) {
      const fullPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        files.push(...getFiles(fullPath));
      } else if (/\.(ts|tsx|js|jsx)$/.test(entry.name)) {
        files.push(fullPath);
      }
    }
    return files;
  };

  const usedSymbols = new Set<string>();

  for (const searchPath of searchPaths) {
    const files = getFiles(searchPath);

    for (const file of files) {
      if (
        file.endsWith(".test.ts") ||
        file.endsWith(".test.tsx") ||
        file.endsWith("sentryNextjs.ts")
      ) {
        continue;
      }

      const content = fs.readFileSync(file, "utf8");

      // 1. Find namespace imports: import * as Sentry from "@sentry/nextjs"
      const namespaceMatch = content.match(
        /import\s+\*\s+as\s+(\w+)\s+from\s+['"]@sentry\/nextjs['"]/,
      );
      if (namespaceMatch) {
        const alias = namespaceMatch[1];
        const memberRegex = new RegExp(`${alias}\\.(\\w+)`, "g");
        let match;
        while ((match = memberRegex.exec(content)) !== null) {
          usedSymbols.add(match[1]);
        }
      }

      // 2. Find named imports: import { init, captureException } from "@sentry/nextjs"
      // Note: we ignore "import type"
      const namedImportRegex = /import\s+\{([^}]+)\}\s+from\s+['"]@sentry\/nextjs['"]/g;
      let namedMatch;
      while ((namedMatch = namedImportRegex.exec(content)) !== null) {
        // Skip "import type"
        if (namedMatch[0].includes("import type")) continue;

        const members = namedMatch[1].split(",");
        for (const member of members) {
          const trimmed = member.trim().split(/\s+as\s+/)[0]; // Handle "foo as bar"
          if (trimmed) {
            usedSymbols.add(trimmed);
          }
        }
      }
    }
  }

  // Common types that might be imported without "type" keyword but aren't runtime symbols.
  // We can expand this list if needed, or just add them to the stub as no-ops.
  const ignoreList = new Set(["Event", "ErrorEvent", "User", "Scope"]);

  it("should have all used Sentry symbols in the stub", () => {
    const missing = Array.from(usedSymbols).filter(
      (symbol) => !stubExports.includes(symbol) && !ignoreList.has(symbol),
    );

    expect(
      missing,
      `The following Sentry symbols are used in the source code but missing from the stub: ${missing.join(", ")}`,
    ).toHaveLength(0);
  });
});
