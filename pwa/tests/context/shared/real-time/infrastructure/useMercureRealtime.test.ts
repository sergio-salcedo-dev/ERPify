import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useMercureRealtime } from "@/context/shared/real-time/infrastructure/useMercureRealtime";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { telemetry } from "@/context/shared/observability/infrastructure";
import { mercureSubscriber } from "@/context/shared/real-time/infrastructure/BrowserMercureSubscriber";

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: vi.fn() },
}));

const authorizeCall = vi.fn();

/** The two refusals no retry can recover from, each named for its real-world cause. */
const TERMINAL_CASES: ReadonlyArray<[string, number]> = [
  ["a revoked permission", HttpStatus.FORBIDDEN],
  ["an expired session", HttpStatus.UNAUTHORIZED],
];

function refusal(status: number): HttpError {
  return new HttpError({
    type: status === HttpStatus.FORBIDDEN ? "forbidden" : "unauthenticated",
    title: "Refused",
    status,
    instance: "0190a001-0000-7000-8000-000000000001",
    "correlation-id": "0190a001-0000-7000-8000-000000000002",
  });
}

function renderFeed(): void {
  renderHook(() =>
    useMercureRealtime<unknown>({
      topics: ["urn:test:topic"],
      authorizePath: "/api/v1/test/realtime/authorize",
      parse: () => null,
      onEvent: () => {},
      scope: "realtime:test",
    }),
  );
}

/** Renders a feed whose stream is already open and hands back its error callback. */
async function renderOpenFeed(): Promise<{ onError: () => void; close: ReturnType<typeof vi.fn> }> {
  const close = vi.fn();
  let onError: (() => void) | undefined;
  vi.spyOn(mercureSubscriber, "subscribe").mockImplementation((_topics, _onMessage, opts) => {
    onError = opts?.onError;
    return { close };
  });

  renderFeed();

  await waitFor(() => expect(onError).toBeDefined());

  return { onError: onError as () => void, close };
}

beforeEach(() => {
  authorizeCall.mockResolvedValue(undefined);
  vi.mocked(container.get).mockImplementation(() => ({ get: authorizeCall }));
});

afterEach(() => {
  vi.restoreAllMocks();
  authorizeCall.mockReset();
});

describe("useMercureRealtime", () => {
  it("routes an authorize failure through telemetry.warn", async () => {
    authorizeCall.mockRejectedValue(refusal(HttpStatus.UNAUTHORIZED));
    const warn = vi.spyOn(telemetry, "warn").mockImplementation(() => {});

    renderFeed();

    await waitFor(() =>
      expect(warn).toHaveBeenCalledWith("subscription skipped", {
        scope: "realtime:test",
        cause: expect.any(HttpError),
      }),
    );
  });

  it("does not open a stream when authorization is refused on mount", async () => {
    authorizeCall.mockRejectedValue(refusal(HttpStatus.FORBIDDEN));
    const subscribe = vi.spyOn(mercureSubscriber, "subscribe");
    vi.spyOn(telemetry, "warn").mockImplementation(() => {});

    renderFeed();

    await waitFor(() => expect(authorizeCall).toHaveBeenCalledTimes(1));
    expect(subscribe).not.toHaveBeenCalled();
  });

  it("does nothing when there are no topics", () => {
    renderHook(() =>
      useMercureRealtime<unknown>({
        topics: [],
        authorizePath: "/x",
        parse: () => null,
        onEvent: () => {},
        scope: "realtime:test",
      }),
    );

    expect(authorizeCall).not.toHaveBeenCalled();
  });

  it("authorizes then subscribes when topics are present", async () => {
    const subscribe = vi.spyOn(mercureSubscriber, "subscribe").mockReturnValue({ close: () => {} });

    renderFeed();

    await waitFor(() => expect(subscribe).toHaveBeenCalledTimes(1));
    expect(authorizeCall).toHaveBeenCalledTimes(1);
    expect(authorizeCall).toHaveBeenCalledWith("/api/v1/test/realtime/authorize");
    // Pin the binding key: the stub answers any identifier, so a renamed binding would
    // otherwise pass here and throw only at runtime.
    expect(container.get).toHaveBeenCalledWith("HttpClient");
    expect(subscribe).toHaveBeenCalledWith(
      ["urn:test:topic"],
      expect.any(Function),
      expect.objectContaining({ onError: expect.any(Function) }),
    );
  });

  it("routes a reconnect re-authorize failure through telemetry.warn", async () => {
    const warn = vi.spyOn(telemetry, "warn").mockImplementation(() => {});
    const { onError } = await renderOpenFeed();

    authorizeCall.mockRejectedValue(refusal(HttpStatus.INTERNAL_SERVER_ERROR));
    onError();

    await waitFor(() =>
      expect(warn).toHaveBeenCalledWith("subscriber-cookie refresh failed", {
        scope: "realtime:test",
        cause: expect.any(HttpError),
      }),
    );
  });

  it("keeps the stream alive when a reconnect re-authorize fails transiently", async () => {
    vi.spyOn(telemetry, "warn").mockImplementation(() => {});
    const { onError, close } = await renderOpenFeed();

    authorizeCall.mockRejectedValue(refusal(HttpStatus.INTERNAL_SERVER_ERROR));
    onError();

    await waitFor(() => expect(authorizeCall).toHaveBeenCalledTimes(2));
    expect(close).not.toHaveBeenCalled();
  });

  it.each(TERMINAL_CASES)(
    "abandons the stream after %s refuses the re-authorize",
    async (_case, status) => {
      const warn = vi.spyOn(telemetry, "warn").mockImplementation(() => {});
      const { onError, close } = await renderOpenFeed();

      authorizeCall.mockRejectedValue(refusal(status));
      onError();

      await waitFor(() => expect(close).toHaveBeenCalledTimes(1));
      expect(warn).toHaveBeenCalledWith("realtime authorization revoked", {
        scope: "realtime:test",
        cause: expect.any(HttpError),
      });

      // The transport keeps signalling while it retries; a closed stream must stay closed and
      // must never mint another doomed authorize. A leaked guard would call through here
      // synchronously, so assert straight after rather than waiting for a count already reached.
      onError();
      onError();

      expect(authorizeCall).toHaveBeenCalledTimes(2);
      expect(close).toHaveBeenCalledTimes(1);
    },
  );

  it("releases the in-flight guard even when reporting the failure throws", async () => {
    // The telemetry singleton fans out to real sinks; one of them throwing must not latch the
    // guard, or the feed would stop re-authorizing without ever having been abandoned.
    vi.spyOn(telemetry, "warn").mockImplementationOnce(() => {
      throw new Error("sink down");
    });
    const { onError, close } = await renderOpenFeed();

    authorizeCall.mockRejectedValue(refusal(HttpStatus.INTERNAL_SERVER_ERROR));
    onError();
    await waitFor(() => expect(authorizeCall).toHaveBeenCalledTimes(2));

    vi.spyOn(telemetry, "warn").mockImplementation(() => {});
    onError();

    await waitFor(() => expect(authorizeCall).toHaveBeenCalledTimes(3));
    expect(close).not.toHaveBeenCalled();
  });

  it("collapses concurrent stream errors into a single authorize attempt", async () => {
    vi.spyOn(telemetry, "warn").mockImplementation(() => {});
    const { onError, close } = await renderOpenFeed();

    let refuse: (error: HttpError) => void = () => {};
    authorizeCall.mockImplementation(
      () =>
        new Promise((_resolve, reject) => {
          refuse = reject;
        }),
    );

    onError();
    onError();
    onError();

    expect(authorizeCall).toHaveBeenCalledTimes(2);

    refuse(refusal(HttpStatus.FORBIDDEN));

    await waitFor(() => expect(close).toHaveBeenCalledTimes(1));
  });

  it("leaves the stream alone when the feed unmounts while a re-authorize is in flight", async () => {
    vi.spyOn(telemetry, "warn").mockImplementation(() => {});
    const close = vi.fn();
    let onError: (() => void) | undefined;
    vi.spyOn(mercureSubscriber, "subscribe").mockImplementation((_topics, _onMessage, opts) => {
      onError = opts?.onError;
      return { close };
    });

    const { unmount } = renderHook(() =>
      useMercureRealtime<unknown>({
        topics: ["urn:test:topic"],
        authorizePath: "/api/v1/test/realtime/authorize",
        parse: () => null,
        onEvent: () => {},
        scope: "realtime:test",
      }),
    );

    await waitFor(() => expect(onError).toBeDefined());

    let refuse: (error: HttpError) => void = () => {};
    authorizeCall.mockImplementation(
      () =>
        new Promise((_resolve, reject) => {
          refuse = reject;
        }),
    );
    onError?.();

    unmount();
    expect(close).toHaveBeenCalledTimes(1);

    refuse(refusal(HttpStatus.FORBIDDEN));

    await waitFor(() => expect(authorizeCall).toHaveBeenCalledTimes(2));
    expect(close).toHaveBeenCalledTimes(1);

    // A stream error queued before cleanup can still land afterwards; authorizing then would
    // cost a doomed request and let a 401 navigate a page the user has already left.
    onError?.();
    expect(authorizeCall).toHaveBeenCalledTimes(2);
  });

  it("reports an unrecognized payload through telemetry.warn without dispatching", async () => {
    let onMessage: ((data: unknown) => void) | undefined;
    vi.spyOn(mercureSubscriber, "subscribe").mockImplementation((_topics, message) => {
      onMessage = message;
      return { close: () => {} };
    });
    const warn = vi.spyOn(telemetry, "warn").mockImplementation(() => {});
    const onEvent = vi.fn();

    renderHook(() =>
      useMercureRealtime<unknown>({
        topics: ["urn:test:topic"],
        authorizePath: "/api/v1/test/realtime/authorize",
        parse: () => null, // never recognises the payload
        onEvent,
        scope: "realtime:test",
      }),
    );

    await waitFor(() => expect(onMessage).toBeDefined());
    onMessage?.({ type: "unknown" });

    expect(warn).toHaveBeenCalledWith("unrecognized realtime payload", { scope: "realtime:test" });
    expect(onEvent).not.toHaveBeenCalled();
  });

  it("invokes onReconnect when the subscriber reports a stream re-open", async () => {
    let onReconnect: (() => void) | undefined;
    vi.spyOn(mercureSubscriber, "subscribe").mockImplementation((_topics, _message, opts) => {
      onReconnect = opts?.onReconnect;
      return { close: () => {} };
    });
    const reconnect = vi.fn();

    renderHook(() =>
      useMercureRealtime<unknown>({
        topics: ["urn:test:topic"],
        authorizePath: "/api/v1/test/realtime/authorize",
        parse: () => null,
        onEvent: () => {},
        onReconnect: reconnect,
        scope: "realtime:test",
      }),
    );

    await waitFor(() => expect(onReconnect).toBeDefined());
    onReconnect?.();

    expect(reconnect).toHaveBeenCalledTimes(1);
  });
});
