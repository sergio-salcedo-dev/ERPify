import { readFileSync } from "node:fs";
import path from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

/**
 * Source maps are UPLOADED to Sentry; they are never PUBLISHED to the browser.
 *
 * Those two are one boolean apart, and nothing at runtime can tell them apart.
 * Without maps a prod stack trace is bundle offsets, which is why upload exists;
 * with maps left in the build output, `/_next/static/**\/*.js.map` is a public
 * URL and any visitor who opens devtools gets the entire client source — every
 * identifier, every comment, every commented-out branch. The build succeeds
 * identically either way, no test exercises a production bundle, and the
 * standalone image would ship it silently.
 *
 * Three invariants, and none implies another:
 *
 *  1. `next.config.ts` sets `deleteSourcemapsAfterUpload: true`. This one is a
 *     PIN, not a switch, and saying otherwise would overstate it: the option
 *     already defaults to `true` in @sentry/nextjs (verified against 10.70.0's
 *     own type docs). Writing it makes a future default flip, or a casual
 *     removal, a visible change instead of a silent republish.
 *  2. `filesToDeleteAfterUpload` is ABSENT. This is the invariant that actually
 *     bites, and it is the hole the first version of this gate left open: the
 *     option **overrides** `deleteSourcemapsAfterUpload` outright, so a narrow
 *     glob there deletes only what it names and serves everything else — with
 *     assertion (1) still green. Adding it is a decision that has to be argued
 *     at review, not a config tweak.
 *  3. `Dockerfile` takes the auth token as a BuildKit **secret**, never as an
 *     `ARG`. An `ARG` is recorded in the image metadata and printed by
 *     `docker history`, so a token passed that way leaks to anyone who can pull
 *     the image — a different failure from (1) and (2), and a worse one, because
 *     it grants WRITE access to the Sentry project rather than read access to
 *     the source.
 *
 * The config half is read with the TypeScript AST rather than a regex: the
 * literal `deleteSourcemapsAfterUpload: true` is equally easy to find in a
 * comment explaining why it matters — this very file's header contains the
 * string — and a comment is trivia attached to a token, not a node the walk
 * visits. The Dockerfile half IS text, and that is not a compromise: a
 * Dockerfile is line-oriented by specification, with no expression grammar for
 * an `ARG` name to hide inside.
 *
 * A green proves the two declarations are what they claim. It proves nothing
 * about what a build actually emits — no build runs here — nor about a map
 * served by something other than Next (a CDN copy, an artifact upload in a
 * workflow); those are deployment surfaces this file cannot see.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const NEXT_CONFIG = path.join(PWA_ROOT, "next.config.ts");
const DOCKERFILE = path.join(PWA_ROOT, "Dockerfile");
const TOKEN_VAR = "SENTRY_AUTH_TOKEN";
const DELETE_AFTER_UPLOAD = "deleteSourcemapsAfterUpload";
const FILES_TO_DELETE = "filesToDeleteAfterUpload";

/** Every `<name>: <initializer>` property assignment in the file, from the AST. */
function propertyAssignments(file: string): Map<string, ts.Expression> {
  const source = ts.createSourceFile(
    file,
    readFileSync(file, "utf8"),
    ts.ScriptTarget.Latest,
    true,
  );
  const found = new Map<string, ts.Expression>();
  const visit = (node: ts.Node): void => {
    if (ts.isPropertyAssignment(node) && ts.isIdentifier(node.name)) {
      found.set(node.name.text, node.initializer);
    }
    ts.forEachChild(node, visit);
  };
  visit(source);
  return found;
}

/** Dockerfile instructions, continuations joined, comments and blanks dropped. */
function dockerInstructions(): string[] {
  const joined = readFileSync(DOCKERFILE, "utf8").replace(/\\\r?\n\s*/g, " ");
  return joined
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line.length > 0 && !line.startsWith("#"));
}

describe("Sentry source maps are uploaded, never published", () => {
  it("deletes the maps from the build output after uploading them", () => {
    const initializer = propertyAssignments(NEXT_CONFIG).get(DELETE_AFTER_UPLOAD);

    expect(
      initializer?.kind,
      `next.config.ts must set ${DELETE_AFTER_UPLOAD}: true — without it every ` +
        "built .js.map is served at a public /_next/static URL.",
    ).toBe(ts.SyntaxKind.TrueKeyword);
  });

  it("declares no glob that would override the deletion", () => {
    const assignments = propertyAssignments(NEXT_CONFIG);

    expect(
      assignments.has(FILES_TO_DELETE),
      `next.config.ts must not set ${FILES_TO_DELETE}: it OVERRIDES ` +
        `${DELETE_AFTER_UPLOAD}, so a narrow glob republishes every map it does ` +
        "not name while the deletion flag still reads true.",
    ).toBe(false);
  });

  it("never takes the auth token as a build ARG", () => {
    const args = dockerInstructions()
      .filter((line) => /^ARG\s/i.test(line))
      .map((line) =>
        line
          .replace(/^ARG\s+/i, "")
          .split("=")[0]
          .trim(),
      );

    expect(
      args,
      `${TOKEN_VAR} must not be an ARG: \`docker history\` prints build args, so ` +
        "the token would leak to anyone who can pull the image.",
    ).not.toContain(TOKEN_VAR);
  });

  it("passes the auth token to the build through a secret mount", () => {
    const buildStep = dockerInstructions().find(
      (line) => /^RUN\s/i.test(line) && line.includes("npm run build"),
    );

    expect(buildStep, "the Dockerfile must still run `npm run build`").toBeDefined();
    expect(buildStep, `the build step must mount ${TOKEN_VAR} as a BuildKit secret`).toMatch(
      /--mount=type=secret,id=sentry_auth_token/,
    );
    expect(buildStep).toContain(TOKEN_VAR);
  });

  it("keeps the token server-only — it never carries the public prefix", () => {
    const sources = [readFileSync(NEXT_CONFIG, "utf8"), readFileSync(DOCKERFILE, "utf8")];

    for (const source of sources) {
      expect(source).not.toContain(`NEXT_PUBLIC_${TOKEN_VAR}`);
    }
  });
});
