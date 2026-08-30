import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

/**
 * Every route in a title-gated tree must resolve a document title of its own.
 *
 * Next's route announcer (`app-router-announcer.js`) reads `document.title` on every tree change
 * and speaks **only when it changes**. Its `<h1>` fallback runs when `document.title` is falsy,
 * and `app/layout.tsx` fixes a static one — so on this tree that fallback is dead code. A route
 * that declares no title of its own therefore inherits the root title, the title never changes
 * between routes, and the navigation is **never announced**. Whole trees were in that state —
 * the back office and every `(auth)` route — and nothing said so: no gate, no lint rule, no
 * failing test.
 *
 * `minRoutes` is a floor against a walk that resolves nothing, and it is the one part of this
 * gate that decays on its own: every route added leaves it further behind the tree, silently,
 * because `>=` keeps passing. Measured on this tree it had drifted twice over — 51 declared
 * against 59 back-office routes, and 4 against 5 `(auth)` ones — so it would have gone on
 * passing after eight back-office deletions. Read a floor here as "at least this many existed
 * when somebody last looked", never as the tree's size. Pinning the small trees as SETS of
 * route paths, the way the repo's mail-surface gate does, would remove the decay for them; it
 * is not done here because the back office churns and a set there would be a merge conflict per
 * route.
 *
 * A title is resolved from the page's own `metadata` or from the nearest layout above it, which
 * is how a Client Component page declares one (`metadata` is server-only). Each tree's own
 * segment root layout is asserted NOT to carry a title: one there would satisfy every route in
 * that tree by inheritance and reinstate exactly the silence this gate exists to refuse.
 *
 * Distinctness is asserted per tree, not assumed. Two routes resolving the same string announce
 * nothing when you move between them — the same silence, one level down — and this gate shipped
 * stating that as a known limit until an independent review found three live collisions in the
 * tree it had just passed. "Only review sees that" was the defect, so the gate sees it now.
 *
 * The title itself is read with the TypeScript compiler's AST rather than a regex over the
 * source text: a regex scanning for `title:` cannot distinguish a top-level `metadata` property
 * from one nested in a sub-object (`openGraph: { title: … }`), and it cannot tell a live
 * `title:` from one sitting inside a comment. Neither ambiguity survives parsing — a comment
 * is trivia attached to a token, never a node the walk visits, and a nested object's properties
 * are children of that object, not of `metadata`'s own literal. The walk also unwraps a
 * `satisfies`/`as`/parenthesized initializer down to its object literal. What it does NOT
 * resolve: a `title` written as a shorthand property (`{ title }`, sourced from a local binding)
 * or reached only through a spread (`{ ...shared }` with no direct `title:` of its own) — both
 * need resolving an identifier's declaration elsewhere in the file, a bigger lift than this walk
 * takes on. Neither form is in use today.
 *
 * A green proves each route resolves a distinct title within its own tree, and that no two
 * trees hand the same title to routes a real transition can connect (a successful login lands
 * on a deep link into `app/backoffice`, so `app/(auth)` and `app/backoffice` share a boundary a
 * screen reader can actually cross). It proves nothing about a title being meaningful or
 * matching what the page shows.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const DYNAMIC_METADATA_RE = /export\s+async\s+function\s+generateMetadata/;
const CLIENT_DIRECTIVE_RE = /^\s*["']use client["']/m;
const PRIVATE_SEGMENT_RE = /^_/;

/** A route tree gated for title coverage. `minRoutes` guards against a walk resolving nothing. */
interface RouteGroup {
  readonly label: string;
  readonly root: string;
  readonly minRoutes: number;
  readonly urlPrefix: string;
}

const ROUTE_GROUPS: readonly RouteGroup[] = [
  {
    label: "back-office",
    root: path.join(PWA_ROOT, "src", "app", "backoffice"),
    minRoutes: 59,
    urlPrefix: "/backoffice",
  },
  {
    label: "auth",
    root: path.join(PWA_ROOT, "src", "app", "(auth)"),
    minRoutes: 5,
    urlPrefix: "",
  },
  {
    label: "errors",
    root: path.join(PWA_ROOT, "src", "app", "(errors)"),
    minRoutes: 6,
    urlPrefix: "",
  },
];

/** The `metadata` object literal's `title` property, read from the AST — not the source text. */
function titleOf(file: string): string | null {
  if (!existsSync(file)) return null;
  const metadataObject = metadataObjectLiteral(file, readFileSync(file, "utf8"));
  return metadataObject ? titlePropertyValue(metadataObject) : null;
}

/** Strips `satisfies X` / `as X` / redundant parentheses down to the wrapped expression. */
function unwrapExpression(node: ts.Expression): ts.Expression {
  let current = node;
  for (;;) {
    if (ts.isSatisfiesExpression(current) || ts.isAsExpression(current)) {
      current = current.expression;
    } else if (ts.isParenthesizedExpression(current)) {
      current = current.expression;
    } else {
      return current;
    }
  }
}

/** The top-level `export const metadata = {...}` object literal, if the file declares one. */
function metadataObjectLiteral(file: string, source: string): ts.ObjectLiteralExpression | null {
  const sourceFile = ts.createSourceFile(
    file,
    source,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  );
  for (const statement of sourceFile.statements) {
    if (!ts.isVariableStatement(statement)) continue;
    const isExported =
      statement.modifiers?.some((m) => m.kind === ts.SyntaxKind.ExportKeyword) ?? false;
    if (!isExported) continue;
    for (const declaration of statement.declarationList.declarations) {
      if (declaration.name.getText() !== "metadata") continue;
      if (!declaration.initializer) continue;
      const value = unwrapExpression(declaration.initializer);
      if (ts.isObjectLiteralExpression(value)) return value;
    }
  }
  return null;
}

/** `title` as a direct (depth-1) property — never one belonging to a nested object literal. */
function titlePropertyValue(objectLiteral: ts.ObjectLiteralExpression): string | null {
  for (const property of objectLiteral.properties) {
    if (!ts.isPropertyAssignment(property)) continue;
    if (property.name.getText() !== "title") continue;
    return stringValueOf(property.initializer);
  }
  return null;
}

/** A plain string title, or the `default` of Next's `{ default, template }` object form. */
function stringValueOf(node: ts.Expression): string | null {
  if (ts.isStringLiteralLike(node)) return node.text;
  if (!ts.isObjectLiteralExpression(node)) return null;
  for (const property of node.properties) {
    if (!ts.isPropertyAssignment(property)) continue;
    if (property.name.getText() !== "default") continue;
    if (ts.isStringLiteralLike(property.initializer)) return property.initializer.text;
  }
  return null;
}

function declaresTitle(file: string): boolean {
  return titleOf(file) !== null;
}

/** The title a route actually resolves: its own, else the nearest layout's above it. */
function resolvedTitle(page: string, group: RouteGroup): string | null {
  for (const source of titleSources(page, group)) {
    const title = titleOf(source);
    if (title !== null) return title;
  }
  return null;
}

function isClientComponent(file: string): boolean {
  return CLIENT_DIRECTIVE_RE.test(readFileSync(file, "utf8"));
}

/** Every `page.tsx` under `dir`, skipping Next's private (`_`-prefixed) segments. */
function* routes(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    if (PRIVATE_SEGMENT_RE.test(entry)) continue;
    const full = path.join(dir, entry);
    if (statSync(full).isDirectory()) {
      yield* routes(full);
    } else if (entry === "page.tsx") {
      yield full;
    }
  }
}

/** The page itself, then every layout from its own segment up to the group root, inclusive. */
function titleSources(page: string, group: RouteGroup): string[] {
  const sources = [page];
  let dir = path.dirname(page);
  for (;;) {
    sources.push(path.join(dir, "layout.tsx"));
    if (dir === group.root) return sources;
    dir = path.dirname(dir);
  }
}

function routeOf(page: string, group: RouteGroup): string {
  const segment = path.relative(group.root, path.dirname(page)).replaceAll(path.sep, "/");
  const url = `${group.urlPrefix}${segment ? `/${segment}` : ""}`;
  // Only a group root page can produce an empty url (no urlPrefix, no segment) — currently just
  // `(auth)`. A bare "/" would silently read as the real site root in a failure message; this
  // reads as neither a URL nor a coincidence.
  return url || `(${group.label} root)`;
}

describe("title extraction reads the AST, not the source text", () => {
  const titleFromSource = (source: string): string | null => {
    const metadataObject = metadataObjectLiteral("fixture.tsx", source);
    return metadataObject ? titlePropertyValue(metadataObject) : null;
  };

  it("ignores a title: nested inside a sub-object of metadata, unlike a text-scanning regex", () => {
    // A text scanner matches the FIRST `title:` in the block regardless of nesting depth, so a
    // route declaring only this would have been wrongly read as titled.
    const source = `export const metadata: Metadata = {
      openGraph: { title: "Nested — not the route's title" },
    };`;
    expect(titleFromSource(source)).toBeNull();
  });

  it("reads the top-level title even when a nested one precedes it in the object", () => {
    const source = `export const metadata: Metadata = {
      openGraph: { title: "Nested" },
      title: "Real title",
    };`;
    expect(titleFromSource(source)).toBe("Real title");
  });

  it("is not fooled by a title: sitting inside a comment ahead of the real one", () => {
    // A text scanner has no notion of comment trivia, so the first `title:` it finds can be
    // one a developer already commented out.
    const source = `export const metadata: Metadata = {
      // title: "Commented out, not live"
      title: "Real title",
    };`;
    expect(titleFromSource(source)).toBe("Real title");
  });

  it("reads the `default` of Next's `{ default, template }` title object form", () => {
    const source = `export const metadata: Metadata = {
      title: { default: "Default title", template: "%s | ERPify" },
    };`;
    expect(titleFromSource(source)).toBe("Default title");
  });

  it("unwraps a satisfies-typed metadata initializer", () => {
    const source = `export const metadata = {
      title: "Real title",
    } satisfies Metadata;`;
    expect(titleFromSource(source)).toBe("Real title");
  });

  it("unwraps an as-cast metadata initializer", () => {
    const source = `export const metadata = {
      title: "Real title",
    } as Metadata;`;
    expect(titleFromSource(source)).toBe("Real title");
  });

  it("unwraps a parenthesized metadata initializer", () => {
    const source = `export const metadata: Metadata = (
      { title: "Real title" }
    );`;
    expect(titleFromSource(source)).toBe("Real title");
  });
});

describe.each(ROUTE_GROUPS)("$label route titles", (group) => {
  const pages = [...routes(group.root)].sort();

  it("finds the routes it claims to check", () => {
    // Without this, a walk that resolves nothing passes the assertion below on an empty set.
    expect(pages.length).toBeGreaterThanOrEqual(group.minRoutes);
  });

  it("resolves a title for every route, from the page or a layout above it", () => {
    const unnamed = pages
      .filter((page) => !titleSources(page, group).some(declaresTitle))
      .map((page) => routeOf(page, group));
    expect(unnamed).toEqual([]);
  });

  it("never puts the title on a Client Component page, where it is not applied", () => {
    // `metadata` is server-only. Next fails the build on this, so the cost is a broken build
    // rather than a silent miss — but the gate above would be green on the way there, and the
    // repair is the layout seam, which is what this names.
    const misplaced = pages
      .filter((page) => isClientComponent(page) && declaresTitle(page))
      .map((page) => routeOf(page, group));
    expect(misplaced).toEqual([]);
  });

  it("keeps the segment layout title-free — inheritance is the silence", () => {
    expect(declaresTitle(path.join(group.root, "layout.tsx"))).toBe(false);
  });

  it("gives no two routes the same title — an unchanged title is an unannounced navigation", () => {
    const byTitle = new Map<string, string[]>();
    for (const page of pages) {
      const title = resolvedTitle(page, group);
      if (title === null) continue;
      byTitle.set(title, [...(byTitle.get(title) ?? []), routeOf(page, group)]);
    }
    const shared = [...byTitle].filter(([, routes]) => routes.length > 1);
    expect(shared).toEqual([]);
  });

  it("reads every title it is asked about — no route hides one behind generateMetadata", () => {
    // The extractor above reads a static `metadata` literal. A dynamic title is not a defect,
    // but it is invisible to it, and a route carrying one would resolve `null` and red the
    // distinctness check for the wrong reason. This names the day that has to be taught.
    const dynamic = pages
      .filter((page) => DYNAMIC_METADATA_RE.test(readFileSync(page, "utf8")))
      .map((page) => routeOf(page, group));
    expect(dynamic).toEqual([]);
  });
});

describe("title distinctness across every gated tree", () => {
  it("gives no two routes in different trees the same title — a real transition crosses them", () => {
    // A successful login (`LoginForm.tsx`) does `router.push` into `app/backoffice` (its own
    // root, or wherever `?next=` deep-links) — a genuine client-side transition between two of
    // these trees, so the per-tree distinctness above is not the whole guarantee an announcer
    // needs; the union has to hold it too.
    const byTitle = new Map<string, string[]>();
    for (const group of ROUTE_GROUPS) {
      for (const page of routes(group.root)) {
        const title = resolvedTitle(page, group);
        if (title === null) continue;
        byTitle.set(title, [...(byTitle.get(title) ?? []), routeOf(page, group)]);
      }
    }
    const shared = [...byTitle].filter(([, matchedRoutes]) => matchedRoutes.length > 1);
    expect(shared).toEqual([]);
  });
});
