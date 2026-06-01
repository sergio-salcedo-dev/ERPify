import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { useMercureRealtime } from "@/context/shared/infrastructure/RealTime/useMercureRealtime";
import { telemetry } from "@/context/shared/infrastructure/Observability";

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("useMercureRealtime", () => {
  it("routes an authorize failure through telemetry.warn", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: false, status: 401 }));
    const warn = vi.spyOn(telemetry, "warn").mockImplementation(() => {});

    renderHook(() =>
      useMercureRealtime<unknown>({
        topics: ["urn:test:topic"],
        authorizePath: "/api/v1/test/realtime/authorize",
        parse: () => null,
        onEvent: () => {},
        scope: "realtime:test",
      }),
    );

    await waitFor(() =>
      expect(warn).toHaveBeenCalledWith("subscription skipped", {
        scope: "realtime:test",
        cause: expect.any(Error),
      }),
    );
  });

  it("does nothing when there are no topics", () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal("fetch", fetchSpy);

    renderHook(() =>
      useMercureRealtime<unknown>({
        topics: [],
        authorizePath: "/x",
        parse: () => null,
        onEvent: () => {},
        scope: "realtime:test",
      }),
    );

    expect(fetchSpy).not.toHaveBeenCalled();
  });
});
