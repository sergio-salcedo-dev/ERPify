import { readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

/**
 * `motion` (framer's motion/react) was removed from the PWA on 2026-06-01:
 * every animation it powered was re-expressed with `tw-animate-css` utilities
 * (already in the bundle) or plain Tailwind / CSS. This guard keeps it gone —
 * re-adding the dependency or importing `motion` / `motion/react` from `src/`
 * fails CI, the same way the `data-testid-uniqueness` and `proxy` guards lock
 * in their invariants. See docs/superpowers/specs/2026-06-01-drop-motion-animations-design.md.
 */

const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx"]);
// Matches `from "motion"` and `from "motion/react"`, but not `framer-motion`,
// `motion-dom`, or `motion-utils` (the char after `motion` must be `/` or the quote).
const MOTION_IMPORT_RE = /from\s+["']motion(?:\/[^"']*)?["']/;

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

describe("motion dependency removal", () => {
  it("no source file imports from `motion`", () => {
    const offenders: string[] = [];
    for (const file of walk(SRC_ROOT)) {
      if (MOTION_IMPORT_RE.test(readFileSync(file, "utf8"))) {
        offenders.push(path.relative(PWA_ROOT, file));
      }
    }
    expect(
      offenders,
      offenders.length > 0
        ? "These files still import `motion`; re-express the animation with " +
            `tw-animate-css / CSS instead:\n${offenders.map((f) => "  " + f).join("\n")}`
        : "",
    ).toEqual([]);
  });

  it("`motion` is not a declared dependency", () => {
    const pkg = JSON.parse(readFileSync(path.join(PWA_ROOT, "package.json"), "utf8")) as {
      dependencies?: Record<string, string>;
      devDependencies?: Record<string, string>;
    };
    expect(pkg.dependencies ?? {}).not.toHaveProperty("motion");
    expect(pkg.devDependencies ?? {}).not.toHaveProperty("motion");
  });
});
