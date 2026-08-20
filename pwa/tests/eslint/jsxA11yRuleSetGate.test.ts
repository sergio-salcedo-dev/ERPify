import { describe, expect, it } from "vitest";
import { ESLint, Linter } from "eslint";
import config from "../../eslint.config.mjs";

/**
 * `eslint.config.mjs` follows upstream's `recommended` rather than freezing a list, so a
 * release that adds a rule is adopted instead of silently skipped. The cost of that choice is
 * paid here.
 *
 * Measured on this tree, the set is one third of what the plugin ships: `recommended` reports
 * 5 findings, every shipped rule forced on reports 54 — 21 of them `prefer-tag-over-role` and
 * 12 `no-onchange`, neither of which the set lists, plus 8 and 7 behind two rules the set
 * carries as `off`. So a minor that flips one `off`, or promotes one unlisted rule, reds
 * product files in bulk under a `chore(deps)` title that mentions none of them. Upstream
 * writes the intent down: `lib/index.js` carries `'jsx-a11y/anchor-ambiguous-text': 'off'`
 * with a `// TODO: error` beside it.
 *
 * Freezing the list instead trades that red for silence — a rule added upstream would never
 * arrive and nothing would say so. Classifying is the third option: the set still follows
 * upstream, and a move reds THIS test naming the rule, so the dependabot PR gets a decision
 * point rather than an opaque wall of findings.
 *
 * Severity is read from the RESOLVED config, never from the block that declares it. ESLint
 * applies the last matching block, so reading the declaration would let a later block downgrade
 * the whole set to `warn` — invisible under an `eslint .` carrying no `--max-warnings` — or
 * switch rules off outright, with every assertion here still green.
 *
 * A green proves the set is resolved as recorded, that the design-system mapping is in place,
 * and that the config is live (a real finding is produced through it). It proves nothing about
 * a rule's OPTIONS: six entries carry an options object, and upstream changing one widens or
 * narrows coverage with every assertion here still green.
 */
type Severity = "error" | "warn" | "off" | "absent";

// Every rule the plugin ships, at the severity the resolved config applies. `absent` is shipped
// but not in the set — coverage deliberately not taken, not an oversight.
const CLASSIFIED: Readonly<Record<string, Severity>> = {
  "accessible-emoji": "absent",
  "alt-text": "error",
  "anchor-ambiguous-text": "off",
  "anchor-has-content": "error",
  "anchor-is-valid": "error",
  "aria-activedescendant-has-tabindex": "error",
  "aria-props": "error",
  "aria-proptypes": "error",
  "aria-role": "error",
  "aria-unsupported-elements": "error",
  "autocomplete-valid": "error",
  "click-events-have-key-events": "error",
  "control-has-associated-label": "off",
  "heading-has-content": "error",
  "html-has-lang": "error",
  "iframe-has-title": "error",
  "img-redundant-alt": "error",
  "interactive-supports-focus": "error",
  "label-has-associated-control": "error",
  "label-has-for": "off",
  lang: "absent",
  "media-has-caption": "error",
  "mouse-events-have-key-events": "error",
  "no-access-key": "error",
  "no-aria-hidden-on-focusable": "absent",
  "no-autofocus": "error",
  "no-distracting-elements": "error",
  "no-interactive-element-to-noninteractive-role": "error",
  "no-noninteractive-element-interactions": "error",
  "no-noninteractive-element-to-interactive-role": "error",
  "no-noninteractive-tabindex": "error",
  "no-onchange": "absent",
  "no-redundant-roles": "error",
  "no-static-element-interactions": "error",
  "prefer-tag-over-role": "absent",
  "role-has-required-aria-props": "error",
  "role-supports-aria-props": "error",
  scope: "error",
  "tabindex-no-positive": "error",
};

// Without this mapping the plugin inspects intrinsic elements only, and this tree routes almost
// every interactive control through a wrapper — so the set would be green over a back-office it
// never looked at.
const MAPPED_COMPONENTS: Readonly<Record<string, string>> = {
  Button: "button",
  CopyButton: "button",
  DateField: "input",
  Input: "input",
  Label: "label",
  Link: "a",
  SingleLineTextarea: "textarea",
};

// `.tsx` under `src/`, so the resolved config is the one a component actually gets. The file
// need not exist: resolution is by path.
const PROBE = "src/probe.tsx";

// Resolution is relative to the working directory, which is `pwa/` — the same place
// `npm run lint` runs from. Getting that wrong cannot pass quietly: ESLint throws when it finds
// no config file, and a config without this plugin empties `shippedRules` and reds below.
const resolved = await new ESLint().calculateConfigForFile(PROBE);

// The plugin object arrives on the resolved config, so the inventory below is compared against
// what the run actually loaded rather than against a separate import — which would also have
// meant a `@types/…` dependency this change has no other reason to add.
const shippedRules = Object.keys(
  (resolved.plugins?.["jsx-a11y"] as { rules?: Record<string, unknown> } | undefined)?.rules ?? {},
);

const SEVERITY_NAME: Readonly<Record<number, Severity>> = { 0: "off", 1: "warn", 2: "error" };

function severityOf(rule: string): Severity {
  const entry = resolved.rules?.[`jsx-a11y/${rule}`];
  if (entry === undefined) return "absent";
  return SEVERITY_NAME[Array.isArray(entry) ? Number(entry[0]) : Number(entry)] ?? "absent";
}

describe("jsx-a11y rule set", () => {
  it("is loaded by the resolved config a component gets", () => {
    // A run that loaded nothing under this name would leave every inventory assertion below
    // comparing empty against empty.
    expect(shippedRules.length).toBeGreaterThan(0);
  });

  it("accounts for every rule the installed plugin ships", () => {
    // Sorted names, so a release adding or removing a rule reds here naming it rather than
    // reporting a count.
    expect(Object.keys(CLASSIFIED).sort()).toEqual([...shippedRules].sort());
  });

  it("resolves each shipped rule to the severity recorded here", () => {
    const actual = Object.fromEntries(shippedRules.map((rule) => [rule, severityOf(rule)]));
    expect(actual).toEqual(CLASSIFIED);
  });

  it("resolves a set that is neither empty nor wholly on", () => {
    // Asserted over the RESOLVED severities, not over the constant: an assertion about the
    // constant alone is a statement about this file, and would hold under any config at all.
    const states = shippedRules.map(severityOf);
    expect(states.filter((state) => state === "error").length).toBeGreaterThan(25);
    expect(states).toContain("off");
    expect(states).toContain("absent");
  });

  it("tells the plugin which components stand in for intrinsic elements", () => {
    expect(resolved.settings?.["jsx-a11y"]?.components).toEqual(MAPPED_COMPONENTS);
  });

  it("is live — the config actually produces an a11y finding", () => {
    // Resolved and applied are different claims. This asserts the second through the real
    // config, so a set that resolves correctly but reaches no file cannot pass on its own data.
    const messages = new Linter().verify(
      'export const P = () => <output role="status" />;',
      config as never,
      PROBE,
    );
    expect(messages.map((message) => message.ruleId)).toContain("jsx-a11y/no-redundant-roles");
  });
});
