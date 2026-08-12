// dependency-cruiser — graph-shaped boundary gate for pwa/src.
//
// Run via `make pwa.lint.graph`; wired into `pwa.quality` and `pwa.quality.dry-run`.
//
// ROLE SPLIT WITH ESLINT — the two gates are siblings, not duplicates, and neither
// is a subset of the other. Retiring either one loses cases:
//
//   - `no-restricted-imports` (eslint.config.mjs) matches an import SPECIFIER, one
//     file at a time. It is what puts the red squiggle under the offending line in
//     the editor, before anything is run. That inline signal is the whole reason it
//     stays.
//   - This ruleset walks the resolved MODULE GRAPH. Three shapes are structurally
//     inexpressible as a specifier match, and all three have bitten this tree:
//       1. Facades / transitivity. `ui -> @/components/cn -> @/context/**` is green
//          in every ESLint block (measured: eslint exits 0 on exactly that tree),
//          which is why #349 had to MOVE `cn` rather than leave a facade behind.
//          The `reachable: true` rules below are the half that sees it.
//       2. Relative escapes. The ESLint groups are `@/`-alias patterns, so a
//          `../../context/shared/x` matches nothing. (Measured: zero today.)
//       3. Barrel re-exports. `components/erpify/index.ts` is invisible to a
//          specifier match; pwa/CLAUDE.md forbids re-exporting the error UI through
//          it in prose, and nothing verified that prose until this rule.
//
// TWO OPTIONS BELOW ARE LOAD-BEARING, not defaults worth tidying away:
//
//   - `tsPreCompilationDeps: true` — 7 of the 17 `erpify -> context/shared` edges
//     are `import type`. With the default `false` this gate would see 10 of 17 and
//     be WEAKER than the ESLint it reinforces (neither ESLint block sets
//     `allowTypeImports`, so both see type imports today).
//   - `enhancedResolveOptions.exportsFields` — dependency-cruiser ships
//     `exportsFields: []` to stay compatible with enhanced-resolve 4. Under that
//     default every ESM-only package publishing `exports` and no `main` is
//     unresolvable: `inversify` (54 edges) and `uuid` (2) go dark, and
//     `no-unresolvable` is born red with 56 violations. Honouring the field is what
//     lets that rule exist at all.
//
// The `@/` alias is resolved from `options.tsConfig` — pwa/tsconfig.json's `paths`,
// read as-is. It is deliberately NOT restated here: a third declaration of `@/`
// (alongside tsconfig.json and vitest.config.ts) would drift from the other two in
// silence. `no-unresolvable` is what turns that failure mode from silent to loud,
// so it is not optional.
//
// No baseline, no cache, no `recommended` preset, and no exception lines: every rule
// below is stated so the legitimate edges pass on their own shape. If a rule ever
// needs an allowlist, the rule is written wrong.

/** @type {import('dependency-cruiser').IConfiguration} */
module.exports = {
  forbidden: [
    {
      name: "ui-is-foundational",
      comment:
        "components/ui is the foundational vendor layer: it must not REACH the DDD context tree, app/, or the design system — directly or through any number of hops. Its one allowed helper is @/components/cn.",
      severity: "error",
      from: { path: "^src/components/ui/" },
      to: {
        path: ["^src/context/", "^src/app/", "^src/components/erpify/"],
        reachable: true,
      },
    },
    {
      name: "components-root-is-foundational",
      comment:
        "Files at the root of src/components/ (today: cn.ts) sit below the context tree and must not reach up into it. No ESLint block covers this path — it is the hole this gate exists to close.",
      severity: "error",
      from: { path: "^src/components/[^/]+\\.tsx?$" },
      to: {
        path: ["^src/context/", "^src/app/", "^src/components/erpify/"],
        reachable: true,
      },
    },
    {
      name: "erpify-not-bounded-context",
      comment:
        "components/erpify is a business-agnostic design system. Stated in the negative — it may build on context/shared (17 edges today, all legitimate); what it may never reach is a bounded context or app/.",
      severity: "error",
      from: { path: "^src/components/erpify/" },
      to: {
        path: ["^src/context/backoffice/", "^src/context/frontoffice/", "^src/app/"],
        reachable: true,
      },
    },
    {
      name: "erpify-barrel-excludes-error-screens",
      comment:
        "The error SCREENS are exported from @/context/shared/error/infrastructure/ui and must keep that boundary explicit; only the reusable error PRIMITIVES belong to the design system. pwa/CLAUDE.md states this in prose — this rule is what checks it.",
      severity: "error",
      from: { path: "^src/components/erpify/index\\.ts$" },
      to: { path: "^src/context/shared/error/infrastructure/ui" },
    },
    {
      name: "no-circular",
      comment:
        "A dependency cycle makes module init order load-bearing and defeats every layering rule above it.",
      severity: "error",
      from: {},
      to: { circular: true },
    },
    {
      name: "no-unresolvable",
      comment:
        "An unresolvable specifier is invisible to every other rule here: the edge is simply absent from the graph, so a boundary crossing hidden behind it passes green. This is the rule that makes a broken alias or resolver config loud instead of silent.",
      severity: "error",
      from: {},
      to: { couldNotResolve: true },
    },
  ],
  options: {
    doNotFollow: { path: "node_modules" },
    tsPreCompilationDeps: true,
    tsConfig: { fileName: "tsconfig.json" },
    enhancedResolveOptions: {
      exportsFields: ["exports"],
      conditionNames: ["import", "require", "node", "default", "types"],
    },
  },
};
