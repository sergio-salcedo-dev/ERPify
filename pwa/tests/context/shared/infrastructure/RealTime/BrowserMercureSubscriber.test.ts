import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { BrowserMercureSubscriber } from "@/context/shared/infrastructure/RealTime/BrowserMercureSubscriber";

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
});
