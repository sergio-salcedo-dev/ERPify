import { describe, expect, it, vi } from "vitest";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { ApiDocumentUploader } from "@/context/shared/documents/infrastructure/ApiDocumentUploader";
import { LegalBasis, RetentionReason } from "@/context/shared/documents/domain/RetentionTerms";

const UPLOAD_ID = "0192aaaa-aaaa-7aaa-8aaa-000000000001";
const DOCUMENT_ID = "0192bbbb-bbbb-7bbb-8bbb-000000000002";
const TERMS = {
  retentionReason: RetentionReason.CONTRACT_EXECUTION,
  legalBasis: LegalBasis.LEGITIMATE_INTERESTS,
  reviewAt: null,
} as const;

function httpClient(overrides: Partial<HttpClient> = {}): HttpClient {
  return {
    get: vi.fn(),
    post: vi.fn().mockResolvedValue({
      data: {
        id: UPLOAD_ID,
        state: "declared",
        documentId: null,
        expiresAt: "2026-01-02T00:00:00Z",
      },
    }),
    put: vi.fn(),
    patch: vi.fn(),
    putRaw: vi.fn().mockResolvedValue({
      data: { id: DOCUMENT_ID, state: "received", digest: "ab".repeat(32), sizeBytes: 3 },
    }),
    delete: vi.fn(),
    ...overrides,
  };
}

const file = new File([new Uint8Array([1, 2, 3])], "Certificado de titularidad #1.pdf", {
  type: "application/pdf",
});

describe("ApiDocumentUploader", () => {
  it("declares the exact body — size and retention terms, never the filename", async () => {
    const client = httpClient();
    await new ApiDocumentUploader(client).upload(file, TERMS);

    const [url, body] = vi.mocked(client.post).mock.calls[0];
    expect(url).toBe("/api/v1/documents/uploads");
    expect(body).toStrictEqual({
      size: 3,
      retentionReason: "contract-execution",
      legalBasis: "legitimate-interests",
      reviewAt: null,
    });
    expect(JSON.stringify(body)).not.toContain("Certificado");
  });

  it("PUTs the File itself to the declared upload with the filename percent-encoded in the query", async () => {
    const client = httpClient();
    const filed = await new ApiDocumentUploader(client).upload(file, TERMS);

    const [url, body, , options] = vi.mocked(client.putRaw).mock.calls[0];
    expect(url).toBe(
      `/api/v1/documents/uploads/${UPLOAD_ID}/content?filename=Certificado%20de%20titularidad%20%231.pdf`,
    );
    expect(body).toBe(file);
    expect(options?.headers).toEqual({ "Content-Type": "application/octet-stream" });
    expect(options?.timeoutMs).toBeGreaterThan(30_000);
    expect(filed).toEqual({
      id: DOCUMENT_ID,
      state: "received",
      digest: "ab".repeat(32),
      sizeBytes: 3,
    });
  });

  it("forwards the progress callback to the transport", async () => {
    const client = httpClient();
    const onProgress = vi.fn();
    await new ApiDocumentUploader(client).upload(file, TERMS, onProgress);

    const options = vi.mocked(client.putRaw).mock.calls[0][3];
    options?.onUploadProgress?.({ loaded: 1, total: 3 });
    expect(onProgress).toHaveBeenCalledWith({ loaded: 1, total: 3 });
  });

  it("guards both 2xx bodies", async () => {
    const client = httpClient();
    await new ApiDocumentUploader(client).upload(file, TERMS);
    const declareGuard = vi.mocked(client.post).mock.calls[0][2] as (b: unknown) => boolean;
    const putGuard = vi.mocked(client.putRaw).mock.calls[0][2] as (b: unknown) => boolean;

    expect(declareGuard({ data: { id: UPLOAD_ID } })).toBe(true);
    expect(declareGuard({ data: { id: null, documentId: DOCUMENT_ID } })).toBe(false);
    expect(declareGuard({ id: UPLOAD_ID })).toBe(false);
    expect(
      putGuard({ data: { id: DOCUMENT_ID, state: "received", digest: "x", sizeBytes: 3 } }),
    ).toBe(true);
    expect(putGuard({ data: { id: DOCUMENT_ID, state: "received", digest: "x" } })).toBe(false);
  });

  it("propagates a refused declare as the transport's HttpError and sends no body", async () => {
    const refusal = new HttpError({
      type: "rate-limited",
      title: "Too many requests",
      status: 429,
      instance: "i",
      "correlation-id": "c",
    });
    const client = httpClient({ post: vi.fn().mockRejectedValue(refusal) });

    await expect(new ApiDocumentUploader(client).upload(file, TERMS)).rejects.toBe(refusal);
    expect(client.putRaw).not.toHaveBeenCalled();
  });
});
