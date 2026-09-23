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
 * Four invariants, and none implies another:
 *
 *  1. The `sourcemaps` object passed to `withSentryConfig` sets
 *     `deleteSourcemapsAfterUpload: true`. This one is a PIN, not a switch: the
 *     SDK already defaults it to `true` when it turns client maps on for a
 *     Turbopack build. Writing it makes a changed default, or a casual removal,
 *     a visible change instead of a silent republish. It is read from THAT
 *     object and no other — the same literal anywhere else in the file proves
 *     nothing about what the SDK receives.
 *  2. `filesToDeleteAfterUpload` is ABSENT. This is the invariant that actually
 *     bites: the option **overrides** `deleteSourcemapsAfterUpload` outright, so
 *     a narrow glob there deletes only what it names and serves everything else
 *     — with (1) still green. It is refused under any key spelling (identifier,
 *     string, computed, shorthand) anywhere in the file, and the `sourcemaps`
 *     object may hold no spread, because a spread is where it would hide.
 *  3. `productionBrowserSourceMaps` is absent or `false`. The SDK sets it to
 *     `true` itself only while upload is on, which is exactly when the deletion
 *     runs; set to `true` by hand it generates client maps in a build with
 *     upload OFF, where nothing deletes them — (1) and (2) green, every map
 *     served.
 *  4. `Dockerfile` takes the auth token as a BuildKit **secret**, never through
 *     an `ARG` or an `ENV`. An `ARG` is recorded in the image metadata and
 *     printed by `docker history`; an `ENV` is baked into the image config and
 *     printed by `docker inspect`. Either way the token leaks to anyone who can
 *     pull the image — a worse failure than (1)–(3), because it grants WRITE
 *     access to the Sentry project rather than read access to the source. The
 *     token name is refused anywhere in either instruction, whichever of the
 *     names on the line it is.
 *
 * Every rule is a pure function over source text, run once against the real
 * file and once against synthetic fixtures it must reject: a detector that
 * finds nothing and one that cannot find anything look the same until a
 * fixture tells them apart.
 *
 * The config half is read with the TypeScript AST rather than a regex: the
 * literal `deleteSourcemapsAfterUpload: true` is equally easy to find in a
 * comment explaining why it matters — this very file's header contains the
 * string — and a comment is trivia attached to a token, not a node the walk
 * visits. The Dockerfile half IS text, and that is not a compromise: a
 * Dockerfile is line-oriented by specification, with no expression grammar for
 * an `ARG` name to hide inside.
 *
 * A green proves the declarations are what they claim. It proves nothing about
 * what a build actually emits — no build runs here — nor about a map served by
 * something other than Next (a CDN copy, an artifact upload in a workflow).
 * Blind spots of the rules themselves: a top-level spread of a variable into
 * the `withSentryConfig` options (only literal spreads are read), a
 * `productionBrowserSourceMaps` set through anything but a property or a direct
 * assignment, and a Dockerfile whose `# escape=` directive changes the
 * continuation character or that sets the token in a heredoc.
 *
 * Server maps are outside these invariants on purpose: they are emitted into
 * `.next/server` on every production build, the SDK does not delete them, and
 * Next never serves that directory — see the comment above `withSentryConfig`
 * in `next.config.ts`.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const NEXT_CONFIG = path.join(PWA_ROOT, "next.config.ts");
const DOCKERFILE = path.join(PWA_ROOT, "Dockerfile");
const TOKEN_VAR = "SENTRY_AUTH_TOKEN";
const WITH_SENTRY_CONFIG = "withSentryConfig";
const SOURCEMAPS = "sourcemaps";
const DELETE_AFTER_UPLOAD = "deleteSourcemapsAfterUpload";
const FILES_TO_DELETE = "filesToDeleteAfterUpload";
const BROWSER_SOURCE_MAPS = "productionBrowserSourceMaps";

function parse(source: string): ts.SourceFile {
  return ts.createSourceFile("next.config.ts", source, ts.ScriptTarget.Latest, true);
}

/** A property's key as written — identifier, string, number or literal computed key. */
function keyName(name: ts.PropertyName | undefined): string | undefined {
  if (name === undefined) return undefined;
  if (ts.isIdentifier(name) || ts.isStringLiteralLike(name) || ts.isNumericLiteral(name)) {
    return name.text;
  }
  if (ts.isComputedPropertyName(name) && ts.isStringLiteralLike(name.expression)) {
    return name.expression.text;
  }
  return undefined;
}

function walk(node: ts.Node, visit: (node: ts.Node) => void): void {
  visit(node);
  ts.forEachChild(node, (child) => walk(child, visit));
}

/** Every property-like member of an object literal with the given key, whatever its spelling. */
function membersNamed(
  object: ts.ObjectLiteralExpression,
  name: string,
): ts.ObjectLiteralElementLike[] {
  return object.properties.filter((member) => keyName(member.name) === name);
}

/** The options object literal passed as the second argument of the single `withSentryConfig` call. */
function sentryOptions(file: ts.SourceFile): ts.ObjectLiteralExpression | string {
  const calls: ts.CallExpression[] = [];
  walk(file, (node) => {
    if (
      ts.isCallExpression(node) &&
      ts.isIdentifier(node.expression) &&
      node.expression.text === WITH_SENTRY_CONFIG
    ) {
      calls.push(node);
    }
  });
  if (calls.length !== 1) {
    return `expected exactly one ${WITH_SENTRY_CONFIG}(…) call, found ${calls.length}`;
  }
  const options = calls[0].arguments[1];
  if (options === undefined || !ts.isObjectLiteralExpression(options)) {
    return `the second argument of ${WITH_SENTRY_CONFIG}(…) must be an object literal`;
  }
  return options;
}

/** Violations of invariants 1 and 2 in a `next.config.ts` source. */
function sourcemapDeletionViolations(source: string): string[] {
  const file = parse(source);
  const violations: string[] = [];

  walk(file, (node) => {
    const name =
      ts.isPropertyAssignment(node) ||
      ts.isShorthandPropertyAssignment(node) ||
      ts.isMethodDeclaration(node) ||
      ts.isPropertyAccessExpression(node)
        ? keyName(node.name)
        : undefined;
    if (name === FILES_TO_DELETE) {
      violations.push(
        `${FILES_TO_DELETE} must not appear: it OVERRIDES ${DELETE_AFTER_UPLOAD}, so a ` +
          "narrow glob republishes every map it does not name while the flag still reads true.",
      );
    }
  });

  const options = sentryOptions(file);
  if (typeof options === "string") return [...violations, options];

  const sourcemapMembers: ts.Node[] = [];
  walk(options, (node) => {
    if (ts.isObjectLiteralElementLike(node) && keyName(node.name) === SOURCEMAPS) {
      sourcemapMembers.push(node);
    }
  });
  const sourcemaps = sourcemapMembers[0];
  if (
    sourcemapMembers.length !== 1 ||
    sourcemaps.parent !== options ||
    !ts.isPropertyAssignment(sourcemaps) ||
    !ts.isObjectLiteralExpression(sourcemaps.initializer)
  ) {
    return [
      ...violations,
      `${WITH_SENTRY_CONFIG}(…) options must hold exactly one direct \`${SOURCEMAPS}: { … }\` object literal`,
    ];
  }
  const sourcemapsObject = sourcemaps.initializer;

  if (sourcemapsObject.properties.some(ts.isSpreadAssignment)) {
    violations.push(
      `the ${SOURCEMAPS} object must hold no spread: it is where ${FILES_TO_DELETE} would hide`,
    );
  }
  const deletion = membersNamed(sourcemapsObject, DELETE_AFTER_UPLOAD);
  if (
    deletion.length !== 1 ||
    !ts.isPropertyAssignment(deletion[0]) ||
    deletion[0].initializer.kind !== ts.SyntaxKind.TrueKeyword
  ) {
    violations.push(
      `${SOURCEMAPS}.${DELETE_AFTER_UPLOAD} must be set exactly once to \`true\` — without it every ` +
        "built .js.map is served at a public /_next/static URL.",
    );
  }
  return violations;
}

/** Violations of invariant 3 in a `next.config.ts` source. */
function browserSourceMapViolations(source: string): string[] {
  const violations: string[] = [];
  const refuse = (value: ts.Expression | undefined): void => {
    if (value?.kind !== ts.SyntaxKind.FalseKeyword) {
      violations.push(
        `${BROWSER_SOURCE_MAPS} must be absent or \`false\`: set otherwise it generates client ` +
          "maps in a build with upload off, where nothing deletes them.",
      );
    }
  };
  walk(parse(source), (node) => {
    if (ts.isPropertyAssignment(node) && keyName(node.name) === BROWSER_SOURCE_MAPS) {
      refuse(node.initializer);
    } else if (ts.isShorthandPropertyAssignment(node) && node.name.text === BROWSER_SOURCE_MAPS) {
      refuse(undefined);
    } else if (
      ts.isBinaryExpression(node) &&
      node.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
      ((ts.isPropertyAccessExpression(node.left) && node.left.name.text === BROWSER_SOURCE_MAPS) ||
        (ts.isElementAccessExpression(node.left) &&
          ts.isStringLiteralLike(node.left.argumentExpression) &&
          node.left.argumentExpression.text === BROWSER_SOURCE_MAPS))
    ) {
      refuse(node.right);
    }
  });
  return violations;
}

/** Dockerfile instructions, continuations joined, comments and blanks dropped. */
function dockerInstructions(dockerfile: string): string[] {
  return dockerfile
    .replace(/\\\r?\n\s*/g, " ")
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line.length > 0 && !line.startsWith("#"));
}

/** Violations of invariant 4 in a Dockerfile source. */
function tokenInImageViolations(dockerfile: string): string[] {
  return dockerInstructions(dockerfile)
    .filter((line) => /^(ARG|ENV)\s/i.test(line) && line.includes(TOKEN_VAR))
    .map(
      (line) =>
        `${TOKEN_VAR} must not reach an ARG or ENV (\`${line}\`): \`docker history\` prints ` +
        "build args and `docker inspect` prints env, so the token leaks to anyone who can pull the image.",
    );
}

describe("Sentry source maps are uploaded, never published", () => {
  const nextConfig = readFileSync(NEXT_CONFIG, "utf8");
  const dockerfile = readFileSync(DOCKERFILE, "utf8");

  it("deletes the maps after upload, from the options the SDK receives, and nothing overrides it", () => {
    expect(sourcemapDeletionViolations(nextConfig)).toEqual([]);
  });

  it("never turns browser source maps on by hand", () => {
    expect(browserSourceMapViolations(nextConfig)).toEqual([]);
  });

  it("never takes the auth token as a build ARG or an image ENV", () => {
    expect(tokenInImageViolations(dockerfile)).toEqual([]);
  });

  it("passes the auth token to the build through a secret mount", () => {
    const buildStep = dockerInstructions(dockerfile).find(
      (line) => /^RUN\s/i.test(line) && line.includes("npm run build"),
    );

    expect(buildStep, "the Dockerfile must still run `npm run build`").toBeDefined();
    expect(buildStep, `the build step must mount ${TOKEN_VAR} as a BuildKit secret`).toMatch(
      /--mount=type=secret,id=sentry_auth_token/,
    );
    expect(buildStep).toContain(TOKEN_VAR);
  });

  it("keeps the token server-only — it never carries the public prefix", () => {
    for (const source of [nextConfig, dockerfile]) {
      expect(source).not.toContain(`NEXT_PUBLIC_${TOKEN_VAR}`);
    }
  });
});

describe("the source-map rules reject what they exist to reject", () => {
  const config = (options: string, nextConfig = "{}"): string => `
    import { withSentryConfig } from "@sentry/nextjs";
    const nextConfig = ${nextConfig};
    export default withSentryConfig(nextConfig, ${options});
  `;
  const PINNED = `{ sourcemaps: { disable: false, deleteSourcemapsAfterUpload: true } }`;

  it("accepts the pinned shape", () => {
    expect(sourcemapDeletionViolations(config(PINNED))).toEqual([]);
    expect(browserSourceMapViolations(config(PINNED))).toEqual([]);
  });

  it("reads the flag from the sourcemaps object, not from anywhere in the file", () => {
    const source = `
      const unrelated = { deleteSourcemapsAfterUpload: true };
      ${config(`{ sourcemaps: { deleteSourcemapsAfterUpload: false } }`)}
    `;
    expect(sourcemapDeletionViolations(source)).toHaveLength(1);
  });

  it("refuses a missing flag and a missing sourcemaps object", () => {
    expect(sourcemapDeletionViolations(config(`{ sourcemaps: { disable: false } }`))).toHaveLength(
      1,
    );
    expect(sourcemapDeletionViolations(config(`{ silent: false }`))).toHaveLength(1);
  });

  it.each([
    ["an identifier key", `filesToDeleteAfterUpload: [".next/static/chunks/*.map"]`],
    ["a string key", `"filesToDeleteAfterUpload": [".next/static/chunks/*.map"]`],
    ["a computed key", `["filesToDeleteAfterUpload"]: [".next/static/chunks/*.map"]`],
    ["a shorthand", `filesToDeleteAfterUpload`],
  ])("refuses filesToDeleteAfterUpload spelled with %s", (_label, member) => {
    const source = config(`{ sourcemaps: { deleteSourcemapsAfterUpload: true, ${member} } }`);
    expect(sourcemapDeletionViolations(source).join("\n")).toContain(FILES_TO_DELETE);
  });

  it("refuses a spread inside the sourcemaps object, where the override would hide", () => {
    const source = `
      const extra = someImportedOptions;
      ${config(`{ sourcemaps: { deleteSourcemapsAfterUpload: true, ...extra } }`)}
    `;
    expect(sourcemapDeletionViolations(source).join("\n")).toContain("no spread");
  });

  it("refuses a second sourcemaps object smuggled in through a spread", () => {
    const source = config(
      `{ sourcemaps: { deleteSourcemapsAfterUpload: true }, ...({ sourcemaps: { disable: false } }) }`,
    );
    expect(sourcemapDeletionViolations(source)).toHaveLength(1);
  });

  it.each([
    ["absent", "{}"],
    ["false", "{ productionBrowserSourceMaps: false }"],
  ])("accepts productionBrowserSourceMaps %s", (_label, nextConfig) => {
    expect(browserSourceMapViolations(config(PINNED, nextConfig))).toEqual([]);
  });

  it.each([
    ["true", "{ productionBrowserSourceMaps: true }"],
    ["a string key", `{ "productionBrowserSourceMaps": true }`],
    ["a non-literal", "{ productionBrowserSourceMaps: Boolean(process.env.MAPS) }"],
  ])("refuses productionBrowserSourceMaps set to %s", (_label, nextConfig) => {
    expect(browserSourceMapViolations(config(PINNED, nextConfig))).toHaveLength(1);
  });

  it("refuses productionBrowserSourceMaps assigned after the object is built", () => {
    const source = `${config(PINNED)}\nnextConfig.productionBrowserSourceMaps = true;`;
    expect(browserSourceMapViolations(source)).toHaveLength(1);
  });
});

describe("the Dockerfile rule rejects the token in every image-visible instruction", () => {
  const SECRET_MOUNT = `RUN --mount=type=secret,id=sentry_auth_token \\
    SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token)" npm run build`;

  it("accepts the token confined to a secret-mounted RUN", () => {
    expect(tokenInImageViolations(`FROM node\nARG SENTRY_ORG=\n${SECRET_MOUNT}\n`)).toEqual([]);
  });

  it.each([
    ["a lone ARG", "ARG SENTRY_AUTH_TOKEN"],
    ["an ARG with a default", "ARG SENTRY_AUTH_TOKEN=changeme"],
    ["the second name of a multi-name ARG", "ARG SENTRY_ORG SENTRY_AUTH_TOKEN"],
    ["a lower-case ARG", "arg SENTRY_AUTH_TOKEN"],
    ["an ENV in key=value form", "ENV SENTRY_AUTH_TOKEN=sntrys_x"],
    ["an ENV in legacy space form", "ENV SENTRY_AUTH_TOKEN sntrys_x"],
    ["the second pair of a multi-pair ENV", "ENV A=1 SENTRY_AUTH_TOKEN=sntrys_x"],
    ["an ENV continued onto a second line", "ENV A=1 \\\n    SENTRY_AUTH_TOKEN=sntrys_x"],
  ])("refuses %s", (_label, instruction) => {
    expect(tokenInImageViolations(`FROM node\n${instruction}\n${SECRET_MOUNT}\n`)).toHaveLength(1);
  });

  it("does not mistake a comment for an instruction", () => {
    expect(tokenInImageViolations(`FROM node\n# ARG SENTRY_AUTH_TOKEN is forbidden\n`)).toEqual([]);
  });
});
