import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { useMercureRealtime } from "@/context/shared/infrastructure/RealTime/useMercureRealtime";
import { telemetry } from "@/context/shared/infrastructure/Observability";
import { mercureSubscriber } from "@/context/shared/infrastructure/RealTime/BrowserMercureSubscriber";

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

  it("authorizes then subscribes when topics are present", async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 204 });
    vi.stubGlobal("fetch", fetchMock);
    const subscribe = vi.spyOn(mercureSubscriber, "subscribe").mockReturnValue({ close: () => {} });

    renderHook(() =>
      useMercureRealtime<unknown>({
        topics: ["urn:test:topic"],
        authorizePath: "/api/v1/test/realtime/authorize",
        parse: () => null,
        onEvent: () => {},
        scope: "realtime:test",
      }),
    );

    await waitFor(() => expect(subscribe).toHaveBeenCalledTimes(1));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(subscribe).toHaveBeenCalledWith(
      ["urn:test:topic"],
      expect.any(Function),
      expect.objectContaining({ onError: expect.any(Function) }),
    );
  });

  it("routes a reconnect re-authorize failure through telemetry.warn", async () => {
    // Initial authorize succeeds (the stream opens); the re-mint on reconnect fails.
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({ ok: true, status: 204 })
      .mockResolvedValue({ ok: false, status: 401 });
    vi.stubGlobal("fetch", fetchMock);

    let onError: (() => void) | undefined;
    vi.spyOn(mercureSubscriber, "subscribe").mockImplementation((_topics, _onMessage, opts) => {
      onError = opts?.onError;
      return { close: () => {} };
    });
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

    await waitFor(() => expect(onError).toBeDefined());
    onError?.();

    await waitFor(() =>
      expect(warn).toHaveBeenCalledWith("subscriber-cookie refresh failed", {
        scope: "realtime:test",
        cause: expect.any(Error),
      }),
    );
  });
});
