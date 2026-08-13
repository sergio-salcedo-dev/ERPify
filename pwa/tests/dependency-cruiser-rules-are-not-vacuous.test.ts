import { readdirSync, statSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

import config from "../.dependency-cruiser.cjs";

/**
 * Every rule in `.dependency-cruiser.cjs` must have at least one module on its
 * `from:` side.
 *
 * Why: a `from.path` that matches nothing cannot fire. The rule keeps printing
 * green, indistinguishable from a rule that ran and found nothing — which is the
 * exact failure mode the graph gate exists to close, one level up.
 * `erpify-barrel-excludes-error-screens` is anchored on a single file
 * (`src/components/erpify/index.ts`), so dissolving that barrel would retire its
 * rule in silence; the other scoped rules select directories and are only one
 * careless `pathNot` away from the same fate.
 *
 * This mirrors the `assertNotEmpty` self-protection every gate under
 * `api/tests/Unit/Shared/Architecture/` carries, and it generalises: a rule added
 * later with a typo'd path fails here rather than shipping as decoration.
 *
 * `from.path` may be a single pattern or an array of them, and `from.pathNot`
 * subtracts from the selection; all of it is applied, because a guard against
 * vacuity that quietly skips a shape carries the very blind spot it exists to
 * close. A `from` key this file does not understand FAILS rather than being
 * skipped — silent skipping is how the population being checked shrinks to
 * nothing without anyone noticing.
 *
 * What a green proves: each rule's `from:` selects real modules. What it does NOT
 * prove: that the rule's `to:` is right, or that the rule can actually go red
 * (provoking that is a human step, recorded per rule in the story artifact).
 * `no-circular` and `no-unresolvable` state a bare `from: {}` on purpose — they
 * are unscoped by design and have no pattern to check.
 */

const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx"]);

function sourceModules(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const absolute = path.join(directory, entry);

    if (statSync(absolute).isDirectory()) {
      return sourceModules(absolute);
    }

    if (!SOURCE_EXTENSIONS.has(path.extname(entry))) {
      return [];
    }

    // dependency-cruiser reports module paths relative to pwa/, e.g. "src/components/cn.ts".
    return [path.relative(PWA_ROOT, absolute)];
  });
}

const UNDERSTOOD_FROM_KEYS = new Set(["path", "pathNot"]);

function asPatterns(value: string | string[] | undefined): string[] {
  if (value === undefined) {
    return [];
  }

  return Array.isArray(value) ? value : [value];
}

const rules = (config.forbidden ?? []).map((rule) => {
  // Read as a bag of keys on purpose: the point is to notice a `from` shape this
  // file does not model, which a narrowed type would hide rather than surface.
  const from = rule.from as Record<string, unknown>;

  return {
    label: rule.name ?? "(unnamed rule)",
    unknownKeys: Object.keys(from).filter((key) => !UNDERSTOOD_FROM_KEYS.has(key)),
    include: asPatterns(from.path as string | string[] | undefined),
    exclude: asPatterns(from.pathNot as string | string[] | undefined),
  };
});

// A rule with a bare `from: {}` is unscoped on purpose and has nothing to select.
const scopedRules = rules.filter(
  (rule) => rule.include.length > 0 || rule.exclude.length > 0 || rule.unknownKeys.length > 0,
);

describe("dependency-cruiser rules", () => {
  const modules = sourceModules(SRC_ROOT);

  it("walks a non-empty source tree", () => {
    // Without this the whole file passes vacuously — the very thing it tests for.
    expect(modules.length).toBeGreaterThan(0);
  });

  it("finds rules with a scoped from: to check", () => {
    expect(scopedRules.length).toBeGreaterThan(0);
  });

  it.each(scopedRules)("$label selects at least one module", (rule) => {
    // An unrecognised `from` key would silently narrow the selection behind this
    // check's back, so it fails here instead of being skipped.
    expect(rule.unknownKeys).toStrictEqual([]);

    const included = (module: string) =>
      rule.include.length === 0 || rule.include.some((pattern) => new RegExp(pattern).test(module));
    const excluded = (module: string) =>
      rule.exclude.some((pattern) => new RegExp(pattern).test(module));

    expect(modules.filter((module) => included(module) && !excluded(module))).not.toHaveLength(0);
  });
});
