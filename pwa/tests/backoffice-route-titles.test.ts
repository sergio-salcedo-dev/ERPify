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
 * A green proves each route resolves a title. It proves nothing about that title being
 * meaningful, distinct from its neighbour's, or matching what the page shows — two routes
 * sharing a string announce nothing when you move between them, and only review sees that.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const BACKOFFICE_ROOT = path.join(PWA_ROOT, "src", "app", "backoffice");
const TITLE_RE = /title:\s*["'`]/;
const METADATA_RE = /export\s+(?:const\s+metadata|async\s+function\s+generateMetadata)/;
const CLIENT_DIRECTIVE_RE = /^\s*["']use client["']/;

function declaresTitle(file: string): boolean {
  if (!existsSync(file)) return false;
  const source = readFileSync(file, "utf8");
  const metadata = source.search(METADATA_RE);
  return metadata !== -1 && TITLE_RE.test(source.slice(metadata));
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
});
