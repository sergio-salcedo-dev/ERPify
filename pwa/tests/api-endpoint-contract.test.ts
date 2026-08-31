import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

/**
 * `ApiEndpoints.ts` says its paths "MUST match the backend exactly". Nothing checked it.
 *
 * That registry is the client's whole picture of the API surface: ~41 constants facing ~43
 * Symfony routes, with the two sides kept in lock-step by hand and by the docblock asking
 * politely. A path that drifts does not fail a build, a type-check or a lint — it becomes a 404
 * in the user's face, at runtime, with every gate green. The repository test beside the adapter
 * is no help either, because it asserts the URL the adapter called against THE ADAPTER'S OWN
 * CONSTANT: true by construction, and true again the day the constant is wrong.
 *
 * The verb half is the reason this is worth building rather than a path-only check. A path holds
 * still while the verb under it moves: `/me/recovery-secret` answers both GET and POST, so which
 * verb destroys the secret is a fact about that path and not about its spelling, and a path-only
 * check is green either way. Method drift is silent in exactly the same way path drift is, and it
 * is the more likely of the two, because a path is copied once and a verb is chosen per call
 * site.
 *
 * **The verb is compared against a DECLARATION, not against the router's union, and that is the
 * whole of it.** Asking the manifest "does this path accept this verb" answers yes for both halves
 * of a swapped pair, because the API really does accept both — measured, 6 of 34 mounted paths
 * take more than one method, `/me/recovery-secret` among them, so on exactly the paths the verb
 * check exists for it would assert nothing. What identifies the operation is the registry KEY, so
 * each key declares the one verb it is called with ({@link METHOD_BY_KEY}) and a call site is
 * compared against that. A key called with two verbs cannot state the fact and has to be split,
 * which is the shape the rest of the registry uses — `BANKS.LIST` and `BANKS.CREATE` are two keys
 * over one path.
 *
 * ## What it reads
 *
 * `api/.route-manifest.json`, generated from Symfony's own router — the routes the application
 * actually mounts, not the `#[Route]` attributes a grep can find. Route name → `{ path, methods }`.
 * A missing manifest is a RED, never a skip: a gate whose input can vanish and take the gate with
 * it proves nothing, and this repository has been bitten by that shape often enough to have written
 * the rule down. Regenerate it rather than deleting this file.
 *
 * ## The manifest is the PRODUCTION surface, and that is why the rule has two directions
 *
 * The manifest is dumped under `prod`, deliberately and settled. Measured against the running
 * stack, the dev router mounts 43 `/api/` routes and the prod router 42; the difference is exactly
 * `/api/v1/dev/frankenphp-hot-reload`, mounted by `api/config/routes/dev.yaml` and mirrored in
 * `routes/test.yaml`. Dumping under `dev` instead would have been the shorter fix and it
 * REINTRODUCES the hole this whole reconciliation exists to close: a manifest that vouches for a
 * dev-only route lets a client constant point at something that 404s in production while this file
 * stays green. So the manifest describes production, and the registry's own key structure says
 * which constants are not claiming to be there.
 *
 * The registry already publishes that classification: `FRONTOFFICE.DEV.FRANKENPHP_HOT_RELOAD` is
 * nested under a `DEV` key. The rule is read off that nesting rather than off a list, and it runs
 * BOTH ways, which is what makes it a classification and not an exemption:
 *
 *  1. A constant nested under a `DEV` key must be ABSENT from the manifest. Its appearance means a
 *     dev-only route has leaked onto the production surface — a defect a one-line allowlist would
 *     have hidden, and a direction this gate would not otherwise have had.
 *  2. Every other constant must resolve to a manifest entry, exactly as before.
 *
 * Both halves are pinned, and the DEV set carries a floor of its own: with no `DEV`-keyed constant
 * in the tree, direction 1 passes over an empty set and says nothing. That floor is the standing
 * cost of the rule — deleting the last dev-tools endpoint reds this row until the floor goes with
 * it, which is the trade `client-minted-problem-types.test.ts` already makes for the same reason.
 *
 * The client side is read as a TypeScript AST, not with a regex, for two independent reasons.
 * A comment is not a node, so the `/api/v1/backoffice/banks` in a docblock never competes with a
 * real one — and there are nine such comments in `src/` today, which is what a grep-based version
 * of the third check would have had to enumerate around. And the endpoint reference at a call site
 * is routinely on its own line:
 *
 *     await this.httpClient.post<Request, void>(
 *       API_ENDPOINTS.IDENTITY.RECOVERY_SECRET_REVOKE,
 *       { currentPassword },
 *     );
 *
 * so the verb and its argument are never on one line for a line-oriented matcher to pair up.
 *
 * ## How a client path is reduced
 *
 * The registry composes its paths from prefix constants (`API_PREFIX_V1`, `BACKOFFICE_PREFIX`,
 * `FRONTOFFICE_PREFIX`) and path-builder functions (`bankPath`, `bankAccountsPath`,
 * `bankAccountPath`, `userPath`), some of them nested one call deep
 * (`` (id) => `${bankAccountPath(id)}/status` ``). The registry is therefore symbolically
 * evaluated: template literals are concatenated, identifiers resolved against the module's own
 * constants, and a builder is applied with its parameter bound to a placeholder. Nothing is
 * hand-listed — the universe is whatever the file declares, so a constant added tomorrow is
 * checked tomorrow.
 *
 * BOTH sides then have every `{...}` segment collapsed to a single `{}` token, because the
 * placeholder NAME is a server-side binding detail the client cannot see: it interpolates a
 * value, not a name. Measured, the names already disagree — the manifest spells the invitation
 * revoke `/backoffice/users/{userId}/invitation` while `userPath` builds `{id}` — so comparing
 * names would report a defect that does not exist. The cost is stated: a placeholder RENAME on
 * the API is invisible here, which is correct, since it is invisible to the client too.
 *
 * ## The direction NOT checked, and why
 *
 * Client → API only. The reverse — every API route is consumed by the client — is deliberately
 * absent. It cannot be stated without an allowlist: `/backoffice/users/{id}/unlock` is reached by
 * a console command, the two health probes are for the deploy and the status page, and
 * `/dev/frankenphp-hot-reload` exists only under dev and test. This repository's position is that
 * a rule needing an allowlist is written wrong, and an allowlist here would grow one line per
 * route nobody calls yet — which is to say, per route that is fine. An unconsumed route is not a
 * defect; a consumed route that does not exist is.
 *
 * ## Blind spots — a green proves less than it looks
 *
 * A green proves that every path the registry declares is a path the router mounts, that every
 * verb a call site uses is a verb that path accepts, and that no fetchable `/api/...` literal
 * lives outside the registry. It proves NOTHING about:
 *
 *  - the request BODY, the response SHAPE, or the status codes — a call site can send the wrong
 *    DTO to the right path and verb and this stays green; the `ResponseGuard`s are the only
 *    control on the response half and nothing at all guards the request half;
 *  - AUTHORIZATION — whether the caller may reach that path at all, whether it needs a CSRF
 *    header, and what the voter expects, are all outside this file;
 *  - a URL that never passes through the registry as a syntactic reference. An endpoint stored in
 *    a variable first, then handed to the client, reaches `useMercureRealtime`'s `authorize()`
 *    with the verb unchecked — the two `authorizePath` call sites are exactly that shape, and
 *    their PATHS are still checked, only not their verb;
 *  - a verb reached through computed member access. The call-site walk matches a PROPERTY access,
 *    so `client[verb](url, body)` yields no call site at all: not a wrong verb, an absent one,
 *    and the path that rides with it goes unchecked too. Nothing in the tree spells a call that
 *    way today, and nothing would say so if something started;
 *  - a path assembled by concatenation from parts that are individually clean. `"/api/" + rest`
 *    escapes the literal sweep by construction, and no AST check can close that;
 *  - a literal deliberately spelled so as not to begin `/api/` followed by an alphanumeric —
 *    the sweep admits `/api/*` in a diagram label for that reason, since a glob is not a
 *    fetchable path;
 *  - that a dev-only endpoint is actually FILED under a `DEV` key. The nesting is a naming
 *    convention this gate now READS; nothing at the source enforces it, and no compiler, linter or
 *    review step requires an author to use it. A dev-only route declared outside a `DEV` key and
 *    absent from the production manifest still reds — as a broken path, by direction 2 — which is
 *    the right answer arrived at for the wrong reason, and the author's fix is to move the
 *    constant rather than to change the route. Read direction 1 as "the constants that CLAIM to be
 *    dev-only are held to that claim", never as "the dev-only constants are known";
 *  - the VERB of anything under a `DEV` key. The two directions are about existence, and a `DEV`
 *    constant is by construction absent from the manifest, so there is no method set to check a
 *    call against — the verb half simply does not cover that branch. It costs nothing today
 *    (`frankenphpHotReload.ts` reaches its path through `fetch`, not through an `HttpClient` verb,
 *    so it is not in the call-site universe at all) and it would cost something the moment a
 *    dev-only endpoint is called through the client. Nothing here would say so;
 *  - a registry constant nobody calls. Direction 2 holds every constant to a mounted route; no
 *    direction runs the other way over the CALL SITES, so a constant whose last caller was
 *    deleted survives for exactly as long as its route does. This is not the "API route with no
 *    client consumer" direction declined above: that one is declined because an unconsumed route
 *    is legitimate and refusing it would need an allowlist, whereas an unreferenced constant is
 *    dead client code with no such defence — it is simply not stated here;
 *  - whether the manifest itself is current. It is a generated artifact; a stale one agrees with
 *    a stale client. That direction belongs to the generator and to CI regenerating it, not here.
 *
 * Two costs of living in the PWA suite, stated rather than hidden. An API developer who deletes a
 * route sees it here, in the PWA's CI job, and not in `make php.quality`. And this file makes the
 * PWA suite depend on a sibling tree, so a standalone `pwa/` checkout fails here rather than
 * skipping — the same trade `client-minted-problem-types.test.ts` already makes, for the same
 * reason, and every sanctioned path runs these targets from the repo root.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const REPO_ROOT = path.resolve(PWA_ROOT, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const REGISTRY = path.join(SRC_ROOT, "context/shared/http-client/infrastructure/ApiEndpoints.ts");
const MANIFEST = path.join(REPO_ROOT, "api/.route-manifest.json");

/** The registry object this gate is the contract for. */
const REGISTRY_EXPORT = "API_ENDPOINTS";

/** The one token both sides collapse a parameterised segment to. */
const PARAM = "{}";
const PLACEHOLDER = /\{[^}]*\}/g;

/** `HttpClient` port methods, and the HTTP method each one puts on the wire. */
const VERBS = new Map([
  ["get", "GET"],
  ["post", "POST"],
  ["put", "PUT"],
  ["patch", "PATCH"],
  ["delete", "DELETE"],
]);

/**
 * Floors, so a broken extraction reports RED instead of passing over an empty set. The universe
 * here is ~41 constants and ~35 call sites against ~43 routes; the floors sit well under those so
 * that deleting a feature is not a false red, and well over zero so that an extraction returning
 * nothing — the failure this repository has actually shipped — cannot be mistaken for compliance.
 */
const MIN_ENDPOINTS = 30;
const MIN_PARAMETERISED = 8;
/** Without a DEV-keyed constant in the tree, the leak direction passes over an empty set. */
const MIN_DEV_ENDPOINTS = 1;
const MIN_CALL_SITES = 25;
const MIN_ROUTES = 30;
const MIN_SOURCE_FILES = 100;

const SOURCE_EXTENSIONS = new Set([".ts", ".tsx"]);

/**
 * The verb each registry key is called with — one per key, declared rather than derived.
 *
 * Derived from usage it would be a tautology (whatever the client sends becomes what the client is
 * allowed to send), and derived from the router it would be the union that let a swapped pair pass.
 * So it is a hand-kept classification with a completeness gate on both sides, the shape this
 * repository already uses for its api-root registries: a key with no verb here fails, and a verb
 * here for a key the registry dropped fails too.
 */
const METHOD_BY_KEY: Readonly<Record<string, string>> = {
  "BACKOFFICE.AUDIT.EVENT_DETAIL": "GET",
  "BACKOFFICE.AUDIT.TIMELINE": "GET",
  "BACKOFFICE.BANKS.ACCOUNTS": "GET",
  "BACKOFFICE.BANKS.COUNT": "GET",
  "BACKOFFICE.BANKS.CREATE": "POST",
  "BACKOFFICE.BANKS.DELETE": "DELETE",
  "BACKOFFICE.BANKS.DETAILS": "GET",
  "BACKOFFICE.BANKS.LIST": "GET",
  // Reached through `useMercureRealtime`'s `authorizePath` variable, so the call-site walk cannot
  // see either of these; only the completeness direction holds them.
  "BACKOFFICE.BANKS.REALTIME_AUTHORIZE": "GET",
  "BACKOFFICE.BANKS.UPDATE": "PUT",
  "BACKOFFICE.BANK_ACCOUNTS.CHANGE_STATUS": "PATCH",
  "BACKOFFICE.BANK_ACCOUNTS.CREATE": "POST",
  "BACKOFFICE.BANK_ACCOUNTS.DELETE": "DELETE",
  "BACKOFFICE.BANK_ACCOUNTS.DETAILS": "GET",
  "BACKOFFICE.BANK_ACCOUNTS.IBAN_LOOKUP": "POST",
  "BACKOFFICE.BANK_ACCOUNTS.LIST": "GET",
  "BACKOFFICE.BANK_ACCOUNTS.REALTIME_AUTHORIZE": "GET",
  "BACKOFFICE.BANK_ACCOUNTS.UPDATE": "PUT",
  "BACKOFFICE.FORGOT_PASSWORD": "POST",
  "BACKOFFICE.HEALTH": "GET",
  "BACKOFFICE.HEALTH_DATABASE": "GET",
  "BACKOFFICE.INVITATIONS.ACCEPT": "POST",
  "BACKOFFICE.INVITATIONS.CREATE": "POST",
  "BACKOFFICE.LOGIN": "POST",
  "BACKOFFICE.RECOVERY_REDEEM": "POST",
  "BACKOFFICE.RESET_PASSWORD": "POST",
  "BACKOFFICE.USERS.CHANGE_ROLES": "PATCH",
  "BACKOFFICE.USERS.CHANGE_STATUS": "PATCH",
  "BACKOFFICE.USERS.DETAILS": "GET",
  "BACKOFFICE.USERS.ERASE": "DELETE",
  "BACKOFFICE.USERS.LIST": "GET",
  "BACKOFFICE.USERS.REVOKE_INVITATION": "DELETE",
  "FRONTOFFICE.HEALTH": "GET",
  "IDENTITY.CHANGE_PASSWORD": "POST",
  "IDENTITY.ME": "GET",
  "IDENTITY.RECOVERY_SECRET": "GET",
  "IDENTITY.RECOVERY_SECRET_MINT": "POST",
  "IDENTITY.RECOVERY_SECRET_REVOKE": "POST",
  "IDENTITY.SESSIONS": "GET",
  "IDENTITY.SESSIONS_REVOKE_CURRENT": "POST",
  "IDENTITY.SESSIONS_REVOKE_OTHERS": "POST",
};

interface ManifestRoute {
  path: string;
  methods: string[];
}

interface Endpoint {
  /** Dotted key inside the registry object, e.g. `BACKOFFICE.BANKS.DETAILS`. */
  key: string;
  /** The path with every parameterised segment reduced to {@link PARAM}. */
  path: string;
  line: number;
}

interface CallSite {
  file: string;
  line: number;
  key: string;
  path: string;
  method: string;
}

function collapse(value: string): string {
  return value.replace(PLACEHOLDER, PARAM);
}

/** The registry key segment that declares a branch as existing only outside production. */
const DEV_SEGMENT = "DEV";

/**
 * True when the constant is nested UNDER a `DEV` key. Ancestors only: a leaf that happens to be
 * named `DEV` is a path like any other, not a branch of dev-only ones.
 */
function isDevOnly(key: string): boolean {
  return key.split(".").slice(0, -1).includes(DEV_SEGMENT);
}

function parse(file: string): ts.SourceFile {
  return ts.createSourceFile(file, readFileSync(file, "utf8"), ts.ScriptTarget.Latest, true);
}

function lineOf(source: ts.SourceFile, node: ts.Node): number {
  return source.getLineAndCharacterOfPosition(node.getStart()).line + 1;
}

// -------------------------------------------------------------------------------------------
// Symbolic evaluation of the registry module.
// -------------------------------------------------------------------------------------------

/** A path-builder: one parameter, one expression that returns the path. */
interface Builder {
  param: string;
  body: ts.Expression;
}

class Registry {
  private readonly constants = new Map<string, string>();
  private readonly builders = new Map<string, Builder>();

  constructor(private readonly source: ts.SourceFile) {
    for (const statement of source.statements) {
      if (ts.isFunctionDeclaration(statement)) this.declareFunction(statement);
      if (ts.isVariableStatement(statement)) this.declareVariables(statement);
    }
  }

  private declareFunction(node: ts.FunctionDeclaration): void {
    const builder = toBuilder(node);
    if (node.name && builder) this.builders.set(node.name.text, builder);
  }

  private declareVariables(node: ts.VariableStatement): void {
    for (const declaration of node.declarationList.declarations) {
      if (!ts.isIdentifier(declaration.name) || !declaration.initializer) continue;
      const builder = toBuilder(declaration.initializer);
      if (builder) {
        this.builders.set(declaration.name.text, builder);
        continue;
      }
      // A constant that does not reduce to a string (the exported object itself) is simply not
      // a constant this evaluator needs; it is walked separately below.
      try {
        this.constants.set(declaration.name.text, this.resolve(declaration.initializer, new Map()));
      } catch {
        /* not a string constant */
      }
    }
  }

  /**
   * Reduces an expression to a path. THROWS on any shape it does not model, so a registry
   * written in a way this evaluator cannot read is reported as unresolved rather than dropped —
   * silence is the failure mode a gate like this dies of.
   */
  resolve(node: ts.Expression, locals: Map<string, string>): string {
    if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) return node.text;
    if (ts.isParenthesizedExpression(node)) return this.resolve(node.expression, locals);
    if (ts.isAsExpression(node) || ts.isSatisfiesExpression(node)) {
      return this.resolve(node.expression, locals);
    }
    if (ts.isTemplateExpression(node)) {
      return node.templateSpans.reduce(
        (acc, span) => acc + this.resolve(span.expression, locals) + span.literal.text,
        node.head.text,
      );
    }
    if (ts.isIdentifier(node)) {
      const local = locals.get(node.text);
      if (local !== undefined) return local;
      const constant = this.constants.get(node.text);
      if (constant !== undefined) return constant;
      const builder = this.builders.get(node.text);
      // `DETAILS: bankPath` — the builder referenced, not called.
      if (builder) return this.apply(builder, PARAM);
      throw new Error(`unresolved identifier ${node.text}`);
    }
    if (ts.isCallExpression(node) && ts.isIdentifier(node.expression)) {
      const callee = node.expression.text;
      const argument = node.arguments[0];
      if (!argument) throw new Error(`call to ${callee} with no argument`);
      // A percent-encode of a value is still that value's slot.
      if (callee === "encodeURIComponent") return this.resolve(argument, locals);
      const builder = this.builders.get(callee);
      if (builder) return this.apply(builder, this.resolve(argument, locals));
      throw new Error(`unresolved call to ${callee}`);
    }
    throw new Error(`unsupported ${ts.SyntaxKind[node.kind]}`);
  }

  private apply(builder: Builder, argument: string): string {
    return this.resolve(builder.body, new Map([[builder.param, argument]]));
  }

  /** Every leaf of the exported registry object, reduced to a comparable path. */
  endpoints(): { resolved: Endpoint[]; unresolved: string[] } {
    const object = this.registryObject();
    const resolved: Endpoint[] = [];
    const unresolved: string[] = [];

    const walk = (node: ts.ObjectLiteralExpression, prefix: string[]): void => {
      for (const property of node.properties) {
        if (!ts.isPropertyAssignment(property)) {
          unresolved.push(`${prefix.join(".")}: ${ts.SyntaxKind[property.kind]} is not a path`);
          continue;
        }
        const name = property.name.getText();
        const trail = [...prefix, name];
        const value = property.initializer;
        if (ts.isObjectLiteralExpression(value)) {
          walk(value, trail);
          continue;
        }
        const key = trail.join(".");
        try {
          const builder = toBuilder(value);
          const raw = builder ? this.apply(builder, PARAM) : this.resolve(value, new Map());
          resolved.push({ key, path: collapse(raw), line: lineOf(this.source, property) });
        } catch (error) {
          unresolved.push(`${key}: ${(error as Error).message}`);
        }
      }
    };

    walk(object, []);
    return { resolved, unresolved };
  }

  private registryObject(): ts.ObjectLiteralExpression {
    for (const statement of this.source.statements) {
      if (!ts.isVariableStatement(statement)) continue;
      for (const declaration of statement.declarationList.declarations) {
        if (!ts.isIdentifier(declaration.name) || declaration.name.text !== REGISTRY_EXPORT) {
          continue;
        }
        let initializer = declaration.initializer;
        while (
          initializer &&
          (ts.isAsExpression(initializer) || ts.isParenthesizedExpression(initializer))
        ) {
          initializer = initializer.expression;
        }
        if (initializer && ts.isObjectLiteralExpression(initializer)) return initializer;
      }
    }
    throw new Error(`${REGISTRY_EXPORT} is not an object literal in ${REGISTRY}`);
  }
}

/** An arrow / function with exactly one parameter and one returned expression, or null. */
function toBuilder(node: ts.Node): Builder | null {
  if (
    !ts.isArrowFunction(node) &&
    !ts.isFunctionDeclaration(node) &&
    !ts.isFunctionExpression(node)
  ) {
    return null;
  }
  const [parameter] = node.parameters;
  if (!parameter || !ts.isIdentifier(parameter.name)) return null;
  const param = parameter.name.text;
  const body = node.body;
  if (!body) return null;
  if (!ts.isBlock(body)) return { param, body };
  const [statement] = body.statements;
  if (
    body.statements.length === 1 &&
    statement &&
    ts.isReturnStatement(statement) &&
    statement.expression
  ) {
    return { param, body: statement.expression };
  }
  return null;
}

// -------------------------------------------------------------------------------------------
// The registry, evaluated once. Independent of the manifest, so these rows still run and still
// diagnose when the manifest is the thing that is missing.
// -------------------------------------------------------------------------------------------

const registrySource = parse(REGISTRY);
const registry = new Registry(registrySource);
const { resolved: endpoints, unresolved } = registry.endpoints();
const endpointByKey = new Map(endpoints.map((endpoint) => [endpoint.key, endpoint]));

// -------------------------------------------------------------------------------------------
// The manifest, read lazily so its absence is one loud row rather than a suite that never loads.
// -------------------------------------------------------------------------------------------

let manifestCache: Map<string, Set<string>> | null = null;

function manifest(): Map<string, Set<string>> {
  if (manifestCache) return manifestCache;
  if (!existsSync(MANIFEST)) {
    throw new Error(
      `${path.relative(REPO_ROOT, MANIFEST)} is missing. It is generated from Symfony's router and ` +
        `is this gate's only picture of the API surface — regenerate it rather than deleting this test.`,
    );
  }
  const raw = JSON.parse(readFileSync(MANIFEST, "utf8")) as Record<string, ManifestRoute>;
  const byPath = new Map<string, Set<string>>();
  for (const [name, route] of Object.entries(raw)) {
    if (typeof route?.path !== "string" || !Array.isArray(route.methods)) {
      throw new Error(`route ${name} does not carry { path, methods }`);
    }
    // One path can be mounted by several routes, one verb each — `/banks` is a GET route and a
    // POST route with different names. The union is what the path accepts.
    const key = collapse(route.path);
    const methods = byPath.get(key) ?? new Set<string>();
    for (const method of route.methods) methods.add(method.toUpperCase());
    byPath.set(key, methods);
  }
  manifestCache = byPath;
  return byPath;
}

// -------------------------------------------------------------------------------------------
// Call sites across src/.
// -------------------------------------------------------------------------------------------

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const full = path.join(dir, entry);
    if (statSync(full).isDirectory()) return sourceFiles(full);
    return SOURCE_EXTENSIONS.has(path.extname(full)) ? [full] : [];
  });
}

/**
 * The dotted key of the registry reference inside an expression, or null when there is none.
 * `API_ENDPOINTS.BACKOFFICE.BANKS.DETAILS(id)` and `API_ENDPOINTS.IDENTITY.ME` both yield a key.
 */
function registryKeyOf(node: ts.Node): string | null {
  if (ts.isCallExpression(node)) return registryKeyOf(node.expression);
  if (!ts.isPropertyAccessExpression(node)) return null;
  const trail: string[] = [];
  let current: ts.Expression = node;
  while (ts.isPropertyAccessExpression(current)) {
    trail.unshift(current.name.text);
    current = current.expression;
  }
  if (!ts.isIdentifier(current) || current.text !== REGISTRY_EXPORT) return null;
  return trail.join(".");
}

interface Argument {
  key: string;
  /** Text appended to the registry path inside a template, `""` when nothing is. */
  suffix: string;
}

/**
 * Reads the first argument of a client call. A template is accepted only when the registry path
 * is followed by nothing or by a query string — `` `${API_ENDPOINTS.X}/extra` `` is a hand-written
 * path wearing the registry's clothes, and is reported rather than credited to `X`.
 */
function argumentOf(node: ts.Expression): Argument | null {
  const direct = registryKeyOf(node);
  if (direct) return { key: direct, suffix: "" };
  if (!ts.isTemplateExpression(node)) return null;
  for (const span of node.templateSpans) {
    const key = registryKeyOf(span.expression);
    if (!key) continue;
    return { key, suffix: span.literal.text };
  }
  return null;
}

function callSitesIn(file: string): { sites: CallSite[]; problems: string[] } {
  const source = parse(file);
  const sites: CallSite[] = [];
  const problems: string[] = [];

  const visit = (node: ts.Node): void => {
    if (ts.isCallExpression(node) && ts.isPropertyAccessExpression(node.expression)) {
      const method = VERBS.get(node.expression.name.text);
      const [first] = node.arguments;
      if (method && first) {
        const argument = argumentOf(first);
        const at = `${path.relative(PWA_ROOT, file)}:${lineOf(source, node)}`;
        if (argument) {
          const endpoint = endpointByKey.get(argument.key);
          if (!endpoint) {
            problems.push(
              `${at} calls ${REGISTRY_EXPORT}.${argument.key}, which the registry does not declare`,
            );
          } else if (argument.suffix !== "" && !argument.suffix.startsWith("?")) {
            problems.push(
              `${at} appends "${argument.suffix}" to ${REGISTRY_EXPORT}.${argument.key} — that path is not in the registry`,
            );
          } else {
            sites.push({
              file: path.relative(PWA_ROOT, file),
              line: lineOf(source, node),
              key: argument.key,
              path: endpoint.path,
              method,
            });
          }
        }
      }
    }
    ts.forEachChild(node, visit);
  };

  visit(source);
  return { sites, problems };
}

const files = sourceFiles(SRC_ROOT);
const scanned = files.map(callSitesIn);
const callSites = scanned.flatMap((result) => result.sites);
const callSiteProblems = scanned.flatMap((result) => result.problems);

// -------------------------------------------------------------------------------------------
// Hand-written API paths.
// -------------------------------------------------------------------------------------------

/**
 * A literal that could be fetched: `/api/` followed by a path character. A glob (`/api/*` in a
 * documentation diagram's edge label) is not a path and is not reported — the alternative was an
 * allowlist entry for a file that is doing nothing wrong.
 */
const FETCHABLE_API_PATH = /^\/api\/[A-Za-z0-9]/;

function handWrittenPathsIn(file: string): string[] {
  const source = parse(file);
  const found: string[] = [];

  const record = (text: string, node: ts.Node): void => {
    if (!FETCHABLE_API_PATH.test(text)) return;
    found.push(`${path.relative(PWA_ROOT, file)}:${lineOf(source, node)} hand-writes "${text}"`);
  };

  const visit = (node: ts.Node): void => {
    if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node))
      record(node.text, node);
    // The head of `` `/api/v1/x/${id}` `` is a path too.
    if (ts.isTemplateExpression(node)) record(node.head.text, node);
    ts.forEachChild(node, visit);
  };

  visit(source);
  return found;
}

const handWritten = files
  .filter((file) => path.resolve(file) !== path.resolve(REGISTRY))
  .flatMap(handWrittenPathsIn);

// -------------------------------------------------------------------------------------------

describe("the API endpoint registry", () => {
  it("was actually read, and every constant in it reduced to a path", () => {
    // The control that matters. An extraction that quietly matches nothing passes every other row
    // in this file over an empty set — a shape this repository has shipped before. The floor is a
    // count; `unresolved` is the stronger half, because it fails on the FIRST constant this
    // evaluator cannot read rather than on the last one.
    expect(unresolved).toEqual([]);
    expect(endpoints.length).toBeGreaterThanOrEqual(MIN_ENDPOINTS);
    expect(files.length).toBeGreaterThanOrEqual(MIN_SOURCE_FILES);
  });

  it("reduced its path builders to the manifest's placeholder form", () => {
    // Not a duplicate of the row above: that one proves each constant reduced to SOMETHING, this
    // one proves the parameterised ones reduced to a comparable shape. A builder that silently
    // returned its own source text would satisfy the first and fail here.
    const parameterised = endpoints.filter((endpoint) => endpoint.path.includes(PARAM));
    expect(parameterised.length).toBeGreaterThanOrEqual(MIN_PARAMETERISED);

    const leaked = endpoints.filter(
      (endpoint) => endpoint.path.includes("${") || !endpoint.path.startsWith("/api/"),
    );
    expect(leaked.map((endpoint) => `${endpoint.key} = ${endpoint.path}`)).toEqual([]);
  });

  it("classifies at least one constant as dev-only, so the leak direction is about something", () => {
    // The floor on direction 1. An empty DEV set makes the row below green for the wrong reason,
    // and this gate's own history is that the vacuous universe is the failure that actually ships.
    const dev = endpoints.filter((endpoint) => isDevOnly(endpoint.key));
    expect(dev.length).toBeGreaterThanOrEqual(MIN_DEV_ENDPOINTS);
  });

  it("declares no production path the router does not mount", () => {
    const routes = manifest();
    expect(routes.size).toBeGreaterThanOrEqual(MIN_ROUTES);

    const missing = endpoints
      .filter((endpoint) => !isDevOnly(endpoint.key) && !routes.has(endpoint.path))
      .map(
        (endpoint) =>
          `${REGISTRY_EXPORT}.${endpoint.key} (ApiEndpoints.ts:${endpoint.line}) declares ${endpoint.path}, which no route mounts`,
      );

    expect(missing).toEqual([]);
  });

  it("keeps every dev-only path off the production surface", () => {
    // The other direction, and the one a plain exemption would have thrown away. The manifest is
    // the PROD router: a path filed under `DEV` appearing in it means a dev-only route now ships,
    // which is a defect on the API side that nothing else here would see.
    const routes = manifest();

    const leaked = endpoints
      .filter((endpoint) => isDevOnly(endpoint.key) && routes.has(endpoint.path))
      .map(
        (endpoint) =>
          `${REGISTRY_EXPORT}.${endpoint.key} (ApiEndpoints.ts:${endpoint.line}) is filed under ${DEV_SEGMENT}, but the production router mounts ${endpoint.path}`,
      );

    expect(leaked).toEqual([]);
  });
});

describe("every client call site", () => {
  it("was found by the walk, and reaches the registry rather than around it", () => {
    // Same control, second universe: a walk that stops matching call sites would leave the verb
    // check below asserting nothing at all.
    expect(callSiteProblems).toEqual([]);
    expect(callSites.length).toBeGreaterThanOrEqual(MIN_CALL_SITES);
  });

  it("declares a verb for every endpoint the registry carries", () => {
    // Completeness. Without it a new endpoint arrives with no declaration, its call sites are
    // filtered out by the `undefined` guard above, and the verb check silently stops covering it.
    const undeclared = endpoints
      .filter((endpoint) => !isDevOnly(endpoint.key) && METHOD_BY_KEY[endpoint.key] === undefined)
      .map(
        (endpoint) =>
          `${REGISTRY_EXPORT}.${endpoint.key} (ApiEndpoints.ts:${endpoint.line}) declares no verb in METHOD_BY_KEY`,
      );

    expect(undeclared).toEqual([]);
  });

  it("declares a verb for nothing the registry has dropped", () => {
    // Staleness, the other direction. A leftover row is a rule with no subject, and it is how a
    // table like this stops describing the tree without anything going red.
    const known = new Set(endpoints.map((endpoint) => endpoint.key));
    const orphaned = Object.keys(METHOD_BY_KEY).filter((key) => !known.has(key));

    expect(orphaned).toEqual([]);
  });

  it("declares only verbs the router actually mounts on that path", () => {
    // The third direction, and the one that keeps the declaration honest against the API rather
    // than merely self-consistent: a key may declare POST only if a POST route exists on its path.
    const routes = manifest();
    const unmounted = endpoints
      .filter((endpoint) => !isDevOnly(endpoint.key))
      .filter((endpoint) => {
        const declared = METHOD_BY_KEY[endpoint.key];
        const methods = routes.get(endpoint.path);
        return declared !== undefined && methods !== undefined && !methods.has(declared);
      })
      .map((endpoint) => {
        const allowed = [...(routes.get(endpoint.path) ?? [])].sort().join(", ");
        return `${REGISTRY_EXPORT}.${endpoint.key} is declared ${METHOD_BY_KEY[endpoint.key]}, but ${endpoint.path} accepts ${allowed}`;
      });

    expect(unmounted).toEqual([]);
  });

  it("uses the verb its registry key declares", () => {
    // Against the DECLARATION, never the router's union: `/me/recovery-secret` mounts a GET route
    // and a POST route, so asking the manifest whether the path accepts the verb answers yes for
    // both halves of a swapped pair. The key is what names the operation.
    const wrong = callSites
      .filter((site) => {
        const declared = METHOD_BY_KEY[site.key];
        return declared !== undefined && declared !== site.method;
      })
      .map(
        (site) =>
          `${site.file}:${site.line} sends ${site.method} to ${REGISTRY_EXPORT}.${site.key}, which is declared ${METHOD_BY_KEY[site.key]}`,
      );

    // A path with no route at all is the previous describe's finding, not this one's — reporting
    // it twice would make one fix look like two.
    expect(wrong).toEqual([]);
  });
});

describe("the registry is the only place an API path is written", () => {
  it("finds no hand-written /api/ literal anywhere else in src/", () => {
    expect(handWritten).toEqual([]);
  });
});
