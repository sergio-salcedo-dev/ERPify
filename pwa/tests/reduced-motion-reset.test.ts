// @vitest-environment node
import { readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import tailwind from "@tailwindcss/postcss";
import postcss, { type AtRule, type Container, type Declaration } from "postcss";
import ts from "typescript";
import { describe, expect, it } from "vitest";

/**
 * The reduced-motion reset in `globals.css` wins by cascade LAYER, not by `!important`.
 *
 * Tailwind 4 emits every utility — its own, tw-animate-css's and shadcn's — inside
 * `@layer`, and an unlayered declaration outranks every layered one whatever the
 * specificity. That is the whole mechanism, and it holds only while two things stay true,
 * which is what this gate pins:
 *
 * - **The reset stays unlayered.** Moved into `@layer base` it would lose to the
 *   `utilities` layer, and every `animate-*` / `transition-*` would run for a user who
 *   asked the OS for no motion (WCAG 2.3.3) — silently, since nothing renders wrong.
 * - **Nothing else outranks it.** Measured in Chrome: an inline `style` that sets a
 *   duration beats the unlayered reset, while one setting only `animationDelay` (StatCard)
 *   does not matter to it. So no inline style may set a property the reset governs, and
 *   no other unlayered declaration may set one unless it sits under a
 *   `prefers-reduced-motion` query — i.e. it states what it does under the preference.
 *
 * The CSS half reads the stylesheet as COMPILED by the same `@tailwindcss/postcss` plugin
 * Next runs, because the imports (`tailwindcss`, `tw-animate-css`, `shadcn/tailwind.css`)
 * are where most motion comes from and none of it is in the source file.
 *
 * A green proves neither a static inline `style` object literal, a `.style.<prop> =`
 * assignment nor a `.style.setProperty("<prop>", …)` call sets a governed property, and
 * that the compiled stylesheet holds no unguarded unlayered one. It proves nothing about a
 * style object reached through a variable or a spread, `React.createElement`, a CSS-in-JS
 * runtime, or a stylesheet a third-party component injects at runtime.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const GLOBALS_CSS = path.join(SRC_ROOT, "app", "globals.css");

const RESET_DECLARATIONS: Readonly<Record<string, string>> = {
  "animation-duration": "0ms",
  "animation-iteration-count": "1",
  "transition-duration": "0ms",
  "scroll-behavior": "auto",
};
// Every property that sets one of the reset's longhands, shorthands included.
const GOVERNED_CSS_PROPERTIES = new Set([
  "animation",
  "animation-duration",
  "animation-iteration-count",
  "transition",
  "transition-duration",
  "scroll-behavior",
]);
const GOVERNED_STYLE_KEYS = new Set([
  "animation",
  "animationDuration",
  "animationIterationCount",
  "transition",
  "transitionDuration",
  "scrollBehavior",
]);
const REDUCED_MOTION_QUERY = /prefers-reduced-motion/;

function* walk(dir: string, extensions: ReadonlySet<string>): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry);
    const stats = statSync(full);
    if (stats.isDirectory()) {
      yield* walk(full, extensions);
    } else if (stats.isFile() && extensions.has(path.extname(full))) {
      yield full;
    }
  }
}

function atRuleAncestors(node: Declaration): AtRule[] {
  const ancestors: AtRule[] = [];
  let parent: Container | undefined = node.parent;
  while (parent && parent.type !== "root") {
    if (parent.type === "atrule") {
      ancestors.push(parent as AtRule);
    }
    parent = parent.parent as Container | undefined;
  }
  return ancestors;
}

const isLayered = (ancestors: readonly AtRule[]): boolean =>
  ancestors.some((rule) => rule.name === "layer" || rule.name === "keyframes");

async function compiledGlobals(): Promise<postcss.Root> {
  const result = await postcss([tailwind()]).process(readFileSync(GLOBALS_CSS, "utf8"), {
    from: GLOBALS_CSS,
  });
  return result.root;
}

function styleKey(name: ts.PropertyName): string | undefined {
  return ts.isIdentifier(name) || ts.isStringLiteral(name) ? name.text : undefined;
}

/** Governed keys set inline, plus how many style sites the walk saw (its own universe). */
function inlineMotionStyles(): { offenders: string[]; sitesSeen: string[] } {
  const offenders: string[] = [];
  const sitesSeen: string[] = [];
  for (const file of walk(SRC_ROOT, new Set([".ts", ".tsx"]))) {
    const relative = path.relative(PWA_ROOT, file);
    const source = ts.createSourceFile(
      file,
      readFileSync(file, "utf8"),
      ts.ScriptTarget.Latest,
      true,
    );
    const report = (node: ts.Node, key: string) => {
      const { line } = source.getLineAndCharacterOfPosition(node.getStart());
      offenders.push(`${relative}:${line + 1} sets ${key}`);
    };
    const visit = (node: ts.Node): void => {
      if (
        ts.isJsxAttribute(node) &&
        node.name.getText() === "style" &&
        node.initializer &&
        ts.isJsxExpression(node.initializer) &&
        node.initializer.expression &&
        ts.isObjectLiteralExpression(node.initializer.expression)
      ) {
        sitesSeen.push(relative);
        for (const property of node.initializer.expression.properties) {
          const key = property.name ? styleKey(property.name) : undefined;
          if (key && GOVERNED_STYLE_KEYS.has(key)) {
            report(property, key);
          }
        }
      }
      if (
        ts.isBinaryExpression(node) &&
        node.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
        ts.isPropertyAccessExpression(node.left) &&
        ts.isPropertyAccessExpression(node.left.expression) &&
        node.left.expression.name.text === "style" &&
        GOVERNED_STYLE_KEYS.has(node.left.name.text)
      ) {
        report(node, node.left.name.text);
      }
      if (
        ts.isCallExpression(node) &&
        ts.isPropertyAccessExpression(node.expression) &&
        node.expression.name.text === "setProperty" &&
        ts.isPropertyAccessExpression(node.expression.expression) &&
        node.expression.expression.name.text === "style"
      ) {
        const [first] = node.arguments;
        if (first && ts.isStringLiteralLike(first) && GOVERNED_CSS_PROPERTIES.has(first.text)) {
          report(node, first.text);
        }
      }
      ts.forEachChild(node, visit);
    };
    visit(source);
  }
  return { offenders, sitesSeen };
}

describe("reduced-motion reset", () => {
  it("globals.css is the only stylesheet under src", () => {
    const stylesheets = [...walk(SRC_ROOT, new Set([".css", ".scss", ".sass"]))].map((file) =>
      path.relative(SRC_ROOT, file),
    );
    expect(
      stylesheets,
      "A new stylesheet can carry an unlayered motion rule this gate does not compile; " +
        "compile it here too before adding it to the expected list",
    ).toEqual([path.join("app", "globals.css")]);
  });

  it("declares the reset unlayered, under prefers-reduced-motion: reduce", async () => {
    const found: Record<string, string> = {};
    (await compiledGlobals()).walkDecls((declaration) => {
      const ancestors = atRuleAncestors(declaration);
      const parent = declaration.parent;
      if (
        parent?.type === "rule" &&
        /(^|,)\s*\*\s*(,|$)/.test((parent as postcss.Rule).selector) &&
        !isLayered(ancestors) &&
        ancestors.some(
          (rule) => rule.name === "media" && /prefers-reduced-motion:\s*reduce/.test(rule.params),
        )
      ) {
        found[declaration.prop] = declaration.value;
      }
    });
    expect(found).toEqual(RESET_DECLARATIONS);
  });

  it("leaves no unlayered motion declaration outside a prefers-reduced-motion query", async () => {
    const root = await compiledGlobals();
    const unguarded: string[] = [];
    let layeredMotion = 0;
    root.walkDecls((declaration) => {
      if (!GOVERNED_CSS_PROPERTIES.has(declaration.prop)) {
        return;
      }
      const ancestors = atRuleAncestors(declaration);
      if (isLayered(ancestors)) {
        layeredMotion += 1;
        return;
      }
      if (
        !ancestors.some((rule) => rule.name === "media" && REDUCED_MOTION_QUERY.test(rule.params))
      ) {
        const selector =
          declaration.parent?.type === "rule" ? (declaration.parent as postcss.Rule).selector : "?";
        unguarded.push(`${selector} { ${declaration.prop}: ${declaration.value} }`);
      }
    });
    // The utilities this reset exists to override must actually be in the compiled sheet,
    // or "nothing unguarded" would be green over a compile that emitted nothing.
    expect(layeredMotion).toBeGreaterThan(0);
    expect(
      unguarded,
      "An unlayered motion rule competes with the reset on specificity, where `*` has none, " +
        "so it wins. Put it in a layer, or under a prefers-reduced-motion query",
    ).toEqual([]);
  });

  it("no inline style sets a property the reset governs", () => {
    const { offenders, sitesSeen } = inlineMotionStyles();
    // StatCard's inline animationDelay is the known live site: reaching it proves the walk
    // covers the components it exists to police.
    expect(sitesSeen).toContain(path.join("src", "components", "erpify", "StatCard.tsx"));
    expect(
      offenders,
      "An inline style outranks the unlayered reduced-motion reset; express the motion as a " +
        "class (a layered utility) instead",
    ).toEqual([]);
  });
});
