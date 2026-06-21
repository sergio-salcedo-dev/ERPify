import { afterEach, describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import { RootErrorBoundary, SegmentErrorBoundary } from "@/context/shared/error/infrastructure/ui";
import { telemetry } from "@/context/shared/observability/infrastructure";

/**
 * Locks the observability contract for the Next.js error boundaries: a caught
 * render error / root-layout crash MUST be routed through the `Telemetry` port
 * (`telemetry.error`), never a bare `console.error`. The port's adapter owns
 * env-gating (dev/staging console, prod silent → future Sentry/Datadog), so the
 * boundary stays a thin, sink-agnostic hook. The browser-rendering redaction
 * policy (no `error.message` in prod) is locked separately in
 * `tests/app/error-redaction.test.tsx`.
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
  expectedMessage: string;
  expectedScope: string;
}

const BOUNDARIES: ReadonlyArray<Boundary> = [
  {
    name: "SegmentErrorBoundary (error.tsx)",
    Component: SegmentErrorBoundary,
    expectedMessage: "segment error boundary caught",
    expectedScope: "error:segment",
  },
  {
    name: "RootErrorBoundary (global-error.tsx)",
    Component: RootErrorBoundary,
    expectedMessage: "root layout crashed",
    expectedScope: "error:root",
  },
];

afterEach(() => {
  vi.restoreAllMocks();
});

describe.each(BOUNDARIES)(
  "$name — routes the caught error through telemetry.error",
  ({ Component, expectedMessage, expectedScope }) => {
    it("reports the error to the Telemetry port with its scope and cause", () => {
      const errorSpy = vi.spyOn(telemetry, "error").mockImplementation(() => {});
      const caught = new Error("boom");

      render(<Component error={caught} reset={() => {}} />);

      expect(errorSpy).toHaveBeenCalledTimes(1);
      expect(errorSpy).toHaveBeenCalledWith(expectedMessage, {
        scope: expectedScope,
        cause: caught,
      });
    });
  },
);
