import { describe, expect, it, vi } from "vitest";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { ApiBankRepository } from "@/context/backoffice/bank/infrastructure/ApiBankRepository";
import type { HttpClient } from "@/context/shared/infrastructure/HttpClient/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/infrastructure/api/ApiEndpoints";

const primitives = {
  id: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T00:00:00+00:00",
  updatedAt: "2026-01-02T00:00:00+00:00",
};

function httpClientReturning(response: unknown): HttpClient {
  return {
    get: vi.fn().mockResolvedValue(response),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  };
}

describe("ApiBankRepository.search", () => {
  it("reads banks from the top-level data array and pagination sibling", async () => {
    const httpClient = httpClientReturning({
      data: [primitives],
      pagination: {
        currentPage: 1,
        pageCount: null,
        count: 31,
        hasMorePages: true,
        cursor: "cursor-1",
      },
    });

    const page = await new ApiBankRepository(httpClient).search();

    expect(httpClient.get).toHaveBeenCalledWith(
      API_ENDPOINTS.BACKOFFICE.BANKS.LIST,
      expect.any(Function),
    );
    expect(page.banks).toHaveLength(1);
    expect(page.banks[0]).toBeInstanceOf(Bank);
    expect(page.banks[0].name).toBe("Acme Savings");
    expect(page.nextCursor).toBe("cursor-1");
  });

  it("omits nextCursor when there are no more pages", async () => {
    const page = await new ApiBankRepository(
      httpClientReturning({
        data: [],
        pagination: {
          currentPage: 1,
          pageCount: 1,
          count: 0,
          hasMorePages: false,
          cursor: "cursor-1",
        },
      }),
    ).search();

    expect(page.banks).toEqual([]);
    expect(page.nextCursor).toBeUndefined();
  });
});

describe("ApiBankRepository response guards", () => {
  const searchEnvelope = {
    data: [primitives],
    pagination: { cursor: "cursor-1", hasMorePages: true },
  };

  it("passes a search guard that accepts the flat envelope and rejects drifted shapes", async () => {
    const httpClient = httpClientReturning(searchEnvelope);
    await new ApiBankRepository(httpClient).search();

    const [, guard] = vi.mocked(httpClient.get).mock.calls[0];
    if (!guard) throw new Error("expected search() to pass a response guard");

    expect(guard(searchEnvelope)).toBe(true);
    expect(guard({ data: [primitives] })).toBe(false); // pagination missing
    expect(guard({ data: null, pagination: searchEnvelope.pagination })).toBe(false);
    expect(guard({ data: [{ id: 1 }], pagination: searchEnvelope.pagination })).toBe(false);
    expect(guard({ data: { nested: [primitives] }, pagination: {} })).toBe(false); // old nested shape
    expect(guard(undefined)).toBe(false);
  });

  it("passes a single-envelope guard to find, create and update", async () => {
    const httpClient: HttpClient = {
      get: vi.fn().mockResolvedValue({ data: primitives }),
      post: vi.fn().mockResolvedValue({ data: primitives }),
      put: vi.fn().mockResolvedValue({ data: primitives }),
      delete: vi.fn(),
    };
    const repository = new ApiBankRepository(httpClient);
    const input = { name: primitives.name, shortName: primitives.shortName };

    await repository.find(primitives.id);
    await repository.create(input);
    await repository.update(primitives.id, input);

    for (const guard of [
      vi.mocked(httpClient.get).mock.calls[0][1],
      vi.mocked(httpClient.post).mock.calls[0][2],
      vi.mocked(httpClient.put).mock.calls[0][2],
    ]) {
      if (!guard) throw new Error("expected a single-envelope response guard");
      expect(guard({ data: primitives })).toBe(true);
      expect(guard(primitives)).toBe(false); // unwrapped payload
      expect(guard({ data: { ...primitives, id: 42 } })).toBe(false);
      expect(guard(null)).toBe(false);
    }
  });
});
