import { readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

/**
 * Static `data-testid` literals must be unique across the source tree.
 *
 * Why: QA scripts target controls by `data-testid`. Two source files
 * declaring the same static testid eventually causes both elements to
 * render on the same page, and Playwright's strict-mode locators throw
 * "more than one element matched" — wrecking the test suite at the worst
 * possible time. We catch the regression at build time instead.
 *
 * Scope:
 * - Only **static** literal values are checked (e.g.
 *   `data-testid="banks-list__title"`). Dynamic / templated values
 *   (`data-testid={\`banks-table__edit-${row.id}\`}`) are unique by
 *   construction and are excluded.
 * - We allow legitimate per-row-template duplication when the literal
 *   appears in test fixtures (e.g. e2e specs) AND in source — but the
 *   uniqueness rule only walks `src/`, so test code is naturally
 *   excluded.
 * - If a reusable component must own the testid, design it to accept a
 *   `testId` prop (see `<DataTable>`, `<CopyButton>`, `<DateField>`).
 */

const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx"]);
const STATIC_TESTID_RE = /data-testid\s*=\s*"([^"]+)"/g;

function* walk(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry);
    const s = statSync(full);
    if (s.isDirectory()) {
      yield* walk(full);
    } else if (s.isFile() && SOURCE_EXTENSIONS.has(path.extname(full))) {
      yield full;
    }
  }
}

interface Hit {
  value: string;
  file: string;
}

describe("data-testid uniqueness", () => {
  it("no static `data-testid` literal appears in more than one source file", () => {
    const occurrences = new Map<string, Hit[]>();
    for (const file of walk(SRC_ROOT)) {
      const content = readFileSync(file, "utf8");
      let match: RegExpExecArray | null;
      while ((match = STATIC_TESTID_RE.exec(content)) !== null) {
        const value = match[1];
        if (!value) continue;
        const list = occurrences.get(value) ?? [];
        list.push({ value, file: path.relative(PWA_ROOT, file) });
        occurrences.set(value, list);
      }
    }

    const duplicates = [...occurrences.entries()]
      .filter(([, hits]) => new Set(hits.map((h) => h.file)).size > 1)
      .map(([value, hits]) => ({
        value,
        files: [...new Set(hits.map((h) => h.file))].sort((a, b) => a.localeCompare(b)),
      }));

    expect(
      duplicates,
      duplicates.length > 0
        ? `Duplicate data-testid literals found:\n${duplicates
            .map((d) => `  ${d.value} → ${d.files.join(", ")}`)
            .join("\n")}\n` +
            "Either rename one to keep them unique, or have the reusable " +
            "component accept a `testId` prop and let the consumer set it."
        : "",
    ).toEqual([]);
  });

  it("no static `data-testid` literal appears more than once in the same source file", () => {
    const offenders: Array<{ file: string; value: string; count: number }> = [];
    for (const file of walk(SRC_ROOT)) {
      const content = readFileSync(file, "utf8");
      const counts = new Map<string, number>();
      let match: RegExpExecArray | null;
      while ((match = STATIC_TESTID_RE.exec(content)) !== null) {
        const value = match[1];
        if (!value) continue;
        counts.set(value, (counts.get(value) ?? 0) + 1);
      }
      for (const [value, count] of counts) {
        if (count > 1) {
          offenders.push({ file: path.relative(PWA_ROOT, file), value, count });
        }
      }
    }
    expect(offenders).toEqual([]);
  });
});
