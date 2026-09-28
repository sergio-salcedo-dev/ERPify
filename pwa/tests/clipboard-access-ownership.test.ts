import { readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

/**
 * `useCopyToClipboard` is the only file under `src/` that reaches the clipboard, and only
 * `src/components/erpify/` imports it.
 *
 * Why: one writer means one answer to "what happens when the clipboard is unavailable", and a
 * second writer is how that answer quietly forks — a copy control that writes for itself can
 * swallow a failure in silence while its sibling reports one, and nothing else in the suite sees
 * the difference. The import half keeps screens on `CopyButton`: the hook is kept out of the barrel,
 * and a direct path import would otherwise walk around that.
 *
 * **What is detected.** A member that writes to the clipboard — `clipboard` (the async API),
 * `clipboardData` (a `copy` event handler) and `execCommand` (the deprecated `document` command) —
 * reached by property access (`navigator.clipboard`, `x?.clipboard`, `e.clipboardData`), by element
 * access with a literal key (`navigator["clipboard"]`), bound by destructuring with a plain or a
 * literal computed key (`const { clipboard } = navigator`, `const { ["clipboard"]: c } = navigator`),
 * or probed with `in` (`"clipboard" in navigator`). The AST is read, not the text, so the word in a
 * comment, a string or a JSX label never counts, and each file is parsed as its own extension so a
 * `.ts` generic arrow is never mistaken for JSX.
 *
 * **Blind spots.** A key built at runtime (`navigator["clip" + "board"]`), a reflective read
 * (`Reflect.get(navigator, "clipboard")`), a reference handed in from outside `src/`, and any file outside `src/` or the four extensions below. The detector is by member
 * NAME, so an unrelated property that happens to be called `clipboard` also counts — none exists
 * today, and the remedy for one is to rename it or narrow this walk, never an exemption. A green
 * proves no other file under `src/` names these members and no file outside the component folder
 * imports the hook — not that the owner handles the clipboard correctly.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx"]);
const MEMBERS = new Set(["clipboard", "clipboardData", "execCommand"]);
const HOOK_MODULE = "useCopyToClipboard";

/** The only folder allowed to import the hook, relative to `pwa/`. */
const HOOK_IMPORTERS = "src/components/erpify/";

/** The single file allowed to reach the clipboard, relative to `pwa/`. */
const OWNER = "src/components/erpify/useCopyToClipboard.ts";

/** A file far from the owner that the enumeration must reach; if it moves, update this. */
const SENTINEL = "src/app/(auth)/_components/LoginForm.tsx";

/** Far below today's tree and above any single subtree, so a narrowed root cannot pass. */
const MIN_FILES = 300;

const toPosix = (file: string): string => file.split(path.sep).join("/");

const SCRIPT_KIND_BY_EXTENSION: Record<string, ts.ScriptKind> = {
  ".ts": ts.ScriptKind.TS,
  ".tsx": ts.ScriptKind.TSX,
  ".js": ts.ScriptKind.JS,
  ".jsx": ts.ScriptKind.JSX,
};

function isMemberLiteral(node: ts.Node): boolean {
  return (
    (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) && MEMBERS.has(node.text)
  );
}

function namesClipboard(node: ts.Node): boolean {
  if (ts.isPropertyAccessExpression(node)) {
    return MEMBERS.has(node.name.text);
  }
  if (ts.isElementAccessExpression(node)) {
    return isMemberLiteral(node.argumentExpression);
  }
  if (ts.isBindingElement(node)) {
    const key = node.propertyName ?? node.name;
    if (ts.isComputedPropertyName(key)) {
      return isMemberLiteral(key.expression);
    }
    return (ts.isIdentifier(key) || ts.isStringLiteral(key)) && MEMBERS.has(key.text);
  }
  if (ts.isBinaryExpression(node) && node.operatorToken.kind === ts.SyntaxKind.InKeyword) {
    return isMemberLiteral(node.left);
  }
  return false;
}

function parse(code: string, fileName: string): ts.SourceFile {
  const kind = SCRIPT_KIND_BY_EXTENSION[path.extname(fileName)] ?? ts.ScriptKind.TSX;
  return ts.createSourceFile(fileName, code, ts.ScriptTarget.Latest, true, kind);
}

function clipboardLines(code: string, fileName: string): number[] {
  const source = parse(code, fileName);
  const lines: number[] = [];
  const visit = (node: ts.Node): void => {
    if (namesClipboard(node)) {
      lines.push(source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1);
    }
    ts.forEachChild(node, visit);
  };
  visit(source);
  return lines;
}

/** Whether the file imports or re-exports the hook module, by any path spelling. */
function importsHook(code: string, fileName: string): boolean {
  return parse(code, fileName).statements.some(
    (statement) =>
      (ts.isImportDeclaration(statement) || ts.isExportDeclaration(statement)) &&
      statement.moduleSpecifier !== undefined &&
      ts.isStringLiteral(statement.moduleSpecifier) &&
      path.posix.basename(statement.moduleSpecifier.text) === HOOK_MODULE,
  );
}

function sourceFiles(): string[] {
  return readdirSync(SRC_ROOT, { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile() && SOURCE_EXTENSIONS.has(path.extname(entry.name)))
    .map((entry) => toPosix(path.relative(PWA_ROOT, path.join(entry.parentPath, entry.name))));
}

describe("clipboard access ownership", () => {
  const files = sourceFiles();
  const offenders = files
    .filter((file) => file !== OWNER)
    .flatMap((file) =>
      clipboardLines(readFileSync(path.join(PWA_ROOT, file), "utf8"), file).map(
        (line) => `${file}:${line}`,
      ),
    );

  it("reaches the whole of src/", () => {
    expect(files.length).toBeGreaterThanOrEqual(MIN_FILES);
    expect(files).toContain(SENTINEL);
    expect(files).toContain(OWNER);
  });

  it("finds the clipboard only in its owner", () => {
    expect(offenders, "reach the clipboard through CopyButton or useCopyToClipboard").toEqual([]);
  });

  it("still sees the owner's own access, so the detector is not blind", () => {
    expect(clipboardLines(readFileSync(path.join(PWA_ROOT, OWNER), "utf8"), OWNER)).not.toEqual([]);
  });

  it.each([
    ["property access", "await navigator.clipboard.writeText(v);"],
    ["optional chaining", "navigator?.clipboard?.writeText(v);"],
    ["a longer receiver", "globalThis.navigator.clipboard.writeText(v);"],
    ["element access", 'navigator["clipboard"].writeText(v);'],
    ["destructuring", "const { clipboard } = navigator;"],
    ["renamed destructuring", "const { clipboard: c } = navigator;"],
    ["a literal computed key", 'const { ["clipboard"]: c } = navigator;'],
    ["an in probe", 'const ok = "clipboard" in navigator;'],
    ["a copy event's clipboardData", 'e.clipboardData.setData("text/plain", v);'],
    ["the deprecated execCommand", 'document.execCommand("copy");'],
    [
      "an access after a .ts generic arrow",
      "const f = <T,>(v: T) => v;\nnavigator.clipboard.writeText(s);",
    ],
  ])("detects %s", (_shape, code) => {
    expect(clipboardLines(code, "fixture.ts")).not.toEqual([]);
  });

  it("parses a .ts file as TypeScript, so an angle-bracket assertion does not hide what follows", () => {
    const code = "const n = <number>value;\nnavigator.clipboard.writeText(s);";
    expect(clipboardLines(code, "fixture.ts")).toEqual([2]);
  });

  it("finds the hook imported only from the component folder", () => {
    const importers = files.filter(
      (file) =>
        !file.startsWith(HOOK_IMPORTERS) &&
        importsHook(readFileSync(path.join(PWA_ROOT, file), "utf8"), file),
    );
    expect(importers, "screens copy through CopyButton, never the hook").toEqual([]);
  });

  it.each([
    ["a relative import", 'import { useCopyToClipboard } from "./useCopyToClipboard";'],
    [
      "an aliased path import",
      'import { useCopyToClipboard } from "@/components/erpify/useCopyToClipboard";',
    ],
    ["a re-export", 'export { useCopyToClipboard } from "./useCopyToClipboard";'],
  ])("recognises the hook through %s", (_shape, code) => {
    expect(importsHook(code, "fixture.ts")).toBe(true);
  });

  it.each([
    ["a comment", "// navigator.clipboard.writeText(v)\nconst x = 1;"],
    ["a string", 'const s = "navigator.clipboard";'],
    ["JSX text", "const el = <span>Copy the recovery secret to the clipboard</span>;"],
  ])("ignores %s", (_shape, code) => {
    expect(clipboardLines(code, "fixture.tsx")).toEqual([]);
  });
});
