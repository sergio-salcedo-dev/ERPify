import { describe, expect, it, vi } from "vitest";
import { BankAccountResourceNavigator } from "@/context/backoffice/bankaccount/infrastructure/BankAccountResourceNavigator";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const ROW = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  bankId: "11111111-1111-7111-8111-111111111111",
  bankName: "Acme Savings",
  bankShortName: "ACME",
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "ACTIVE",
} as const;

const ENVELOPE = {
  data: [ROW],
  pagination: {
    hasNext: false,
    hasPrev: true,
    count: null,
    links: { next: null, prev: "/api/v1/backoffice/bank-accounts?before=cursor&limit=25" },
  },
};

const LINK = "/api/v1/backoffice/bank-accounts?after=cursor&limit=25";

const PROBLEM: ProblemDetails = {
  type: "about:blank",
  title: "Boom",
  status: 500,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000500",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000500",
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

describe("BankAccountResourceNavigator", () => {
  it("follows a relative link verbatim and remaps the collection page to {items}", async () => {
    const httpClient = httpClientReturning(ENVELOPE);
    const result = await new BankAccountResourceNavigator(httpClient).follow(LINK);

    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toBe(LINK);
    expect(result).toEqual({
      items: [ROW],
      hasNext: false,
      hasPrev: true,
      count: null,
      links: { next: null, prev: "/api/v1/backoffice/bank-accounts?before=cursor&limit=25" },
    });
    // The JOINed bank name survives navigation, never rebuilt client-side.
    expect(result.items[0].bankName).toBe("Acme Savings");
  });

  it("refuses a non-relative or cross-origin or dangerous link before any request", async () => {
    const httpClient = httpClientReturning(ENVELOPE);
    const navigator = new BankAccountResourceNavigator(httpClient);

    await expect(navigator.follow("https://evil.example/x")).rejects.toThrow();
    await expect(navigator.follow("//evil.example/x")).rejects.toThrow();
    await expect(navigator.follow("javascript:alert(1)")).rejects.toThrow();
    await expect(navigator.follow("relative/no-leading-slash")).rejects.toThrow();
    expect(httpClient.get).not.toHaveBeenCalled();
  });

  it("propagates a follow failure untouched", async () => {
    const error = new HttpError(PROBLEM);
    const httpClient: HttpClient = {
      get: vi.fn().mockRejectedValue(error),
      post: vi.fn(),
      put: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
    };

    await expect(new BankAccountResourceNavigator(httpClient).follow(LINK)).rejects.toBe(error);
  });
});
