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
 * exact failure mode the graph gate exists to close, one level up. Two of the
 * rules are anchored on a single file today (`src/components/cn.ts` and
 * `src/components/erpify/index.ts`), so renaming or dissolving either one would
 * retire its rule in silence.
 *
 * This mirrors the `assertNotEmpty` self-protection every gate under
 * `api/tests/Unit/Shared/Architecture/` carries, and it generalises: a rule added
 * later with a typo'd path fails here rather than shipping as decoration.
 *
 * `from.path` may be a single pattern or an array of them; both are covered,
 * because skipping the array form would reintroduce the same blind spot inside
 * the guard against it.
 *
 * What a green proves: each rule's `from:` selects real modules. What it does NOT
 * prove: that the rule's `to:` is right, that the rule can actually go red
 * (provoking that is a human step, recorded per rule in the story artifact), or
 * anything about rules whose `from:` is deliberately unscoped — `no-circular` and
 * `no-unresolvable` state `from: {}` on purpose and have no pattern to check.
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

const scopedRules = (config.forbidden ?? []).flatMap((rule) => {
  const pattern = "path" in rule.from ? rule.from.path : undefined;

  if (pattern === undefined) {
    return [];
  }

  const patterns = Array.isArray(pattern) ? pattern : [pattern];

  return patterns.map((expression, index) => ({
    label: `${rule.name ?? "(unnamed rule)"}${patterns.length > 1 ? ` [${index}]` : ""}`,
    expression,
  }));
});

describe("dependency-cruiser rules", () => {
  const modules = sourceModules(SRC_ROOT);

  it("walks a non-empty source tree", () => {
    // Without this the whole file passes vacuously — the very thing it tests for.
    expect(modules.length).toBeGreaterThan(0);
  });

  it("finds rules with a scoped from: to check", () => {
    expect(scopedRules.length).toBeGreaterThan(0);
  });

  it.each(scopedRules)("$label selects at least one module", ({ expression }) => {
    const matcher = new RegExp(expression);

    expect(modules.filter((module) => matcher.test(module))).not.toHaveLength(0);
  });
});
