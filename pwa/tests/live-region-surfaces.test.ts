import { readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import ts from "typescript";
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
 * The tree is read with the TypeScript compiler's AST, not a regex over the source text: an
 * `<output` spelled inside a comment or a string is not a rendered element, and a comment is
 * trivia attached to a token rather than a node the walk visits, so it never competes with a
 * live one for the match. The walk also reaches a **dynamically** created element —
 * `createElement("output", …)` — which no text-based scan sees at all, and resolves that call
 * through the file's own `import … from "react"` (a named import under any alias, or a
 * namespace/default import used as `X.createElement(...)`), not just the two literal spellings
 * `createElement`/`React.createElement`. A tag whose name is itself a variable
 * (`const Tag = "output"; <Tag />`) stays out of scope: that is not a defect literate analysis
 * fails to close, it is the boundary of what a static read of the source can ever answer.
 *
 * A green proves no unregistered file spells `<output>`, statically or via `createElement`. It
 * proves nothing about the OTHER spellings of a live region (`role="status"`, `role="alert"`,
 * `aria-live`), which are explicit, greppable and self-documenting — the trap closed here is
 * the one that is neither.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".tsx", ".jsx"]);
const OUTPUT_TAG = "output";
const REACT_MODULE_SPECIFIER = "react";

// Path relative to `src/` → why this surface is genuinely a live region.
const LIVE_REGION_SURFACES: Readonly<Record<string, string>> = {
  "app/backoffice/BackOfficeLayoutClient.tsx":
    "the sign-out window: both menus close over the relabelled entry, so this region is the " +
    "only signal that survives the click",
  "app/backoffice/bank-accounts/_components/BankAccountIbanLookup.tsx":
    "the result of a user action — a well-formed IBAN with no matching account, replacing " +
    "the just-submitted search, distinct from the mutation-error surface reserved for a " +
    "genuine failure",
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

function isOutputJsxElement(node: ts.Node): boolean {
  if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
    return node.tagName.getText() === OUTPUT_TAG;
  }
  return false;
}

/** Local names bound to React's `createElement`, resolved from the file's own imports. */
interface CreateElementBindings {
  /** Called directly: `h("output", …)`. */
  readonly direct: ReadonlySet<string>;
  /** Called through a namespace/default import: `React.createElement("output", …)`. */
  readonly namespaces: ReadonlySet<string>;
}

/**
 * `createElement`/`React` are seeded unconditionally so a file reaching either name without
 * importing it (a global JSX runtime shim, a test fixture) still matches, exactly as the two
 * literal spellings this replaces did — every real `import … from "react"` only adds aliases.
 */
function createElementBindings(sourceFile: ts.SourceFile): CreateElementBindings {
  const direct = new Set<string>(["createElement"]);
  const namespaces = new Set<string>(["React"]);
  for (const statement of sourceFile.statements) {
    if (!ts.isImportDeclaration(statement)) continue;
    if (!ts.isStringLiteral(statement.moduleSpecifier)) continue;
    if (statement.moduleSpecifier.text !== REACT_MODULE_SPECIFIER) continue;
    const clause = statement.importClause;
    if (!clause) continue;
    if (clause.name) namespaces.add(clause.name.text); // `import React from "react"`
    const bindings = clause.namedBindings;
    if (bindings && ts.isNamespaceImport(bindings)) namespaces.add(bindings.name.text);
    if (bindings && ts.isNamedImports(bindings)) {
      for (const element of bindings.elements) {
        if ((element.propertyName ?? element.name).text === "createElement") {
          direct.add(element.name.text);
        }
      }
    }
  }
  return { direct, namespaces };
}

function isOutputCreateElementCall(node: ts.Node, bindings: CreateElementBindings): boolean {
  if (!ts.isCallExpression(node)) return false;
  const callee = node.expression;
  const isDirectCall = ts.isIdentifier(callee) && bindings.direct.has(callee.text);
  const isNamespacedCall =
    ts.isPropertyAccessExpression(callee) &&
    callee.name.text === "createElement" &&
    ts.isIdentifier(callee.expression) &&
    bindings.namespaces.has(callee.expression.text);
  if (!isDirectCall && !isNamespacedCall) return false;
  const [tagArgument] = node.arguments;
  return !!tagArgument && ts.isStringLiteralLike(tagArgument) && tagArgument.text === OUTPUT_TAG;
}

/** Whether `source` renders an `<output>` — as static JSX or via a `createElement` call. */
function rendersOutputInSource(fileName: string, source: string): boolean {
  const sourceFile = ts.createSourceFile(
    fileName,
    source,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  );
  const bindings = createElementBindings(sourceFile);

  let found = false;
  const visit = (node: ts.Node): void => {
    if (found) return;
    if (isOutputJsxElement(node) || isOutputCreateElementCall(node, bindings)) {
      found = true;
      return;
    }
    ts.forEachChild(node, visit);
  };
  visit(sourceFile);
  return found;
}

function rendersOutputElement(file: string): boolean {
  return rendersOutputInSource(file, readFileSync(file, "utf8"));
}

function filesRenderingOutput(): string[] {
  return [...walk(SRC_ROOT)]
    .filter((file) => rendersOutputElement(file))
    .map((file) => path.relative(SRC_ROOT, file).replaceAll(path.sep, "/"))
    .sort();
}

describe("output detection reads the AST, not the source text", () => {
  it("finds a real <output> even past a regex literal an odd-quote text scanner would misread", () => {
    // A text scanner that tracks quotes with a single flag treats the apostrophe inside this
    // regex literal as opening a string, then silently drops everything after it — including a
    // genuine <output> — until it happens to find another apostrophe to "close" it with.
    const source = `
      const CANT_MATCH_RE = /don't match/;
      export function Widget() {
        return <output data-testid="widget__status">hi</output>;
      }
    `;
    expect(rendersOutputInSource("fixture.tsx", source)).toBe(true);
  });

  it("finds an <output> created via createElement, which no text-based scan can see", () => {
    const source = `
      import { createElement } from "react";
      export function Widget() {
        return createElement("output", { "data-testid": "widget__status" }, "hi");
      }
    `;
    expect(rendersOutputInSource("fixture.tsx", source)).toBe(true);
  });

  it("does not flag createElement calls building a different tag", () => {
    const source = `
      import { createElement } from "react";
      export function Widget() {
        return createElement("div", null, "hi");
      }
    `;
    expect(rendersOutputInSource("fixture.tsx", source)).toBe(false);
  });

  it("follows a renamed named import of createElement", () => {
    const source = `
      import { createElement as h } from "react";
      export function Widget() {
        return h("output", { "data-testid": "widget__status" }, "hi");
      }
    `;
    expect(rendersOutputInSource("fixture.tsx", source)).toBe(true);
  });

  it("follows an aliased namespace import used as X.createElement", () => {
    const source = `
      import * as R from "react";
      export function Widget() {
        return R.createElement("output", { "data-testid": "widget__status" }, "hi");
      }
    `;
    expect(rendersOutputInSource("fixture.tsx", source)).toBe(true);
  });

  it("follows a default import of React used as React.createElement", () => {
    const source = `
      import React from "react";
      export function Widget() {
        return React.createElement("output", { "data-testid": "widget__status" }, "hi");
      }
    `;
    expect(rendersOutputInSource("fixture.tsx", source)).toBe(true);
  });

  it("still flags a same-named createElement imported from an unrelated module — over-matching is the safe direction here", () => {
    // `createElement`/`React` are seeded unconditionally (see createElementBindings), so a
    // same-named import from any other module is a false positive this walk accepts rather
    // than resolves — a missed live region is the dangerous direction, an extra flag is not.
    const source = `
      import { createElement } from "some-other-library";
      export function Widget() {
        return createElement("output", { "data-testid": "widget__status" }, "hi");
      }
    `;
    expect(rendersOutputInSource("fixture.tsx", source)).toBe(true);
  });
});

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
