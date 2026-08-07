import prettier from "eslint-plugin-prettier";
import prettierConfig from "eslint-config-prettier";
import tsParser from "@typescript-eslint/parser";
import tsPlugin from "@typescript-eslint/eslint-plugin";
import { fixupPluginRules } from "@eslint/compat";
import reactPlugin from "eslint-plugin-react";
import hooksPlugin from "eslint-plugin-react-hooks";
import nextPlugin from "@next/eslint-plugin-next";

const eslintConfig = [
  {
    ignores: [
      ".next/**",
      ".next-e2e/**",
      "node_modules/**",
      "dist/**",
      "build/**",
      "reports/**",
      "next-env.d.ts",
    ],
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
  prettierConfig,
];

export default eslintConfig;
