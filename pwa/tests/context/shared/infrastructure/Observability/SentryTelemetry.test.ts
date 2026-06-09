import { afterEach, describe, expect, it, vi } from "vitest";

const { captureException, captureMessage, scope } = vi.hoisted(() => ({
  captureException: vi.fn(),
  captureMessage: vi.fn(),
  scope: { setLevel: vi.fn(), setTag: vi.fn(), setContext: vi.fn() },
}));

vi.mock("@sentry/nextjs", () => ({
  withScope: (callback: (s: typeof scope) => void) => callback(scope),
  captureException,
  captureMessage,
}));

import { SentryTelemetry } from "@/context/shared/infrastructure/Observability/SentryTelemetry";

afterEach(() => {
  vi.clearAllMocks();
});

describe("SentryTelemetry", () => {
  it("captures an Error cause as an exception, tagging scope and attaching the label", () => {
    const cause = new Error("boom");
    new SentryTelemetry().error("render failed", { scope: "error:segment", cause });

    expect(scope.setLevel).toHaveBeenCalledWith("error");
    expect(scope.setTag).toHaveBeenCalledWith("telemetry.scope", "error:segment");
    expect(scope.setContext).toHaveBeenCalledWith("telemetry", { message: "render failed" });
    expect(captureException).toHaveBeenCalledWith(cause);
    expect(captureMessage).not.toHaveBeenCalled();
  });

  it("maps warn to the Sentry 'warning' level via captureMessage", () => {
    new SentryTelemetry().warn("slow hub", { scope: "realtime:bank" });

    expect(scope.setLevel).toHaveBeenCalledWith("warning");
    expect(captureMessage).toHaveBeenCalledWith("slow hub", "warning");
    expect(captureException).not.toHaveBeenCalled();
  });

  it("serializes + scrubs a non-Error cause into context (single, unwrapped layer)", () => {
    new SentryTelemetry().error("bad payload", { cause: { token: "secret-abc", id: 7 } });

    expect(scope.setContext).toHaveBeenCalledWith("cause", { value: { id: 7 } });
    expect(captureMessage).toHaveBeenCalledWith("bad payload", "error");
  });
});
