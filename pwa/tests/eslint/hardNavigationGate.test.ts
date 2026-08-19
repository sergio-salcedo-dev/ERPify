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
 */
const linter = new Linter();

function navigationErrorsIn(code: string): number {
  return linter
    .verify(code, config as never, "src/probe.ts")
    .filter((message) => message.ruleId === "no-restricted-syntax").length;
}

describe("the hard-navigation gate", () => {
  it.each([
    ["assign on globalThis", 'globalThis.location.assign("/x");'],
    ["replace on window", 'window.location.replace("/x");'],
    ["assign on a bare receiver", 'location.assign("/x");'],
    ["replace on document", 'document.location.replace("/x");'],
    ["href assignment", 'globalThis.location.href = "/x";'],
    ["href on a bare receiver", 'location.href = "/x";'],
    ["whole-location assignment", 'document.location = "/x";'],
    ["computed member access", 'globalThis.location["assign"]("/x");'],
  ])("flags %s", (_label, code) => {
    expect(navigationErrorsIn(code)).toBe(1);
  });

  it.each([
    [
      "a domain string field calling replace",
      'const w = { location: "a b" }; w.location.replace(/\\s+/g, "-");',
    ],
    ["a domain field being assigned", 'const w = { location: "" }; w.location = "A1";'],
    ["a nested domain href", 'const w = { location: { href: "" } }; w.location.href = "x";'],
    ["an unrelated assign call", 'const o = { assign: (_: string) => {} }; o.assign("/x");'],
  ])("leaves %s alone", (_label, code) => {
    expect(navigationErrorsIn(code)).toBe(0);
  });

  it("does not claim the blind spots it cannot see", () => {
    // Recorded as tests so the limits are as legible as the coverage, and so a future widening
    // shows up here as a decision rather than as a silent behaviour change.
    expect(navigationErrorsIn('const l = globalThis.location; l.assign("/x");')).toBe(0);
    expect(navigationErrorsIn("globalThis.location.reload();")).toBe(0);
    expect(navigationErrorsIn('window.open("/x", "_self");')).toBe(0);
  });
});
