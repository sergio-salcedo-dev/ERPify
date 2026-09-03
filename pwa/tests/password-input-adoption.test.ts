import { readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

/**
 * `PasswordInput` is the only place in the tree that declares a password-typed input.
 *
 * Why: the component already existed — reveal toggle, static accessible name, `aria-pressed`,
 * 44px target — and a form that spelled its own `<Input type="password">` got none of it, with
 * every gate green. A convention that lives only in prose is rediscovered one form at a time;
 * this makes it a property the suite can refute.
 *
 * **What is detected, stated precisely.** For every JSX attribute named `type`, and for a `type`
 * property inside an inline spread object (`{...{ type: "password" }}`), the walk searches the
 * value subtree for a string literal — or a template literal with no substitutions — whose text
 * is `password` in any case. `type` is an ASCII-case-insensitive enumerated attribute, so
 * `type="Password"` renders a masked field and is a declaration like any other. It reads the AST
 * rather than the text, so `password` inside a comment, an identifier or an unrelated `const`
 * never competes with an attribute value.
 *
 * **Blind spots, stated rather than implied.** A green proves only that no password input is
 * declared as a literal `type` attribute (or inline-spread `type` property) outside the
 * component. It proves nothing about: a `type` arriving through a variable (`ui/input.tsx`
 * legitimately forwards `type={type}`), a spread of a named object (`const p = { type:
 * "password" }; <Input {...p} />`), a value built by concatenation (`{"pass" + "word"}`) or by a
 * substituting template literal, an element built with `React.createElement`, a secret field
 * deliberately declared `type="text"`, or any file outside `src/` or outside the four source
 * extensions below.
 *
 * **It over-matches too, and that direction had no record until a review pointed at it.** The
 * subtree search accepts a literal anywhere under the value, so `type={kind === "password" ?
 * "text" : "email"}` is reported although `password` can never be the resulting value, and so is
 * a domain-valued `type` prop on something that is not an input (`<Notification
 * type="password">`). Both fail safe, and the tree holds zero of either today — but a developer
 * who hits one gets a red they cannot act on by fixing their code, and the answer then is to
 * narrow this walk, not to add an exemption.
 *
 * The two invariants are asserted separately, and the universe is measured independently of the
 * positives. A non-empty check derived from the same walk can only notice the universe going
 * *empty*, never it *shrinking to the owner*: measured, pointing `SRC_ROOT` at `src/components`
 * leaves both the count and the ownership assertions green while covering 43 of 496 files and
 * none of the forms this gate exists to police.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx"]);
const PASSWORD_TYPE = "password";

/** The single file allowed to declare a password-typed input, relative to `pwa/`. */
const OWNER = path.join("src", "components", "ui", "PasswordInput.tsx");

/** The walk must reach the route tree, not just the component folder the owner sits in. */
const FORM_TREE = path.join("src", "app");

/** A floor on the walked file count, far below today's 496 and far above any one subtree. */
const MIN_WALKED_FILES = 300;

interface Declaration {
  /** Path relative to `pwa/`, or the fixture name for in-memory sources. */
  file: string;
  line: number;
}

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

/** A literal spelling of the word, in either form that carries no expression, in any case. */
function isPasswordLiteral(node: ts.Node): boolean {
  return (
    (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) &&
    node.text.toLowerCase() === PASSWORD_TYPE
  );
}

function subtreeHasPasswordLiteral(node: ts.Node): boolean {
  if (isPasswordLiteral(node)) {
    return true;
  }
  return ts.forEachChild(node, subtreeHasPasswordLiteral) ?? false;
}

function isTypeNamed(name: ts.Node): boolean {
  return (ts.isIdentifier(name) || ts.isStringLiteral(name)) && name.text === "type";
}

/** `type={…}` written as an attribute, or as a property of an inline spread object. */
function declaresPasswordType(node: ts.Node): boolean {
  if (ts.isJsxAttribute(node)) {
    return (
      isTypeNamed(node.name) &&
      node.initializer !== undefined &&
      subtreeHasPasswordLiteral(node.initializer)
    );
  }
  if (ts.isJsxSpreadAttribute(node) && ts.isObjectLiteralExpression(node.expression)) {
    return node.expression.properties.some(
      (property) =>
        ts.isPropertyAssignment(property) &&
        isTypeNamed(property.name) &&
        subtreeHasPasswordLiteral(property.initializer),
    );
  }
  return false;
}

/** Every password-typed declaration in one source file. */
function collectPasswordTypeDeclarations(source: ts.SourceFile, file: string): Declaration[] {
  const found: Declaration[] = [];

  const visit = (node: ts.Node): void => {
    if (declaresPasswordType(node)) {
      const { line } = source.getLineAndCharacterOfPosition(node.getStart(source));
      found.push({ file, line: line + 1 });
    }
    ts.forEachChild(node, visit);
  };

  visit(source);
  return found;
}

function parse(code: string, fileName: string): ts.SourceFile {
  return ts.createSourceFile(fileName, code, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
}

function collectFromTree(): { files: string[]; declarations: Declaration[] } {
  const files: string[] = [];
  const declarations: Declaration[] = [];
  for (const absolute of walk(SRC_ROOT)) {
    const file = path.relative(PWA_ROOT, absolute);
    files.push(file);
    declarations.push(
      ...collectPasswordTypeDeclarations(parse(readFileSync(absolute, "utf8"), file), file),
    );
  }
  return { files, declarations };
}

const format = ({ file, line }: Declaration): string => `${file}:${line}`;

describe("password input adoption", () => {
  const { files, declarations } = collectFromTree();

  it("walks a universe wide enough to be worth asserting over", () => {
    expect(
      files.length,
      `The walk reached ${files.length} files, below the ${MIN_WALKED_FILES} floor. A narrowed ` +
        `SRC_ROOT or a skip inside walk() leaves every assertion below green over a fraction of ` +
        `the tree, which is the vacuous pass this floor exists to refuse.`,
    ).toBeGreaterThan(MIN_WALKED_FILES);

    expect(
      files.some((file) => file.startsWith(FORM_TREE + path.sep)),
      `The walk never reached ${FORM_TREE}/, where the forms this gate polices live.`,
    ).toBe(true);
  });

  it(`still finds a password input — ${OWNER} must keep declaring one`, () => {
    expect(
      declarations.length,
      `No password-typed input found anywhere under src/. ${OWNER} was deleted, or its type ` +
        `stopped being a literal. Note that a RENAME or a MOVE does not land here: the ` +
        `declaration still exists, so this assertion passes and the ownership one fails instead.`,
    ).toBeGreaterThan(0);
  });

  it(`declares them nowhere but ${OWNER}`, () => {
    const offenders = declarations.filter((d) => d.file !== OWNER).map(format);

    expect(
      offenders,
      `Password inputs must be composed from <PasswordInput>, which owns the reveal toggle, its ` +
        `accessible name, its masked default and the text-assist attributes a revealed field ` +
        `needs. Declared elsewhere:\n${offenders.join("\n")}\n` +
        `If one of those IS the component under a new path, update OWNER at the top of this file.`,
    ).toEqual([]);
  });
});

describe("the collector's contract", () => {
  it.each([
    { form: 'type="password"', code: '<input type="password" />;' },
    {
      form: 'type="Password" (the attribute is case-insensitive)',
      code: '<input type="Password" />;',
    },
    { form: 'type={"password"}', code: '<Input type={"password"} />;' },
    { form: "type={`password`}", code: "<Input type={`password`} />;" },
    { form: "conditional", code: '<Input type={revealed ? "text" : "password"} />;' },
    { form: "logical and", code: '<Input type={locked && "password"} />;' },
    { form: "inline spread object", code: '<Input {...{ type: "password" }} />;' },
  ])("detects $form", ({ code }) => {
    expect(collectPasswordTypeDeclarations(parse(code, "fixture.tsx"), "fixture.tsx")).toHaveLength(
      1,
    );
  });

  it.each([
    { form: "an unrelated const", code: 'const password = "password";\n<Input type="text" />;' },
    { form: "a commented-out attribute", code: '// <input type="password" />\n<Input />;' },
    { form: "a forwarded variable", code: "<Input type={type} />;" },
    { form: "another input type", code: '<Input type="text" />;' },
    {
      form: "a spread of a named object (documented blind spot)",
      code: 'const p = { type: "password" };\n<Input {...p} />;',
    },
    {
      form: "a concatenated value (documented blind spot)",
      code: '<Input type={"pass" + "word"} />;',
    },
  ])("ignores $form", ({ code }) => {
    expect(collectPasswordTypeDeclarations(parse(code, "fixture.tsx"), "fixture.tsx")).toEqual([]);
  });

  it("reports the line of every declaration in a file", () => {
    const code = ['<input type="password" />;', "<Input />;", '<Input type={"password"} />;'].join(
      "\n",
    );

    expect(collectPasswordTypeDeclarations(parse(code, "fixture.tsx"), "fixture.tsx").map(format)) //
      .toEqual(["fixture.tsx:1", "fixture.tsx:3"]);
  });
});
