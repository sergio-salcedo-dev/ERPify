import { describe, expect, it } from "vitest";
import { Linter } from "eslint";
import config from "../../eslint.config.mjs";

/**
 * The hard-navigation selectors are a gate, and a gate nobody re-runs is a claim, not a
 * control: provoking its reds by hand proves it worked once, in a session whose notes are
 * deleted when the branch closes. This runs the real `eslint.config.mjs` — imported, never
 * transcribed, because a copied selector proves the copy works — and asserts both directions.
 *
 * The negatives are the half that decays silently. An ERP has `warehouse.location`, and a
 * rule keyed on "any object with a `.location`" fires on a string field that never navigates;
 * the disable that follows is rule-wide, so it would also switch off the `maxLength` and
 * test-id contract bans on that line. Widening the selectors resurrects exactly that.
 *
 * The third direction is containment. `eslint.config.mjs` ships the upstream Next rule off on
 * the grounds that these selectors already report everything it reports — a claim that decays
 * the moment a selector is narrowed, and one no assertion over the shipped config can make,
 * because a rule set to `off` reports nothing whatever the selectors do. So the rule is forced
 * back to `error` in a cloned config and the two are compared fixture by fixture.
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

function navigationErrorsIn(code: string): number {
  return linter
    .verify(code, config as never, "src/probe.ts")
    .filter((message) => message.ruleId === "no-restricted-syntax").length;
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
];

describe("the hard-navigation gate", () => {
  it.each(POSITIVES)("flags %s", (_label, code) => {
    expect(navigationErrorsIn(code)).toBe(1);
  });

  it.each(DOMAIN_NEGATIVES)("leaves %s alone", (_label, code) => {
    expect(navigationErrorsIn(code)).toBe(0);
  });

  it("accepts a known cost: a local named like a global is swept in", () => {
    // `parent`, `top`, `self` and `document` are enumerated because they are real cross-frame
    // navigation receivers — and they are also ordinary local-variable names. So a domain object
    // called one of them is a false positive, and this is the price of the enumeration rather
    // than an oversight. Recorded as a passing test so narrowing the set later is a decision:
    // dropping them would silence `top.location.assign(u)`, which is a genuine navigation.
    expect(
      navigationErrorsIn('const parent = { location: "a b" }; parent.location.replace(/ /g, "-");'),
    ).toBe(1);
    expect(
      navigationErrorsIn(
        'const self = { location: { assign: (_: string) => {} } }; self["location"].assign("x");',
      ),
    ).toBe(1);
    // A receiver outside the set is what the enumeration actually buys.
    expect(
      navigationErrorsIn(
        'const site = { location: { assign: (_: string) => {} } }; site["location"].assign("x");',
      ),
    ).toBe(0);
  });

  it("does not claim the blind spots it cannot see", () => {
    // Recorded as tests so the limits are as legible as the coverage, and so a future widening
    // shows up here as a decision rather than as a silent behaviour change.
    expect(navigationErrorsIn('const l = globalThis.location; l.assign("/x");')).toBe(0);
    expect(navigationErrorsIn("globalThis.location.reload();")).toBe(0);
    expect(navigationErrorsIn('window.open("/x", "_self");')).toBe(0);
    // A template-literal key is one backtick away from the computed shape the selectors do match:
    // `property.value` exists on a Literal, not on a TemplateLiteral.
    expect(navigationErrorsIn('globalThis[`location`].assign("/x");')).toBe(0);
    // And the receiver arms reach one level, so the canonical cross-frame spelling escapes while
    // its bare form (`top.location.assign(u)`) is flagged — an asymmetry worth stating.
    expect(navigationErrorsIn('window.top.location.assign("/x");')).toBe(0);
    expect(navigationErrorsIn('iframe.contentWindow.location.replace("/x");')).toBe(0);
  });

  it("reports everything the upstream rule would, so switching it off costs no coverage", () => {
    // The corpus is generated, deliberately NOT drawn from POSITIVES. Filtering POSITIVES by
    // "the upstream rule sees it" would make the loop circular: every entry there is asserted
    // `toBe(1)` against the selectors two tests above, so a shape the upstream rule reports and
    // the selectors miss could never enter the universe being checked. A gap has to be able to
    // appear before an assertion about gaps means anything.
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

    const gaps = seenByUpstream.filter((code) => navigationErrorsIn(code) === 0);
    expect(gaps).toEqual([]);
  });

  it("reports shapes the upstream rule structurally cannot", () => {
    // It never inspects replace(), and it gives up on a destination it cannot fold to a
    // literal — which is what the two real call sites pass (`Routes.HOME`, an imported
    // binding). Those two gaps are why the selectors exist rather than a severity bump.
    expect(upstreamErrorsIn('globalThis.location.replace("/x");')).toBe(0);
    expect(navigationErrorsIn('globalThis.location.replace("/x");')).toBe(1);

    const imported = 'import { R } from "./r"; globalThis.location.assign(R.HOME);';
    expect(upstreamErrorsIn(imported)).toBe(0);
    expect(navigationErrorsIn(imported)).toBe(1);
  });
});
