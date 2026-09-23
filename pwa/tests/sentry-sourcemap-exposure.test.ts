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
 * Six invariants, and none implies another:
 *
 *  1. The `sourcemaps` object passed to `withSentryConfig` sets
 *     `deleteSourcemapsAfterUpload: true`. This one is a PIN, not a switch: the
 *     SDK already defaults it to `true` when it turns client maps on for a
 *     Turbopack build. Writing it makes a changed default, or a casual removal,
 *     a visible change instead of a silent republish. It is read from THAT
 *     object and no other — the same literal anywhere else in the file proves
 *     nothing about what the SDK receives.
 *  2. Nothing overrides that deletion. `filesToDeleteAfterUpload` **overrides**
 *     `deleteSourcemapsAfterUpload` outright, so a narrow glob there deletes only
 *     what it names and serves everything else — with (1) still green.
 *     `unstable_sentryWebpackPluginOptions` does the same from further away: the
 *     SDK spreads its `sourcemaps` LAST over the block it built, replacing it
 *     whole, deletion glob included (top-level spelling too — the SDK migrates it
 *     under `webpack`). Both are refused under any key spelling (identifier,
 *     string, computed, shorthand, member access) anywhere in the file, and the
 *     `sourcemaps` object may hold no spread, because a spread is where they
 *     would hide.
 *  3. `productionBrowserSourceMaps` is absent or `false`. The SDK sets it to
 *     `true` itself only while upload is on, which is exactly when the deletion
 *     runs; set to `true` by hand it generates client maps in a build with
 *     upload OFF, where nothing deletes them — (1) and (2) green, every map
 *     served.
 *  4. `useRunAfterProductionCompileHook` is absent or `true`. The deletion runs
 *     in the SDK's post-compile hook and nowhere else under Turbopack, while the
 *     maps are switched on independently of it whenever `sourcemaps.disable` is
 *     false; with the hook off they are generated and never deleted — (1)–(3)
 *     green, every map served.
 *  5. `Dockerfile` takes the auth token as a BuildKit **secret**, never through
 *     an `ARG` or an `ENV` (an `ONBUILD` one included), and every `npm run
 *     build` step mounts it. An `ARG` is recorded in the image metadata and
 *     printed by `docker history`; an `ENV` is baked into the image config and
 *     printed by `docker inspect`. Either way the token leaks to anyone who can
 *     pull the image — a worse failure than (1)–(4), because it grants WRITE
 *     access to the Sentry project rather than read access to the source. The
 *     token name is refused anywhere in either instruction, whichever of the
 *     names on the line it is.
 *  6. `SENTRY_AUTH_TOKEN` is never an object KEY in `next.config.ts`. Reading
 *     `process.env.SENTRY_AUTH_TOKEN` is how the config learns the token; a key
 *     is how it would publish it, since Next's `env` option inlines every entry
 *     into the browser bundle as a literal.
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
 * The options object may be wrapped in `satisfies`, `as` or parentheses — the
 * typed spelling of the same literal — and is unwrapped before it is read.
 * Continuation lines of a Dockerfile instruction are joined the way BuildKit
 * joins them: a comment line inside a continuation is dropped, not joined into
 * the instruction, so a `#` line cannot hide the name on the line after it.
 *
 * A green proves the declarations are what they claim. It proves nothing about
 * what a build actually emits — no build runs here — nor about a map served by
 * something other than Next (a CDN copy, an artifact upload in a workflow).
 * Blind spots of the rules themselves: a top-level spread of a variable into
 * the `withSentryConfig` options (only literal spreads are read); a setting
 * reached through anything but a property or a direct assignment; a token fed
 * into the build line through a differently named `ARG` or `ENV` (the name is
 * matched, not the value's provenance); a Dockerfile whose `# escape=`
 * directive changes the continuation character or that sets the token in a
 * heredoc. A `withSentryConfig` imported under an alias or called through a
 * namespace is not matched — that fails CLOSED by design (zero calls found is
 * a violation), so the rule cannot pass over a call it cannot see.
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
const POST_COMPILE_HOOK = "useRunAfterProductionCompileHook";
const UNSTABLE_WEBPACK_OPTIONS = "unstable_sentryWebpackPluginOptions";
const SECRET_MOUNT = /--mount=type=secret,id=sentry_auth_token(?:[,\s]|$)/;

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

/** The expression under any `satisfies`, `as`, `<T>` or parentheses wrapping it. */
function unwrap(expression: ts.Expression): ts.Expression {
  let current = expression;
  while (
    ts.isParenthesizedExpression(current) ||
    ts.isAsExpression(current) ||
    ts.isSatisfiesExpression(current) ||
    ts.isTypeAssertionExpression(current)
  ) {
    current = current.expression;
  }
  return current;
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
  const argument = calls[0].arguments[1];
  const options = argument === undefined ? undefined : unwrap(argument);
  if (options === undefined || !ts.isObjectLiteralExpression(options)) {
    return `the second argument of ${WITH_SENTRY_CONFIG}(…) must be an object literal`;
  }
  return options;
}

/** The name an object member or a member access declares, whatever its spelling. */
function memberName(node: ts.Node, includeAccess: boolean): string | undefined {
  if (
    ts.isPropertyAssignment(node) ||
    ts.isShorthandPropertyAssignment(node) ||
    ts.isMethodDeclaration(node) ||
    (includeAccess && ts.isPropertyAccessExpression(node))
  ) {
    return keyName(node.name);
  }
  return undefined;
}

const DELETION_OVERRIDES: ReadonlyMap<string, string> = new Map([
  [
    FILES_TO_DELETE,
    `it OVERRIDES ${DELETE_AFTER_UPLOAD}, so a narrow glob republishes every map it does not ` +
      "name while the flag still reads true.",
  ],
  [
    UNSTABLE_WEBPACK_OPTIONS,
    "the SDK spreads its `sourcemaps` last over the block it built, so it replaces the " +
      "deletion glob wholesale.",
  ],
]);

/** Violations of invariants 1 and 2 in a `next.config.ts` source. */
function sourcemapDeletionViolations(source: string): string[] {
  const file = parse(source);
  const violations: string[] = [];

  walk(file, (node) => {
    const name = memberName(node, true);
    const reason = name === undefined ? undefined : DELETION_OVERRIDES.get(name);
    if (reason !== undefined) {
      violations.push(`${name} must not appear: ${reason}`);
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
    !ts.isObjectLiteralExpression(unwrap(sourcemaps.initializer))
  ) {
    return [
      ...violations,
      `${WITH_SENTRY_CONFIG}(…) options must hold exactly one direct \`${SOURCEMAPS}: { … }\` object literal`,
    ];
  }
  const sourcemapsObject = unwrap(sourcemaps.initializer) as ts.ObjectLiteralExpression;

  if (sourcemapsObject.properties.some(ts.isSpreadAssignment)) {
    violations.push(
      `the ${SOURCEMAPS} object must hold no spread: it is where ${FILES_TO_DELETE} would hide`,
    );
  }
  const deletion = membersNamed(sourcemapsObject, DELETE_AFTER_UPLOAD);
  if (
    deletion.length !== 1 ||
    !ts.isPropertyAssignment(deletion[0]) ||
    unwrap(deletion[0].initializer).kind !== ts.SyntaxKind.TrueKeyword
  ) {
    violations.push(
      `${SOURCEMAPS}.${DELETE_AFTER_UPLOAD} must be set exactly once to \`true\` — without it every ` +
        "built .js.map is served at a public /_next/static URL.",
    );
  }
  return violations;
}

/**
 * Every place a source sets `key` — a property of any spelling, a shorthand, or
 * a direct assignment — whose value is not the literal `allowed`. A shorthand
 * and a non-literal value are violations: neither can be read as the literal.
 */
function settingViolations(
  source: string,
  key: string,
  allowed: ts.SyntaxKind.TrueKeyword | ts.SyntaxKind.FalseKeyword,
  message: string,
): string[] {
  const violations: string[] = [];
  const refuse = (value: ts.Expression | undefined): void => {
    if (value === undefined || unwrap(value).kind !== allowed) {
      violations.push(message);
    }
  };
  walk(parse(source), (node) => {
    if (ts.isPropertyAssignment(node) && keyName(node.name) === key) {
      refuse(node.initializer);
    } else if (ts.isShorthandPropertyAssignment(node) && node.name.text === key) {
      refuse(undefined);
    } else if (
      ts.isBinaryExpression(node) &&
      node.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
      ((ts.isPropertyAccessExpression(node.left) && node.left.name.text === key) ||
        (ts.isElementAccessExpression(node.left) &&
          ts.isStringLiteralLike(node.left.argumentExpression) &&
          node.left.argumentExpression.text === key))
    ) {
      refuse(node.right);
    }
  });
  return violations;
}

/** Violations of invariant 3 in a `next.config.ts` source. */
function browserSourceMapViolations(source: string): string[] {
  return settingViolations(
    source,
    BROWSER_SOURCE_MAPS,
    ts.SyntaxKind.FalseKeyword,
    `${BROWSER_SOURCE_MAPS} must be absent or \`false\`: set otherwise it generates client ` +
      "maps in a build with upload off, where nothing deletes them.",
  );
}

/** Violations of invariant 4 in a `next.config.ts` source. */
function postCompileHookViolations(source: string): string[] {
  return settingViolations(
    source,
    POST_COMPILE_HOOK,
    ts.SyntaxKind.TrueKeyword,
    `${POST_COMPILE_HOOK} must be absent or \`true\`: the source-map deletion runs in that ` +
      "hook, so with it off client maps are generated and never deleted.",
  );
}

/** Violations of invariant 6 in a `next.config.ts` source. */
function tokenAsConfigKeyViolations(source: string): string[] {
  const violations: string[] = [];
  walk(parse(source), (node) => {
    if (memberName(node, false) === TOKEN_VAR) {
      violations.push(
        `${TOKEN_VAR} must not be an object key in next.config: Next's \`env\` option inlines ` +
          "every entry into the browser bundle, which would publish a write credential.",
      );
    }
  });
  return violations;
}

/**
 * Dockerfile instructions, continuations joined, comments and blanks dropped.
 * A comment or blank line inside a continuation is skipped rather than joined,
 * which is what BuildKit does: the instruction goes on at the next real line.
 */
function dockerInstructions(dockerfile: string): string[] {
  const instructions: string[] = [];
  let pending: string[] | undefined;
  for (const raw of dockerfile.split(/\r?\n/)) {
    const line = raw.trim();
    if (line.length === 0 || line.startsWith("#")) continue;
    const continued = line.endsWith("\\");
    const part = continued ? line.slice(0, -1).trim() : line;
    pending = [...(pending ?? []), part];
    if (!continued) {
      instructions.push(pending.join(" "));
      pending = undefined;
    }
  }
  if (pending !== undefined) instructions.push(pending.join(" "));
  return instructions;
}

/** Violations of invariant 5's image half in a Dockerfile source. */
function tokenInImageViolations(dockerfile: string): string[] {
  return dockerInstructions(dockerfile)
    .filter((line) => /^(ONBUILD\s+)?(ARG|ENV)\s/i.test(line) && line.includes(TOKEN_VAR))
    .map(
      (line) =>
        `${TOKEN_VAR} must not reach an ARG or ENV (\`${line}\`): \`docker history\` prints ` +
        "build args and `docker inspect` prints env, so the token leaks to anyone who can pull the image.",
    );
}

/** Violations of invariant 5's build half: every `npm run build` step mounts the token secret. */
function secretMountViolations(dockerfile: string): string[] {
  const buildSteps = dockerInstructions(dockerfile).filter(
    (line) => /^RUN\s/i.test(line) && line.includes("npm run build"),
  );
  if (buildSteps.length === 0) {
    return ["the Dockerfile must still run `npm run build` in a RUN step"];
  }
  return buildSteps
    .filter((line) => !SECRET_MOUNT.test(line) || !line.includes(TOKEN_VAR))
    .map(
      (line) =>
        `the build step must mount ${TOKEN_VAR} as the BuildKit secret sentry_auth_token and ` +
        `hand it to the build (\`${line.slice(0, 80)}…\`)`,
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

  it("never switches off the post-compile hook that deletes the maps", () => {
    expect(postCompileHookViolations(nextConfig)).toEqual([]);
  });

  it("never names the auth token as a config key", () => {
    expect(tokenAsConfigKeyViolations(nextConfig)).toEqual([]);
  });

  it("never takes the auth token as a build ARG or an image ENV", () => {
    expect(tokenInImageViolations(dockerfile)).toEqual([]);
  });

  it("passes the auth token to the build through a secret mount", () => {
    expect(secretMountViolations(dockerfile)).toEqual([]);
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

  it.each([
    ["absent", PINNED],
    [
      "true",
      `{ useRunAfterProductionCompileHook: true, sourcemaps: { deleteSourcemapsAfterUpload: true } }`,
    ],
  ])("accepts useRunAfterProductionCompileHook %s", (_label, options) => {
    expect(postCompileHookViolations(config(options))).toEqual([]);
  });

  it.each([
    ["false", `{ useRunAfterProductionCompileHook: false, ${PINNED.slice(1)}`],
    [
      "a non-literal",
      `{ useRunAfterProductionCompileHook: Boolean(process.env.HOOK), ${PINNED.slice(1)}`,
    ],
    ["a string key", `{ "useRunAfterProductionCompileHook": false, ${PINNED.slice(1)}`],
    ["a shorthand", `{ useRunAfterProductionCompileHook, ${PINNED.slice(1)}`],
  ])("refuses useRunAfterProductionCompileHook set to %s", (_label, options) => {
    expect(postCompileHookViolations(config(options))).toHaveLength(1);
  });

  it("refuses the post-compile hook switched off after the options are built", () => {
    const source = `const options = ${PINNED};\noptions.useRunAfterProductionCompileHook = false;`;
    expect(postCompileHookViolations(source)).toHaveLength(1);
  });

  it.each([
    ["under webpack", `webpack: { unstable_sentryWebpackPluginOptions: { sourcemaps: {} } }`],
    ["at the top level", `unstable_sentryWebpackPluginOptions: { sourcemaps: {} }`],
    ["with a string key", `webpack: { "unstable_sentryWebpackPluginOptions": {} }`],
  ])("refuses unstable_sentryWebpackPluginOptions %s", (_label, member) => {
    const source = config(`{ ${member}, sourcemaps: { deleteSourcemapsAfterUpload: true } }`);
    expect(sourcemapDeletionViolations(source).join("\n")).toContain(UNSTABLE_WEBPACK_OPTIONS);
  });

  it("refuses unstable_sentryWebpackPluginOptions assigned after the options are built", () => {
    const source = `${config(PINNED)}\nopts.webpack.unstable_sentryWebpackPluginOptions = {};`;
    expect(sourcemapDeletionViolations(source).join("\n")).toContain(UNSTABLE_WEBPACK_OPTIONS);
  });

  it.each([
    ["satisfies", `${PINNED} satisfies SentryBuildOptions`],
    ["as", `${PINNED} as SentryBuildOptions`],
    ["parentheses", `(${PINNED})`],
    ["nested wrappers", `((${PINNED}) satisfies SentryBuildOptions)`],
    [
      "a wrapped sourcemaps object",
      `{ sourcemaps: { deleteSourcemapsAfterUpload: true as const } satisfies object }`,
    ],
  ])("reads an options object wrapped in %s", (_label, options) => {
    expect(sourcemapDeletionViolations(config(options))).toEqual([]);
  });

  it("still refuses a wrapped options object that lacks the pin", () => {
    const source = config(`{ sourcemaps: { disable: false } } satisfies SentryBuildOptions`);
    expect(sourcemapDeletionViolations(source)).toHaveLength(1);
  });

  it("refuses a withSentryConfig call it cannot read, rather than passing over it", () => {
    const source = `
      import * as Sentry from "@sentry/nextjs";
      export default Sentry.withSentryConfig({}, ${PINNED});
    `;
    expect(sourcemapDeletionViolations(source).join("\n")).toContain("found 0");
  });

  it("accepts reading the token from the environment", () => {
    const source = config(
      `{ authToken: process.env.SENTRY_AUTH_TOKEN?.trim(), ${PINNED.slice(1)}`,
      `{ env: { NEXT_PUBLIC_APP_ENV: process.env["SENTRY_AUTH_TOKEN"] ? "x" : "y" } }`,
    );
    expect(tokenAsConfigKeyViolations(source)).toEqual([]);
  });

  it.each([
    ["an identifier key", `{ env: { SENTRY_AUTH_TOKEN: process.env.SENTRY_AUTH_TOKEN } }`],
    ["a string key", `{ env: { "SENTRY_AUTH_TOKEN": "sntrys_x" } }`],
    ["a computed key", `{ env: { ["SENTRY_AUTH_TOKEN"]: token } }`],
    ["a shorthand", `{ env: { SENTRY_AUTH_TOKEN } }`],
  ])("refuses SENTRY_AUTH_TOKEN as %s", (_label, nextConfig) => {
    expect(tokenAsConfigKeyViolations(config(PINNED, nextConfig))).toHaveLength(1);
  });
});

describe("the Dockerfile rule rejects the token in every image-visible instruction", () => {
  const MOUNTED_BUILD = `RUN --mount=type=secret,id=sentry_auth_token \\
    SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token)" npm run build`;

  it("accepts the token confined to a secret-mounted RUN", () => {
    expect(tokenInImageViolations(`FROM node\nARG SENTRY_ORG=\n${MOUNTED_BUILD}\n`)).toEqual([]);
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
    [
      "an ENV whose continuation carries a comment line",
      "ENV A=1 \\\n    # the token follows\n    SENTRY_AUTH_TOKEN=sntrys_x",
    ],
    [
      "an ENV whose continuation carries a blank line",
      "ENV A=1 \\\n\n    SENTRY_AUTH_TOKEN=sntrys_x",
    ],
    ["an ONBUILD ARG", "ONBUILD ARG SENTRY_AUTH_TOKEN"],
    ["a lower-case ONBUILD ENV", "onbuild env SENTRY_AUTH_TOKEN=sntrys_x"],
  ])("refuses %s", (_label, instruction) => {
    expect(tokenInImageViolations(`FROM node\n${instruction}\n${MOUNTED_BUILD}\n`)).toHaveLength(1);
  });

  it("does not mistake a comment for an instruction", () => {
    expect(tokenInImageViolations(`FROM node\n# ARG SENTRY_AUTH_TOKEN is forbidden\n`)).toEqual([]);
  });

  it("does not join a comment inside a continuation into the instruction", () => {
    const dockerfile = "FROM node\nENV A=1 \\\n    # ARG SENTRY_AUTH_TOKEN is forbidden\n    B=2\n";
    expect(tokenInImageViolations(dockerfile)).toEqual([]);
    expect(dockerInstructions(dockerfile)).toEqual(["FROM node", "ENV A=1 B=2"]);
  });

  it("accepts a build step that mounts the token secret", () => {
    expect(secretMountViolations(`FROM node\n${MOUNTED_BUILD}\n`)).toEqual([]);
  });

  it.each([
    ["no mount at all", `RUN SENTRY_AUTH_TOKEN="" npm run build`],
    ["a bare build", "RUN npm run build"],
    [
      "another secret id",
      `RUN --mount=type=secret,id=sentry_auth_token_old SENTRY_AUTH_TOKEN="$t" npm run build`,
    ],
    [
      "the mount without handing the token over",
      "RUN --mount=type=secret,id=sentry_auth_token npm run build",
    ],
  ])("refuses a build step with %s", (_label, step) => {
    expect(secretMountViolations(`FROM node\n${step}\n`)).toHaveLength(1);
  });

  it("refuses a second build step that lacks the mount", () => {
    expect(secretMountViolations(`FROM node\n${MOUNTED_BUILD}\nRUN npm run build\n`)).toHaveLength(
      1,
    );
  });

  it("refuses a Dockerfile that no longer builds", () => {
    expect(secretMountViolations("FROM node\nRUN npm ci\n")).toHaveLength(1);
  });
});
