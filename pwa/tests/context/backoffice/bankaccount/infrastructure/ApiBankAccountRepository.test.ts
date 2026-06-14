import { describe, expect, it, vi } from "vitest";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import { ApiBankAccountRepository } from "@/context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository";
import type { HttpClient } from "@/context/shared/infrastructure/HttpClient/HttpClient";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import type { BankAccountSearchCriteria } from "@/context/backoffice/bankaccount/domain/BankAccountRepository";

const BANK_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b";

const primitives = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  status: "active",
};

// Cursor-only envelope v2 (PR3): directional flags, optional count, verbatim links.
const pagination = {
  hasNext: true,
  hasPrev: true,
  count: 42,
  links: {
    next: "/api/v1/backoffice/banks/" + BANK_ID + "/accounts?limit=25&after=cursor-next",
    prev: "/api/v1/backoffice/banks/" + BANK_ID + "/accounts?limit=25&before=cursor-prev",
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

const BASE_CRITERIA: BankAccountSearchCriteria = { filters: [], sort: null, limit: 25 };

describe("ApiBankAccountRepository.search", () => {
  it("targets the per-bank accounts endpoint and serializes filters, sort and limit — never a cursor", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    await new ApiBankAccountRepository(httpClient).search(BANK_ID, {
      filters: [{ field: "holderName", operator: "contains", value: "acme" }],
      sort: { field: "holderName", direction: SortDirection.DESC },
      limit: 50,
    });

    const calledUrl = vi.mocked(httpClient.get).mock.calls[0][0];
    expect(calledUrl).toContain(`/api/v1/backoffice/banks/${BANK_ID}/accounts`);
    const q = queryOf(httpClient);
    expect(q.get("filters[0][field]")).toBe("holderName");
    expect(q.get("filters[0][operator]")).toBe("contains");
    expect(q.get("filters[0][value]")).toBe("acme");
    expect(q.get("sort")).toBe("holderName");
    expect(q.get("direction")).toBe("DESC"); // PWA enum is lowercase; the wire is uppercase
    expect(q.get("limit")).toBe("50");
    // The query path NEVER serializes a cursor — continuing pages is the
    // navigator following server links verbatim.
    expect(q.has("page")).toBe(false);
    expect(q.has("cursor")).toBe(false);
    expect(q.has("after")).toBe(false);
    expect(q.has("before")).toBe(false);
    expect(q.has("paginationMode")).toBe(false);
  });

  it("encodes the bank id into the path", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    await new ApiBankAccountRepository(httpClient).search("a b/c", BASE_CRITERIA);
    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toContain("/banks/a%20b%2Fc/accounts");
  });

  it("omits sort when absent and clamps limit to the wire ceiling (D-Cap)", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    await new ApiBankAccountRepository(httpClient).search(BANK_ID, {
      filters: [],
      sort: null,
      limit: 1000,
    });

    const q = queryOf(httpClient);
    expect(q.has("sort")).toBe(false);
    expect(q.has("direction")).toBe(false);
    expect(q.get("limit")).toBe("100");
  });

  it("maps the cursor-only envelope to a BankAccountSearchPage", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    const page = await new ApiBankAccountRepository(httpClient).search(BANK_ID, BASE_CRITERIA);

    expect(page.accounts).toHaveLength(1);
    expect(page.accounts[0]).toBeInstanceOf(BankAccount);
    expect(page.accounts[0].holderName).toBe("Acme Corp");
    expect(page.accounts[0].iban).toBe("ES9121000418450200051332");
    expect(page.accounts[0].status).toBe("active");
    expect(page.hasNext).toBe(true);
    expect(page.hasPrev).toBe(true);
    expect(page.count).toBe(42);
    // Links travel through verbatim — never rebuilt client-side.
    expect(page.links).toEqual(pagination.links);
  });
});

describe("ApiBankAccountRepository response guards", () => {
  const searchEnvelope = { data: [primitives], pagination };

  it("accepts the cursor-only envelope and rejects the legacy page-based shape", async () => {
    const httpClient = httpClientReturning(searchEnvelope);
    await new ApiBankAccountRepository(httpClient).search(BANK_ID, BASE_CRITERIA);

    const [, guard] = vi.mocked(httpClient.get).mock.calls[0];
    if (!guard) throw new Error("expected search() to pass a response guard");

    expect(guard(searchEnvelope)).toBe(true);
    // Nullable bic/alias and absent links/count are all valid.
    expect(
      guard({
        data: [{ ...primitives, bic: null, alias: null }],
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
    // A missing required field (holderName) on an item is rejected.
    expect(guard({ data: [{ ...primitives, holderName: undefined }], pagination })).toBe(false);
    // A non-string iban is rejected.
    expect(guard({ data: [{ ...primitives, iban: 42 }], pagination })).toBe(false);
    expect(guard(undefined)).toBe(false);
  });
});
