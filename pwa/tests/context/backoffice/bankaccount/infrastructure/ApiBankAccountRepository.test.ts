import { describe, expect, it, vi } from "vitest";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import {
  ApiBankAccountRepository,
  isBankAccountCollectionResponse,
  isBankAccountCollectionRowResponse,
  isBankAccountSingleResponse,
} from "@/context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import type { BankAccountSearchCriteria } from "@/context/backoffice/bankaccount/domain/BankAccountRepository";

const BANK_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b";

const primitives = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
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
    patch: vi.fn(),
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
    expect(page.accounts[0].status).toBe("ACTIVE");
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

const ACCOUNT_ID = "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c";

// Detail/write resource: unlike the list item it carries bankId + timestamps.
const detailPrimitives = {
  id: ACCOUNT_ID,
  bankId: BANK_ID,
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
  createdAt: "2026-01-01T00:00:00+00:00",
  updatedAt: "2026-01-02T00:00:00+00:00",
};

function writeHttpClient(): HttpClient {
  return {
    get: vi.fn().mockResolvedValue({ data: detailPrimitives }),
    post: vi.fn().mockResolvedValue({ data: detailPrimitives }),
    put: vi.fn().mockResolvedValue({ data: detailPrimitives }),
    patch: vi.fn().mockResolvedValue({ data: detailPrimitives }),
    delete: vi.fn().mockResolvedValue(undefined),
  };
}

describe("ApiBankAccountRepository CRUD", () => {
  it("find() GETs the standalone detail endpoint and maps bankId + timestamps", async () => {
    const httpClient = writeHttpClient();
    const account = await new ApiBankAccountRepository(httpClient).find(ACCOUNT_ID);

    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toBe(
      `/api/v1/backoffice/bank-accounts/${ACCOUNT_ID}`,
    );
    expect(account).toBeInstanceOf(BankAccount);
    expect(account.bankId).toBe(BANK_ID);
    expect(account.iban).toBe("ES9121000418450200051332");
    expect(account.createdAt).toBe("2026-01-01T00:00:00+00:00");
    expect(account.updatedAt).toBe("2026-01-02T00:00:00+00:00");
  });

  it("create() POSTs /bank-accounts with bankId in the body and NEVER a status", async () => {
    const httpClient = writeHttpClient();
    await new ApiBankAccountRepository(httpClient).create({
      bankId: BANK_ID,
      holderName: "Acme Corp",
      iban: "ES9121000418450200051332",
      bic: "CAIXESBBXXX",
      alias: "Payroll",
      currency: "EUR",
    });

    const [url, body] = vi.mocked(httpClient.post).mock.calls[0];
    expect(url).toBe("/api/v1/backoffice/bank-accounts");
    expect(body).toEqual({
      bankId: BANK_ID,
      holderName: "Acme Corp",
      iban: "ES9121000418450200051332",
      bic: "CAIXESBBXXX",
      alias: "Payroll",
      currency: "EUR",
    });
    expect(body).not.toHaveProperty("status");
  });

  it("update() PUTs the detail endpoint with the descriptive fields and NEVER a bankId or status", async () => {
    const httpClient = writeHttpClient();
    await new ApiBankAccountRepository(httpClient).update(ACCOUNT_ID, {
      holderName: "Acme Corp",
      iban: "ES9121000418450200051332",
      bic: null,
      alias: null,
      currency: "EUR",
    });

    const [url, body] = vi.mocked(httpClient.put).mock.calls[0];
    expect(url).toBe(`/api/v1/backoffice/bank-accounts/${ACCOUNT_ID}`);
    expect(body).toEqual({
      holderName: "Acme Corp",
      iban: "ES9121000418450200051332",
      bic: null,
      alias: null,
      currency: "EUR",
    });
    expect(body).not.toHaveProperty("bankId");
    expect(body).not.toHaveProperty("status");
  });

  it("changeStatus() PATCHes the dedicated /status endpoint with the new status", async () => {
    const httpClient = writeHttpClient();
    const account = await new ApiBankAccountRepository(httpClient).changeStatus(
      ACCOUNT_ID,
      "CLOSED",
    );

    const [url, body] = vi.mocked(httpClient.patch).mock.calls[0];
    expect(url).toBe(`/api/v1/backoffice/bank-accounts/${ACCOUNT_ID}/status`);
    expect(body).toEqual({ status: "CLOSED" });
    expect(account).toBeInstanceOf(BankAccount);
  });

  it("delete() DELETEs the standalone detail endpoint", async () => {
    const httpClient = writeHttpClient();
    await new ApiBankAccountRepository(httpClient).delete(ACCOUNT_ID);
    expect(vi.mocked(httpClient.delete).mock.calls[0][0]).toBe(
      `/api/v1/backoffice/bank-accounts/${ACCOUNT_ID}`,
    );
  });
});

describe("isBankAccountSingleResponse", () => {
  it("accepts the fully-populated detail/write resource", () => {
    expect(isBankAccountSingleResponse({ data: detailPrimitives })).toBe(true);
    expect(
      isBankAccountSingleResponse({ data: { ...detailPrimitives, bic: null, alias: null } }),
    ).toBe(true);
  });

  it("rejects a list-shaped item lacking bankId / timestamps", () => {
    expect(isBankAccountSingleResponse({ data: primitives })).toBe(false);
    expect(isBankAccountSingleResponse({ data: { ...detailPrimitives, bankId: undefined } })).toBe(
      false,
    );
    expect(
      isBankAccountSingleResponse({ data: { ...detailPrimitives, createdAt: undefined } }),
    ).toBe(false);
    expect(isBankAccountSingleResponse({ data: { ...detailPrimitives, iban: 42 } })).toBe(false);
    expect(isBankAccountSingleResponse(undefined)).toBe(false);
  });
});

// One row of the cross-bank collection view: carries the owning bank's identity
// (bankId/bankName/bankShortName from the server JOIN), no audit timestamps.
const collectionRow = {
  id: ACCOUNT_ID,
  bankId: BANK_ID,
  bankName: "Acme Savings",
  bankShortName: "ACME",
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
};

const collectionPagination = {
  hasNext: true,
  hasPrev: true,
  count: 42,
  links: {
    next: "/api/v1/backoffice/bank-accounts?limit=25&after=cursor-next",
    prev: "/api/v1/backoffice/bank-accounts?limit=25&before=cursor-prev",
  },
};

describe("ApiBankAccountRepository.searchAll", () => {
  it("targets the global collection endpoint and serializes filters, sort and limit — never a cursor", async () => {
    const httpClient = httpClientReturning({
      data: [collectionRow],
      pagination: collectionPagination,
    });
    await new ApiBankAccountRepository(httpClient).searchAll({
      filters: [{ field: "bankId", operator: "eq", value: BANK_ID }],
      sort: { field: "holderName", direction: SortDirection.DESC },
      limit: 50,
    });

    const calledUrl = vi.mocked(httpClient.get).mock.calls[0][0];
    expect(calledUrl).toContain("/api/v1/backoffice/bank-accounts");
    expect(calledUrl).not.toContain("/banks/");
    const q = queryOf(httpClient);
    expect(q.get("filters[0][field]")).toBe("bankId");
    expect(q.get("filters[0][value]")).toBe(BANK_ID);
    expect(q.get("sort")).toBe("holderName");
    expect(q.get("direction")).toBe("DESC");
    expect(q.get("limit")).toBe("50");
    expect(q.has("cursor")).toBe(false);
    expect(q.has("after")).toBe(false);
    expect(q.has("paginationMode")).toBe(false);
  });

  it("omits sort when absent and clamps limit to the wire ceiling (D-Cap)", async () => {
    const httpClient = httpClientReturning({
      data: [collectionRow],
      pagination: collectionPagination,
    });
    await new ApiBankAccountRepository(httpClient).searchAll({
      filters: [],
      sort: null,
      limit: 1000,
    });

    const q = queryOf(httpClient);
    expect(q.has("sort")).toBe(false);
    expect(q.get("limit")).toBe("100");
  });

  it("maps the envelope to a collection page, preserving bankName/bankShortName", async () => {
    const httpClient = httpClientReturning({
      data: [collectionRow],
      pagination: collectionPagination,
    });
    const page = await new ApiBankAccountRepository(httpClient).searchAll(BASE_CRITERIA);

    expect(page.rows).toHaveLength(1);
    expect(page.rows[0].id).toBe(ACCOUNT_ID);
    expect(page.rows[0].bankId).toBe(BANK_ID);
    expect(page.rows[0].bankName).toBe("Acme Savings");
    expect(page.rows[0].bankShortName).toBe("ACME");
    expect(page.rows[0].iban).toBe("ES9121000418450200051332");
    expect(page.hasNext).toBe(true);
    expect(page.count).toBe(42);
    expect(page.links).toEqual(collectionPagination.links);
  });
});

describe("ApiBankAccountRepository.findByIban", () => {
  it("POSTs the dedicated lookup endpoint with the IBAN in the body — never a query string", async () => {
    const httpClient: HttpClient = {
      get: vi.fn(),
      post: vi.fn().mockResolvedValue({ data: collectionRow }),
      put: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
    };

    const row = await new ApiBankAccountRepository(httpClient).findByIban(
      "ES9121000418450200051332",
    );

    const [url, body] = vi.mocked(httpClient.post).mock.calls[0];
    expect(url).toBe("/api/v1/backoffice/bank-accounts/iban-lookup");
    expect(body).toEqual({ iban: "ES9121000418450200051332" });
    expect(row.id).toBe(ACCOUNT_ID);
    expect(row.bankName).toBe("Acme Savings");
  });

  it("propagates a rejection (404/422) rather than swallowing it", async () => {
    const failure = new Error("boom");
    const httpClient: HttpClient = {
      get: vi.fn(),
      post: vi.fn().mockRejectedValue(failure),
      put: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
    };

    await expect(
      new ApiBankAccountRepository(httpClient).findByIban("ES9121000418450200051332"),
    ).rejects.toBe(failure);
  });
});

describe("isBankAccountCollectionRowResponse", () => {
  it("accepts a single collection row under data, never a list", () => {
    expect(isBankAccountCollectionRowResponse({ data: collectionRow })).toBe(true);
    expect(isBankAccountCollectionRowResponse({ data: [collectionRow] })).toBe(false);
    expect(isBankAccountCollectionRowResponse({ data: { ...collectionRow, bankName: 42 } })).toBe(
      false,
    );
    expect(isBankAccountCollectionRowResponse(undefined)).toBe(false);
  });
});

describe("isBankAccountCollectionResponse", () => {
  it("accepts the collection envelope and requires the JOINed bank name on every row", () => {
    expect(
      isBankAccountCollectionResponse({ data: [collectionRow], pagination: collectionPagination }),
    ).toBe(true);
    // Nullable bic/alias and absent links/count stay valid.
    expect(
      isBankAccountCollectionResponse({
        data: [{ ...collectionRow, bic: null, alias: null }],
        pagination: {
          hasNext: false,
          hasPrev: false,
          count: null,
          links: { next: null, prev: null },
        },
      }),
    ).toBe(true);
    // A row missing bankName/bankShortName (the read-composition) is rejected.
    expect(
      isBankAccountCollectionResponse({
        data: [{ ...collectionRow, bankName: undefined }],
        pagination: collectionPagination,
      }),
    ).toBe(false);
    expect(
      isBankAccountCollectionResponse({
        data: [{ ...collectionRow, bankShortName: 42 }],
        pagination: collectionPagination,
      }),
    ).toBe(false);
    // The per-bank list item (no bank identity) is NOT a collection row.
    expect(
      isBankAccountCollectionResponse({ data: [primitives], pagination: collectionPagination }),
    ).toBe(false);
    // Legacy page-based envelope is rejected (drift guard).
    expect(
      isBankAccountCollectionResponse({
        data: [collectionRow],
        pagination: { currentPage: 1, hasMorePages: true, cursor: "c" },
      }),
    ).toBe(false);
    expect(isBankAccountCollectionResponse({ data: [collectionRow] })).toBe(false);
    expect(isBankAccountCollectionResponse(undefined)).toBe(false);
  });
});
