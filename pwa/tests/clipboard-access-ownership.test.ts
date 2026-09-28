import { readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

/**
 * `useCopyToClipboard` is the only file under `src/` that reaches the clipboard.
 *
 * Why: the rule lived in prose (`pwa/CLAUDE.md` → "Clipboard / navigator APIs") and
 * `CorrelationIdChip` broke it with every gate green — its own `navigator.clipboard.writeText`
 * swallowed a failed copy in silence while `CopyButton` reported one. One writer means one answer
 * to "what happens when the clipboard is unavailable", and a second writer is how that answer
 * quietly forks.
 *
 * **What is detected.** A member named `clipboard` reached by property access (`navigator.clipboard`,
 * `globalThis.navigator.clipboard`, `x?.clipboard`), by element access with a literal key
 * (`navigator["clipboard"]`), or bound by destructuring (`const { clipboard } = navigator`). The AST
 * is read, not the text, so the word in a comment, a string or a JSX label never counts.
 *
 * **Blind spots.** A key built at runtime (`navigator["clip" + "board"]`), a reference passed in from
 * outside `src/`, `document.execCommand("copy")` (a different API, refused by `CopyButton.test.tsx`
 * rather than here), and any file outside `src/` or the four extensions below. A green proves no
 * other file under `src/` names the clipboard member — not that the owner handles it correctly.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx"]);
const MEMBER = "clipboard";

/** The single file allowed to reach the clipboard, relative to `pwa/`. */
const OWNER = "src/components/erpify/useCopyToClipboard.ts";

/** A file far from the owner that the enumeration must reach; if it moves, update this. */
const SENTINEL = "src/app/(auth)/_components/LoginForm.tsx";

/** Far below today's tree and above any single subtree, so a narrowed root cannot pass. */
const MIN_FILES = 300;

const toPosix = (file: string): string => file.split(path.sep).join("/");

function namesClipboard(node: ts.Node): boolean {
  if (ts.isPropertyAccessExpression(node)) {
    return node.name.text === MEMBER;
  }
  if (ts.isElementAccessExpression(node)) {
    const key = node.argumentExpression;
    return (
      (ts.isStringLiteral(key) || ts.isNoSubstitutionTemplateLiteral(key)) && key.text === MEMBER
    );
  }
  if (ts.isBindingElement(node)) {
    const key = node.propertyName ?? node.name;
    return (ts.isIdentifier(key) || ts.isStringLiteral(key)) && key.text === MEMBER;
  }
  return false;
}

function clipboardLines(code: string, fileName: string): number[] {
  const source = ts.createSourceFile(
    fileName,
    code,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  );
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
  ])("detects %s", (_shape, code) => {
    expect(clipboardLines(code, "fixture.ts")).not.toEqual([]);
  });

  it.each([
    ["a comment", "// navigator.clipboard.writeText(v)\nconst x = 1;"],
    ["a string", 'const s = "navigator.clipboard";'],
    ["JSX text", "const el = <span>Copy the recovery secret to the clipboard</span>;"],
  ])("ignores %s", (_shape, code) => {
    expect(clipboardLines(code, "fixture.tsx")).toEqual([]);
  });
});
