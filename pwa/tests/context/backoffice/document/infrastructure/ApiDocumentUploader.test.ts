import { describe, expect, it, vi } from "vitest";
import { ApiDocumentUploader } from "@/context/backoffice/document/infrastructure/ApiDocumentUploader";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import {
  MALFORMED_RESPONSE_ENVELOPE,
  NETWORK_ERROR,
} from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

const UPLOAD_ID = "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c01";
const TERMS = { retentionReason: "contract-execution", legalBasis: "legitimate-interests" };

/** The slice of `XMLHttpRequest` the uploader drives, recorded so a test can answer for the server. */
class FakeRequest {
  method = "";
  url = "";
  headers: Record<string, string> = {};
  body: unknown = undefined;
  status = 0;
  responseText = "";
  responseHeaders: Record<string, string> = {};
  aborted = false;
  upload: { onprogress: ((event: ProgressEvent) => void) | null } = { onprogress: null };
  onload: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onabort: (() => void) | null = null;

  open(method: string, url: string): void {
    this.method = method;
    this.url = url;
  }

  setRequestHeader(name: string, value: string): void {
    this.headers[name] = value;
  }

  getResponseHeader(name: string): string | null {
    return this.responseHeaders[name] ?? null;
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

  answer(status: number, body: unknown, headers: Record<string, string> = {}): void {
    this.status = status;
    this.responseText = typeof body === "string" ? body : JSON.stringify(body);
    this.responseHeaders = headers;
    this.onload?.();
  }
}

class UploaderUnderTest extends ApiDocumentUploader {
  readonly requests: FakeRequest[] = [];

  protected override createRequest(): XMLHttpRequest {
    const request = new FakeRequest();
    this.requests.push(request);
    return request as unknown as XMLHttpRequest;
  }
}

function httpClient(post = vi.fn().mockResolvedValue({ data: { id: UPLOAD_ID } })): HttpClient {
  return {
    get: vi.fn(),
    post,
    put: vi.fn(),
    patch: vi.fn(),
    putRaw: vi.fn(),
    delete: vi.fn(),
  };
}

async function started(uploader: UploaderUnderTest): Promise<FakeRequest> {
  await vi.waitFor(() => expect(uploader.requests).toHaveLength(1));
  return uploader.requests[0];
}

const RECEIPT = {
  data: { id: "doc-1", state: "received", digest: "a".repeat(64), sizeBytes: 5 },
};

describe("ApiDocumentUploader", () => {
  it("declares the exact size and the kind's terms, then PUTs the file to the declared upload", async () => {
    const post = vi.fn().mockResolvedValue({ data: { id: UPLOAD_ID } });
    const uploader = new UploaderUnderTest(httpClient(post));
    const file = new File(["12345"], "certificate.pdf", { type: "application/pdf" });

    const filed = uploader.upload(file, TERMS);
    const request = await started(uploader);
    request.answer(201, RECEIPT);

    await expect(filed).resolves.toEqual(RECEIPT.data);
    expect(post).toHaveBeenCalledWith(
      "/api/v1/documents/uploads",
      { size: 5, retentionReason: "contract-execution", legalBasis: "legitimate-interests" },
      expect.any(Function),
    );
    expect(request.method).toBe("PUT");
    expect(request.url).toBe(
      `/api/v1/documents/uploads/${UPLOAD_ID}/content?filename=certificate.pdf`,
    );
    expect(request.headers.Accept).toBe("application/json");
  });

  it("sends the original as an octet-stream blob of exactly its size, never a form", async () => {
    const uploader = new UploaderUnderTest(httpClient());
    const file = new File(["12345"], "photo.jpg", { type: "application/x-www-form-urlencoded" });

    void uploader.upload(file, TERMS);
    const request = await started(uploader);

    expect(request.body).toBeInstanceOf(Blob);
    const body = request.body as Blob;
    expect(body.type).toBe("application/octet-stream");
    expect(body.size).toBe(file.size);
    expect(await body.text()).toBe("12345");
  });

  it("puts the NFC name, percent-encoded, in the single filename parameter", async () => {
    const uploader = new UploaderUnderTest(httpClient());
    const decomposed = "Certificádo de titularidad & co?.pdf";
    const file = new File(["x"], decomposed);

    void uploader.upload(file, TERMS);
    const request = await started(uploader);

    const query = request.url.split("?")[1];
    expect(query).toBe(`filename=${encodeURIComponent(decomposed.normalize("NFC"))}`);
    expect(new URLSearchParams(query).getAll("filename")).toEqual([
      "Certificádo de titularidad & co?.pdf",
    ]);
  });

  it("reports progress as the body leaves the browser", async () => {
    const onProgress = vi.fn();
    const uploader = new UploaderUnderTest(httpClient());

    void uploader.upload(new File(["12345"], "a.pdf"), TERMS, { onProgress });
    const request = await started(uploader);
    request.progress(2, 5);
    request.progress(5, 5);

    expect(onProgress.mock.calls).toEqual([[{ loaded: 2, total: 5 }], [{ loaded: 5, total: 5 }]]);
  });

  it("rejects with the server's Problem Details on a refusal", async () => {
    const uploader = new UploaderUnderTest(httpClient());
    const filed = uploader.upload(new File(["1"], "a.pdf"), TERMS);
    const request = await started(uploader);
    const problem = {
      type: "invalid-filename",
      title: "The original filename is not valid.",
      status: 422,
      instance: "i",
      "correlation-id": "c",
    };

    request.answer(422, problem);

    await expect(filed).rejects.toBeInstanceOf(HttpError);
    await expect(filed).rejects.toMatchObject({ problem: { type: "invalid-filename" } });
  });

  it("keeps the status and the server's correlation id when the refusal is not Problem Details", async () => {
    const uploader = new UploaderUnderTest(httpClient());
    const filed = uploader.upload(new File(["1"], "a.pdf"), TERMS);
    const request = await started(uploader);

    request.answer(502, "<html>bad gateway</html>", { "X-Correlation-Id": "corr-1" });

    await expect(filed).rejects.toMatchObject({
      problem: { type: "about:blank", status: 502, "correlation-id": "corr-1" },
    });
  });

  it("refuses a 2xx body that is not a receipt", async () => {
    const uploader = new UploaderUnderTest(httpClient());
    const filed = uploader.upload(new File(["1"], "a.pdf"), TERMS);
    const request = await started(uploader);

    request.answer(201, { data: { id: "doc-1" } });

    await expect(filed).rejects.toMatchObject({ problem: { type: MALFORMED_RESPONSE_ENVELOPE } });
  });

  it("reports a transfer that got no answer as a network error", async () => {
    const uploader = new UploaderUnderTest(httpClient());
    const filed = uploader.upload(new File(["1"], "a.pdf"), TERMS);
    const request = await started(uploader);

    request.onerror?.();

    await expect(filed).rejects.toMatchObject({ problem: { type: NETWORK_ERROR, status: 0 } });
  });

  it("aborts the body when the signal fires and rejects with an AbortError", async () => {
    const controller = new AbortController();
    const uploader = new UploaderUnderTest(httpClient());
    const filed = uploader.upload(new File(["1"], "a.pdf"), TERMS, { signal: controller.signal });
    const request = await started(uploader);

    controller.abort();

    expect(request.aborted).toBe(true);
    await expect(filed).rejects.toMatchObject({ name: "AbortError" });
  });

  it("sends nothing when the declare is refused", async () => {
    const refusal = new HttpError({
      type: "rate-limited",
      title: "t",
      status: 429,
      instance: "i",
      "correlation-id": "c",
    });
    const uploader = new UploaderUnderTest(httpClient(vi.fn().mockRejectedValue(refusal)));

    await expect(uploader.upload(new File(["1"], "a.pdf"), TERMS)).rejects.toBe(refusal);
    expect(uploader.requests).toHaveLength(0);
  });

  it("guards the declare: only an upload id is accepted", async () => {
    const post = vi.fn().mockResolvedValue({ data: { id: UPLOAD_ID } });
    const uploader = new UploaderUnderTest(httpClient(post));
    void uploader.upload(new File(["1"], "a.pdf"), TERMS);
    await started(uploader);
    const guard = post.mock.calls[0][2] as (body: unknown) => boolean;

    expect(guard({ data: { id: UPLOAD_ID } })).toBe(true);
    expect(guard({ data: { id: null } })).toBe(false);
    expect(guard({ data: { id: "" } })).toBe(false);
    expect(guard({ id: UPLOAD_ID })).toBe(false);
    expect(guard(null)).toBe(false);
  });
});
