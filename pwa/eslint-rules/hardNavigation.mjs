/**
 * A full-document navigation, found by resolving its receiver rather than by matching its spelling.
 *
 * The predecessor was two `no-restricted-syntax` selectors. A selector matches syntax and has no
 * scope manager, so the receiver could only be an enumerated NAME — and a name is ambiguous in both
 * directions at once. It missed every navigation whose receiver was bound to something first
 * (`const l = location; l.assign(u)`), and it reported ordinary code whose identifier happened to
 * collide: an ERP has `warehouse.location`, and `const { location } = warehouse;
 * location.replace(/ /g, "-")` was flagged as a navigation — a rule-wide disable on a line that
 * also carries the `maxLength` and test-id contract bans.
 *
 * Both directions are the same missing fact, which is why one rule closes both: does this
 * expression denote the global `Location`? A scope lookup answers it. A local binding is a local
 * binding whatever it is called, so the receiver set no longer has to be conservative to stay
 * quiet, and `frames`/`opener` join it at no cost.
 *
 * **What is still out of reach, and why it needs types rather than a wider rule.**
 *  - `iframe.contentWindow.location.replace(u)` — whether `iframe` is an element is a type
 *    question; a scope manager only says where the binding came from.
 *  - `const { assign } = location; assign(u)` — the call site names no receiver at all, so nothing
 *    at that node distinguishes it from any other one-argument call.
 *  - `Object.assign(location, { href: u })` and other reflective reaches.
 *  - A `let` reassigned after its declaration: the alias is read from the single declaration, so a
 *    binding that changes meaning later is deliberately not chased. `const` is the only form
 *    treated as an alias, and that is a decision — following assignments would mean a dataflow
 *    pass, and the first thing it would have to be right about is branches.
 */

/** Identifiers that denote an object carrying the global `location`, when they resolve globally. */
const GLOBAL_RECEIVERS = new Set([
  "window",
  "globalThis",
  "self",
  "top",
  "parent",
  "document",
  "frames",
  "opener",
]);

/**
 * Properties that step from one global object to another (`window.top.location`). The one-level
 * limit was a stated asymmetry of the selectors — `top.location.assign(u)` flagged while
 * `window.top.location.assign(u)` did not — and it only existed because reaching further meant
 * matching any `.location`. Resolving the ROOT of the chain instead makes depth free.
 */
const WINDOW_LIKE = new Set(["top", "parent", "self", "window", "frames", "opener", "document"]);

/** Methods on `Location` that leave the current document. */
const NAVIGATION_METHODS = new Set(["assign", "replace"]);

/** `open()` targets that reuse an existing browsing context instead of creating one. */
const CURRENT_CONTEXT_TARGETS = new Set(["_self", "_top", "_parent"]);

/** Cycle and runaway guard for alias chains (`const a = location; const b = a; …`). */
const MAX_ALIAS_DEPTH = 8;

/** The static property name of a member expression, or null when it cannot be read statically. */
function propertyNameOf(member) {
  if (!member.computed) {
    return member.property.type === "Identifier" ? member.property.name : null;
  }
  const key = member.property;
  if (key.type === "Literal" && typeof key.value === "string") return key.value;
  // A template key with no substitutions is a string literal wearing backticks. The selectors read
  // `property.value`, which a TemplateLiteral does not have, so this shape used to escape.
  if (key.type === "TemplateLiteral" && key.expressions.length === 0 && key.quasis.length === 1) {
    return key.quasis[0].value.cooked;
  }
  return null;
}

const rule = {
  meta: {
    type: "problem",
    docs: {
      description:
        "Require a stated reason for a full-document navigation, resolving the receiver through scope analysis.",
    },
    schema: [],
    messages: {
      navigation:
        "A full-document navigation (location.assign/replace, location.href =) discards all in-memory client state; inside the app use router.push()/replace() from next/navigation. It is legitimate when leaving an authenticated area, or when no React context exists to reach a router from — disable this rule on the line and say which of the two it is.",
      reload:
        "location.reload() is a full-document navigation: it discards all in-memory client state. Re-fetch the data instead, or disable this rule on the line and say why the whole document has to go.",
      openSelf:
        'open() with a "_self"/"_top"/"_parent" target navigates an existing browsing context, exactly like location.assign(). Use router.push()/replace(), or disable this rule on the line with the reason.',
    },
  },

  create(context) {
    const sourceCode = context.sourceCode;

    function variableFor(identifier) {
      for (let scope = sourceCode.getScope(identifier); scope !== null; scope = scope.upper) {
        const variable = scope.set.get(identifier.name);
        if (variable !== undefined) return variable;
      }
      return null;
    }

    /**
     * A name resolves globally when nothing declares it: either it is unresolved entirely, or it
     * comes from `languageOptions.globals`, which contributes variables with no definitions. A
     * local — `const parent = { location: "A 1" }` — has one, and is therefore not this.
     */
    function isGlobalName(node, names) {
      if (node.type !== "Identifier" || !names.has(node.name)) return false;
      const variable = variableFor(node);
      return variable === null || variable.defs.length === 0;
    }

    /** What a `const` binding was initialised with, when it is a plain single-name declaration. */
    function aliasInitializerOf(node) {
      if (node.type !== "Identifier") return null;
      const variable = variableFor(node);
      if (variable === null || variable.defs.length !== 1) return null;
      const [definition] = variable.defs;
      if (definition.type !== "Variable" || definition.parent.kind !== "const") return null;
      // A destructuring pattern is not an alias of its source: `const { location } = warehouse`
      // binds a FIELD, which is the domain false positive this rule exists to stop reporting.
      if (definition.node.id.type !== "Identifier") return null;
      return definition.node.init ?? null;
    }

    function isGlobalReceiver(node, depth) {
      if (depth > MAX_ALIAS_DEPTH) return false;
      if (isGlobalName(node, GLOBAL_RECEIVERS)) return true;
      if (node.type === "MemberExpression") {
        const property = propertyNameOf(node);
        return (
          property !== null && WINDOW_LIKE.has(property) && isGlobalReceiver(node.object, depth + 1)
        );
      }
      const initializer = aliasInitializerOf(node);
      return initializer !== null && isGlobalReceiver(initializer, depth + 1);
    }

    function isLocationExpression(node, depth) {
      if (depth > MAX_ALIAS_DEPTH) return false;
      if (node.type === "MemberExpression") {
        return propertyNameOf(node) === "location" && isGlobalReceiver(node.object, depth + 1);
      }
      if (node.type !== "Identifier") return false;
      if (isGlobalName(node, new Set(["location"]))) return true;
      const initializer = aliasInitializerOf(node);
      return initializer !== null && isLocationExpression(initializer, depth + 1);
    }

    function targetsAnExistingContext(args) {
      // No target at all means `_blank`: a new browsing context, which leaves this document alone.
      const target = args[1];
      return (
        target !== undefined &&
        target.type === "Literal" &&
        typeof target.value === "string" &&
        CURRENT_CONTEXT_TARGETS.has(target.value)
      );
    }

    return {
      CallExpression(node) {
        const callee = node.callee;

        if (callee.type === "Identifier") {
          if (isGlobalName(callee, new Set(["open"])) && targetsAnExistingContext(node.arguments)) {
            context.report({ node, messageId: "openSelf" });
          }
          return;
        }

        if (callee.type !== "MemberExpression") return;
        const method = propertyNameOf(callee);
        if (method === null) return;

        if (method === "open") {
          if (isGlobalReceiver(callee.object, 0) && targetsAnExistingContext(node.arguments)) {
            context.report({ node, messageId: "openSelf" });
          }
          return;
        }

        if (!NAVIGATION_METHODS.has(method) && method !== "reload") return;
        if (!isLocationExpression(callee.object, 0)) return;

        context.report({ node, messageId: method === "reload" ? "reload" : "navigation" });
      },

      AssignmentExpression(node) {
        const left = node.left;

        // `location = "/x"` navigates in every browser. TypeScript refuses it in .ts/.tsx, but
        // this config also lints .js/.jsx, where nothing does — and the selectors could not reach
        // it at all, because both required `left` to be a MemberExpression.
        if (left.type === "Identifier") {
          if (isGlobalName(left, new Set(["location"]))) {
            context.report({ node, messageId: "navigation" });
          }
          return;
        }

        if (left.type !== "MemberExpression") return;
        const property = propertyNameOf(left);
        if (property === null) return;

        if (property === "href" && isLocationExpression(left.object, 0)) {
          context.report({ node, messageId: "navigation" });
          return;
        }

        if (property === "location" && isGlobalReceiver(left.object, 0)) {
          context.report({ node, messageId: "navigation" });
        }
      },
    };
  },
};

export default rule;
