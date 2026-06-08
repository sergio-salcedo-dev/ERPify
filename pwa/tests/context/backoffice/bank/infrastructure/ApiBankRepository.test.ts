import { describe, expect, it, vi } from "vitest";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { ApiBankRepository } from "@/context/backoffice/bank/infrastructure/ApiBankRepository";
import type { HttpClient } from "@/context/shared/infrastructure/HttpClient/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/infrastructure/api/ApiEndpoints";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import type { BankSearchCriteria } from "@/context/backoffice/bank/domain/BankRepository";

const primitives = {
  id: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T00:00:00+00:00",
  updatedAt: "2026-01-02T00:00:00+00:00",
};

const pagination = {
  currentPage: 2,
  pageCount: 5,
  count: 42,
  hasMorePages: true,
  cursor: "cursor-1",
};

function httpClientReturning(response: unknown): HttpClient {
  return {
    get: vi.fn().mockResolvedValue(response),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  };
}

function queryOf(httpClient: HttpClient): URLSearchParams {
  const url = vi.mocked(httpClient.get).mock.calls[0][0];
  return new URLSearchParams(url.split("?")[1] ?? "");
}

const BASE_CRITERIA: BankSearchCriteria = { filters: [], sort: null, page: 1, limit: 25 };

describe("ApiBankRepository.search", () => {
  it("serializes filters, sort, page, cursor and limit into the request", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    await new ApiBankRepository(httpClient).search({
      filters: [
        { field: "name", operator: "contains", value: "banc" },
        { field: "shortName", operator: "in", value: ["ES", "PT"] },
      ],
      sort: { field: "createdAt", direction: SortDirection.DESC },
      page: 2,
      cursor: "cursor-1",
      limit: 50,
    });

    const q = queryOf(httpClient);
    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toContain(
      API_ENDPOINTS.BACKOFFICE.BANKS.LIST,
    );
    expect(q.get("filters[0][field]")).toBe("name");
    expect(q.get("filters[0][operator]")).toBe("contains");
    expect(q.get("filters[0][value]")).toBe("banc");
    expect(q.getAll("filters[1][value][]")).toEqual(["ES", "PT"]);
    expect(q.get("sort")).toBe("createdAt");
    expect(q.get("direction")).toBe("DESC"); // PWA enum is lowercase; the wire is uppercase
    expect(q.get("page")).toBe("2");
    expect(q.get("cursor")).toBe("cursor-1");
    expect(q.get("limit")).toBe("50");
    expect(q.get("paginationMode")).toBe("detailed");
  });

  it("omits sort and cursor when absent", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    await new ApiBankRepository(httpClient).search(BASE_CRITERIA);

    const q = queryOf(httpClient);
    expect(q.has("sort")).toBe(false);
    expect(q.has("direction")).toBe(false);
    expect(q.has("cursor")).toBe(false);
    expect(q.get("page")).toBe("1");
    expect(q.get("limit")).toBe("25");
  });

  it("maps the pagination envelope to a BankSearchPage", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    const page = await new ApiBankRepository(httpClient).search(BASE_CRITERIA);

    expect(page.banks).toHaveLength(1);
    expect(page.banks[0]).toBeInstanceOf(Bank);
    expect(page.banks[0].name).toBe("Acme Savings");
    expect(page.cursor).toBe("cursor-1");
    expect(page.currentPage).toBe(2);
    expect(page.hasMorePages).toBe(true);
    expect(page.totalCount).toBe(42);
  });
});

describe("ApiBankRepository response guards", () => {
  const searchEnvelope = { data: [primitives], pagination };

  it("passes a search guard that accepts the full envelope and rejects drifted shapes", async () => {
    const httpClient = httpClientReturning(searchEnvelope);
    await new ApiBankRepository(httpClient).search(BASE_CRITERIA);

    const [, guard] = vi.mocked(httpClient.get).mock.calls[0];
    if (!guard) throw new Error("expected search() to pass a response guard");

    expect(guard(searchEnvelope)).toBe(true);
    expect(guard({ data: [primitives] })).toBe(false); // pagination missing
    expect(guard({ data: [primitives], pagination: { cursor: "c", hasMorePages: true } })).toBe(
      false,
    ); // currentPage missing
    expect(guard({ data: null, pagination })).toBe(false);
    expect(guard({ data: [{ id: 1 }], pagination })).toBe(false);
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
