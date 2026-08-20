import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

/**
 * Every back-office route must resolve a document title of its own.
 *
 * Next's route announcer (`app-router-announcer.js`) reads `document.title` on every tree change
 * and speaks **only when it changes**. Its `<h1>` fallback runs when `document.title` is falsy,
 * and `app/layout.tsx` fixes a static one — so on this tree that fallback is dead code. A
 * back-office route that declares no title of its own therefore inherits the root title, the
 * title never changes between routes, and the navigation is **never announced**. That was the
 * state of 46 routes, and nothing said so: no gate, no lint rule, no failing test.
 *
 * A title is resolved from the page's own `metadata` or from the nearest layout above it, which
 * is how a Client Component page declares one (`metadata` is server-only). The segment root
 * `app/backoffice/layout.tsx` is asserted NOT to carry a title: one there would satisfy every
 * route by inheritance and reinstate exactly the silence this gate exists to refuse.
 *
 * Distinctness is asserted, not assumed. Two routes resolving the same string announce nothing
 * when you move between them — the same silence, one level down — and this gate shipped stating
 * that as a known limit until an independent review found three live collisions in the tree it
 * had just passed. "Only review sees that" was the defect, so the gate sees it now.
 *
 * A green proves each route resolves a distinct title. It proves nothing about that title being
 * meaningful or matching what the page shows.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const BACKOFFICE_ROOT = path.join(PWA_ROOT, "src", "app", "backoffice");
const TITLE_RE = /(?:^|[{,\s])title:\s*(["'`])((?:[^\\]|\\.)*?)\1/;
const METADATA_RE = /export\s+const\s+metadata/;
const DYNAMIC_METADATA_RE = /export\s+async\s+function\s+generateMetadata/;
const CLIENT_DIRECTIVE_RE = /^\s*["']use client["']/m;

/**
 * The `metadata` object literal, bounded by brace balance rather than read to end of file.
 *
 * Searching the whole remainder for `title:` accepts one belonging to any later data literal —
 * a shape this tree already holds — so a route declaring `metadata` without a title passed while
 * resolving none.
 */
function metadataBlock(source: string): string | null {
  const start = source.search(METADATA_RE);
  if (start === -1) return null;
  const open = source.indexOf("{", start);
  if (open === -1) return null;

  let depth = 0;
  let quote = "";
  for (let i = open; i < source.length; i++) {
    const char = source[i];
    if (quote) {
      if (char === "\\") i++;
      else if (char === quote) quote = "";
    } else if (char === '"' || char === "'" || char === "`") quote = char;
    else if (char === "{") depth++;
    else if (char === "}" && --depth === 0) return source.slice(open, i + 1);
  }
  return null;
}

function titleOf(file: string): string | null {
  if (!existsSync(file)) return null;
  const block = metadataBlock(readFileSync(file, "utf8"));
  return block ? (TITLE_RE.exec(block)?.[2] ?? null) : null;
}

function declaresTitle(file: string): boolean {
  return titleOf(file) !== null;
}

/** The title a route actually resolves: its own, else the nearest layout's above it. */
function resolvedTitle(page: string): string | null {
  for (const source of titleSources(page)) {
    const title = titleOf(source);
    if (title !== null) return title;
  }
  return null;
}

function isClientComponent(file: string): boolean {
  return CLIENT_DIRECTIVE_RE.test(readFileSync(file, "utf8"));
}

function* routes(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry);
    if (statSync(full).isDirectory()) {
      yield* routes(full);
    } else if (entry === "page.tsx") {
      yield full;
    }
  }
}

/** The page itself, then every layout from its own segment up to `backoffice/` inclusive. */
function titleSources(page: string): string[] {
  const sources = [page];
  let dir = path.dirname(page);
  for (;;) {
    sources.push(path.join(dir, "layout.tsx"));
    if (dir === BACKOFFICE_ROOT) return sources;
    dir = path.dirname(dir);
  }
}

function routeOf(page: string): string {
  const segment = path.relative(BACKOFFICE_ROOT, path.dirname(page)).replaceAll(path.sep, "/");
  return `/backoffice${segment ? `/${segment}` : ""}`;
}

describe("back-office route titles", () => {
  const pages = [...routes(BACKOFFICE_ROOT)].sort();

  it("finds the routes it claims to check", () => {
    // Without this, a walk that resolves nothing passes the assertion below on an empty set.
    expect(pages.length).toBeGreaterThan(50);
  });

  it("resolves a title for every route, from the page or a layout above it", () => {
    const unnamed = pages.filter((page) => !titleSources(page).some(declaresTitle)).map(routeOf);
    expect(unnamed).toEqual([]);
  });

  it("never puts the title on a Client Component page, where it is not applied", () => {
    // `metadata` is server-only. Next fails the build on this, so the cost is a broken build
    // rather than a silent miss — but the gate above would be green on the way there, and the
    // repair is the layout seam, which is what this names.
    const misplaced = pages
      .filter((page) => isClientComponent(page) && declaresTitle(page))
      .map(routeOf);
    expect(misplaced).toEqual([]);
  });

  it("keeps the back-office segment layout title-free — inheritance is the silence", () => {
    expect(declaresTitle(path.join(BACKOFFICE_ROOT, "layout.tsx"))).toBe(false);
  });

  it("gives no two routes the same title — an unchanged title is an unannounced navigation", () => {
    const byTitle = new Map<string, string[]>();
    for (const page of pages) {
      const title = resolvedTitle(page);
      if (title === null) continue;
      byTitle.set(title, [...(byTitle.get(title) ?? []), routeOf(page)]);
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
      .map(routeOf);
    expect(dynamic).toEqual([]);
  });
});
