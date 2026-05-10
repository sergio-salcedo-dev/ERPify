import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import GlobalError from "@/app/global-error";
import ErrorBoundary from "@/app/error";
import { NodeEnv } from "@/context/shared/domain/types/nodeEnv";

/**
 * Locks the production redaction rule for the Next.js error boundaries:
 * "in production only generic messages may be rendered". The dev-mode
 * details block (`error.message`) and any framework / runtime metadata
 * (Symfony class names, file paths, line numbers, stack traces, secrets)
 * MUST be invisible to the browser in `NODE_ENV=production`.
 *
 * The matching dev disclosure ("in development, surface the original
 * `error.message` so engineers see what blew up") is asserted in the
 * sibling test cases.
 */

vi.mock("next/navigation", () => ({
  // `<ErrorActions>` calls these inside the boundaries; jsdom isn't a Next
  // runtime, so we stub them out with safe defaults.
  useRouter: () => ({ back: vi.fn(), push: vi.fn() }),
  usePathname: () => "/",
  notFound: vi.fn(),
}));

interface Boundary {
  name: string;
  Component: (props: { error: Error & { digest?: string }; reset: () => void }) => React.ReactNode;
  testIdPrefix: string;
  expectedTitle: string;
  expectedStatus: string;
}

const BOUNDARIES: ReadonlyArray<Boundary> = [
  {
    name: "error.tsx (segment-level 500 boundary)",
    Component: ErrorBoundary,
    testIdPrefix: "error-page",
    expectedTitle: "Something went wrong",
    expectedStatus: "Error 500",
  },
  {
    name: "global-error.tsx (RootLayout crash)",
    Component: GlobalError,
    testIdPrefix: "global-error",
    expectedTitle: "The application could not start",
    expectedStatus: "Critical error",
  },
];

const SENSITIVE_MESSAGE =
  "Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException at /app/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php:41 — secret token=sk_live_42";

describe.each(BOUNDARIES)(
  "$name — production redaction (only generic messages allowed)",
  ({ Component, testIdPrefix, expectedTitle, expectedStatus }) => {
    beforeEach(() => {
      vi.stubEnv("NODE_ENV", NodeEnv.PRODUCTION);
    });

    afterEach(() => {
      vi.unstubAllEnvs();
    });

    it("never renders the original error.message", () => {
      const { queryByTestId, container } = render(
        <Component
          error={Object.assign(new Error(SENSITIVE_MESSAGE), { digest: "1465261818" })}
          reset={() => {}}
        />,
      );

      expect(queryByTestId(`${testIdPrefix}__details`)).toBeNull();
      expect(container.textContent ?? "").not.toContain(SENSITIVE_MESSAGE);
    });

    it("never leaks framework class names, file paths, line numbers or secrets", () => {
      const { container } = render(
        <Component
          error={Object.assign(new Error(SENSITIVE_MESSAGE), { digest: "1465261818" })}
          reset={() => {}}
        />,
      );

      const text = container.textContent ?? "";
      expect(text).not.toMatch(/Symfony\\Component/);
      expect(text).not.toMatch(/HttpKernel/);
      expect(text).not.toMatch(/\.php/);
      expect(text).not.toMatch(/BankSearchController/);
      expect(text).not.toMatch(/sk_live_/);
      expect(text).not.toMatch(/\bsecret\b/i);
      expect(text).not.toMatch(/\btoken\b/i);
    });

    it("renders only generic copy", () => {
      const { getByTestId } = render(
        <Component
          error={Object.assign(new Error(SENSITIVE_MESSAGE), { digest: "1465261818" })}
          reset={() => {}}
        />,
      );

      expect(getByTestId(`${testIdPrefix}__title`).textContent).toBe(expectedTitle);
      expect(getByTestId(`${testIdPrefix}__status`).textContent).toBe(expectedStatus);
    });

    it("still surfaces the opaque digest so users can quote it to support", () => {
      const digest = "1465261818";
      const { getByTestId } = render(
        <Component
          error={Object.assign(new Error(SENSITIVE_MESSAGE), { digest })}
          reset={() => {}}
        />,
      );

      // The digest is a Next-generated correlation hash, NOT a source-leaking
      // string — keeping it visible is part of the "generic but actionable"
      // contract. If this changes (e.g. Next starts hashing source paths into
      // it), revisit the production policy in pwa/docs/error-pages-testing.md.
      expect(getByTestId(`${testIdPrefix}__digest-value`).textContent).toBe(digest);
    });
  },
);

describe.each(BOUNDARIES)(
  "$name — development disclosure (engineers see what blew up)",
  ({ Component, testIdPrefix }) => {
    beforeEach(() => {
      vi.stubEnv("NODE_ENV", NodeEnv.DEVELOPMENT);
    });

    afterEach(() => {
      vi.unstubAllEnvs();
    });

    it("renders error.message inside the details block", () => {
      const message = "thrown in dev";
      const { getByTestId } = render(
        <Component error={Object.assign(new Error(message), { digest: "d1" })} reset={() => {}} />,
      );

      expect(getByTestId(`${testIdPrefix}__details`).textContent).toContain(message);
    });
  },
);
