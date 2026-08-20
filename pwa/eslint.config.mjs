import prettier from "eslint-plugin-prettier";
import prettierConfig from "eslint-config-prettier";
import tsParser from "@typescript-eslint/parser";
import tsPlugin from "@typescript-eslint/eslint-plugin";
import { fixupPluginRules } from "@eslint/compat";
import reactPlugin from "eslint-plugin-react";
import hooksPlugin from "eslint-plugin-react-hooks";
import nextPlugin from "@next/eslint-plugin-next";
import jsxA11y from "eslint-plugin-jsx-a11y";

const eslintConfig = [
  {
    ignores: [
      ".next/**",
      ".next-e2e/**",
      "node_modules/**",
      "dist/**",
      "build/**",
      "reports/**",
      "coverage/**",
      "next-env.d.ts",
    ],
  },
  {
    // A stale `eslint-disable` is a reason nobody can check any more, and as a warning it is
    // indistinguishable from silence — `npm run lint` is a bare `eslint .` with no
    // `--max-warnings`, the same reason the Next hard-navigation rule below cannot gate.
    //
    // What an error buys is asymmetric, and worth stating rather than assuming: it reds
    // `pwa.lint.dry-run`, so CI and any check-only run name the directive and its line. It
    // does NOT red `make pwa.quality`, which runs `eslint . --fix` — there ESLint deletes the
    // directive and exits 0, taking the paragraph of reasoning above it and leaving no trace.
    // So this catches a stale exemption before merge, never at the moment it goes stale.
    linterOptions: { reportUnusedDisableDirectives: "error" },
  },
  {
    files: ["**/*.ts", "**/*.tsx", "**/*.js", "**/*.jsx"],
    languageOptions: {
      parser: tsParser,
      parserOptions: {
        ecmaVersion: "latest",
        sourceType: "module",
        ecmaFeatures: {
          jsx: true,
        },
      },
      // Declared inline rather than through the `globals` package, which is in neither
      // package.json nor package-lock.json — `npm ci`, what make/pwa.mk and CI run, leaves
      // `import globals from "globals"` at ERR_MODULE_NOT_FOUND. The list is load-bearing:
      // @next/next/no-location-assign-relative-destination resolves a receiver only through the
      // global scope (`scopeManager.scopes[0].set`), so a name missing here makes that rule blind
      // to it — measured, trimming this block drops the shapes it can see from 36 to 8, and the
      // eight survivors are `globalThis`, which ESLint supplies as an ES builtin from
      // `ecmaVersion` and which is therefore deliberately absent below. `location` is present
      // because the rule's bare-receiver form resolves that identifier itself.
      // tests/eslint/hardNavigationGate.test.ts asserts one shape per receiver, so trimming this
      // list reds it. It is NOT a full browser environment (`fetch`,
      // `localStorage`, `setTimeout` and the rest are absent) — enabling `no-undef` or adding a
      // rule that resolves identifiers globally needs this list widened first.
      globals: {
        window: "readonly",
        document: "readonly",
        location: "readonly",
        self: "readonly",
        top: "readonly",
        parent: "readonly",
      },
    },
    plugins: {
      prettier,
      "@typescript-eslint": tsPlugin,
      react: fixupPluginRules(reactPlugin),
      "react-hooks": fixupPluginRules(hooksPlugin),
      "@next/next": fixupPluginRules(nextPlugin),
    },
    rules: {
      ...tsPlugin.configs.recommended.rules,
      ...reactPlugin.configs.recommended.rules,
      ...hooksPlugin.configs.recommended.rules,
      ...nextPlugin.configs.recommended.rules,
      ...nextPlugin.configs["core-web-vitals"].rules,
      // Kept on, at the severity both presets give it. The selectors below report every shape
      // it reports, plus three it structurally cannot: `location.replace()`, which it never
      // inspects; a destination that does not fold to a literal, which is what our real call
      // sites pass (`Routes.HOME` is an imported binding); and a receiver it does not enumerate.
      // So it is not the control — it is `warn` under an `eslint .` carrying no --max-warnings
      // and cannot turn a gate red in either direction. It is kept because it costs nothing and
      // reads differently: measured with it forced to `error` over src/**/*.{ts,tsx}, 464 files
      // report 0, because both legitimate call sites spell the navigation `location.replace()`.
      // Zero reports means zero extra rule ids and zero extra eslint-disable directives, so
      // turning it off would buy none of the rule-wide-disable savings that argument suggests,
      // and would give up a scope-aware second reader (`isGlobalReference`) that resolves the
      // receiver instead of matching its name — something a syntactic selector cannot do. The
      // containment is asserted, not assumed: tests/eslint/hardNavigationGate.test.ts forces
      // this rule to `error` and requires every line it reports to be one the selectors report
      // too.
      "@next/next/no-location-assign-relative-destination": "warn",
      "prettier/prettier": "error",
      "@typescript-eslint/no-unused-vars": [
        "error",
        { argsIgnorePattern: "^_", varsIgnorePattern: "^_", caughtErrorsIgnorePattern: "^_" },
      ],
      "@typescript-eslint/no-explicit-any": "error",
      "no-console": ["warn", { allow: ["warn", "error"] }],
      "no-restricted-syntax": [
        "error",
        {
          // Keyed on the receiver, not on the destination. The Next rule for this
          // (@next/next/no-location-assign-relative-destination) folds the argument to a static
          // string prefix and gives up on anything it cannot resolve, so how a destination is
          // spelled decides whether it is seen; it also never looks at `replace()`. Enumerating
          // the global receivers instead is what keeps a domain object's `location` field out:
          // an ERP has `warehouse.location`, and a rule that fired on `warehouse.location.replace`
          // would be silenced on lines that never navigate — and this disable is rule-wide, so
          // each one also switches off the maxLength and test-id bans on its line.
          //
          // What a green run does NOT prove: an aliased receiver (`const l = location`) needs
          // scope analysis and escapes; so do `location.reload()` and `window.open(u, "_self")`,
          // which are navigations this rule does not claim. Blind spots are listed in pwa/CLAUDE.md.
          selector:
            "CallExpression:matches([callee.property.name=/^(assign|replace)$/], [callee.computed=true][callee.property.value=/^(assign|replace)$/]):matches([callee.object.object.name=/^(window|globalThis|self|document|top|parent)$/][callee.object.property.name='location'], [callee.object.object.name=/^(window|globalThis|self|document|top|parent)$/][callee.object.computed=true][callee.object.property.value='location'], [callee.object.name='location'])",
          message:
            "A full-document navigation (location.assign/replace) discards all in-memory client state; inside the app use router.push()/replace() from next/navigation. It is legitimate when leaving an authenticated area, or when no React context exists to reach a router from — disable this rule on the line and say which of the two it is.",
        },
        {
          selector:
            "AssignmentExpression:matches([left.property.name='href'][left.object.name='location'], [left.property.name='href'][left.object.object.name=/^(window|globalThis|self|document|top|parent)$/][left.object.property.name='location'], [left.property.name='href'][left.object.object.name=/^(window|globalThis|self|document|top|parent)$/][left.object.computed=true][left.object.property.value='location'], [left.computed=true][left.property.value='href'][left.object.name='location'], [left.computed=true][left.property.value='href'][left.object.object.name=/^(window|globalThis|self|document|top|parent)$/][left.object.property.name='location'], [left.computed=true][left.property.value='href'][left.object.object.name=/^(window|globalThis|self|document|top|parent)$/][left.object.computed=true][left.object.property.value='location'], [left.property.name='location'][left.object.name=/^(window|globalThis|self|document|top|parent)$/], [left.computed=true][left.property.value='location'][left.object.name=/^(window|globalThis|self|document|top|parent)$/])",
          message:
            "Assigning location.href — or the whole location object — is a full-document navigation wearing a property assignment. Same rule as location.assign/replace: use router.push()/replace(), or disable this rule on the line with the reason.",
        },
        {
          selector: "JSXAttribute[name.name='maxLength']",
          message:
            "maxLength silently truncates typed/pasted input. Enforce the limit in the entity's Zod schema (.max()) so the user sees the 'must not exceed' error instead.",
        },
        {
          selector:
            "JSXAttribute[name.name=/^data-testid$|^testId(Prefix)?$|TestId(Prefix)?$/] CallExpression[callee.name=/^(cn|clsx|cx|cva|classNames|twMerge|twJoin)$/]",
          message:
            "A test id (data-testid or a testId/testIdPrefix prop) is a QA contract, independent of styling — never derive it from a class-name helper (cn/clsx/cva/twMerge). Give the element its own stable test id. See docs/adr/test-id-naming-contract.md.",
        },
        {
          selector:
            "JSXAttribute[name.name=/^data-testid$|^testId(Prefix)?$|TestId(Prefix)?$/] Identifier[name='className']",
          message:
            "A test id (data-testid or a testId/testIdPrefix prop) must not be the element's className — it is a QA identifier, not a style hook. Use a dedicated, stable value. See docs/adr/test-id-naming-contract.md.",
        },
        {
          selector:
            "JSXAttribute[name.name=/^data-testid$|^testId(Prefix)?$|TestId(Prefix)?$/] TemplateLiteral Identifier[name=/^(index|idx|i|n)$/]",
          message:
            "A dynamic test id (data-testid or a testId/testIdPrefix prop) must not be keyed by a positional index (index/i/idx/n) — it breaks under reorder, filter, or pagination. Suffix with the entity's stable id (UUID) instead. See docs/adr/test-id-naming-contract.md.",
        },
        {
          selector:
            "JSXAttribute[name.name=/^data-testid$|^testId(Prefix)?$|TestId(Prefix)?$/] > JSXExpressionContainer > BinaryExpression Identifier[name=/^(index|idx|i|n)$/]",
          message:
            "A dynamic test id (data-testid or a testId/testIdPrefix prop) must not be keyed by a positional index (index/i/idx/n) — it breaks under reorder, filter, or pagination. Suffix with the entity's stable id (UUID) instead. See docs/adr/test-id-naming-contract.md.",
        },
        {
          // Both `:not(:has(ArrowFunctionExpression))` guards keep each side's `:has()` inside its own
          // statement: without them the descent reaches into whole `it(…)` callbacks and consecutive
          // `it(…)` statements match each other as the sibling pair.
          selector:
            "ExpressionStatement:not(:has(ArrowFunctionExpression)):has(CallExpression[callee.property.name='getByTestId'] TemplateLiteral[quasis.0.value.raw=/__actions-$/]) ~ ExpressionStatement:not(:has(ArrowFunctionExpression)):has(CallExpression[callee.property.name='findByTestId'] TemplateLiteral[quasis.0.value.raw=/__(delete|revoke)-$/])",
          message:
            "Opening a row's ⋯ overflow menu and then awaiting its item (`…__delete-<id>`, `…__revoke-<id>`) is a race: under jsdom a just-opened Base UI popup can close again before its content mounts, so the single-shot open survives locally and goes red under CI parallelism. Open it through openRowMenuItem(surface, action, id) from tests/app/backoffice/_interactions.ts (or the banks-scoped openRowDeleteItem built on it) — it retries the open, so an item that genuinely never renders still fails.",
        },
      ],
      "react/react-in-jsx-scope": "off", // Next.js doesn't need it
      "react/prop-types": "off", // Using TS
    },
    settings: {
      react: {
        version: "detect",
      },
    },
  },
  {
    files: ["src/components/ui/**/*.{ts,tsx}"],
    rules: {
      "no-restricted-imports": [
        "error",
        {
          patterns: [
            {
              group: ["@/components/erpify", "@/components/erpify/**"],
              message:
                "components/ui is the foundational vendor layer: it must not depend on components/erpify. The design system builds on ui, not the other way round.",
            },
            {
              group: ["@/app", "@/app/**"],
              message:
                "components/ui must not depend on app/ — dependencies point toward the lowest layer.",
            },
            {
              group: ["@/context", "@/context/**"],
              message:
                "components/ui is the foundational vendor layer: it must not reach into the DDD context tree. Foundational presentation helpers it needs (e.g. cn) live at @/components/cn, below the context tree.",
            },
          ],
        },
      ],
    },
  },
  {
    files: ["src/components/erpify/**/*.{ts,tsx}"],
    rules: {
      "no-restricted-imports": [
        "error",
        {
          patterns: [
            {
              group: [
                "@/context/backoffice",
                "@/context/backoffice/**",
                "@/context/frontoffice",
                "@/context/frontoffice/**",
              ],
              message:
                "components/erpify is a business-agnostic design system: it must not depend on a bounded context (backoffice/frontoffice). Pass data in via props/composition.",
            },
            {
              group: ["@/app", "@/app/**"],
              message: "components/erpify must not depend on app/.",
            },
          ],
        },
      ],
    },
  },
  {
    // The accessibility family SonarCloud reports (S6822, S6847, S6853 …) is this plugin
    // wrapped, so without this block the only reader of an a11y regression is a cloud
    // analysis that runs after the merge. The plugin was a declared devDependency nothing
    // imported until now.
    //
    // `files` is set here rather than spreading `flatConfigs.recommended` whole: that export
    // carries no `files` of its own and drags `parserOptions.ecmaFeatures.jsx` onto every
    // `.mjs`/`.cjs` espree parses. Only `.tsx`/`.jsx` can ever produce a finding.
    //
    // The rule set follows upstream instead of being frozen here, so a release that adds a
    // rule is adopted rather than silently skipped. The direction that costs — a release
    // that moves a rule into the set and reds product files under a `chore(deps)` title —
    // is bounded by tests/eslint/jsxA11yRuleSetGate.test.ts, which names the moved rule.
    //
    // Without `components` the plugin only inspects intrinsic elements, and this tree hands
    // almost every interactive control to a wrapper: 66 `<Link>`, 80 `<Button>`, 21 `<Input>`
    // against a single intrinsic `<label>`. That is not a small blind spot, it is most of the
    // back-office. Measured, closing it costs nothing — the mapping below adds zero findings —
    // and the mapping is live rather than inert: pointed at `a` instead, `Button` alone
    // produces 191.
    files: ["**/*.tsx", "**/*.jsx"],
    plugins: { "jsx-a11y": jsxA11y },
    settings: {
      "jsx-a11y": {
        components: {
          Button: "button",
          CopyButton: "button",
          DateField: "input",
          Input: "input",
          Label: "label",
          Link: "a",
          SingleLineTextarea: "textarea",
        },
      },
    },
    rules: jsxA11y.flatConfigs.recommended.rules,
  },
  prettierConfig,
];

export default eslintConfig;
