import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { FetchHttpClient } from "@/context/shared/http-client/infrastructure/FetchHttpClient";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

const URL = "/api/v1/documents/uploads/0192aaaa-aaaa-7aaa-8aaa-000000000001/content?filename=a.pdf";

type Listener = ((event: ProgressEvent) => void) | null;

/** The slice of XMLHttpRequest the transport drives, recorded and answered by the test. */
class FakeXhr {
  static last: FakeXhr | null = null;
  method = "";
  url = "";
  headers: Record<string, string> = {};
  body: unknown = null;
  status = 0;
  responseText = "";
  responseType = "";
  responseHeaders = "";
  aborted = false;
  upload: { onprogress: Listener; onload: (() => void) | null } = {
    onprogress: null,
    onload: null,
  };
  onload: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onabort: (() => void) | null = null;

  constructor() {
    FakeXhr.last = this;
  }
  open(method: string, url: string): void {
    this.method = method;
    this.url = url;
  }
  setRequestHeader(name: string, value: string): void {
    this.headers[name.toLowerCase()] = value;
  }
  getAllResponseHeaders(): string {
    return this.responseHeaders;
  }
  send(body: unknown): void {
    this.body = body;
  }
  abort(): void {
    this.aborted = true;
    this.onabort?.();
  }
  progress(loaded: number, total: number): void {
    this.upload.onprogress?.({ loaded, total, lengthComputable: true } as ProgressEvent);
  }
  respond(status: number, body: unknown, headers = "content-type: application/json\r\n"): void {
    this.status = status;
    this.responseText = JSON.stringify(body);
    this.responseHeaders = headers;
    this.onload?.();
  }
}

const file = new File([new Uint8Array([1, 2, 3])], "a.pdf", { type: "application/pdf" });
const guard = (b: unknown): b is { data: { id: string } } =>
  typeof b === "object" && b !== null && "data" in b;

describe("FetchHttpClient.putRaw with upload progress", () => {
  let fetchSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    FakeXhr.last = null;
    vi.stubGlobal("XMLHttpRequest", FakeXhr);
    fetchSpy = vi.spyOn(globalThis, "fetch");
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    fetchSpy.mockRestore();
    vi.useRealTimers();
  });

  it("sends the File itself over XHR with the caller's headers and reports progress", async () => {
    const onUploadProgress = vi.fn();
    const pending = new FetchHttpClient().putRaw(URL, file, guard, {
      headers: { "Content-Type": "application/octet-stream" },
      onUploadProgress,
    });
    const xhr = FakeXhr.last!;
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(xhr.method).toBe("PUT");
    expect(xhr.url).toBe(URL);
    expect(xhr.body).toBe(file);
    expect(xhr.headers["content-type"]).toBe("application/octet-stream");
    expect(xhr.headers.accept).toBe("application/json");

    xhr.progress(1, 3);
    xhr.progress(3, 3);
    xhr.respond(201, { data: { id: "document-1" } });

    await expect(pending).resolves.toEqual({ data: { id: "document-1" } });
    expect(onUploadProgress).toHaveBeenNthCalledWith(1, { loaded: 1, total: 3 });
    expect(onUploadProgress).toHaveBeenNthCalledWith(2, { loaded: 3, total: 3 });
  });

  it("says when the last byte has left, before the response arrives", async () => {
    const onUploadSent = vi.fn();
    const pending = new FetchHttpClient().putRaw(URL, file, guard, {
      onUploadProgress: vi.fn(),
      onUploadSent,
    });
    const xhr = FakeXhr.last!;
    expect(onUploadSent).not.toHaveBeenCalled();
    xhr.upload.onload?.();
    expect(onUploadSent).toHaveBeenCalledOnce();
    xhr.respond(201, { data: { id: "document-1" } });
    await pending;
  });

  it("parses a problem+json refusal into the same HttpError the fetch path throws", async () => {
    const pending = new FetchHttpClient().putRaw(URL, file, guard, { onUploadProgress: vi.fn() });
    FakeXhr.last!.respond(
      422,
      {
        type: "invalid-filename",
        title: "Invalid filename",
        status: 422,
        instance: "i",
        "correlation-id": "c",
      },
      "content-type: application/problem+json\r\nx-correlation-id: c\r\n",
    );

    const error = await pending.catch((e: unknown) => e);
    expect(error).toBeInstanceOf(HttpError);
    expect((error as HttpError).problem.type).toBe("invalid-filename");
  });

  it("mints network-error on a transport failure", async () => {
    const pending = new FetchHttpClient().putRaw(URL, file, guard, { onUploadProgress: vi.fn() });
    FakeXhr.last!.onerror?.();

    const error = await pending.catch((e: unknown) => e);
    expect((error as HttpError).problem.type).toBe(NETWORK_ERROR);
  });

  it("aborts the XHR and mints request-timeout when the budget runs out", async () => {
    vi.useFakeTimers();
    const pending = new FetchHttpClient()
      .putRaw(URL, file, guard, { onUploadProgress: vi.fn(), timeoutMs: 1000 })
      .catch((e: unknown) => e);
    await vi.advanceTimersByTimeAsync(1000);

    expect(FakeXhr.last!.aborted).toBe(true);
    expect(((await pending) as HttpError).problem.type).toBe(REQUEST_TIMEOUT);
  });

  it("stays on fetch when no progress is asked for", async () => {
    fetchSpy.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: { id: "document-1" } }), { status: 201 }),
    );
    await new FetchHttpClient().putRaw(URL, file, guard);
    expect(fetchSpy).toHaveBeenCalledOnce();
    expect(FakeXhr.last).toBeNull();
  });
});
