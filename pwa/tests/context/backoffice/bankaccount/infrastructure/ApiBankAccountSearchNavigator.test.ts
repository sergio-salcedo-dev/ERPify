import { describe, expect, it, vi } from "vitest";
import { ApiBankAccountSearchNavigator } from "@/context/backoffice/bankaccount/infrastructure/ApiBankAccountSearchNavigator";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";

const primitives = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
};

const envelope = {
  data: [primitives],
  pagination: {
    hasNext: false,
    hasPrev: true,
    count: null,
    links: { next: null, prev: "/api/v1/backoffice/banks/b/accounts?limit=25&before=opaque" },
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

describe("ApiBankAccountSearchNavigator.follow", () => {
  it("fetches a server-issued relative link VERBATIM and maps the envelope", async () => {
    const httpClient = httpClientReturning(envelope);
    const link = "/api/v1/backoffice/banks/b/accounts?limit=25&after=opaque-cursor";

    const page = await new ApiBankAccountSearchNavigator(httpClient).follow(link);

    expect(httpClient.get).toHaveBeenCalledTimes(1);
    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toBe(link);
    expect(page.accounts[0].holderName).toBe("Acme Corp");
    expect(page.hasPrev).toBe(true);
    expect(page.links.prev).toBe("/api/v1/backoffice/banks/b/accounts?limit=25&before=opaque");
  });

  it("refuses non-same-origin or unsafe links and never hits the network (open-redirect guard)", async () => {
    const httpClient = httpClientReturning(envelope);
    const navigator = new ApiBankAccountSearchNavigator(httpClient);

    for (const bad of [
      "https://evil.example/api/v1/backoffice/banks/b/accounts?after=c", // absolute, external host
      "//evil.example/api/v1/backoffice/banks/b/accounts", // protocol-relative
      "api/v1/backoffice/banks/b/accounts?after=c", // not path-absolute
      "javascript:alert(1)", // dangerous scheme
      "",
    ]) {
      await expect(navigator.follow(bad)).rejects.toThrow();
    }
    expect(httpClient.get).not.toHaveBeenCalled();
  });
});
