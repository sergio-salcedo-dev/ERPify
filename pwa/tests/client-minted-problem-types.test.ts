import { readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

/**
 * `ProblemDetails.type` has two minters and nothing said so.
 *
 * The API mints one per domain failure; the client mints three of its own, for failures that never
 * reached a server — `network-error`, `request-timeout`, `malformed-response-envelope`. Neither
 * `docs/api-error-contract.md` nor any registry decided who may mint what, which is fine while the
 * two sets happen not to overlap and stops being fine the moment they do: a 408/504 marker claiming
 * `request-timeout` is a plausible next addition on the API side, and at that point a client-minted
 * and a server-minted problem become indistinguishable to `data-problem-type` selectors and to the
 * four `switch (problem.type)` sites under `src/app/backoffice/**`. The recovery a caller offers
 * would then be chosen from a type that means two different things.
 *
 * The convention this pins is documented in `docs/api-error-contract.md`: the client owns exactly
 * the names declared in `HttpClient.ts`, and the API mints none of them.
 *
 * It lives on the PWA side because the subject is the PWA's namespace — the three constants and the
 * gallery that exhibits them. Two costs of that home, stated rather than hidden: an API developer
 * adding a colliding `type:` sees this in CI and not in `make php.quality`, and this file is the
 * one thing making the PWA suite depend on a sibling tree — a checkout with no `api/src` fails
 * here rather than skipping. Today every sanctioned path runs the PWA targets from the repo root
 * (`make/pwa.mk`), so that dependency is satisfied; a standalone `pwa/` checkout is not a
 * supported configuration and this row is where it would first say so.
 *
 * The rule it enforces on `HttpClient.ts` is stronger than a completeness check and is meant to
 * be: EVERY exported string constant in that module must be a minted problem type. The module has
 * no other string exports today, and the alternative — narrowing the universe by naming
 * convention — would let a non-type sit next to the types with nothing saying which is which. If a
 * genuine non-type string export is ever needed there, move it rather than giving it the doc
 * marker: the marker enrols a name into the API collision check and the gallery-exemplar check,
 * so marking a non-type forces a bogus gallery entry.
 *
 * A green proves three things and no more: every exported constant in `HttpClient.ts` is declared as
 * a client-minted type, none of those names is minted in `api/src`, and each has an exemplar in the
 * gallery. It says nothing about a type the API builds dynamically or reaches through a constant
 * rather than writing as a literal in type position, and nothing about whether a name is a good one.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const REPO_ROOT = path.resolve(PWA_ROOT, "..");
const HTTP_CLIENT = path.join(PWA_ROOT, "src/context/shared/http-client/domain/HttpClient.ts");
const ERROR_GALLERY = path.join(PWA_ROOT, "src/app/backoffice/dev-tools/error-gallery/page.tsx");
const API_SRC = path.join(REPO_ROOT, "api/src");

/** `export const NAME = "value";` — every exported string constant the module declares. */
const EXPORTED_STRING_CONST = /export const (\w+) = "([^"]+)";/g;

/**
 * The same declaration, but only where the doc comment above it says the constant IS a minted
 * `ProblemDetails.type`. The marker is the doc comment on purpose: it is the thing a reader of that
 * file already relies on, so a constant added without one is caught by the completeness check below
 * rather than being quietly excluded from every other assertion here.
 */
const MINTED_TYPE_CONST =
  /\/\*\*(?:(?!\*\/)[\s\S])*?ProblemDetails\.type(?:(?!\*\/)[\s\S])*?\*\/\s*export const (\w+) = "([^"]+)";/g;

function matchAll(source: string, pattern: RegExp): Array<[string, string]> {
  return [...source.matchAll(pattern)].map((m) => [m[1], m[2]] as [string, string]);
}

function* walkPhp(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry);
    if (statSync(full).isDirectory()) {
      yield* walkPhp(full);
      continue;
    }
    if (full.endsWith(".php")) yield full;
  }
}

const httpClientSource = readFileSync(HTTP_CLIENT, "utf8");
const declared = matchAll(httpClientSource, EXPORTED_STRING_CONST);
const minted = matchAll(httpClientSource, MINTED_TYPE_CONST);

describe("the client-minted ProblemDetails.type namespace", () => {
  it("is not empty, so every assertion below is about something", () => {
    // Without this the whole file passes vacuously the day the constants are renamed or moved.
    expect(minted.length).toBeGreaterThanOrEqual(3);
  });

  it("declares every exported constant as a minted type", () => {
    const unmarked = declared
      .filter(([name]) => !minted.some(([mintedName]) => mintedName === name))
      .map(([name]) => name);

    // A constant added without the marker would be invisible to the collision check below, which
    // is the direction this gate would otherwise fail open in.
    expect(unmarked).toEqual([]);
  });

  it("collides with no type the API mints", () => {
    const names = minted.map(([, value]) => value);
    // The QUOTED LITERAL anywhere under `api/src`, not the literal in type position. Matching the
    // position was the first attempt and it was wrong in the way this repository keeps rediscovering:
    // `type: 'x'` is only how a `DomainException` subclass spells it, while `ProblemDetailsFactory`
    // spells its defaults `Marker::class => 'x'` and `Response::HTTP_… => 'x'` — measured, a planted
    // `request-timeout` in the second family passed a position-matching check with every row green.
    // Over-matching is the safe direction here: the names are distinctive, so a hit is either a real
    // collision or a mention worth a reviewer's eye, and neither should be silent.
    // The haystack is read as UTF-8 and the needles are asserted ASCII, rather than reading the
    // haystack as latin1 and asserting nothing: a latin1 haystack with a UTF-8 needle silently
    // never matches, so a future minted name carrying a non-ASCII byte would report "no collision"
    // while colliding. (An earlier cut of this file justified latin1 with "one file under api/src
    // is not valid UTF-8" — measured over all 598 of them with a fatal decoder, there is no such
    // file.)
    for (const value of names) {
      expect(value).toMatch(/^[\x20-\x7e]+$/);
    }

    let walked = 0;
    const collisions: string[] = [];
    for (const file of walkPhp(API_SRC)) {
      walked += 1;
      const source = readFileSync(file, "utf8");
      for (const value of names) {
        if (source.includes(`'${value}'`) || source.includes(`"${value}"`)) {
          collisions.push(`${path.relative(REPO_ROOT, file)} names "${value}"`);
        }
      }
    }

    // The floor. Without it a directory that exists but holds no `.php` — an `api/` skeleton, a
    // sparse checkout, a future move of `api/src` — walks nothing and the row is green having
    // proved nothing. A MISSING directory already throws, which is the other half.
    expect(walked).toBeGreaterThan(100);
    expect(collisions).toEqual([]);
  });

  it("has an exemplar in the error gallery for each name", () => {
    // The gallery calls itself a "visual inventory of every PWA error surface" and that claim was
    // false twice over — `request-timeout` and `malformed-response-envelope` were both absent, and
    // nothing said so. Only the client-minted half of the claim is checkable here.
    const gallery = readFileSync(ERROR_GALLERY, "utf8");
    const missing = minted
      .map(([, value]) => value)
      .filter((value) => !gallery.includes(`type: "${value}"`));

    expect(missing).toEqual([]);
  });
});
