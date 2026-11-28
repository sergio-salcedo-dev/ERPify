import { readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import ts from "typescript";
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { buttonVariants } from "@/components/ui/button-variants";
import { Input } from "@/components/ui/input";
import { BanksActiveFilters } from "@/app/backoffice/banks/_components/BanksActiveFilters";
import { BankAccountsActiveFilters } from "@/app/backoffice/bank-accounts/_components/BankAccountsActiveFilters";

// jsdom evaluates no media query: these pin the utilities, which compile to
// `@media (any-pointer: coarse)`. Desktop classes must stay untouched.

it.each([
  ["default", "h-8", "any-pointer-coarse:h-11"],
  ["xs", "h-6", "any-pointer-coarse:h-11"],
  ["sm", "h-7", "any-pointer-coarse:h-11"],
  ["lg", "h-9", "any-pointer-coarse:h-11"],
  ["icon", "size-8", "any-pointer-coarse:size-11"],
  ["icon-xs", "size-6", "any-pointer-coarse:size-11"],
  ["icon-sm", "size-7", "any-pointer-coarse:size-11"],
  ["icon-lg", "size-9", "any-pointer-coarse:size-11"],
] as const)(
  "Button size %s keeps %s and grows to 44px on coarse pointers",
  (size, base, coarse) => {
    const classes = buttonVariants({ size }).split(/\s+/);
    expect(classes).toContain(base);
    expect(classes).toContain(coarse);
  },
);

it("Input keeps h-8 and grows to 44px on coarse pointers", () => {
  render(<Input aria-label="x" />);
  expect(screen.getByLabelText("x")).toHaveClass("h-8", "any-pointer-coarse:h-11");
});

it.each([
  [
    "banks",
    <BanksActiveFilters
      key="b"
      chips={[{ key: "name", label: "Name: Acme" }]}
      onRemove={vi.fn()}
      onClearAll={vi.fn()}
    />,
    "banks-filters__chip-name",
  ],
  [
    "bank accounts",
    <BankAccountsActiveFilters
      key="a"
      chips={[{ key: "iban", label: "IBAN: ES" }] as never}
      onRemove={vi.fn()}
      onClearAll={vi.fn()}
    />,
    "bank-accounts-filters__chip-iban",
  ],
])("%s chip-remove is 24px (WCAG 2.5.8) and 44px on coarse pointers", (_n, ui, testId) => {
  render(ui);
  expect(screen.getByTestId(testId)).toHaveClass("size-6", "any-pointer-coarse:size-11");
  expect(screen.getByTestId(testId)).not.toHaveClass("size-4");
});

/**
 * Every control that is 36 px tall on desktop (`h-9`) grows to 44 px on a coarse pointer. The
 * universe is derived from the tree, never listed: every class string under `src/` is read from
 * the TypeScript AST, and one is a CONTROL class string when either signal holds —
 *
 * - it carries an interaction-state variant (`focus:`, `focus-visible:`, `hover:`, `active:`,
 *   `disabled:`), which only something a person operates needs; or
 * - it is the literal `className` of an intrinsic interactive element (`<button>`, `<select>`,
 *   `<input>`, `<textarea>`, `<a>`, `<summary>`), which catches a control spelled with no state.
 *
 * A row height (`{ compact: "h-9" }`) or a skeleton row is neither, and stays out by shape rather
 * than by an exemption line. A template literal is read whole (head and every span's literal
 * text), so a substitution between the height and the state variant does not split the string.
 *
 * The universe asserts itself: a file floor and a sentinel guard the walk (a narrowed root keeps
 * an empty violation list green), and a floor on the control strings found guards the classifier
 * (one that matches nothing is green over everything).
 *
 * A green proves nothing about classes assembled across arguments (`cn("h-9", state)` with the
 * state in a different literal and the element not intrinsic), a height arriving through a
 * variable, a 36 px control spelled other than `h-9`, or what the browser actually renders —
 * jsdom evaluates no media query, so a touch-profile measurement is not this test.
 */
describe("control-height h-9 pairs with the coarse-pointer height", () => {
  const PWA_ROOT = path.resolve(__dirname, "../../..");
  const SRC_ROOT = path.join(PWA_ROOT, "src");
  const SOURCE_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx"]);
  const CONTROL_HEIGHT = "h-9";
  const COARSE_HEIGHT = "any-pointer-coarse:h-11";
  const INTERACTIVE_ELEMENTS = new Set(["a", "button", "input", "select", "summary", "textarea"]);
  const INTERACTION_STATE = /^(?:[a-z-]+:)*(?:focus|focus-visible|hover|active|disabled):/;
  /** A file the walk must reach and the classifier must find a control in. */
  const SENTINEL = "src/app/backoffice/users/_components/UsersFilters.tsx";
  /** Far below today's ~530 files, far above any one subtree. */
  const MIN_WALKED_FILES = 300;
  /** Today 11 control strings carry `h-9`; a classifier finding fewer than this has gone blind. */
  const MIN_CONTROL_STRINGS = 9;

  interface ClassString {
    file: string;
    line: number;
    tokens: string[];
  }

  const toPosix = (file: string): string => file.split(path.sep).join("/");

  function isInteractiveClassName(node: ts.Node): boolean {
    const holder = ts.isJsxExpression(node.parent) ? node.parent.parent : node.parent;
    if (!ts.isJsxAttribute(holder) || holder.name.getText() !== "className") {
      return false;
    }
    const element = holder.parent.parent;
    return (
      (ts.isJsxOpeningElement(element) || ts.isJsxSelfClosingElement(element)) &&
      ts.isIdentifier(element.tagName) &&
      INTERACTIVE_ELEMENTS.has(element.tagName.text)
    );
  }

  function isControlClassString(node: ts.Node, tokens: string[]): boolean {
    return tokens.some((token) => INTERACTION_STATE.test(token)) || isInteractiveClassName(node);
  }

  /** Control class strings in one source carrying the control height. */
  function controlHeightStrings(code: string, file: string): ClassString[] {
    const source = ts.createSourceFile(file, code, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
    const found: ClassString[] = [];

    const consider = (node: ts.Node, text: string): void => {
      const tokens = text.split(/\s+/).filter(Boolean);
      if (tokens.includes(CONTROL_HEIGHT) && isControlClassString(node, tokens)) {
        const { line } = source.getLineAndCharacterOfPosition(node.getStart(source));
        found.push({ file, line: line + 1, tokens });
      }
    };

    const visit = (node: ts.Node): void => {
      if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) {
        consider(node, node.text);
        return;
      }
      if (ts.isTemplateExpression(node)) {
        consider(
          node,
          [node.head.text, ...node.templateSpans.map((s) => s.literal.text)].join(" "),
        );
        node.templateSpans.forEach((span) => visit(span.expression));
        return;
      }
      ts.forEachChild(node, visit);
    };

    visit(source);
    return found;
  }

  const files = readdirSync(SRC_ROOT, { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile() && SOURCE_EXTENSIONS.has(path.extname(entry.name)))
    .map((entry) => path.join(entry.parentPath, entry.name));
  const strings = files.flatMap((absolute) =>
    controlHeightStrings(
      readFileSync(absolute, "utf8"),
      toPosix(path.relative(PWA_ROOT, absolute)),
    ),
  );
  const bare = (found: ClassString[]): string[] =>
    found
      .filter(({ tokens }) => !tokens.includes(COARSE_HEIGHT))
      .map(({ file, line }) => `${file}:${line}`);

  it("walks the whole source tree", () => {
    expect(files.length).toBeGreaterThanOrEqual(MIN_WALKED_FILES);
    expect(files.map((file) => toPosix(path.relative(PWA_ROOT, file)))).toContain(SENTINEL);
  });

  it("finds the control-height class strings the tree holds", () => {
    expect(strings.length).toBeGreaterThanOrEqual(MIN_CONTROL_STRINGS);
    expect(strings.map(({ file }) => file)).toContain(SENTINEL);
  });

  it("pairs every control-height h-9 with any-pointer-coarse:h-11", () => {
    expect(bare(strings)).toEqual([]);
  });

  it.each([
    ["a bare intrinsic select", `<select className="h-9 w-full rounded-md border" />`],
    ["a state-bearing class constant", `const c = "h-9 w-full focus-visible:ring-2";`],
    ["a template literal split by a substitution", "const c = `h-9 ${x} disabled:opacity-50`;"],
    ["a className through an expression", `<button className={"h-9 px-4"}>Go</button>`],
  ])("reports %s", (_shape, code) => {
    expect(bare(controlHeightStrings(code, "fixture.tsx"))).toHaveLength(1);
  });

  it.each([
    ["a row-height map", `const ROW = { compact: "h-9", comfortable: "h-11" };`],
    ["a skeleton row", `<li className="flex h-9 items-center gap-4 px-3" />`],
    ["a paired control", `<button className="h-9 any-pointer-coarse:h-11 hover:bg-x">Go</button>`],
  ])("leaves %s alone", (_shape, code) => {
    expect(bare(controlHeightStrings(code, "fixture.tsx"))).toEqual([]);
  });
});
