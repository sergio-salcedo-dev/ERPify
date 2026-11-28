import { describe, expect, it, vi } from "vitest";
import { ApiBankAccountEvidenceRepository } from "@/context/backoffice/bankaccount/infrastructure/ApiBankAccountEvidenceRepository";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";

const ACCOUNT_ID = "0190e9c2-0000-7000-8000-000000000001";

const ITEM = {
  id: "0190e9c2-0000-7000-8000-0000000000aa",
  documentId: "0190e9c2-0000-7000-8000-0000000000bb",
  kind: "ownership-certificate",
  name: "certificate.pdf",
  state: "ready",
  digest: "a".repeat(64),
  sizeBytes: 1024,
  attachedAt: "2026-11-28T10:00:00+00:00",
};

function httpClient(overrides: Partial<HttpClient>): HttpClient {
  return {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    putRaw: vi.fn(),
    delete: vi.fn(),
    ...overrides,
  };
}

describe("ApiBankAccountEvidenceRepository", () => {
  it("lists the account's evidence from its own route", async () => {
    const get = vi.fn().mockResolvedValue({ data: [ITEM] });

    const listed = await new ApiBankAccountEvidenceRepository(httpClient({ get })).list(ACCOUNT_ID);

    expect(listed).toEqual([ITEM]);
    expect(get.mock.calls[0][0]).toBe(`/api/v1/backoffice/bank-accounts/${ACCOUNT_ID}/evidence`);
  });

  it("attaches by document id and kind, never by bytes", async () => {
    const post = vi.fn().mockResolvedValue({ data: ITEM });

    const attached = await new ApiBankAccountEvidenceRepository(httpClient({ post })).attach(
      ACCOUNT_ID,
      ITEM.documentId,
      "ownership-certificate",
    );

    expect(attached).toEqual(ITEM);
    expect(post.mock.calls[0][0]).toBe(`/api/v1/backoffice/bank-accounts/${ACCOUNT_ID}/evidence`);
    expect(post.mock.calls[0][1]).toEqual({
      documentId: ITEM.documentId,
      kind: "ownership-certificate",
    });
  });

  it("guards the payloads with the closed vocabularies", async () => {
    const get = vi.fn().mockResolvedValue({ data: [] });
    const post = vi.fn().mockResolvedValue({ data: ITEM });
    const repository = new ApiBankAccountEvidenceRepository(httpClient({ get, post }));
    await repository.list(ACCOUNT_ID);
    await repository.attach(ACCOUNT_ID, ITEM.documentId, "ownership-certificate");
    const listGuard = get.mock.calls[0][1] as (body: unknown) => boolean;
    const itemGuard = post.mock.calls[0][2] as (body: unknown) => boolean;

    expect(listGuard({ data: [ITEM, { ...ITEM, name: null, state: "erased" }] })).toBe(true);
    expect(listGuard({ data: [{ ...ITEM, state: "deleted" }] })).toBe(false);
    expect(listGuard({ data: [{ ...ITEM, kind: "photo" }] })).toBe(false);
    expect(listGuard({ data: ITEM })).toBe(false);
    expect(itemGuard({ data: ITEM })).toBe(true);
    expect(itemGuard({ data: { ...ITEM, sizeBytes: "1024" } })).toBe(false);
    expect(itemGuard(null)).toBe(false);
  });
});
