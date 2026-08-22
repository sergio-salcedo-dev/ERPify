import { describe, expect, it } from "vitest";
import { Linter } from "eslint";
import config from "../../eslint.config.mjs";

/**
 * The hard-navigation gate, and a gate nobody re-runs is a claim, not a control: provoking its
 * reds by hand proves it worked once, in a session whose notes are deleted when the branch
 * closes. This runs the real `eslint.config.mjs` — imported, never transcribed, because a copied
 * rule proves the copy works — and asserts every direction.
 *
 * The negatives are the half that decays silently. An ERP has `warehouse.location`, and a rule
 * keyed on "any object with a `.location`" fires on a string field that never navigates. Under
 * the selectors this file recorded four such reports as an accepted COST, because a syntactic
 * matcher cannot tell a global from a binding that shadows it; the rule resolves the receiver
 * through scope analysis, so they are ordinary negatives now and the cost is gone rather than
 * documented.
 *
 * The third direction is containment. `eslint.config.mjs` keeps the upstream Next rule at the
 * presets' `warn`, which cannot gate on its own, and leans on this one instead — on the claim
 * that it reports everything the upstream rule reports. No assertion over the shipped severity
 * can make that claim, because a `warn` under an `eslint .` with no --max-warnings is
 * indistinguishable from silence. So the upstream rule is forced to `error` in a cloned config
 * and the two are compared fixture by fixture.
 *
 * The fourth is that the comparison stays worth making. The upstream rule resolves a receiver
 * only through the global scope, so `languageOptions.globals` is what its half of the corpus
 * rests on: strip that block and the shapes it can see fall from 36 to 8 (the `globalThis` rows,
 * which ESLint supplies as a builtin) while `gaps` stays empty and the non-vacuity guard still
 * passes. A receiver-by-receiver assertion is what turns that silent shrink into a red.
 */
const NEXT_RULE = "@next/next/no-location-assign-relative-destination";

type ConfigBlock = { rules?: Record<string, unknown> };

// Only the block that already declares the rule is patched: adding it to a block whose
// `plugins` does not register `@next/next` is a configuration error, not a stricter run.
const configWithUpstreamRuleOn = (config as ConfigBlock[]).map((block) =>
  block.rules && NEXT_RULE in block.rules
    ? { ...block, rules: { ...block.rules, [NEXT_RULE]: "error" } }
    : block,
);

const linter = new Linter();

const GATE_RULE = "erpify/hard-navigation";

function navigationErrorsIn(code: string): number {
  return linter
    .verify(code, config as never, "src/probe.ts")
    .filter((message) => message.ruleId === GATE_RULE).length;
}

function upstreamErrorsIn(code: string): number {
  return linter
    .verify(code, configWithUpstreamRuleOn as never, "src/probe.ts")
    .filter((message) => message.ruleId === NEXT_RULE).length;
}

// Destinations are relative string literals throughout: the upstream rule folds its argument
// to a static prefix and gives up on anything else, so a non-literal would make every
// containment comparison pass for the wrong reason.
const POSITIVES: ReadonlyArray<readonly [string, string]> = [
  ["assign on globalThis", 'globalThis.location.assign("/x");'],
  ["replace on window", 'window.location.replace("/x");'],
  ["assign on a bare receiver", 'location.assign("/x");'],
  ["replace on document", 'document.location.replace("/x");'],
  ["assign on self", 'self.location.assign("/x");'],
  ["assign on top", 'top.location.assign("/x");'],
  ["assign on parent", 'parent.location.assign("/x");'],
  ["href assignment", 'globalThis.location.href = "/x";'],
  ["href on a bare receiver", 'location.href = "/x";'],
  ["whole-location assignment", 'document.location = "/x";'],
  ["computed method access", 'globalThis.location["assign"]("/x");'],
  ["computed location member", 'globalThis["location"].assign("/x");'],
  ["computed location member on window", 'window["location"].replace("/x");'],
  ["computed location member with href", 'globalThis["location"].href = "/x";'],
  ["computed on both halves", 'globalThis["location"]["assign"]("/x");'],
  ["computed whole-location assignment", 'document["location"] = "/x";'],
  // Shapes the selectors declared out of scope, each closed by resolving the receiver instead of
  // matching its name.
  ["an aliased receiver", 'const l = globalThis.location; l.assign("/x");'],
  ["an aliased bare receiver", 'const l = location; l.replace("/x");'],
  ["an aliased receiver's href", 'const l = window.location; l.href = "/x";'],
  ["an aliased global object", 'const w = window; w.location.assign("/x");'],
  ["a chain of aliases", 'const a = location; const b = a; b.assign("/x");'],
  ["a reload", "globalThis.location.reload();"],
  ["a bare reload", "location.reload();"],
  ["open into the current context", 'window.open("/x", "_self");'],
  ["open into the top context", 'window.open("/x", "_top");'],
  ["a bare open into the current context", 'open("/x", "_self");'],
  ["assigning the global binding itself", 'location = "/x";'],
  ["a template-literal computed key", 'globalThis[`location`].assign("/x");'],
  ["a nested global receiver", 'window.top.location.assign("/x");'],
  ["a receiver the selectors never enumerated", 'frames.location.assign("/x");'],
];

const DOMAIN_NEGATIVES: ReadonlyArray<readonly [string, string]> = [
  [
    "a domain string field calling replace",
    'const w = { location: "a b" }; w.location.replace(/\\s+/g, "-");',
  ],
  ["a domain field being assigned", 'const w = { location: "" }; w.location = "A1";'],
  ["a nested domain href", 'const w = { location: { href: "" } }; w.location.href = "x";'],
  ["an unrelated assign call", 'const o = { assign: (_: string) => {} }; o.assign("/x");'],
  [
    "a domain field reached by computed access",
    'const s = { location: { assign: (_: string) => {} } }; s["location"].assign("/x");',
  ],
  // A receiver merely *named* like a browser global is ordinary code — `parent` and `top` are
  // tree-node vocabulary in an ERP and `href` is an ordinary nav-entry field. Keeping `href`
  // coupled to a `location` object is what separates them; factoring the property check away
  // from the object check pairs every `href` with every global name.
  ["an href field on a receiver named parent", 'const parent = { href: "" }; parent.href = "/x";'],
  ["an href field on a receiver named top", 'const top = { href: "" }; top.href = "/x";'],
  [
    "an href field on a receiver named document",
    'const document = { href: "" }; document.href = "/x";',
  ],
  ["a plain anchor href", 'const anchor = { href: "" }; anchor.href = "/x";'],
  // The four the selectors reported and this rule does not. Each is a LOCAL binding that merely
  // shares a name with a global, which is exactly what a scope lookup can see and a name match
  // cannot — and the last two are the likeliest domain shapes of the set.
  [
    "a local named parent carrying a domain location",
    'const parent = { location: "a b" }; parent.location.replace(/ /g, "-");',
  ],
  [
    "a local named self reached by computed access",
    'const self = { location: { assign: (_: string) => {} } }; self["location"].assign("x");',
  ],
  ["a local named location", 'const location = "A 1"; location.replace(/ /g, "-");'],
  [
    "a location destructured out of a domain object",
    'const warehouse = { location: "A 1" }; const { location } = warehouse; location.replace(/ /g, "-");',
  ],
  // An alias is followed only through `const` with a plain name — a destructuring pattern binds a
  // FIELD, never the object it came from.
  [
    "an alias of a domain object",
    'const warehouse = { location: { assign: (_: string) => {} } }; const w = warehouse; w.location.assign("/x");',
  ],
  // No target means a NEW browsing context, which leaves this document alone.
  ["open with no target", 'window.open("/x");'],
  ["open into a new context", 'window.open("/x", "_blank");'],
  // Reads are not navigations.
  ["reading the path", "const p = globalThis.location.pathname;"],
];

describe("the hard-navigation gate", () => {
  it.each(POSITIVES)("flags %s", (_label, code) => {
    expect(navigationErrorsIn(code)).toBe(1);
  });

  it.each(DOMAIN_NEGATIVES)("leaves %s alone", (_label, code) => {
    expect(navigationErrorsIn(code)).toBe(0);
  });

  it("still buys what the receiver enumeration bought: a receiver outside the set", () => {
    // The negatives above prove locals are excluded; this proves the rule is not simply quiet.
    // `site` is neither a global nor an alias of one, so its `.location` is a domain field.
    expect(
      navigationErrorsIn(
        'const site = { location: { assign: (_: string) => {} } }; site["location"].assign("x");',
      ),
    ).toBe(0);
    // And a global receiver still reports, which is what makes the line above a real exclusion
    // rather than a rule that stopped firing.
    expect(navigationErrorsIn('globalThis.location.assign("x");')).toBe(1);
  });

  it("does not claim the blind spots that need types rather than scope", () => {
    // Recorded as tests so the limits stay as legible as the coverage, and so a future widening
    // shows up here as a decision rather than as a silent behaviour change. What is left is not a
    // matter of matching harder: each needs a fact a scope manager does not hold.
    //
    // Whether `iframe` is an element is a TYPE question.
    expect(navigationErrorsIn('iframe.contentWindow.location.replace("/x");')).toBe(0);
    // The call site names no receiver at all, so nothing at that node separates it from any other
    // one-argument call.
    expect(navigationErrorsIn('const { assign } = location; assign("/x");')).toBe(0);
    // Reflective reaches, and a receiver produced by a branch rather than named.
    expect(navigationErrorsIn('Object.assign(location, { href: "/x" });')).toBe(0);
    expect(navigationErrorsIn('(true ? window : self).location.assign("/x");')).toBe(0);
    // A computed key that is not statically readable. The template literal WITH substitutions is
    // the same class; the one without is claimed above, because it is a string literal in
    // backticks.
    expect(navigationErrorsIn('const k = "location"; globalThis[k].assign("/x");')).toBe(0);
    // An alias is read from its single `const` declaration, so a binding that acquires its meaning
    // by later assignment is deliberately not chased — following that means a dataflow pass whose
    // first job would be being right about branches.
    expect(navigationErrorsIn('let l; l = location; l.assign("/x");')).toBe(0);
  });

  it("reports everything the upstream rule would, so this gate is the whole control", () => {
    // The corpus is generated, deliberately NOT drawn from POSITIVES. Filtering POSITIVES by
    // "the upstream rule sees it" would make the loop circular: every entry there is asserted
    // `toBe(1)` against the selectors two tests above, so a shape the upstream rule reports and
    // the selectors miss could never enter the universe being checked. A gap has to be able to
    // appear before an assertion about gaps means anything.
    // A stated limit rather than an instrumented one: this axis is OUR enumeration, so a receiver
    // upstream starts recognising and we do not — `frames`, `opener`, anything a future release
    // adds to its GLOBAL_PREFIXES — is never emitted here and the gap it opens cannot appear.
    // Declaring those names in the config's globals purely so this loop could see them would be
    // config nobody reads against an event nobody has had; the honest form is this sentence.
    const receivers = ["window", "globalThis", "self", "document", "top", "parent", ""];
    const corpus: string[] = [];
    for (const receiver of receivers) {
      for (const loc of receiver === ""
        ? ["location"]
        : [`${receiver}.location`, `${receiver}["location"]`]) {
        corpus.push(
          `${loc}.assign("/x");`,
          `${loc}["assign"]("/x");`,
          `${loc}.replace("/x");`,
          `${loc}.href = "/x";`,
          `${loc}["href"] = "/x";`,
        );
      }
      if (receiver !== "") {
        corpus.push(`${receiver}.location = "/x";`, `${receiver}["location"] = "/x";`);
      }
    }

    const seenByUpstream = corpus.filter((code) => upstreamErrorsIn(code) > 0);

    // Guards the other way: a rule reporting nothing is trivially contained, which is exactly
    // what the shipped config's `off` makes it.
    expect(seenByUpstream.length).toBeGreaterThan(0);

    // Guards the globals declaration itself. Every receiver below resolves only because
    // `languageOptions.globals` names it; `globalThis` is deliberately not among them, because it
    // resolves as an ES builtin either way and so cannot witness the block's removal.
    for (const receiver of ["window.", "document.", "self.", "location."]) {
      expect(seenByUpstream.some((code) => code.startsWith(receiver))).toBe(true);
    }

    const gaps = seenByUpstream.filter((code) => navigationErrorsIn(code) === 0);
    expect(gaps).toEqual([]);
  });

  it("reports shapes the upstream rule structurally cannot", () => {
    // It never inspects replace(), and it gives up on a destination it cannot fold to a
    // literal — which is what the real call site passes (`Routes.HOME`, an imported binding).
    // Those two gaps are why this gate exists rather than a severity bump.
    expect(upstreamErrorsIn('globalThis.location.replace("/x");')).toBe(0);
    expect(navigationErrorsIn('globalThis.location.replace("/x");')).toBe(1);

    const imported = 'import { R } from "./r"; globalThis.location.assign(R.HOME);';
    expect(upstreamErrorsIn(imported)).toBe(0);
    expect(navigationErrorsIn(imported)).toBe(1);
  });
});
