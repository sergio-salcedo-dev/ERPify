import { describe, expect, it, vi } from "vitest";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import {
  ApiBankAccountEvidenceRepository,
  isEvidenceListResponse,
} from "@/context/backoffice/bankaccount/infrastructure/ApiBankAccountEvidenceRepository";

const ACCOUNT_ID = "0192aaaa-aaaa-7aaa-8aaa-000000000001";
const ITEM = {
  id: "0192cccc-cccc-7ccc-8ccc-000000000003",
  documentId: "0192bbbb-bbbb-7bbb-8bbb-000000000002",
  kind: "ownership-certificate",
  attachedAt: "2026-01-01T10:00:00Z",
  documentState: null,
  documentName: null,
  documentSizeBytes: null,
};

function httpClient(overrides: Partial<HttpClient> = {}): HttpClient {
  return {
    get: vi.fn().mockResolvedValue({ data: [ITEM] }),
    post: vi.fn().mockResolvedValue({ data: ITEM }),
    put: vi.fn(),
    patch: vi.fn(),
    putRaw: vi.fn(),
    delete: vi.fn(),
    ...overrides,
  };
}

describe("ApiBankAccountEvidenceRepository", () => {
  it("GETs the account's evidence", async () => {
    const client = httpClient();
    await expect(new ApiBankAccountEvidenceRepository(client).list(ACCOUNT_ID)).resolves.toEqual([
      ITEM,
    ]);
    expect(vi.mocked(client.get).mock.calls[0][0]).toBe(
      `/api/v1/backoffice/bank-accounts/${ACCOUNT_ID}/evidence`,
    );
  });

  it("POSTs exactly { documentId, kind }", async () => {
    const client = httpClient();
    await new ApiBankAccountEvidenceRepository(client).attach(
      ACCOUNT_ID,
      ITEM.documentId,
      "ownership-certificate",
    );
    const [url, body] = vi.mocked(client.post).mock.calls[0];
    expect(url).toBe(`/api/v1/backoffice/bank-accounts/${ACCOUNT_ID}/evidence`);
    expect(body).toStrictEqual({ documentId: ITEM.documentId, kind: "ownership-certificate" });
  });

  it("guards the listing: nullable document fields admitted, unknown kind or state refused", () => {
    expect(isEvidenceListResponse({ data: [ITEM] })).toBe(true);
    expect(isEvidenceListResponse({ data: [{ ...ITEM, documentState: "ready" }] })).toBe(true);
    expect(isEvidenceListResponse({ data: [{ ...ITEM, kind: "passport" }] })).toBe(false);
    expect(isEvidenceListResponse({ data: [{ ...ITEM, documentState: "lost" }] })).toBe(false);
    expect(isEvidenceListResponse({ data: ITEM })).toBe(false);
  });
});
