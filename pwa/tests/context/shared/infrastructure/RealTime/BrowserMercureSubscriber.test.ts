import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { BrowserMercureSubscriber } from "@/context/shared/infrastructure/RealTime/BrowserMercureSubscriber";

/** Minimal EventSource stand-in: captures the last instance and its handlers. */
class FakeEventSource {
  static last: FakeEventSource | undefined;
  onmessage: ((event: MessageEvent<string>) => void) | null = null;
  onerror: ((event: Event) => void) | null = null;
  readonly url: string;
  closed = false;

  constructor(url: string) {
    this.url = url;
    FakeEventSource.last = this;
  }

  close(): void {
    this.closed = true;
  }
}

describe("BrowserMercureSubscriber", () => {
  beforeEach(() => {
    FakeEventSource.last = undefined;
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

    expect(FakeEventSource.last?.url).toContain("topic=urn%3Aerpify%3Abackoffice%3Abanks");

    subscription.close();
    expect(FakeEventSource.last?.closed).toBe(true);
  });

  it("delivers JSON-parsed message data to the handler", () => {
    const onMessage = vi.fn();
    new BrowserMercureSubscriber().subscribe(["urn:x"], onMessage);

    FakeEventSource.last?.onmessage?.({
      data: '{"type":"bank.deleted","id":"abc"}',
    } as MessageEvent<string>);

    expect(onMessage).toHaveBeenCalledWith({ type: "bank.deleted", id: "abc" });
  });

  it("ignores malformed payloads without throwing", () => {
    const onMessage = vi.fn();
    new BrowserMercureSubscriber().subscribe(["urn:x"], onMessage);

    expect(() =>
      FakeEventSource.last?.onmessage?.({ data: "not-json" } as MessageEvent<string>),
    ).not.toThrow();
    expect(onMessage).not.toHaveBeenCalled();
  });

  it("invokes onError on a stream error so the caller can refresh authorization", () => {
    const onError = vi.fn();
    new BrowserMercureSubscriber().subscribe(["urn:x"], () => {}, { onError });

    FakeEventSource.last?.onerror?.(new Event("error"));

    expect(onError).toHaveBeenCalledTimes(1);
  });

  it("debounces a reconnect storm into a single authorization refresh", () => {
    const nowSpy = vi.spyOn(Date, "now").mockReturnValue(1_000_000);
    const onError = vi.fn();
    new BrowserMercureSubscriber().subscribe(["urn:x"], () => {}, { onError });
    const source = FakeEventSource.last;

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
    expect(() => FakeEventSource.last?.onerror?.(new Event("error"))).not.toThrow();
  });

  it("degrades to a no-op subscription when EventSource is unavailable", () => {
    vi.stubGlobal("EventSource", undefined);
    const onError = vi.fn();

    const subscription = new BrowserMercureSubscriber().subscribe(["urn:x"], () => {}, { onError });

    expect(FakeEventSource.last).toBeUndefined();
    expect(() => subscription.close()).not.toThrow();
    expect(onError).not.toHaveBeenCalled();
  });
});
