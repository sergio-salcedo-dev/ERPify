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

// Cursor-only envelope v2 (PR3): directional flags, optional count, verbatim links.
const pagination = {
  hasNext: true,
  hasPrev: true,
  count: 42,
  links: {
    next: "/api/v1/backoffice/banks?limit=25&after=cursor-next",
    prev: "/api/v1/backoffice/banks?limit=25&before=cursor-prev",
  },
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

const BASE_CRITERIA: BankSearchCriteria = { filters: [], sort: null, limit: 25 };

describe("ApiBankRepository.search", () => {
  it("serializes filters, sort and limit — never a page or cursor (W11)", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    await new ApiBankRepository(httpClient).search({
      filters: [
        { field: "name", operator: "contains", value: "banc" },
        { field: "shortName", operator: "in", value: ["ES", "PT"] },
      ],
      sort: { field: "createdAt", direction: SortDirection.DESC },
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
    expect(q.get("limit")).toBe("50");
    // The query path NEVER serializes a cursor or page — continuing pages is the
    // navigator following server links verbatim (W11). One serialization (W2).
    expect(q.has("page")).toBe(false);
    expect(q.has("cursor")).toBe(false);
    expect(q.has("after")).toBe(false);
    expect(q.has("before")).toBe(false);
    // No `paginationMode` is sent — the API defaults to LIGHT (no COUNT(*)).
    expect(q.has("paginationMode")).toBe(false);
  });

  it("omits sort when absent and clamps limit to the wire ceiling (D-Cap)", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    await new ApiBankRepository(httpClient).search({ filters: [], sort: null, limit: 1000 });

    const q = queryOf(httpClient);
    expect(q.has("sort")).toBe(false);
    expect(q.has("direction")).toBe(false);
    // A UI that somehow asks for 1000 is clamped to 100 before the wire — hard
    // client enforcement complementary to the backend 422.
    expect(q.get("limit")).toBe("100");
  });

  it("maps the cursor-only envelope to a BankSearchPage", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    const page = await new ApiBankRepository(httpClient).search(BASE_CRITERIA);

    expect(page.banks).toHaveLength(1);
    expect(page.banks[0]).toBeInstanceOf(Bank);
    expect(page.banks[0].name).toBe("Acme Savings");
    expect(page.hasNext).toBe(true);
    expect(page.hasPrev).toBe(true);
    expect(page.count).toBe(42);
    // Links travel through verbatim — never rebuilt client-side (W2/W9).
    expect(page.links).toEqual(pagination.links);
  });
});

describe("ApiBankRepository response guards", () => {
  const searchEnvelope = { data: [primitives], pagination };

  it("accepts the cursor-only envelope and rejects the legacy page-based shape", async () => {
    const httpClient = httpClientReturning(searchEnvelope);
    await new ApiBankRepository(httpClient).search(BASE_CRITERIA);

    const [, guard] = vi.mocked(httpClient.get).mock.calls[0];
    if (!guard) throw new Error("expected search() to pass a response guard");

    expect(guard(searchEnvelope)).toBe(true);
    // Null links are valid (affordance absent) — the shape stays constant.
    expect(
      guard({
        data: [primitives],
        pagination: {
          hasNext: false,
          hasPrev: false,
          count: null,
          links: { next: null, prev: null },
        },
      }),
    ).toBe(true);
    expect(guard({ data: [primitives] })).toBe(false); // pagination missing
    // The legacy page-based envelope MUST be rejected (drift guard).
    expect(
      guard({
        data: [primitives],
        pagination: { currentPage: 1, hasMorePages: true, cursor: "c" },
      }),
    ).toBe(false);
    // links.next must be string | null, never another type.
    expect(
      guard({
        data: [primitives],
        pagination: { hasNext: true, hasPrev: false, count: null, links: { next: 1, prev: null } },
      }),
    ).toBe(false);
    expect(guard({ data: null, pagination })).toBe(false);
    expect(guard({ data: [{ id: 1 }], pagination })).toBe(false);
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
