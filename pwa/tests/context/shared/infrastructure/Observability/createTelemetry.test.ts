import { afterEach, describe, expect, it, vi } from "vitest";

const { captureMessage } = vi.hoisted(() => ({ captureMessage: vi.fn() }));

vi.mock("@sentry/nextjs", () => ({
  withScope: (callback: (s: unknown) => void) =>
    callback({ setLevel: vi.fn(), setTag: vi.fn(), setContext: vi.fn() }),
  captureMessage,
  captureException: vi.fn(),
}));

import { createTelemetry } from "@/context/shared/Observability/infrastructure";

afterEach(() => {
  vi.unstubAllEnvs();
  vi.clearAllMocks();
  vi.restoreAllMocks();
});

/**
 * Builds telemetry with the given DSN and emits one error. APP_ENV=prod keeps the
 * console adapter silent so the assertion isolates whether the Sentry sink fired.
 */
function emitErrorWithDsn(dsn: string): void {
  vi.stubEnv("NEXT_PUBLIC_SENTRY_DSN", dsn);
  vi.stubEnv("NEXT_PUBLIC_APP_ENV", "prod");
  vi.spyOn(console, "error").mockImplementation(() => {});
  createTelemetry().error("boom", { scope: "error:segment" });
}

describe("createTelemetry sink selection", () => {
  it("does NOT add the Sentry sink when no DSN is configured", () => {
    emitErrorWithDsn("");
    expect(captureMessage).not.toHaveBeenCalled();
  });

  it("does NOT add the Sentry sink for a whitespace-only DSN", () => {
    emitErrorWithDsn("   ");
    expect(captureMessage).not.toHaveBeenCalled();
  });

  it("does NOT add the Sentry sink for a malformed (non-https) DSN", () => {
    emitErrorWithDsn("key@o0.ingest.de.sentry.io/1");
    expect(captureMessage).not.toHaveBeenCalled();
  });

  it("adds the Sentry sink when a DSN is configured", () => {
    emitErrorWithDsn("https://key@o0.ingest.de.sentry.io/1");
    expect(captureMessage).toHaveBeenCalledWith("boom", "error");
  });
});
