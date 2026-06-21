import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  BrowserMercureSubscriber,
  mercureSubscriber,
} from "@/context/shared/real-time/infrastructure/BrowserMercureSubscriber";
import { useMercureRealtime } from "@/context/shared/real-time/infrastructure/useMercureRealtime";

/** Opened EventSource stand-ins for the current test (reset in `beforeEach`). */
const sources: FakeEventSource[] = [];
const lastSource = (): FakeEventSource | undefined => sources.at(-1);

/** Minimal EventSource stand-in: records itself and exposes its handlers. */
class FakeEventSource {
  onmessage: ((event: MessageEvent<string>) => void) | null = null;
  onerror: ((event: Event) => void) | null = null;
  onopen: ((event: Event) => void) | null = null;
  readonly url: string;
  closed = false;

  constructor(url: string) {
    this.url = url;
    sources.push(this);
  }

  close(): void {
    this.closed = true;
  }
}

describe("BrowserMercureSubscriber", () => {
  beforeEach(() => {
    sources.length = 0;
    vi.stubGlobal("EventSource", FakeEventSource);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("opens an EventSource for the requested topics and closes it on unsubscribe", () => {
    const subscription = new BrowserMercureSubscriber().subscribe(
      ["urn:erpify:backoffice:banks"],
      () => {},
    );

    expect(lastSource()?.url).toContain("topic=urn%3Aerpify%3Abackoffice%3Abanks");

    subscription.close();
    expect(lastSource()?.closed).toBe(true);
  });

  it("delivers JSON-parsed message data to the handler", () => {
    const onMessage = vi.fn();
    new BrowserMercureSubscriber().subscribe(["urn:x"], onMessage);

    lastSource()?.onmessage?.({
      data: '{"type":"bank.deleted","id":"abc"}',
    } as MessageEvent<string>);

    expect(onMessage).toHaveBeenCalledWith({ type: "bank.deleted", id: "abc" });
  });

  it("ignores malformed payloads without throwing", () => {
    const onMessage = vi.fn();
    new BrowserMercureSubscriber().subscribe(["urn:x"], onMessage);

    expect(() =>
      lastSource()?.onmessage?.({ data: "not-json" } as MessageEvent<string>),
    ).not.toThrow();
    expect(onMessage).not.toHaveBeenCalled();
  });

  it("invokes onError on a stream error so the caller can refresh authorization", () => {
    const onError = vi.fn();
    new BrowserMercureSubscriber().subscribe(["urn:x"], () => {}, { onError });

    lastSource()?.onerror?.(new Event("error"));

    expect(onError).toHaveBeenCalledTimes(1);
  });

  it("debounces a reconnect storm into a single authorization refresh", () => {
    const nowSpy = vi.spyOn(Date, "now").mockReturnValue(1_000_000);
    const onError = vi.fn();
    new BrowserMercureSubscriber().subscribe(["urn:x"], () => {}, { onError });
    const source = lastSource();

    source?.onerror?.(new Event("error"));
    source?.onerror?.(new Event("error"));
    source?.onerror?.(new Event("error"));
    expect(onError).toHaveBeenCalledTimes(1);

    // Past the debounce window the next error refreshes again.
    nowSpy.mockReturnValue(1_000_000 + 31_000);
    source?.onerror?.(new Event("error"));
    expect(onError).toHaveBeenCalledTimes(2);
  });

  it("never refreshes when no onError handler is supplied", () => {
    new BrowserMercureSubscriber().subscribe(["urn:x"], () => {});
    expect(() => lastSource()?.onerror?.(new Event("error"))).not.toThrow();
  });

  it("skips the first open and invokes onReconnect on each later re-open", () => {
    const onReconnect = vi.fn();
    new BrowserMercureSubscriber().subscribe(["urn:x"], () => {}, { onReconnect });
    const source = lastSource();

    source?.onopen?.(new Event("open")); // initial connect — caller already has data
    expect(onReconnect).not.toHaveBeenCalled();

    source?.onopen?.(new Event("open")); // recovered after a drop
    source?.onopen?.(new Event("open"));
    expect(onReconnect).toHaveBeenCalledTimes(2);
  });

  it("wires no onopen handler when no onReconnect is supplied", () => {
    new BrowserMercureSubscriber().subscribe(["urn:x"], () => {});
    expect(lastSource()?.onopen).toBeNull();
  });

  it("degrades to a no-op subscription when EventSource is unavailable", () => {
    vi.stubGlobal("EventSource", undefined);
    const onError = vi.fn();

    const subscription = new BrowserMercureSubscriber().subscribe(["urn:x"], () => {}, { onError });

    expect(lastSource()).toBeUndefined();
    expect(() => subscription.close()).not.toThrow();
    expect(onError).not.toHaveBeenCalled();
  });

  describe("padded NEXT_PUBLIC_API_BASE_URL", () => {
    const ORIGINAL_API_BASE = process.env.NEXT_PUBLIC_API_BASE_URL;

    afterEach(() => {
      if (ORIGINAL_API_BASE === undefined) {
        delete process.env.NEXT_PUBLIC_API_BASE_URL;
      } else {
        process.env.NEXT_PUBLIC_API_BASE_URL = ORIGINAL_API_BASE;
      }
    });

    it("resolves the identical origin for the EventSource and the authorize fetch", async () => {
      // A padded value reproduces the bug: without .trim(), the trailing space
      // survives into `new URL(base + path)` right after the host (the parser
      // only strips leading/trailing input whitespace), so the URL throws
      // ("Invalid URL") and the EventSource diverges from the trimmed fetch
      // path. Both builders must trim and land on the same origin.
      process.env.NEXT_PUBLIC_API_BASE_URL = " https://x ";

      // EventSource side.
      new BrowserMercureSubscriber().subscribe(["urn:erpify:test"], () => {});
      const eventSourceOrigin = new URL(lastSource()!.url).origin;

      // Authorize fetch side (built by `useMercureRealtime`).
      const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 204 });
      vi.stubGlobal("fetch", fetchMock);
      vi.spyOn(mercureSubscriber, "subscribe").mockReturnValue({ close: () => {} });

      renderHook(() =>
        useMercureRealtime<unknown>({
          topics: ["urn:erpify:test"],
          authorizePath: "/api/v1/test/realtime/authorize",
          parse: () => null,
          onEvent: () => {},
          scope: "realtime:test",
        }),
      );

      await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
      const [fetchInput] = fetchMock.mock.calls[0] as [URL];
      const fetchOrigin = fetchInput.origin;

      expect(eventSourceOrigin).toBe("https://x");
      expect(fetchOrigin).toBe(eventSourceOrigin);
    });
  });
});
