import { afterEach, describe, expect, it, vi } from "vitest";

const { captureMessage } = vi.hoisted(() => ({ captureMessage: vi.fn() }));

vi.mock("@sentry/nextjs", () => ({
  withScope: (callback: (s: unknown) => void) =>
    callback({ setLevel: vi.fn(), setTag: vi.fn(), setContext: vi.fn() }),
  captureMessage,
  captureException: vi.fn(),
}));

import { createTelemetry } from "@/context/shared/infrastructure/Observability";

afterEach(() => {
  vi.unstubAllEnvs();
  vi.clearAllMocks();
  vi.restoreAllMocks();
});

describe("createTelemetry sink selection", () => {
  it("does NOT add the Sentry sink when no DSN is configured", () => {
    vi.stubEnv("NEXT_PUBLIC_SENTRY_DSN", "");
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "prod"); // keep console silent
    vi.spyOn(console, "error").mockImplementation(() => {});

    createTelemetry().error("boom", { scope: "error:segment" });

    expect(captureMessage).not.toHaveBeenCalled();
  });

  it("does NOT add the Sentry sink for a whitespace-only DSN", () => {
    vi.stubEnv("NEXT_PUBLIC_SENTRY_DSN", "   ");
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "prod");
    vi.spyOn(console, "error").mockImplementation(() => {});

    createTelemetry().error("boom", { scope: "error:segment" });

    expect(captureMessage).not.toHaveBeenCalled();
  });

  it("adds the Sentry sink when a DSN is configured", () => {
    vi.stubEnv("NEXT_PUBLIC_SENTRY_DSN", "https://key@o0.ingest.de.sentry.io/1");
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "prod");
    vi.spyOn(console, "error").mockImplementation(() => {});

    createTelemetry().error("boom", { scope: "error:segment" });

    expect(captureMessage).toHaveBeenCalledWith("boom", "error");
  });
});
