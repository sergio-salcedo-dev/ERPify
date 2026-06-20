import { describe, expect, it, vi } from "vitest";
import { ApiBankCountReader } from "@/context/backoffice/bank/infrastructure/ApiBankCountReader";
import type { HttpClient } from "@/context/shared/infrastructure/HttpClient/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/infrastructure/api/ApiEndpoints";

function httpClientReturning(response: unknown): HttpClient {
  return {
    get: vi.fn().mockResolvedValue(response),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  };
}

describe("ApiBankCountReader", () => {
  it("reads the count endpoint and returns the plain total", async () => {
    const httpClient = httpClientReturning({ total: 7 });

    const total = await new ApiBankCountReader(httpClient).read();

    expect(total).toBe(7);
    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toBe(API_ENDPOINTS.BACKOFFICE.BANKS.COUNT);
  });

  it("guards the plain { total } shape — not the { data } envelope the reads use", async () => {
    const httpClient = httpClientReturning({ total: 0 });
    await new ApiBankCountReader(httpClient).read();

    const guard = vi.mocked(httpClient.get).mock.calls[0][1];
    if (!guard) throw new Error("expected read() to pass a response guard");

    // A legitimate zero (empty back office) must pass.
    expect(guard({ total: 0 })).toBe(true);
    expect(guard({ total: 42 })).toBe(true);

    // The total is a plain field — a `{ data }`-wrapped body is the wrong shape.
    expect(guard({ data: { total: 1 } })).toBe(false);

    // `typeof NaN === "number"`; floats and negatives would otherwise slip past
    // a bare typeof check, so the guard rejects every non-natural number.
    expect(guard({ total: Number.NaN })).toBe(false);
    expect(guard({ total: Number.POSITIVE_INFINITY })).toBe(false);
    expect(guard({ total: -1 })).toBe(false);
    expect(guard({ total: 1.5 })).toBe(false);
    expect(guard({ total: "3" })).toBe(false);
    expect(guard({})).toBe(false);
    expect(guard(null)).toBe(false);
    expect(guard(undefined)).toBe(false);
  });
});
