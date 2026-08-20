import { readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

/**
 * `<output>` is a live region, and the tree may only declare one on purpose.
 *
 * HTML-AAM maps `<output>` to role `status`, so the element announces its content politely
 * whenever it changes. That is right for the result of a user action and wrong for a static
 * badge: a table of N rows spelled with `<output>` declares N polite regions that announce
 * nothing and compete with the ones that do. Both badges in this tree were spelled that way,
 * and nothing said so — `jsx-a11y` ships no rule for it, so the set wired in
 * `eslint.config.mjs` looks green over exactly this defect.
 *
 * The registry is per FILE, not per line: line numbers drift with every edit, while a file
 * arriving with its first `<output>` is the moment a decision is owed. Adding an entry means
 * stating why that surface is a live region — a reason review can refuse.
 *
 * Comments are stripped before matching: the two badges document why they are NOT `<output>`,
 * and a gate that reads its own rationale as a violation is one nobody can write the reason in.
 *
 * A green proves no unregistered file spells `<output>`. It proves nothing about the
 * OTHER spellings of a live region (`role="status"`, `role="alert"`, `aria-live`), which are
 * explicit, greppable and self-documenting — the trap closed here is the one that is neither.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".tsx", ".jsx"]);
const OUTPUT_ELEMENT_RE = /<output[\s/>]/;
const BLOCK_COMMENT_RE = /\/\*[\s\S]*?\*\//g;
const LINE_COMMENT_RE = /^[^\n]*?\/\/[^\n]*$/gm;

// Path relative to `src/` → why this surface is genuinely a live region.
const LIVE_REGION_SURFACES: Readonly<Record<string, string>> = {
  "app/backoffice/BackOfficeLayoutClient.tsx":
    "the sign-out window: both menus close over the relabelled entry, so this region is the " +
    "only signal that survives the click",
  "app/backoffice/profile/_components/ChangePasswordForm.tsx":
    "the result of a user action — the success confirmation replacing the submitted form",
};

function* walk(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry);
    const stats = statSync(full);
    if (stats.isDirectory()) {
      yield* walk(full);
    } else if (stats.isFile() && SOURCE_EXTENSIONS.has(path.extname(full))) {
      yield full;
    }
  }
}

function withoutComments(source: string): string {
  return source.replace(BLOCK_COMMENT_RE, "").replace(LINE_COMMENT_RE, "");
}

function filesRenderingOutput(): string[] {
  return [...walk(SRC_ROOT)]
    .filter((file) => OUTPUT_ELEMENT_RE.test(withoutComments(readFileSync(file, "utf8"))))
    .map((file) => path.relative(SRC_ROOT, file).replaceAll(path.sep, "/"))
    .sort();
}

describe("live-region surfaces", () => {
  it("declares `<output>` only where the registry says the surface announces", () => {
    expect(filesRenderingOutput()).toEqual(Object.keys(LIVE_REGION_SURFACES).sort());
  });

  it("keeps every registered reason non-empty — an entry is a decision, not a waiver", () => {
    const unexplained = Object.entries(LIVE_REGION_SURFACES)
      .filter(([, reason]) => reason.trim().length === 0)
      .map(([file]) => file);
    expect(unexplained).toEqual([]);
  });
});
