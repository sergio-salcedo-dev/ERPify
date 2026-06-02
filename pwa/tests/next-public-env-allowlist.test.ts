import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

/**
 * Hard guardrail: only allowlisted `NEXT_PUBLIC_*` env vars may reach the
 * browser bundle.
 *
 * Why this matters: Next.js inlines every `process.env.NEXT_PUBLIC_FOO`
 * literal into the JavaScript it ships to the browser, at `next build` time,
 * replacing it with `NEXT_PUBLIC_FOO`'s build-time value. So **any** value
 * placed behind a `NEXT_PUBLIC_`-prefixed name is public by construction —
 * shipped to, and readable by, every visitor. `.dockerignore` and httpOnly
 * cookies cannot protect against this: the leak happens at compile time, in
 * the bundle, not at runtime. The only durable defence is to never let a
 * non-public name acquire the prefix — which a written convention alone can't
 * enforce. This test is that enforcement.
 *
 * The allowlist is the single source of truth. To add a genuinely-public var:
 *   1. add it to {@link ALLOWED_PUBLIC_ENV_VARS} below,
 *   2. document it in `pwa/CLAUDE.md`'s `NEXT_PUBLIC_` table, and
 *   3. confirm in review that it carries no secret / credential / PII.
 *
 * Surfaces scanned — all owned by `pwa/`, and together sufficient to cover the
 * leak vector:
 *   - `src/**`       The only code Next.js bundles. A `process.env.NEXT_PUBLIC_X`
 *                    literal here is what actually gets inlined into the browser
 *                    bundle — the leak surface itself.
 *   - `Dockerfile`   The build gateway. A Docker/Compose build-arg is inert
 *                    unless the builder stage declares a matching `ARG`, so this
 *                    is where a value-bearing public var must be declared to take
 *                    effect. (Compose passes args *into* these ARGs; an arg with
 *                    no `ARG` here is ignored by the build, so guarding the
 *                    Dockerfile closes the declaration half.)
 *   - `.env.example` The documented public env contract contributors copy from.
 *
 * Test files are deliberately NOT scanned: they never ship to the browser, and
 * this file intentionally contains a forbidden name in its synthetic fixtures
 * below to prove the detector works.
 */

const ALLOWED_PUBLIC_ENV_VARS = new Set<string>([
  "NEXT_PUBLIC_API_BASE_URL",
  "NEXT_PUBLIC_APP_ENV",
]);

const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx", ".mjs", ".cjs"]);

// A `NEXT_PUBLIC_<NAME>` token that is NOT preceded by an identifier char, so
// unrelated names that merely embed the prefix (e.g. the Playwright-only
// `PLAYWRIGHT_NEXT_PUBLIC_API_BASE_URL` override) are not mistaken for a public
// var — those don't start with the prefix and Next.js never inlines them.
const PUBLIC_ENV_RE = /(?<!\w)NEXT_PUBLIC_[A-Z0-9_]+/g;

/** Every `NEXT_PUBLIC_*` var name referenced in a blob of text. */
function publicEnvNamesIn(content: string): string[] {
  return content.match(PUBLIC_ENV_RE) ?? [];
}

interface Violation {
  name: string;
  file: string;
}

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

/** Files Next.js can inline a `NEXT_PUBLIC_*` value from, plus its declaration surfaces. */
function bundleFacingFiles(): string[] {
  const files = [...walk(SRC_ROOT)];
  for (const extra of [path.join(PWA_ROOT, "Dockerfile"), path.join(PWA_ROOT, ".env.example")]) {
    // Loud-on-drift: these are core pwa files. If one is renamed/removed, the
    // guard should fail and prompt a conscious update rather than silently
    // shrink its coverage.
    expect(existsSync(extra), `${path.relative(PWA_ROOT, extra)} is expected to exist`).toBe(true);
    files.push(extra);
  }
  return files;
}

function scan(files: string[]): Violation[] {
  const violations: Violation[] = [];
  for (const file of files) {
    for (const name of publicEnvNamesIn(readFileSync(file, "utf8"))) {
      if (!ALLOWED_PUBLIC_ENV_VARS.has(name)) {
        violations.push({ name, file: path.relative(PWA_ROOT, file) });
      }
    }
  }
  return violations;
}

describe("NEXT_PUBLIC_* env allowlist", () => {
  it("no disallowed NEXT_PUBLIC_ var reaches the browser bundle", () => {
    const violations = scan(bundleFacingFiles());
    expect(
      violations,
      violations.length > 0
        ? `Disallowed NEXT_PUBLIC_ env var(s) found — these would be inlined into ` +
            `the client bundle and shipped to every visitor:\n${violations
              .map((v) => `  ${v.name} → ${v.file}`)
              .join("\n")}\n` +
            "If the value is genuinely public, add it to ALLOWED_PUBLIC_ENV_VARS " +
            "in this file AND to pwa/CLAUDE.md's NEXT_PUBLIC_ table. If it carries " +
            "a secret/credential/PII, rename it WITHOUT the NEXT_PUBLIC_ prefix and " +
            "read it server-side only (SSR / route handler)."
        : "",
    ).toEqual([]);
  });

  describe("detector (proves the guard catches violations)", () => {
    it("flags a NEXT_PUBLIC_ name outside the allowlist", () => {
      expect(scan.length).toBeGreaterThan(0); // sanity: helper is wired
      const offenders = publicEnvNamesIn(
        'const key = process.env.NEXT_PUBLIC_API_KEY ?? "";',
      ).filter((n) => !ALLOWED_PUBLIC_ENV_VARS.has(n));
      expect(offenders).toEqual(["NEXT_PUBLIC_API_KEY"]);
    });

    it("passes the allowlisted names", () => {
      const names = publicEnvNamesIn(
        "process.env.NEXT_PUBLIC_API_BASE_URL; process.env.NEXT_PUBLIC_APP_ENV;",
      );
      expect(names.every((n) => ALLOWED_PUBLIC_ENV_VARS.has(n))).toBe(true);
    });

    it("ignores names that merely embed the prefix (e.g. PLAYWRIGHT_NEXT_PUBLIC_*)", () => {
      expect(publicEnvNamesIn("PLAYWRIGHT_NEXT_PUBLIC_API_BASE_URL=https://localhost")).toEqual([
        // the embedded substring must NOT be treated as a public var
      ]);
    });

    it("ignores the documentation `NEXT_PUBLIC_*` glob token", () => {
      expect(publicEnvNamesIn("a `NEXT_PUBLIC_*` var would be bundled into the client")).toEqual(
        [],
      );
    });
  });

  it("allowlist contains exactly the two known-public vars (changing it is a conscious edit)", () => {
    expect([...ALLOWED_PUBLIC_ENV_VARS].sort((a, b) => a.localeCompare(b))).toEqual([
      "NEXT_PUBLIC_API_BASE_URL",
      "NEXT_PUBLIC_APP_ENV",
    ]);
  });
});
